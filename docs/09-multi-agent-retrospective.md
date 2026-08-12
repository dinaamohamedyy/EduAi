# Running six Claude sessions on one repo — what worked, what didn't

Written for someone who wants to copy the workflow, not admire it. The useful
parts of this project are the failures, so they are here in detail. If you only
read one section, read §6.

---

## 1. What the project is, and the point of it

**EduAi** is a study platform for university students, built as a WordPress site
with a custom theme and two custom plugins. A student signs in, browses a library
of course material, and uses four AI features:

| Tab | What it does |
|---|---|
| **Summarise** | Upload a lecture PDF or PPTX, get a structured summary |
| **AiCalc** | Arithmetic and symbolic maths |
| **Q&A** | Ask questions about the material, or general science |
| **PrepareME** | Upload a lecture → AI writes a banded easy→hard exam → student answers → AI marks it with corrections and a score |

The point is PrepareME. Summarising is commodity; a lecturer's actual deck turned
into a practice exam that then *marks itself with explanations* is the thing a
student cannot get elsewhere. Everything else supports it.

**The architecture that matters.** The four features do not call a model
directly. They call a gateway (`class-eduai-claude.php`) that resolves a
**capability tier** — `strongest` / `balanced` / `fast` — to whatever provider is
configured. The feature asks for "strongest"; it never names a model. Providers
(Anthropic, Groq, Z.ai) are swappable behind that. The key lives server-side
only — there is deliberately no UI field for it anywhere, because the owner is
the one paying for inference, not the student.

**One deliberate non-AI decision worth stealing.** AiCalc does not send `2+3*4`
to a model. It has a hand-written evaluator, and the UI says so on the badge:
*"Computed exactly — code, not a model."* A model that is right 99% of the time
on arithmetic is a calculator that is wrong 1% of the time, which is worse than
useless for a student checking homework. Only symbolic input routes to the LLM.
**Ask what genuinely needs a model and what merely could use one.**

Scale: 24 commits, ~15,500 lines of PHP/JS/Perl, 58 PHP files, 8 design docs.
Runs on Docker at `localhost:8080`. Not deployed anywhere yet.

---

## 2. How six sessions ran at once — the actual mechanics

There is no orchestrator and no framework. Six **Claude Code Desktop sessions**,
each opened separately, each with its own independent context window, all with
their working directory set to the same folder: `D:\chatbot`.

```
                    D:\chatbot   (one filesystem, one git repo)
                         │
   ┌──────────┬──────────┼──────────┬──────────────┬─────────────┐
Tech Manager  QA/Tester  AI Eng.  Back-end dev  Front-end dev  Deployment
 (this one)
```

They talk through a session-messaging tool (`send_message`), which drops a
message into the target session as a user turn labelled with the sender. That is
the entire coordination mechanism. No shared memory, no message bus, no locking.

**Three consequences you must plan for:**

1. **Shared mutable state, no locks.** Every session can edit every file. Nothing
   prevents two sessions writing the same file. In practice this was survivable
   only because roles owned distinct directories — and it did produce collisions.
2. **One git identity.** All six commit as the same user, so `git shortlog` shows
   23 commits by one author. You lose per-agent attribution entirely. Commit
   messages become the only forensic trace, which is a real argument for writing
   them properly.
3. **Cost multiplies, output does not.** Six sessions burn roughly six times the
   tokens. If they are dividing work, you get maybe 3× the throughput. If they
   are duplicating each other's reading, less. This is a genuine expense and the
   owner of this project raised it directly.

---

## 3. Why multiple agents — and which ones actually earned their keep

Honest answer: the six roles were not a designed architecture. They grew, and
they mirrored a human org chart — manager, front-end, back-end, AI, QA, deploy.
That instinct is wrong, and the results show exactly where.

**What paid for itself: adversarial independence.**

Every serious bug in this project was found by a session that did *not* write the
code, and could not see the author's assumptions:

- I wrote an arithmetic evaluator and tested it. **Tech Manager found `-3^2`
  returned `9` instead of `-9`.** My parity fixture had been generated *from my
  own reference implementation*, so it could confirm the two agreed and never
  that either was correct. A separate mind asked the question I had designed
  myself out of asking.
- I wrote a redaction guard. **The deployment engineer found it exited 0 without
  running at all.** Then I found the same defect in a second script.
- I built the four tabs and reported them live. **The owner opened the site and
  saw the old version.** More on that in §5.

