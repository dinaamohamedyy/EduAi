#!/usr/bin/env perl
use strict;
use warnings;
use File::Basename qw(dirname);
use File::Spec;

# Push design/preview.html — the shippable, key-free copy — into preview.html,
# the local working copy, preserving the API key baked into the working copy's
# `var API_KEY = '...';` line. Design edits go into design/preview.html; this
# is the only correct sync direction. contract-tests.pl's preview-copies-in-sync
# check verifies the result, and shippable-preview-keyless enforces the
# invariant this script also refuses to violate.
#
#     perl scripts/sync-preview.pl
#
# Byte-faithful on purpose: both files are read and written raw, and only the
# single API_KEY line differs between them afterwards.

my $root = File::Spec->rel2abs( File::Spec->catdir( dirname(__FILE__), File::Spec->updir ) );

my $ship_path = File::Spec->catfile( $root, 'design', 'preview.html' );
my $live_path = File::Spec->catfile( $root, 'preview.html' );

my $ship = slurp_raw($ship_path);
my $live = slurp_raw($live_path);

# The shippable copy must be keyless — refuse to propagate a leak.
my ($ship_key) = $ship =~ /^var API_KEY = '([^']*)';/m;
die "design/preview.html has no API_KEY line — refusing to guess\n" unless defined $ship_key;
die "design/preview.html has a key baked in — remove it first, that copy ships\n" if '' ne $ship_key;

# The working copy's key line survives the sync verbatim.
my ($live_line) = $live =~ /^(var API_KEY = '[^']*';)/m;
die "preview.html has no API_KEY line — refusing to overwrite it blind\n" unless defined $live_line;

my $out = $ship;
$out =~ s/^var API_KEY = '';/$live_line/m;

if ( $out eq $live ) {
    print "preview.html is already in sync with design/preview.html\n";
    exit 0;
}

open( my $fh, '>:raw', $live_path ) or die "cannot write preview.html: $!\n";
print {$fh} $out;
close $fh;

printf "preview.html updated from design/preview.html (%d bytes, key line kept)\n", length $out;
exit 0;

sub slurp_raw {
    my ($path) = @_;
    open( my $fh, '<:raw', $path ) or die "cannot read $path: $!\n";
    local $/;
    return scalar <$fh>;
}
