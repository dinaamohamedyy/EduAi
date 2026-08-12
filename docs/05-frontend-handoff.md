# Front-end handoff — pages, templates and what the back end still owns

The front end of EduAi (formerly Scholaris) is design-complete. This document is the map for
whoever wires the remaining server-side behaviour: every route, the template
that renders it, which parts already work with no custom code, and the exact
hooks to extend.

**See everything without WordPress:** open `design/preview.html` in a browser.
The header carries the seven-tab nav (Home · Library · Summarise · AiCalc ·
Q&A · PrepareME · My Progress); the footer links to the auth screens (Sign in ·
Create account · Reset password · 404). Light/dark toggle is in the header.
PrepareME's form and marked report are built against
`docs/07-prepareme-contract.md` and the pinned fixture.

---

## Page inventory

| Route | Rendered by | Status |
|---|---|---|
| `/` (home) | `themes/scholaris/front-page.php` | Done. Pulls live counts, recent material, quiz strip when signed in. |
| `/library/` | page + `[scholaris_library]` (plugin template `library-grid.php`) | Done. |
| `/material/<slug>/` | `scholaris-library/templates/single-study_material.php` | Done. Gated download + inline PDF already implemented. |
| `/progress/` | page + `[scholaris_dashboard]` | Done. Reads Tutor LMS attempts. **`/dashboard/` belongs to Tutor LMS** (created on activation); every theme link resolves through `scholaris_progress_url()`. |
| `/assistant/`, `/summarise/` | pages + `[eduai_panel]` / `[eduai_summarizer]` | Done. Needs a key in `wp-config.php` or the environment. |
| `/sign-in/` | `page-templates/auth-signin.php` | **Works today** against the native login endpoint. |
| `/register/` | `page-templates/auth-register.php` + `inc/auth-flow.php` | **Works today** — full name + password chosen at signup, honeypot + rate limit, auto sign-in, lands on `/progress/?welcome=1`. |
| `/reset-password/` | `page-templates/auth-reset.php` + `inc/auth-flow.php` | **Works today** — native lost-password flow with throttling and themed error bounces. |
| Search results | `index.php` (`is_search()` branch) | Done. |
| Blog post / plain page | `single.php` / `page.php` | Done. |
| 404 | `404.php` | Done. |

`scripts/setup.sh` creates all of these pages (including the three auth pages,
with their templates assigned). Registration is already enabled by setup
(`users_can_register 1`); a `student` role is cloned from subscriber and set
as the site's `default_role`, so everyone who signs up through `/register/`
is a student. Setup ends with a **"Verifying the database"** section that
asserts the plugin tables (`eduai_messages`, `eduai_chunks`), the options
and every page above actually exist — if it prints `!!`, something needs
attention before go-live.

---

## How the auth pages are wired

Two files split the work: **`inc/auth.php`** (front-of-house: routing,
templates, notices, wp-login branding) and **`inc/auth-flow.php`**
(server side: extra registration fields, validation, throttles, sign-in
after signup). The forms post to the **native WordPress endpoints**:

| Form | Posts to | Fields |
|---|---|---|
| Sign in | `site_url('wp-login.php', 'login_post')` | `log`, `pwd`, `rememberme`, `redirect_to`, `sl_from=login`, `wp-submit` |
| Register | `…wp-login.php?action=register` | `user_login`, `user_email`, `sl_from=register` + injected by auth-flow: `sl_name`, `sl_pass1`, `sl_pass2`, `sl_website` (honeypot) |
| Reset | `…wp-login.php?action=lostpassword` | `user_login`, `redirect_to`, `sl_from=lostpassword` |

**Keep the `sl_from` marker on any form you rebuild.** It is how
`scholaris_authflow_from_page()` recognises our own pages, and everything the
back end adds hangs off that answer: themed error bounces, the refill token,
auto sign-in after signup. A missing Referer header used to be enough to lose
all three (privacy modes, a `Referrer-Policy` from a security plugin or CDN,
corporate proxies) and drop the student on the naked wp-login.php screen;
Referer is now only the fallback. The marker is a routing hint, not a
permission — it decides which page renders the outcome, never whether the
outcome is allowed.

