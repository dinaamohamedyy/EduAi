# UI design proposal — the four AI tabs, the header, and the signed-out door

Design response to the Tech Manager's brief of 10 Aug 2026. **Proposal only —
nothing here has been implemented.** Routing is the Tech Manager's call.

Scope discipline: nothing below changes an endpoint, a payload, a prompt or the
exam schema. `docs/07-prepareme-contract.md` and the agent definitions are
untouched. Where a change needs plugin PHP it is markup and copy, never
response shape. One item (§3) needs a new argument on an internal PHP method;
that is called out explicitly.

**What I verified and what I did not.** Everything about CSS, templates and
markup below is read from source and from the live site *signed out*. I could
not observe the signed-in rendering: minting a session cookie was blocked by
the permission classifier, and I will not type a password into a form. So the
admin-bar geometry is taken on the Tech Manager's diagnosis, and the header
arithmetic in §4 is **computed from the stylesheet, not measured in a browser**.
It should be confirmed with a ruler before anyone trusts the exact pixel
figures — the conclusion (there is a range with no valid layout) does not
depend on them being exact.

---

## The finding in one paragraph

The four new tabs are not off-brand. `chat.css` aliases the theme's colour and
font tokens properly, so they inherit laurel, bronze, paper, Fraunces and Inter
automatically, and they degrade gracefully on a foreign theme. **That part of
the architecture is correct and must be preserved.** What was never aliased is
everything colour and type don't cover — spacing, measure, radius, body size —
and that is precisely the layer that carries "considered" versus "assembled".
The original pages and the new ones use the same palette on a different grid.
That is why the product looks damaged in a way nobody could point at.

---

## 1. The four tools are a second design system

`chat.css` line 11–27 aliases eleven colour tokens and three font tokens from
the theme, each with a hard-coded fallback. Nothing else is aliased. The
consequences are measurable:

**Spacing is off the scale.** The theme's space scale is
`0.25 / 0.5 / 0.75 / 1 / 1.5 / 2 / 3 / 4 / 6rem`. The tools use `0.9rem`,
`0.85rem`, `0.65rem`, `0.6rem` — values on no scale at all. Every gap in the
four tabs is a near-miss against the rhythm of every other page. This is the
single largest contributor to the "assembled" read, and it is invisible in a
screenshot of any one page; you only feel it moving between tabs.

**Four sibling tools have four different content widths.**

| Tool | Width | Behaviour |
|---|---|---|
| AiCalc | `max-width: 46rem` | flows down the page |
| Summarise | `max-width: 52rem` | flows down the page |
| PrepareME | `max-width: 54rem` | flows down the page |
| Q&A | fixed `608px` tall box | internal scroll, own chrome |

Three arbitrary measures plus one that isn't a measure. Tabbing between them,
the text column jumps every time. None of the three matches the theme's
`--wrap-narrow` (760px / 47.5rem).

**Radius and body size drift too.** `border-radius: 7px` on `.eduai-tab` and
`12px` in several places, against a scale of `4 / 8 / 12 / 16 / 22`. `.eduai-app`
sets `font-size: 0.95rem` where the theme's body is `--step-0` (0.98–1.06rem
fluid) — Q&A renders in slightly smaller text than the rest of the site.

**The component vocabulary is a subset.** `.eduai-btn` has two variants against
`.btn`'s seven. `.eduai-card` re-implements `.card`. The tools have no access to
`.badge`, `.stat`, `.meter`, `.notice`, `.empty-state`, `.eyebrow` or
`table.data` — so where the theme would show a `.notice--error`, the tools show
a bespoke `.eduai-error`, and where PrepareME's marked report wants a ledger
tile and a progress meter, it invents its own. The theme already has exactly
the two components a score wants.

### What I would change

Extend the existing alias block rather than replace the approach — the
fallback pattern is what keeps the plugin alive on a foreign theme, and it
should stay.

1. Alias the **space scale** (`--eduai-space-2xs` … `--eduai-space-l`), the
   **radius scale**, and **`--eduai-step-0`** the same way the colours are
   aliased, each with its current hard-coded value as the fallback. Then
   replace the raw rem values with them. Mechanical, and it can be done in one
   pass with no visual decisions.
2. Introduce **one `--eduai-measure`** and give all four tools the same content
   width. I propose **52rem**, matching Summarise: 46rem is too narrow for
   PrepareME's four-option MCQs, and 54rem is past a comfortable reading
   measure for the summary prose.
3. Port `.stat`, `.meter`, `.notice` and `.empty-state` into `chat.css` as
   `eduai-`-prefixed aliases. Four components, and they unlock §3 and §5.

---

## 2. Q&A is still a widget

