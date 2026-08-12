# EduAi admin console — implementation specification

**Status:** buildable spec. Every file/line citation below was read from the file in this pass, or measured against the running stack (`scholaris-wp`, `scholaris-db`, `scholaris-mail` — all up, Tutor LMS 4.0.4, dina = user 16, administrator). Where the survey could not establish something it is marked **UNVERIFIED** and never papered over.

**This session is read-only. Nothing below has been written.**

---

## 0. PRECONDITION — do this before writing any code

There are **0 `courses`, 0 `lesson`, 0 `topics`, 0 `tutor_quiz` posts** on this install (measured this pass via `wp_count_posts`). Requirement 2 ("add, edit, remove courses") is delivered entirely by linking into Tutor's React course builder, and **nobody has ever loaded that builder on this box.**

Before section 5 is committed to: sign in as dina in a real browser, open `/wp-admin/admin.php?page=create-course`, create a course with one topic and one lesson, save, and confirm by SQL that a `courses` row and a `topics` row exist. Ten minutes. If the builder does not render or does not save, section 5 has no answer in this spec and the whole courses requirement needs re-scoping — sections 1–4 and 6–8 are unaffected and can proceed regardless.

---

## 1. THE DECISION

**We are enhancing WordPress's own admin, not building a console application.** The new surface is one branded landing page (`admin.php?page=eduai-console`) that contains links, counts and no CRUD; three new fields on the study-material editor that already exists; and one new front-end handler. Every create/edit/delete verb is served by a screen that already works. This is the decision because the alternatives lose things that are expensive to rebuild and invisible when missing: `edit.php?post_type=study_material` already gives search, both taxonomy filter dropdowns (`show_admin_column => true`, `class-sl-post-types.php:52,65`), quick edit, bulk edit, trash-with-restore and per-page screen options; `study_material` declares `'revisions'` support (`class-sl-post-types.php:39`) and `classic-editor` is active, so the editor gives autosave, revision compare and restore today; `SL_Meta::save()` already gates on `current_user_can( 'edit_post', $post_id )` (`class-sl-meta.php:118`), which routes through `map_meta_cap` and honours whatever `user-role-editor` (active) did last week. A bespoke screen built from `admin-post.php` + `wp_ajax_` handlers receives none of that enforcement for free and must re-derive it — and re-derived permission checks are where WordPress plugins get CVEs. There is one further mechanical reason, verified in core this pass: `save_post_{$post_type}` fires at `wp-includes/post.php:5182` and `save_post` at `:5193`, so `SL_Meta::save()` (priority 10 on `save_post_study_material`) runs *before* `EduAI_Knowledge::on_save_post()` (priority 20 on `save_post`, `class-eduai-knowledge.php:29`). Material saved through the meta box is indexed for the assistant with the file already attached, with no extra call. A custom handler that inserts the post and then writes the meta gets the ordering backwards and silently indexes nothing.

**What we are explicitly not building, and why that is right.** No bespoke material CRUD screen or list table — it would be a second write path to the same record, and a guarantee enforced at one of two entry points is not a guarantee. No course builder, no lesson/topic/quiz editor: Tutor's builder is a React SPA over ~12 `wp_ajax_tutor_*` write actions, its REST namespace is GET-only, and reimplementing it means also reimplementing attempt recording, marking and the gradebook. No reusable cross-course question bank: `wp_tutor_quiz_questions.content_id` exists as a seam and no free-plugin PHP reads or writes it — Content Bank is Tutor **Pro**. No second login form and no check against the literal username `dina` or the literal password: WordPress's own login already satisfies requirement 4, a hardcoded credential pair in a repo six sessions share is an authentication bypass, and a design keyed to a username breaks the day a second lecturer is hired. **Separately: the password `abcd1234` is now written in plain text in the task brief that produced this spec. It should be rotated from `wp-admin/profile.php`. Nobody on this work has entered it anywhere and no code should ever contain it.** And no private-video streaming infrastructure in v1 — see section 3, which explains what that would cost and states the trigger for building it.

---

## 2. MATERIAL UPLOAD

Everything happens on the existing Classic editor at `post-new.php?post_type=study_material`. Title, description, excerpt, featured image, Subject, Material type, author, revisions, publish/schedule/trash are already there and are not touched.

### 2.1 Fields

| Box | Context | Field | `name` | Meta key | Status |
|---|---|---|---|---|---|
| **Document** (`sl_material_file`, `class-sl-meta.php:21-30`) | side / high | hidden attachment id + Choose/Remove | `sl_file_id` | `_scholaris_file_id` | exists (`:69`) |
| | | Pages (blank = auto-detect) | `sl_pages` | `_scholaris_pages` | exists (`:83`) |
| | | Lecturer | `sl_lecturer` | `_scholaris_lecturer` | exists (`:89`) |
| | | Who can open it (`public` / `members`) | `sl_access` | `_scholaris_access` | exists (`:94-97`), **new help text** |
| **Video** (`sl_material_video`) | side / default | radio: None / Link / Uploaded file | `sl_video_source` | `_scholaris_video_source` (`''\|link\|file`) | **new** |
| | | url input, shown for Link | `sl_video_url` | `_scholaris_video_url` | **new** |
| | | hidden attachment id + Choose/Remove, shown for File | `sl_video_id` | `_scholaris_video_id` | **new** |
| **Question bank** (`sl_material_bank`) | normal / low | repeatable MCQ rows (§4) | `sl_bank[i][...]` | `_scholaris_bank` (JSON) | **new** |
| | | hidden row count | `sl_bank_count` | — | **new**, see §2.5 |
| | | (internal) revision counter | — | `_scholaris_bank_rev` (int) | **new** |

All three boxes are in the same `post.php` form and are persisted by the **single existing** `SL_Meta::save()` under the **single existing** nonce `sl_material_nonce` (`class-sl-meta.php:59`, verified at `:109-113`). One write path is the point; do not add a second nonce.

### 2.2 The video link-or-upload mechanism

Both branches the owner chose, per material, selected by one radio.

**Uploaded file** uses a second `wp.media` frame in `assets/js/admin.js`, cloned from the existing one at `:15-22` with `library: { type: 'video' }`. This is deliberate and load-bearing: the media modal uploads through its own asynchronous request (`async-upload.php`), **not** through the post form. A 60 MB video therefore cannot take the title, the description and a hand-typed question bank down with it when it fails — only the attachment id rides in the post form. No staged-save machinery has to be invented; wp.media already is it.

