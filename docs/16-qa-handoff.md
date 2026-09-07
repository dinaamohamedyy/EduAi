# QA handoff — automated browser tests against a local EduAi

For a QA engineer who knows WordPress and has never seen this project.

## How to read this file

Every factual claim is marked one of two ways. They are never blurred.

- **READ** — the file was opened and the line seen. A path and line number
  follows. Line numbers are as of 7 Sep 2026.
- **ASSUMED** — worked out from what was read, not seen stated. Treat as a
  hypothesis to confirm against a running site.

`___` means it could not be found in the code. Each one names the file that
would answer it.

This file describes **what the code does**. It does not describe what it should
do. Where the two might differ, it says so and stops. Intent is Dina's to state.

**Do not skip section 5.** It lists what is already known broken. Reporting
those back costs everyone time.

---

## 1. Every address

> Covered already: `docs/05-frontend-handoff.md` "Page inventory" lists the
> routes and their templates, and "How the auth pages are wired" covers the
> three auth forms and their POST targets. **Not repeated here.** Below is what
> that table does not carry: access per role, the REST routes, the shortcode
> placement, and what has changed since it was written.

### Changed since `docs/05`

That doc describes a **seven-tab nav** (Home · Library · Summarise · AiCalc ·
Q&A · PrepareME · My Progress). That is no longer what ships.

- The primary menu is built from exactly 4 slugs: `home`, `library`,
  `progress`, `profile`. **READ** — `scripts/setup.sh:362`.
- The pages for `summarise`, `calc`, `ask` and `prepare` are still created and
  still resolve. **READ** — `scripts/setup.sh:259-261`.
- `setup.sh:356` says those 4 pages "redirect (inc/retired-routes.php)".
  **They do not.** `inc/retired-routes.php` is not loaded. **READ** —
  `wp-content/themes/scholaris/functions.php:224` states it is deliberately not
  loaded, and no `require` for it exists in the theme.
- So the 4 tool pages are reachable by URL but absent from the nav. **ASSUMED**
  — follows from the two READs above.

There is also a second, contradicting nav. `scholaris_fallback_menu()` still
enumerates 6 slugs including the 4 retired ones. **READ** —
`wp-content/themes/scholaris/functions.php:144-151`. It renders only when no
menu is assigned to the `primary` location. **ASSUMED** — that is the standard
`wp_nav_menu` fallback contract; the call site was not read.

### Pages

Access columns: **out** = signed out, **stu** = signed in as `student`,
**adm** = administrator.

| Path | Shows | out | stu | adm | Evidence |
|---|---|---|---|---|---|
| `/` | Landing page signed out; student dashboard signed in | yes | yes | yes | **READ** `themes/scholaris/front-page.php:22` branches on `is_user_logged_in()` |
| `/library/` | `[scholaris_library]` grid | yes | yes | yes | **READ** `scripts/setup.sh:250` |
| `/material/<slug>/` | Single document | ___ | ___ | ___ | Page itself has no gate that was found; the **download** is gated (§3). Would need `plugins/scholaris-library/includes/class-sl-templates.php` read in full to state the page-level rule. |
| `/progress/` | `[scholaris_dashboard]` | **notice, not content** | yes | yes | **READ** `plugins/scholaris-library/includes/class-sl-quiz-history.php:254-261` returns a "Sign in to open your dashboard" notice when signed out |
| `/profile/` | Account screen | **notice** | yes | yes + extra block | **READ** `themes/scholaris/page-templates/profile.php:34` gates on `is_user_logged_in()`; **READ** `:178` shows an extra block to `current_user_can('list_users')` |
| `/assistant/` | `[eduai_panel height="600"]` | page renders, calls 401 | yes | yes | **READ** `scripts/setup.sh:252` |
| `/summarise/` | `[eduai_summarizer]` | page renders, calls 401 | yes | yes | **READ** `scripts/setup.sh:253` |
| `/ask/` | `[eduai_panel tabs="chat" page="1"]` | page renders, calls 401 | yes | yes | **READ** `scripts/setup.sh:259` |
| `/calc/` | `[eduai_calc]` | page renders, calls 401 | yes | yes | **READ** `scripts/setup.sh:260` |
| `/prepare/` | `[eduai_prepare]` | page renders, calls 401 | yes | yes | **READ** `scripts/setup.sh:261` |
| `/sign-in/`, `/register/`, `/reset-password/` | Auth screens | yes | redirected away | redirected away | **READ** `scripts/setup.sh:302-304`; redirect behaviour is stated in `docs/05` and was not re-read |
| `/privacy/` | Static copy | yes | yes | yes | **READ** `scripts/setup.sh:254` |

