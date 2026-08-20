#!/usr/bin/env bash
#
# Every check that needs a running WordPress, in one command.
#
#     bash scripts/live-checks.sh              # the free harnesses
#     bash scripts/live-checks.sh --spend      # …and the one that costs money
#     bash scripts/live-checks.sh --help
#
# WHY THIS EXISTS. Seven harnesses cover the things CI cannot reach — the
# answer key never leaving the server, a download URL failing for the student
# it was not minted for, a model's plausible lie not minting marks. None can be
# a CI check: they need a live site and a database, which the lint job has
# neither of. So they protect nobody unless a human runs them, and "run the
# live checks" was seven separate invocations to remember. A person under
# deadline remembers zero of seven as easily as one.
#
# This is the single source of truth for what the seven are and how each is
# invoked. `.github/workflows/nightly.yml` composes a stack and calls this
# script rather than re-listing them: two lists drift, and the drift stays
# invisible until a nightly runs six of seven and reports green.
#
# TWO GUARDS, ENFORCED RATHER THAN DOCUMENTED:
#
#   Spend.    Only roundtrip.php makes real model calls; it is behind --spend.
#             The other harnesses stub the provider at pre_http_request AND use
#             a placeholder key, so they neither spend nor need a real one.
#   Mutation. These create fixtures, a test administrator, exam rows and
#             attempts. Fine locally, wrong anywhere real — so a non-localhost
#             target is REFUSED, not warned about. The operator who most needs
#             that guard is the one not reading this header.
#
# AND TWO THINGS IT PROVES ABOUT ITSELF before believing any verdict: that the
# stack is actually up, and that it is serving the *current* code rather than a
# stale container. Four separate instrument failures on this project were tools
# reporting confidently about a state they had never established.
#
# A GREEN FROM THIS SUITE MEANS "GREEN ON A STACK OF THIS AGE", AND THAT IS NOT
# A CAVEAT BELONGING TO ONE HARNESS. Three instances in one day, pointing both
# ways:
#
#   FRESH stack reds are MISSING SETUP. `download-gate` passed on the
#   long-lived stack for as long as anyone had run it while a fresh install
#   served gated study material to anyone holding the URL —
#   uploads/scholaris-private/ existed here from months of use and was never
#   created on a new one. Green on a used stack was evidence about that stack's
#   history, not about what a deployment would do.
#
#   LONG-LIVED stack reds are ACCUMULATED STATE. `submit-contract` and
#   `projection-leak` both go through routes behind check_rate_limit(), so on a
#   stack that has had these harnesses run against it all day the fixture user
#   is out of quota and the route answers 429 before it can reach the thing
#   being tested. Both pass on a fresh stack for a reason that has nothing to
#   do with what they assert. The remedy is per-harness: clear the fixture
#   user's transient before asserting, as rate-limit.php has always done.
#
# So the nightly and a developer re-run are not the same test, and neither is
# the strict one. The nightly boots fresh, so it can NEVER show the second kind
# — which inverts the usual assumption that CI is strict and local is lenient.
# When recording a green, record which stack produced it.
#
# ON INTERMITTENT FAILURES SEEN LOCALLY. `download-gate` and `ui-geometry` have
# each gone red once on a wrapper run, passed when run directly, and passed on
# every run since — neither reproducible. Both are harnesses that create a
# fixture user and then sign in as it, and several sessions share this machine's
# stack, so contention over users, sessions or the active theme is the leading
# hypothesis. It is a hypothesis, not a diagnosis.
#
# The nightly is the one place it cannot happen: a fresh stack, one job, nobody
# else touching it. So a flake here is not evidence of a flake in CI, and a red
# on a shared developer machine is worth re-running once before it is chased.
# That is the only case on this project where "run it again" is legitimate, and
# it is legitimate precisely because the environment, not the product, is what
# differs.

set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

URL="http://localhost:8080"
SPEND=0