No `upload_mimes` work is required. Measured this pass in the web container: **0 callbacks registered on `upload_mimes` site-wide**, and `get_allowed_mime_types()` already returns `mp4|m4v => video/mp4` and `webm => video/webm`. The document picker's mime list at `admin.js:18-20` is a *browse-list* filter on the Media Library tab only — it does not constrain the Upload Files tab, and it is not an enforcement mechanism. Do not cite it as one.

**Link** stores a URL and nothing else. It is the recommended default and the box says so, because it puts no bytes in `wp-content/uploads` (section 3), sidesteps the 64 MB ceiling entirely (section 7), and hands seeking, transcoding and bandwidth to YouTube/Vimeo.

### 2.3 Validation of a pasted URL — exact algorithm

In `SL_Meta::save()`:

```php
$raw  = isset( $_POST['sl_video_url'] ) ? trim( wp_unslash( $_POST['sl_video_url'] ) ) : '';
$url  = esc_url_raw( $raw, array( 'http', 'https' ) );
$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
$host = preg_replace( '/^www\./', '', $host );

$allowed = apply_filters( 'scholaris_video_hosts', array(
    'youtube.com', 'm.youtube.com', 'youtu.be', 'vimeo.com', 'player.vimeo.com',
) );

if ( ! $url || ! in_array( $host, $allowed, true ) ) { /* reject */ }
```

Rules, each with its reason:

1. **Compare the parsed host, never `strpos()` on the whole string.** `https://evil.com/?x=youtube.com` passes a substring test.
2. `esc_url()` alone is not sufficient. It blocks `javascript:` and `data:` by protocol whitelist but permits **any** http(s) host.
3. **A rejected URL is not saved. The previous value is kept** and an admin notice names the host that was refused. Silently blanking a field the owner just filled in is the worse failure.
4. Reject a YouTube path beginning `/embed/` or `/v/` with its own message: *"Paste the address from your browser's address bar, not the embed address."* `youtube.com/embed/…` is **not** a registered oEmbed provider, so it renders as a bare link with no explanation. (Established in survey 4 and reproduced in critique 3; **not re-run in this pass**.)
5. The allowlist is also what stops core's `embed_oembed_discover` (defaults true, confirmed at `wp-includes/class-wp-embed.php:305`) from making an **outbound HTTP fetch to any host an admin types** and parsing its `<link rel="alternate" type="application/json+oembed">`.

**Also fix, while in this file:** `class-sl-meta.php:122-123` is the entirety of existing file validation —

```php
$file_id = isset( $_POST['sl_file_id'] ) ? absint( wp_unslash( $_POST['sl_file_id'] ) ) : 0;
update_post_meta( $post_id, '_scholaris_file_id', $file_id );
```

— no check that the id is an attachment, that it exists, or what its mime is. Add: reject unless `'attachment' === get_post_type( $id )`. For `sl_video_id` additionally require `str_starts_with( (string) get_post_mime_type( $id ), 'video/' )`.

### 2.4 Rendering — and the bug that must be fixed with it

**`templates/single-study_material.php:76` is `if ( $sl_file_id && $sl_allowed )`, and `:106` is `elseif ( $sl_file_id && ! $sl_allowed )`.** A link-video material — the *recommended default* — has `_scholaris_file_id = 0`. Dropped naively into that block it renders a title, a description and nothing else. Dropped outside it, the access gate is gone. The template must be restructured, not spot-edited.

Add after `single-study_material.php:23`:

```php
$sl_video_src = (string) get_post_meta( $sl_id, '_scholaris_video_source', true );
$sl_video_url = (string) get_post_meta( $sl_id, '_scholaris_video_url', true );
$sl_video_id  = (int)    get_post_meta( $sl_id, '_scholaris_video_id', true );
$sl_has_video = ( 'link' === $sl_video_src && $sl_video_url )
             || ( 'file' === $sl_video_src && $sl_video_id );
$sl_has_media = $sl_file_id || $sl_has_video;
```

Then:

- `:76` becomes `if ( $sl_has_media && $sl_allowed ) :`
- immediately inside it, the **video block** (`if ( $sl_has_video )`), above the document viewer
- the existing `.sl-viewer` div, `:77-105` unchanged, wrapped in `if ( $sl_file_id )`
- `:106` becomes `elseif ( $sl_has_media && ! $sl_allowed ) :` — the sign-in notice at `:107-113` now also covers video-only material

Video block bodies:

```php
// Link — the CACHED oembed path.
echo $GLOBALS['wp_embed']->shortcode( array(), $sl_video_url );
```

**Use `WP_Embed::shortcode()`, not `wp_oembed_get()`.** Verified in core this pass: `wp_oembed_get()` (`wp-includes/embed.php:113-116`) is two lines straight to `WP_oEmbed::get_html()` with no cache of any kind — every page view would make a blocking request to YouTube or Vimeo. The cache lives in `WP_Embed::shortcode()`, which stores provider HTML in `_oembed_*` / `_oembed_time_*` post meta under an `oembed_ttl` filter (`class-wp-embed.php:236-315`) and takes its post id from `get_post()` at `:197`. The template is inside the loop (`the_post()` at `:12`), so that resolves correctly. `shortcode()` returns `maybe_make_link( $url )` — a plain anchor — when it cannot embed, which is the fallback for free. Its output is provider HTML: do **not** `esc_html()` it.

```php
// Uploaded file — deliberately the direct uploads URL. See section 3.
echo wp_video_shortcode( array( 'src' => wp_get_attachment_url( $sl_video_id ) ) );
```

### 2.5 The `max_input_vars` trap in the bank editor

Measured in the web container this pass: **`max_input_vars = 1000`**. `php/uploads.ini` does not set it, so PHP's default applies. Each bank row posts ~8 inputs; the classic editor posts its own. Past roughly 100 rows PHP truncates `$_POST` **with no error** and the tail of the bank vanishes on save.

Guard: the box emits a hidden `sl_bank_count`. `SL_Meta::save()` compares it to `count( (array) $_POST['sl_bank'] )`; on mismatch it **refuses the bank write**, keeps the previous value, and shows a notice naming `max_input_vars`. The UI caps the editor at 50 rows.

### 2.6 File and line changes — `wp-content/plugins/scholaris-library`

