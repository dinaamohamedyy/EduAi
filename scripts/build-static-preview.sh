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

# Deliberately the ONLY file. Serving config lives in the repo-root vercel.json,
# because that is the one Vercel reads for a Git-integration build; a copy in
# here would be served as a public file and confuse the next reader about which
# one is in effect.
ok "index.html written"

say "Stamping the build with its source commit"

# Vercel builds `origin/main`, not anyone's working tree. Six sessions share one
# working copy here, commits land irregularly and pushes lag further, so what is
# deployed trails what is on disk by an unpredictable amount — it was six
# commits once, and one commit plus nine uncommitted files an hour later. The
# published mock then shows superseded copy while looking perfectly current,
# which on this project is the expensive failure: the owner's first bug report
# was against the mock believing it was the site.
#
# docs/03 tells the reader to compare HEAD, origin/main and git status before
# trusting the URL. That works only if they remember. Stamping the commit into
# the page makes it answerable from the page itself.
if [ -n "${VERCEL_GIT_COMMIT_SHA:-}" ]; then
	sha="$(printf '%s' "$VERCEL_GIT_COMMIT_SHA" | cut -c1-7)"   # set by Vercel
elif command -v git >/dev/null 2>&1 && git -C "$repo" rev-parse --short HEAD >/dev/null 2>&1; then
	sha="$(git -C "$repo" rev-parse --short HEAD)"
else
	sha="unknown"
fi

# An "unknown" stamp is the vacuous version of this feature: the meta tag is
# present, the assertion passes, and the page answers "which commit?" with a
# shrug — while looking like the mechanism works. Tolerable locally (a bare
# export of the index is not a git repo and has no Vercel env), never on the
# surface people actually read. Vercel sets both VERCEL and
# VERCEL_GIT_COMMIT_SHA on a Git-integration build, so failing to resolve one
# there means something changed and the stamp can no longer be trusted.
if [ "$sha" = "unknown" ]; then
	if [ -n "${VERCEL:-}" ]; then
		die "building on Vercel but VERCEL_GIT_COMMIT_SHA is unset — refusing to publish a page that cannot say which commit it came from"
	fi
	printf '  \033[0;33m!!\033[0m no commit id available (not a git checkout, not Vercel) — stamping "unknown"\n' >&2
fi

built="$(date -u '+%Y-%m-%d %H:%MZ')"

# Machine-readable, and the thing the assertion below checks.
perl -i -pe "
	if (!\$done && m{</head>}i) {
		s{</head>}{  <meta name=\"built-from-commit\" content=\"$sha\">\n  <meta name=\"built-at\" content=\"$built\">\n</head>}i;
		\$done = 1;
	}
" "$out/index.html"

# Human-readable, in the browser tab. Best-effort: the front-end dev owns this
# title and may reword it. Its absence is not worth failing a build over, but
# the meta above is, so that is what gets asserted.
perl -i -pe "s{<title>(.*?)</title>}{<title>\$1 · $sha</title>} if !\$t && m{<title>}i and \$t = 1" "$out/index.html"

grep -q "built-from-commit\" content=\"$sha\"" "$out/index.html" \
	|| die "could not stamp the source commit into the page"
ok "stamped $sha ($built)"

say "Refusing to ship a key"

# Explicit path => check-no-secrets.pl applies NO exemptions. That matters: the
# preview.html exemption exists for a developer's working tree and must never
# apply to something about to be made public.
#
# Perl is present locally and in GitHub Actions, but this also runs inside
# Vercel's build container, where it is not guaranteed. Falling back silently
# to "no check" would make this guard vacuous exactly where it is hardest to
# notice, so the fallback is a real scan and its absence is fatal, never
# skipped.
if command -v perl >/dev/null 2>&1; then
	if ! perl "$repo/scripts/check-no-secrets.pl" "$out" >/dev/null 2>&1; then
		printf '\n' >&2
		perl "$repo/scripts/check-no-secrets.pl" "$out" >&2 || true
		printf '\n' >&2
		die "a key reached the build output — nothing has been deployed"
	fi
	ok "no API key in the build output (check-no-secrets.pl)"
else
	# Same shapes the Perl scanner looks for. Length-bounded so the provider
	# *detection patterns* in the page ("sk-ant-", /^gsk_/) do not self-match:
	# the page legitimately contains those prefixes as string literals.
	if grep -rqE "gsk_[A-Za-z0-9]{20,}|sk-ant-[A-Za-z0-9_-]{20,}|[0-9a-f]{32}\.[A-Za-z0-9]{16,}" "$out"; then
		grep -rnE "gsk_[A-Za-z0-9]{20,}|sk-ant-[A-Za-z0-9_-]{20,}|[0-9a-f]{32}\.[A-Za-z0-9]{16,}" "$out" >&2 || true
		die "a key reached the build output — nothing has been deployed"
	fi
	ok "no API key in the build output (grep fallback; perl unavailable)"
fi

# Belt to the above braces: assert the literal line is empty, in case the
# scanner's patterns ever drift behind a new provider's key shape.
if ! grep -qE "^var API_KEY = '';" "$out/index.html"; then
	die "API_KEY in the built page is not empty — refusing to publish"
fi
ok "API_KEY line is empty"

# Everything in this directory becomes public, so anything unexpected in it is
# a leak. One file, by design.
count=$(find "$out" -type f | wc -l)
[ "$count" -eq 1 ] || die "expected exactly 1 file in the output, found $count"
ok "output contains exactly 1 file, the intended one"

say "Done"
cat <<EOF
  Built: dist/preview-site/index.html — the design mock-up
         (assistant / calculator / exams are inert in it)

  Vercel's GitHub integration runs this automatically: repo-root vercel.json
  sets buildCommand to this script and outputDirectory to dist/preview-site,
  so only the built page is served — not wp-content, docs or scripts.

  To publish by hand instead (this directory only, never the repo root, which
  holds the gitignored preview.html with a live key in it):

    npx vercel deploy --prod dist/preview-site

  This publishes a DESIGN MOCK-UP, not the working site. The AI features
  need WordPress on a PHP host: see docs/03-hosting-deployment.md.
EOF
