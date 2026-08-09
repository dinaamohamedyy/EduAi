# EduAi — a WordPress learning platform built around four AI study tools

A complete educational system built on WordPress: a searchable library of
lecture PDFs organised by course, quizzes that record every attempt and score,
and four AI tools that have read your material — lecture summaries (Summarise),
exact calculation (AiCalc), grounded chat (Q&A) and generated exams with marked
corrections (PrepareME). The product was renamed from Scholaris; directories,
slugs and shortcodes deliberately keep their original names
(docs/06-eduai-rebuild.md §1 explains why).

```
┌──────────────────────────────────────────────────────────────┐
│  EduAi theme (directory: scholaris)                          │
│  Home · Library→Course→Material · Summarise · AiCalc         │
│  · Q&A · PrepareME · Student dashboard                       │
└───────────────┬──────────────────────────┬───────────────────┘
                │                          │
┌───────────────▼───────────┐  ┌───────────▼───────────────────┐
│  EduAi Library            │  │  EduAI Assistant              │
│  · study_material CPT     │  │  · Chat grounded in material  │
│  · Subject / type filters │  │  · Lecture summariser         │
│  · Gated PDF downloads    │  │  · Voice in / voice out       │
│  · Quiz history + charts  │  │  · Usage limits and logging   │
└───────────────┬───────────┘  └───────────┬───────────────────┘
                │                          │
┌───────────────▼───────────┐  ┌───────────▼───────────────────┐
│  Tutor LMS (free)         │  │  Claude API (Anthropic)       │
│  Courses · Quizzes        │  │  Sonnet 5 / Haiku 4.5         │
│  Attempt + score records  │  │                               │
└───────────────────────────┘  └───────────────────────────────┘
```

---

## What is in this repository

```
chatbot/
├─ docker-compose.yml            Local WordPress + MariaDB + wp-cli
├─ .env.example                  Copy to .env and fill in
├─ scripts/setup.sh              One-shot bootstrap: plugins, pages, menu
├─ .github/workflows/deploy.yml  Checks on every PR and push, deploy main only
├─ design/preview.html           Static UI preview — open it in any browser
├─ docs/
│  ├─ 01-setup-guide.md          Install, first run, troubleshooting
│  ├─ 02-plugin-stack.md         Every plugin choice and the reasoning
│  ├─ 03-hosting-deployment.md   Host selection, GitHub deploy, go-live checklist
│  ├─ 04-agent-roadmap.md        Phase 2: what to build next and how
│  └─ 05-frontend-handoff.md     Page inventory, auth flow, design system, back-end checklist
├─ .claude/agents/               Agents for Claude Code itself (dev-side)
└─ wp-content/
   ├─ themes/scholaris/          Custom theme
   └─ plugins/
      ├─ eduai-assistant/        The chatbot
      │  └─ agents/              Agent definitions the chatbot runs
      └─ scholaris-library/      Library and progress tracking
```

> `.claude/agents/` and `eduai-assistant/agents/` are different things.
> The first is read by Claude Code when you are working on this repository;
> the second is read by the plugin at runtime and shapes what students get.

---

## Quick start

```powershell
copy .env.example .env
# edit .env — set ANTHROPIC_API_KEY and ADMIN_PASSWORD

docker compose up -d
docker compose --profile tools run --rm cli bash /scripts/setup.sh
```

Then open <http://localhost:8080>.

No Docker? See `docs/01-setup-guide.md` for the LocalWP path.

**Want to see the design first?** Open `design/preview.html` in a browser — it
is a static, dependency-free mock of the homepage, library, material page and
dashboard, with a working chat panel demo.

---

## The features, and where they live

### A library of study material

Custom post type with subject and material-type taxonomies, PDF/DOCX upload,
automatic page-count detection, per-document access control, and downloads
served through a nonce-protected handler so file URLs cannot be shared outside
the site. The single-material view embeds the PDF inline.

→ `wp-content/plugins/scholaris-library/`

### Quizzes with full attempt history

Tutor LMS records every attempt. The dashboard reads that data directly and
shows score percentage, marks earned against total, pass or fail against the
quiz's own passing grade, questions answered, time taken, and a trend line
across the last twelve attempts.

