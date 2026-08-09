# The plugin stack — what we chose and why

Every plugin here earns its place. WordPress sites rot when they collect
overlapping plugins, so the rule applied throughout: **one plugin per job, and
prefer a small custom plugin over a large general-purpose one** when the job is
specific to this project.

---

## 1. Core: learning management and quizzes

### Tutor LMS — *free*

The engine for courses, lessons, quizzes and enrolment.

**Why this one over the alternatives:**

| | Tutor LMS | LearnDash | LifterLMS | Sensei |
|---|---|---|---|---|
| Cost for what we need | Free | ~$199/yr | Free core, paid add-ons | ~$179/yr |
| Quiz attempt history with scores | Built in, free | Built in | Built in | Built in |
| Question types in the free tier | 10 | n/a (paid) | 6 | 5 |
| Attempt data queryable via SQL | Yes, clean schema | Yes | Yes | Yes |
| Front-end course builder | Yes | Paid add-on | No | No |

Tutor LMS stores every attempt in `wp_tutor_quiz_attempts` with `total_marks`,
`earned_marks`, `attempt_status` and timestamps. That table is what our
**Scholaris Library & Progress** plugin reads to build the student dashboard —
so the "previous quizzes with their scores" requirement is satisfied by real
data, not a parallel record we would have to keep in sync.

**Quiz settings worth turning on** (Tutor LMS → Settings → Quiz):

- *Attempts allowed*: 0 for unlimited practice, or 3 for graded assessments
- *Quiz auto start*: off — students should see the instructions first
- *Question layout*: one question per page for exam-like quizzes
- *Feedback mode*: **Retry** for revision quizzes, **Reveal** for practice

---

## 2. Custom-built for this project

These two are in the repository. They exist because no off-the-shelf plugin
does the job without dragging in a lot of unrelated weight.

### EduAI Assistant

The chatbot. It does four things:

1. **Answers questions grounded in your own material.** Every study document
   and lesson is split into passages, stored in `wp_eduai_chunks` and indexed
   with a MySQL `FULLTEXT` index. When a student asks something, the most
   relevant passages are retrieved and passed to Claude as context, and the
   answer cites the documents it used. No external vector database, no monthly
   fee, no extra service to keep alive.
2. **Summarises lectures.** Upload a PDF, DOCX or text file, or paste the text.
   Three output styles: full study notes, quick summary, exam preparation. If a
   PDF is a scan with no text layer, the file is sent to Claude directly as a
   document block so it still works.
3. **Voice in and out.** Speech-to-text and text-to-speech through the browser's
   Web Speech API. Zero additional API cost, works offline for the speech part,
   supported in Chrome and Edge. See *Voice: the trade-off* below.
4. **Guards the budget.** Per-user hourly rate limit, signed-in-only by default,
   token usage reported under Tools → EduAI Usage.

**Why not an off-the-shelf AI chat plugin?** The ones on the market
(AI Engine, WPBot, Chatbot with ChatGPT) are built around answering from a
generic knowledge base or a scraped copy of your site. None of them read your
uploaded PDFs, none cite the source document, and most charge monthly for the
features that matter here. Building it took one plugin file tree and gives you
full control of the prompt, the retrieval and the data.

### Scholaris Library & Progress

The material library and the student dashboard.

- Custom post type `study_material` with `Subject` and `Material type` taxonomies
- Attach a PDF/DOCX per document, with page count detected automatically
- Access control per document: public or signed-in students only
- Downloads go through a nonce-protected handler, so the file URL cannot be
  shared outside the site
- Searchable, filterable grid; inline PDF viewer on the single view
- `[scholaris_quiz_history]` — attempts table with score, pass/fail, marks,
  time taken and a score-trend chart
- `[scholaris_dashboard]` — the whole student view in one shortcode

---

## 3. Supporting stack

Installed automatically by `scripts/setup.sh`.

| Plugin | Job | Why this one |
|---|---|---|
| **Wordfence Security** | Firewall, malware scan, login rate-limiting | The most complete free WAF for WordPress. A site holding student records needs one. |
| **UpdraftPlus** | Scheduled backups to Google Drive / S3 | Free tier covers full site backups on a schedule. Test a restore before you need it. |
| **WP Super Cache** | Page caching | Simple, stable, no configuration required. Skip it if your host has server-level caching (Cloudways, Kinsta) — running both causes stale pages. |
| **Yoast SEO** | Titles, meta, sitemaps | Only matters if the site is public-facing. Drop it for a closed internal platform. |
| **WPS Hide Login** | Moves `/wp-login.php` to a custom URL | Removes ~90% of automated login attempts on its own. |
| **User Role Editor** | Fine-grained capabilities | Lets you give instructors material-upload rights without full admin. |
| **WebP Express** | Serves WebP images | Course thumbnails are the heaviest asset on the library grid. |
| **Redirection** | 404 tracking and redirects | Catches broken links when course URLs change. |
| **Loco Translate** | Edit UI strings | Change any wording without touching code — useful for adding Arabic later. |

### Deliberately *not* installed

- **Elementor / page builders** — the theme is purpose-built; a builder would
  add ~500 KB to every page load and make the design harder to keep consistent.
- **A form plugin** — Tutor LMS handles enrolment; WordPress handles
  registration. Add WPForms only when you actually need a contact form.
- **A separate membership plugin** — Tutor LMS enrolment already gates content.
- **An AI chat plugin** — replaced by the custom one, as explained above.

---

## Voice: the trade-off

The Web Speech API is free, has no per-request cost, and needs no keys. It is
also **browser-dependent**: excellent in Chrome and Edge, partial in Safari,
absent in Firefox by default.

If you need voice to work identically everywhere, the upgrade path is:

- **Speech to text** → OpenAI Whisper API (~$0.006/min) or Deepgram
- **Text to speech** → ElevenLabs or OpenAI TTS for a natural voice

The plugin is structured for this: add a `/tts` and `/stt` REST route beside the
existing ones in `class-eduai-rest.php` and point `chat.js` at them. Nothing
else changes. Start with the free path and only pay if students complain.

---

## Cost estimate

| Item | Monthly |
|---|---|
| Hosting (shared, e.g. Hostinger Premium) | $3 – $10 |
| Domain (amortised) | ~$1 |
| Tutor LMS + supporting plugins | $0 |
| Claude API — 200 students, ~15 questions each | ~$6 – $18 |
| Claude API — lecture summaries, ~100/month | ~$4 – $12 |
| **Total** | **~$15 – $40** |

Token cost is the only variable that scales with usage. To control it:

- Keep *Restrict to signed-in users* on
- Set the hourly message limit to 15–20
- Use **Claude Haiku 4.5** for chat and **Claude Sonnet 5** only for summaries —
  that alone cuts the chat bill by roughly 70%
- Watch Tools → EduAI Usage for the first month, then tune
