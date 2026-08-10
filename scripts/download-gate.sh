#!/usr/bin/env bash
#
# Gated downloads, end to end over HTTP — the README's claim that documents are
# "served through a nonce-protected handler so file URLs cannot be shared
# outside the site".
#
#     bash scripts/download-gate.sh
#
# Needs the local stack up (docker compose up -d). Fixtures come from
# scripts/download-gate.php; this drives real requests with real login cookies,
# because the claim under test is about what happens when one student pastes a
# URL to another, and that is a cookie-level property no in-process test can
# reach.
#
# Every assertion below is of the form "this request is refused". That shape
# passes just as well when the URL is malformed, the host is down, or the query
# string never reached WordPress — so the run starts with a control that must
# SUCCEED. If a legitimate download cannot be served, nothing after it means
# anything and the script stops.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

pass=0
fail=0
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

say()  { printf '%s\n' "$*"; }
ok()   { pass=$((pass+1)); printf 'ok    %s\n' "$1"; }
bad()  { fail=$((fail+1)); printf 'FAIL  %s\n        %s\n' "$1" "$2"; }

# --- fixtures --------------------------------------------------------------

say "setting up fixtures..."
fixtures="$(MSYS_NO_PATHCONV=1 docker compose --profile tools run --rm cli \
  wp eval-file /scripts/download-gate.php --allow-root 2>/dev/null | grep -E '^[A-Z_]+=')"

if [ -z "$fixtures" ]; then
  say "could not create fixtures — is the stack up? (docker compose up -d)"
  exit 1
fi

eval "$fixtures"
say "site $SITE, members doc #$POST_MEMBERS, public doc #$POST_PUBLIC"
say ""

# --- sign in, keeping each student's cookies apart -------------------------

# Posts to whatever wp_login_url() resolves to, never a literal wp-login.php:
# wps-hide-login is active on this stack and 404s the native path.
login() {
  local user="$1" pass="$2" jar="$3"
  curl -s -c "$jar" -o /dev/null "$LOGIN_URL"          # pick up the test cookie
  curl -s -c "$jar" -b "$jar" \
    --data-urlencode "log=$user" \
    --data-urlencode "pwd=$pass" \
    --data "wp-submit=Log+In&testcookie=1" \
    -o /dev/null "$LOGIN_URL"
  grep -q "wordpress_logged_in" "$jar"
}

login "$USER_A" "$PASS_A" "$tmp/a.jar" || { say "could not sign in as $USER_A"; exit 1; }
login "$USER_B" "$PASS_B" "$tmp/b.jar" || { say "could not sign in as $USER_B"; exit 1; }

# Fetch a download URL and report "<http status>|<body contains payload>".
fetch() {
  local url="$1" jar="${2:-}"
  local out="$tmp/out.$$"
  local code
  if [ -n "$jar" ]; then
    code="$(curl -s -o "$out" -w '%{http_code}' -b "$jar" "$url")"
  else
    code="$(curl -s -o "$out" -w '%{http_code}' "$url")"
  fi
  if grep -q 'GATE-TEST-PAYLOAD' "$out" 2>/dev/null; then
    printf '%s|served' "$code"
  else
    printf '%s|withheld' "$code"
  fi
}

# The download link as a student actually obtains it: scraped off the material
# page while signed in as them. It cannot be minted ahead of time — a nonce
# mixes in the session token, so one made outside the browser's session belongs
# to nobody and 403s for a reason that has nothing to do with gating.
link_for() {
  local page="$1" jar="${2:-}"
  local html
  if [ -n "$jar" ]; then
    html="$(curl -s -b "$jar" "$page")"
  else
    html="$(curl -s "$page")"
  fi
  # esc_url() writes the separator as the numeric entity &#038;, not &amp; —
  # decode both or the link is found and then requested without its nonce,
  # which looks exactly like the gate refusing a legitimate download.
  printf '%s' "$html" \
    | grep -oE 'https?://[^"'"'"' ]*\?sl_download=[0-9]+(&|&amp;|&#038;)_wpnonce=[a-z0-9]+' \
    | head -1 | sed -e 's/&#038;/\&/g' -e 's/&amp;/\&/g'
}

# Swap the post id in an existing link, keeping its nonce — for proving the
# nonce is bound to one document.
retarget() { printf '%s' "$1" | sed -E "s/sl_download=[0-9]+/sl_download=$2/"; }

# --- collect the real links ------------------------------------------------

LINK_A="$(link_for "$PERMALINK_MEMBERS" "$tmp/a.jar")"
LINK_B="$(link_for "$PERMALINK_MEMBERS" "$tmp/b.jar")"
LINK_ANON_PUBLIC="$(link_for "$PERMALINK_PUBLIC")"

if [ -z "$LINK_A" ] || [ -z "$LINK_B" ]; then
  say "could not find a download link on $PERMALINK_MEMBERS — the page may not render one"
  exit 1
fi

say "student A's link: $LINK_A"
say ""

# --- control: a legitimate download must work ------------------------------

result="$(fetch "$LINK_A" "$tmp/a.jar")"
if [ "${result#*|}" = "served" ]; then
  ok "control: the owning student's own link serves the file ($result)"
else
  bad "control: the owning student's own link serves the file" \
      "got $result — every refusal below would be meaningless, stopping here"
  printf '\n%d passed, %d failed\n' "$pass" "$fail"
  exit 1
fi

# --- the claim -------------------------------------------------------------

result="$(fetch "$LINK_A" "$tmp/b.jar")"
[ "${result#*|}" = "withheld" ] \
  && ok "a link copied from student A is refused for student B ($result)" \
  || bad "a link copied from student A is refused for student B" \
         "got $result — the README's sharing claim does not hold"

result="$(fetch "$LINK_A")"
[ "${result#*|}" = "withheld" ] \
  && ok "the same link signed out is refused ($result)" \
  || bad "the same link signed out is refused" "got $result"

result="$(fetch "$LINK_B" "$tmp/b.jar")"
[ "${result#*|}" = "served" ] \
  && ok "student B's own link for the same document works ($result)" \
  || bad "student B's own link for the same document works" \
         "got $result — B is being refused their own valid link, so the test above proves nothing"

result="$(fetch "$(retarget "$LINK_A" "$POST_PUBLIC")" "$tmp/a.jar")"
[ "${result#*|}" = "withheld" ] \
  && ok "a nonce minted for one document is refused on another ($result)" \
  || bad "a nonce minted for one document is refused on another" \
         "got $result — the nonce is not bound per post"

result="$(fetch "$SITE/?sl_download=$POST_MEMBERS&_wpnonce=deadbeef" "$tmp/a.jar")"
[ "${result#*|}" = "withheld" ] \
  && ok "a forged nonce is refused ($result)" \
  || bad "a forged nonce is refused" "got $result"

result="$(fetch "$SITE/?sl_download=$POST_MEMBERS" "$tmp/a.jar")"
[ "${result#*|}" = "withheld" ] \
  && ok "no nonce at all is refused ($result)" \
  || bad "no nonce at all is refused" "got $result"

# --- and the other side of the gate ----------------------------------------
# A public document must still be reachable signed out, or "everything is
# refused" would score full marks on a handler that simply denies everyone.

result="$(fetch "$LINK_ANON_PUBLIC")"
[ "${result#*|}" = "served" ] \
  && ok "a public document is served signed out ($result)" \
  || bad "a public document is served signed out" \
         "got $result — the handler may be refusing everything, which would fake every pass above"

printf '\n%d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1