**What did not pay: dividing work by role.** Front-end / back-end / UI-UX as
separate sessions mostly produced handoff overhead — one session waiting on
another's contract, duplicated file reading, and merge collisions. They were
*splitting* work, not *checking* each other. Splitting is what a single session
does fine with a task list.

**So the distinction that matters is:**

> Parallelism for **throughput** is mostly a false economy — you pay N× the
> tokens for far less than N× the work, plus coordination cost.
>
> Parallelism for **independent verification** is where the wins are, because an
> author cannot audit their own blind spots at any effort level.

---

## 4. The more efficient shape

If I started this again I would run **three** sessions, not six:

1. **Implementer** — writes the code. Owns the repo.
2. **Adversary** — never writes feature code. Its only job is to try to make the
   implementer's claims false. Given the *artifact*, not the reasoning.
3. **Integrator** — owns the running system: does the deployed thing match the
   claim? Runs setup, looks at rendered pages, checks the live site.

Rules that make it work:

- **The adversary must not read the implementer's reasoning**, only the output.
  The moment it inherits the author's framing it inherits the blind spot.
- **Role by artifact, not by job title.** "Owns the exam schema" beats "back-end
  dev". Titles cause turf and gaps; artifacts have clear boundaries.
- **Spawn for a question, not for a seat.** Most of the six sessions should have
  been short-lived: opened to answer "does the PrepareME round trip work",
  closed when answered. A standing session accumulates stale context (§5).
- **One session at a time touches a given file.** No locking exists. Enforce by
  ownership convention or accept collisions.

For most work, one session plus a genuinely adversarial second is the whole win.
Add more only when you can name the *artifact* the new one owns.

---

## 5. Is running long-lived sessions good? No — and here is the proof

This is the question with the sharpest answer, and it cost this project its worst
moment.

Late in a very long session I reported that all seven tabs were live. I had
verified it: I fetched every route and they all returned **HTTP 200**.

The owner opened the site and replied:

> *"these are the old version are you kidding me ?? wake up and see your job is
> that a joke"*

They were right. The nav still read *Home · Library · Study Assistant · My
Progress*. The title still said *Scholaris*. Four tabs were missing. Every URL
returned 200 because WordPress happily serves an old page.

The root cause is a property of **long sessions**, not of carelessness:

- Early on, I edited `setup.sh` to add the new nav and the rename. True.
- Hundreds of turns later, I still "knew" that. Also true.
- What I had lost was that **`setup.sh` had never been re-run**. The script
  describes a *fresh install*; it does not converge a running one.

A long session accumulates **beliefs that were true when formed and silently
expire**. Worse, when context is compacted, what survives is *the summary of what
you concluded* — not the evidence you concluded it from. The belief gets
promoted to fact, and the thing that would have refuted it is gone.

I had criticised exactly this failure in another session's work a few hours
earlier, and then committed it myself. That is how structural it is.

**What to do instead:**

- Scope a session to a **deliverable**, and end it with verification.
- **Verify in a fresh context.** A new session with no memory of what you
  intended asks "does the page say EduAi?" instead of "did I write the rename?".
- Treat any belief older than the last compaction as **unverified**, especially
  "I already fixed that".
- Long sessions are fine for *building*. They are unreliable for *concluding*.

---

## 6. The failure catalogue — the genuinely transferable part

Five patterns, each of which bit this project more than once. These generalise to
any agent-assisted codebase.

### 6.1 A green check that cannot go red

Four separate instances in one week:

- Two scripts starting `defined( 'ABSPATH' ) || exit;` **before** loading
  WordPress — so they exited with status 0, having run nothing. Silent pass.
- A pre-commit hook committed as an **empty file**.
- A redaction guard wired into **no CI step at all**.

Fix: **before believing a green, ask what would make it red.** Then make that
happen and confirm it does. We added `register_shutdown_function` tripwires so a
script that ends without reaching its assertions fails loudly.

### 6.2 A 200 is not a working page

The `/dashboard/` route returned 200 for weeks while rendering Tutor LMS's login
form — our page had never been created, because Tutor LMS claims that slug on
activation and our page creator was create-only, so it skipped. Eight hard-coded
links pointed there. Registration and sign-in both landed students on it.

Fix: **verify the rendered output, not the status code.** "The route responds"
and "the feature works" are unrelated claims.

### 6.3 Mutate, and confirm the mutation applied

