#!/usr/bin/env perl
use strict;
use warnings;
# The output layer below encodes to UTF-8, so the literals in this file have to
# be characters rather than bytes — without this, every em-dash in a message
# gets encoded twice and prints as "â".
use utf8;
use File::Basename qw(dirname);
use File::Find ();
use File::Spec;
use JSON::PP ();

# Cross-file contract tests for machines with no PHP, Node or Docker on them.
#
#     perl scripts/contract-tests.pl           # run every check
#     perl scripts/contract-tests.pl --list    # name the checks and exit
#
# php-sanity.pl asks "is this file still syntactically whole?". This asks the
# question one level up: "do the files that must agree with each other still
# agree?". Every check here guards a claim the codebase makes in prose — that
# the test page sends byte-for-byte what the plugin sends, that the preview
# renders replies the way the real site will, that every notice flag the back
# end emits has a message on the front end. Those claims are currently held
# together by hand, and hands forget.
#
# Output is one line per check, `ok` / `NOT OK`, and a non-zero exit if any
# check failed — so it drops straight into a CI step next to the linters.

binmode STDOUT, ":encoding(UTF-8)";
binmode STDERR, ":encoding(UTF-8)";

my $root = File::Spec->rel2abs( File::Spec->catdir( dirname(__FILE__), File::Spec->updir ) );

my $AGENTS  = "wp-content/plugins/eduai-assistant/agents";
my $CLAUDE  = "wp-content/plugins/eduai-assistant/includes/class-eduai-claude.php";
my $REST    = "wp-content/plugins/eduai-assistant/includes/class-eduai-rest.php";
my $AGENTSPHP = "wp-content/plugins/eduai-assistant/includes/class-eduai-agents.php";
my $AUTH    = "wp-content/themes/scholaris/inc/auth.php";
my $FLOW    = "wp-content/themes/scholaris/inc/auth-flow.php";
my $FIXTURE = "wp-content/plugins/eduai-assistant/fixtures/exam-sample.json";
my $HANDOFF = "docs/05-frontend-handoff.md";
my $HOSTING = "docs/03-hosting-deployment.md";
my $MAINCSS = "wp-content/themes/scholaris/assets/css/main.css";
my $CHATCSS = "wp-content/plugins/eduai-assistant/assets/css/chat.css";
my $TEMPLATES = "wp-content/plugins/eduai-assistant/templates";
my $THEMEFN = "wp-content/themes/scholaris/functions.php";
my $TESTPG  = "tools/agent-test.html";
my $PREVIEW = "design/preview.html";
my $LIVE    = "preview.html";

my @checks = (
    [ 'agent-prompt-parity'    => \&check_agent_prompts ],
    [ 'agent-registry-parity'  => \&check_agent_registry ],
    [ 'provider-model-parity'  => \&check_providers ],
    [ 'markdown-rule-parity'   => \&check_markdown ],
    [ 'preview-copies-in-sync' => \&check_preview_copies ],
    [ 'shippable-preview-keyless' => \&check_preview_keyless ],
    [ 'auth-flag-coverage'     => \&check_auth_flags ],
    [ 'wp-config-examples'     => \&check_wpconfig_examples ],
    [ 'summariser-prompt'      => \&check_summariser_prompt ],
    [ 'exam-fixture-schema'    => \&check_exam_fixture ],
    [ 'exam-route-parity'      => \&check_exam_routes ],
    [ 'fixture-inline-parity'  => \&check_fixture_inline ],
    [ 'aicalc-label-parity'    => \&check_calc_labels ],
    [ 'abspath-tripwire-order' => \&check_abspath_tripwires ],
    [ 'admin-bar-offset'       => \&check_admin_bar_offset ],
    [ 'template-root-tokens'   => \&check_template_root_tokens ],
    [ 'nav-breakpoint-parity'  => \&check_nav_breakpoint ],
    [ 'admin-bar-pair'         => \&check_admin_bar_pair ],
    [ 'nowrap-asymmetry'       => \&check_nowrap_asymmetry ],
    [ 'preview-route-targets'  => \&check_preview_routes ],
    [ 'demo-notice-wording'    => \&check_demo_notice_wording ],
    [ 'gated-screens-pinned'   => \&check_gated_screens ],
    [ 'hidden-attribute-works' => \&check_hidden_attribute ],
    [ 'no-mojibake'            => \&check_no_mojibake ],
    [ 'lede-copy-parity'       => \&check_lede_copy ],
    [ 'doc-citations-resolve'  => \&check_doc_citations ],
    [ 'injected-js-escapes'    => \&check_injected_escapes ],
    [ 'scoped-link-honesty'    => \&check_scoped_links ],
);

if ( grep { '--list' eq $_ } @ARGV ) {
    print "$_->[0]\n" for @checks;
    exit 0;
}

my $failed  = 0;
my $skipped = 0;
my $errored = 0;
my @absent_local;

for my $check (@checks) {
    my ( $name, $code ) = @$check;
    my @problems = eval { $code->() };

    if ($@) {
        my $err = $@;
        $err =~ s/\s+\z//;

        # preview.html is the local, key-carrying copy and is gitignored, so it
        # is absent in CI by design. Skipping is the honest outcome there —
        # loudly, on its own line, never folded into the pass count.
        if ( $err =~ /^\Qlocal-only file missing: \E(.+)/ ) {
            $skipped++;
            printf "skip    %s\n", $name;
            print  "          $1 is not in the repository (local-only) — nothing to compare\n";
            next;
        }

        # A check that died did not find a problem — it found nothing at all,
        # because it never finished looking. That is missing coverage, and it
        # must not read like an ordinary finding: `NOT OK` alongside a real
        # failure invites someone to fix the reported line and move on, when
        # what actually needs fixing is the check.
        $errored++;
        printf "ERROR   %s\n", $name;
        print  "          the check itself failed to run, so nothing was verified\n";
        print  "          $err\n";
        next;
    }

    if (@problems) {
        $failed++;
        printf "NOT OK  %s\n", $name;
        print  "          $_\n" for @problems;
    } else {
        printf "ok      %s\n", $name;
    }
}

my $ran = scalar(@checks) - $skipped;

print "\nnot compared (local-only, absent here): $_\n" for @absent_local;

if ($errored) {
    print "\n$errored of $ran checks could not run — that is missing coverage, not a pass.\n";
    print "Fix the check itself before trusting this run";
    print "; $failed other check(s) also failed" if $failed;
    print ".\n";
    exit 1;
}

if ($failed) {
    print "\n$failed of $ran checks failed";
    print " ($skipped skipped)" if $skipped;
    print "\n";
    exit 1;
}

print "\nall $ran contract checks pass";
print " ($skipped skipped — local-only files absent)" if $skipped;
print "\n";
exit 0;

# ---------------------------------------------------------------------------
# helpers
# ---------------------------------------------------------------------------

sub slurp {
    my ($rel) = @_;
    my $path = File::Spec->catfile( $root, split m{/}, $rel );
    open( my $fh, '<:encoding(UTF-8)', $path ) or die "cannot read $rel: $!";
    local $/;
    return scalar <$fh>;
}

# Same as slurp, for files that legitimately only exist on a developer's
# machine. Raises the sentinel the runner turns into a visible `skip`, so an
# absent local file never reads as a passing check.
sub slurp_local {
    my ($rel) = @_;
    die "local-only file missing: $rel\n" unless -e File::Spec->catfile( $root, split m{/}, $rel );
    return slurp($rel);
}

# For checks that sweep several files, only one of which is local-only: drop
# that file and keep the coverage of the rest, but remember what was dropped
# so the run can say so out loud instead of quietly checking less.
sub skip_absent_local {
    my ($rel) = @_;
    return 0 unless $rel eq $LIVE;
    return 0 if -e File::Spec->catfile( $root, split m{/}, $rel );
    push @absent_local, $rel unless grep { $_ eq $rel } @absent_local;
    return 1;
}

sub agent_files {
    my $dir = File::Spec->catdir( $root, split m{/}, $AGENTS );
    opendir( my $dh, $dir ) or die "cannot read $AGENTS: $!";
    my @f = sort grep { /\.md$/ && !/^_/ } readdir $dh;
    closedir $dh;
    return @f;
}

# The prompt a .md file contributes: front matter and HTML comments removed.
# Mirrors what sync-agents.pl inlines and what EduAI_Agents reads.
sub markdown_body {
    my ($text) = @_;
    $text =~ s/^\x{FEFF}//;
    $text =~ s/^---\r?\n.*?\r?\n---\r?\n//s;
    $text =~ s/<!--.*?-->//gs;
    $text =~ s/\r\n/\n/g;
    $text =~ s/^\s+//;
    $text =~ s/\s+\z//;
    return $text;
}

# Unescape a JavaScript double-quoted string literal.
sub js_string {
    my ($lit) = @_;
    $lit =~ s/\\n/\n/g;
    $lit =~ s/\\t/\t/g;
    $lit =~ s/\\"/"/g;
    $lit =~ s/\\\\/\\/g;
    return $lit;
}

# First difference between two strings, as a human-readable line.
sub first_diff {
    my ( $got, $want, $got_label, $want_label ) = @_;
    my @a = split /\n/, $got;
    my @b = split /\n/, $want;
    my $max = @a > @b ? $#a : $#b;

    for my $i ( 0 .. $max ) {
        my $x = defined $a[$i] ? $a[$i] : '<line missing>';
        my $y = defined $b[$i] ? $b[$i] : '<line missing>';
        next if $x eq $y;
        return sprintf( "line %d\n            %s: %s\n            %s: %s",
            $i + 1, $got_label, $x, $want_label, $y );
    }
    return 'differ only in trailing whitespace';
}

