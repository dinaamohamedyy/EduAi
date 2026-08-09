#!/usr/bin/env perl
use strict;
use warnings;
use File::Basename qw(dirname);
use File::Find;
use File::Spec;

# A PHP syntax smoke test for machines with no PHP on them.
#
#     perl scripts/php-sanity.pl            # scan wp-content, php/ and scripts/
#     perl scripts/php-sanity.pl FILE...    # scan specific files
#
# Walks every .php file with a small state machine (PHP/HTML modes, strings,
# comments, heredocs) and reports unbalanced braces/parens/brackets and
# unterminated strings, comments or heredocs — the classic hand-edit breakages.
# It is NOT a parser: CI still runs the real `php -l` on every push
# (.github/workflows/deploy.yml); this is the local pre-flight.
#
# Known blind spots, accepted for simplicity: backtick shell strings and a
# `?>` inside a // comment (neither appears in this codebase).

my $root = File::Spec->rel2abs( File::Spec->catdir( dirname(__FILE__), File::Spec->updir ) );

my @files;
if (@ARGV) {
    @files = @ARGV;
} else {
    # php/ holds container-mounted code (dev-mail.php) that deploys nowhere
    # but still fatals a local WordPress if broken — it gets the same scan.
    # scripts/ holds the PHP-CLI checkers (calc-parity.php, redaction-guard.php).
    # CI does run those, so a syntax error there fails the build either way —
    # but as a confusing runtime error, and only after the push. This machine
    # has no PHP at all, so without this entry they get no local check whatever.
    for my $dir ( 'wp-content', 'php', 'scripts' ) {
        my $path = File::Spec->catdir( $root, $dir );
        next unless -d $path;
        find(
            {
                no_chdir => 1,
                wanted   => sub {
                    push @files, $File::Find::name if -f $File::Find::name && $File::Find::name =~ /\.php$/i;
                },
            },
            $path
        );
    }
}

my $bad = 0;

for my $file (sort @files) {
    my @errors = check_file($file);
    next unless @errors;

    $bad++;
    my $rel = $file;
    $rel =~ s/^\Q$root\E[\/\\]?//;
    print "$rel\n";
    print "  $_\n" for @errors;
}

if ($bad) {
    print "\n$bad file(s) with problems\n";
    exit 1;
}

printf "clean: %d PHP file(s) pass the smoke test\n", scalar @files;
exit 0;

sub check_file {
    my ($file) = @_;

    open( my $fh, '<:raw', $file ) or return ("cannot open: $!");
    my $src = do { local $/; <$fh> };
    close $fh;

    my @errors;

    push @errors, 'starts with a UTF-8 BOM (breaks headers)' if $src =~ /^\xEF\xBB\xBF/;
    push @errors, 'does not start with <?php' unless $src =~ /^(\xEF\xBB\xBF)?<\?php/;

    my $state = 'html';        # html | php | sq | dq | lc | bc | hd
    my $line  = 1;
    my $hd_label = '';
    my @stack;                 # [ char, line ] for { ( [
    my %close = ( '}' => '{', ')' => '(', ']' => '[' );

    my $len = length $src;
    my $i   = 0;

    while ( $i < $len ) {
        my $c    = substr( $src, $i, 1 );
        my $two  = $i + 1 < $len ? substr( $src, $i, 2 ) : '';
        my $three = $i + 2 < $len ? substr( $src, $i, 3 ) : '';

        $line++ if "\n" eq $c;

        if ( 'html' eq $state ) {
            if ( '<?' eq $two ) {
                $state = 'php';
                $i += ( '<?php' eq lc substr( $src, $i, 5 ) ) ? 5 : 2;
                next;
            }
        }
        elsif ( 'php' eq $state ) {
            if ( '?>' eq $two )  { $state = 'html'; $i += 2; next; }
            if ( "'" eq $c )     { $state = 'sq';   $i++;    next; }
            if ( '"' eq $c )     { $state = 'dq';   $i++;    next; }
            if ( '//' eq $two || '#' eq $c ) { $state = 'lc'; $i++; next; }
            if ( '/*' eq $two )  { $state = 'bc'; $i += 2; next; }
            if ( '<<<' eq $three ) {
                # Heredoc <<<LABEL / <<<"LABEL", nowdoc <<<'LABEL'.
                if ( substr( $src, $i + 3 ) =~ /^[ \t]*(['"]?)([A-Za-z_][A-Za-z0-9_]*)\1\r?\n/ ) {
                    $hd_label = $2;
                    $state    = 'hd';
                }
                $i += 3;
                next;
            }
            if ( $c =~ /[\{\(\[]/ ) { push @stack, [ $c, $line ]; $i++; next; }
            if ( exists $close{$c} ) {
                if ( !@stack || $stack[-1][0] ne $close{$c} ) {
                    push @errors, "line $line: unexpected '$c'" . ( @stack ? " (open '$stack[-1][0]' from line $stack[-1][1])" : '' );
                    # Keep going: one report per mismatch site is enough.
                } else {
                    pop @stack;
                }
                $i++;
                next;
            }
        }
        elsif ( 'sq' eq $state ) {
            if ( '\\' eq $c ) { $i += 2; next; }
            $state = 'php' if "'" eq $c;
        }
        elsif ( 'dq' eq $state ) {
            if ( '\\' eq $c ) { $i += 2; next; }
            $state = 'php' if '"' eq $c;
        }
        elsif ( 'lc' eq $state ) {
            $state = 'php' if "\n" eq $c;
        }
        elsif ( 'bc' eq $state ) {
            if ( '*/' eq $two ) { $state = 'php'; $i += 2; next; }
        }
        elsif ( 'hd' eq $state ) {
            # PHP 7.3 flexible closer: optional indentation, then the label.
            if ( "\n" eq $c && substr( $src, $i + 1 ) =~ /^[ \t]*\Q$hd_label\E\b/ ) {
                $state = 'php';
            }
        }

        $i++;
    }

    push @errors, "unterminated single-quoted string at end of file" if 'sq' eq $state;
    push @errors, "unterminated double-quoted string at end of file" if 'dq' eq $state;
    push @errors, "unterminated block comment at end of file"        if 'bc' eq $state;
    push @errors, "unterminated heredoc '$hd_label' at end of file"  if 'hd' eq $state;

    for my $open (@stack) {
        push @errors, "line $open->[1]: '$open->[0]' never closed";
    }

    return @errors;
}
