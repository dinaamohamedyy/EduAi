# Hosting and deployment

## Vercel hosts the mock-up. It does not host the product.

Both halves of that sentence matter, and the repository contains evidence for
each — `vercel.json` at the root, and this section explaining what it is for.
Read this before concluding that either one is a mistake.

### Vercel cannot run WordPress

WordPress is a PHP application needing a persistent MySQL database and a
writable filesystem for uploads. Vercel runs JavaScript and Python in
short-lived serverless functions with a read-only filesystem and no MySQL.
Community PHP runtimes exist; none run WordPress core, and even one that did
would lose every uploaded PDF when the function recycled.

So the **product is deployed nowhere** until `DEPLOY_METHOD` is set and a PHP
host exists. `deploy.yml`'s deploy job is a deliberate no-op until then, and it
says so in its own summary rather than reporting a success.

### What the Vercel deployment actually is

`vercel.json` builds `scripts/build-static-preview.sh` into `dist/preview-site`,
which emits **one file**: the design mock-up from `design/preview.html`.

- **The AI features are inert in it.** The assistant, AiCalc and PrepareME all
  need a server-side key; the published page ships `API_KEY = ''` and the build
  refuses to run if that ever stops being empty. Nothing there talks to a model.
- **`.vercelignore` excludes the product** — `wp-content/`, `php/`, `docs/`,
  `.github/`. Only the mock and its build inputs are uploaded.
- **It is `noindex, nofollow`** and sits behind Vercel Deployment Protection, so
  it is not publicly reachable and will not appear in search.

**Do not use `edu-ai.vercel.app` to check it.** That domain belongs to an
unrelated Ukrainian-language site. The project's own deployments are on
`edu-ai-git-main-*.vercel.app` style hostnames.

### The trap this exists to prevent

The owner's first bug report on this project — a sign-in page showing a
permanent "password is wrong" error and letting anyone through — was **against
the mock-up**, while he believed he was looking at the site. It cost days.

A public URL serving that same mock is that trap with a domain name on it, and
on 10 Aug 2026 it was worse than that: Vercel was building a commit **six
behind**, so the published mock still carried the permanent sign-in error that
`d3c260c` had already replaced with an on-demand demo. The deployment was
advertising the original bug back at us.

**So: Vercel is where the design is reviewed, never where the product is
tested.** Anything about sign-in, exams, downloads or the assistant has to be
checked against a real WordPress — locally on Docker, or on a host once one
exists.

### Vercel builds `origin/main`, which is not your working tree

This is the failure mode to expect, because it is structural rather than a bug
that was fixed once. Six sessions share one working copy here; commits land
irregularly and pushes lag further. Vercel builds from what has been **pushed**,
so "what is deployed" trails "what is here" by an unpredictable amount — it was
six commits on 10 Aug, and one commit plus nine uncommitted files an hour later.

Before trusting anything on that URL, check what it was built from:

```bash
git rev-parse --short HEAD          # what you are looking at locally
git rev-parse --short origin/main   # what Vercel builds
git status --porcelain              # work that is in neither
```

If those disagree, the deployed mock is not the mock you are editing, and no
amount of reloading the page will change that.

### If you ever want a real Vercel front end

The route is *headless WordPress*: keep WordPress as the admin and API on a PHP
host, and build a Next.js front end on Vercel reading the WordPress REST API.
That is a separate, larger project, worth doing only for front-end performance.

---

## Choosing a host

**Buy SSH. It is the requirement, not a nicety** — and it is the one thing that
decides this table.

`scripts/setup.sh` is **37 wp-cli invocations**: it clones the `student` role,
creates eight pages carrying exact shortcodes, sets `default_role`,
`users_can_register` and the permalink structure, activates the theme, flushes
rewrites, and finishes by asserting the tables, options and pages exist. A host
without SSH means performing all of that by hand in wp-admin — which is exactly
where a deployment quietly stops being the site that was approved. The role
clone and the shortcode pages are the ones that go wrong silently.

| Host | Cost | SSH / WP-CLI | Notes |
|---|---|---|---|
| **Hostinger Business** | ~$4–8/mo | **Yes** | The pick. LiteSpeed caching, Egypt-friendly billing. |
| ~~Hostinger Premium~~ | ~$3/mo | **No** | Cheaper and disqualified — `setup.sh` cannot run. |
| **Cloudways** (DigitalOcean 2 GB) | ~$26/mo | Yes | Staging sites, server-level caching, no bandwidth surprises. |
| **SiteGround** GrowBig | ~$7/mo intro | Yes | Good support, prices jump sharply at renewal. |
| **Kinsta / WP Engine** | $30+/mo | Yes | Excellent, overkill until this is a real product. |

