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

say "Starting the stack"
"${COMPOSE[@]}" up -d db wordpress mailpit || die "could not start containers"
ok "containers up"

# The database accepts connections before it is ready to be written to, and
# wp-cli's failure in that window reads like a credentials problem rather than a
# timing one. Wait for the healthcheck the compose file already defines.
say "Waiting for the database"
for _ in $(seq 1 60); do
	state=$(docker inspect -f '{{.State.Health.Status}}' scholaris-db 2>/dev/null || echo starting)
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
for path in "" library/ summarise/ calc/ ask/ prepare/ progress/ sign-in/; do
	code=$(docker exec scholaris-wp curl -s -o /dev/null -w '%{http_code}' "http://localhost/${path}" || echo 000)
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