"page renders, calls 401" is **ASSUMED**: the page is a normal WordPress page
with no gate found, while the REST routes behind it refuse (§3). The visible
result of that combination was not observed on a running site.

### REST routes

Namespace `eduai/v1`. **READ** — `plugins/eduai-assistant/includes/class-eduai-rest.php:15`.

| Route | Method | Permission | Evidence |
|---|---|---|---|
| `/eduai/v1/chat` | POST | `can_use` | **READ** `class-eduai-rest.php:22` |
| `/eduai/v1/summarize` | POST | `can_use` | **READ** `:58` |
| `/eduai/v1/history` | GET | `can_use` | **READ** `:64` |
| `/eduai/v1/calc` | POST | `can_use` | **READ** `:74` |
| `/eduai/v1/exam` | POST | `can_use` | **READ** `:89` |
| `/eduai/v1/exam/<id>/submit` | POST | `can_use` | **READ** `:103` |
| `/eduai/v1/exam/<id>` | GET | `can_use` | **READ** `:112` |

`can_use` returns 401 `eduai_auth` when the `logged_in_only` setting is on and
the caller is signed out; otherwise true. It performs **no capability check** —
a student and an administrator are treated identically. **READ** — `:127-132`.

Namespace `eduai-enquiry/v1`. **READ** —
`plugins/eduai-enquiry/includes/class-enquiry-rest.php:44`.

| Route | Method | Permission | Evidence |
|---|---|---|---|
| `/eduai-enquiry/v1/chat` | POST | `__return_true` | **READ** `class-enquiry-rest.php:76-83` |
| `/eduai-enquiry/v1/recommend` | POST | `__return_true` | **READ** `:91-98` |
| `/eduai-enquiry/v1/lead` | POST | `__return_true` | **READ** `:101-109` |

**These 3 are open to anyone, signed out, with no nonce.** That is deliberate —
the file's header comment says a nonce on a cached page is a nonce that expired
before anyone read it. **READ** — `:15`. They are defended by IP rate limits
instead (§3).

### Shortcodes

| Shortcode | Registered at | Placed on |
|---|---|---|
| `[eduai_panel]` | **READ** `class-eduai-shortcodes.php:17` | `/assistant/`, `/ask/` — **READ** `setup.sh:252,259` |
| `[eduai_summarizer]` | **READ** `:18` | `/summarise/` — **READ** `setup.sh:253` |
| `[eduai_calc]` | **READ** `:19` | `/calc/` — **READ** `setup.sh:260` |
| `[eduai_prepare]` | **READ** `:20` | `/prepare/` — **READ** `setup.sh:261` |
| `[eduai_progress]` | **READ** `:21` | ___ — no page in `setup.sh` carries it |
| `[eduai_enquiry]` | **READ** `class-enquiry-widget.php:36` | ___ — `setup.sh` never places it (0 matches). Whether it auto-injects was not established; `class-enquiry-widget.php` hooks only `wp_enqueue_scripts` (**READ** `:35`), so a `wp_footer` injection was not found |
| `[scholaris_library]` | **READ** `class-sl-library.php:16` | `/library/` |
| `[scholaris_quiz_history]` | **READ** `class-sl-quiz-history.php:20` | ___ — conditionally on `/` — **READ** `front-page.php:193` |
| `[scholaris_dashboard]` | **READ** `class-sl-quiz-history.php:21` | `/progress/` |

### wp-admin screens

| Screen | Capability | Evidence |
|---|---|---|
| Settings → EduAI Assistant (`options-general.php?page=eduai`) | `manage_options` | **READ** `class-eduai-settings.php:291-296` |
| Settings → EduAI Enquiry (`?page=eduai-enquiry`) | `manage_options` | **READ** `class-enquiry-admin.php:35-41` |
| EduAi console (top-level, `?page=eduai-console`) | `edit_posts` | **READ** `class-sl-console.php:120-124`, slug **READ** `:24` |