# PHP source with comments blanked to spaces, preserving every byte offset so
# positions computed from it still index the original file.
#
# Offsets matter here: check_abspath_tripwires() compares where a tripwire is
# armed against where the require happens, and a file that merely *mentions*
# register_shutdown_function in a docblock above the require would otherwise
# read as compliant. Prose describing a guard is not a guard.
sub php_code_only {
    my ($src) = @_;

    # Block comments, including docblocks. Newlines are kept so line numbers and
    # offsets do not shift.
    $src =~ s{/\*.*?\*/}{ my $m = $&; $m =~ s/[^\n]/ /g; $m }gse;

    # Line comments, both spellings.
    $src =~ s{(//|\#)[^\n]*}{ ' ' x length($&) }ge;

    return $src;
}

# Body of a top-level `function NAME(...) { ... }`, closing brace at column 0.
sub js_function {
    my ( $src, $name ) = @_;
    return $src =~ /^function \Q$name\E\s*\([^)]*\)\s*\{(.*?)^\}/ms ? $1 : undef;
}

# ---------------------------------------------------------------------------
# checks
# ---------------------------------------------------------------------------

# The claim: "Agent definitions and house rules are inlined from the plugin's
# agents/*.md at build time, so this page sends byte-for-byte what the plugin
# sends" (tools/agent-test.html, and _house-rules.md says the same). True only
# for as long as somebody remembers to re-run scripts/sync-agents.pl.
sub check_agent_prompts {
    my $html = slurp($TESTPG);
    my @problems;

    my ($rules) = $html =~ /var HOUSE_RULES\s*=\s*"((?:\\.|[^"\\])*)"/s;
    if ( !defined $rules ) {
        push @problems, "HOUSE_RULES literal not found in $TESTPG";
    } else {
        my $want = markdown_body( slurp("$AGENTS/_house-rules.md") );
        my $got  = js_string($rules);
        push @problems, "house rules have drifted: " . first_diff( $got, $want, 'page', ' md ' )
            if $got ne $want;
    }

    my ($block) = $html =~ /var LIVE_AGENTS\s*=\s*\{(.*?)\n\};/s;
    return ( @problems, "LIVE_AGENTS literal not found in $TESTPG" ) if !defined $block;

    while ( $block =~ /"?([\w-]+)"?\s*:\s*\{(.*?)\n\s*\}/gs ) {
        my ( $id, $obj ) = ( $1, $2 );
        my ($lit) = $obj =~ /"?prompt"?\s*:\s*"((?:\\.|[^"\\])*)"/s;

        if ( !defined $lit ) {
            push @problems, "$id: no prompt string in the inlined registry";
            next;
        }

        my $file = "$AGENTS/$id.md";
        my $path = File::Spec->catfile( $root, split m{/}, $file );
        if ( !-e $path ) {
            push @problems, "$id: inlined on the page but $file does not exist";
            next;
        }

        my $got  = js_string($lit);
        my $want = markdown_body( slurp($file) );
        push @problems, "$id has drifted from $file: " . first_diff( $got, $want, 'page', ' md ' )
            if $got ne $want;
    }

    return @problems;
}

# Every agent file should reach the test page, so "add a file and it appears"
# holds there too and not only in the plugin.
sub check_agent_registry {
    my $html = slurp($TESTPG);
    my ($block) = $html =~ /var LIVE_AGENTS\s*=\s*\{(.*?)\n\};/s;
    return ("LIVE_AGENTS literal not found in $TESTPG") if !defined $block;

    my %inlined;
    $inlined{$1} = 1 while $block =~ /"?([\w-]+)"?\s*:\s*\{/gs;

    my @problems;
    for my $file ( agent_files() ) {
        ( my $id = $file ) =~ s/\.md$//;
        push @problems, "$id: in $AGENTS but missing from $TESTPG — re-run scripts/sync-agents.pl"
            unless $inlined{$id};
    }

    return @problems;
}

# Three copies of the same registry: the plugin, the design preview and the
# test page. A stale model id here is a 404 from the provider at runtime.
sub check_providers {
    my %want = extract_php_models( slurp($CLAUDE) );
    return ("could not parse the model registry out of $CLAUDE") unless keys %want;

    my @problems;
    for my $file ( $PREVIEW, $TESTPG, $LIVE ) {
        next if skip_absent_local($file);
        my %got = extract_js_models( slurp($file) );

        if ( !keys %got ) {
            push @problems, "$file: could not parse a PROVIDERS registry";
            next;
        }

        for my $key ( sort keys %want ) {
            my $mine = defined $got{$key} ? $got{$key} : '<missing>';
            push @problems, "$file: $key is '$mine', $CLAUDE says '$want{$key}'"
                if $mine ne $want{$key};
        }
        for my $key ( sort keys %got ) {
            push @problems, "$file: $key is not in $CLAUDE" unless exists $want{$key};
        }
    }

    return @problems;
}

sub extract_php_models {
    my ($src) = @_;
    my ($body) = $src =~ /function providers\(\)\s*:\s*array\s*\{(.*?)\n\t\}/s;
    return () unless defined $body;

    my %models;
    while ( $body =~ /'(\w+)'\s*=>\s*array\((.*?)\n\t\t\t\)/gs ) {
        my ( $provider, $chunk ) = ( $1, $2 );
        next unless $chunk =~ /'models'\s*=>\s*array\((.*?)\)/s;
        my $list = $1;
        $models{"$provider.$1"} = $2 while $list =~ /'(\w+)'\s*=>\s*'([^']+)'/g;
    }
    return %models;
}

sub extract_js_models {
    my ($src) = @_;
    my ($block) = $src =~ /(?:var\s+)?PROVIDERS\s*=\s*\{(.*?)\n\};/s;
    return () unless defined $block;

    my %models;
    while ( $block =~ /(\w+)\s*:\s*\{(.*?)models\s*:\s*\{([^}]*)\}/gs ) {
        my ( $provider, $list ) = ( $1, $3 );
        $models{"$provider.$1"} = $2 while $list =~ /(\w+)\s*:\s*'([^']+)'/g;
    }
    return %models;
}

# EduAI_REST::to_html() is the reference renderer. Both HTML pages carry a
# hand-written JavaScript port and both claim parity with it in a comment.
# Compare the set of constructs each one emits, which is what a student
# actually sees the difference in.
sub check_markdown {
    my $php = slurp($REST);
    my ($ref) = $php =~ /(public static function to_html\(.*?\n\t\})/s;
    return ("could not find to_html() in $REST") unless defined $ref;

    my %rule = (
        '<pre><code>' => 'fenced code block',
        '<code>'      => 'inline code',
        '<h4>'        => '### heading',
        '<h3>'        => '## / # heading',
        '<strong>'    => '**bold**',
        '<em>'        => '*italic*',
        '<li>'        => 'list item',
        '<ul>'        => 'list wrapper',
        '<p>'         => 'paragraph',
    );

    my @expected = sort grep { -1 != index( $ref, $_ ) } keys %rule;

    # A rule set that came back empty means the parse above failed, and every
    # port below would then pass without being looked at. Fail loudly instead.
    return ("parsed no rendering rules out of to_html() — this check would otherwise pass vacuously")
        if @expected < 5;

    my @problems;
    for my $spec ( [ $PREVIEW, 'md' ], [ $LIVE, 'md' ], [ $TESTPG, 'toHtml' ] ) {
        my ( $file, $fn ) = @$spec;
        next if skip_absent_local($file);
        my $body = js_function( slurp($file), $fn );

        if ( !defined $body ) {
            push @problems, "$file: could not find function $fn()";
            next;
        }

        for my $tag (@expected) {
            push @problems,
                "$file: $fn() never emits $tag — $rule{$tag} renders differently here than on the real site"
                if -1 == index( $body, $tag );
        }
    }

    return @problems;
}

# design/preview.html is the shippable copy of preview.html. They are allowed
# to differ on exactly one thing: the API key baked into the working copy.
sub check_preview_copies {
    my $live = slurp_local($LIVE);
    my $ship = slurp($PREVIEW);

    for ( \$live, \$ship ) {
        $$_ =~ s/^var API_KEY = '[^']*';/var API_KEY = '<key>';/m;
    }

    return () if $live eq $ship;
    return ( "$LIVE and $PREVIEW have diverged beyond the API key: "
            . first_diff( $live, $ship, 'live', 'ship' ) );
}

sub check_preview_keyless {
    my $ship = slurp($PREVIEW);
    my ($key) = $ship =~ /^var API_KEY = '([^']*)';/m;

    return ("no API_KEY line found in $PREVIEW") unless defined $key;
    return ("$PREVIEW has a key baked in — it is the copy that ships") if '' ne $key;
    return ();
}

# The seam contract: inc/auth-flow.php (back end) redirects with ?flag=value,
# scholaris_auth_notices() in inc/auth.php (front end) turns those into
# messages, and docs/05-frontend-handoff.md is where the two halves agree.
# A flag with no message renders a silent blank failure for the student.
sub check_auth_flags {
    my $flow    = slurp($FLOW);
    my $auth    = slurp($AUTH);
    my $handoff = slurp($HANDOFF);

    my %emitted;

    # Direct bounces: array( 'login' => 'throttled' ) and friends.
    while ( $flow =~ /'(login|register|lostpw|checkemail)'\s*=>\s*'([a-z_]+)'/g ) {
        $emitted{$1}{$2} = 1;
    }

    # The register bounce maps a WP_Error code to a flag through $flags.
    if ( $flow =~ /\$flags\s*=\s*array\((.*?)\n\t\);/s ) {
        my $map = $1;
        while ( $map =~ /'\w+'\s*=>\s*'([a-z_]+)'/g ) {
            $emitted{register}{$1} = 1;
        }
    }
    $emitted{register}{generic} = 1 if $flow =~ /\?\?\s*'generic'/;

    my @problems;

    for my $param ( sort keys %emitted ) {
        for my $flag ( sort keys %{ $emitted{$param} } ) {
            push @problems, "?$param=$flag is emitted by $FLOW but has no message in $AUTH"
                unless handled( $auth, $param, $flag );
            push @problems, "?$param=$flag is emitted by $FLOW but is not in the table in $HANDOFF"
                unless -1 != index( $handoff, $flag );
        }
    }

    return @problems;
}