| File | Change |
|---|---|
| `scholaris-library.php:19` | `SL_VERSION` `1.0.2` → `1.1.0` (admin.js/admin.css are cache-keyed on `?ver=`) |
| `scholaris-library.php:24-28` | `require_once` `class-sl-console.php`, `class-sl-bank.php` |
| `scholaris-library.php:35-43` | `SL_Console::init(); SL_Bank::init();` |
| `includes/class-sl-meta.php:21-30` | register `sl_material_video` (side/default) and `sl_material_bank` (normal/low) |
| `includes/class-sl-meta.php:37-51` | also enqueue `assets/css/admin.css` (new file — `assets/css/` currently holds only `library.css`); add the new `wp_localize_script` strings |
| `includes/class-sl-meta.php` (new render methods) | video box markup; bank box markup |
| `includes/class-sl-meta.php:122-123` | attachment-type validation on `sl_file_id` |
| `includes/class-sl-meta.php:143-144` | after the access select, print the honest help text of section 3.3 |
| `includes/class-sl-meta.php:145` (extend `save()`) | video source/url/id validation (§2.3); bank via `SL_Bank::save()` (§2.5, §4.3) |
| `includes/class-sl-post-types.php:102-114` | add a `sl_media` column beside `sl_file`/`sl_pages` |
| `includes/class-sl-post-types.php:122-146` | `sl_media` renders `PDF · VIDEO · BANK`; **the red `none` at `:126-128` must not fire for a material that has a video but no document** |
| `templates/single-study_material.php:23,76,106` | §2.4 restructure |
| `templates/single-study_material.php` (aside, after `:143`) | the "Sit the practice paper" panel (§4.4) |
| `templates/library-grid.php:90,97,119` | `$sl_ext` is `''` for a video-only material and the chip falls back to `'DOC'` at `:97`; add a `VIDEO` chip and include it in the `:119` meta line |
| `assets/js/admin.js` | second `wp.media` frame with `library: { type: 'video' }`; source-radio show/hide; bank add/remove row from a `<template>` (must degrade: with JS off, the rows already present still save) |
| `assets/css/admin.css` | **new** |
| `includes/class-sl-console.php` | **new** — §5, §8 |
| `includes/class-sl-bank.php` | **new** — §4 |
| `templates/admin/console.php` | **new** — §5 |

---

## 3. THE VIDEO GATING HOLE

### 3.1 The actual behaviour: yes, uploaded files are publicly fetchable

Measured this pass, unauthenticated, against the existing fixture (post 22, `_scholaris_access = members`):

```
GET /wp-content/uploads/2026/08/gate-members.txt   →  200   (body served)
GET /?sl_download=22                               →  403
```

Same file, same material. The mechanism is in `/var/www/html/.htaccess`, read this pass — stock WordPress, `RewriteCond %{REQUEST_FILENAME} !-f`: a request that resolves to an existing file never reaches `index.php`. `wp-content/.htaccess` denies only `\.(log|sql|bak)$`. **There is no `.htaccess` anywhere under `uploads/`** (confirmed by `find` in the container).

This is **not new to video.** It is true of every PDF in the library today. `_scholaris_access = members` gates exactly one route — the `?sl_download=` handler — and the raw uploads URL is the other one.

Video makes it sharper for one specific reason. `SL_Library::handle_download()` sets Content-Type, Content-Disposition, Content-Length and `X-Content-Type-Options` at `:185-188` and ends in `readfile()` at `:190`. **No `Accept-Ranges`, no HTTP 206 handling anywhere in the file.** An HTML5 `<video>` cannot seek through it and Safari/iOS generally refuse to play at all, while Apache serving the raw path *does* send `Accept-Ranges: bytes`. So an implementer who wires `<video src>` to `SL_Library::download_url()`, finds it unseekable, and switches to `wp_get_attachment_url()` silently removes the gate. **That is a one-line trap that rewards you for falling into it**, and it is why §2.4 specifies the direct URL explicitly, with the reason in a code comment, rather than leaving it to be discovered.

Also at `:180`: `update_post_meta()` on every request. Fine for a PDF fetched once; a database write per seek for a video.

### 3.2 The honest answer on the fix

**Full gating is achievable on this stack and cannot be guaranteed on the production host. We are not building it in v1.**

Both halves of that are measured, and the first half is a fact none of the surveys established:

- **`.htaccess` deny works here.** `GET /wp-content/debug.log` returns **403** with the file present on disk (5 bytes, verified), enforced by the `FilesMatch` block in `wp-content/.htaccess`, under `AllowOverride All` scoped to `<Directory /var/www/>` at `/etc/apache2/conf-available/docker-php.conf:10`. So a `Require all denied` in a private uploads subdirectory would genuinely work on this Apache.
- **It cannot be shipped or proven from the repo.** `wp-content/uploads` is not in git and is not in `.github/workflows/deploy.yml`'s rsync set — it lives in the `wp_core` named volume. A deny file has to be written at runtime by PHP, which means nothing in version control proves it is there, and if that write ever fails the upload still succeeds, the material still saves, and the "Signed-in students" label still lies.
- **Production is a different answer, unverified.** `docs/03-hosting-deployment.md:97` recommends Hostinger Business (LiteSpeed). LiteSpeed honours `.htaccess`; **nginx does not**, and on such a host the deny file is inert with nothing to tell you.
- **Serving would be entirely through PHP.** `apache2ctl -M` in the container this pass shows no `xsendfile_module` (and no `headers_module`), so a Range-capable streamer means owning 206/`Content-Range`/resume/concurrency in PHP, against `memory_limit 512M`, with WP Super Cache's `ob_start` latent behind `$cache_enabled = false` (`wp-cache-config.php:13`, verified). Done badly that is worse than not done.
- **Closing it for video only would be theatre.** The identical hole exists for every PDF already on the site.

### 3.3 The mitigation, and exactly what the owner must be told

**Link is the default and the recommended path,** stated in the Video box itself. Nothing lands in `uploads`, the 64 MB ceiling never applies, and YouTube/Vimeo do the access control.

**Uploaded video is allowed and labelled.** When *Uploaded file* is selected, the meta box prints the file's real public URL and, when `_scholaris_access` is `members`, this text inline, next to the access select — not in a document:

> **Anyone with the file address can open this, signed in or not.** The "Who can download" setting controls the link on this page; it does not control the file itself. For anything that must stay inside the class, paste an unlisted YouTube or Vimeo link instead of uploading.

**The same sentence must appear for documents.** The measured leak was a `.txt` attached to a document, not a video. Warning only on video would label the case we were thinking about and leave unlabelled the case we actually measured. The help text at the access select is therefore keyed on "a file is attached", not on "the file is a video."

**What the owner is told, in one line:** *the library controls who sees the page; it does not control who can fetch the file if they have its address. Unlisted links are the way to keep a lecture inside the class.*

### 3.4 The deferred fix, with its trigger

