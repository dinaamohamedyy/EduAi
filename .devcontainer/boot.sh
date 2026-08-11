#!/usr/bin/env bash
#
# Brings the real EduAi up inside a Codespace: WordPress, MariaDB, the theme,
# both plugins, all seven tabs. Not the mock — the application.
#
# Run automatically by devcontainer.json on create. Safe to run again by hand:
#
#     bash .devcontainer/boot.sh
#
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

say() { printf '\n\033[1;36m> %s\033[0m\n' "$1"; }
ok()  { printf '  \033[0;32mok\033[0m %s\n' "$1"; }
die() { printf '  \033[0;31mFAILED\033[0m %s\n' "$1" >&2; exit 1; }

# ---------------------------------------------------------------- the address
#
# A Codespace's public hostname is derived from its name, not from anything in
# the repository. Computing it here — rather than asking someone to paste it —
# is what makes this one command instead of a procedure with a manual step in
# the middle, and manual steps are what did not happen last time.
if [ -n "${CODESPACE_NAME:-}" ]; then
	SITE_URL="https://${CODESPACE_NAME}-8080.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}"
else
	SITE_URL="http://localhost:8080"
fi
export SITE_URL

say "Site address"
ok "$SITE_URL"

# ------------------------------------------------------------------ the key
#
# Never written to a file, never committed, never asked for in the UI. In a
# Codespace it arrives as a repository secret (Settings → Secrets and variables
# → Codespaces). Absent, everything except the AI features still works, so the
# boot continues rather than failing — but it says so plainly, because "the
# site is up and the assistant is silent" is otherwise a confusing state.
say "AI provider"
if [ -n "${GROQ_API_KEY:-}" ]; then
	ok "GROQ_API_KEY present (${#GROQ_API_KEY} chars) — the assistant will answer"
else
	printf '  \033[0;33mwarn\033[0m no GROQ_API_KEY set. The site will run and every AI feature will fail.\n'
	printf '        Add it as a Codespaces secret named GROQ_API_KEY, then re-run this script.\n'
fi

COMPOSE=(docker compose -f docker-compose.yml -f .devcontainer/compose.codespaces.yml)

# Optional extra overlay, so this script can be run end-to-end somewhere a stack
# is ALREADY up under the same container names — which is every machine on this
# project. Without it the only way to test the deploy is to break the running
# site, so it never gets tested and ships on hope. Unset in a Codespace, where
# nothing is running yet. Combine with COMPOSE_PROJECT_NAME, which compose reads
# on its own, to get a fully isolated stack.
[ -n "${EXTRA_COMPOSE_FILE:-}" ] && COMPOSE+=(-f "$EXTRA_COMPOSE_FILE")

say "Starting the stack"
"${COMPOSE[@]}" up -d db wordpress mailpit || die "could not start containers"
ok "containers up"

# Containers are addressed through compose, never by the container_name in the
# compose file. Hardcoding scholaris-db/scholaris-wp would work in a Codespace
# and make this script untestable anywhere a stack is already running under
# those names — which is every developer machine on this project. Asking compose
# means the script can be proven in an isolated project before anyone relies on
# it, and that is the only reason to trust it.
dbc() { "${COMPOSE[@]}" ps -q db; }
wpc() { "${COMPOSE[@]}" ps -q wordpress; }

# The database accepts connections before it is ready to be written to, and
# wp-cli's failure in that window reads like a credentials problem rather than a
# timing one. Wait for the healthcheck the compose file already defines.
say "Waiting for the database"
for _ in $(seq 1 60); do
	id=$(dbc 2>/dev/null || true)
	state=$([ -n "$id" ] && docker inspect -f '{{.State.Health.Status}}' "$id" 2>/dev/null || echo starting)
	[ "$state" = "healthy" ] && break
	sleep 2
done
[ "${state:-}" = "healthy" ] || die "database did not become healthy"
ok "database healthy"

say "Installing WordPress, the theme, the plugins and the pages"
"${COMPOSE[@]}" --profile tools run --rm \
	-e SITE_URL="$SITE_URL" \
	-e GROQ_API_KEY="${GROQ_API_KEY:-}" \
	cli bash /scripts/setup.sh || die "setup.sh did not finish"

# ------------------------------------------------------------------ verify
#
# setup.sh finishing is not the same as the site serving, and this project has
# reported success off the former more than once. Fetch the real pages.
say "Checking the site actually serves"
fails=0
wp_id=$(wpc)
[ -n "$wp_id" ] || die "the WordPress container is not running"

# Request the way GitHub's proxy does, not the way curl would by default.
#
# Both headers are load-bearing. Without the Host header WordPress sees a
# request for localhost, does not recognise it as its own address, and issues a
# canonical 301 to the real one — a redirect that says nothing about whether the
# page works. Without X-Forwarded-Proto the shim in the compose overlay never
# fires, so this check would pass while the live site redirect-loops for every
# real visitor. Sending both means a 200 here is evidence about the deployed
# site rather than about localhost.
host="${SITE_URL#https://}"
host="${host#http://}"

for path in "" library/ summarise/ calc/ ask/ prepare/ progress/ sign-in/; do
	code=$(docker exec "$wp_id" curl -s -o /dev/null -w '%{http_code}' \
		-H "Host: ${host}" \
		-H "X-Forwarded-Proto: https" \
		"http://localhost/${path}" || echo 000)
	if [ "$code" = "200" ]; then
		ok "/${path} ($code)"
	else
		printf '  \033[0;31mFAILED\033[0m /%s returned %s\n' "$path" "$code"
		fails=$((fails + 1))
	fi
done

[ "$fails" -eq 0 ] || die "$fails page(s) are not serving"

printf '\n\033[1;32m%s\033[0m\n' "EduAi is running."
printf '  %s\n' "$SITE_URL"
printf '\n'
printf '  Port 8080 must be PUBLIC for anyone else to open that link.\n'
printf '  Ports panel -> right-click 8080 -> Port Visibility -> Public.\n'
printf '  Left private, the link works for you and 404s for everyone else.\n\n'
printf '  Sign in at %s/sign-in/\n' "$SITE_URL"
printf '  Mail (password resets) is caught locally, at port 8025.\n\n'
