#!/usr/bin/env perl
use strict;
use warnings;
use File::Basename qw(dirname);
use File::Find;
use File::Spec;

# Fails if a live API key has found its way into anything that gets shipped.
# Run it before packaging or committing:
#
#     perl scripts/check-no-secrets.pl
#
# Two files are expected to hold a key and are skipped: preview.html at the
# project root, which is the configured local copy of the demo, and .env, which
# is where the server-side key is supposed to live. .gitignore keeps both out
# of the repo — this check is the belt to that braces, for everything else.

my $root = File::Spec->rel2abs( File::Spec->catdir( dirname(__FILE__), File::Spec->updir ) );

my %skip = map { File::Spec->canonpath("$root/$_") => 1 } (
    'preview.html',
    '.env',
);

my @patterns = (
    [ 'Groq key'       => qr/\bgsk_[A-Za-z0-9]{20,}/ ],
    [ 'Anthropic key'  => qr/\bsk-ant-[A-Za-z0-9_\-]{20,}/ ],
    [ 'OpenAI key'     => qr/\bsk-[A-Za-z0-9]{32,}/ ],
    # Z.ai / Zhipu GLM issues <32 hex>.<secret>, with no prefix to key off.
    [ 'Z.ai key'       => qr/\b[0-9a-f]{32}\.[A-Za-z0-9]{16,}\b/ ],
    [ 'filled API_KEY' => qr/^var API_KEY = '[^']+';/m ],
);

my @hits;

find(
    {
        no_chdir => 1,
        wanted   => sub {
            my $path = $File::Find::name;
            return unless -f $path;
            return if $path =~ m{[/\\]\.git[/\\]};
            return if $path =~ m{[/\\]node_modules[/\\]};
            return if $path =~ /\.(zip|png|jpg|jpeg|gif|pdf|woff2?|ico)$/i;
            return if $skip{ File::Spec->canonpath($path) };

            open( my $fh, '<:raw', $path ) or return;
            my $raw = do { local $/; <$fh> };
            close $fh;

            for my $p (@patterns) {
                next unless $raw =~ $p->[1];
                my $rel = $path;
                $rel =~ s/^\Q$root\E[\/\\]?//;
                push @hits, "$rel — $p->[0]";
                last;
            }
        },
    },
    $root
);

if (@hits) {
    print "SECRET FOUND — do not package or commit:\n";
    print "  $_\n" for @hits;
    exit 1;
}

print "clean: no API keys in anything shippable\n";
exit 0;
