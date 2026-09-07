#!/usr/bin/env perl
#
# Ask a NON-Claude model one question and print its answer. Nothing else.
#
# This is a tool an agent calls, in the same sense that grep is a tool. The
# external model reads nothing, writes nothing and decides nothing: it receives
# a question as text and returns an opinion as text. The agent that called it
# stays in charge of what happens next.
#
# That boundary is the whole design. The owner drew it himself: "a feature can
# use external llm but not a whole agent." It is the same shape the site's own
# AI features already use -- Summarise and PrepareME send a prompt to Groq and
# use what comes back -- and it is safe for the same reason. A model that cannot
# reach the filesystem cannot corrupt it, and an opinion that arrives labelled
# as an opinion cannot quietly become a decision.
#
#   perl scripts/consult-external.pl "Should the LMS seam live in the theme?"
#   perl scripts/consult-external.pl --provider=ollama "..."
#   perl scripts/consult-external.pl --provider=openai --model=gpt-4o "..."
#   echo "a longer question" | perl scripts/consult-external.pl -
#
# KEYS ARE READ FROM THE ENVIRONMENT AND NOWHERE ELSE. Never written to a file,
# never echoed, never passed on a command line where `ps` would show them. That
# is not caution for its own sake: scripts/check-no-secrets.pl scans this tree
# precisely because a key committed once is a key leaked forever, and this file
# is the obvious place someone would be tempted to paste one.
#
# Exits non-zero and says why on every failure. It never prints an empty answer:
# an agent cannot tell "the model had no comment" from "the request never left
# the machine", and this project has already paid for guards that stayed silent.

use strict;
use warnings;
use JSON::PP;

# `key_optional` is what makes local Ollama work. It runs on this machine with
# no authentication, so demanding a key would refuse a perfectly good provider --
# but the same models served from ollama.com do need one, hence two entries
# rather than one entry with a guess inside it.
my $OLLAMA_HOST = $ENV{OLLAMA_HOST} || 'http://localhost:11434';

my %PROVIDER = (
    groq => {
        url   => 'https://api.groq.com/openai/v1/chat/completions',
        env   => 'GROQ_API_KEY',
        model => 'openai/gpt-oss-120b',
    },
    openai => {
        url   => 'https://api.openai.com/v1/chat/completions',
        env   => 'OPENAI_API_KEY',
        model => 'gpt-4o',
    },
    ollama => {
        url          => $OLLAMA_HOST . '/v1/chat/completions',
        tags         => $OLLAMA_HOST . '/api/tags',
        env          => 'OLLAMA_API_KEY',
        key_optional => 1,
        model        => '',   # Resolved from what is installed; see below.
    },
    'ollama-cloud' => {
        url   => 'https://ollama.com/v1/chat/completions',
        env   => 'OLLAMA_API_KEY',
        model => 'gpt-oss:120b',
    },
);

my $provider = 'groq';
my $model    = '';
my $system   = 'You are a senior engineer giving a second opinion. Be concise '
             . 'and concrete. If the question cannot be answered from what you '
             . 'were told, say exactly what is missing instead of guessing.';
my @rest;

for my $arg ( @ARGV ) {
    if    ( $arg =~ /^--provider=(.+)$/ ) { $provider = lc $1 }
    elsif ( $arg =~ /^--model=(.+)$/ )    { $model    = $1 }
    elsif ( $arg =~ /^--system=(.+)$/ )   { $system   = $1 }
    elsif ( $arg eq '--help' || $arg eq '-h' ) { usage(); exit 0 }
    else  { push @rest, $arg }
}

sub usage {
    my $known = join ' | ', sort keys %PROVIDER;
    print <<"USAGE";
consult-external.pl -- ask a non-Claude model one question.

  perl scripts/consult-external.pl "your question"
  echo "your question" | perl scripts/consult-external.pl -

  --provider=NAME   $known (default: groq)
  --model=NAME      override the provider's default model
  --system=TEXT     override the system prompt

Keys come from the provider's environment variable and from nowhere else. Set
one in your shell for the session; do not put it in a file.

  groq          GROQ_API_KEY
  openai        OPENAI_API_KEY
  ollama        no key needed -- talks to Ollama on this machine.
                OLLAMA_HOST overrides http://localhost:11434.
                With no --model it uses the first model you have installed.
  ollama-cloud  OLLAMA_API_KEY, from ollama.com
USAGE
}

my $cfg = $PROVIDER{$provider}
    or die "consult-external: unknown provider '$provider'. Known: "
         . join( ', ', sort keys %PROVIDER ) . "\n";

# The question: argv, or stdin when the single argument is "-". Reading stdin
# matters more than it looks -- a question worth asking another model is usually
# a paragraph, and shell quoting mangles paragraphs.
my $question;
if ( @rest == 1 && $rest[0] eq '-' ) {
    local $/;
    $question = <STDIN>;
} else {
    $question = join ' ', @rest;
}
$question = '' unless defined $question;
$question =~ s/\A\s+|\s+\z//g;

