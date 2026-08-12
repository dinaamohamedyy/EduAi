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

**The collision you will actually hit, and why it is not carelessness.** Three
sessions edited one file within an hour, each believing it unowned. The manager
mentioned a gap without saying it was assigned; a second session picked it up
without asking; the third assumed ownership because they had written the
original. That third session had been giving other people the "a quiet file is
not an unowned file" advice all week, and the second had it written in their own
notes — and neither rule fired, because **nothing prompted the question.**

One of them sharpened it afterwards, and the sharper version is the one to keep:
they were told the file had been reassigned **in the same message** that
corrected their verdict, and edited anyway, because they had already decided it
was theirs. **Being told is not the same as having heard it.** Information
arriving alongside a correction competes with the correction for attention, and
loses.

That is a mechanism failure, not three lapses. The map lived in the manager's
head while messages crossed faster than it could be relayed, so "is anyone in
this file?" had no answer except asking, and asking requires already suspecting.

Three things helped, in increasing order of usefulness:

- **Stand down rather than sweep.** When a file is moving under you, hand your
  hunks over as a specification and stop. Ours survived to be built on precisely
  because nobody committed a 289-line file of mixed authorship.
- **Disclose what rode along.** `git commit <paths>` takes whole files, so a
  co-edited file carries the other session's hunks whatever you do. Read
  `git diff --numstat --ignore-all-space` against what you know you wrote, then
  say what came with it.
- **A written claim beats a remembered one** — with the caveat the manager put
  in the file itself: a hand-maintained ledger is exactly the artefact that once
  sent an engineer to wire five already-wired guards. It is a lookup, not an
  authority. Verify against the tree before acting on it.

**Why the tooling cannot help you here, which is the root of all of it.** Every
session commits under the same git identity. `git log --format=%an` returns one
name for the entire repository, so git *cannot* answer "who wrote this" — and
`git blame` is actively misleading, because it looks authoritative and is not.

Three attributions had to be settled by asking, in one day. One of them was a
credit handed to the wrong session, offered in good faith and nearly accepted by
silence. That leaves exactly one durable record of who wrote what:

> **The commit message is the only authorship metadata that exists.** Write it
> as though it is the sole surviving evidence, because it is.

The same gap explains the ownership collision above. The tooling that would
normally answer *"who owns this file"* and *"who wrote this line"* answers
neither, so both questions fall back on memory and on messages — and messages
crossed seven times in one day between two sessions alone.

**And one failure mode no check can catch: committing is not landing.** Twice in
a day, a commit sat on the local tree and was never pushed — one of them a fix
for a real defect, one a CI hardening. Every guard in this project runs on push,
so an unpushed commit is invisible to all of them. Nothing detects it. Only the
habit does.

**When a documented lesson recurs, that is evidence about the medium, not the
reader.** These harnesses inject JavaScript as a string, so escapes are
processed twice: `\s` inside a template literal is not a recognised escape and
silently degrades to the letter `s`, turning a whitespace regex into a letter
regex. One probe reported a heading as `"Cour e Categorie"`. That was the
**third** occurrence on this project, and it was already written down after the
first.

The instinct is to document it harder. That instinct is wrong — it had been
documented, by people who then hit it anyway, including the person who wrote the
note. It is the same shape as the ownership collision above: *a rule you have to
already suspect you need is not a rule that fires.*

The remedy for a recurring lesson is mechanical, not editorial:

- a shared helper that builds injected JS with `String.raw`, so the escape is
  handled once in one place rather than remembered at every call site; or
- a check that flags `\s`, `\d` or `\w` inside a template literal in injected
  JS, so the third occurrence is caught by a machine rather than by a person
  reading their own output carefully enough to notice a missing letter.

Two occurrences is a lesson. Three is a design problem — and the count is the
signal that the write-up was the wrong intervention.

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

Five instances, and the first is the one that generalises furthest:

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
- **Arithmetically incapable.** `scrollWidth - window.innerWidth`, the overflow
  criterion in every layout harness here, run under mobile emulation. The layout
  viewport *widens to fit overflowing content*, so both terms move together and
  the difference is zero for any overflow that exists. A 900px box planted at a
  420px viewport measured scrollWidth 900, innerWidth 900, difference 0, with
  nothing clipping; `documentElement.clientWidth` stayed 420 and reported 480.
  It had been certifying "no horizontal overflow on any of 14 screens at 420px"
  while the signed-in header ran 10px over at 420 and 110px over at 320, on
  every screen. The same expression is quietly wrong at desktop too, where
  innerWidth includes the scrollbar and clientWidth does not: a clean page reads
  `-15`, so up to 15px of real overflow scores as negative.

The distinction worth holding: four of those five could not reach the mechanism
by accident — of scale, of configuration, of construction, of arithmetic. The
last of those is the cleanest specimen in the set, because it needs no accident
at all: the expression is *identically zero* wherever the emulation applies, so
the check could not have been right on any run, on any page, from the moment it
was written. A test that fails sometimes is flaky; a test that cannot produce a
failing value is decoration. The first could not reach it by **imagination**. Nobody had thought of the file as something you
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