Build when — and only when — a specific recording must be genuinely restricted to enrolled students. Contents, so it is not re-derived later:

1. Route new uploads for gated materials into `wp_upload_dir()['basedir'] . '/scholaris-private/<32-hex>/'` via an `upload_dir` filter scoped to the duration of one `media_handle_upload()` call. The random segment is defence in depth, **not a control**.
2. Write `.htaccess` (`Require all denied` plus legacy `deny from all`) and an empty `index.php` into that directory on creation. **If that write fails, refuse the upload** — an error in front of the person who can fix it beats a nightly probe three weeks later.
3. A Range-capable `?sl_stream=<id>&_wpnonce=` handler beside `sl_download`: same nonce and `SL_Meta::can_download()` gate, `while ( ob_get_level() ) ob_end_clean()`, `Accept-Ranges: bytes`, parse `HTTP_RANGE`, emit 206 with `Content-Range`, stream via `fopen`/`fread` in 8 KB chunks — never `readfile()` — and no per-request `update_post_meta`.
4. An **external** probe, not an in-PHP self-check. A PHP self-probe is unreliable in this layout: `home_url()` is `http://localhost:8080`, but Apache listens on `:80` inside the container, so `wp_remote_get( home_url(...) )` does not reach it. Extend `scripts/download-gate.sh` instead, which already drives real cookies and opens with a control that must succeed (`download-gate.sh:15-19`).
5. Extend the same mechanism to documents, with a migration for existing attachments.

Do this **before** telling anyone the library restricts files.

---

## 4. QUESTION BANK

### 4.1 Tutor cannot host it — verified, with the mechanism

A Tutor quiz **cannot** attach to a `study_material`. Three independent couplings, established by execution in the surveys:

1. `QuizBuilder::save_quiz()` hardcodes `'post_parent' => $topic_id` (`classes/QuizBuilder.php:719-724`) behind `can_user_manage( 'topic', $topic_id )`.
2. That gate rejects a study_material **even for administrator dina**: `get_course_id_by( 'topic', 24 )` returns `0`, and the administrator bypass in `Utils::can_user_manage()` sits *inside* `if ( $course_id )`, so it is never reached.
3. Even if it saved, student access runs `has_enrolled_content_access( 'quiz', … )` → course 0, so no student could ever open it, and `wp_tutor_quiz_attempts.course_id` would be written 0 — dropping every attempt out of Quiz Attempts, the gradebook, and `SL_Quiz_History`.

Linking a material to an existing course quiz by permalink is also wrong, and this is the trap the earlier designs fell into: Tutor's quiz template gates on **enrolment in the parent course**. Library access is `public|members`, and `members` means only `is_user_logged_in()` (`class-sl-meta.php:152-160`). A *public* material with a quiz link would hand an anonymous visitor a button that lands on Tutor's login template. Two incompatible access models, silently composed. There are also 0 `tutor_enrolled` rows and this spec builds no enrolment path.

**Tutor is out.**

### 4.2 The concrete alternative: author on the material, materialise per student at sit time

The bank is stored on the `study_material` and converted into a real per-student exam row the moment a student clicks *Sit the practice paper*.

- **`_scholaris_bank`** — JSON `{ "schema_version": 1, "questions": [ … ] }`, in **exactly** the envelope `EduAI_Exams::generate()` writes at `class-eduai-exams.php:558-561`.
- **`_scholaris_bank_rev`** — integer, incremented whenever the normalised bank JSON changes. Edit the bank and students get the new version; old attempts keep pointing at the paper they actually sat.

That envelope is the entire argument for this approach, and every hop was read this pass:

| What you get free | Where |
|---|---|
| The student sits it in the existing PrepareME UI | `assets/js/prepare.js:435-449` parses `/[?&]exam=(\d+)/` and calls `loadExam()`; `eduai_prepare_url( int $exam_id )` already exists (`eduai-assistant.php:351`) |
| Blank paper served on a retake | `EduAI_REST::exam_get()` `:432-437` |
| No cross-student reads | `exam_owned()` `:195-203` — 403 unless `exam.user_id === get_current_user_id()` |
| Answer key never reaches the browser | `EduAI_Exams::redact()` `:336-355`, plus `scripts/redaction-guard.php` in CI |
| Marking, instant and free | `grade()` `:615-638` compares MCQ in PHP |
| Attempt stored, roster updated | `store_attempt()` `:815` → `wp_eduai_exam_attempts` → the Student progress screen |

**No new student-facing JavaScript** — because the sit button is a plain link to a server handler (§4.4), not a `fetch()`.

### 4.3 `SL_Bank` — storage and validator

**v1 is MCQ only.** This is a deliberate boundary with a named cause. `grade()` at `class-eduai-exams.php:700-705`:

```php
if ( $pending ) {
    $marked = self::grade_short_answers( array_values( $pending ) );
    if ( is_wp_error( $marked ) ) {
        return $marked;
```

One short-answer question couples the **entire attempt** to a live model call: the MCQ marks already computed at `:619-638` are discarded and `store_attempt()` never runs. On an install where the owner's Z.ai key 401s and a free Groq tier is what answers, a teacher-authored bank must not have that dependency. To lift the limit later, `:700-705` must first store the MCQ half and flag shorts ungraded — the `Ungraded` branch at `:716-731` already shows the shape.

**`SL_Bank::validate()` must NOT call `EduAI_Exams::normalize_exam()`.** Read this pass: it requires *exactly* `$count` questions (`:1104-1106`), ids 1..n in presentation order (`:1219-1228`), bands ordered easy→medium→hard (`:1223-1225`) and a fixed band split against `band_mix()` (`:1230-1241`). Those exist to catch a *model* that miscounted. A lecturer writing seven medium questions is not a defect.

Keep only the invariants downstream code actually depends on:

| Invariant | Why (verified) |
|---|---|
| ids renumbered 1..n contiguous, in array order | `grade()` matches on `$q['id']` `:616`; `redact()` passes it through `:340` |
| `type === 'mcq'` | `grade()` has exactly two branches, `:619` and the short fallthrough |
| exactly 4 non-empty `options` | `redact()` emits `$q['options']` for mcq `:348` |
| `answer_index` int 0–3 | compared at `:624` |
| `explanation` non-empty | echoed at `:636` with no coalescing — a missing key is a PHP notice in a student's marked paper |
| `band` ∈ easy\|medium\|hard, **default `medium`** | read with no coalescing at `:630` and `:341`; `attempt_for_client()` groups on it `:396-406` |
| `marks` int 1–5 | `:617`, clamped like `:1163` |
| **no** count rule, **no** band-mix rule, **no** band-order rule | generation invariants only |

