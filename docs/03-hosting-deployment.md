# Hosting and deployment

## Why not Vercel

You asked to deploy from GitHub to Vercel. That is not possible for a WordPress
site, and it is worth being precise about why so the decision stays made.

WordPress is a PHP application that requires a persistent MySQL database and a
writable filesystem for uploads. Vercel runs JavaScript and Python in
short-lived serverless functions with a read-only filesystem, and provides no
MySQL. There are community PHP runtimes for Vercel, but none run WordPress
core — and even if one did, every uploaded PDF would vanish when the function
recycled.

**GitHub is still central to this project.** Your code lives there, the CI
workflow lints it, and deployment is automated from `main`. The only change is
that the deploy target is a PHP host instead of Vercel.

If you ever want a Vercel-hosted front end, the route is *headless WordPress*:
keep WordPress as the admin and API on a PHP host, and build a Next.js front end
on Vercel that reads from the WordPress REST API. That is a separate, larger
project — worth doing only if you need the front-end performance.

---

## Choosing a host

| Host | Cost | Best for | Notes |
|---|---|---|---|
| **Hostinger** Premium/Business | $3–8/mo | Starting out | Cheapest credible option. LiteSpeed caching included. Egypt-friendly billing. |
| **Cloudways** (DigitalOcean 2 GB) | ~$26/mo | Growing, 200+ students | SSH access, staging sites, real server-level caching, no bandwidth surprises. |
| **SiteGround** GrowBig | ~$7/mo intro | Managed simplicity | Good support, prices jump sharply at renewal. |
| **Kinsta / WP Engine** | $30+/mo | Institutional | Excellent, overkill until this is a real product. |

**Recommendation:** start on Hostinger Premium. Move to Cloudways when either
concurrent students exceed ~50 or the library exceeds ~2 GB.

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

## Setting up the repository

```bash
cd D:\chatbot
git init
git add .
git commit -m "Scholaris learning platform: theme, assistant and library"
git branch -M main
git remote add origin https://github.com/YOUR-USERNAME/chatbot.git
git push -u origin main
```

`.gitignore` already excludes WordPress core, uploads, `wp-config.php`,
third-party plugins and `.env`. Only your own code is committed — which is what
you want, because core and plugins are managed by WordPress's own updater on the
server.

**Before the first push, confirm no key is in the repo:**

```bash
git grep -i "sk-ant"
```

That must return nothing.

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

### FTPS — Hostinger and most shared hosting

Repository **variable**: `DEPLOY_METHOD` = `ftp`

Repository **secrets**: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`,
`FTP_REMOTE_DIR` (e.g. `/public_html/wp-content/`).

Use FTPS, never plain FTP — plain FTP sends the password in clear text.

---

## Going live

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
