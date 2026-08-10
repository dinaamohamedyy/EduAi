# A scheduled home for the live-stack harnesses — proposal

**Status: proposal, 10 Aug 2026.** Written by the front end at the Tech
Manager's request; nothing here is implemented. Implementation is roughly a
day once the three decisions in §6 are made.

The problem is stated in one line: seven live-stack harnesses run when
someone remembers. Tonight someone remembered five times; the admin-bar bug
shipped anyway, because remembering is not a schedule and every harness ran
logged out besides.

---

## 1. What exists, and what each run needs

Per-push CI (deploy.yml lint job) already covers everything that runs
without a site: secrets (including inside committed archives), syntax across
four languages, the 15 contract checks, calc parity both halves, and the
redaction guard over the fixture. That tier is healthy and unchanged.

The live-stack harnesses — everything below needs the compose stack up and
`wp eval-file` (or a browser) against it:

| Harness | Asks | Model spend | Notes |
|---|---|---|---|
| `page-drift.php` | do the pages setup.sh promises exist? | none | read-only |
| `projection-leak.php` | does the answer key reach the browser? | none | model stubbed |
| `submit-contract.php` | is the §3 submit shape exact (tri-state, every key)? | none | model stubbed |
| `rate-limit.php` | does the per-user bucket refuse at N+1, per user? | none | creates users |
| `download-gate.sh` (+`.php`) | are gated files denied cross-user, allowed same-user? | none | creates users + files |
| `grade-adversarial.php` | do the four grading rules survive a real model's lies? | **yes** | real model |
| `roundtrip.php` | does a real deck → generate → answer → mark round-trip? | **yes** | real model, the E2E |
| `ui-geometry.mjs` | signed-in geometry: admin bar, nav row, containing block | none | needs a browser + cookie mint |
| `visual-checks.js` | can a human see the product? | none | **deliberately unschedulable** — see §5 |

The split that matters is the spend column: five of seven PHP harnesses stub
the model at `pre_http_request` and cost nothing; two exist precisely
because stubs cannot answer their question, and they spend real credit on
every run.

---

## 2. Tier N — nightly, in CI, free

A second workflow (`nightly.yml`), `schedule:` cron plus
`workflow_dispatch`, that does what a developer does locally:

1. checkout; `docker compose up -d` (WordPress + MariaDB — Actions runners
   ship compose);
2. `bash scripts/setup.sh` via the cli profile — which also exercises setup
   convergence (page drift, titles, menu) as a side effect on every run;
3. run the five free harnesses in order: `page-drift`, `projection-leak`,
   `submit-contract`, `rate-limit`, `download-gate.sh`;
4. install Chrome (`browser-actions/setup-chrome`, or the runner's own) and
   run `node scripts/ui-geometry.mjs --edge "$(which google-chrome)"` — the
   flag already exists; the script needs only its Edge default parametrised,
   a two-line change;
5. one summary line per harness in the job summary, red job on any failure.

No secrets needed, no spend, ~6 minutes of runner time. This tier alone
converts "when someone remembers" into "every night, witnessed".

**What it does not prove:** the owner's actual machine. That is deliberate —
the nightly proves the *code* against a fresh stack; the dev machine's
quirks (BIOS virtualisation, stale PATH, sleeping laptop) are documented in
memory and are not a scheduling problem.

---

## 3. Tier S — the spend tier: weekly + before release, gated

`grade-adversarial.php` and `roundtrip.php` hit a real model. They are the
two most valuable checks in the repo and the two that cannot run nightly
without someone deciding that spend is fine.

Proposal: same workflow, separate job, three gates —

- runs only when the `GROQ_API_KEY` repository secret exists (absent secret
  → job skips loudly, never fails);
- `schedule:` weekly (Sunday night) plus `workflow_dispatch` for
  pre-release runs — the docs/03 checklist gains one line: "dispatch the
  spend job, wait for green";
- a spend note in the job summary: both harnesses together are a handful of
  Groq free-tier calls today (~10 requests); if the provider or tier ever
  changes, the note is where the number lives.

Weekly matches how often the grading path changes now that the contract is
frozen; dispatch covers "we are about to ship".

---

## 4. The local command — one name instead of seven

For the team's own loops, a wrapper so the local ritual is one line:

    bash scripts/live-checks.sh          # the five free harnesses + geometry
    bash scripts/live-checks.sh --spend  # adds the two model harnesses

Windows Task Scheduler on the dev machine was considered and rejected as the
scheduled home: the machine sleeps, the Docker engine has a documented BIOS
dependency, and a scheduled job that silently doesn't run is the exact
failure mode this proposal exists to end. The nightly CI is the schedule;
the wrapper is for humans.

---

## 5. What stays human, on purpose

`visual-checks.js` is paste-into-a-real-browser by design — its value is
eyes on a rendered page, and a headless assertion of "can you see it" is a
contradiction. It stays manual with a trigger rather than a schedule:
**run it after any merge that touches CSS**, both themes, per its own
eight-run procedure. The tester owns the trigger discipline; the two-edge
theme-switch pings from today are already the working protocol.

---

## 6. Decisions needed (owner / Tech Manager)

1. **Enable the nightly tier?** Costs Actions minutes only. (Recommended:
   yes, immediately — it is the cheap 80%.)
2. **Fund the spend tier?** Needs `GROQ_API_KEY` as a repository secret and
   sign-off that ~10 model calls weekly is acceptable. (Recommended: yes,
   weekly + dispatch.)
3. **Post-deploy smoke against production** — a third, later tier: after the
   deploy job, run the read-only pair (`page-drift` semantics via HTTP 200
   sweep + `ui-geometry.mjs --url https://…`) against the real host. Blocked
   on the hosting decision; noted here so it lands in the same file when
   that decision exists. State-creating harnesses (rate-limit,
   download-gate) stay off production permanently.

Ownership (revised by the Tech Manager on approval, 10 Aug 2026): **the
tester writes `scripts/live-checks.sh`** — they own the harnesses, their
prerequisites (fixtures, `ui-admin`, the cookie mint), and the two guards
that must live in one place: free set by default with `--spend` for the
model pair, and *refusing* rather than warning when the target is not
localhost. The front end writes `nightly.yml`, which composes the stack and
**calls the wrapper rather than re-listing the harnesses** — one source of
truth for what the harnesses are and how each is invoked, so the wrapper
and the workflow cannot drift apart into a nightly that quietly runs six of
seven. Front end also owns the `ui-geometry.mjs` parametrisation; back end
owns the spend budget line. Nothing here touches the AI engineer's
territory.

Status: nightly tier approved and `nightly.yml` landed 10 Aug 2026 — red by
design until the wrapper exists (the workflow's first step asserts it,
loudly). Decisions 2 and 3 remain with the owner.