# The summariser is the one model call that does not get the full house rules
# — Scope and Calculations are written for answering a question and would tell
# a summary to behave like an answer. Notation is different: it is the section
# rendering depends on, because neither to_html() nor md() handles LaTeX, so
# without it a maths summary reaches the student as literal \(a_0\).
#
# Note what this checks and why. Parity between the plugin and the preview
# would NOT have caught the original defect: both sides were consistently
# wrong, agreeing with each other perfectly while dropping the rule. The
# presence assertion is the regression guard; parity only stops them drifting
# apart afterwards. Both are here, and the presence half is the one that
# matters.
sub check_summariser_prompt {
    my $rest = slurp($REST);
    my @problems;

    my ($summarize) = $rest =~ /(public static function summarize\(.*?\n\t\})/s;
    return ("could not find summarize() in $REST") unless defined $summarize;

    my ($call) = $summarize =~ /(EduAI_Claude::message\(.*?\n\t\t\);)/s;
    return ("could not find the model call inside summarize()") unless defined $call;

    # --- the guard: Notation has to reach the model -------------------------
    my $plugin_has_notation =
           $call =~ /house_rules_section\(\s*'Notation'\s*\)/
        || $call =~ /Notation/;

    push @problems,
        "$REST: summarize() sends no Notation rule — LaTeX in a summary would reach the student raw"
        unless $plugin_has_notation;

    # The accessor's hard-coded fallback is a second copy of the rule by
    # design, for when the file is missing. A second copy is a second thing
    # to forget, so it has to still say what the file says.
    my $agents = slurp($AGENTSPHP);
    if ( $agents =~ /'Notation'\s*===\s*\$heading.*?return\s+(.*?);\n/s ) {
        my $fallback = php_concat($1);
        my $source   = notation_section( slurp("$AGENTS/_house-rules.md") );

        if ( !defined $source ) {
            push @problems, "$AGENTS/_house-rules.md has no Notation section for the accessor to read";
        } elsif ( squash($fallback) ne squash($source) ) {
            push @problems,
                "$AGENTSPHP: the hard-coded Notation fallback no longer matches _house-rules.md: "
                . first_diff( $fallback, $source, 'fallback', '  file  ' );
        }
    }

    # --- and the same rule on the pages, plus base-prompt parity ------------
    my ($base) = $call =~ /"((?:\\.|[^"\\])*)"/;
    my $plugin_base = php_concat( join ' . ', map { "\"$_\"" }
        ( $call =~ /"((?:\\.|[^"\\])*)"/g ) );
    $plugin_base = squash($plugin_base);
    $plugin_base =~ s/\s*Notation.*\z//s;    # the section is appended at runtime

    for my $file ( $PREVIEW, $LIVE ) {
        # Drop the local-only copy where it is absent rather than skipping the
        # whole check — the plugin and the shippable page still have to be
        # compared in CI, which is the only place this branch is taken.
        next if skip_absent_local($file);
        my $src = slurp($file);

        my ($lit) = $src =~ /var SUM_SYSTEM\s*=\s*(.*?);\n/s;
        if ( !defined $lit ) {
            push @problems, "$file: no SUM_SYSTEM found";
            next;
        }

        my $prompt = php_concat($lit);    # JS and PHP concatenation look alike here

        # Either shape counts: the page may inline the rule, or pull it from
        # its own HOUSE_RULES the way the plugin pulls it from the .md. The
        # accessor form is the better one and is what shipped, so test the
        # expression rather than only the string literals in it.
        push @problems,
            "$file: SUM_SYSTEM carries no Notation rule — summaries render LaTeX raw, unlike the plugin"
            unless $lit =~ /houseRulesSection\(\s*'Notation'\s*\)/ || $prompt =~ /Notation/;

        my $squashed = squash($prompt);
        push @problems,
            "$file: SUM_SYSTEM's base text has drifted from summarize() in $REST"
            unless -1 != index( $squashed, $plugin_base );
    }

    return @problems;
}

# Join a run of concatenated double-quoted string literals (PHP `.` or JS `+`)
# and unescape them. Anything that is not a literal — a function call in the
# middle of the expression — is simply skipped.
sub php_concat {
    my ($expr) = @_;
    my $out = '';

    while ( $expr =~ /"((?:\\.|[^"\\])*)"/g ) {
        my $lit = $1;
        $lit =~ s/\\n/\n/g;
        $lit =~ s/\\t/\t/g;
        $lit =~ s/\\"/"/g;
        $lit =~ s/\\\\/\\/g;
        $out .= $lit;
    }

    return $out;
}

sub notation_section {
    my ($md) = @_;
    $md =~ s/<!--.*?-->//gs;
    return $md =~ /^(Notation\n(?:^-.*(?:\n|\z))+)/m ? $1 : undef;
}

sub squash {
    my ($s) = @_;
    $s =~ s/\s+/ /g;
    $s =~ s/^\s+|\s+\z//g;
    return $s;
}

# The preview inlines fixtures/exam-sample.json as EXAM_FIXTURE, for the same
# reason it inlines the house rules: a file:// page cannot fetch a sibling file
# without tripping CORS. So the demo renders a copy, and a copy goes stale the
# moment the fixture is regenerated — which is exactly what happened when the
# `expected` values were rewritten to mark-scheme form and the pages kept
# showing the old answers.
#
# Compared as decoded JSON rather than as text: the inline is formatted one
# question per line while the fixture is pretty-printed, so a string compare
# would fail on whitespace forever and teach everyone to ignore it.
sub check_fixture_inline {
    my $raw = slurp($FIXTURE);
    my $json = JSON::PP->new->utf8(0);

    my $want = eval { $json->decode($raw) };
    return ("$FIXTURE is not valid JSON: $@") if $@;

    my @problems;

    for my $file ( $PREVIEW, $LIVE ) {
        next if skip_absent_local($file);

        my ($lit) = slurp($file) =~ /var EXAM_FIXTURE\s*=\s*(\{.*?\n\});/s;
        if ( !defined $lit ) {
            push @problems, "$file: no EXAM_FIXTURE literal found";
            next;
        }

        my $got = eval { $json->decode($lit) };
        if ($@) {
            my $err = $@;
            $err =~ s/ at \S+ line \d+\.?\s*\z//;    # the reader wants the JSON fault, not our line number
            $err =~ s/\s+\z//;
            push @problems, "$file: EXAM_FIXTURE is not decodable as JSON — $err";
            next;
        }

        my $diff = json_diff( $got, $want, 'EXAM_FIXTURE' );
        push @problems, "$file has drifted from $FIXTURE at $diff" if defined $diff;
    }

    return @problems;
}

# First difference between two decoded JSON structures, as a path a human can
# go and look at — "EXAM_FIXTURE.questions[2].expected" beats "they differ" on
# a five-kilobyte fixture.
sub json_diff {
    my ( $got, $want, $path ) = @_;

    my $rg = ref $got;
    my $rw = ref $want;

    return "$path: " . ( $rg || 'scalar' ) . " inline, " . ( $rw || 'scalar' ) . " in the fixture"
        if $rg ne $rw;

    if ( 'HASH' eq $rg ) {
        for my $k ( sort keys %$want ) {
            return "$path.$k: in the fixture, missing from the inlined copy"
                unless exists $got->{$k};
            my $d = json_diff( $got->{$k}, $want->{$k}, "$path.$k" );
            return $d if defined $d;
        }
        for my $k ( sort keys %$got ) {
            return "$path.$k: inlined but not in the fixture" unless exists $want->{$k};
        }
        return undef;
    }

    if ( 'ARRAY' eq $rg ) {
        return "$path: " . scalar(@$got) . ' entries inline, ' . scalar(@$want) . ' in the fixture'
            if @$got != @$want;
        for my $i ( 0 .. $#$want ) {
            my $d = json_diff( $got->[$i], $want->[$i], $path . '[' . $i . ']' );
            return $d if defined $d;
        }
        return undef;
    }

    # Leaves, including JSON::PP::Boolean objects, which stringify to 1 and 0.
    my $g = defined $got  ? "$got"  : '<null>';
    my $w = defined $want ? "$want" : '<null>';
    return undef if $g eq $w;

    return "$path:\n            inline : " . clip($g) . "\n            fixture: " . clip($w);
}

sub clip {
    my ($s) = @_;
    $s =~ s/\s+/ /g;
    return length($s) > 110 ? substr( $s, 0, 107 ) . '...' : $s;
}

# The theme must account for the WordPress admin bar, which only exists for
# signed-in users with rights — so every logged-out check is blind to it. The
# owner's homepage was visibly broken while fourteen contract checks and seven
# live harnesses were green.
#
# READ THIS BEFORE TRUSTING A GREEN HERE. This check is weaker than it looks:
# it proves the theme *says something* about the admin bar, not that the
# geometry is right. It passes the moment any `admin-bar` rule exists, with any
# value in it — a rule offsetting the header by 5px, or by a hard-coded 32px
# that never becomes 46px on mobile, sails through. Only
# scripts/admin-bar-geometry.js, run in a browser signed in as an
# administrator, measures whether the header is actually clear. Do not record
# this as covering the occlusion; it covers the *regression* of the fix being
# deleted, which is a different and much smaller thing.
sub check_admin_bar_offset {
    my $css = slurp($MAINCSS);
    my @problems;

    my ($block) = $css =~ /(body\.admin-bar[^\{]*\{[^\}]*\})/s;

    push @problems, "$MAINCSS has no body.admin-bar rule — the header will sit under the admin bar for every signed-in user"
        unless defined $block;

    return @problems unless defined $block;

    # The header offset specifically must come from core's own variable. A
    # literal works today and silently stops working at core's 782px
    # breakpoint, where the bar becomes 46px — a second number to drift, which
    # is the whole failure mode this was written for.
    #
    # Look at the header rules themselves, not at the file. The first version
    # of this asked whether the variable appeared anywhere inside any
    # `admin-bar` block, and the `::before` spacer uses it too — so hard-coding
    # `top: 32px` on the header passed, which is precisely the mobile bug the
    # message claims to catch. "The theme mentions the variable somewhere" and
    # "the header offset derives from it" are different assertions.
    my @header_rules = $css =~ /(body\.admin-bar\s+\.site-header\s*\{[^\}]*\})/gs;

    push @problems, "$MAINCSS has no body.admin-bar .site-header rule to offset the header with"
        unless @header_rules;

    my $derives = grep { /--wp-admin--admin-bar--height/ } @header_rules;

    # `top: 0` is legitimate below 782px, where the bar is absolute and scrolls
    # away — that rule is *supposed* to be a literal zero. Any other literal is
    # a hand-maintained number.
    my @literals = grep { !/--wp-admin--admin-bar--height/ && /top\s*:\s*(?!0\b)[\d.]+/ } @header_rules;

    push @problems,
        "$MAINCSS offsets the header with a literal rather than core's --wp-admin--admin-bar--height; that number is wrong at core's 782px breakpoint, where the bar becomes 46px"
        if @header_rules && ( !$derives || @literals );

    # Disabling core's own html margin is what makes the theme's spacer the
    # single source of the offset. Without it both apply and the page gains a
    # phantom gap.
    my $functions = slurp($THEMEFN);
    push @problems,
        "$THEMEFN does not disable core's admin-bar margin (add_theme_support 'admin-bar' with a false callback), so core's offset and the theme's will both apply"
        unless $functions =~ /add_theme_support\(\s*'admin-bar'/;

    return @problems;
}

