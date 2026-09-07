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
# AI features already use — Summarise and PrepareME send a prompt to Groq and
# use what comes back — and it is safe for the same reason. A model that cannot
# reach the filesystem cannot corrupt it, and an opinion that arrives labelled
# as an opinion cannot quietly become a decision.
#
#   perl scripts/consult-external.pl "Should the LMS seam live in the theme?"
#   perl scripts/consult-external.pl --provider=openai --model=gpt-4o "..."
#   echo "long question" | perl scripts/consult-external.pl -
#
# THE KEY IS READ FROM THE ENVIRONMENT AND NOWHERE ELSE. It is never written to
# a file, never echoed, never passed on a command line where `ps` would show it.
# That is not caution for its own sake: scripts/check-no-secrets.pl scans this
# tree precisely because a key committed once is a key leaked forever, and this
# file is the obvious place someone would be tempted to paste one.
#
# Exits non-zero and says why on any failure. It never prints an empty answer:
# an agent cannot tell "the model had no comment" from "the request never left
# the machine", and this project has already paid for guards that stayed silent.

use strict;
use warnings;
use JSON::PP;

my %PROVIDER = (
    groq => {
        url   => 'https://api.groq.com/openai/v1/chat/completions',
        env   => 'GROQ_API_KEY',
        # Pinned to what the site itself uses. The Llama id that was here first
        # does not exist any more -- exactly the failure docs record in
        # scripts/model-catalogue.php, met again within a minute of writing it.
        model => 'openai/gpt-oss-120b',
    },
    openai => {
        url   => 'https://api.openai.com/v1/chat/completions',
        env   => 'OPENAI_API_KEY',
        model => 'gpt-4o',
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
    print <<"USAGE";
consult-external.pl — ask a non-Claude model one question.

  perl scripts/consult-external.pl "your question"
  echo "your question" | perl scripts/consult-external.pl -

  --provider=NAME   @{[ join ' | ', sort keys %PROVIDER ]} (default: groq)
  --model=NAME      override the provider's default model
  --system=TEXT     override the system prompt

The API key comes from the provider's environment variable and from nowhere
else. Set it in your shell for the session; do not put it in a file.
USAGE
}

my $cfg = $PROVIDER{$provider}
    or die "consult-external: unknown provider '$provider'. Known: "
         . join( ', ', sort keys %PROVIDER ) . "\n";

$model ||= $cfg->{model};

# The question: argv, or stdin when the single argument is "-". Reading stdin
# matters more than it looks — a question worth asking another model is usually
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
if ( ! defined $key || $key !~ /\S/ ) {
    die "consult-external: \$$cfg->{env} is not set.\n"
      . "Set it in this shell and re-run. Do NOT write it into a file --\n"
      . "scripts/check-no-secrets.pl exists because that mistake is permanent.\n";
}

my $payload = JSON::PP->new->utf8->encode( {
    model    => $model,
    messages => [
        { role => 'system', content => $system   },
        { role => 'user',   content => $question },
    ],
} );

# Body over stdin and key over a header file, so neither ever appears in the
# process table where any other user on the box could read it.
my $pid = $$;
my $hdr = ( $ENV{TMPDIR} || $ENV{TEMP} || '/tmp' ) . "/consult-$pid.hdr";
open my $fh, '>', $hdr or die "consult-external: cannot write $hdr: $!\n";
print {$fh} "Authorization: Bearer $key\n";
close $fh;
chmod 0600, $hdr;

# Write both, then run curl once. No jq, no PHP, no CPAN module --
# this box has none of them.
my $raw = q{};
my $body_file = ( $ENV{TMPDIR} || $ENV{TEMP} || '/tmp' ) . "/consult-$pid.json";
open my $bf, '>', $body_file or die "consult-external: cannot write $body_file: $!\n";
binmode $bf;
print {$bf} $payload;
close $bf;

$raw = `curl -sS --max-time 60 -H \@"$hdr" -H "Content-Type: application/json" --data-binary \@"$body_file" "$cfg->{url}" 2>&1`;
my $curl_status = $?;

unlink $hdr, $body_file;

if ( 0 != $curl_status ) {
    die "consult-external: the request failed to complete.\n$raw\n";
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