**Recommendation:** **Hostinger Business**, using the SSH/rsync transport already
built into `deploy.yml`. Move to Cloudways when concurrent students exceed ~50 or
the library exceeds ~2 GB.

### Why not a container host (Railway, Render, Fly.io)

`docker-compose.yml` is a **development** environment, and deploying it looks
closer to what you have been testing than it really is:

- It **bind-mounts** the theme and both plugins from local disk. Those paths do
  not exist on a remote host, so the code has to be baked into an image — a
  Dockerfile and a rebuild-on-push pipeline, neither of which exists.
- **Uploads land on an ephemeral filesystem** unless a volume is deliberately
  attached, so student PDFs vanish on redeploy. That is the same failure that
  disqualified Vercel; taking it on knowingly would be worse than taking it on
  by accident.
- MariaDB becomes a managed service to provision and back up. **Render has no
  managed MySQL at all.**
- `deploy.yml` already has working, tested rsync **and** FTPS transports.
  A container host uses neither.

The product is a theme and two plugins on WordPress. That is precisely what
managed PHP hosting serves, and the SSH path reaches a working URL in about an
hour — most of it waiting on DNS and the WordPress installer.

### Server requirements

- PHP **8.1+** (8.2 preferred), MySQL 5.7+ / MariaDB 10.4+
- PHP extensions: `zip` (for DOCX reading), `zlib` (for PDF text extraction),
  `curl`, `mbstring`
- `memory_limit` ≥ 256 MB
- `upload_max_filesize` and `post_max_size` ≥ 64 MB
- `max_execution_time` ≥ 120 (summarising a long lecture takes time)
- **Outbound HTTPS must be allowed** — some shared hosts block it, and the
  assistant cannot reach the Claude API without it. Test with the
  *Test API connection* button before committing to a host.