while [ $# -gt 0 ]; do
  case "$1" in
    --spend) SPEND=1; shift ;;
    --url)   URL="${2:-}"; shift 2 ;;
    --help|-h)
      sed -n '2,40p' "$0" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) printf 'unknown option: %s (try --help)\n' "$1"; exit 2 ;;
  esac
done

pass=0; fail=0; skipped=0
declare -a SKIPPED=()

# --- the manifest: every harness this wrapper is answerable for --------------
#
# A wired guard is not a running guard. A registration that silently fails to
# apply prints the same "all N passed" as one that ran, and this project has
# produced that shape repeatedly — a contract-check registration that no-oped
# five times over, a nightly that would happily run six of seven. Counting the
# greens cannot catch it, because the missing one contributes no line to count.
#
# So the wrapper declares what it owes and reconciles at the end. Every harness
# below MUST report exactly one of ok / NOT OK / skip. One that reports nothing
# is a fatal error in its own right, named, regardless of how the others went.
#
# Adding a harness means adding it here as well as calling it. That is the
# point: forgetting the call is then a loud failure rather than a silent
# absence, which is the only direction of forgetting this wrapper can catch.
declare -a MANIFEST=(
  grade-adversarial
  projection-leak
  submit-contract
  rate-limit
  page-drift
  render-safety
  model-catalogue
  download-gate
  roundtrip
  ui-geometry
  visual-checks
)
declare -a ACCOUNTED=()

# The harness id is the first token of the label every reporter is called with.
account() { ACCOUNTED+=("${1%% *}"); }

say()  { printf '%s\n' "$*"; }
ok()   { account "$1"; pass=$((pass+1));    printf 'ok      %s\n' "$1"; }
bad()  { account "$1"; fail=$((fail+1));    printf 'NOT OK  %s\n' "$1"; [ -n "${2:-}" ] && printf '          %s\n' "$2"; }
skip() { account "$1"; skipped=$((skipped+1)); SKIPPED+=("$1"); printf 'skip    %s\n          %s\n' "$1" "$2"; }

# --- guard: mutation ---------------------------------------------------------
# Fail closed. A warning here would be read past by exactly the person about to
# create a test administrator on a production site.

case "$URL" in
  http://localhost:*|http://127.0.0.1:*|https://localhost:*|https://127.0.0.1:*) ;;
  *)
    say "REFUSING to run against $URL"
    say ""
    say "These harnesses create users, exams, attempts and study materials, and the"
    say "visual pass switches themes. That is fine on a local stack and wrong"
    say "anywhere a real person might be looking. There is deliberately no flag to"
    say "override this."
    exit 2 ;;
esac

# --- guard: is the stack actually up, serving current code? ------------------

say "target: $URL"

# Never `curl … || echo 000`. A shell fallback *appends* to whatever the
# command already printed rather than replacing it — and curl still prints the
# status from -w when it exits non-zero. With MSYS_NO_PATHCONV=1 set (which
# this project's notes require for docker, and which this file sets one screen
# below) `/dev/null` goes untranslated, Windows curl fails the body write with
# CURLE_WRITE_ERROR/23, and the capture becomes "200000". That never equals
# 200, so a healthy stack is reported dead and nothing runs — the guard firing
# correctly for a reason that is wrong. Validate the shape; do not trust the
# exit code to mean the output is unusable.
probe_status() {
  local out
  out="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$1/" 2>/dev/null)"
  case "$out" in
    [0-9][0-9][0-9]) printf '%s' "$out" ;;
    *)               printf '000'       ;;
  esac
}

code="$(probe_status "$URL")"

if [ "$code" != "200" ]; then
  say "stack is not answering (HTTP $code) — starting it"
  MSYS_NO_PATHCONV=1 docker compose up -d >/dev/null 2>&1
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    code="$(probe_status "$URL")"
    [ "$code" = "200" ] && break
    sleep 2 2>/dev/null || true
  done
fi

if [ "$code" != "200" ]; then
  say "NOT OK  the stack never came up (HTTP $code) — nothing below would mean anything"
  exit 1