### 4.4 The sit handler and the one refactor it needs

**New in `class-eduai-exams.php`:** extract the insert into a public method. The correct range is **`:549-565` (the `$wpdb->insert`) plus `:567` (`$wpdb->insert_id`), returning the array currently at `:575-581`.** It stops there: `:569-570` are the two `EduAI_Conversation::add()` calls and the second passes `$out['usage']`, a variable that cannot exist in the extracted function. Those stay in `generate()`.

```php
public static function store_prepared(
    int $user_id, array $questions, string $label, string $hash, string $title
): array
```

`generate()` then calls it. No behaviour change; `scripts/redaction-guard.php` and the rest of CI cover the file already.

**New in `class-sl-bank.php`:** `handle_sit()` on `template_redirect`, reached by a plain link

```
/?sl_sit=<material_id>&_wpnonce=<wp_create_nonce( 'sl_sit_' . $material_id )>
```

mirroring `SL_Library::download_url()` (`class-sl-library.php:138-146`). Sequence, mirroring `handle_download()` (`:151-192`):

1. bad nonce → `wp_die` 403 (`:159-161`)
2. not a published `study_material` → 404 (`:163-165`)
3. `! is_user_logged_in()` → `wp_safe_redirect( wp_login_url( get_permalink( $id ) ) )` (`:167-170`)
4. `! SL_Meta::can_download( $id )` → same redirect. **The bank is gated exactly as the document is** — one access model, not two
5. `! class_exists( 'EduAI_Exams' )` → `wp_die` with "practice papers need the assistant plugin"
6. empty bank → 404
7. `$hash = hash( 'sha256', 'sl_bank:' . $id . ':' . $rev )`
8. `$existing = EduAI_Exams::find_by_hash( $user_id, $hash )` (`:143-159`) → reuse its id, so re-sitting does not accumulate one exam row per click
9. else `EduAI_Exams::store_prepared( $user_id, $questions, get_the_title( $id ), $hash, get_the_title( $id ) )`
10. `wp_safe_redirect( eduai_prepare_url( $exam_id ) )`

`store_prepared()` makes no model call, so it correctly bypasses `check_exam_rate_limit()`. **Note for the record:** submitting a paper spends the *chat* bucket, not the exam bucket — `exam_submit()` calls `check_rate_limit()` at `class-eduai-rest.php:356` (default `rate_limit` 20/hour, per-user), while `check_exam_rate_limit()` is only reached from `exam_create()` at `:336`. With MCQ-only banks there is no model call in the loop at all.

**Front end:** in the aside of `single-study_material.php`, after the assistant panel (`:132-143`), a panel gated on `$sl_bank && $sl_allowed && class_exists( 'EduAI_Exams' )`, containing one link to `SL_Bank::sit_url( $sl_id )`. Follow the `function_exists( 'eduai_ask_url' )` precedent at `:118` — a missing control is honest, a dead link is not.

**Known latent issue, worth writing down now:** `sit_url()` bakes a nonce into markup, exactly as `download_url()` already does three times in this template (`:81, :88, :92/:100`). WP Super Cache is active with `advanced-cache.php` in place; `$cache_enabled = false` today (`wp-cache-config.php:13`, verified), so it is inert. **If page caching is ever switched on, cached HTML carries one visitor's nonce and every one of those links 403s for everyone else.** That is more likely and more visible than the cached-`sl_download`-body question everyone has been asking about. Do not enable caching without testing it.

---

## 5. COURSES

**Nothing is built. Four links, and a fallback.**

dina holds every capability these need — verified live this pass: `manage_tutor`, `manage_tutor_instructor`, `edit_tutor_courses` all `Y`, alongside `manage_options`, `list_users`, `upload_files`, `edit_posts`.

| Owner's click | URL | Required cap |
|---|---|---|
| New course | `admin.php?page=create-course` | `manage_tutor_instructor` |
| All courses (edit, delete, bulk) | `admin.php?page=tutor` | `manage_tutor_instructor` |
| Course categories | `edit-tags.php?taxonomy=course-category&post_type=courses` | `manage_tutor` |
| Enrolled students | `admin.php?page=tutor-students` | `manage_tutor` |
| *(fallback)* Classic course list | `edit.php?post_type=courses` | `edit_posts` |

Add, edit and delete all live inside Tutor's own list and builder. Lessons, topics and quizzes are created *inside* the builder and have no admin screen of their own. The only reason this feels missing today is that Tutor calls `remove_menu_page( 'edit.php?post_type=courses' )`, so there is no "Courses" item where a WordPress user looks. Putting those URLs where the owner looks **is** the fix.

The fallback link matters: `courses` is still registered `show_ui => true`, so the classic list stays reachable by direct URL. If the React builder misbehaves, that is the only escape hatch.

**Per-link capability gating is mandatory.** `tutor_instructor` holds `manage_tutor_instructor` but **not** `manage_tutor`, so gating the console *tile* rather than each *link* hands a future lecturer two buttons that 403. Implement as `SL_Console::link( $url, $label, $cap )` returning `''` when `! current_user_can( $cap )`.

**Add a contract check.** Tutor is not in this repository — `.gitignore` excludes `/wp-content/plugins/*` except the two custom plugins, `docker-compose.yml:69-76` bind-mounts only those, and Tutor is installed **unpinned** by `scripts/setup.sh`. Its admin menu is an array behind `apply_filters( 'tutor_admin_menu', … )` whose entries move between releases. A dead deep link is a 404 with no signal. Add to `scripts/contract-tests.pl` an assertion that `tutor`, `create-course` and `tutor-students` are still registered admin page slugs. It costs less than the console's CSS and turns "the link is dead" into "CI says the link is dead."

Say this to the owner once, plainly: **`docker compose down -v` deletes Tutor and every course, lesson and quiz in it.** The compose header at `docker-compose.yml:6` advertises that command as "stop and delete the database" and does not mention the rest.

---

## 6. STUDENT DATA

**Nothing new is built. The existing screen is linked and fixed in place.**

`users.php?page=eduai-students` — `EduAI_Students`, gated on `list_users` at registration (`class-eduai-students.php:20,27-35`) **and again** in the callback (`:48-54`, with the comment explaining why a callback that trusts its menu registration is one refactor from being reachable directly). Keep both checks.

**Shown:**