The console is `edit_posts`, not `manage_options`. An Editor reaches it.
**ASSUMED** — that is what `edit_posts` means; no test was run.

**Note for anything that scripts wp-admin:** `docs/05` says wps-hide-login makes
`wp-login.php` return 404 and `/wp-admin/` redirect to `/404/`. Not re-verified
here. A test that hard-codes those paths will fail in a way that reads like
broken authentication.

---

## 2. The journeys

> Covered already: `docs/05-frontend-handoff.md` gives the auth form fields and
> POST targets, and its "Notice flags" table lists every `?login=` / `?register=`
> / `?lostpw=` value. **Not repeated.** `docs/07-prepareme-contract.md` gives
> the full PrepareME and AiCalc wire shapes. **Not repeated.**

Every step below is **ASSUMED** unless it carries its own evidence. They are
assembled from routes and templates read separately, not from walking the site.
Nobody walked these journeys to write this file.

### Register a new student

1. `GET /register/` — form with Full name, e-mail, password, repeat password.
   **READ** — fields injected on the `register_form` hook, per `docs/05`.
2. Submit posts to `wp-login.php?action=register` with `sl_from=register`.
   **READ** — `docs/05` "How the auth pages are wired".
3. On success: signed in automatically, redirected to `/progress/?welcome=1`.
   **READ** — `docs/05`.
4. On screen at `/progress/`: the dashboard. **Nothing renders the `welcome`
   flag** — `docs/05` says so explicitly. Do not assert a welcome banner.
5. Role is `student`, cloned from subscriber and set as `default_role`.
   **READ** — `docs/05` "Page inventory" trailing paragraph.

### Sign in

1. `GET /sign-in/`.
2. Posts to `site_url('wp-login.php','login_post')` — **not** `wp_login_url()`,
   which resolves to `/sign-in/` itself. **READ** — `docs/05` states posting
   credentials to the latter re-renders the page with a 200 and nobody signed
   in. **This is the single most likely way to write a false-passing login
   test.**
3. Success redirects to `/progress/`. **READ** — `docs/05`.
4. Failure returns to `/sign-in/?login=failed` (wrong password) or
   `?login=nouser` (no such account). **READ** — `docs/05` notice table.

### Find a lecture in the library

1. `GET /library/`.
2. Grid of `study_material` posts, 12 per page, filters on. **READ** —
   `class-sl-library.php:84-90` (`per_page` 12, `filters` yes, `columns` 3).
3. Filter by `material_subject` or `material_type` taxonomy. **READ** —
   `class-sl-post-types.php:199,212`.
4. Click through to `/material/<slug>/`.

### Download a gated PDF

1. On `/material/<slug>/`, the download link is
   `?sl_download=<post_id>&_wpnonce=<nonce>`. **READ** —
   `class-sl-library.php:147-148`, nonce action `sl_download_<post_id>`.
2. The handler checks, in this order — **READ** `class-sl-library.php:157-192`:
   1. nonce valid, else **403**;
   2. post type is `study_material` and status `publish`, else **404**;
   3. `SL_Meta::can_download()`, else **redirect to the login page**;
   4. `SL_Private::assert_secured()`, else **500**;
   5. file exists on disk, else **404**.
3. Success streams the file with `Content-Disposition: inline`. **READ** —
   `:201`. It is **inline, not attachment** — the browser displays rather than
   saves. A test asserting a download dialog will fail.
4. A download increments `_scholaris_downloads` post meta. **READ** — `:195`.

### Take a quiz

`___`. Quiz-sitting is Tutor LMS's own flow, and **Tutor is not installed** in
this tree — `wp-content/plugins/` contains only `eduai-assistant`,
`eduai-enquiry` and `scholaris-library`. **READ** — directory listing.

To document this journey, someone must say which LMS the test environment
actually runs. See §5.

### See your progress

1. `GET /progress/`.
2. Signed out: a "Sign in to open your dashboard" notice, not the dashboard.
   **READ** — `class-sl-quiz-history.php:254-261`.
3. Signed in: quiz attempts, **read from `wp_tutor_quiz_attempts`**. **READ** —
   `class-sl-quiz-history.php:35`.
4. If that table does not exist, the code detects it and returns `''` rather
   than erroring. **READ** — `:38-41`. What the page then shows in its place is
   `___` — would need the render path in the same file read in full.
