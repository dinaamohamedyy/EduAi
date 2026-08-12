# Who is in which file

**Check this before you start editing a file you do not normally own. Then ping the
Tech Manager anyway if the claim looks stale — a quiet file is not an unowned file.**

This exists because on 12 Aug 2026 three sessions edited `scripts/projection-leak.php`
within one hour. None was careless. The Tech Manager held the assignment map in his
head and told one session about a gap without saying it was already assigned; messages
between sessions crossed seven times that day. **No session could see what another had
been given**, so "is anyone in this file?" had no answer except asking — and asking
requires already suspecting.

That is a coordination shape, not three individual lapses, and the fix is a place to look.

## Standing rules

1. **Claim before you write**, not after. Tell the Tech Manager; he updates this file.
2. **A claim without a date is expired.** Treat it as unowned and ping.
3. **Handshake at boundaries.** If you need a file someone else holds, they ping you when
   clear — never estimate their pace. Timing guesses put a chart cut and a profile screen
   into each other's commits on the same day this file was written.
4. **Prefer standing down to sweeping.** An uncommitted file of mixed authorship is worth
   more than a clean commit that discards someone's work.
5. **This file goes stale the moment it is wrong.** It is a lookup, not an authority — the
   same hazard as the guard list that sent an engineer to wire five things already wired.
6. **Committing is not landing. Push.** CI runs on push, so a commit nobody pushes is
   invisible to every gate in this repo. Two were found sitting local on 12 Aug 2026 —
   `e1798af` and front-end's Node precondition guard — both by a third party noticing,
   not by any check. Nothing detects this; only the habit does.
   **And `git fetch` FIRST.** `origin/main` is a local cache of the remote as of your last
   fetch, so `git log origin/main..HEAD` on a stale ref lists commits that are already
   pushed. The Tech Manager reported two such commits as unpushed the same day this rule
   was written, having fetched before one query and not the next. **"In the file", "in the
   repository" and "on the remote" are three different claims**, and the third is only ever
   answered as of your last fetch.
7. **NEVER `git reset --hard` in this tree. Not even to undo your own commit.** It discards
   every session's uncommitted changes to tracked files, tree-wide — and unlike a bad push
   there is nothing to recover from. Use `--soft` or `--mixed`, which drop your commit and
   touch nobody's working copy. On 12 Aug 2026 one `reset --hard HEAD~1` destroyed two other
   sessions' in-progress work; the person who ran it checked the two files they knew were
   dirty, found them clean, and concluded no loss was detectable. **The losses were in files
   they had no reason to look at.**
   **And it is unrecoverable specifically for UNSTAGED work.** Staged content survives as a
   dangling blob and can be fished out with `git fsck`; unstaged content was never written to
   the object database, so no artefact exists anywhere. That is why this beats a bad push, a
   bad merge and a bad rebase — all of which leave something behind.
8. **Never delete `.git/index.lock`. A stale-looking lock is another session committing.**
   Waiting costs seconds; clearing it corrupts someone else's in-flight commit, and it is the
   same class of act as `reset --hard` — you cannot see whose work you are standing on. Two
   sessions hit a live lock on 12 Aug 2026; both waited, and both were right that a concurrent
   operation was underway.
9. **Restoring code does not restore the database.** A plant-run-restore cycle is only
   complete when the state the mutation wrote is also reverted. On 12 Aug 2026 a `rate-limit`
   mutation was reverted in the file hours before its `eduai_rl_shared` transient — left at
   the ceiling — made `submit-contract` fail for someone else, as a `429` that looked like a
   refusal. Every mutation against a stateful path has this exposure.
10. **Commit the moment it lints; verify afterwards.** Two sessions lost verified, working
   fixes the same day by holding them uncommitted while checking them. A commit is the only
   durable storage in a tree six sessions write to — fixing forward from a committed mistake
   is always cheaper than re-deriving a lost correct one. Explicit pathspec, as always.
9. **Git cannot tell you who wrote something.** Every session commits as `Scholaris`, so
   `git log --format=%an` is uniform and blame proves nothing about authorship. Three
   attributions had to be settled by asking on 12 Aug 2026. Name contributors in the
   commit *body* by what they built — it is the only durable record there is.

## Current claims

_Updated 12 Aug 2026 by Tech Manager._

| File / area | Held by | Note |
|---|---|---|
| `scripts/projection-leak.php` | — free — | Landed `540ab20`, 36/36. Main credited in the commit body. |
| `wp-content/plugins/eduai-assistant/includes/class-eduai-exams.php` | Back-end | Released 12 Aug. Tester and deployment engineer both finished their mutations and restored byte-identical. |
| `wp-content/plugins/eduai-assistant/includes/class-eduai-knowledge.php` | AI engineer | Retrieval filter + optional scope (`462692a`). |
| `wp-content/plugins/eduai-assistant/includes/class-eduai-scope.php` | Back-end | Resolves and **gates** `?source=`. Gate is `has_enrolled_content_access() \|\| current_user_can('edit_posts')`. |
| `wp-content/plugins/eduai-assistant/includes/class-eduai-rest.php` | Back-end | Incl. the scope→`retrieve()` wiring at `:471`. AI engineer supplies the line; back-end lands it. |
| `wp-content/themes/scholaris/inc/auth.php` | Front-end + Back-end | Pathspec being agreed; back-end is blocked pending answer |
| `wp-content/plugins/scholaris-library/` (PHP) | Back-end | `SL_Console`, `SL_Private`, `SL_Meta` |
| `wp-content/plugins/scholaris-library/templates/` | Front-end | incl. `admin/console.php`, written by back-end as a structure to style |
| `scripts/page-drift.php` | Deployment engineer | Fixing computed-but-discarded `absent` signal |
| `scripts/download-gate.php` | — free — | `file_exists()` fix landed `e1798af`. Note: this guard's result depends on **stack age** — a green is only meaningful alongside which stack ran it, and the nightly is the one that tells the truth. |
| `.github/workflows/`, `scripts/live-checks.sh` | Deployment engineer + Main | Nightly wiring and manifest |
| `docs/09-multi-agent-retrospective.md` | Main | Sole author by agreement — collisions here caused §2 |
| `.github/workflows/php.yml` | **owner** | Stock Composer starter, no `composer.json`, fails every push |