- Roster (`:100-131`): Student (display name + `user_email` at `:119`), Role, Papers sat, Average, Best, Last active
- Per student (`:139-200`): Paper, Source, Marks, Score, Sat, from `EduAI_Exams::history_for_user()` (`:202`) and `stats_for_user()` (`:257`)
- Once Tutor quizzes are actually used, quiz attempts through `SL_Quiz_History` — which already degrades to an empty array when the table is absent

**Deliberately not shown, and a prettier screen is not a reason to reverse any of it:**

- **`wp_eduai_exam_attempts.answers`** — the student's own submitted words. The refusal at `class-eduai-students.php:202-208` is a considered decision with the reasoning attached. If marking ever requires it, it gets its own capability, its own per-attempt action and an audit trail — never a column. *(Worth surfacing to the owner as a product question, not settling silently: short answers are marked by a model, so withholding `answers` also means an AI mark cannot be reviewed. v1's MCQ-only bank (§4.3) makes this moot for bank papers.)*
- **`wp_eduai_messages.content`** — 1,600 rows of what students asked an AI tutor. Nothing in wp-admin surfaces it today. Report counts and tokens, never text.
- **`wp_tutor_quiz_attempts.attempt_ip`** — personal data with no teaching value.

**Three fixes, in `class-eduai-students.php` itself — not ported to a new screen:**

1. **Paging.** `:78-81` is `get_users( array( 'orderby' => 'display_name', 'number' => 500 ) )`. Past 500 accounts students silently vanish while the header at `:87-98` keeps saying "500 registered accounts". Use `WP_User_Query` with `get_total()`, 50 per page.
2. **Role filter**, defaulting to `student`. Administrators currently appear in the student roster.
3. **Search** by name or email.

**Capability:** `list_users`, held by `administrator` only on this install. Note that `user-role-editor` 4.65 is active, so this is a policy boundary, not a durable one — real email addresses are on this screen and widening the cap must be a deliberate decision.

**Correction to the record — do not schedule this fix.** Two earlier passes reported `[scholaris_quiz_history user_id="15"]` as an unguarded cross-user read. It is guarded. Read this pass, `class-sl-quiz-history.php:220-223`:

```php
// Only administrators may inspect another student's record.
if ( $user_id !== get_current_user_id() && ! current_user_can( 'edit_users' ) ) {
    return '';
}
```

The assignment is at `:209` and the check is eleven lines below it. No ticket.

---

## 7. UPLOAD LIMITS

### 7.1 Current state, measured in the web container this pass

```
upload_max_filesize = 64M     post_max_size = 64M
memory_limit = 512M           max_input_vars = 1000
wp_max_upload_size()  →  64 MB
```

`D:\chatbot\php\uploads.ini` is 7 lines and sets `file_uploads`, `memory_limit`, `upload_max_filesize`, `post_max_size`, `max_execution_time = 300`, `max_input_time = 300`. It is mounted read-only at `docker-compose.yml:74`.

### 7.2 Recommended change: **none to the limit**

Leave 64 MB. Raising it does not make a lecture uploadable: 500 MB is one multipart POST against `max_execution_time` and a normal upstream, and the failure is total and late — Apache accepts the entire body before PHP rejects it, so the browser uploads everything and *then* errors. Link is the answer for long recordings (§2.2, §3.3).

**If it is raised anyway**, the exact change is: edit `D:\chatbot\php\uploads.ini` lines 4–5, keeping `post_max_size` a few MB above `upload_max_filesize` for field overhead, then `docker compose restart wordpress` — `conf.d` is read at PHP startup.

### 7.3 Is a webserver change also required?

**On this stack, no.** PHP is the only binding layer: no `LimitRequestBody`, `php_value` or `php_admin_value` anywhere in `/etc/apache2/`; mod_php, so there is no separate FPM or proxy timeout; and Apache passed a 70,000,319-byte body through to PHP, which rejected it with `POST Content-Length … exceeds the limit of 67108864 bytes` — a PHP 400, not an Apache 413. `user_ini.filename` is `false`, so `php/uploads.ini` is the only lever.

**On production, unknown and probably yes.** `docs/03-hosting-deployment.md:97` recommends Hostinger Business (LiteSpeed); LiteSpeed and nginx both impose request-body caps PHP cannot override from inside. `docs/03:134` only requires ≥ 64 MB, so **nothing above 64 MB has been validated on any host.** Measure before promising. `docs/03:105` also says to move hosts once the library exceeds ~2 GB — that line predates any video plan and should be revisited before uploads are encouraged.

### 7.4 One change that should ride along regardless

`docker-compose.yml:95-100` — the `cli` service does **not** mount `./php/uploads.ini`. wp-cli therefore reports `upload_max_filesize=2M / post_max_size=8M` and `wp_max_upload_size()` returns 2 MB there, against the web container's real 64 MB. This has already misled one survey. Add:

```yaml
      - ./php/uploads.ini:/usr/local/etc/php/conf.d/uploads.ini:ro
```

---

## 8. ROUTING — what happens when dina signs in

### 8.1 The defect that must be fixed first

There is **no `login_redirect` filter registered anywhere** — verified two ways this pass: grep across `wp-content` returns nothing, and `$wp_filter['login_redirect']` in the live site has **0 callbacks**.

`page-templates/auth-signin.php:21` sets `$scholaris_redirect = scholaris_progress_url()` and emits it as a hidden `redirect_to` at `:66` on **every** sign-in through `/sign-in/`. The form posts to `site_url( 'wp-login.php', 'login_post' )` (`:41`), so core does run `login_redirect` — with `/progress/` as the requested value.

**So today, dina signing in the way the product presents it lands on the student progress page.** The brief's "signing in lands on /wp-admin/" is true only of the native `wp-login.php` form. And a filter guarded on "the requested redirect is the bare `admin_url()`" — the obvious implementation — **never fires**, because the value is always `/progress/`.

### 8.2 The fix

**Theme, `wp-content/themes/scholaris/inc/auth.php`** (this is the auth seam documented in `docs/05-frontend-handoff.md` — coordinate with the front-end session before landing it):

```php
function scholaris_admin_home_url(): string {
    return (string) apply_filters( 'scholaris_admin_home_url', admin_url() );
}

add_filter( 'login_redirect', function ( $to, $requested, $user ) {
    if ( ! $user instanceof WP_User || ! user_can( $user, 'edit_posts' ) ) {
        return $to;
    }

    // The themed form ALWAYS posts a redirect_to (auth-signin.php:21,66), so
    // "only override when empty" never fires. These three are the defaults.
    $defaults = array_map( 'untrailingslashit', array(
        scholaris_progress_url(),  // what /sign-in/ posts
        admin_url(),               // what wp-login.php posts
        home_url( '/' ),           // progress-url's own fallback, template-tags.php:86
    ) );

    // A deep link the visitor actually asked for still wins — a student who
    // clicked a gated download must land back on the document.
    if ( ! in_array( untrailingslashit( (string) $to ), $defaults, true ) ) {
        return $to;
    }

    return scholaris_admin_home_url();
}, 10, 3 );
```