`.eduai-app` is a bordered, shadowed, `overflow: hidden` card, 608px tall, with
its own dark brand-coloured header bar (`.eduai-head`, background
`--eduai-brand-strong`), its own avatar, its own status dot, and its own
internal tab strip. On `/ask/` that box sits underneath a page `h1` and a
breadcrumb that already say where you are.

The widget was retired as a floating launcher (docs/06 §3) but its *shape*
survived onto the page. So the tab reads as an embed of another product, while
its three siblings read as pages. This is the most visible break in the "one
family" requirement.

**What I would change.** On `/ask/`, drop the shell: no outer border, no
shadow, no `.eduai-head` bar, no fixed height. The conversation flows down the
page and the composer sticks to the bottom of the viewport. Keep `.eduai-app`
exactly as it is for `[eduai_panel]` embedded elsewhere — the homepage hero
uses it and existing embeds must not break. This is a modifier
(`.eduai-app--page`), not a rewrite.

**Correction to an earlier draft of this section.** I wrote that the page
"should pass" `tabs="chat"` to drop the redundant internal tab strip. It
already does: `setup.sh:149` creates `/ask/` as
`[eduai_panel height="600" tabs="chat"]`, and `panel.php:31` honours it with a
comment giving the same reasoning I did. That part was already handled. What
remains for this item is only the shell — border, shadow, fixed height and the
`.eduai-head` bar.

Worth noting what that `height="600"` becomes: `.eduai-app--inline` computes
`calc(var(--eduai-body-h) + 128px)`, so `/ask/` is a **728px fixed-height box**
with internal scroll, on a page that also scrolls.

---

## 3. The signed-out card is the most-seen screen in the product

This is what a visitor gets today on `/calc/`, in full:

```
Home / AiCalc
AiCalc
    Sign in to use the assistant
    The study assistant is available to registered students.
    [ Sign in ]
```

`EduAI_Shortcodes::login_card()` takes no arguments and is called from all four
shortcodes, so **AiCalc, Summarise, Q&A and PrepareME are identical when signed
out** — and the copy describes none of them. Someone who clicks "AiCalc" to
find out what AiCalc is gets a page that never mentions calculation. Four
products, one door, and the door is generic.

The irony is that the good per-tool copy already exists in the same file — the
i18n arrays carry "Please sign in to use the calculator", "…to use PrepareME",
"…to use the summariser". It was written for the session-expiry state and never
reached the front door.

**What I would change.** Give `login_card()` a tool identity: the tool's own
name, one sentence of what it does, and a glimpse of what it produces. The
theme's `.empty-state` and `.cta-band` exist for this and are already designed.
Signed out, `/prepare/` should show its three-step flow (`.eduai-prep__flow` is
already built and needs no data) with the sign-in call under it; `/calc/`
should show its example chips. Both are static markup — nothing hits an
endpoint, nothing leaks.

**This one needs back-end**: `login_card()` gains a parameter and each caller
passes its own strings. Internal method signature, no endpoint or payload
change.