fi

# "Up" is not "serving the code in this working tree". A container holding a
# stale theme answers 200 and passes every check about a version of the site
# nobody is editing. Derive the expected version rather than pinning a literal:
# a hard-coded one rots the first time anyone bumps it, which happened here
# between a version being suggested and the assertion being written.
version="$(sed -n "s/.*SCHOLARIS_VERSION', *'\([^']*\)'.*/\1/p" wp-content/themes/scholaris/functions.php | head -1)"
home="$(curl -s --max-time 15 "$URL/")"

if [ -z "$version" ]; then
  skip "instrument: serving-current-code" "could not read SCHOLARIS_VERSION from functions.php"
elif printf '%s' "$home" | grep -q "main.css?ver=$version"; then
  ok "instrument: the stack is serving the current theme (ver=$version)"
else
  # Stop, do not merely report. A stale container answers 200 and every harness
  # below would then pass — about code nobody is editing. Seven greens under one
  # red is read as green, so this has to be fatal in the same way "the stack
  # never came up" is: there is no useful verdict to be had from here.
  say "NOT OK  instrument: the stack is serving stale code"
  say "          functions.php declares $version but the page does not load main.css?ver=$version"
  say "          Nothing below would be about the code in this working tree, so the run stops."
  say "          docker compose restart wordpress   (or docker compose up -d --force-recreate)"
  exit 1
fi

say ""

# --- the harnesses -----------------------------------------------------------
# One list. The nightly workflow calls this script rather than repeating it.

run_wp() {                      # name, file, description
  local name="$1" file="$2"

  # A harness file that is not there must be a named red, not a container error
  # the caller has to decode. /scripts is the mount of this directory, so the
  # local path is the one to test.
  if [ ! -f "scripts/$file" ]; then
    bad "$name" "scripts/$file does not exist — the wrapper lists a harness that is not in the repository"
    return
  fi

  if MSYS_NO_PATHCONV=1 docker compose --profile tools run --rm cli \
       wp eval-file "/scripts/$file" --allow-root >/tmp/lc.$$ 2>&1; then
    ok "$name"
  else
    bad "$name" "$(grep -E '^(FAIL|no assertions|.*Error)' /tmp/lc.$$ | head -2 | tr '\n' ' ')"
  fi
  rm -f /tmp/lc.$$
}

run_wp "grade-adversarial  docs/06 §5.2, a model's lie cannot mint marks" grade-adversarial.php
run_wp "projection-leak    docs/07 §1, the answer key never reaches the browser" projection-leak.php
run_wp "submit-contract    docs/07 §3, a full-credit short is not shown as wrong" submit-contract.php
run_wp "rate-limit         the assistant's quota is per user, not shared" rate-limit.php

# setup.sh describes a fresh install, not this one: make_page() is create-only,
# so a page added to the script after a site was bootstrapped never appears on
# it. /ask/ and /prepare/ both 404'd for days while present in the script.
# Missing slug fails; content mismatch only warns, because make_page skips
# existing pages and editing copy in wp-admin is expected rather than wrong.
run_wp "page-drift         the running site has the pages setup.sh declares" page-drift.php

# HERE, NOT IN THE LINT JOB, and the distinction is worth stating because the
# obvious reading puts it there. It guards shipped code — EduAI_REST::to_html()
# is in a plugin that rsyncs — and chat.js assigns its output straight into
# innerHTML, so it is the most-rendered surface in the product and the front end
# has nothing left to escape. Both true, and neither decides where it can run.
#
# It needs a live WordPress: it calls wp_kses() and esc_html(), and to_html()
# does too. Run standalone it refuses outright — "Run me through wp-cli, not
# php.", exit 1 — which is why it is not beside redaction-guard.php despite the
# resemblance. That one is standalone by construction: it defines ABSPATH and
# requires the class file directly. This one does neither.
#
# "What it protects" and "what it needs to run" are independent questions, and
# only the second one picks the home.
run_wp "render-safety      model output cannot reach the page as live markup" render-safety.php

# --- model-catalogue: the only check here that asks somebody else ------------
#
# Every other harness in this file asks whether we agree with ourselves — the
# evidence and the thing under test come from this repository, so all of them
# are structurally blind to a provider changing under us. Groq retired the
# entire Llama line and two of three chat tiers stopped existing with nothing
# in this repo changing; it arrived as a 404 in front of the owner.
#
# Costs nothing: GET /models is a catalogue listing, not inference, so this is
# outside the --spend rule rather than an exception to it.
#
# ITS SUMMARY LINE IS REPRODUCED VERBATIM, and the skip count with it. The
# script says "all 4 CHECKED pinned model(s) still exist" — deliberately not
# "all" — because six ids go unchecked when a provider has no key configured.
# Collapsing that to a green tick would claim a coverage this does not have,
# which is the failure this whole file exists to prevent. If you shorten
# anything here, do not shorten that.
#
# WHY IT IS HERE AND NOT IN THE PER-PUSH LINT JOB, because the obvious answer
# is wrong and the obvious fix is worse than the problem.
#
# "It needs WordPress" is true of this script but is not the blocker:
# contract-tests.pl already pulls the same ids straight out of
# class-eduai-claude.php with a regex, no WordPress, and runs on every push.
# A stackless version is cheap.
#
# THE BLOCKER IS THE KEY. .github/workflows/ carries SSH_HOST, SSH_USER,
# REMOTE_PATH and SSH_PRIVATE_KEY — no provider key at all. Without one, a
# lint-job version would report SKIPPED for every id and go green having
# checked nothing: a check that runs on every push and verifies zero models,
# which is strictly worse than a nightly that verifies four. Adding
# GROQ_API_KEY as a repository secret is the owner's call and puts the key in
# a fourth place, so it has not been proposed.
#
# So the window — a model can die at 09:00 and this notices at 01:17 UTC — is
# ACCEPTED, deliberately, not overlooked. Most of it is closed elsewhere:
# EduAI_Claude records a 401/403/404 from the provider and the settings screen
# says so within seconds, cleared by the next success. This stays the backstop
# for a retirement nobody happens to trip over.
mc_out="/tmp/lc.mc.$$"
if MSYS_NO_PATHCONV=1 docker compose --profile tools run --rm cli \
     wp eval-file /scripts/model-catalogue.php --allow-root >"$mc_out" 2>&1; then
  ok "model-catalogue    $(grep -E 'pinned model\(s\) still exist' "$mc_out" | tail -1)"
  mc_skipped="$(grep -oE '^[0-9]+ pinned model\(s\) NOT checked' "$mc_out" | grep -oE '^[0-9]+' | head -1)"
  if [ -n "${mc_skipped:-}" ] && [ "$mc_skipped" -gt 0 ]; then
    printf '          %s pinned model(s) NOT checked — that provider has no key here\n' "$mc_skipped"
  fi
else
  # A red here is not a lint failure. It means a tab is returning 404 to
  # students right now, so it is worth reading differently from a style nit.
  bad "model-catalogue    a pinned model no longer exists at the provider" \
      "$(grep -A4 'NO LONGER EXIST' "$mc_out" | tail -n +2 | grep -E '^\s+\S' | tr '\n' ' ')USER-FACING: the tab asking for that tier is 404ing now."
fi
rm -f "$mc_out"

# TWO THINGS ABOUT THIS ONE THAT ARE NOT VISIBLE FROM THE CALL.
#
# 1. IT TAKES NO --url AND STILL FOLLOWS $URL. That looks like a bug and is
#    not. download-gate.sh builds its fixtures through `docker compose … cli`,
#    so it inherits COMPOSE_PROJECT_NAME / COMPOSE_FILE, and the URLs it then
#    fetches come from the site's own siteurl. Point this wrapper at a second
#    stack and the harness follows. Verified rather than assumed, because an
#    invocation that could silently test the wrong site is exactly the shape
#    that has cost this project days: during a run against a fresh stack on
#    :8090, every URL in its output was :8090 and none :8080. If you ever doubt
#    it again, that is the check — grep its output for the port.
#
# 2. ITS RESULT IS STACK-AGE DEPENDENT, so a green from it means nothing on its
#    own. It passed on the long-lived developer stack for as long as anyone had
#    run it, while a FRESH install served gated study material to anyone with
#    the URL. The difference was history: `uploads/scholaris-private/` existed
#    on the old stack from months of use and was never created on a new one,
#    because placement hung off `save_post_study_material` and programmatic
#    creation writes the post first and the meta after — so at save_post there
#    was no file to place, and no later save came. Fixed in a714af5 by hooking
#    the meta values placement depends on.
#
#    The lesson outlives that bug: when this harness is green, record WHICH
#    STACK it was green on. Green on a long-lived stack is evidence about that
#    stack's history, not about what a deployment would do. The nightly's fresh
#    stack is the one whose verdict transfers to a real install.
if bash scripts/download-gate.sh >/tmp/lc.dg.$$ 2>&1; then
  ok "download-gate      a link copied from one student fails for another"
else
  # Two different failures wear the same exit code: assertions that failed
  # (which print FAIL lines) and setup that never got far enough to assert
  # (which prints "could not sign in as…" and stops). Grepping only for FAIL
  # produced a bare red with no reason at all — seen once, and a red without a
  # reason is the one thing worse than a red, because it teaches people to
  # re-run rather than look. Fall back to the tail of the output.
  reason="$(grep -E '^FAIL' /tmp/lc.dg.$$ | head -2 | tr '\n' ' ')"
  [ -z "$reason" ] && reason="no assertion failed — it stopped before asserting: $(tail -2 /tmp/lc.dg.$$ | tr '\n' ' ')"
  bad "download-gate      a link copied from one student fails for another" "$reason"
fi
rm -f /tmp/lc.dg.$$

if [ "$SPEND" -eq 1 ]; then
  if MSYS_NO_PATHCONV=1 docker compose --profile tools run --rm cli \
       wp eval-file /scripts/roundtrip.php --allow-root >/tmp/lc.rt.$$ 2>&1; then
    ok "roundtrip          a real deck through a real model, end to end"
    grep -E '^5\. cost' /tmp/lc.rt.$$ | sed 's/^/          /'
  else
    bad "roundtrip          a real deck through a real model, end to end" \
        "$(grep -E 'FAILED|Rate limit' /tmp/lc.rt.$$ | head -1)"
  fi
  rm -f /tmp/lc.rt.$$
else
  skip "roundtrip" "spends credit on real model calls — pass --spend to include it"
fi

# ui-geometry.mjs resolves a browser itself, and throws if it finds none. That
# throw would surface here as a FAILING layout check, which is the worst
# available shape: a red pointing at the wrong thing. "Cannot run" and "ran and
# found a defect" are different states, so resolve the browser first and skip
# loudly when there is none. UI_BROWSER is the documented override, so setting
# it is also how a caller pins a specific binary.
find_browser() {
  [ -n "${UI_BROWSER:-}" ] && { printf '%s' "$UI_BROWSER"; return 0; }
  for c in google-chrome google-chrome-stable chromium-browser chromium msedge microsoft-edge; do
    p="$(command -v "$c" 2>/dev/null)" && [ -n "$p" ] && { printf '%s' "$p"; return 0; }
  done
  # Written MSYS-style because that is the only form `[ -x ]` can test, and
  # converted before it leaves, because Node cannot spawn it:
  #
  #   node spawning "/c/…/msedge.exe" directly            → ENOENT
  #   UI_BROWSER="/c/…" node …        → Node receives     → C:/…
  #   MSYS_NO_PATHCONV=1 UI_BROWSER="/c/…" node …         → /c/…  → crash
  #
  # Unconverted it works only because Git Bash silently rewrites the value on
  # its way into a Windows binary. Depending on that is unwise here of all
  # places: this same file sets MSYS_NO_PATHCONV=1 on every docker call one
  # screen down, so the project already suppresses that rescue deliberately in
  # some contexts and would be relying on it in others. Convert at the source
  # and the value is correct however it is passed on.
  for p in "/c/Program Files (x86)/Microsoft/Edge/Application/msedge.exe" \
           "/c/Program Files/Microsoft/Edge/Application/msedge.exe"; do
    if [ -x "$p" ]; then
      if command -v cygpath >/dev/null 2>&1; then
        cygpath -m "$p"
      else
        printf '%s' "$p" | sed 's|^/\([a-zA-Z]\)/|\1:/|'
      fi
      return 0
    fi
  done
  return 1
}

if ! command -v node >/dev/null 2>&1; then
  skip "ui-geometry" "needs node, which is not on this machine — it runs in CI"
elif ! browser="$(find_browser)"; then
  skip "ui-geometry" "no Chromium-family browser found — set UI_BROWSER=/path/to/chrome (the harness needs one; this is not a layout failure)"
else
  if UI_BROWSER="$browser" node scripts/ui-geometry.mjs --url "$URL" >/tmp/lc.ui.$$ 2>&1; then
    ok "ui-geometry        signed-in layout, headless, asserts its own scroll"
  else
    bad "ui-geometry        signed-in layout, headless, asserts its own scroll" \
        "$(grep -iE 'fail|Error' /tmp/lc.ui.$$ | head -2 | tr '\n' ' ')"
  fi
  rm -f /tmp/lc.ui.$$
fi

# Human-run by design, not by omission: it needs a browser, and its tool checks
# want running twice, under the real theme and a stock one. Announced so that
# "everything ran" is never read off a summary that quietly excluded it.
skip "visual-checks" "human-run by design — paste scripts/visual-checks.js into a browser signed in as an admin, on each tool page, under both themes"

# --- summary -----------------------------------------------------------------
# What did not run is printed as loudly as what failed. "Ran six of seven" must
# never be able to read as green.

say ""

# --- reconcile against the manifest -----------------------------------------
#
# Before any verdict. A harness that reported nothing at all is invisible in
# every count above — it adds no ok, no NOT OK, no skip — so "5 passed, 0
# failed" reads exactly the same whether six ran or five did. This is the only
# check that can see that, and it has to be able to turn a clean run red.
missing=()
for want in "${MANIFEST[@]}"; do
  found=0
  for got in ${ACCOUNTED[@]+"${ACCOUNTED[@]}"}; do
    [ "$got" = "$want" ] && { found=1; break; }
  done
  [ "$found" -eq 0 ] && missing+=("$want")
done

if [ "${#missing[@]}" -gt 0 ]; then
  say "MANIFEST MISMATCH — ${#missing[@]} harness(es) reported nothing at all:"
  for m in "${missing[@]}"; do say "  - $m"; done
  say ""
  say "Each of these is listed in MANIFEST but produced no ok, NOT OK or skip"
  say "line, so it did not run and nothing above is a complete verdict. Either"
  say "its call is missing from this script, or it exited without reporting."
  say ""
  say "$fail failed, $pass passed, $skipped not run — and the run is INCOMPLETE"
  exit 1
fi

if [ "$skipped" -gt 0 ]; then
  say "did not run (${skipped}):"
  for s in "${SKIPPED[@]}"; do say "  - $s"; done
  say ""
fi

if [ "$fail" -gt 0 ]; then
  say "$fail failed, $pass passed, $skipped not run"
  exit 1
fi

say "$pass passed, 0 failed, $skipped not run"
say "all ${#MANIFEST[@]} manifest harnesses accounted for"
[ "$skipped" -gt 0 ] && say "(a clean run is not a complete one — see the list above)"
exit 0