**The stronger version, which three people reached independently.** Mutating a
check once proves it *could* fail on the day someone checked. It says nothing
after the refactor that quietly breaks the probe. So build the control into the
check and re-prove it on every run:

- The answer-key guard searches the **raw, unprojected** exam first and refuses
  to believe any "absent" result unless the answers were found there. A mistyped
  probe, a renamed field or a silently non-matching search turns the run red
  instead of green.
- The harness wrapper declares a manifest and reconciles against it, so a
  harness that emits nothing is fatal and named. Deleting one call produced
  `0 failed, 7 passed, exit 1` — a clean run turned red by an absence alone.
- The rule that generalises both: **when a check reports absence, prove it can
  see presence first.** "Not found anywhere" is also what a broken search says.

Prefer a built-in known-positive control. Mutate only where one cannot be
constructed.

**The case that settles it is one no review could have caught.** Two sessions
independently fixed the same real defect in a leak detector: the answer texts
were raw UTF-8 while the payload was JSON-escaped, so three of ten probes could
not match and those answers could have leaked in full under a green "none appear
verbatim". One session escaped the needle; the AI engineer un-escaped the
haystack. **Either fix works alone. Applied together they cancel**, and the
detector went blind again.

Neither diff was wrong. No reviewer reading either change would have found
anything to object to, because the defect existed only in the *pair*. What
noticed was the control asserting that the probes must fire on known-positive
data — an assertion about the test's own machinery, with nothing to do with the
product.

The surviving mechanism is the AI engineer's — *attributed by assignment and by
their own report, not confirmed by the tree*, for the reason two paragraphs
below — and their reason for keeping it is the general one:

> Narrower is the wrong direction. Being broad costs a false positive somebody
> investigates; being narrow costs an answer key on the wire that nothing
> reports.

**Not everything that encodes should be normalised, which is the trap.** The
same file makes three deliberately *inconsistent* encoding calls, in the stub
that impersonates the model's response. Those are an **input** to the code under
test, not a haystack to search — normalising them for consistency would have
made the stand-in less faithful than the thing it stands in for. Two of us
missed that; the third caught it. A tidy-up justified by symmetry is how a
fixture stops resembling production.

A mutation performed once could not have caught this. It proves a check could
fail on the day someone checked; it says nothing about what a second correct
change does to it next week.

**And when a mutation is unavoidable, its breadth is the whole result.** The
tester who had used the technique most found the limit in their own verdict, and
stated it against themselves:

> I planted at the head of the function — which kills the projection on *every*
> route at once. A guard watching any one route catches that. So the plant
> confirmed exactly what a whole-function plant can confirm — that the detector
> fires — and was structurally incapable of revealing which routes it watches.
>
> **A broad mutation proves less than a narrow one.** Breaking everything gets
> caught by a guard that watches almost nothing. The mutation has to be as
> narrow as the coverage question you are actually asking, and mine was shaped
> like the answer I already believed.

That is why the same guard passed one person's mutation and failed another's.
Stripping the projection from *one* route, leaving the others intact, gave the
blind spot nowhere to hide — and the guard turned out to watch one route of
four while reporting twelve green assertions.

This closes the arc §6.6 opens. There, the instrument could not reach the
mechanism. Here it reaches it perfectly and is too **blunt** to distinguish
which part of it responded: a correct positive that proves far less than the
reader assumes. Both are the same underlying failure — *an instrument that
cannot discriminate between the hypotheses returns a confident result about
neither.*

**One practice worth copying from the same episode.** When the redundant
mechanism was finally removed, the commit recorded that the removal was
deliberate and why. Without that, the absence is indistinguishable from an
oversight, and the next careful reader restores it. That is §6.9's disease in a
third place: knowledge that exists only as an absence has to be written down
somewhere, or it decays back.

### 6.8 The assertion is sound; the input stopped posing the problem

Distinct from §6.7, and the distinction is worth keeping. Those checks could not
*reach* the mechanism. These reach it perfectly well — and pass because the
thing they were pointed at quietly shrank.

The Tester's three, from one sweep, one shape in three disguises:

| Guard | What shrank | What it still reported |
|---|---|---|
| `download-gate` | no way to name the file | thorough about the door, blind to the wall |
| `grade-adversarial` | the planted lie made legal | "the rule neutralised this" — with nothing to neutralise |
| `submit-contract` | the required-key list shortened | "all N keys present", where N had shrunk |

The clamp case is the cleanest. A crafted response claimed 99 marks on a 2-mark
question; someone changed the 99 to a legal 2, and the harness reported:

```
ok    awarded clamped to the maximum (q3, worth 2, model said 99)
```

Green, comparing 2 against 2, exercising the clamp not at all — **and the label
still said "model said 99", because the label is a string.** Seventeen
assertions, no coverage.

That last clause is the transferable part:

> **A test's label is documentation, and documentation drifts from the data
> silently.** "model said 99" was true when written and false afterwards, and no
> run would ever say so. It is a comment outliving the code it describes, except
> it is wearing a green tick.