**Plugin, `SL_Console::init()`** supplies the destination:

```php
add_filter( 'scholaris_admin_home_url', fn() => admin_url( 'admin.php?page=eduai-console' ) );
```

Same resolver-with-honest-fallback shape as `scholaris_progress_url()` (`inc/template-tags.php:81-94`): if the library plugin is off, the owner lands on wp-admin — a worse landing but a working one.

Gate on **`edit_posts`, never on the username `dina`.** `student` and `subscriber` lack `edit_posts`, so students are untouched; `tutor_instructor` and `editor` hold it, so a second lecturer works on day one.

**Known edge:** `home_url('/')` is in the defaults, so a lecturer who explicitly asks to go home is sent to the console instead. That path is only reachable when the `/progress/` page is missing. Accepted; documented here so it is not later diagnosed as a bug.

### 8.3 The console page

`includes/class-sl-console.php`:

```php
add_menu_page( 'EduAi', 'EduAi', 'edit_posts', 'eduai-console',
    array( __CLASS__, 'render' ), 'dashicons-welcome-learn-more', '3.7' );
```

Position `'3.7'` as a **string**, not `2` — Tutor's own top-level menu occupies position 2 and WordPress nudges collisions by a hash offset, so "directly under Dashboard" is otherwise not guaranteed.

`render()` re-checks `current_user_can( 'edit_posts' )` and `wp_die`s otherwise — the same belt-and-braces as `class-eduai-students.php:48-54`.

Content: four task cards, each rendered link-by-link through `SL_Console::link( $url, $label, $cap )` (§5), plus a status strip showing material count, **materials with neither a document nor a video** (the health metric must be media-aware, or every correctly-configured link-video material reads as broken), course count (guarded by `post_type_exists( 'courses' )`), registered accounts, and `size_format( wp_max_upload_size() )` — which renders in the web container and therefore shows the true 64 MB, pre-answering "why did my upload fail".

Cards: **1 Library** (`post-new.php?post_type=study_material`, `edit.php?post_type=study_material`, subjects, types) · **2 Courses** (§5) · **3 Students** (`users.php?page=eduai-students`, `admin.php?page=tutor-students`, `users.php`) · **4 Practice papers** (one sentence: *a question bank lives on a material — add it in the Question bank box when you edit the material*).

Plus `wp_add_dashboard_widget` with the same links, so the console is one click away even if the redirect is ever disabled.

Brand tokens from `wp-content/themes/scholaris/assets/css/main.css`, scoped under `.sl-console` so nothing leaks into the rest of wp-admin.

---

## 9. WORK BREAKDOWN

### 9.0 Gate — anyone, before the rest starts

The section-0 precondition run. Blocking for §5 only.

### 9.1 Back-end developer — plugin PHP

**`wp-content/plugins/scholaris-library`**

1. `class-sl-meta.php` — register the Video and Question bank meta boxes; extend `save()` with video source/url/id validation (§2.3), the attachment-type fix at `:122-123`, the bank write and the `sl_bank_count` truncation guard (§2.5).
2. `class-sl-bank.php` (new) — `questions()`, `save()`, `validate()` (§4.3 invariant table), `sit_url()`, `handle_sit()` on `template_redirect` (§4.4), revision bump.
3. `class-sl-console.php` (new) — menu registration, `render()` with its own cap check, `SL_Console::link()` per-link gating, the counts query, dashboard widget, the `scholaris_admin_home_url` filter, admin asset enqueue.
4. `class-sl-post-types.php:102-146` — the `sl_media` column; stop printing red `none` for video-only material.
5. `scholaris-library.php:19,24-28,35-43` — version bump, requires, inits.

**`wp-content/plugins/eduai-assistant`**

6. `class-eduai-exams.php` — extract `store_prepared()` from `:549-567`, leaving `:569-570` in `generate()` (§4.4). No behaviour change.
7. `class-eduai-students.php:78-98` — paging via `WP_User_Query::get_total()`, role filter defaulting to `student`, search (§6).

**`wp-content/themes/scholaris`** *(theme file, but this is auth plumbing — pair with the front-end owner, per the shared-tree rule)*

8. `inc/auth.php` — `scholaris_admin_home_url()` + the `login_redirect` filter (§8.2).

**Infrastructure**

9. `docker-compose.yml:95-100` — mount `uploads.ini` into `cli` (§7.4).
10. `scripts/contract-tests.pl` — Tutor admin-slug assertions (§5).

### 9.2 Front-end developer — markup, CSS, templates

1. `templates/single-study_material.php` — the §2.4 restructure at `:23/:76/:106`, the video block, the practice-paper panel in the aside after `:143`. **This is the highest-risk change in the front-end set**: get the condition wrong and the recommended default renders a blank page.
2. `templates/library-grid.php:90,97,119` — VIDEO chip and meta line.
3. `templates/admin/console.php` (new) — the four cards and the status strip.
4. `assets/css/admin.css` (new) — console layout and meta-box styling. `assets/css/` currently holds only `library.css`.
5. `assets/css/library.css` — player and embed sizing; `wp_video_shortcode` and oEmbed HTML both need a responsive wrapper.
6. `assets/js/admin.js` — second `wp.media` frame (`library: { type: 'video' }`), source-radio show/hide, bank add/remove-row from a `<template>`. **Progressive enhancement only**: with JS off, the rows already in the form must still save.
7. Per `css-geometry-needs-a-viewport`: the bank editor's option row and the console cards get looked at in a real browser at 900 px, 1190 px and mobile before they ship. A stylesheet cannot tell you what wraps.

**Unresolved ownership, flagged rather than assumed:** `single-study_material.php` and `library-grid.php` live in the *plugin*, not the theme, and the FE/BE split on this project is theme-vs-plugin. Someone must decide who commits those two files. Suggested: back-end supplies the data variables and the condition restructure, front-end owns the markup inside the blocks, one paired commit with an explicit pathspec.

### 9.3 Tester — must be verified by execution before any of this is called done

Nothing here is satisfied by reading code.

