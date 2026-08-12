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

# Every consumer of $tmp below is Windows curl (cookie jars, response bodies),
# and mktemp hands back an MSYS path. Normally Git Bash rewrites it on the way
# across; with MSYS_NO_PATHCONV=1 set — which this project's notes require for
# docker, so people do export it — it does not, curl cannot create the jar, and
# the run dies at "could not sign in" with the real cause two layers down.
# Convert once here rather than depending on a rescue we suppress elsewhere.
# MSYS tools accept the drive-letter form too, so one path serves both.
command -v cygpath >/dev/null 2>&1 && tmp="$(cygpath -m "$tmp")"
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

# --- the wall, not the door -------------------------------------------------
#
# Everything above probes ?sl_download=, which is the handler. None of it asks
# the question an attacker asks first: can I just fetch the file?
#
# On 11 Aug 2026 the answer was yes. Members-only material in wp-content/uploads
# returned 200 with its payload to an anonymous request, while the same file
# through the handler returned 403 — the gate protected the route and never the
# bytes. This harness scored 8/8 throughout, because it had no way to name the
# file: seven probes of one door, none at the wall beside it.
#
# It is not even obscurity: /wp-json/wp/v2/media answers anonymously and
# publishes source_url for every attachment, so the paths are indexed.
#
# These are the assertions that can go red on the real defect. Until they pass,
# the README's claim that file URLs "cannot be shared outside the site" is false
# for every document in the library.

result="$(fetch "$FILE_MEMBERS")"
[ "${result#*|}" = "withheld" ] \
  && ok "the members-only FILE itself is refused anonymously ($result)" \
  || bad "the members-only FILE itself is refused anonymously" \
         "got $result — the bytes are served directly from wp-content/uploads, so the nonce handler is decoration. Every gated document in the library is public to anyone with the URL, and /wp-json/wp/v2/media hands out the URLs."

result="$(fetch "$FILE_MEMBERS" "$tmp/b.jar")"
[ "${result#*|}" = "withheld" ] \
  && ok "the members-only FILE is refused to a signed-in non-member ($result)" \
  || bad "the members-only FILE is refused to a signed-in non-member" \
         "got $result — being signed in is not membership; this is the same hole with a session attached"

# The anonymous media index is the other half: it publishes the paths. Even once
# the bytes are protected, a public listing of every gated file's URL is a leak
# worth failing on rather than tolerating.
media="$(curl -s "$SITE/wp-json/wp/v2/media?per_page=100")"
if printf '%s' "$media" | grep -q "$(basename "$FILE_MEMBERS")"; then
  bad "the anonymous media index does not list gated files" \
      "GET /wp-json/wp/v2/media returned $(basename "$FILE_MEMBERS") to an unauthenticated request"
else
  ok "the anonymous media index does not list gated files"
fi

# --- and the other side of the gate ----------------------------------------
# A public document must still be reachable signed out, or "everything is
# refused" would score full marks on a handler that simply denies everyone.

result="$(fetch "$LINK_ANON_PUBLIC")"
[ "${result#*|}" = "served" ] \
  && ok "a public document is served signed out ($result)" \
  || bad "a public document is served signed out" \
         "got $result — the handler may be refusing everything, which would fake every pass above"

# --- seeking inside a gated video, signed in, over HTTP ---------------------
#
# The other way out of the same gate. Everything above is a document fetched
# whole through ?sl_download=; this is ?sl_stream= with a Range header, which is
# what a player does when a student drags to minute forty.
#
# WHY THE FIXTURE IS 2 MB. Apache's byterange filter satisfies a Range request
# itself on responses it has fully buffered, so a small file returns 206 with a
# correct Content-Range whether or not the PHP handler implements ranges at all.
# Measured on this stack: 1 KB -> 206, 64 KB -> 200, 1 MB -> 200, 50 MB -> 200.
# Three sessions reached the opposite conclusion from one small file. Anything
# asserted here below ~64 KB tests Apache and reports it as the product.
#
# ASSERT THE BYTES, NOT THE COUNT. This is the trap that caught the author, and
# it is worth stating because the count is the obvious sufficient check and it
# is not one: 206, a correct Content-Range, and exactly 19 bytes ALL pass while
# the handler returns the WRONG 19 bytes — the head of the file every time, the
# precise bug this section exists to catch. Only comparing the returned bytes to
# the marker planted at that offset proves the seek landed, which is why the two
# wrong-offset probes below are here: they establish that different offsets
# return different content, so a matching marker cannot be a coincidence.
#
# The stream URL is scraped off the rendered page with a real login, like every
# link above. An earlier version minted the nonce instead, on a measurement that
# showed no player on the page — which was wrong, and wrong in a way worth
# recording: the fixture had not set _scholaris_video_source, so has_video()
# returned false and the template correctly rendered nothing. "The feature is
# not built yet" and "my fixture does not satisfy its precondition" look
# identical from outside, and only one of them is somebody else's bug.

say ""
say "--- gated video, signed in, seeking over HTTP ---"

vid="$(MSYS_NO_PATHCONV=1 docker compose --profile tools run --rm cli \
  wp eval-file /scripts/video-gate-probe.php --allow-root 2>/dev/null | grep -E '^[A-Z_]+=')"

if [ -z "$vid" ]; then
  bad "the video fixture could be created" "video-gate-probe.php produced nothing — stack down, or /scripts unmounted"