5. PrepareME results appear via a different source: `EduAI_Exams::stats_for_user()`
   and `history_for_user()`. **READ** — `themes/scholaris/inc/dashboard.php:240-249`.
   So exam history and quiz history are two independent systems on one page.

### The 4 AI tools

All 4 pages render signed out and all 4 REST calls refuse signed out (§1).

| Tool | Page | Route | Wire contract |
|---|---|---|---|
| Summarise | `/summarise/` | `POST /eduai/v1/summarize` | `docs/07` §8 |
| Q&A | `/ask/` | `POST /eduai/v1/chat` | ___ — no section found in `docs/07`; would need `class-eduai-rest.php:22-57` read in full |
| AiCalc | `/calc/` | `POST /eduai/v1/calc` | `docs/07` §7 |
| PrepareME | `/prepare/` | `POST /eduai/v1/exam` | `docs/07` §2-§5 |

**Scoping to one lesson.** `?source=<post_id>` on a tool page points it at a
single piece of material. Fully described in `docs/05` "Scoping a tool to one
lecture". Two facts a test must not get wrong, both from that section: `scope`
being `null` is indistinguishable between "no `?source=`" and "a `?source=` you
may not read", deliberately; and a scoped **Ask** legitimately returns passages
from outside the scoped lesson, because scope boosts rather than filters.

**`GET /eduai/v1/exam/0` serves a committed fixture** through the real
projection. **READ** — `docs/05` "PrepareME API". Build the report UI tests
against it before any generation runs.

---

## 3. Every rule the code enforces, with its number

> Covered already: `docs/07-prepareme-contract.md` §6 has the rate-limit table
> and §2 has the `count` allowlist. `docs/05` covers the auth throttles
> (5/hour register, 5/hour reset, 8/10min sign-in) and is explicit that the
> sign-in counter runs **after** the password check and therefore blocks
> nothing. **Not repeated.** Below: the values with file and line, plus what
> those docs do not carry.

### Assistant limits

| Rule | Value | Configurable | Evidence |
|---|---|---|---|
| Chat/calc/summarise rate limit | **20** per rolling hour | `rate_limit` setting | **READ** default `class-eduai-settings.php:53`; read at `class-eduai-rest.php:140` |
| Rate limit disabled | when `rate_limit` ≤ 0 | same | **READ** `class-eduai-rest.php:141-143` |
| Rate-limit key | user id, or IP hash when anonymous | — | **READ** `class-eduai-rest.php:145-147` |
| Exam generation limit | **4** units per rolling hour | `exam_limit` setting | **READ** `class-eduai-rest.php:176` |
| Exam generation cost | `count / 10` units | — | **READ** `class-eduai-rest.php:392` |
| Max tokens | **2000** | `max_tokens` | **READ** `class-eduai-settings.php:35` |
| Temperature | **0.2** | `temperature` | **READ** `:36` |
| Retrieved passages | **6** | `context_chunks` | **READ** `:54` |
| Signed-in only | **true** | `logged_in_only` | **READ** `:52` |
| Chat log retention | **180** days | `retention_days` | **READ** `:57` |
| Default provider | `groq` | `provider` | **READ** `:26` |

**`exam_limit` is not in `defaults()`.** It is read with an inline fallback of
`4` at `class-eduai-rest.php:176` and appears nowhere in
`class-eduai-settings.php:20-58`. **READ** — both. Consequence: it is not
listed among the defaults, so `EduAI_Settings::get('exam_limit')` with no
explicit fallback would return `null`. Whether a settings-screen field exists
for it is `___` — would need `class-eduai-settings.php` render path read.

### Uploads (Summarise and PrepareME, identical)

| Rule | Value | Evidence |
|---|---|---|
| Max file size | **20 MB** | **READ** `class-eduai-rest.php:241` (exam), `:893` (summarize) |
| Accepted extensions | `pdf`, `pptx`, `docx`, `txt`, `md` | **READ** `:244-252` |
| Rejected with a specific message | `ppt`, `doc` | **READ** `:260-266` |
| Minimum extracted text | **40** characters | **READ** `:279` |
| Minimum pasted text, exam | **___** — the message says "a couple of paragraphs" but the numeric threshold was not located | `class-eduai-rest.php` around `:339` |
| Minimum pasted text, summarize | **80** characters per `docs/07` §8; not confirmed in code here | — |
| Text sent to the model, cap | **220000** characters | **READ** `class-eduai-rest.php:1295` |