1. **Precondition (§0).** Course builder creates and saves a course + topic + lesson; confirm by SQL.
2. **Link video, members-only material.** Sign in as a student, open it: the oEmbed player renders. Sign out: the sign-in notice renders, **not a blank page**. This is the §2.4 regression.
3. **Uploaded video.** Upload an mp4 through the Video box; confirm it plays and seeks. Then confirm — and record — that its raw `wp-content/uploads/…` URL returns **200 anonymously**, and that the honest label (§3.3) is present on the editor screen and on the front end.
4. **Rejected URL.** Paste `https://evil.com/?x=youtube.com` → refused, previous value intact, notice names the host. Paste a `youtube.com/embed/…` URL → refused with the address-bar message.
5. **Bank round-trip, with real cookies.** New script `scripts/bank-sit.sh`, modelled on `scripts/download-gate.sh` including its control-must-succeed opener (`download-gate.sh:15-19`): as student A, `GET /?sl_sit=<id>&_wpnonce=…` → 302 to `/prepare/?exam=N`; the paper renders and marks; the mark appears on `users.php?page=eduai-students`. Then `GET /eduai/v1/exam/N` as **student B** → 403. Anonymous `?sl_sit=` → redirect to sign-in. Public material → the button works signed-out only if `_scholaris_access` is `public`.
6. **Redaction.** Confirm the paper served to the student contains no `answer_index` and no `explanation` before submission. `scripts/redaction-guard.php` covers the code path; this covers the wiring.
7. **Truncation guard.** A 60-row bank saves intact, or is refused with the `max_input_vars` notice. Never silently short.
8. **Sign-in routing.** dina at `/sign-in/` → the console. A student at `/sign-in/` → `/progress/`, unchanged. A signed-out visitor clicking a gated download, signing in → lands back on **the document**, not the console.
9. **Capability walk.** Create a throwaway `tutor_instructor` and confirm the console shows no link that 403s.
10. **CI.** New PHP under the existing `php -l` sweep, new JS under `node --check`. `bank-sit.sh` goes in `nightly.yml`, not `deploy.yml` — it needs a live stack. Re-check the exec bit on any new `.sh` **after** committing (this repo has lost it before).

---

## 10. THE THREE BIGGEST RISKS

### Risk 1 — Two of the four requirements point at a plugin that has never been run here and is not in this repository

Measured: 0 `courses`, 0 `lesson`, 0 `topics`, 0 `tutor_quiz`. Tutor 4.0.4 is installed **unpinned** by `setup.sh`, lives in the `wp_core` named volume, is excluded by `.gitignore`, is not in `deploy.yml`'s rsync set, and is destroyed by `docker compose down -v` along with every course the owner ever authors. Its admin menu is an array behind a filter whose entries move between releases. If `admin.php?page=create-course` breaks, the console links to a dead end and nothing anywhere fails.

**What catches it:** the §0 precondition run before a line is written, plus the permanent slug assertions in `scripts/contract-tests.pl` (§5) — the difference between "the link is dead" and "CI says the link is dead". And one sentence to the owner about `down -v`.

### Risk 2 — The access control is a label, and a label can be silently removed

`_scholaris_access = members` gates one route. The other route returns 200 to anyone — measured this pass on a real members-only material. The v1 answer is honesty in the UI (§3.3), and honesty in the UI is exactly the kind of thing a later refactor, a theme change, or a "tidy up the help text" commit deletes without noticing, because nothing breaks when it goes.

**What catches it:** extend `scripts/download-gate.sh` with two assertions that encode the *documented* behaviour rather than a hoped-for one — (a) the raw uploads URL of a members-only material returns 200, and (b) the rendered material page and the editor screen both contain the warning string. Assertion (a) failing means someone closed the hole and the docs must be updated; assertion (b) failing means someone deleted the only thing standing between the owner and a false belief. Both are the useful alarm.

### Risk 3 — The bank round-trip spans two plugins, a per-user table and a redirect, and none of it is visible to inspection

Bank JSON on a `study_material` → `SL_Bank::handle_sit()` → `EduAI_Exams::store_prepared()` → a row in `wp_eduai_exams` owned by the *student* → `/prepare/?exam=N` → `exam_owned()` → `grade()` → `store_attempt()` → the roster. Every hop is real and was read this pass, but the composition has never executed. The specific ways it fails quietly: `store_prepared()` extracted one line too far and `$out['usage']` becomes a fatal; the hash not including `_scholaris_bank_rev` so an edited bank serves the old paper forever; a bank row missing `band` or `explanation` producing PHP notices inside a student's marked paper (`grade():630,636` and `redact():341` all read those keys with no coalescing); the nonce cached by WP Super Cache the day someone flips `$cache_enabled`.

**What catches it:** `scripts/bank-sit.sh` (§9.3 item 5) — a real cookie, a real 302, a real 403 for the wrong student, and a mark that appears on the roster. It must open with a control that must succeed, or "the request was refused" passes for the wrong reason.

---

## What this specification could not establish

- **Whether Tutor's course builder renders and saves on this install.** Source read; screen never loaded. §0 exists to close this.
- **`max_execution_time` / `max_input_time` as mod_php sees them.** Still unmeasured, and I reproduced why: `php -r` inside `scholaris-wp` correctly reports `post_max_size=64M` from `uploads.ini` but reports `max_execution_time=0` and `max_input_time=-1`, because the CLI SAPI hardcodes those regardless of ini. `uploads.ini` sets 300 for both and is proven loaded; that is a sound inference, not a measurement. Serving a probe file would settle it.
- **Whether WP Super Cache would cache a `?sl_download=` or `?sl_sit=` response and serve it to the wrong visitor.** `$cache_enabled = false` today, so it is inert. Untested.
- **Whether Wordfence 8.2.2 interferes with an authenticated 60 MB media upload.** It did not block an anonymous `.txt` fetch; that is a different code path.
- **Production limits and behaviour on the recommended LiteSpeed host** — body caps, and whether an `.htaccess` deny would be honoured there. Unverified. Governs §3.4 and §7.3.
- **That `youtube.com/embed/…` is not a registered oEmbed provider.** Established in two prior passes; not re-run here.
- **Who owns the two plugin-side templates in the front-end / back-end split.** Named in §9.2 rather than assumed.
- **Where `study_material`'s `map_meta_cap => true` comes from.** It is registered with no `capability_type` and no `map_meta_cap` argument (`class-sl-post-types.php:22-42`), yet reports `map_meta_cap=true` at runtime, which is what makes `current_user_can( 'edit_post', $id )` at `class-sl-meta.php:118` a real per-object check. If that is coming from a plugin rather than from core defaults, the gate degrades when that plugin is deactivated. Worth one line of a comment; not worth a redesign.