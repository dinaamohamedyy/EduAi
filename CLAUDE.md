# CLAUDE.md — standing instructions for Claude Code in this repository

EduAi: a WordPress learning platform. A library of lecture material, quizzes,
and 4 AI study tools (Summarise, AiCalc, Q&A, PrepareME). `README.md` describes
the product; this file is only what an agent needs before touching it.

Codex reads `AGENTS.md`. This file is the Claude equivalent. Keep both true.

## Run it before you believe it

The stack runs locally: WordPress on <http://localhost:8080>, Mailpit on :8025.

Every real bug in this project has been invisible to static checks. A grep that
finds a class name is not proof a page renders, and a status code is not proof
a feature works. Open the page.

The most expensive mistake made here repeatedly is checking a **label** instead
of the **behaviour** — reporting a fix because the right text appeared in the
markup, while the button next to it was still broken.

## The shared working tree

Several sessions edit this one tree at once, under one git identity, and Codex
sessions do too now. So:

- Commit with an **explicit pathspec**. Never `git commit -a`.
- Read `git diff --numstat` before committing. If a file you did not touch has
  changed, someone else is mid-edit — leave it.
- Never reset the tree. Never delete a git lock file.
- `AGENTS.md` carries Codex's version of these rules; they are the same rules.

## Never write an API key into a file

The key is server-side only: `wp-config.php` constants or the environment.
There is no UI field for it anywhere, by design. The owner supplies his own key.

Root `preview.html` holds a live key and is gitignored; `design/preview.html`
is the shippable key-free copy. Run `perl scripts/check-no-secrets.pl` before
packaging anything.

## Gates to run before you commit

```
perl scripts/php-sanity.pl        # brace/heredoc smoke test over every PHP file
perl scripts/contract-tests.pl    # cross-file contract checks
perl scripts/check-no-secrets.pl  # no live keys in anything shippable
```

CI runs these on every pull request. They are a merge gate, not a habit.

## Things that will mislead you

**The LMS is not in this tree.** `wp-content/plugins/` holds only
`eduai-assistant`, `eduai-enquiry`, `scholaris-library`. LearnDash was installed
through wp-admin into the container volume — it is live on :8080 and invisible
to grep. Reading the tree suggests the site has no LMS. It has one.

**Never name a post type literally.** Ask `EduAI_LMS`
(`plugins/eduai-assistant/includes/class-eduai-lms.php`). Hardcoded `'lesson'`
and `'courses'` took the AI features down twice during the LearnDash migration,
silently — no error, just nothing happening.

**Two login URLs, not interchangeable.** `wp_login_url()` is where you send a
*person*; `site_url('wp-login.php','login_post')` is where a form *posts*. POST
credentials to the first and WordPress re-renders the page with a 200 and
nobody signed in. `docs/05-frontend-handoff.md` has the detail.

**`setup.sh` bootstraps a fresh install, not the running one.** Five bugs so
far have come from editing it and expecting the live site to change. Re-run it,
then look at the page.

**`inc/retired-routes.php` is deliberately not loaded**, whatever `setup.sh`'s
comments say.

## Where the documentation is

Read the one that matches the task; do not read them all.

| File | Covers |
|---|---|
| `docs/01-setup-guide.md` | install and first run |
| `docs/05-frontend-handoff.md` | routes, auth seam, design tokens |
| `docs/07-prepareme-contract.md` | PrepareME, AiCalc and Summarise wire shapes |
| `docs/09-multi-agent-retrospective.md` | how this project's failures actually happened |
| `docs/14-learndash-conversion.md` | the Tutor → LearnDash migration surface |
| `docs/15-tm-to-gm-handover.md` | who owns what now, and what is genuinely open |
| `docs/16-qa-handoff.md` | every route, limit and error message, with line numbers |

## Reporting

Say what you verified and how. If a test failed, show the output. If you
skipped a step, say which. Do not report a task complete on the strength of a
check that could not have detected the failure.