- Free SSL (Let's Encrypt) — required, and the microphone will not work without
  HTTPS anyway

---

## The repository

Already set up and pushed — **<https://github.com/dinaamohamedyy/EduAi>**, branch
`main`. Nothing here needs doing again; it is recorded so the next person knows
where the code lives.

```bash
git clone https://github.com/dinaamohamedyy/EduAi.git
```

`.gitignore` excludes WordPress core, uploads, `wp-config.php`, third-party
plugins, `.env`, and every `preview*.html` at the root — that last one is the
working copy which deliberately holds a live key. Only our own code is
committed, because core and plugins are managed by WordPress's own updater on
the server.

### The repository is public

Anyone can read it, so a leaked key is not a mistake a later commit can undo —
it is public the moment it is pushed, and it stays in the history. Rotate
immediately rather than trying to erase it.

CI runs `scripts/check-no-secrets.pl` as its **first** step for exactly this
reason, and a second step extracts every committed `.zip` and scans inside it —
archive entries are compressed, so scanning the container itself matches
nothing and would report "clean" while checking nothing.

**Before any push, and before regenerating the zip:**

```bash
perl scripts/check-no-secrets.pl
```

That must print `clean`. Do not use `git grep "sk-ant"` — it misses Groq
(`gsk_`), Z.ai, and a filled-in `var API_KEY`, all of which the script catches.

### Enable the pre-commit hook — once per clone

CI is the wrong last line of defence for a public repository: it runs *after*
the push, and the push is the irreversible act. By the time the lint job goes
red the key is already readable and in the history for good. `.githooks/pre-commit`
catches it while the mistake is still cheap, scanning the **staged** content
(and inside any staged `.zip`) rather than the working tree — the working tree
legitimately holds `preview.html` with a live key in it.

Git does not share hooks between clones, so **it does nothing until you run
this**, and a hook nobody enabled protects nobody:

```bash
git config core.hooksPath .githooks
```

Verify it is live before trusting it — a hook that silently does not run looks
exactly like one finding nothing:

```bash
printf "var API_KEY = 'gsk_%s';\n" "TESTTESTTESTTESTTESTTESTTESTTESTTESTTESTTESTTEST" > design/hooktest.html
git add design/hooktest.html && git commit -m "must be blocked"
git reset HEAD design/hooktest.html && rm design/hooktest.html
```

The commit must be refused with `COMMIT BLOCKED`. If it succeeds, `core.hooksPath`
is not set, or the hook lost its executable bit (`git ls-files -s .githooks/pre-commit`
must show mode `100755`, not `100644`).

---

## First deployment

### 1. Install WordPress on the host

Use the host's one-click WordPress installer. Then, over SFTP or the file
manager, upload:

```
wp-content/themes/scholaris/
wp-content/plugins/eduai-assistant/
wp-content/plugins/scholaris-library/
```

Install **Tutor LMS** and the supporting plugins from wp-admin → Plugins → Add
New (do not upload those from the repo — let WordPress manage their updates).

### 2. Harden `wp-config.php`

```php
define( 'EDUAI_ANTHROPIC_API_KEY', 'sk-ant-...' );
define( 'DISALLOW_FILE_EDIT', true );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'FORCE_SSL_ADMIN', true );
define( 'WP_AUTO_UPDATE_CORE', 'minor' );
```

Regenerate the salts at <https://api.wordpress.org/secret-key/1.1/salt/> and
paste them in, replacing the defaults.

**If the site sits behind Cloudflare, a load balancer or any reverse proxy,
add one more line.** The auth rate limits (5 signups/hour, 5 resets/hour,
8 failed sign-ins/10 min) key on the client IP. Behind a proxy, `REMOTE_ADDR`
is the *proxy*, so every visitor shares a single bucket — the sixth person to
register all day is refused, campus-wide. Name the header your edge actually
sets, and define it **once** — uncomment the line that matches, not both:

```php
define( 'SCHOLARIS_CLIENT_IP_HEADER', 'HTTP_CF_CONNECTING_IP' );    // Cloudflare
// define( 'SCHOLARIS_CLIENT_IP_HEADER', 'HTTP_X_FORWARDED_FOR' );  // one trusted proxy hop
```

Set this **only** when a proxy is genuinely in front of the site, and only to a
header that proxy overwrites on every request. These headers are supplied by
the client, so trusting one on a directly-exposed site lets anyone forge an IP
and bypass the limits entirely. With no constant defined, `REMOTE_ADDR` is
used, which is the safe default. `scholaris_authflow_client_ip()` in
`inc/auth-flow.php` takes the right-most entry of a list-valued header — the
hop your own proxy appended — and falls back to `REMOTE_ADDR` if it does not
parse as an IP.

**With two hops in front of PHP** (Cloudflare in front of a host load
balancer, say) `X-Forwarded-For` stops working: your LB appends *Cloudflare's
edge address*, so the right-most entry is the edge, not the student, and
buckets collapse per edge node — the original bug, partially back. Chained
setups must use a single-valued header set at the outermost edge
(`HTTP_CF_CONNECTING_IP`, `HTTP_TRUE_CLIENT_IP`); those name the client
directly and cannot be diluted by later hops.

**To confirm the header is actually read, you must trip a limit** — a test
that stays under every limit passes whether buckets are shared or not. The
fastest is the failed-sign-in counter (8 per 10 minutes):

1. From network A (phone on mobile data), fail sign-in with a wrong password
   eight times.
2. From network B (wifi), fail sign-in once.
3. Network B seeing the ordinary "that password is not right" notice means
   per-IP resolution works. Network B being told "too many sign-in attempts"
   means both networks share one bucket — the constant is wrong or not being
   read. (A ninth failure from network A should show the throttle notice;
   that just proves the limiter itself is alive.)

Afterwards, just wait — and note the window slides: every failed attempt
resets the full ten minutes, so the throttle clears ten minutes after the
**last** failure, not after the test began. There is deliberately no purge
one-liner here: the counters are transients, which live in the options table
on plain hosting but in Redis/Memcached when the host runs an object cache
(common on Cloudways), so any single command silently does nothing on half
the hosts this guide recommends. The clock works on all of them.

**Provider ceiling — Groq's free tier does not scale past one student.**
The free tier caps the whole **organisation** (the API key, not the user) at
8,000 tokens per minute. One PrepareME round trip measured live is ~5.6k
(generation ~3.3k + marking ~2.3k), so generating and marking back-to-back
trips the cap, and **two students generating in the same minute collide** —
reproducibly. One student is fine because sitting the paper takes minutes;
a class is not, and being per-organisation the ceiling does not grow with
class size at all. The plugin fails honestly when it hits (clear error,
nothing half-stored), but for any real class either pay for a Groq tier
with headroom or configure an Anthropic key — the free tier is a
development and demo key, not a production one.

### 3. Activate and configure

Activate Scholaris, then run through `docs/01-setup-guide.md` → *First-run
checklist*.

---

## Automated deployment from GitHub

`.github/workflows/deploy.yml` runs the checks on every pull request and every
push to `main`, and deploys **only** from `main` or a manual dispatch — a pull
request stops at the checks. Those checks are the merge gate: secrets scan
first, then `php -l`, JavaScript (including the inline script in the HTML
pages), shell syntax, and `scripts/contract-tests.pl`. It supports two
transports.

### SSH / rsync — Cloudways, VPS, any host with SSH

Repository **variable**: `DEPLOY_METHOD` = `ssh`

Repository **secrets**:

| Secret | Value |
|---|---|
| `SSH_HOST` | server hostname or IP |
| `SSH_USER` | SSH username |
| `SSH_PORT` | usually `22` |
| `SSH_PRIVATE_KEY` | the full private key, including header and footer lines |
| `REMOTE_PATH` | e.g. `/home/master/applications/abc/public_html` |

Generate a deploy key and add the public half to the server:

```bash
ssh-keygen -t ed25519 -C "github-deploy" -f deploy_key
# paste deploy_key.pub into the server's ~/.ssh/authorized_keys
# paste the contents of deploy_key into the SSH_PRIVATE_KEY secret
```

#### Dry-run the first deploy. Once, by hand, before CI does it for real.

**`rsync --delete` has never executed against a real server, and the first time
it does it will delete.** Step 1 of *First deployment* populates those three
directories by manual SFTP upload. Anything up there that the repository does
not contain — an editor backup, a stray `.htaccess`, a host-generated file, a
file someone hand-patched before CI existed — is removed on the first run, and
the job summary says "Deployed" either way.

`-n` prints every `deleting …` line and does none of it:

```bash
rsync -avzn --delete -e "ssh -p PORT -i deploy_key" \
  wp-content/themes/scholaris/ USER@HOST:REMOTE_PATH/wp-content/themes/scholaris/
```

Run it for each of the three directories, **read the delete list**, and only
then let CI deploy for real. If something on that list matters, retrieve it
first — it will not be there afterwards.

What `--delete` cannot reach, deliberately: it is scoped to
`themes/scholaris/`, `plugins/eduai-assistant/` and `plugins/scholaris-library/`
rather than to `wp-content/`, so **uploads, WordPress core and third-party
plugins are never in range**. That scoping is the reason a student's PDFs
survive a deploy, and it is not to be widened for convenience.

The workflow also refuses to run at all if `SSH_HOST`, `SSH_USER` or
`REMOTE_PATH` is empty. An empty `REMOTE_PATH` would target
`USER@HOST:/wp-content/…`, which fails loudly on an ordinary box but can
*succeed* inside a chrooted shared-hosting account — writing to a path nobody
looks at while reporting a clean deploy.

### FTPS — Hostinger and most shared hosting

Repository **variable**: `DEPLOY_METHOD` = `ftp`

Repository **secrets**: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`,
`FTP_REMOTE_DIR` (e.g. `/public_html/wp-content/`).

Use FTPS, never plain FTP — plain FTP sends the password in clear text.

### After every deploy that changes CSS or JS

**Purge the page cache** (wp-super-cache on this stack, or the host's own).
Assets are cache-keyed on `?ver=` version strings baked into the cached HTML,
so a deploy that bumps a plugin version but leaves the page cache warm keeps
serving markup that points at the old files — a fix the server has and the
browser does not is indistinguishable from no fix at all. This is a per-deploy
step, not a go-live step: go-live happens once, deploys happen every time.

---

## The provider rate limit decides how many students you can serve

**Measured, not estimated, on 10 Aug 2026 against Groq's free tier.** One
PrepareME exam — generate plus mark, 10 questions — costs about **6,600 tokens
and 11 seconds**. Groq's free tier allows **8,000 tokens per minute, and that
ceiling is per organisation, not per user.**

So the arithmetic is roughly one exam per minute for the *whole site*. That is
not a limit that grows with your class:

- One student is fine, provided they take a minute to sit the paper.
- **Two students generating at once is not.** Nor is one student who submits
  quickly — generate and mark inside the same minute exceeds the ceiling.
- Q&A and Summarise draw on the same budget, so a class using the assistant
  during a lecture competes with itself.

This was observed, not predicted — a real run returned
`Rate limit reached … Limit 8000, Used 7281, Requested 2348. Please try again in 12.2s.`
The failure is graceful: an honest message naming the provider and the wait,
propagated as a `WP_Error`, and **no half-built attempt is stored**. But to a
student mid-lecture it reads as "the assistant is broken".

**Decide this before a class uses PrepareME**, because it is a hard ceiling on
concurrency rather than something more hardware fixes:

1. **A paid provider tier** — the direct fix. Both Groq and Anthropic sell
   substantially higher per-minute allowances.
2. **Serialise generation server-side** — a queue with an honest "you are in
   line" beats a rate-limit error, and it keeps the free tier viable for small
   groups.
3. **Stagger by design** — if a cohort sits papers in a scheduled slot rather
   than all at once, one per minute may genuinely be enough.

Whichever you pick, set it before students arrive. The cost figure above is the
one to plan against.

---

## Going live

- [ ] **Provider rate limit sized for the class**, not just for one tester —
      see the section above. At 8,000 TPM you can serve roughly one exam per
      minute site-wide.
- [ ] SSL certificate active, HTTP redirects to HTTPS
- [ ] `WP_DEBUG` off
- [ ] Admin username is **not** `admin`, password is strong
- [ ] Two-factor auth on the admin account (Wordfence provides it free)
- [ ] Login URL moved (WPS Hide Login)
- [ ] UpdraftPlus scheduled: daily database, weekly files, stored off-server
- [ ] **A restore tested from a backup** — an untested backup is not a backup
- [ ] `EDUAI_ANTHROPIC_API_KEY` set in `wp-config.php`, key removed from
      Settings → EduAI Assistant
- [ ] Assistant restricted to signed-in users
- [ ] Rate limit set (15–20/hour is a sensible start)
- [ ] Spending limit set in the Anthropic console
- [ ] Privacy policy published — it must state that questions are sent to
      Anthropic for processing
- [ ] Behind a proxy or CDN: `SCHOLARIS_CLIENT_IP_HEADER` configured (see
      *First deployment → Harden wp-config.php*), and Wordfence's own
      "How does Wordfence get IPs" setting matched to it — it resolves IPs
      independently and has the same shared-bucket failure mode
- [ ] **Grading rules re-verified against a live stack** — run
      `docker compose --profile tools run --rm cli wp eval-file /scripts/grade-adversarial.php --allow-root`
      and confirm 12/12. It is the only thing checking that a model's plausible
      lie cannot mint marks: `awarded` clamped to `[0, marks]`, the exam
      winning an `of` disagreement, a skipped `short` scoring 0 and reported as
      ungraded, a hallucinated `id` awarding nothing. **CI does not run this
      and cannot** — it needs a running WordPress, which the lint job has no
      install of. If it has not been run by hand since the marking path last
      changed, those four rules are unverified.
- [ ] **Answer key confirmed not to reach the browser** — run
      `docker compose --profile tools run --rm cli wp eval-file /scripts/projection-leak.php --allow-root`
      This is the server-side half of docs/07 §1: the request goes through
      `rest_do_request`, so the real route, permission callback and
      `EduAI_Exams::for_client()` projection all run, and the assertion is made
      against the JSON that would actually go on the wire. **CI does not run
      this and cannot**, for the same reason as the check above. Note that CI's
      `redaction-guard.php` step covers only `EduAI_Exams::redact()` in
      isolation — a leak introduced anywhere else on the route (a stray field
      re-added after projection, a second endpoint) is invisible to it and
      visible here. Anything the browser receives is one devtools Network tab
      away from the student.
- [ ] Test the whole student journey on a phone

---

## Ongoing maintenance

**Weekly** — apply plugin updates on staging first, check Wordfence alerts,
skim Tools → EduAI Usage for cost.

**Monthly** — confirm backups are running, review chat logs for questions the
assistant handled badly (that is your best source of prompt improvements), and
check the Anthropic console for spend against expectation.

**Each term** — re-index the library after a bulk material upload, archive the
previous term's courses, purge chat logs older than the retention window (the
plugin does this automatically on the daily cron).