Twice, a `sed` intended to break the code silently did nothing (shell quoting ate
the variable). The check "survived" — and that reading was meaningless, because
nothing had changed.

Fix: **count the target string before and after.** A mutation test that cannot
prove it mutated is not a test.

### 6.4 Substring matching is not equality

A label check passed a **truncated** label, because the truncation was a prefix
of the real string. An assertion counted `eduai-prep__q` twice because it also
matches `eduai-prep__qhead`.

Fix: assert on delimited or whole-string matches.

### 6.5 When the harness has been unreliable, that is not evidence the code is fine

The most dangerous habit to form. The test harnesses here were wrong more often
than the code was, which makes it tempting to shrug off a red result.

The rule Tech Manager wrote, which is the best line to come out of this project:

> Never conclude the implementation is fine **because** harnesses have been
> unreliable. Prove the assertion can see the thing at all, then decide which
> side broke.

### 6.6 The dominant failure: a correct reading of the wrong object

If you take one thing from this document, take this one. It is not a mistake
anyone made through carelessness, it survived review every time, and by the end
of the week four sessions had produced it independently in four unrelated
domains.

The shape is always the same. **The measurement is correct. The instrument
works. The object measured is not the object in question** — and because the
object was a plausible stand-in, nothing about the result looks wrong.

The four:

- **A test that reimplemented the thing it tested.** Verifying another session's
  regex fix, the probe pasted the pattern inline and tested *that*: 28/28, and
  identical had the fix never landed. The file was never opened. Two sessions
  did this within an hour, on the same change.
- **A fixture too small to exercise the behaviour.** Byte-range requests through
  the download handler appeared to work, measured on an 86-byte file. Re-run at
  2 MB, the handler ignores the range entirely and returns the whole file from
  byte 0 — the web server had only been slicing what it happened to have
  buffered. The boundary sits between 1 KB and 64 KB, so every fixture below it
  reports success.
- **A stale description used in place of the tree.** A handoff listed six guards
  as running in no CI job. Five had been wired since it was written; acting on
  the list would have produced five fixes to things already working, each of
  them green and meaningless.
- **An audit that could not see the answer.** Mine, checking that same claim. It
  grepped the workflow YAML for each script name — but CI invokes a wrapper, and
  the wrapper invokes the guards, so anything one level down was invisible by
  construction. Five guards printed `NOT IN ANY CI JOB` while CI ran them
  nightly. The follow-up grep, of the wrapper itself, required a `scripts/`
  prefix the file does not always use, and returned three of eight. Two greps,
  one audit, both confidently wrong, and the output *looked like an audit*.

Why it beats review: everything you can point at checks out. The regex was
right. The range request really was satisfied. The handoff was accurate when
written. The grep did exactly what it said. Reviewing the reasoning finds
nothing, because the reasoning is sound — the referent is what moved.

**What actually caught all four, every time: repeating the measurement at a
different scale, or against the artifact instead of the description.** Not more
care, not a second reader. A different-shaped measurement.

So the practical rules:

1. **Name the thing under test inside the test.** If the probe never mentions
   the function, the file, or the URL it is about, it is testing your copy of
   the idea. The cheapest tell there is.
2. **Vary the scale before believing a boundary behaviour.** 86 bytes and 2 MB
   answered differently. One size is one data point about that size.
3. **Read the tree, never the description of the tree** — handoffs, comments and
   your own earlier notes are all stale by default in a repository several
   sessions are editing.
4. **When a check reports on absence, prove it can see presence.** "Not found
   anywhere" is the answer a broken search gives, and it is indistinguishable
   from the true one until you point the search at something you know exists.

### 6.7 A check that cannot reach the mechanism still returns a result

UI/UX's sentence, and it is the one that lands:

> **A check that cannot reach the mechanism still returns a result, and the
> result looks like evidence.**

§6.6 is about *which object* you measured. This is about whether your instrument
could have detected the thing **at the scale or in the place you ran it**. A
probe below the threshold where the mechanism engages is not a weak test — it is
a test of a different code path, and it returns a confident green.

Four instances, and the first is the one that generalises furthest:

- **Thorough, and blind.** `download-gate.sh` held eight passing assertions
  while members-only files were being served to anonymous visitors. Seven of
  them probe the download handler — copied link, signed out, wrong document,
  forged token, no token — and none fetch the file. The harness *could not*:
  its fixtures never emitted a file URL. Seven probes of one door, none at the
  wall beside it. It now fails 3 of 11 on the real defect with nothing planted.