else
  eval "$vid"

  # Scraped off the rendered page with student A's real login cookies, exactly
  # like the document links above — NOT minted. An earlier version minted the
  # nonce because the player appeared not to render; it renders, and the reason
  # it seemed not to is recorded in video-gate-probe.php. Scraping is strictly
  # stronger: a minted nonce proves the streamer works, while a scraped one also
  # proves a student can obtain it, so the player element vanishing is a failure
  # here rather than something the harness routes around.
  V_URL="$(curl -s -b "$tmp/a.jar" "$PERMALINK" \
    | grep -oE 'https?://[^"'"'"' ]*\?sl_stream=[0-9]+(&|&amp;|&#038;)_wpnonce=[a-z0-9]+' \
    | head -1 | sed -e 's/&#038;/\&/g' -e 's/&amp;/\&/g')"

  if [ -z "$V_URL" ]; then
    bad "the video material page offers a stream link to a signed-in student" \
        "no ?sl_stream= URL on $PERMALINK — either the player block stopped rendering, or the material lost _scholaris_video_source (SL_Meta::has_video() returns false without it whatever the attachment id says)"
  else
    V_JAR="$tmp/a.jar"

    # Control, same discipline as the document half: if the whole file will not
    # stream to its owner, every refusal and every byte count below is noise.
    whole="$(curl -s -o "$tmp/whole.bin" -w '%{http_code}' -b "$V_JAR" "$V_URL")"
    got="$(wc -c < "$tmp/whole.bin" | tr -d ' ')"

    if [ "$whole" = "200" ] && [ "$got" = "$SIZE" ]; then
      ok "control: the signed-in student streams the whole video ($whole, $got bytes)"

      code="$(curl -s -D "$tmp/rh.txt" -o "$tmp/seek.bin" -w '%{http_code}' \
        -b "$V_JAR" -H "Range: bytes=$MARKER_OFFSET-$((MARKER_OFFSET+18))" "$V_URL")"
      cr="$(grep -i '^content-range' "$tmp/rh.txt" | tr -d '\r' | sed 's/^[Cc]ontent-[Rr]ange: *//')"
      n="$(wc -c < "$tmp/seek.bin" | tr -d ' ')"
      body="$(cat "$tmp/seek.bin")"
      want="$(printf '%s' "$MARKER" | cut -c1-19)"

      [ "$code" = "206" ] \
        && ok "a mid-file range answers 206, not 200 with the whole file" \
        || bad "a mid-file range answers 206, not 200 with the whole file" \
               "got $code — at $SIZE bytes Apache does not slice this, so 200 means the handler ignored the seek"

      [ "$cr" = "bytes $MARKER_OFFSET-$((MARKER_OFFSET+18))/$SIZE" ] \
        && ok "Content-Range names the requested window ($cr)" \
        || bad "Content-Range names the requested window" "got '$cr'"

      [ "$n" = "19" ] \
        && ok "exactly 19 bytes come back, not the rest of the file" \
        || bad "exactly 19 bytes come back, not the rest of the file" "got $n"

      # The assertion the three above cannot make between them.
      [ "$body" = "$want" ] \
        && ok "the bytes ARE the ones at offset $MARKER_OFFSET (seek landed)" \
        || bad "the bytes ARE the ones at offset $MARKER_OFFSET (seek landed)" \
               "got '$body', wanted '$want' — a correct status, a correct Content-Range and a correct byte count can all be true of the wrong bytes"

      head19="$(curl -s -b "$V_JAR" -H 'Range: bytes=0-18' "$V_URL")"
      tail19="$(curl -s -b "$V_JAR" -H "Range: bytes=$((SIZE-19))-" "$V_URL")"

      [ "$head19" != "$tail19" ] && [ "$head19" != "$body" ] \
        && ok "control: different offsets return different bytes" \
        || bad "control: different offsets return different bytes" \
               "head, tail and mid are not all distinct — the marker match above could be an artefact of a handler returning the same bytes for every range"

      unsat="$(curl -s -o /dev/null -w '%{http_code}' -b "$V_JAR" -H 'Range: bytes=99999999-' "$V_URL")"
      [ "$unsat" = "416" ] \
        && ok "a range past the end is refused with 416" \
        || bad "a range past the end is refused with 416" "got $unsat"

      anon="$(curl -s -o /dev/null -w '%{http_code}' -H "Range: bytes=$MARKER_OFFSET-$((MARKER_OFFSET+18))" "$V_URL")"
      [ "$anon" = "403" ] \
        && ok "the same URL, same nonce, signed out is refused ($anon)" \
        || bad "the same URL, same nonce, signed out is refused" \
               "got $anon — the streaming route is a second way to the gated bytes and must gate like the first"

      # Objection 1 to docs/11-admin-console.md §9.3 item 3, encoded so it
      # cannot silently regress: the spec still says to record this as 200.
      raw="$(curl -s -o /dev/null -w '%{http_code}' "$RAW_URL")"
      [ "$raw" = "403" ] \
        && ok "the video's raw uploads URL is refused anonymously ($raw)" \
        || bad "the video's raw uploads URL is refused anonymously" \
               "got $raw at $RAW_URL — placement did not move an uploaded video on members-only material, and the streaming gate above is decoration"
    else
      bad "control: the signed-in student streams the whole video" \
          "got $whole, $got bytes (wanted 200, $SIZE) — every range assertion below would be meaningless, skipping them"
    fi
  fi
fi

printf '\n%d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1