→ `includes/class-sl-quiz-history.php` · `[scholaris_dashboard]`

### Sign in and registration that match the site

Students create an account on `/register/` — name, e-mail and a password
chosen on the spot — and land on their dashboard already signed in, with the
`student` role. Sign-in and password reset live on matching themed pages.
All three forms post to the **native WordPress endpoints**, so Wordfence,
wps-hide-login and 2FA plugins keep working untouched; a honeypot and per-IP
throttles keep drive-by bots out, and every failure returns to the themed
page as a readable notice rather than a bare wp-login screen.

→ `themes/scholaris/inc/auth.php` (front) · `inc/auth-flow.php` (back) ·
`docs/05-frontend-handoff.md` (the seam contract)

### An assistant that has read your material

Documents are split into overlapping passages and indexed with a MySQL FULLTEXT
index. Each question retrieves the most relevant passages and passes them to
Claude as context; answers cite the documents they came from. No vector
database, no monthly service fee.

→ `includes/class-eduai-knowledge.php`

### Selectable agents

The assistant's persona is not hard-coded. Each agent is a Markdown file with
front matter in `wp-content/plugins/eduai-assistant/agents/`, in the same format
the [claude-code-templates](https://github.com/davila7/claude-code-templates)
catalogue publishes — so an agent pulled down with
`npx claude-code-templates@latest --agent <path>` can be dropped in as-is. Add a
file and it appears in the settings dropdown and in the chat window; there is no
code to change.

| Agent | What it does |
|---|---|
| **Knowledge synthesiser** | Answers, then says where that answer sits in the rest of the course — what it depends on, what it contradicts, what the material never covers. The default. Adapted from `expert-advisors/knowledge-synthesizer`. |
| **Study tutor** | Patient, step-by-step explanations grounded in your material. |
| **Critical thinking** | Answers, then names the assumption the answer rests on and asks one question back. Adapted from `expert-advisors/critical-thinking`. |
| **Maths & science** | Full working for calculations and derivations, on the syllabus or off it. |

Both adapted agents keep the upstream method and change the domain: the
originals are written for engineers reviewing code and for mining multi-agent
operational logs, and neither register suits a student who is stuck. The
unmodified upstream files sit in `.claude/agents/expert-advisors/` for use inside
Claude Code, where the original framing is the right one. All four run Opus 5.

Every agent prompt is followed by a shared block of **house rules**, and that is
what stops any single prompt narrowing the assistant's scope: an off-syllabus
maths or science question is always answered in full, under a "Beyond the course
material" heading, rather than deflected to a lecturer. Administrators who need
the opposite can switch it off with *Answer beyond the course material*.

→ `includes/class-eduai-agents.php` · `agents/*.md`

### Two providers, one set of agents

The assistant runs on **Anthropic (Claude)** or **Groq** (open models, free tier),
chosen in Settings → EduAI Assistant. Agent files name a capability *tier* —
`strongest`, `balanced`, `fast` — rather than a model id, so switching provider
needs no other change:

| Tier | Anthropic | Groq |
|---|---|---|
| `strongest` | `claude-opus-5` | `openai/gpt-oss-120b` |
| `balanced` | `claude-sonnet-5` | `llama-3.3-70b-versatile` |
| `fast` | `claude-haiku-4-5-20251001` | `llama-3.1-8b-instant` |

`model: opus` in front matter therefore means "the strongest model this site's
provider offers". `EduAI_Claude::providers()` holds the registry and is
filterable, so Gemini or a self-hosted OpenAI-compatible endpoint is a few lines.

One capability differs: only Anthropic can read a PDF handed over as a document
block, so a scan with no text layer needs Anthropic or OCR first. The plugin says
so rather than failing obscurely.

In the two HTML pages the provider is inferred from the key prefix — `sk-ant-`
or `gsk_` — so there is nothing to configure.

### Testing the agents without WordPress

`tools/agent-test.html` is a single self-contained page that talks to the model
API using the real agent prompts and house rules — they are inlined from
`agents/*.md`, so it sends byte-for-byte what the plugin sends. This one is a
developer harness, not a student-facing surface, so it does still take a key:
open it, paste one, pick an agent, ask something off-syllabus. It shows the
resolved model,
token usage, and the exact system prompt being sent, and it renders replies
through a JavaScript port of `EduAI_REST::to_html()`.

The key lives in the tab's `sessionStorage` and is never written into the file,
so the file stays safe to keep in the repository. What it cannot test is
anything server-side: retrieval from your library, and PDF/PPTX extraction.

If the browser blocks the request as cross-origin, serve the folder over HTTP
rather than opening the file from disk.

### Lecture summarising

Upload a PDF, **PowerPoint deck**, DOCX or text file, or paste the text. Four
styles: full study notes, quick summary, exam preparation, and critical review.

Decks are read slide by slide, in slide order, with **speaker notes pulled in
alongside each slide** — usually the fullest statement of a point, and the part
a student reading the slides alone never sees. Text is extracted server-side;
a PDF that is a scan, or one whose fonts this reader cannot decode, is sent to
Claude as a document block instead, so it still works.

→ `includes/class-eduai-rest.php` · `includes/class-eduai-pdf.php` · `[eduai_summarizer]`

### Voice

Speech-to-text and spoken replies through the browser's Web Speech API — no
extra API cost. Dictation auto-sends when you stop speaking; any answer can be
read aloud. Chrome and Edge have the best support; `docs/02-plugin-stack.md`
covers the paid upgrade path if you need every browser.

→ `assets/js/chat.js`

---

## Shortcodes

| Shortcode | Renders |
|---|---|
| `[eduai_panel height="520"]` | Inline chat + summariser |
| `[eduai_summarizer]` | Lecture summariser alone |
| `[scholaris_library per_page="12" filters="yes"]` | Filterable material grid |
| `[scholaris_quiz_history limit="10" show_chart="yes"]` | Attempts, scores, trend |
| `[scholaris_dashboard]` | The full student view |

Add `data-eduai-open` to any button to open the assistant from anywhere.

---

## Security notes

- The API key belongs in `wp-config.php` (`EDUAI_GROQ_API_KEY`,
  `EDUAI_ZAI_API_KEY` or `EDUAI_ANTHROPIC_API_KEY`) or in the server
  environment — not in the database, and never entered through a browser. The
  settings screen shows which provider is connected and where its key came
  from, but offers no field to type one into, and students are never asked.
- The assistant is restricted to signed-in users by default. Leave it that way
  unless you want anonymous visitors spending your API credit.
- Rate limiting is per user per hour, configurable, on by default.
- Signups are honeypot-protected and throttled (5 per IP per hour, same for
  password resets, 8 failed sign-ins per 10 minutes). Sign-in errors are
  deliberately specific — "no account with that e-mail" vs "wrong password" —
  because registration and reset already reveal whether an account exists.
  Wordfence is the brute-force control: the signup and reset throttles run
  before the action and genuinely stop it, but the sign-in counter runs after
  core has already checked the password, so it changes the message a student
  sees rather than blocking a guess. Behind a proxy/CDN, name the trusted
  client-IP header (`SCHOLARIS_CLIENT_IP_HEADER`) or the per-IP limits become
  site-wide — see `docs/03-hosting-deployment.md`.
- All REST routes verify a nonce and check capability; all output is escaped;
  all queries are prepared.
- Downloads check the document's access level and a per-document nonce.
- Chat logs are purged on a schedule you set, and deleted with the user.

---

## Requirements

WordPress 6.4+ · PHP 8.0+ (8.2 recommended) · MySQL 5.7+ / MariaDB 10.4+ ·
PHP extensions `zip`, `zlib`, `curl`, `mbstring` · outbound HTTPS ·
an API key for Groq, Z.ai or Anthropic (the first two have a free tier)

---

## What comes next

`docs/04-agent-roadmap.md` sets out phase 2: a Quiz Coach that builds a
revision plan from the questions a student got wrong, a Quiz Generator that
drafts questions from a lecture PDF, a Study Planner, and a Content Gap
Detector that reports which topics your library covers badly. The hooks those
agents need (`eduai_request_body`, `eduai_retrieve`) are already in place.

---

## Licence

GPL-2.0-or-later, matching WordPress.