Two smaller things on the same screen: `wp_login_url( get_permalink() )` is
already passing a correct return path, so a student who signs in from `/calc/`
lands back on `/calc/` — worth keeping and worth saying in the copy ("you'll
come straight back here"). And the card offers no route to *register*, only to
sign in, which for a first-time visitor is the wrong of the two.

---

## 4. Seven tabs — SETTLED, and there was no width range to fix

> **This section was wrong twice and is kept only as a record of how.**
> Superseded 10 Aug 2026 by front-end's measurement after their fix landed.
>
> **What was actually broken:** flex min-content shrinking split the label
> "My Progress" onto two lines **inside its own link box**. Not the row running
> out of room — one label wrapping. It reproduces at any squeeze, including
> **1400px when signed in**, where the extra header action tightens things.
>
> **The fix** is `white-space: nowrap` on `.nav a` (`main.css:460`), which
> removes the failure mode rather than avoiding it. Measured afterwards, signed
> in, with real geometry probes: **the seven-tab row fits down to 905px with
> 24px clearance on both sides** — the fluid type scale absorbs the squeeze.
>
> **The existing 900px toggle breakpoint is correct and stays**, now by
> measurement rather than by luck. My ~1150px estimate and the Tech Manager's
> 940px were both symptoms of the label bug; changing the breakpoint to either
> would have papered over it while leaving it live at wider widths for
> signed-in users, and 940 would have hidden a provably-fitting nav from every
> viewport between 901 and 940.

**The seven-tabs question is answered: no compromise was needed.** The
recommendation below — don't hide the tools behind a dropdown, because they
*are* the product now — stands, and for a better reason than I had. The row
genuinely fits at every width where it is shown. All the crowding we were
designing around was one unwrapped label.

The original arithmetic is deleted rather than kept. It reached "there is a
problem" through a chain of estimates that were individually wrong, and leaving
it in invites someone to re-derive a breakpoint from it.

### Item 0 — the live menu has drifted from the script

The Tech Manager found the widest item on the bar is not what we ship. The
running site's nav reads **"Summarise a Lecture"** — 19 characters — where
`scripts/setup.sh:142` creates the page as **"Summarise"** and docs/06
specifies "Summarise" twice. I confirmed the script side: line 142 is
`make_page "Summarise" "summarise" "[eduai_summarizer]"`.

So the live menu title was edited away from spec, in the one dimension
`page-drift.php` does not cover. It is a spec-compliance fix and it stands on
that alone — the "buys ~60px of headroom" argument I attached to it is void,
since there was no headroom problem.

### The other three findings, and what survived

**The brand tagline — my premise was wrong.** I wrote that hiding it below
~1100px "reclaims ~350px and costs nothing", treating it as a fit lever. It is
not one. `.brand__tag` is `display: block`, so it stacks under the brand name,
and as a shrinkable flex-item child it yields under pressure rather than
forcing the row wider. It never gates whether the header fits. **Any change
here is aesthetic and must be argued on aesthetic grounds** — see §4.1 for the
one mechanical point that does survive.

**"My Progress" is in the header twice when signed in.** This one still holds.
`header.php:61` renders a "My progress" button in `.header-actions` for
logged-in users, and the nav carries "My Progress" as its seventh tab. Same
destination, same header. It is now a redundancy argument only, not a fit
argument — the row fits either way — so it is worth doing for clarity and is
not urgent.

**The toggle breakpoint — wrong, twice.** I said 900px was "too late" and
should move to ~1100px. Measured, 900px is correct and stays.

**What survives.** The recommendation against hiding the tools behind a
dropdown, for the reason given above. The two-tier header idea is **withdrawn**:
it was contingency for crowding that does not exist, and building it would add
a row of chrome to solve nothing.

### 4.1 The tagline wraps on every phone — and `nowrap` is the wrong fix

`.brand__tag` has no `white-space: nowrap` and no `max-width`. Under horizontal
pressure the wrapper collapses toward min-content and the tagline wraps.

Measured by the Tech Manager, at 375px:

```
lines rendered   3        (49px against a 16px line-height)
header height    80px     against min-height: 72px
headerGrewBeyondMin: true
```

Three lines, not the one I guessed, and the header is **8px taller than
designed on every phone viewport**. Live, not latent. The wrapper measures
**360px against the brand name's 59px**, confirming the tagline is what sets
that flex item's automatic size.

**Do not fix this with `white-space: nowrap` alone — that makes it worse.** My
earlier draft suggested exactly that, by analogy with `.nav a`. The analogy
breaks: `.nav a` labels are short, and a 360px tagline held on one line inside
a 375px viewport overflows horizontally. Brand mark (38px) + gap + 360px is
roughly 406px against ~343px of usable width, and nothing in the header sets
`overflow: hidden` — so `nowrap` trades 8px of extra height for a horizontally
scrolling page on every phone. That is a worse defect and a more visible one.

**The fix is to hide it where it cannot fit.** That half is right and shipped:

```css
@media (max-width: 900px) { .brand__tag { display: none; } }
```

### 4.2 The `nowrap` half was wrong too — measured and rejected

I proposed pairing that with `white-space: nowrap` above the breakpoint, to
stop the tagline wrapping where there is room. **Front-end measured it and it
is wrong**, and the shipped `main.css:451–461` now carries an explicit warning
not to "complete" the fix by adding it.

Measured 10 Aug 2026, signed in, `nowrap` injected: the one-line 360px tagline
beside the seven-tab row **fits at 1380px and breaks at 1370px**, failing by
horizontal document overflow — **358px of it at 950px**. So `nowrap` above the
toggle would put a sideways-scrolling page on nearly every laptop, which is the
same defect I had just corrected at phone widths and did not think to check in
between.

**Between 900px and ~1380px the tagline wraps to two lines and `min-height`
absorbs it. That is the designed behaviour, not a defect.**

This was my third geometry error of the same kind, and the most instructive:
having been caught reasoning from source about 375px, I checked 375px, fixed
it, and asserted the rest of the range from the same armchair. Narrowing the
scope of an unmeasured claim does not make it measured. The floor is 1370–1380
because someone ran it at every width, not because anyone reasoned about it.

---

## 5. PrepareME's report should use the components that already exist

Smaller, but it is the flagship. The marked report renders its score with
bespoke markup while the theme carries `.stat` (the ledger tile, designed for
exactly a figure with a label) and `.meter` (designed for exactly a proportion).
Using them makes the report look like it belongs to the same product as the
dashboard, which is where the student sees their other scores.

Two correctness details from docs/06 §2.4 that are design-visible and must
survive any restyle — both are ship gates, not preferences:

- A full-marks short answer comes back with `correct: null`, not `true`. Any
  styling pass that adds a "correct" state to short answers risks
  reintroducing `if (r.correct)`, which stamps a ✗ on an answer that scored
  full marks.
- Marks are floats (`2.5 / 4`). Tabular figures, and no rounding in display.

### The two renderers disagree, and the spec needs one answer

Specifying this item surfaced a divergence that should be settled before
anyone restyles the report. **Both renderers are safe** — neither reads
`correct` for short answers, so the dangerous failure is absent from both. They
disagree on presentation:

| | full-marks short answer |
|---|---|
| `design/preview.html` (the demo) | **no glyph at all** — its three `mark` lines are all in the MCQ branch |
| `prepare.js` (what ships) | **`✓`** — tone derived from `awarded >= of`, documented at line 352–357 |

docs/06 §2.4 describes the demo's behaviour ("full-marks shorts carry no ✗
*and* no ✓ … their absence on shorts is deliberate") and the shipped tab does
the other thing on purpose.

**My recommendation as designer: keep what `prepare.js` does, and correct
docs/06 to match.** Withholding the tick to signal "a model marked this, not
code" is a distinction that serves our architecture, not the student — they
got it right, and the report should say so. The demo's rule is also actively
ambiguous: a short answer with no glyph is indistinguishable from one that
failed to render.

The safety property is real but it is not "no ✓". Stated precisely, and this
is what a harness should assert:

> **Short-answer tone is derived from `awarded` against `of`, never from
> `correct`. A short answer at full marks must never render `✗`.**

`prepare.js` already enforces that structurally by never reading `correct`
outside the MCQ branch. This is a docs correction and a test-assertion
wording change, not a code change — but it touches a normative document with
live harnesses behind it, so it is the Tech Manager's to route, not mine to
edit.

---

## Proposed order

1. **Header** — done. Not as proposed: the fix was `white-space: nowrap` on
   `.nav a`, the 900px toggle stayed, and the tagline is hidden below 900px
   with `nowrap` deliberately rejected. See §4.
2. **The signed-out door** (§3). Highest ratio of people-who-see-it to
   effort, self-contained, and it is the screen the owner's visitors meet
   first.
3. **Token aliasing and one measure** (§1) — **promoted ahead of 2, 4 and 5.**
   It turned out to be the highest-value item once `library.css` proved to
   carry the same drift. See docs/09-ui-implementation-specs.md §3.4.
4. **Q&A sheds the widget shell** (§2).
5. **PrepareME report on `.stat` / `.meter`** (§5).

---

## What this document got right, and what it got wrong

Worth recording, because it is a boundary around a method rather than a verdict
on any one recommendation — and it tells the next reader which claims here to
trust without re-checking.

**Everything derived from source held.** The 30/12/23 token counts and the
`library.css` drift behind them; the argument-less `login_card()` shared by four
callers; "My Progress" duplicated between `header.php:61` and the nav; the
five-selector alias block in `library.css`; the `/ask/` panel's surviving widget
shape; `setup.sh:142` versus the live menu title. Each was checkable by reading
a file, and each survived independent verification.

**Everything inferred about layout under pressure was wrong** — three times, in
the same section, by three different routes:

1. "The nav breaks from ~1150px down" — arithmetic. Wrong; so was the measured
   940px that replaced it, which was a symptom of an unwrapped label.
2. "`min-height: 72px` absorbs the extra line" — it wrapped to three lines and
   grew the header to 80px on every phone.
3. "Pair the hide with `nowrap` above the breakpoint" — would have caused 358px
   of horizontal overflow at 950px. Corrected only because it was measured.

The pattern is not that estimates were sloppy. Each was reasonable from what a
stylesheet shows. **Length, wrapping and available width are simply not
properties a stylesheet exposes** — they exist only once something renders at a
particular width. The rule the team settled on is the right one and applies to
everybody regardless of role: *nobody states geometry without a measurement*,
and *before sending a CSS fix to an implementer, apply it in a browser at the
width it is meant to fix.*

Error 3 is the one to learn from. It happened after that rule was agreed, by the
person who proposed it, in the act of correcting error 2 — by checking the
single width that had just been named and asserting the rest of the range from
the same armchair. **Narrowing the scope of an unmeasured claim does not make it
measured.**

**Open question for the owner, not for me:** the site title still reads
"Scholaris" in the header and browser tab, while page titles say "EduAi"
(`AiCalc - EduAi`). docs/06 §1 says rename the display name everywhere a human
reads it. That is a one-line option change plus the tagline, but it is a
branding call and I have not made it.