`docs/07` §2 says pasted text for an exam has a **200** character minimum. That
number was **not found** in the code during this read. Treat the doc as
unconfirmed until someone reads the `eduai_short` branch at
`class-eduai-rest.php:339`.

### Exam rules

| Rule | Value | Evidence |
|---|---|---|
| Question count allowlist | **5, 10, 20** | **READ** message at `class-eduai-rest.php:367`; `docs/07` §2 |
| Max answers per submit | **100** | `docs/07` §3 — not re-confirmed in code |
| Own exams only | 403 for another user's **and** for a non-existent id, identically | **READ** `class-eduai-rest.php:210,462` |

### AiCalc

| Rule | Value | Evidence |
|---|---|---|
| Input length | 1 to **2000** characters | **READ** `class-eduai-rest.php:83` |
| Exact path cost | none — bypasses the rate limit | `docs/07` §7 |

### Downloads

| Rule | Value | Evidence |
|---|---|---|
| Access levels | `public` or `members` only | **READ** `class-sl-meta.php:946-952` |
| Default when unset | `members` | **READ** `:946` |
| `members` means | **any** signed-in account | **READ** `:952` — `return is_user_logged_in();` |
| Nonce action | `sl_download_<post_id>` | **READ** `class-sl-library.php:148,165` |

**There is no per-student, per-cohort or per-course restriction.** The README
says this too, and it is worth repeating because it is the single most
over-read behaviour in the product: a document marked `members` is readable by
every registered account on the site.

### Enquiry plugin (public, unauthenticated)

| Rule | Value | Evidence |
|---|---|---|
| Chat limit | **20** per window | **READ** `class-enquiry-rest.php:51` |
| Lead limit | **5** per window | **READ** `:56` |
| Window | **5 minutes** | **READ** `:58` |
| Max message characters | **1000** | **READ** `:63` |
| Limit key | `REMOTE_ADDR` + nonce salt | **READ** `:379-385` |

The key is `REMOTE_ADDR` directly, with no forwarded-header handling. **READ** —
`:383`. `docs/05` records that the *auth* throttles honour
`SCHOLARIS_CLIENT_IP_HEADER`; this one does not. **ASSUMED consequence:** behind
a proxy or CDN, all enquiry traffic shares one bucket. Not tested.

---

## 4. What breaks it

Every message below is quoted verbatim from the source, without its
`__( … , 'eduai' )` wrapper.

### No API key

All 4 AI tools, one shared failure. **READ** —
`class-eduai-claude.php:201-211`. HTTP **500**, code `eduai_no_key`:

> No %1$s API key is configured. Add one under Settings → EduAI Assistant, or get one at %2$s.

`%1$s` is the provider label, `%2$s` its console URL. **READ** — `:206-207`.

**This message is misleading and QA should not treat it as a bug.** It tells
the reader to add a key under Settings, and there is no field there — the key
is `wp-config.php` or environment only, by design. `docs/05` back-end checklist
and the README both state there is no settings field. Flagged here so it is not
re-reported; whether the wording changes is Dina's call.

### Signed out

Every `eduai/v1` route. HTTP **401**, code `eduai_auth`. **READ** —
`class-eduai-rest.php:129`:

> Please sign in to use the assistant.

The enquiry routes do **not** refuse — they are `__return_true` (§1).

### Rate limit reached

Chat, calc, summarise. HTTP **429**, code `eduai_rate`. **READ** —
`class-eduai-rest.php` (code list at `:155` region):

> You have reached the hourly message limit. Try again a little later.

Exam generation. HTTP **429**, code `eduai_exam_rate`:

> You have generated several exams this hour already. Re-open one of them, or try again a little later.

Enquiry chat and lead. HTTP **429**, code `eduai_eq_rate_limited`. **READ** —
`class-enquiry-rest.php:123,181,227`. Message text `___` — the strings sit on
lines not captured in this read.

### Upload too big

HTTP **413**, code `eduai_upload_big`. **READ** — `class-eduai-rest.php:241,893`:

> Files must be 20 MB or smaller.