Because the action URLs go through `site_url(..., 'login_post')`, they remain
correct when **wps-hide-login** (installed by setup.sh) moves `wp-login.php`.

**Two login URLs are in play, and they are not interchangeable.** This costs an
afternoon the first time and is invisible in a diff:

| Call | Resolves to | What it is for |
|---|---|---|
| `wp_login_url()` | `/sign-in/` | where you send a **person** — the theme filters it to the themed page |
| `site_url( 'wp-login.php', 'login_post' )` | `/login/` | where a **form posts** — wps-hide-login rewrites it |

POST credentials to the first and WordPress simply re-renders the sign-in page:
no error, no cookie, nobody signed in, and a `200` that looks like success. Use
`wp_login_url()` for links and redirects, the `login_post` form of `site_url()`
for anything that submits. On this stack `wp-login.php` itself returns **404**
and `/wp-admin/` redirects to `/404/` — that is wps-hide-login working, not a
broken install, and any script that hard-codes the native path will fail in a
way that reads like broken authentication.

What `inc/auth.php` already does:

- Filters `login_url`, `register_url`, `lostpassword_url` → the themed pages,
  so every "Sign in" link in core, the theme and plugins lands on them.
  Falls back to native URLs if the pages are deleted.
- Applies the right template by slug (`sign-in`, `register`, `reset-password`)
  even if nobody assigns it in the editor.
- Redirects signed-in visitors off the auth pages to their progress page
  (via `scholaris_progress_url()`).
- Sends failed logins back to `/sign-in/?login=failed` instead of the naked
  wp-login screen (`wp_login_failed` + an `authenticate` guard for empty
  fields).
- Brands the **residual native wp-login.php screens** (register validation
  errors reached outside our flow, the actual reset form opened from the
  e-mail link, plugin 2FA interstitials) with the same paper/laurel design,
  fonts included.

What `inc/auth-flow.php` adds on top:

- Renders **Full name / Password / Repeat password** plus a honeypot into
  both the themed form and the native fallback via the `register_form` hook.
- Validates on `registration_errors` (name present, password ≥ 8 chars and
  matching), with a per-IP rate limit (5/hour per bucket) on register and
  reset. Failures bounce back to the themed pages as `?register=…` /
  `?lostpw=…` flags.
- On success stores the real name, sets the chosen password, **signs the
  student in** and redirects to `/progress/?welcome=1`; the admin
  notification e-mail still goes out, the student's "set your password"
  e-mail is skipped (they already chose one). Fires
  `do_action( 'scholaris_student_registered', $user_id )` for anything
  else that should happen on signup.
- Marks the three auth pages `noindex`.

### Notice flags the templates understand

`scholaris_auth_notices()` renders these query flags as styled notices:

