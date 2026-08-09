#!/usr/bin/env perl
use strict;
use warnings;
use File::Basename qw(dirname);
use File::Find;
use File::Spec;

# Fails if a live API key has found its way into anything that gets shipped.
# Run it before packaging or committing:
#
#     perl scripts/check-no-secrets.pl              # scan the repository
#     perl scripts/check-no-secrets.pl DIR [DIR..]  # scan somewhere else
#
# Two files are expected to hold a key and are skipped: preview.html at the
# project root, which is the configured local copy of the demo, and .env, which
# is where the server-side key is supposed to live. .gitignore keeps both out
# of the repo — this check is the belt to that braces, for everything else.
#
# ARCHIVES ARE A BLIND SPOT AND THE PATH ARGUMENT IS THE ANSWER TO IT.
# Zip entries are compressed, so scanning the container byte-for-byte matches
# nothing and reports "clean" — worse than not looking, because it reads as
# assurance. The repository ships scholaris-wordpress-platform.zip, so CI
# extracts every tracked archive and points this script at the result; see the
# "No secrets inside committed archives" step in .github/workflows/deploy.yml.
#
# When explicit roots are given, NOTHING is exempt. The two skips above are
# about a developer's working tree, not about shippable content: an archive
# carrying a key-bearing preview.html is precisely the bug being hunted.

my $repo  = File::Spec->rel2abs( File::Spec->catdir( dirname(__FILE__), File::Spec->updir ) );
my @roots = @ARGV ? map { File::Spec->rel2abs($_) } @ARGV : ($repo);
my $given = @ARGV ? 1 : 0;

my %skip = $given ? () : map { File::Spec->canonpath("$repo/$_") => 1 } (
    'preview.html',
    '.env',
);

for my $r (@roots) {
    die "not a directory: $r\n" unless -d $r;
}

my @patterns = (
    [ 'Groq key'       => qr/\bgsk_[A-Za-z0-9]{20,}/ ],
    [ 'Anthropic key'  => qr/\bsk-ant-[A-Za-z0-9_\-]{20,}/ ],
    [ 'OpenAI key'     => qr/\bsk-[A-Za-z0-9]{32,}/ ],
    # Z.ai / Zhipu GLM issues <32 hex>.<secret>, with no prefix to key off.
    [ 'Z.ai key'       => qr/\b[0-9a-f]{32}\.[A-Za-z0-9]{16,}\b/ ],
    [ 'filled API_KEY' => qr/^var API_KEY = '[^']+';/m ],
);

my @hits;
my $scanned = 0;

find(
    {
        no_chdir => 1,
        wanted   => sub {
            my $path = $File::Find::name;
            return unless -f $path;
            return if $path =~ m{[/\\]\.git[/\\]};
            return if $path =~ m{[/\\]node_modules[/\\]};
            # Archives are skipped here on purpose — compressed bytes never
            # match. CI extracts them and re-invokes with the extracted path.
            return if $path =~ /\.(zip|png|jpg|jpeg|gif|pdf|woff2?|ico)$/i;
            return if $skip{ File::Spec->canonpath($path) };

            open( my $fh, '<:raw', $path ) or return;
            my $raw = do { local $/; <$fh> };
            close $fh;
            $scanned++;

            for my $p (@patterns) {
                next unless $raw =~ $p->[1];
                my $rel = $path;
                $rel =~ s/^\Q$_\E[\/\\]?// for @roots;
                push @hits, "$rel — $p->[0]";
                last;
            }
        },
    },
    @roots
);

if (@hits) {
    print "SECRET FOUND — do not package or commit:\n";
    print "  $_\n" for @hits;
    exit 1;
}

# A scan that read nothing is not a pass. When CI points this at an extracted
# archive, an empty or wrongly-nested directory would otherwise report "clean".
if ( !$scanned ) {
    print "NOTHING SCANNED — no readable files under: @roots\n";
    print "  Refusing to report clean; a check that examines nothing passes forever.\n";
    exit 1;
}

print "clean: no API keys in $scanned file(s)\n";
exit 0;