`submit-contract` is the same disease as a parity harness generated from its own
reference: the count came from the list's own length rather than from the twelve
keys the contract names, so deleting one key produced `ok: all 11 keys present`.
Correct arithmetic about a diminished subject.

**The mirror image — a control that was not a control.** Two arms of a test
were compared, `/wp-admin/` with an admin cookie and without, and both returned
identical redirects. The conclusion — that the redirect was unconditional —
would have killed an entire architecture. It was an auth gate all along: the
minted cookie lacked the `/wp-admin`-scoped one, so **both arms were
unauthenticated**. The comparison was sound and the two things compared were the
same thing.

> **Agreement between two arms of a test is only informative if the arms
> actually differ.**

Both failures answer to the same discipline, and it is §6.7's rule pointed at
the input rather than the instrument: **prove the probe can distinguish, before
believing what it distinguished.** Assert that the planted defect really
violates the rule. Assert that the two arms really differ. Assert that the
iteration labelled "fresh" really took the fresh path — I wrote that exact
defect into a guard twenty minutes after reading this section's source, labelled
two iterations `fresh` and `reused` off a constant input where both took the
reuse path, and caught it only because the finding was still in front of me.

### 6.9 The check knew. The plumbing dropped it.

Every failure above is a check that could not *see* something — by scale,
configuration, construction, imagination, a filter, a shrunken target, an
unverified label. This one sees perfectly, diagnoses correctly, phrases it
better than a person would, and then throws the result away at the reporting
boundary.

The page-drift guard, mutated three ways:

| mutation | expected | actual |
|---|---|---|
| a page set to draft | fail | exit 1, names the missing page |
| copy reworded, shortcode intact | warn only | exit 0 |
| **shortcode stripped entirely** | fail | **exit 0** |

With the shortcode gone the Library renders nothing at all, and the harness
prints exactly the right sentence:

```
LIKELY BROKEN: [scholaris_library] not present on the page,
               so that feature renders nowhere
```

…and exits 0. The exit code derived only from missing pages and missing ledes;
the array holding that diagnosis never reached it. In a nightly job — which
reads exit codes, not prose — that sentence is written to nobody.

**The closing detail is the whole argument.** That guard's own docblock cites
the incident it was written for: a page sitting on another plugin's login form
for days, with the shortcode registered and rendering nowhere. **The guard
written to catch that exact failure would not catch it today.** Nothing makes
the point better than a check whose motivating example it can no longer detect.

**It pairs with the manifest fix, which is the same disease one level up.** A
skipped harness contributes no output line, so counting passes is structurally
incapable of noticing an absence — deleting one call produced `0 failed, 7
passed, exit 1` only once the wrapper declared what it owed and reconciled
first. Here, a computed warning contributes no exit code, so reading exit codes
is structurally incapable of noticing a known break.

Both are **the reporting layer losing information the check already had**. That
is a different question from "can this check fail", and it needs asking
separately:

> Everything this check can conclude — does *all* of it reach the caller? Or
> does some of it end in a variable nobody reads?

The remedy generalises: declare what you owe before you start, and reconcile
against that declaration before emitting a verdict. A verdict computed only from
the paths someone remembered to wire is a summary of the wiring, not of the
system.

### 6.10 The frame is wrong, so the orphans are invisible by construction

The last one, and the only case where the check is **never wrong about anything
it examines** — and still misses the thing that matters.

A file-protection indicator was built keyed on "is this material's file
secured?". Correct per render, derived from the real state, cannot drift. Then
the live site was measured, and the owner's own 4.6 MB PDF was **publicly
downloadable and publicly indexed** — because it had been uploaded straight into
the media library and attached to no material at all.

The indicator cannot help. There is no material to ask about, no editor screen
for it to appear on, nowhere for a label to live. The author's framing is the
transferable part:

> It is the **frame** being wrong rather than a state being missed — the control
> reasons per material, so anything without a material is invisible to it *by
> construction*. The next feature that reasons per-material will have the same
> blind spot for the same reason.

**The tell is cheap and worth making a habit.** Ask what the check's *subject*
is, then ask whether the thing you are worried about always has one:

> Files always exist. Materials do not. Anything keyed on the material misses
> the orphans — silently, permanently — and because the check is never wrong,
> nothing ever fires.

**It recurred within the hour, from the opposite direction**, which is what
makes it structural rather than an oversight. A fix for the upload window could
not route through the material-shaped reconciliation at all: at upload time the
material does not reference the file yet, so the material-shaped rule concluded
there was nothing to move. It had to be re-keyed on the **attachment**. Same
frame problem, found independently, fixed by changing what the rule is *about*
rather than by extending what it checks.

**And its epistemic sibling, which belongs here rather than in §6.9.** The new
indicator's column was empty on first inspection — because everything happened
to have been edited that day, not because any sweep had run. *Empty because
clear* and *empty because unable to tell* are the same pixels with opposite
meanings, and only knowing which distinguishes them. A reader who cannot tell
will reasonably conclude the column is redundant and remove it.

That is §6.9's disease in a display rather than an exit code: **the absence of a
signal is not a signal, unless something says which kind of absence it is.**

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
