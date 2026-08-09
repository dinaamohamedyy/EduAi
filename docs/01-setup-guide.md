# Setup guide

Two paths. Pick one — you do not need both.

---

## Path A — Docker (recommended for development)

Everything runs in containers, so nothing is installed on Windows except Docker
itself. This is the fastest way to see the site working.

### 1. Install Docker Desktop

<https://www.docker.com/products/docker-desktop/> — install, launch it, wait for
the whale icon to go green.

### 2. Configure

Open a terminal in `D:\chatbot`:

```powershell
copy .env.example .env
notepad .env
```

Set at minimum:

```ini
ADMIN_PASSWORD=something-strong
ADMIN_EMAIL=you@example.com
ANTHROPIC_API_KEY=sk-ant-...
GROQ_API_KEY=
```

**One assistant key is enough, either provider.** Anthropic (Claude):
<https://console.anthropic.com/settings/keys>, about $5 of credit covers
weeks of testing. Groq (open models, free tier, no card):
<https://console.groq.com/keys>. Set both and the provider becomes a
choice in Settings → EduAI Assistant.

### 3. Start

```powershell
docker compose up -d
```

Wait ~30 seconds for the database to become healthy, then bootstrap the site:

```powershell
docker compose --profile tools run --rm cli bash /scripts/setup.sh
```

That installs WordPress, the plugin stack, activates the theme and creates the
Library / My Progress / Study Assistant pages with the menu wired up.

### 4. Open it

- Site: <http://localhost:8080>
- Admin: <http://localhost:8080/wp-admin>
- Mail catcher: <http://localhost:8025> — every e-mail the site sends
  (password resets, new-user notifications) lands here, nothing is
  actually delivered
- Database browser: <http://localhost:8081> (run with `--profile tools`)

### Everyday commands

```powershell
docker compose up -d          # start
docker compose down           # stop, keep the database
docker compose down -v        # stop and wipe the database (fresh start)
docker compose logs -f wordpress
docker compose --profile tools run --rm cli wp plugin list --allow-root
```

Your theme and plugin files are bind-mounted from `wp-content/`, so any edit
you save on disk is live on the next page refresh. No rebuild needed.

---

## Path B — LocalWP (no Docker)