# Every constant the theme and plugin read must be findable in the hosting
# guide, and no single PHP block there may define the same constant twice.
# The wp-config snippets in docs/03 are written to be pasted wholesale, so two
# lines defining one constant is not "here are your options" to a reader in a
# hurry -- PHP keeps the first, warns about the second, and the setting they
# actually wanted is silently the one that lost.
sub check_wpconfig_examples {
    my $doc = slurp($HOSTING);
    my @problems;

    my @blocks = $doc =~ /```php\n(.*?)```/gs;

    for my $block (@blocks) {
        my %seen;
        $seen{$1}++ while $block =~ /^\s*define\(\s*'(\w+)'/gm;

        for my $const ( sort grep { $seen{$_} > 1 } keys %seen ) {
            push @problems,
                "$HOSTING: one php block defines $const $seen{$const} times — "
                . 'alternatives need commenting out, or a reader pasting the block gets the first silently';
        }
    }

    # The constant the code actually reads has to be the one documented.
    my $flow = slurp($FLOW);
    while ( $flow =~ /defined\(\s*'(SCHOLARIS_\w+)'\s*\)/g ) {
        push @problems, "$FLOW reads $1 but $HOSTING never mentions it"
            if -1 == index( $doc, $1 );
    }

    return @problems;
}

# A flag is handled if the block that reads its query parameter either names it
# explicitly -- as an elseif comparison or a key in the message map, the two
# shapes scholaris_auth_notices() uses -- or ends in a catch-all, which several
# blocks deliberately do so an unknown value still says something sensible.
sub handled {
    my ( $auth, $param, $flag ) = @_;
    my $block = notice_block( $auth, $param );
    return 0 unless defined $block;

    return 1 if $block =~ /'\Q$flag\E'\s*===\s*\$flag/;
    return 1 if $block =~ /'\Q$flag\E'\s*=>\s*(?:sprintf|__|get_option)/;

    # Catch-alls: a bare `} else {`, or `?? $..['generic']` on the map lookup.
    return 1 if $block =~ /\}\s*else\s*\{/;
    return 1 if $block =~ /\?\?\s*\$\w+\[/;

    return 0;
}

# The stretch of scholaris_auth_notices() that reads one query parameter:
# from its isset() up to wherever the next parameter is read.
sub notice_block {
    my ( $auth, $param ) = @_;
    return undef unless $auth =~ /isset\(\s*\$_GET\['\Q$param\E'\]\s*\)/g;

    my $start = pos($auth);
    my $rest  = substr( $auth, $start );
    $rest =~ s/isset\(\s*\$_GET\['\w+'\]\s*\).*\z//s;

    return $rest;
}

# PrepareME stores exams as JSON and three components read them: the PHP
# scorer, the grading prompt and the answer form. The fixture is what all three
# are built against before generation is wired, so it has to actually conform to
# docs/06-eduai-rebuild.md §5 — and the band split is read back out of the doc
# so that changing the table there without the fixture fails here.
#
# The zero-questions guard is deliberate and load-bearing: a check that silently
# passes on an empty or unparsed array is worse than no check, because it reads
# as coverage.
sub check_exam_fixture {
    require JSON::PP;

    my $path = "wp-content/plugins/eduai-assistant/fixtures/exam-sample.json";
    my $raw  = eval { slurp($path) };
    return ("$path is missing — it is what unblocks front-end and back-end") unless defined $raw;

    my $exam = eval { JSON::PP->new->decode($raw) };
    return ("$path is not valid JSON: $@") if $@ || !$exam;

    my @err;

    push @err, "schema_version must be 1, got " . ( $exam->{schema_version} // 'undef' )
        unless ( $exam->{schema_version} // 0 ) == 1;
    push @err, 'title is empty' unless length( $exam->{title} // '' );

    my $qs = $exam->{questions};
    unless ( ref $qs eq 'ARRAY' ) {
        return ( @err, 'questions is not an array' );
    }

    my $n = scalar @$qs;
    return ( @err, 'questions is empty — this check must never pass on zero questions' ) unless $n;

    # Band split comes from the table in the doc, not from a constant here, so
    # editing the table without the fixture fails. Scoped to §4 — other sections
    # have tables too, and the rows are indented inside a numbered list.
    my $doc = slurp('docs/06-eduai-rebuild.md');
    my ($sec4) = $doc =~ /^##\s*4\.(.*?)(?=^##\s|\z)/ms;

    my %splits;
    if ( defined $sec4 ) {
        while ( $sec4 =~ /^\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|/mg ) {
            $splits{$1} = { easy => $2, medium => $3, hard => $4 };
        }
    }
    push @err, 'no band-split table found in docs/06-eduai-rebuild.md section 4' unless %splits;

    if (%splits) {
        push @err, "exam length $n is not one of the allowed lengths ("
            . join( ', ', sort { $a <=> $b } keys %splits ) . ')'
            unless $splits{$n};
    }

    my %seen_band;
    my %seen_type;
    my $marks_total   = 0;
    my $mid_index_hit = 0;
    my @order;

    for my $i ( 0 .. $#$qs ) {
        my $q  = $qs->[$i];
        my $id = $q->{id} // '?';

        push @err, "q$id: id must be " . ( $i + 1 ) . " (1..n in presentation order)"
            unless ( $q->{id} // -1 ) == $i + 1;

        my $band = $q->{band} // '';
        push @err, "q$id: band '$band' is not easy/medium/hard"
            unless $band =~ /^(?:easy|medium|hard)$/;
        $seen_band{$band}++;
        push @order, $band;

        my $type = $q->{type} // '';
        push @err, "q$id: type '$type' is not mcq/short" unless $type =~ /^(?:mcq|short)$/;
        $seen_type{$type}++;

        my $marks = $q->{marks};
        if ( !defined $marks || $marks !~ /^\d+$/ || $marks < 1 ) {
            push @err, "q$id: marks must be a positive integer";
        }
        else {
            $marks_total += $marks;
        }

        if ( $type eq 'mcq' ) {
            my $opts = $q->{options};
            if ( ref $opts ne 'ARRAY' || @$opts != 4 ) {
                push @err, "q$id: mcq needs exactly 4 options";
            }
            my $ai = $q->{answer_index};
            if ( !defined $ai || $ai !~ /^\d+$/ || $ai > 3 ) {
                push @err, "q$id: answer_index must be an integer 0..3 (0-based, see §5.1)";
            }
            elsif ( $ai != 0 && $ai != 3 ) {
                $mid_index_hit = 1;
            }
            push @err, "q$id: mcq needs a non-empty explanation"
                unless length( $q->{explanation} // '' );
        }
        elsif ( $type eq 'short' ) {
            push @err, "q$id: short answer needs a non-empty expected"
                unless length( $q->{expected} // '' );
        }
    }

    # Ordering: easy then medium then hard, never going back up.
    my %rank = ( easy => 0, medium => 1, hard => 2 );
    for my $i ( 1 .. $#order ) {
        next unless defined $rank{ $order[$i] } && defined $rank{ $order[ $i - 1 ] };
        if ( $rank{ $order[$i] } < $rank{ $order[ $i - 1 ] } ) {
            push @err, "questions are not ordered easy -> medium -> hard (position " . ( $i + 1 ) . ')';
            last;
        }
    }

    if ( $splits{$n} ) {
        for my $band (qw(easy medium hard)) {
            my $want = $splits{$n}{$band};
            my $got  = $seen_band{$band} // 0;
            push @err, "band '$band': doc table says $want for a $n-question exam, fixture has $got"
                unless $want == $got;
        }
    }

    # Both grading paths must be exercised — MCQ is scored in PHP, short by the
    # model, and a fixture with only one type leaves half the grader unbuilt.
    push @err, 'fixture has no mcq questions — the PHP scorer has nothing to build against'
        unless $seen_type{mcq};
    push @err, 'fixture has no short questions — the model grader has nothing to build against'
        unless $seen_type{short};

    # Off-by-one canary: if every answer_index were 0 or 3, a 0-vs-1-based
    # disagreement could survive the whole test suite.
    push @err, 'no mcq uses an answer_index of 1 or 2 — an off-by-one could pass unnoticed'
        unless $mid_index_hit;

    push @err, 'total marks is zero' unless $marks_total > 0;

    return @err;
}

# docs/05 is the seam front-end builds against: it names the PrepareME routes,
# their verbs and their response shapes. A path that drifts from what
# register_rest_route() actually registers gives the front end a 404 at
# integration time, long after both halves looked finished.
#
# Scope is deliberately one-directional. Every route the doc *claims* must
# exist; the doc is not required to list every route the plugin registers,
# because /chat, /summarize and /history predate this document and are described
# elsewhere. Claiming an endpoint that is not there is the failure that costs a
# day; omitting one that is only costs a lookup.
sub check_exam_routes {
    my $rest = slurp($REST);
    my $doc  = slurp($HANDOFF);
    my @err;

    # Registered: namespace is a constant, so only the path argument varies.
    my %registered;
    while ( $rest =~ /register_rest_route\(\s*self::NS\s*,\s*'([^']+)'/g ) {
        $registered{ normalise_route($1) } = 1;
    }
    return ('no register_rest_route() calls found — the check cannot mean anything') unless %registered;

    # Documented: any /eduai/v1/... path mentioned anywhere in the handoff.
    my %documented;
    while ( $doc =~ m{/eduai/v1(/[A-Za-z0-9_\-/<>?\(.+)\[\]]*)}g ) {
        my $path = $1;
        $path =~ s/[`*.,;:)]+\z//;    # trailing markdown and punctuation
        next if '' eq $path || '/' eq $path;
        $documented{ normalise_route($path) } = 1;
    }
    return ('no /eduai/v1 routes documented in ' . $HANDOFF) unless %documented;

    for my $path ( sort keys %documented ) {
        push @err, "$HANDOFF documents $path but register_rest_route() has no such route"
            unless $registered{$path};
    }

    # The three PrepareME routes are the reason this check exists, so their
    # absence from either side is called out by name rather than passing
    # quietly because the doc happened not to mention them.
    for my $required ( '/exam', '/exam/<id>', '/exam/<id>/submit' ) {
        push @err, "$required is not registered" unless $registered{$required};
        push @err, "$required is not documented in $HANDOFF" unless $documented{$required};
    }

    return @err;
}

# `/exam/(?P<id>\d+)/submit` and `/exam/<id>/submit` and `/exam/0` are the same
# route wearing three notations. Reduce all of them to the middle one.
sub normalise_route {
    my ($path) = @_;
    $path =~ s/\(\?P<\w+>[^)]*\)/<id>/g;    # PHP named capture
    $path =~ s{/\d+}{/<id>}g;               # a concrete id used as an example
    $path =~ s{/+\z}{};                     # trailing slash
    return $path;
}

# AiCalc exists to make one distinction obvious: whether the answer was computed
# exactly in code or produced by a model. The preview page and the real page are
# two surfaces stating that same distinction, and the moment they word it
# differently a student cannot tell whether they are looking at the same claim.
# The preview is the design reference, so the plugin's labels have to appear in
# it verbatim.
sub check_calc_labels {
    my $php     = slurp('wp-content/plugins/eduai-assistant/includes/class-eduai-shortcodes.php');
    my $preview = slurp($PREVIEW);
    my @err;

    # Whole labels on both sides, compared as complete strings. An earlier
    # version asked only whether the plugin's label appeared *somewhere* in the
    # preview, which a truncated label passes: "Computed exactly" is a prefix of
    # "Computed exactly â code, not a model". Mutation testing caught it.
    my %plugin;
    for my $key (qw(exact viaModel)) {
        $plugin{$key} = $1 if $php =~ /'\Q$key\E'\s*=>\s*__\(\s*'(.*?)',/;
    }

    for my $key (qw(exact viaModel)) {
        push @err, "no '$key' label found in class-eduai-shortcodes.php"
            unless defined $plugin{$key};
    }
    return @err if @err;

    # The preview renders each badge as <p class="pathtag pathtag--KIND">TEXT</p>.
    my %design;
    while ( $preview =~ /pathtag--(exact|model)">([^<]+)</g ) {
        $design{ 'exact' eq $1 ? 'exact' : 'viaModel' } = $2;
    }

    for my $key (qw(exact viaModel)) {
        push @err, "no '$key' path tag found in $PREVIEW â the design reference moved"
            unless defined $design{$key};
    }
    return @err if @err;

    for my $key (qw(exact viaModel)) {
        next if $plugin{$key} eq $design{$key};
        push @err, sprintf(
            '%s badge differs: plugin says "%s", %s says "%s"',
            $key, $plugin{$key}, $PREVIEW, $design{$key}
        );
    }

    return @err;
}

# A standalone script that requires a plugin file guarded by
# `defined( 'ABSPATH' ) || exit;` will terminate with status 0, printing
# nothing, if that constant is missing. CI reads exit codes, so the step goes
# green having tested nothing. This shape has now occurred twice —
# redaction-guard.php and calc-parity.php — so it is a class, not two bugs.
#
# The guard is a register_shutdown_function tripwire armed BEFORE the require.
# Ordering is the entire property: armed afterwards it can never fire, because
# nothing after the require runs when the early exit happens. A check that only
# asks whether the file mentions register_shutdown_function anywhere passes that
# broken shape, so this compares byte offsets.
sub check_abspath_tripwires {
    my $dir = File::Spec->catdir( $root, 'scripts' );

    opendir( my $dh, $dir ) or die "cannot read scripts/: $!";
    my @php = sort grep { /[.]php$/ } readdir $dh;
    closedir $dh;

    my @err;
    my $examined = 0;

    for my $file (@php) {
        # Comments blanked, offsets preserved. Scanning raw source counted a
        # docblock that merely mentioned register_shutdown_function as an armed
        # tripwire, and matched the word "require" in prose — both found by
        # mutation-testing this check against a deliberately broken fixture.
        my $src = php_code_only( slurp("scripts/$file") );

        # Offsets of every tripwire arming, collected once from a copy. A nested
        # m//g over the same scalar shares pos() with the loop below and resets
        # it on every pass, which never terminates.
        my @armed;
        my $scan = $src;
        while ( $scan =~ /register_shutdown_function/g ) {
            push @armed, $-[0];
        }

        # Every require/require_once with a single-quoted path.
        while ( $src =~ /\brequire(?:_once)?\b[^;]*?'([^']+)'/g ) {
            my $target = $1;
            my $at     = $-[0];

            # Resolve relative to scripts/ and skip anything not on disk.
            my $path = $target;
            $path =~ s{^__DIR__\s*[.]\s*}{};
            $path =~ s{^/}{};
            my $full = File::Spec->catfile( $dir, split m{/}, $path );
            next unless -f $full;

            open( my $fh, '<:encoding(UTF-8)', $full ) or next;
            my $body = do { local $/; <$fh> };
            close $fh;

            # Only files that can exit on require are at risk.
            next unless $body =~ /defined[(]\s*'ABSPATH'\s*[)]\s*[|][|]\s*exit/;

            $examined++;

            my $armed = -1;
            for my $pos (@armed) {
                $armed = $pos if $pos < $at && $pos > $armed;
            }

            if ( $armed < 0 ) {
                my $any = ( $src =~ /register_shutdown_function/ ) ? 'only after it' : 'not at all';
                push @err,
                    "scripts/$file requires $path, which exits on a missing ABSPATH, "
                    . "but arms no shutdown tripwire before that require ($any) — "
                    . 'the script would exit 0 printing nothing and CI would read it as a pass';
            }
        }
    }

    # A sweep that examined nothing is not a passing check.
    push @err, 'no scripts/*.php requires an ABSPATH-guarded file — this check examined nothing'
        unless $examined;

    return @err;
}

# Every template root that styles itself with --eduai-* must appear in the
# token declaration list at the top of chat.css.
#
# chat.css says so in a comment, and says "that bug shipped once" — a root
# missing from the list gets no custom properties at all, so every rule built on
# one computes to nothing. The failure is quiet in the worst way: a primary
# button's background falls to transparent while its text, inherited from the
# theme body, still looks deliberate. Until now that rule was prose with nothing
# enforcing it, which is the same shape as the guards in docs/08-handoff.md §4.
#
# The test is not "is every block in the list". Blocks nested inside another
# root inherit its properties, and .eduai-dock legitimately styles nothing with
# tokens — it is a fixed-position wrapper whose child .eduai-app is the themed
# element. So a root is required to be in the list only when some rule whose
# SUBJECT is that block, or a BEM child of it, actually reads a --eduai-*
# value. Matching on the subject rather than anywhere in the selector is what
# keeps `.eduai-dock .eduai-app { ... var(--eduai-radius-l) ... }` from being
# blamed on the dock.
sub check_template_root_tokens {
    my $css = slurp($CHATCSS);
    my @problems;

    # The declaration list: every selector before the block that defines the
    # properties. Anchored on --eduai-brand because that is the first token and
    # the one every other rule ultimately depends on.
    my ($selectors) = $css =~ /((?:^\s*\.[\w-]+\s*,\s*\n)+^\s*\.[\w-]+\s*)\{[^\}]*--eduai-brand:/m;

    unless ( defined $selectors ) {
        return ("$CHATCSS has no --eduai-brand declaration block — the token list this check reads is gone");
    }

    my %declared = map { $_ => 1 } $selectors =~ /\.([\w-]+)/g;

    opendir( my $dh, File::Spec->catdir( $root, $TEMPLATES ) )
        or return ("cannot read $TEMPLATES");
    my @templates = sort grep { /\.php$/ } readdir($dh);
    closedir($dh);

    return ("$TEMPLATES contains no templates — this check would pass vacuously")
        unless @templates;

    for my $file (@templates) {
        my $markup = slurp("$TEMPLATES/$file");

        # The root is the first eduai- class in the file: these templates all
        # open with their root element.
        my ($block) = $markup =~ /class="(eduai-[a-z]+)/;

        next unless defined $block;

        # Rules whose subject is this block or a BEM child of it. The subject is
        # the last compound in the selector, so split on commas, then take what
        # follows the final descendant combinator.
        my $uses_tokens = 0;

        while ( $css =~ /([^\{\}]+)\{([^\}]*)\}/g ) {
            my ( $selector, $body ) = ( $1, $2 );

            next unless $body =~ /var\(\s*--eduai-/;

            for my $one ( split /,/, $selector ) {
                $one =~ s/^\s+|\s+$//g;
                my ($subject) = $one =~ /([^\s>+~]+)$/;
                next unless defined $subject;

                if ( $subject =~ /^\.\Q$block\E(?:__|--|[^\w-]|$)/ ) {
                    $uses_tokens = 1;
                    last;
                }
            }

            last if $uses_tokens;
        }

        next unless $uses_tokens;

        push @problems,
            "$file has root .$block, whose rules read --eduai-* values, but .$block is missing from the token list in $CHATCSS — those rules will compute to nothing"
            unless $declared{$block};
    }

    return @problems;
}

# The nav toggle and the tagline hide must share one breakpoint, in both the
# theme and the shippable preview.
#
# Four places carry the number: main.css hides .brand__tag at it and shows
# .nav-toggle at it, and design/preview.html mirrors both. They are one
# transition — below it the header is a brand plus a toggle, above it a brand
# plus a tagline plus a seven-item row. Move one alone and the transition
# splits into two, producing a band where the tagline is gone but the full nav
# is still trying to fit, which is the overflow the 1200 was measured to avoid.
#
# The value itself is not asserted: three people measured it and the owner ruled
# it final, but a future re-measurement should be free to move it. What must not
# happen is it moving in three places out of four.
sub check_nav_breakpoint {
    my @problems;

    my %found;

    my $css = slurp($MAINCSS);

    # The media wrapping the toggle reveal, and the one wrapping the tag hide.
    # Both are matched from the rule backwards so a reordering of the file does
    # not quietly point this at some other media query.
    if ( $css =~ /\@media\s*\(\s*max-width:\s*(\d+)px\s*\)\s*\{[^\}]*\.nav-toggle\s*\{\s*display:\s*grid/s ) {
        $found{'main.css .nav-toggle'} = $1;
    }

    if ( $css =~ /\@media\s*\(\s*max-width:\s*(\d+)px\s*\)\s*\{\s*\.brand__tag\s*\{\s*display:\s*none/s ) {
        $found{'main.css .brand__tag'} = $1;
    }

    my $preview = slurp($PREVIEW);

    if ( $preview =~ /\@media\s*\(\s*max-width:\s*(\d+)px\s*\)\s*\{[^\}]*\.nav-toggle\s*\{\s*display:\s*grid/s ) {
        $found{'preview.html .nav-toggle'} = $1;
    }

    if ( $preview =~ /\@media\s*\(\s*max-width:\s*(\d+)px\s*\)\s*\{\s*\.brand__tag\s*\{\s*display:\s*none/s ) {
        $found{'preview.html .brand__tag'} = $1;
    }

    for my $where ( 'main.css .nav-toggle', 'main.css .brand__tag', 'preview.html .nav-toggle', 'preview.html .brand__tag' ) {
        push @problems, "could not find the breakpoint for $where — this check can no longer see one of the four places the number lives"
            unless exists $found{$where};
    }

    return @problems if @problems;

    my %values = map { $_ => 1 } values %found;

    return () if 1 == keys %values;

    push @problems,
        "the nav toggle and the tagline hide have drifted apart — "
        . join( ', ', map { "$_=$found{$_}px" } sort keys %found )
        . "; they are one transition and must carry one value";

    return @problems;
}

# admin-bar: the theme opts out of core's spacing and must provide its own.
#
# functions.php disables core's html-margin bump with
# add_theme_support( 'admin-bar', array( 'callback' => '__return_false' ) ).
# That is only safe because main.css replaces it — a ::before spacer and a top
# offset on the fixed header. Either half alone is broken: the opt-out without
# the CSS puts the admin bar over the header for every signed-in user, and it
# survived a week unmeasured precisely because nobody signed in looked.
#
# admin-bar-offset already asserts the offset derives from core's variable.
# This asserts something different and cross-file: that the two halves exist
# together at all.
sub check_admin_bar_pair {
    my $fn  = php_code_only( slurp($THEMEFN) );
    my $css = slurp($MAINCSS);

    my $opts_out = $fn =~ /add_theme_support\(\s*'admin-bar'.*?__return_false/s ? 1 : 0;
    my $spacer   = $css =~ /body\.admin-bar::before\s*\{/         ? 1 : 0;
    my $offset   = $css =~ /body\.admin-bar\s+\.site-header\s*\{/ ? 1 : 0;

    return () unless $opts_out || $spacer || $offset;

    my @problems;

    if ( $opts_out && !( $spacer && $offset ) ) {
        push @problems,
            "$THEMEFN opts out of core's admin-bar spacing, but $MAINCSS no longer provides the replacement ("
            . ( $spacer ? '' : 'body.admin-bar::before spacer missing' )
            . ( $spacer || $offset ? '' : '; ' )
            . ( $offset ? '' : 'body.admin-bar .site-header offset missing' )
            . ") — the admin bar will cover the header for every signed-in user";
    }

    if ( !$opts_out && ( $spacer || $offset ) ) {
        push @problems,
            "$MAINCSS still carries the admin-bar replacement rules, but $THEMEFN no longer opts out of core's own spacing — the offset is now applied twice";
    }

    return @problems;
}

# The nowrap asymmetry, which reads like an oversight and is not.
#
# .nav a MUST keep white-space: nowrap — without it flex min-content shrinking
# splits "My Progress" across two lines long before the row is out of room.
# .brand__tag must NEVER gain it: the tagline is 48 characters, and holding it
# on one line puts a sideways-scrolling page on most laptops (measured: fits at
# 1380, overflows at 1370). Its comment says "do not complete this fix" for that
# reason.
#
# So the risk is symmetrical and in opposite directions: a tidier adding the
# missing nowrap for consistency, or removing the odd one out. Prose already
# says both; this makes it fail instead.
sub check_nowrap_asymmetry {
    my $css = slurp($MAINCSS);
    my @problems;

    my ($nav) = $css =~ /(^\.nav a \{[^\}]*\})/ms;

    if ( !defined $nav ) {
        push @problems, "$MAINCSS has no .nav a rule — this check cannot see the half it is meant to protect";
    } elsif ( $nav !~ /white-space:\s*nowrap/ ) {
        push @problems, "$MAINCSS .nav a has lost white-space: nowrap — multi-word labels will split across two lines under flex shrinking, long before the row runs out of room";
    }

    my ($tag) = $css =~ /(^\.brand__tag \{[^\}]*\})/ms;

    if ( !defined $tag ) {
        push @problems, "$MAINCSS has no .brand__tag rule — this check cannot see the half it is meant to protect";
    } elsif ( $tag =~ /white-space:\s*nowrap/ ) {
        push @problems, "$MAINCSS .brand__tag has gained white-space: nowrap — measured to overflow the document horizontally below ~1380px. Its own comment says not to complete the fix this way";
    }

    return @problems;
}

# Every data-go target must have a screen to land on.
#
# The mock's router hides every .screen then shows #s-<target>. A target with no
# screen therefore hides everything and leaves a blank white page — no error, no
# console warning, nothing to see. It is the worst kind of broken: silent, and
# indistinguishable from a slow load.
#
# This is not hypothetical. On 11 Aug 2026 a "Your profile" button was committed
# while the #s-profile screen it pointed at was still mid-flight in another
# session's edit, and the synced copy carried the button without the screen.
# Caught by eye that time.
#
# Checked on the shippable copy: the root copy is generated from it by
# sync-preview.pl, so a dangling target here becomes a dangling target there.
sub check_preview_routes {
    my $html = slurp($PREVIEW);

    my %screens = map { $_ => 1 } ( $html =~ /id="s-([a-z0-9-]+)"/g );
    my @targets = ( $html =~ /data-go="([a-z0-9-]+)"/g );

    return ("no screens found in $PREVIEW — this check can no longer see what it guards")
        unless %screens;

    return ("no data-go targets found in $PREVIEW — this check can no longer see what it guards")
        unless @targets;

    my ( %seen, @dangling );

    for my $t (@targets) {
        next if $seen{$t}++;
        push @dangling, $t unless $screens{$t};
    }

    return () unless @dangling;

    return (
        "$PREVIEW has data-go target(s) with no matching screen: "
        . join( ', ', map { qq{"$_" (no #s-$_)} } @dangling )
        . ' — the router hides every screen and shows none, so clicking these blanks the page silently'
    );
}

# The mock's demo notice must be the wording production actually shows.
#
# design/preview.html was retasked on 11 Aug to describe what shipped, after the
# owner deployed it and reported the site as broken. Its sign-in card can inject
# the failed-password state on demand, and that demo is only worth having if the
# words are the ones a student really sees — a mock that paraphrases is back to
# describing a product that does not exist, which is the failure it was retasked
# to stop.
#
# The claim "production wording verbatim" was made in prose when the toggle
# landed and was true then. This is the enforcement, because prose does not stay
# true through a copy edit on either side.
#
# Compared as text, not as markup: the theme carries a printf placeholder
# (<a href="%s">) and the mock a real target (<a href="#" data-go="forgot">), so
# tags are stripped from both and whitespace collapsed before comparing. That
# lets the link markup differ, which it must, while the sentence may not.
sub check_demo_notice_wording {
    my $auth = slurp($AUTH);
    my $mock = slurp($PREVIEW);

    my $strip = sub {
        my ($s) = @_;
        $s =~ s/<[^>]*>//g;
        $s =~ s/\s+/ /g;
        $s =~ s/^\s+|\s+\z//g;
        return $s;
    };

    my ($shipped) = $auth =~ /__\(\s*'(That password[^']*)'/;

    return ('could not find the bad-password notice in ' . $AUTH
            . ' — this check can no longer see the string it guards')
        unless defined $shipped;

    # Anchored on role="status", which is what distinguishes the sign-in demo
    # from the other two notice--error injections in this file (the prepare
    # screen's "attach a lecture" validation and its generic error). Written
    # first as a bare class match, it silently compared the prepare screen's
    # sentence instead and reported drift that did not exist — twice, including
    # once after an "anchor fix" that still matched the wrong element.
    #
    # Ambiguity fails rather than resolving: if more than one injection ever
    # carries role="status", picking the first is how this check goes back to
    # being about the wrong object without saying so.
    my @demos = $mock =~ /innerHTML\s*=\s*'<p class="notice notice--error" role="status">(.*?)<\/p>'/gs;

    return ( 'found ' . scalar(@demos) . " failed-state notices carrying role=\"status\" in $PREVIEW"
            . ' — this check compares exactly one and cannot choose between them' )
        if @demos > 1;

    my ($demo) = @demos;

    return ("could not find the injected failed-state notice in $PREVIEW"
            . ' — either the demo toggle is gone, or this check can no longer see it')
        unless defined $demo;

    my $want = $strip->($shipped);
    my $got  = $strip->($demo);

    return () if $want eq $got;

    return (
        "the mock's failed sign-in demo has drifted from the wording production shows.\n"
        . "          $AUTH: \"$want\"\n"
        . "          $PREVIEW: \"$got\""
    );
}

# The mock's list of screens that imply a session, pinned.
#
# go() flips the demo's session on for these targets, so the header can never
# offer "Sign in" while an account page is on screen. The membership rule is a
# JS object literal, which means a third gated screen is added by remembering
# to — and on 11 Aug "remembered" lost to "rendered" twice in one day.
#
# Pinned to exact equality rather than "contains": a new gated screen must go
# red until someone reads this and moves the pin deliberately. That is the point
# of the check, not a side effect of it.
#
# Plus one semantic anchor for the inverse mistake: a screen carrying a sign-out
# control presumes an account, so it must be gated. That direction cannot be
# caught by a pin, because the pin does not know what the screens contain.
sub check_gated_screens {
    my $html = slurp($PREVIEW);
    my @problems;

    my ($literal) = $html =~ /var\s+GATED\s*=\s*\{([^}]*)\}/;

    return ("no GATED literal found in $PREVIEW — the session gate this check guards is gone, or has been renamed")
        unless defined $literal;

    my %declared = map { $_ => 1 } ( $literal =~ /([a-z][a-z0-9-]*)\s*:/g );
    my %pinned   = ( dashboard => 1, profile => 1 );

    my @added   = sort grep { !$pinned{$_} }   keys %declared;
    my @removed = sort grep { !$declared{$_} } keys %pinned;

    push @problems,
        'GATED has gained ' . join( ', ', @added )
        . " in $PREVIEW. If that screen really implies a session, move the pin in this check"
        . ' — deliberately, having checked the header behaves on every route into it'
        if @added;

    push @problems,
        'GATED has lost ' . join( ', ', @removed )
        . " in $PREVIEW — that screen can now be reached while the header still offers \"Sign in\""
        if @removed;

    # Any screen containing a sign-out control must be gated.
    while ( $html =~ /<div class="screen" id="s-([a-z0-9-]+)"(.*?)(?=<div class="screen"|<!-- =)/gs ) {
        my ( $screen, $body ) = ( $1, $2 );
        next unless $body =~ /id="[a-z-]*signout[a-z-]*"|>\s*Sign out\s*</i;
        next if $declared{$screen};

        push @problems,
            "screen s-$screen carries a sign-out control but is not in GATED"
            . ' — it presumes an account, so reaching it must imply a session';
    }

    return @problems;
}

# The hidden attribute must actually hide.
#
# `hidden` is only a UA-stylesheet `display:none`, and any author rule at equal
# specificity beats it. `.btn{display:inline-flex}` therefore defeated it on the
# header's session controls, and the mock shipped for one commit rendering
# "Sign in", "Your account" and "Sign out" at once — while el.hidden toggled
# exactly as designed, which is why the walk that verified the attribute passed.
#
# The file had already been bitten once and patched narrowly
# (.preview-ribbon[hidden]); bd402aa generalised it. This keeps the general form
# from being tidied away, and keeps !important on it: without that, the
# attribute's promise depends on source order against every display rule.
sub check_hidden_attribute {
    my $html = slurp($PREVIEW);

    # A bare [hidden] selector, not one scoped to a class — element-scoped
    # patches are what this replaced.
    return ()
        if $html =~ /(?:^|[\s,{}])\[hidden\]\s*\{[^}]*display\s*:\s*none\s*!important/m;

    my $narrow = $html =~ /[.#][a-z0-9_-]+\[hidden\]\s*\{/i ? ' (only element-scoped [hidden] patches remain, which is the shape that failed before)' : '';

    return (
        "$PREVIEW has no global [hidden]{display:none!important} rule$narrow"
        . ' — the hidden attribute is only a UA-stylesheet rule and any author display rule outranks it,'
        . ' so hidden elements will render'
    );
}

# Corrupt text encoding, swept across every source file that carries copy.
#
# WHY THIS EXISTS. design/preview.html shipped a double-encoded em dash in the
# summarise and calc ledes — the bytes c3 a2 e2 82 ac e2 80 9d where U+2014 (e2
# 80 94) belongs, which is what happens when UTF-8 is read as Windows-1252 and
# written back out as UTF-8. On a page declaring utf-8 that renders as three
# stray glyphs. It was on the deployed Vercel mock and none of the other 23
# checks looked for it: every one of them asserts that something correct is
# PRESENT, and corruption is a wrong value in a field that is present and
# populated, so it sails through.
#
# It also came within one copy-paste of reaching the live site — that mock copy
# was the source for setup.sh's page ledes, and lifting the two strings verbatim
# would have written the corruption into the database.
#
# THE SIGNATURES. Double-encoding is mechanical, so its output is a small fixed
# set rather than arbitrary noise. Every UTF-8 sequence starting e2 80 (the
# General Punctuation block — em dash, en dash, curly quotes, ellipsis, bullet)
# becomes U+00E2 U+20AC followed by one more character. Those two together are
# the signature, and they are effectively impossible in real prose: "â" is only
# ever followed by a letter in a language that uses it, never by a currency
# sign. That block is where this class of bug actually lives, because those are
# the characters a writer pastes out of a word processor.
#
# The c2-lead and c3-lead families (Latin-1 symbols, and accented letters) are
# caught too, but only when the lead character is followed by punctuation or a
# symbol rather than a letter. U+00C2 and U+00C3 are real letters in French,
# Portuguese, Welsh and Vietnamese, and flagging them unconditionally would make
# this check something people learn to ignore.
#
# WRITTEN AS \x{} ESCAPES, AND NAMED BY CODEPOINT RATHER THAN SPELLED OUT.
# An earlier version of this comment illustrated the c3 family by pasting two
# corrupt examples into it. That is a real occurrence of the signature living in
# the scanner's own source, and it was invisible because the scanner also
# skipped its own file — the one file guaranteed to contain the patterns was
# the one file never examined, so genuine corruption here could never have been
# reported. Both halves of that are now gone: nothing below is spelled out, and
# nothing is skipped. Keep it that way — describe these sequences by codepoint,
# never by example.
sub check_no_mojibake {
    my @problems;

    # ONE RULE, NOT A LIST OF SPECIAL CASES. Double-encoding is mechanical: a
    # UTF-8 lead byte becomes a lead CHARACTER, and the continuation byte
    # becomes whatever Windows-1252 maps it to. So every instance is a lead
    # from a set of three, followed by one character from a fixed set — and
    # naming the two sets covers the whole class rather than the examples
    # someone happened to find.
    #
    # Two earlier versions of this check enumerated instead, and each missed
    # something real. The first knew only the e2 lead, so a double-encoded
    # middle dot in the library card meta — this design's separator — passed
    # clean and was caught by eye (3434ac0). The second added the c2 and c3
    # leads but allowed only U+00A0-U+00BF after them, which silently excluded
    # every CAPITAL accented letter: the continuation byte of À-ß is 0x80-0x9F,
    # and Windows-1252 maps that range to typographic specials, not to Latin-1.
    # A double-encoded É is U+00C3 U+2030, and the check passed it.
    #
    # The lead set. These are the only three UTF-8 lead bytes this project's
    # copy produces — c2 (Latin-1 symbols), c3 (accented letters) and e2
    # (General Punctuation).
    my $lead = "[\x{00E2}\x{00C2}\x{00C3}]";

    # The follower set: Windows-1252's reading of a UTF-8 continuation byte.
    # 0xA0-0xBF passes through to Latin-1; 0x80-0x9F becomes the typographic
    # specials below; the five positions CP1252 leaves undefined decode to
    # their C1 control codepoints and are included because a control character
    # mid-sentence is never legitimate either.
    my $follower = '['
        . "\x{00A0}-\x{00BF}"                                    # 0xA0-0xBF
        . "\x{20AC}\x{201A}\x{0192}\x{201E}\x{2026}\x{2020}"     # 0x80-0x86
        . "\x{2021}\x{02C6}\x{2030}\x{0160}\x{2039}\x{0152}"     # 0x87-0x8C
        . "\x{017D}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}"     # 0x8E-0x95
        . "\x{2013}\x{2014}\x{02DC}\x{2122}\x{0161}\x{203A}"     # 0x96-0x9B
        . "\x{0153}\x{017E}\x{0178}"                             # 0x9C-0x9F
        . "\x{0081}\x{008D}\x{008F}\x{0090}\x{009D}"             # CP1252 gaps
        . ']';

    # Requiring a follower is what keeps this exact rather than heuristic: the
    # three lead characters are real letters in French, Portuguese, Welsh,
    # Romanian and Vietnamese, so flagging them alone would fire on ordinary
    # prose. Followed by a symbol, they are corruption.
    my $mojibake = $lead . $follower;

    my @files;
    File::Find::find(
        {
            no_chdir => 1,
            wanted   => sub {
                my $p = $File::Find::name;

                # dist/ is build output, rebuilt from design/preview.html on
                # every run — flagging it would report the same defect twice
                # and point at the copy nobody edits.
                if ( -d $p && $p =~ m{/(?:\.git|node_modules|dist|vendor)$} ) {
                    $File::Find::prune = 1;
                    return;
                }

                return unless -f $p;
                return unless $p =~ /\.(?:php|html|css|js|mjs|sh|pl|md|json)$/;
                push @files, $p;
            },
        },
        $root
    );

    for my $path ( sort @files ) {
        # abs2rel + tr rather than a hand-rolled prefix strip: the separator is
        # a backslash on this project's Windows boxes and a slash in CI, and
        # every report below is compared against a slash-form path.
        my $rel = File::Spec->abs2rel( $path, $root );
        $rel =~ tr{\\}{/};

        # No file is skipped, this one included — see the note above on why the
        # self-skip that used to be here was a blind spot rather than a
        # convenience. It holds because the signatures are written as \x{}
        # escapes, so this source does not contain the sequences it looks for.
        open( my $fh, '<:encoding(UTF-8)', $path ) or next;
        my $line = 0;
        while ( my $text = <$fh> ) {
            $line++;
            next unless $text =~ /$mojibake/;

            $text =~ s/\s+/ /g;
            $text =~ s/^\s+|\s+$//g;
            $text = substr( $text, 0, 90 ) . '…' if length($text) > 90;

            push @problems,
                "$rel:$line has double-encoded UTF-8 (text read as Windows-1252 and re-encoded): $text";
        }
        close($fh);
    }

    return @problems;
}

# The third edge of a three-way sync. The four tool-page ledes exist as copy in
# TWO places: the mock's page heroes, and setup.sh's set_lede calls that put
# them in the database. page-drift.php guards setup.sh against the live site,
# and needs a running stack to do it. Nothing guarded the mock against setup.sh
# — so editing a lede here would leave setup.sh on the old string, page-drift
# would stay green because IT still agrees with the database, and the mock
# would silently diverge from the site. That is the exact drift that produced
# the three-heading report this all started from.
#
# This edge is the cheap one: pure text, no stack, no PHP, no Docker.
#
# The screen-id-to-slug map is the part that cannot be derived — the Q&A screen
# is `qa` in the mock and `ask` on the site, and no amount of parsing reveals
# that. /progress/ is deliberately absent: its hero is title-only, and the
# orienting line lives in the dashboard card the shortcode renders beneath it.
sub check_lede_copy {
    my %screen_slug = (
        summarise => 'summarise',
        calc      => 'calc',
        qa        => 'ask',
        prepare   => 'prepare',
    );

    my $mock  = slurp($PREVIEW);
    my $setup = slurp('scripts/setup.sh');
    my @problems;

    my %declared;
    while ( $setup =~ /^set_lede\s+"([^"]+)"\s+"([^"]*)"/mg ) {
        $declared{$1} = $2;
    }
    return ('scripts/setup.sh declares no set_lede calls — this check would pass vacuously')
        unless %declared;

    my $normalise = sub { my $s = shift; $s =~ s/\s+/ /g; $s =~ s/^\s+|\s+$//g; return $s; };

    for my $screen ( sort keys %screen_slug ) {
        my $slug = $screen_slug{$screen};

        # Bound the search to the screen's own block BEFORE looking for its
        # hero. Anchoring on the id alone is not anchoring: `.*?` from the id to
        # the next "pagehero" runs straight past the end of the screen, so a
        # screen that loses its hero silently borrows the next one's. Mutation
        # -tested — removing calc's hero reported a copy mismatch quoting the
        # Q&A screen's lede, which is a wrong-object failure wearing the costume
        # of a real finding. The lookahead stops at the next screen or EOF.
        my ($block) = $mock =~ /id="s-\Q$screen\E"(.*?)(?=<div class="screen"|\z)/s;
        unless ( defined $block ) {
            push @problems, "$PREVIEW has no screen 's-$screen' — this check's subject is gone";
            next;
        }

        my ($hero) = $block =~ /<div class="pagehero"><div class="wrap">(.*?)<\/div><\/div>/s;
        unless ( defined $hero ) {
            push @problems, "$PREVIEW has no page hero for screen '$screen' — the lede this check compares is gone";
            next;
        }
        my ($copy) = $hero =~ /<p class="muted[^"]*">(.*?)<\/p>/s;
        unless ( defined $copy ) {
            push @problems, "$PREVIEW screen '$screen' has a hero but no lede paragraph";
            next;
        }

        unless ( exists $declared{$slug} ) {
            push @problems, "setup.sh has no set_lede for '$slug' but the mock shows a lede on screen '$screen' — that page renders bare";
            next;
        }

        my $have = $normalise->($copy);
        my $want = $normalise->( $declared{$slug} );
        next if $have eq $want;
        push @problems, "lede copy differs for '$slug' — mock: \"$have\" / setup.sh: \"$want\"";
    }

    # The inverse: copy seeded onto the site that the design never showed.
    my %known = map { $screen_slug{$_} => 1 } keys %screen_slug;
    for my $slug ( sort keys %declared ) {
        next if $known{$slug};
        push @problems, "setup.sh seeds a lede for '$slug' that the mock does not show — the site would say something the design does not";
    }

    return @problems;
}

# Regex classes inside a template literal, which is where JS source gets built
# before being injected into a page over CDP.
#
# THE BUG THIS REMOVES, three occurrences in one day before it was mechanised:
# `\s` is not a recognised escape in a string or template literal, so it
# collapses to the bare letter. `.replace(/\s+/g, ' ')` written inside a
# template literal becomes `.replace(/s+/g, ' ')` in the page — a regex that
# matches the LETTER s. It does not throw. It returns a plausible string with
# letters missing: a footer read back as "Re et pa word", a heading as
# "Cour e Categorie". Twice I nearly filed those as defects in the page.
#
# `\b` is worse, because it IS a valid escape: it becomes a backspace
# character rather than a word boundary, so the regex silently matches
# something real and wrong.
#
# The fix is String.raw`...`, which passes backslashes through untouched. This
# check exists because knowing the rule demonstrably did not stop me applying
# it — the write-up was the wrong intervention, three times over.
#
# Deliberately scoped INSIDE template literals only: a regex literal in the
# harness's own Node code (`!/^\s*·/.test(x)`) is correct and must not be
# flagged. mock-state-pass.mjs:193 is exactly that case and stays green.
sub check_injected_escapes {
    my @problems;

    my $dir = File::Spec->catdir( $root, 'scripts' );
    opendir( my $dh, $dir ) or return ("cannot read scripts/ — this check would pass vacuously");
    my @files = sort grep { /\.mjs$/ } readdir($dh);
    closedir($dh);

    return ('scripts/ contains no .mjs harnesses — this check would pass vacuously')
        unless @files;

    for my $file (@files) {
        my $src = slurp("scripts/$file");

        # Walk every template literal, remembering whether String.raw tagged it.
        while ( $src =~ /(String\.raw[ \t]*)?`((?:[^`\\]|\\.)*)`/gs ) {
            my $tagged = $1;
            my $body   = $2;
            my $end    = pos($src);

            next if defined $tagged;

            my %bad;
            while ( $body =~ /\\([sSdDwWbB])/g ) {
                $bad{"\\$1"} = 1;
            }
            next unless %bad;

            my $line  = 1 + ( substr( $src, 0, $end ) =~ tr/\n// );
            my @found = sort keys %bad;

            # Say what each one actually does, because they do not all do the
            # same thing and a message that misdescribes its own failure is
            # the defect this suite keeps finding elsewhere.
            my $why = ( grep { '\\b' eq $_ } @found )
                ? '\\b is a VALID escape and becomes a backspace character, not a word boundary'
                : 'they collapse to the bare letter, so \\s reaches the page as s and the regex matches letters instead of whitespace';

            push @problems,
                "scripts/$file:~$line a template literal contains "
                . join( ', ', @found )
                . " — $why. Tag the literal String.raw`...`.";
        }
    }

    return @problems;
}

# A control that names a specific thing must carry that thing's id.
#
# "Ask about THIS document" and "Summarise IT for me" both point at the bare
# tool URL today, so pressing them opens a picker — the student is asked to
# find the document they were already reading. The copy is the promise and the
# href is the delivery, and they disagree.
#
# The discriminator is DEFINITE reference, not the presence of a noun. On the
# dashboard "Ask the assistant" names the tool and "Summarise a lecture" names
# a generic one; both are honest with a bare URL and must not be flagged.
# "this" and "it" point at something the reader can see, and only an id can
# keep that promise.
#
# Pinned rather than failing outright, in the idiom the token migration used:
# the two liars below are known debt from before ?source= existed. A NEW one
# fails immediately; removing one of these means deleting its line here, which
# is a deliberate act rather than a silent drift.
sub check_scoped_links {
    my @problems;

    # file => label, for links that name a specific object but carry no id.
    my %pinned = (
        'templates/single-study_material.php' => {
            'Ask about this document' => 1,
            'Summarise it for me'     => 1,
        },
    );

    my $base = 'wp-content/plugins/scholaris-library';
    my @scan = ( 'templates/single-study_material.php', 'templates/dashboard.php', 'templates/library-grid.php' );

    my $seen = 0;

    for my $rel (@scan) {
        my $path = File::Spec->catfile( $root, $base, $rel );
        next unless -f $path;
        my $src = slurp("$base/$rel");

        # An anchor carrying a bare tool URL, then its translated label.
        while ( $src =~ /eduai_(?:ask|summarise)_url\(\s*\)(.{0,220}?)_e\(\s*'([^']+)'/gs ) {
            my $label = $2;
            $seen++;

            # Definite reference: the reader is looking at the thing.
            next unless $label =~ /\b(?:this|it)\b/i;

            next if $pinned{$rel} && $pinned{$rel}{$label};

            push @problems,
                "$base/$rel: \"$label\" names a specific thing but links to the bare tool URL — "
                . 'pressing it opens a picker and asks the student to find what they were already reading. '
                . 'Pass the id (?source=<id>), or reword the label so it does not promise one.';
        }
    }

    return ("$base: found no eduai_*_url() links at all — this check would pass vacuously")
        unless $seen;

    # The pins must still describe reality, or they are hiding a fixed problem
    # rather than a known one.
    for my $rel ( sort keys %pinned ) {
        my $src = -f File::Spec->catfile( $root, $base, $rel ) ? slurp("$base/$rel") : '';
        for my $label ( sort keys %{ $pinned{$rel} } ) {
            next if index( $src, $label ) >= 0;
            push @problems,
                "$base/$rel no longer contains the pinned label \"$label\" — "
                . 'delete it from %pinned in this check, so the pin lists debt that exists.';
        }
    }

    return @problems;
}

# A bare docs/NN citation must resolve to exactly one file.
#
# docs/ has grown two files per number more than once (08-handoff and
# 08-ui-design-proposal; 09-multi-agent-retrospective and
# 09-ui-implementation-specs), and the moment that happens every existing
# bare citation of that number in the codebase silently stops resolving. Six
# were already broken before anyone noticed — and worse, they still LOOK like
# citations, so a reader follows one, lands in the wrong document, and finds a
# section by that number waiting there too.
#
# This comment deliberately does not spell an example out in the broken form.
# The scan covers every committed source file, including this one, so quoting it
# made the suite fail on its own documentation — which is the check working. A
# citation is a citation wherever it appears.
#
# The fix chosen deliberately is NOT renumbering. Renumbering breaks the
# citations that name a file in full — the ones that work — to repair the ones
# that do not, and leaves the next collision free to form.
#
# It is also NOT "ban bare numbers". Fifty citations point at docs/06 and
# docs/07, which are unique files and resolve correctly today; rewriting all of
# them buys nothing and would make this check a chore rather than a signal.
#
# So: a bare number is fine while it is unambiguous, and becomes an error the
# instant a second file claims that number. Adding docs/06-anything.md turns
# every docs/06 citation red at once, which is the correct moment to be told.
sub check_doc_citations {
    my $dir = File::Spec->catdir( $root, 'docs' );

    opendir( my $dh, $dir ) or die "cannot read docs/: $!";
    my @docs = grep { /^\d{2}-.*\.md$/ } readdir $dh;
    closedir $dh;

    return ('docs/ contains no NN-named files — this check can no longer see what it guards')
        unless @docs;

    my %by_number;
    for my $doc (@docs) {
        my ($n) = $doc =~ /^(\d{2})-/;
        push @{ $by_number{$n} }, $doc;
    }

    my @ambiguous = sort grep { @{ $by_number{$_} } > 1 } keys %by_number;

    return () unless @ambiguous;

    # Only now is it worth scanning the tree: a number with one file cannot be
    # cited wrongly, however many times it appears.
    my %found;      # number => [ "file:citation", ... ]
    my $checked = 0;

    for my $rel ( source_files() ) {
        my $text = eval { slurp($rel) };
        next unless defined $text;
        $checked++;

        for my $n (@ambiguous) {
            while ( $text =~ /docs\/$n\s+§([0-9.]+)/g ) {
                push @{ $found{$n} }, "$rel — \"docs/$n §$1\"";
            }
        }
    }

    return ("scanned no source files — this check examined nothing") unless $checked;

    # One finding per ambiguous NUMBER, not per citation, and both remedies on
    # it. The count decides which remedy is right: six broken citations are
    # worth editing, but creating a second docs/06 file makes 39 existing ones
    # ambiguous at once, and there the fix is to rename the new file rather
    # than edit thirty-nine working references. Repeating "name the file in
    # full" thirty-nine times points at the symptom and hides the cause — the
    # reader would reasonably start doing the expensive thing.
    my @problems;

    for my $n ( sort keys %found ) {
        my @cites = @{ $found{$n} };
        my @files = sort @{ $by_number{$n} };

        push @problems,
            "docs/ has " . scalar(@files) . " files numbered $n (" . join( ', ', @files ) . "), "
            . 'so ' . scalar(@cites)
            . ( 1 == @cites ? " bare citation of docs/$n no longer resolves" : " bare citations of docs/$n no longer resolve" )
            . ( @cites > 5
                ? ". Rename one of those files, OR name the file in full at each citation below"
                : ". Name the file in full at each, or rename one of those files" )
            . ":\n          " . join( "\n          ", @cites );
    }

    return @problems;
}

# Every committed file a citation could live in. Deliberately not a hardcoded
# list: a check that only looks where someone remembered to point it is the
# shape this suite exists to replace.
sub source_files {
    my $out = `git -C "$root" ls-files 2>/dev/null`;
    return grep { /\.(php|css|js|mjs|pl|sh|md)$/ } split /\n/, $out;
}