if ( '' eq $question ) {
    usage();
    die "\nconsult-external: no question given.\n";
}

my $key = $ENV{ $cfg->{env} };
$key = '' unless defined $key;

if ( $key !~ /\S/ && ! $cfg->{key_optional} ) {
    die "consult-external: \$$cfg->{env} is not set.\n"
      . "Set it in this shell and re-run. Do NOT write it into a file --\n"
      . "scripts/check-no-secrets.pl exists because that mistake is permanent.\n";
}

my $tmp = $ENV{TMPDIR} || $ENV{TEMP} || '/tmp';
$tmp =~ s{\\}{/}g;
$tmp =~ s{/+$}{};

# The header goes to a 0600 file rather than an argv slot, so the key never
# appears in the process table where another user on the box could read it.
my $hdr = "$tmp/consult-$$.hdr";
open my $fh, '>', $hdr or die "consult-external: cannot write $hdr: $!\n";
print {$fh} "Content-Type: application/json\n";
print {$fh} "Authorization: Bearer $key\n" if $key =~ /\S/;
close $fh;
chmod 0600, $hdr;

my $body_file = "$tmp/consult-$$.json";

# Cleanup runs however this exits, including every die above and below. A key
# sitting in a world-readable temp file after a crash is the whole risk this
# indirection was meant to remove.
END { unlink $hdr, $body_file }

# Ollama serves whatever the user has pulled, so there is no default worth
# hardcoding -- a guess here is a guaranteed failure on most machines. Ask the
# server what it has instead. This also turns the commonest failure by far
# ("Ollama is not running") into that sentence, rather than a connection error
# the caller has to interpret.
if ( ! $model && $cfg->{tags} ) {
    my $tags   = `curl -sS --max-time 10 "$cfg->{tags}" 2>&1`;
    my $failed = $?;

    if ( 0 != $failed || ! defined $tags || $tags !~ /\S/ ) {
        die "consult-external: no Ollama server at $cfg->{tags}.\n"
          . "Start it with `ollama serve`, or set OLLAMA_HOST, or use\n"
          . "--provider=ollama-cloud with OLLAMA_API_KEY set.\n";
    }

    my $parsed = eval { JSON::PP->new->utf8->decode( $tags ) };
    my @names  = map { $_->{name} }
                 grep { ref $_ eq 'HASH' && defined $_->{name} }
                 @{ ( $parsed && ref $parsed eq 'HASH' && $parsed->{models} ) || [] };

    if ( ! @names ) {
        die "consult-external: Ollama is running but has no models installed.\n"
          . "Pull one first, e.g. `ollama pull llama3.2`.\n";
    }

    $model = $names[0];
}

$model ||= $cfg->{model};

if ( '' eq $model ) {
    die "consult-external: no model resolved for '$provider'. Pass --model=NAME.\n";
}

my $payload = JSON::PP->new->utf8->encode( {
    model    => $model,
    stream   => JSON::PP::false,
    messages => [
        { role => 'system', content => $system   },
        { role => 'user',   content => $question },
    ],
} );

open my $bf, '>', $body_file or die "consult-external: cannot write $body_file: $!\n";
binmode $bf;
print {$bf} $payload;
close $bf;

my $raw    = `curl -sS --max-time 120 -H \@"$hdr" --data-binary \@"$body_file" "$cfg->{url}" 2>&1`;
my $status = $?;

if ( 0 != $status ) {
    die "consult-external: the request to $provider failed to complete.\n$raw\n";
}
if ( ! defined $raw || $raw !~ /\S/ ) {
    die "consult-external: empty response from $provider. The request may not "
      . "have left this machine.\n";
}

my $decoded = eval { JSON::PP->new->utf8->decode( $raw ) };
if ( ! $decoded ) {
    die "consult-external: $provider did not return JSON. Raw response:\n$raw\n";
}

if ( ref $decoded eq 'HASH' && $decoded->{error} ) {
    my $msg = ref $decoded->{error} eq 'HASH'
        ? ( $decoded->{error}{message} || 'no message' )
        : $decoded->{error};
    die "consult-external: $provider returned an error: $msg\n";
}

my $answer = eval { $decoded->{choices}[0]{message}{content} };
if ( ! defined $answer || $answer !~ /\S/ ) {
    die "consult-external: $provider returned no answer text.\n";
}

# Labelled on the way out. An agent relaying this to teammates must be able to
# say where it came from, and a bare paragraph of text loses that instantly --
# which is how another model's guess ends up quoted as a decision.
print "--- opinion from $provider/$model (NOT verified, NOT a decision) ---\n";
print $answer;
print "\n--- end opinion ---\n";