If Docker is too heavy, [LocalWP](https://localwp.com/) is a one-click
WordPress installer for Windows.

1. Install LocalWP, create a site called **scholaris** (PHP 8.2+, MySQL 8).
2. Click **Go to site folder** → open `app/public/wp-content/`.
3. Copy `D:\chatbot\wp-content\themes\scholaris` into `themes/`.
4. Copy both folders from `D:\chatbot\wp-content\plugins\` into `plugins/`.
5. In wp-admin: Appearance → Themes → activate **Scholaris**.
6. Plugins → add **Tutor LMS**, activate it and the two Scholaris plugins.
7. Create the pages listed under *Pages the site expects* below.

---

## First-run checklist

Work through this once, in order.

### 1. Give the server an API key

The key is a server-side setting and only a server-side setting. There is no
field for one on the settings screen and no prompt in the chat window — a key
typed through a browser ends up in the database and in page requests, and
students must never be asked for one.

On the Docker stack this is already done if you filled in `.env` — the keys
pass through as `EDUAI_GROQ_API_KEY` / `EDUAI_ZAI_API_KEY` /
`EDUAI_ANTHROPIC_API_KEY` constants.

Elsewhere, **preferred** — edit `wp-config.php` and add one of these above
the `/* That's all, stop editing! */` line:

```php
define( 'EDUAI_GROQ_API_KEY', 'gsk_your-key-here' );
```

```php
define( 'EDUAI_ZAI_API_KEY', 'your-key-here' );
```

```php
define( 'EDUAI_ANTHROPIC_API_KEY', 'sk-ant-your-key-here' );
```

**Or**, on a host where `wp-config.php` is not writable, set `GROQ_API_KEY`
(or `ZAI_API_KEY` / `ANTHROPIC_API_KEY`) in the server environment — the plugin
reads that too.

Any one provider is enough. Groq and Z.ai both have a free tier; on Groq the
`strongest` tier resolves to `openai/gpt-oss-120b`, which is the strongest
model this project reaches without a bill. Only Anthropic can read a PDF that
has no text layer.

Then click **Test API connection** on Settings → EduAI Assistant. You want
"Connected successfully" for the provider you configured.

### 2. Upload your first study material

Library → Add study material:

- Title: the lecture name
- Excerpt: one or two sentences — this shows on the library card
- **Document** box on the right: Choose file → upload the PDF
- Subject and Material type on the right
- Publish

The page count fills in automatically for PDFs.

### 3. Index it for the assistant

Settings → EduAI Assistant → **Rebuild index**.

You should see something like *"Index rebuilt: 47 passages from 3 documents."*
If it says 0 passages from a PDF you just uploaded, the PDF is a scan with no
text layer — see *Troubleshooting* below.

From now on, indexing happens automatically whenever you save a document. The
manual rebuild is only for the initial import or after a bulk upload.

### 4. Create a course and a quiz

Tutor LMS → Courses → Add New. Inside the course builder:

- Add a topic, then a lesson
- Add a quiz to the topic
- Add questions — multiple choice, true/false, fill in the blanks, etc.
- Quiz settings: set **Passing grade** (the dashboard reads this to decide
  pass/fail), and set **Attempts allowed** to 0 for unlimited practice

### 5. Test as a student

Open a private browser window, register a new account, take the quiz, then
visit **/dashboard/**. You should see the attempt with its score, the pass/fail
badge and the trend chart.

Then click the assistant button and ask something answerable only from your
uploaded PDF. The answer should cite the document by name.

---

## Pages the site expects

`scripts/setup.sh` creates these. If you set the site up by hand, create them
with exactly these slugs and contents:

| Page | Slug | Content |
|---|---|---|
| Home | `home` | anything — the theme's `front-page.php` takes over |
| Library | `library` | `[scholaris_library per_page="12" filters="yes"]` |
| My Progress | `dashboard` | `[scholaris_dashboard]` |
| Study Assistant | `assistant` | `[eduai_panel height="600"]` |
| Summarise a Lecture | `summarise` | `[eduai_summarizer]` |
| Sign in | `sign-in` | empty — the theme applies `page-templates/auth-signin.php` by slug |
| Create account | `register` | empty — `auth-register.php`; students choose a password and are signed straight in |
| Reset password | `reset-password` | empty — `auth-reset.php`; e-mails the reset link |

Then Settings → Reading → *Your homepage displays* → **A static page** → Home.

Registration is open by default (`users_can_register 1`) and new accounts get
the **student** role (`default_role`), both set by `setup.sh`. Every "Sign in"
link in the theme goes through `wp_login_url()`, which the theme routes to
`/sign-in/` — see `docs/05-frontend-handoff.md` for the full auth-flow map.

---

## All shortcodes

| Shortcode | What it renders |
|---|---|
| `[eduai_panel height="520"]` | Inline chat + summariser panel |
| `[eduai_summarizer]` | Just the lecture summariser |
| `[scholaris_library subject="" type="" per_page="12" filters="yes" columns="3"]` | Filterable material grid |
| `[scholaris_quiz_history limit="10" show_chart="yes" show_stats="yes"]` | Attempts table, stats and trend |
| `[scholaris_dashboard]` | Greeting, quiz history and recent material |

Any button on any page can open the assistant — give it `data-eduai-open`, or
`data-eduai-open="summary"` to land on the summariser tab:

```html
<button data-eduai-open>Ask the assistant</button>
```

---

## Troubleshooting

**"Index rebuilt: 0 passages"**
The PDF has no text layer (it is a photo of pages). Two options: run it through
OCR first (Adobe Acrobat, or `ocrmypdf` on the command line), or leave it — the
summariser still handles scans by sending the file to Claude directly, it is
only the searchable index that needs real text.

**Assistant replies "no course material matched"**
Either the index is empty (rebuild it) or the question uses words that appear
nowhere in your documents. MySQL FULLTEXT also ignores words under 4 characters
and words appearing in more than 50% of rows — normal for a small corpus, and it
resolves itself as you add more documents.

**"The Claude API rejected the key"**
The key is wrong, revoked, or the account has no credit. Check
<https://console.anthropic.com/settings/billing>.

**The microphone button does nothing**
Speech recognition needs Chrome or Edge, and an **HTTPS** connection.
`localhost` counts as secure; a plain-HTTP staging domain does not.

**Uploads fail on a big PDF**
Raise `upload_max_filesize` and `post_max_size` in PHP. In Docker they are
already 64 MB via `php/uploads.ini`. On shared hosting, use the host's PHP
settings panel.

**Quiz history is empty but you took a quiz**
Only *finished* attempts are shown. An abandoned attempt stays in the
`attempt_started` state and is filtered out by design.
