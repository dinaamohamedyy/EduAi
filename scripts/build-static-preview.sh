#!/usr/bin/env bash
#
# Build an isolated, publishable copy of the design mock-up for static hosting
# (Vercel, Netlify, GitHub Pages — anything that serves files).
#
#     bash scripts/build-static-preview.sh
#     npx vercel deploy --prod dist/preview-site
#
# WHY A SEPARATE FOLDER, AND NOT `vercel` IN THE REPO ROOT.
#
# The Vercel CLI uploads the working directory and does NOT read .gitignore —
# it reads .vercelignore, which this repo has never had. Running it here would
# publish `preview.html` (the front-end dev's working copy, which holds a LIVE
# Groq key) and `.env` (which holds another). Both are gitignored, so every
# guard in this repo is blind to them: check-no-secrets.pl skips them by design,
# the pre-commit hook scans the git index, and CI scans a git checkout. None of
# those protect an upload that never touches git.
#
# So this builds a directory containing exactly two files and nothing else.
# There is nothing in it to leak. That is the whole design.
#
# WHAT YOU GET. The static design mock-up: real layout, real copy, real
# navigation. The assistant, the calculator and the exams DO NOT WORK — they
# need the WordPress plugins and a server-side API key. design/preview.html
# ships with `var API_KEY = ''` on purpose and this script refuses to build if
# that ever stops being true. Do not "fix" that by pasting a key in: a key in a
# static page is readable by anyone who views source, and it would be public the
# moment this deploys.

set -euo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
src="$repo/design/preview.html"
out="$repo/dist/preview-site"

say() { printf '\n\033[1;36m> %s\033[0m\n' "$1"; }
ok()  { printf '  \033[0;32mok\033[0m %s\n' "$1"; }
die() { printf '  \033[0;31mFAILED\033[0m %s\n' "$1" >&2; exit 1; }

# Leave NOTHING deployable behind on failure. The first version of this script
# printed "a key reached the build output" and then left that output sitting in
# dist/preview-site — so the very next `npx vercel deploy --prod dist/preview-site`
# would have published the key the check had just caught. A guard that fails
# loudly while leaving the loaded gun on the table is not a guard. Any non-zero
# exit, from any cause including an unexpected one, removes the directory.
cleanup_on_failure() {
	rc=$?
	if [ "$rc" -ne 0 ] && [ -n "${out:-}" ] && [ -d "$out" ]; then
		rm -rf "$out"
		printf '  \033[0;31m--\033[0m removed %s — there is nothing to deploy\n' "${out#"$repo/"}" >&2
	fi
	exit "$rc"
}
trap cleanup_on_failure EXIT

say "Checking the source"

[ -f "$src" ] || die "design/preview.html not found"

# The shippable copy is the key-free one. The root preview.html is the working
# copy WITH a live key; picking it up here would be the exact accident this
# script exists to prevent, so assert we read the right file.
case "$src" in
	*/design/preview.html) ;;
	*) die "refusing to build from anything but design/preview.html" ;;
esac
ok "source is design/preview.html ($(wc -c < "$src") bytes)"

say "Building $out"

rm -rf "$out"
mkdir -p "$out"
cp "$src" "$out/index.html"

# Static host config. No build step, no framework: serve the files as they are.
cat > "$out/vercel.json" <<'JSON'
{
  "$schema": "https://openapi.vercel.sh/vercel.json",
  "cleanUrls": true,
  "headers": [
    {
      "source": "/(.*)",
      "headers": [
        { "key": "X-Robots-Tag", "value": "noindex, nofollow" }
      ]
    }
  ]
}
JSON

ok "index.html + vercel.json written"

say "Refusing to ship a key"

# Explicit path => check-no-secrets.pl applies NO exemptions. That matters: the
# preview.html exemption exists for a developer's working tree and must never
# apply to something about to be made public.
if ! perl "$repo/scripts/check-no-secrets.pl" "$out" >/dev/null 2>&1; then
	printf '\n' >&2
	perl "$repo/scripts/check-no-secrets.pl" "$out" >&2 || true
	printf '\n' >&2
	die "a key reached the build output — nothing has been deployed"
fi
ok "no API key in the build output"

# Belt to the above braces: assert the literal line is empty, in case the
# scanner's patterns ever drift behind a new provider's key shape.
if ! grep -qE "^var API_KEY = '';" "$out/index.html"; then
	die "API_KEY in the built page is not empty — refusing to publish"
fi
ok "API_KEY line is empty"

# The upload is the whole directory, so anything unexpected in it is a leak.
count=$(find "$out" -type f | wc -l)
[ "$count" -eq 2 ] || die "expected exactly 2 files in the output, found $count"
ok "output contains exactly 2 files, both intended"

say "Done"
cat <<EOF
  Built: dist/preview-site/
    index.html   the design mock-up (assistant / calculator / exams inert)
    vercel.json  static config, noindex

  Deploy it (this directory only — never the repo root):

    npx vercel deploy --prod dist/preview-site

  This publishes a DESIGN MOCK-UP, not the working site. The AI features
  need WordPress on a PHP host: see docs/03-hosting-deployment.md.
EOF
