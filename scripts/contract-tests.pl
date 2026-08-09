#!/usr/bin/env perl
use strict;
use warnings;
use File::Basename qw(dirname);
use File::Spec;

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
my $HANDOFF = "docs/05-frontend-handoff.md";
my $HOSTING = "docs/03-hosting-deployment.md";
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
);

if ( grep { '--list' eq $_ } @ARGV ) {
    print "$_->[0]\n" for @checks;
    exit 0;
}

my $failed  = 0;
my $skipped = 0;
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

        @problems = ("check itself blew up: $err");
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