| Flag | Shown on | Meaning |
|---|---|---|
| `?login=failed` | sign-in | **wrong password** for an existing account (links to reset) |
| `?login=nouser` | sign-in | **no account** matches the identifier (links to register) |
| `?login=empty` | sign-in | a field was blank |
| `?login=throttled` | sign-in | 8+ failed attempts in 10 min from one IP |
| `?loggedout=true` | sign-in | successful sign-out |
| `?registration=disabled` | any | `users_can_register` is off |
| `?checkemail=confirm` | reset | reset link e-mailed |
| `?checkemail=registered` | sign-in/register | account created, link e-mailed (fallback path) |
| `?password=changed` | sign-in | reset completed |
| `?register=username_exists / email_exists / username / email / name / password / password_short / password_mismatch / throttled / generic` | register | validation bounce from auth-flow |
| `?lostpw=invalid / throttled` | reset | lost-password bounce from auth-flow (any unrecognised value renders the `invalid` message — the render block's `else` is a deliberate catch-all) |
| `?welcome=1` | `/progress/` | set after auto sign-in at signup — free to use for a first-visit banner (nothing renders it yet) |
| `?sl_form=<token>` | any auth page | refill token: what the student typed, flashed server-side for 5 min (never PII in the URL). Templates read it with `scholaris_auth_old( 'login'\|'username'\|'email'\|'name' )` — read once, then burned. |

Notices may carry **one inline link** (`<a href>` only — the renderer runs
`wp_kses` with exactly that whitelist): wrong-password links to reset,
no-such-account and reset-not-found link to register, already-exists links to
sign-in. Enumeration stance: sign-in now says honestly whether the account
exists — register and reset already did.

**Wordfence is the brute-force control, not our throttles.** Be precise about
what each one does, because they are not the same kind of thing:

- Register (5/hour) and reset (5/hour) run *before* the action, on
  `registration_errors` and `lostpassword_post`, so they genuinely stop the
  signup or the reset e-mail.
- The sign-in counter (8/10 min) runs on `wp_login_failed`, which fires
  **after** core has already checked the password. It changes the message a
  student sees; it never blocks a guess, and a script posting straight to
  `wp-login.php` does not trigger it at all. Treat it as UX, and leave
  real login hardening to Wordfence (installed by `setup.sh`).

If you ever want that counter to actually block, it has to move ahead of the
password check — and the honest answer is usually to let Wordfence or Limit
Login Attempts own it rather than hand-rolling around core's `authenticate`
chain, where returning a `WP_Error` early does not reliably stop core's own
authenticators from running afterwards.

### Hooks left open for the back end

Each form fires the standard core action inside the `<form>`, so security
plugins (Wordfence 2FA, captchas) keep working and custom fields have a
mount point:

- Sign-in form → `do_action( 'login_form' )`
- Register form → `do_action( 'register_form' )`
- Reset form → `do_action( 'lostpassword_form' )`

`inc/auth-flow.php` already uses these hooks for name/password-at-signup —
further fields (cohort, student ID) follow the same pattern: print inputs on
`register_form` (use the `.field` markup, it styles itself), validate on
`registration_errors`, persist on `user_register`.

Still open if you want them:

1. **Post-login routing** per role: filter `login_redirect`.
   The sign-in form already forwards `?redirect_to=` when it was given one,
   and defaults to `scholaris_progress_url()` (`/progress/`).
2. **E-mail templates**: `retrieve_password_message`,
   `wp_new_user_notification_email` if the default mails should match the
   brand voice.
3. **Welcome banner** on `/progress/?welcome=1` — the flag is set on first
   arrival after signup; render it in the plugin's dashboard template or via
   `scholaris_student_registered`.

---

## Design system — the 60-second version

Identity: **modern academia** — ink, warm paper, laurel green, bronze.

- Fonts: **Fraunces** (display serif), **Inter** (UI), **Spline Sans Mono**
  (meta labels, badges, figures, table headers).
- Tokens live at the top of
  `wp-content/themes/scholaris/assets/css/main.css` as HSL triplets
  (`--brand`, `--accent`, `--ink`, `--bg`, …) with a full
  `[data-theme="dark"]` set. Use them as `hsl(var(--token) / alpha)`.
- Component classes to reuse (all in `main.css`):
  `.btn --primary/--ghost/--accent/--quiet/--sm/--lg/--block`,
  `.badge --brand/--success/--warning/--danger`, `.card` (+ `__media`,
  `__body`, `__foot`, `card__media--doc` + `.doc-stamp`), `.stat` (ledger
  tile), `table.data`, `.meter`, `.notice --error/--success/--info`,
  `.eyebrow`, `.cta-band`, `.empty-state`, and the auth set
  (`.auth`, `.auth__side`, `.auth__card`, `.auth__row`, `.check`).
- The two plugins carry their own prefixed copies with fallbacks
  (`library.css`, `chat.css`) so they survive on foreign themes; when the
  theme is present they inherit its tokens automatically.
- `design/preview.html` mirrors the component CSS **by hand** — if you
  change a component in `main.css`, mirror it there so the preview stays
  truthful. (Agent definitions, by contrast, are synced automatically by
  `scripts/sync-agents.pl`.) Edit **`design/preview.html`** only, then run
  `perl scripts/sync-preview.pl` to push it into the working `preview.html`
  (its local API key survives the sync). `perl scripts/contract-tests.pl`
  guards the pair: markdown-rule parity with `to_html()`, both copies in
  sync, and the shippable copy staying keyless.

---

## Back-end checklist

- [x] Password-at-signup registration, throttling, auto sign-in
      (`inc/auth-flow.php`).
- [ ] Run `scripts/setup.sh` (creates pages incl. auth, menu, roles, options;
      also verifies the plugin database tables at the end).
- [ ] Put the model API key in `wp-config.php` (`EDUAI_GROQ_API_KEY`,
      `EDUAI_ZAI_API_KEY` or `EDUAI_ANTHROPIC_API_KEY`), or set the matching
      `*_API_KEY` environment variable. There is no settings field for it.
- [ ] SMTP: install a mailer plugin or configure the host so the reset and
      confirmation e-mails actually deliver.
- [x] Proxy/CDN client IPs: the limits key on
      `scholaris_authflow_client_ip()`, which honours one explicitly named
      forwarded header (`SCHOLARIS_CLIENT_IP_HEADER` or the
      `scholaris_client_ip_header` filter) and falls back to `REMOTE_ADDR`.
      Configure per host — see `docs/03-hosting-deployment.md` (wp-config
      hardening step), and match Wordfence's IP-source setting.
- [ ] Optional: further registration fields, per-role `login_redirect`,
      welcome banner on `?welcome=1` (see above).
- [ ] Confirm wps-hide-login's chosen slug and that `/sign-in/` still posts
      correctly (it will — the action URL is filtered).
- [ ] **Check whether Tutor LMS registers students via `register_new_user()`.**
      `scholaris_authflow_register_validate()` is hooked to `registration_errors`
      unconditionally, so it applies to *every* path that fires it — while
      `scholaris_authflow_enrich_user()` deliberately tolerates other paths.
      The native wp-login screen is fine (our `register_form` hook injects the
      fields there too), but a plugin with its own signup form would be rejected
      for a missing `sl_pass1` and shown "Please choose a password" on a form
      that never asked for one. Only reachable through `register_new_user()`;
      `wp_insert_user()` (wp-admin, WP-CLI) does not fire this filter.

      **The obvious fix is a trap.** Guarding the whole function on "our fields
      are present" lets an attacker skip the honeypot *and* the per-IP signup
      limit by simply omitting `sl_website` and `sl_pass1` from the POST. The
      honeypot and the rate limit must stay unconditional; only the name and
      password requirements may be gated on our fields having been rendered.

---

## PrepareME API

**The wire contract is `docs/07-prepareme-contract.md`** — pinned; build
against that, not this section. Model-side schema: `docs/06-eduai-rebuild.md`
§5. Back-end facts 07 doesn't cover:

- `GET /eduai/v1/exam/0` serves the committed sample
  (`fixtures/exam-sample.json`) through the real projection with
  `attempted: false` — build the whole UI against it before generation runs.
- The generation bucket is **weighted**: `exam_limit` (default 4/hour) is
  spent in units of `count / 10`, so a 20-question paper costs 2 units.
- The dedupe key hashes extracted text **and** question count — same lecture
  at a different length is a fresh generation; `regenerate=1` bypasses reuse.
- Foreign and missing exams both answer an identical `403` (probing-safe).

**AiCalc's wire contract is also in `docs/07-prepareme-contract.md` (§7)** —
`POST /eduai/v1/calc` with `input` (string, ≤2000 chars). Pure arithmetic is
answered exactly in code (`method: "exact"`, with `steps`) before the rate
limit is even consulted; anything symbolic or worded goes to the model under
the shared chat bucket. The split is visible in `method` — render "computed
exactly" only for the exact path, it is a stronger claim than a model answer.

<details><summary>Superseded draft (kept for history, do not build against)</summary>

### PrepareME API (frozen contract)

Backing for the `/prepare/` tab — docs/06 §2.4 has the rationale; these are
the exact shapes. All three routes sit behind `can_use()`; generation has its
own tight per-user bucket (default **5/hour**, `exam_rate_limit` setting)
separate from the shared chat bucket; submit uses the shared one.

**`POST /eduai/v1/exam`** — multipart `file` (PDF/PPTX/DOCX/TXT/MD, ≤20 MB,
same rules as `/summarize`) *or* `text` (≥80 chars). Optional `count`
(5–20, default 10), `regenerate` (bool). Identical material from the same
user returns the stored exam with `"reused": true` and costs nothing —
`regenerate=1` forces a fresh one.

```jsonc
{ "id": 7, "title": "…", "reused": false,
  "questions": [   // REDACTED — no answer_index, expected or explanation
    { "id": 1, "band": "easy", "type": "mcq", "question": "…",
      "options": ["…","…","…","…"], "marks": 1 },
    { "id": 2, "band": "medium", "type": "short", "question": "…", "marks": 2 } ] }
```

**`POST /eduai/v1/exam/<id>/submit`** — body `{ "answers": [ { "id": 1,
"answer": 2 }, { "id": 2, "answer": "text…" } ] }` (MCQ: option index int;
short: string, ≤1200 chars kept). Unanswered questions may be omitted — they
score 0. Response:

```jsonc
{ "attempt_id": 12, "score": 7.5, "total": 11,
  "results": [
    { "id": 1, "type": "mcq", "band": "easy", "awarded": 1, "of": 1,
      "correct": true, "your_index": 2, "answer_index": 2, "explanation": "…" },
    { "id": 2, "type": "short", "band": "medium", "awarded": 1.5, "of": 2,
      "comment": "…", "expected": "…" } ] }
```

**`GET /eduai/v1/exam/<id>`** — the exam (questions still redacted) plus
`attempts: [ { id, score, total, results, created_at } ]`, newest first,
where `results` carries the full reveal exactly as submit returned it.

Notes for the form: MCQ marking is deterministic PHP (index comparison), so
`correct` is authoritative the instant the response lands. Short answers are
marked by the model in one batched call — a marking failure returns an
honest 502 **and stores nothing**, so keep the student's answers client-side
until an `attempt_id` comes back. Both 404s (missing exam, someone else's
exam) are identical on purpose. Errors are standard `{ code, message }` WP
shapes: `eduai_exam_rate` (429), `eduai_exam_json` / `eduai_exam_invalid` /
`eduai_exam_grading` (502, retryable), upload errors as on `/summarize`.

</details>

---

## Scoping a tool to one lecture — `?source=`

One parameter, `?source=<post_id>`, points the assistant at a single piece of
material. It works on both `study_material` and Tutor `lesson`; the server
reads the post type and applies that type's own access rule, so scoping a
tool can never become a way to read something the visitor could not open
directly.

Both bundles gain a `scope` key:

```js
EduAIConfig.scope     // null  |  { id: 139, title: "Loop of Henle" }
EduAIPrepConfig.scope
```

**Take the title from there and nowhere else.** It is read from the post
server-side and tag-stripped. A title carried in the query string would be
attacker-supplied text rendering into the page.

Send it back on `POST /eduai/v1/chat` as `source` (integer, default `0`). The
server re-resolves and re-gates the id on arrival — the localized copy is a UI
hint, never authority — so a stale value costs you an unscoped answer, not an
error.

**`null` means two different things and you cannot distinguish them:** no
`?source=` present, and a `?source=` naming something this visitor may not
read. That is deliberate. Telling them apart would answer "does post 47 exist
and is it closed" for anyone who asks. So there is no "you don't have access
to that" state to render, and reconstructing one from a probe would rebuild
exactly the oracle this avoids.

While scoped, retrieval is constrained to that one source and the assistant
will **not** fall back to general knowledge — it answers from that lecture or
says what the lecture does not cover. If the UI shows a "scoped to X" chip,
that is the behaviour it is promising.

Scopable types are `study_material` and `lesson`. A page or post resolves to
`null` even though anyone may read it: readable and scopable are separate
questions, and the allowlist deliberately does not answer the first one.

---

## Verifying on a machine with no PHP

**CI enforces all of this on every pull request**, not just on push to `main`,
and pull requests stop at the checks without reaching the deploy step. So these
are a merge gate now rather than a habit. Run them locally anyway — the loop is
a second here and minutes there. The dev workstation has no PHP or Node of its
own (Docker arrived later — see *On a live stack* below), so before pushing:

- `perl scripts/php-sanity.pl` — brace/string/heredoc smoke test over every
  theme and plugin PHP file (CI still runs the real `php -l` on push).
- `perl scripts/contract-tests.pl` — cross-file contract checks (tester-owned):
  every auth flag the back end emits has a notice message and a row in this
  document (`auth-flag-coverage`), model tiers in `EduAI_Claude::providers()`
  match both HTML pages (`provider-model-parity`), agent prompts/preview
  copies in sync, and more. Run it after touching either side of the seam.
  In CI **one check skips**: `preview-copies-in-sync`
  compares against the gitignored `preview.html`, which is not in the
  repository. The skip is printed on its own line rather than counted as a
  pass — a check that quietly compares nothing is worse than no check.
- `perl scripts/check-no-secrets.pl` — no live API keys in anything shippable.
  Runs **first** in CI: a leaked key is the one failure a later commit cannot
  undo.
- `bash -n scripts/setup.sh` — shell syntax.
- `node scripts/check-inline-js.js` — syntax-checks the inline JavaScript in
  `design/preview.html` and `tools/agent-test.html`. The CI JavaScript step
  only globs `wp-content/**/*.js`, so ~2,000 lines of inline script in the two
  HTML pages were previously unchecked. Needs Node, so it runs in CI only —
  nothing on this workstation can run it.
- Open `design/preview.html` and walk the auth screens; markup and class
  names mirror `page-templates/auth-*.php` one-to-one.

## On a live stack

Some things are only true of a running site, and the checks above cannot reach
them. Since Docker landed, the stack runs locally: WordPress on
<http://localhost:8080>, Mailpit on <http://localhost:8025>.

- `docker compose --profile tools run --rm cli wp eval-file /scripts/grade-adversarial.php --allow-root`
  — the four grading rules in `docs/06-eduai-rebuild.md` §5.2, tested
  adversarially against the real marking path: `awarded` clamped to
  `[0, marks]`, the exam winning when the model's `of` disagrees, a `short`
  missing from the grade scoring 0 and reported as ungraded, and a
  hallucinated `id` minting no marks. The crafted grade is injected at
  `pre_http_request`, so no request leaves the container and no API credit is
  spent, while `EduAI_Claude::message`, the JSON extraction, the schema
  validation and `EduAI_Exams::grade` all run for real — the only faked
  component is the model's reply, which is the one under suspicion.

  **This is not a CI check and cannot become one.** It needs a running
  WordPress; CI has PHP but no install. It is deliberately unwired, in the same
  category as `php-sanity.pl`, and it only protects anyone if a human runs it
  after touching the marking path. Do not record it anywhere as automated
  coverage: the grading rules are guarded by this harness and by nothing else.

  Its assertions are quoted from §5.2 itself rather than from what the code
  currently returns. That distinction is the whole point — `calc-cases.json`
  was generated from the implementation it tested and passed 31/31 while the
  `-3^2` convention in it was wrong.

### Fixtures these harnesses leave behind

They are idempotent — re-running reuses what exists rather than piling up — but
they do create real rows on whatever stack they run against. Recorded here
because an undocumented disposable account is worse than a documented permanent
one: the next person to audit the user list should find an explanation rather
than a mystery.

| Fixture | Created by | Why it exists |
|---|---|---|
| `gate-student-a`, `gate-student-b` | `download-gate.php` | Two separate students are the only way to test whether a download URL copied from one works for the other — that is a cookie-level property |
| `rl-student-a`, `rl-student-b` | `rate-limit.php` | One exhausts a quota, the other proves the bucket is not shared |
| `rt-student` | `roundtrip.php` | Owns the generated exams |
| `ui-admin` (**administrator**) | admin-bar geometry check | The only fixture with elevated rights. Every other harness runs as a student or logged out, which is why an admin-only rendering bug survived all of them |
| Two `study_material` posts, `gate-members` / `gate-public` | `download-gate.php` | One of each access level; the public one proves the handler is not simply refusing everyone |
| `scripts/roundtrip-deck.pptx` | `make-lecture-fixture.php` | Gitignored and rebuilt in one command. **Not** committed as a binary: its traps are the point and a zip cannot be reviewed |

**About the ~20 `exam` rows attributed to user 0.** They are real, they are
mine, and they are deliberately left in place. `EduAI_Exams::generate()` once
returned no `user_id` while `grade()` read one, so composing the two filed
attempts against nobody — silent with `WP_DEBUG` off. The fix landed; these
rows are what the bug cost, kept as evidence it was worth fixing. If anyone
later wonders why user 0 has an exam history, this is the answer.

The first real-WordPress run happens on Docker (any machine that has it) or
straight on the host: `setup.sh` finishes by asserting the database state,
and its printed next-steps walk the register → dashboard → role check path.