### Wrong file type

HTTP **415**, code `eduai_upload_type`. **READ** — `:258`:

> Supported files are PDF, PPTX, DOCX, TXT and MD.

Legacy Office. HTTP **415**, code `eduai_upload_legacy`. **READ** — `:262-266`:

> That is the older binary Office format, which cannot be read here. Open it, use Save as to produce a .pptx or .docx, and upload that — or export it to PDF.

### File readable but empty of text

HTTP **422**, code `eduai_empty`. **READ** — `:281,940`. The message is built by
`self::empty_file_message( $ext )` and **varies by extension**, so it cannot be
quoted as one string. `___` — would need `empty_file_message()` read.

### Scoped lesson with no text

Summarise. HTTP **422**, code `eduai_scope_empty`:

> There is no readable text on that lecture yet. Attach a file or paste the part you need.

PrepareME. HTTP **422**, code `eduai_scope_thin`:

> There is not enough text in this lesson to build an exam from. Open the lecture it came from, or paste the part you want to be tested on.

### Not enough text

PrepareME. HTTP **400**, code `eduai_short`. **READ** — `:339`:

> Paste at least a couple of paragraphs of the lecture, or attach a file — an exam needs more source than that.

Summarise. HTTP **400**, code `eduai_short`. **READ** — `:1010`:

> Paste at least a paragraph of the lecture, or attach a file.

### Bad question count

HTTP **400**, code `eduai_exam_count`. **READ** — `:367`:

> Exams come in 5, 10 or 20 questions.

### Malformed submit body

HTTP **400**, code `eduai_exam_answers`. **READ** — `:424`:

> Send the answers as a list of { id, choice } and { id, text } entries.

### Somebody else's exam, or one that does not exist

HTTP **403**, code `eduai_exam_forbidden`, **identical for both**. **READ** —
`:210,462`:

> Only your own exams are visible.

### Empty knowledge index

**No error message exists for this.** **READ** —
`class-eduai-knowledge.php:860-890`: when the FULLTEXT query returns nothing,
the code falls back to a `LIKE` search on terms longer than 3 characters. What
happens when that is also empty is `___` — the branch past `:890` was not read.

**ASSUMED, and the most important thing in this section:** with an empty index
and `allow_general_knowledge` defaulting to `true`
(**READ** `class-eduai-settings.php:45`), Q&A will most likely answer from the
model's general knowledge rather than refusing. A test asserting "no material,
therefore no answer" would then fail — and a test asserting "an answer came
back" would pass while retrieval is completely broken. **Assert on citations,
not on the presence of an answer.**

### Library, signed out

The gated download does not error. It **redirects to the login page**. **READ** —
`class-sl-library.php:175-178`. A test following redirects sees a 200 on
`/sign-in/`, not a 403.

### Expired or wrong download nonce

HTTP **403** via `wp_die`. **READ** — `class-sl-library.php:166`:

> That download link has expired. Reload the page and try again.

### File missing from disk

HTTP **404** via `wp_die`. **READ** — `:191`:

> The file is missing from the media library.

### Material not published, or not a study_material

HTTP **404** via `wp_die`. **READ** — `:170`:

> Document not found.

---

## 5. Known broken or unfinished

Do not report these.

1. **No LMS is installed in this tree.** `wp-content/plugins/` holds only
   `eduai-assistant`, `eduai-enquiry`, `scholaris-library`. **READ** —
   directory listing. `docs/15-tm-to-gm-handover.md` records that LearnDash was
   installed through wp-admin into the container volume, so it is live on the
   running site and invisible to the repository. **Confirm which LMS your test
   environment has before writing any course, lesson or quiz test.**

2. **The dashboard reads a Tutor LMS table.**
   `class-sl-quiz-history.php:35` queries `wp_tutor_quiz_attempts`. With Tutor
   gone, the table check at `:38` returns empty and quiz history has no source.
   **READ** — both lines. What the page renders instead is `___`.

3. **`setup.sh` documents a redirect that does not happen.** `setup.sh:356`
   says the 4 tool pages redirect via `inc/retired-routes.php`; that file is
   deliberately not loaded (`functions.php:224`). **READ** — both.

4. **Two navigation sources disagree.** The menu is 4 items
   (`setup.sh:362`); `scholaris_fallback_menu()` lists 6 including retired
   tabs (`functions.php:144-151`). **READ** — both.