- **Below the threshold.** A `Range:` request against a download handler
  containing no range code returned a correct `206`, because the web server
  satisfies ranges itself on responses it has fully buffered. The boundary sits
  between 1 KB and 64 KB. At 50 MB with a marker planted at the seek offset, the
  handler returns `200` and the whole 52,428,800 bytes; the marker never comes
  back. Three sessions reached the wrong conclusion from one 86-byte fixture.
- **The wrong container.** An upload limit measured in the `cli` container,
  where `uploads.ini` is not mounted — a real reading of a limit no visitor is
  ever subject to. It misled two separate measurements.
- **Expectations derived from the implementation.** A parity harness generated
  from the reference it validated. Its expectations could not disagree with it.

The distinction worth holding: three of those four could not reach the mechanism
by accident — of scale, of configuration, of construction. The first could not
reach it by **imagination**. Nobody had thought of the file as something you
could simply fetch, so no amount of rigour along the axis they *had* thought of
would ever have found it. Rigour is not coverage; it is depth along whichever
axis already occurred to someone.

The practical form, and the one worth adopting:

> Before wiring a check into anything, ask **whether it can fail on the thing it
> claims to protect** — not whether it passes.

Asked before wiring, that question costs a single run. Asked after, you have
already shipped a green nightly over a live defect. And when a check has never
once been observed red, you have no evidence about it at all — only about the
code paths it happens to touch.

### Bonus: fixtures should be generators, not binaries

The test deck for PPTX extraction was a `.pptx` — a zip. Opaque in review,
undiffable, its traps invisible. It lived in one container's `/tmp` and no one
else could find it. Replaced with a ~120-line PHP script that *builds* it, with
each trap documented in source: the speaker note stored as `notesSlide1.xml`
while belonging to slide 2 (which is what PowerPoint genuinely writes), a fact
present only in the notes, and a `slide10.xml` that string-sorts before
`slide2.xml`. Output is deterministic; the traps are readable.

---

## 7. What to steal for your own workflow

**Process:**

1. Run a second session whose only job is to falsify the first one's claims.
   Give it artifacts, not reasoning.
2. End every session with verification in a **fresh** context.
3. Write commit messages explaining *why*, not *what* — with a shared git
   identity across sessions, they are your only audit trail.
4. Keep a living handoff doc split into **what is proven by running it** vs
   **what is merely written**. This project's `docs/08-handoff.md` does that, and
   it is the first thing any new session should read.
5. Distinguish "I wrote it" from "it is deployed". They are separated by an
   action someone has to actually take.

**Technique:**

6. **Contract tests** — cheap scripts asserting cross-file consistency (does the
   route in the docs match the route in the code, does the JS label match the PHP
   label). 14 of them here; they catch the drift that unit tests never see.
7. **Tripwires** on any script that could exit early without asserting.
8. **Deterministic code over model calls** wherever correctness is checkable.
9. **Capability tiers, not model names**, so swapping providers is config.
10. When something must be exactly right, find the check the *artifact itself*
    can satisfy. Best example here: to prove exam answer indices were 0-based, the
    tester answered every question with the paper's **own** answer key and
    required 7/7 — a 1-based paper cannot mark its own key correct. That is
    stronger than any inspection.

**Anti-patterns:**

11. Don't spawn agents by job title. Spawn them for artifacts or questions.
12. Don't let a session run indefinitely and keep issuing conclusions.
13. Don't accept "all checks pass" without knowing what turns them red.
14. Don't put an API key in a file, ever, and don't ask an end user for one that
    the product owner should be paying for.

---

## 8. Where the project actually stands

Honest status, because a summary that overstates it would repeat §5's mistake:

- Seven tabs serve on the local stack. Summarise, AiCalc and Q&A are proven
  against a live provider.
- PrepareME's full round trip — generate → answer → mark — is proven from pasted
  text: schema-valid on the first call, correct band mix, all marking rules
  respected. **From an uploaded file it is still unproven**, which is the half
  that fails silently.
- Five guard scripts need a live WordPress and therefore run in **no** CI job. A
  guard that never runs is a guard that passes forever (§6.1).
- **Nothing is deployed.** It works on one machine, while Docker is running.

---

*See `docs/08-handoff.md` for engineering-level state, `docs/06-eduai-rebuild.md`
for the product spec, and `docs/07-prepareme-contract.md` for the API contracts.*