5. **`exam_limit` is missing from `defaults()`.** §3 above.

6. **The no-key message points at a settings field that does not exist.** §4
   above.

7. **Open TODO on the material page.**
   `plugins/scholaris-library/templates/single-study_material.php:263` —
   "carry this document into the panel (`?doc=<id>`) once /ask/ reads it — a
   plain link loses the 'this document' preselection". **READ**. So clicking
   "Ask about this document" does **not** preselect that document.

8. **`[eduai_progress]` is registered but placed on no page.**
   **READ** — `class-eduai-shortcodes.php:21`; 0 matches in `setup.sh`.

9. **`[eduai_enquiry]` is placed on no page either**, and no auto-injection
   hook was found. **READ** — `class-enquiry-widget.php:35` registers only
   `wp_enqueue_scripts`. How the enquiry widget reaches a visitor is `___`.

10. **LearnDash styling has never been looked at.**
    `docs/15-tm-to-gm-handover.md` records that
    `themes/scholaris/assets/css/learndash.css` is enqueued and served but has
    not been opened in a browser by anyone. **READ**. Expect visual defects on
    lesson pages.

11. **The exam rows attributed to user 0.** `docs/05` records ~20 of them,
    left deliberately as evidence of a fixed bug. Not a defect.

12. **Fixture accounts exist on any stack the harnesses have run against** —
    `gate-student-a`, `gate-student-b`, `rl-student-a`, `rl-student-b`,
    `rt-student`, `ui-admin`. **READ** — `docs/05` "Fixtures these harnesses
    leave behind". `ui-admin` is an administrator.

---

## 6. DRAFT "must never happen"

**Every line below is `DRAFT, Dina to confirm`.** These are guesses at intent
read off the code's behaviour. Code doing something is not evidence that it is
meant to. Only Dina can confirm these, and until she does they are not
requirements and must not be turned into failing tests.

Ranked worst first.

1. A student must never receive an exam's `answer_index` before submitting that
   exam. — `DRAFT, Dina to confirm`. Basis: `docs/07` §1 states the projection
   exists for exactly this reason.
2. A student must never read another student's exam. — `DRAFT, Dina to
   confirm`. Basis: **READ** `class-eduai-rest.php:210,462`.
3. A student must never download a `members` document while signed out. —
   `DRAFT, Dina to confirm`. Basis: **READ** `class-sl-meta.php:946-952`.
4. A student must never reuse a download nonce minted for a different document.
   — `DRAFT, Dina to confirm`. Basis: **READ** `class-sl-library.php:165`,
   action is per post id.
5. A signed-out visitor must never spend model credit through `eduai/v1`. —
   `DRAFT, Dina to confirm`. Basis: **READ** `class-eduai-rest.php:128`.
   **Note this is contradicted by the enquiry plugin**, whose 3 routes are open
   to anyone (§1). Dina should say whether that is intended.
6. A student must never exceed 20 model calls in a rolling hour. — `DRAFT,
   Dina to confirm`. Basis: **READ** `class-eduai-settings.php:53`.
7. A student must never generate an exam of a size outside 5, 10 or 20. —
   `DRAFT, Dina to confirm`. Basis: **READ** `class-eduai-rest.php:367`.
8. A student must never upload a file larger than 20 MB to any AI tool. —
   `DRAFT, Dina to confirm`. Basis: **READ** `class-eduai-rest.php:241,893`.
9. A student must never see whether a given exam id exists. — `DRAFT, Dina to
   confirm`. Basis: **READ** the two identical 403s at `:210,462`.
10. A student must never reach the EduAi console at `?page=eduai-console`. —
    `DRAFT, Dina to confirm`. Basis: **READ** `class-sl-console.php:120-124`
    requires `edit_posts`, which a `student` cloned from subscriber does not
    have. **ASSUMED** — the student role's capability list was not read.

**One deliberate omission.** No line here says a student must never see another
student's *progress* or *marks*. The dashboard reads
`get_current_user_id()`-scoped data as far as was read, but no cross-user
access test was found in the code, and asserting the rule without having read
the query would be inventing it. If that rule matters — and it probably does —
it needs `class-sl-quiz-history.php:45-80` read line by line first.
