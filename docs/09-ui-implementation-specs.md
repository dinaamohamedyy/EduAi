# UI implementation specs — items 3, 4 and 5

Companion to `docs/08-ui-design-proposal.md`, written at the Tech Manager's
request on 10 Aug 2026. Items 0, 1 and 2 are already routed and are not
repeated here.

**Order is load-bearing.** Item 3 first: 4 and 5 both consume the tokens it
introduces, and doing them first means touching the same declarations twice.

**Contract safety.** No endpoint, payload, prompt or exam-schema change in any
of this. Item 5 raises one docs correction, flagged for routing and not acted
on. No new PHP method arguments — the one flagged in docs/08 belongs to item 2,
which is back-end's and already routed.

---

## Item 3 — token aliasing and one shared measure

**Files:** `eduai-assistant/assets/css/chat.css` (734 lines) **and**
`scholaris-library/assets/css/library.css` (324 lines) — see §3.4, which is
scope the original proposal missed. No template or JS change, and **no theme
CSS change**: `main.css` is the reference the plugins align *to*, not something
that moves.

**Owner — split by file** (routed 10 Aug 2026, revised):

| File | Owner | When |
|---|---|---|
| `library.css` | **front-end** | first — 324 lines, proves the migration |
| `chat.css` | **back-end** | after the pattern has landed in `library.css` |

Different files, so the no-collision rule is satisfied without serialising 3, 4
and 5 behind one session. Ownership by directory was a heuristic; it is not
worth a queue.

**Item 3 now runs ahead of items 4 and 5, not merely before them** — see §3.4
for why it turned out to be the highest-value item in the proposal.

### Why this is bigger than it looked

I quantified the drift while speccing it. Against the theme's scales:

| Dimension | Theme scale | `chat.css` today |
|---|---|---|
| Spacing | 9 steps (`0.25`→`6rem`) | **30 distinct values** |
| Radius | 6 steps (`4/8/12/16/22/pill`) | **12 distinct values** |
| Type | 7 fluid steps | **23 distinct font sizes** |

The type figure is the telling one: `0.9`, `0.92`, `0.94`, `0.95`, `0.98rem`
all appear — five sizes inside a 0.08rem span. That is not a scale, it is
per-element nudging, and it is most of why the four tabs read as assembled.

### 3.1 Extend the alias block

`chat.css:8–29` already aliases eleven colour tokens and three font tokens as
`--eduai-x: var(--x, <fallback>)`. **Keep that pattern exactly** — the
fallbacks are what let the plugin survive on a foreign theme, and nothing here
may make the plugin depend on the theme being present.

Add, in the same block and the same form:

```css
  /* Space — mirrors the theme scale, falls back to its own values */
  --eduai-space-3xs: var(--space-3xs, 0.25rem);
  --eduai-space-2xs: var(--space-2xs, 0.5rem);
  --eduai-space-xs:  var(--space-xs,  0.75rem);
  --eduai-space-s:   var(--space-s,   1rem);
  --eduai-space-m:   var(--space-m,   1.5rem);
  --eduai-space-l:   var(--space-l,   2rem);

  /* Radius */
  --eduai-radius-xs: var(--radius-xs, 4px);
  --eduai-radius-s:  var(--radius-s,  8px);
  --eduai-radius-m:  var(--radius-m,  12px);
  --eduai-radius-l:  var(--radius-l,  16px);
  --eduai-radius-pill: var(--radius-pill, 999px);

  /* Type */
  --eduai-step--1: var(--step--1, 0.875rem);
  --eduai-step-0:  var(--step-0,  1rem);
  --eduai-step-1:  var(--step-1,  1.25rem);

  /* One measure for all four tools */
  --eduai-measure: 52rem;
```

`--eduai-radius` (the existing alias, currently `--radius-l`) stays so nothing
breaks; new work uses the explicit names.

### 3.2 Migration table

Apply these mappings. **This is not a blind find-and-replace** — the exemptions
below are load-bearing.

| Current | Becomes |
|---|---|
| `0.25rem`, `0.3rem` | `--eduai-space-3xs` |
| `0.4`–`0.6rem` | `--eduai-space-2xs` |
| `0.65`–`0.9rem` | `--eduai-space-xs` |
| `1rem`, `1.1rem`, `1.15rem`, `1.2rem` | `--eduai-space-s` |
| `1.25`–`1.8rem` | `--eduai-space-m` |
| `5px`, `7px`, `9px`, `10px` | `--eduai-radius-s` |
| `14px` | `--eduai-radius-l` |
| `12px` | `--eduai-radius-m` |
| `0.9`–`0.98rem` font-size | `--eduai-step-0` |
| `0.82`–`0.88rem` font-size | `--eduai-step--1` |

**Exempt — do not migrate:**

- Values below `0.25rem` (`0.1`, `0.12`, `0.15`, `0.18`, `0.2rem`). These are
  optical adjustments inside small components — the typing indicator's 4px dot
  gap, badge insets. Snapping them to a 4px grid will look wrong.
- `border-radius: 50%` and `999px` — circles and pills are intent, not scale.
  `999px` may become `--eduai-radius-pill` cosmetically; no behaviour change.
- `font-size: 0.7rem` and below — mono meta labels and badges, which the theme
  also sets in raw rem (`.stat__label` is `0.7rem`). Leave them.
- `1.7rem` / `1.9rem` in the report score block — item 5 replaces that whole
  block, so migrating it now is wasted work.

### 3.3 One measure

Replace the three divergent widths with the shared token:

- `chat.css:464` `.eduai-calc { … max-width: 46rem }` → `var(--eduai-measure)`
- `chat.css:523` `.eduai-sum { … max-width: 52rem }` → `var(--eduai-measure)`
- `chat.css:618` `.eduai-prep { … max-width: 54rem }` → `var(--eduai-measure)`

52rem is the proposal's choice: 46rem is too tight for PrepareME's four-option
MCQs, 54rem is past a comfortable measure for summary prose.

Also normalise the three container gaps, which are currently `0.9 / 0.9 /
1rem`, to `--eduai-space-s`.

### 3.4 `library.css` has the same drift — and it corrects the proposal

Routing item 3 to "the two plugin stylesheets" prompted me to check the one I
had not looked at. `library.css` is the same story in miniature:

- Same alias pattern, `--sl-*` with fallbacks (lines 7–27), and the same
  coverage gap. It aliases fourteen colour tokens, two radii and two fonts —
  **no space scale, no type scale, no measure.**
- **21 distinct spacing values**, and **16 font sizes** including the same
  near-miss clusters: `0.9` / `0.92`, `0.74` / `0.76` / `0.78`,
  `0.82` / `0.84`. Against the theme's 2 and 7 — see the baseline table in
  §3.5.

Apply §3.1–§3.2 to it identically, with `--sl-` names and its own values as
fallbacks. It is a third the size, so it is the cheaper of the two and a
sensible place to prove the migration before doing `chat.css`.

**This corrects the framing in `docs/08` §1.** I wrote that the original pages
look considered and the new ones look assembled. That is not quite it: the
**theme** is disciplined, and **both plugins** drift the same way for the same
reason. `library.css` renders `/library/`, `/material/<slug>/` and
`/dashboard/` — so three surfaces I had counted as "original and fine" have the
same off-scale rhythm as the four new tabs. The new tabs are worse, not
different in kind.

**One consequence for item 5.** Its stated aim was to make the PrepareME report
"look like it belongs to the same product as the dashboard". Since the
dashboard is `library.css` and drifts too, the target is the **theme's**
`.stat` and `.meter` as defined in `main.css`, not whatever the dashboard
currently does. If `library.css` is migrated first, both converge on the same
thing anyway.

### 3.5 Acceptance

**The criterion is convergence on the theme's counts, not "fewer literals."**
A migration that snaps the easy values and leaves the near-miss clusters intact
is the most likely way this ends up half-done and declared finished — and it
would still show a large reduction.

**Measure with exactly this command, or the numbers are not comparable.** The
proposal and the Tech Manager's independent check disagreed by a few counts
purely on method (whether `px` counts as spacing, whether `50%` counts as a
radius). Pinning it removes that:

```bash
count() {
  f="$1"
  sp=$(grep -oE "(gap|padding|margin)(-[a-z]+)?:[^;]*;" "$f" | grep -oE "[0-9]*\.?[0-9]+rem" | sort -u | wc -l)
  fs=$(grep -oE "font-size:[^;]*;" "$f" | grep -oE "[0-9]*\.?[0-9]+rem" | sort -u | wc -l)
  rd=$(grep -oE "border-radius:[^;]*;" "$f" | grep -oE "[0-9]*\.?[0-9]+(px|rem)" | sort -u | wc -l)
  printf "%-14s %5s %5s %5s\n" "$(basename $f)" "$sp" "$fs" "$rd"
}
```

Baseline, measured 10 Aug 2026 — this command reproduces the Tech Manager's
figures exactly:

| File | Lines | Space | Type | Radius |
|---|---|---|---|---|
| `main.css` (**the target**) | 809 | **2** | **7** | **1** |
| `chat.css` | 734 | 29 | 22 | 9 |
| `library.css` | 324 | 21 | 16 | 3 |

The theme uses **two** literal rem spacing values across 809 lines, because it
uses its tokens. That is what "disciplined" measures as.

Pass condition: both plugins land near `2 / 7 / 1`, allowing for the §3.2
exemptions — realistically low single digits on space and type. **Anything
still in the twenties means the near-miss clusters survived**, and the item is
not done regardless of how much the diff removed.

- The three tools report the same content width in the browser.
- **Tester-owned, not implementer-owned** (routed 10 Aug 2026): switch to a
  stock theme, load all four tools, confirm they still render with correct
  spacing from the `var()` fallbacks. This is the property the whole alias
  pattern exists to protect, and a mistyped fallback fails **silently and only
  in that configuration** — the one nobody looks at. It needs someone whose job
  is disbelief, which is why it does not sit with whoever writes the CSS.

---

## Item 4 — Q&A sheds the widget shell

**Files:** `chat.css`, `wp-content/plugins/eduai-assistant/templates/panel.php`.
**Owner:** back-end (plugin-side).

### What is already handled

`/ask/` is created by `setup.sh:149` as `[eduai_panel height="600" tabs="chat"]`,
and `panel.php:31` already honours `tabs="chat"` to suppress the internal tab
strip, with a comment giving the same reasoning docs/08-ui-design-proposal.md §2 does. **Do not
re-do that.** An earlier draft of the proposal said the page "should pass"
`tabs="chat"`; it already does.

### 4.0 The defect, stated precisely — do not fix it by changing the number

Today `height="600"` feeds
`.eduai-app--inline { height: calc(var(--eduai-body-h) + 128px) }`, so `/ask/`
is a **728px fixed-height box with internal scroll, on a page that also
scrolls**.

**The defect is the existence of two scroll contexts, not the size of the
inner one.** The inner box being shorter than most desktop viewports is the
symptom that makes it obvious, but raising `height="600"` to `800` — or to any
number — leaves a nested scroll region that fights the page, traps the wheel,
and strands the composer mid-page. There is no correct value. The fix is
`height: auto`, which is what §4.1 does.

### 4.1 A new modifier, not a rewrite

`.eduai-app` must keep its current appearance for the floating widget and for
any existing `[eduai_panel]` embed — the homepage hero uses one. Add
`.eduai-app--page` alongside `--inline`:

```css
.eduai-app--page {
  height: auto;
  max-height: none;
  border: 0;
  border-radius: 0;
  box-shadow: none;
  background: transparent;
  overflow: visible;
}
.eduai-app--page .eduai-head { display: none; }
.eduai-app--page .eduai-log { overflow: visible; }
.eduai-app--page .eduai-composer {
  position: sticky;
  bottom: 0;
  background: hsl(var(--eduai-bg));
  border-top: 1px solid hsl(var(--eduai-border));
}
.eduai-app--page { max-width: var(--eduai-measure); }
```

The `.eduai-head` bar goes because the page `h1` and breadcrumb already say
where you are, and a second dark title bar underneath them is the single most
"embedded" thing on the page.

### 4.2 Where the head's controls go

`.eduai-head` carries three things that must not be lost:

| Element | Where it goes |
|---|---|
| "New chat" button (`data-eduai-new`) | A `.eduai-btn` above the log, right-aligned. Keep the same `data-` attribute — `chat.js` binds to it. |
| Status line (`data-eduai-status`) | Below the composer as quiet meta. It is reassurance, not a title. |
| Avatar, close button | Dropped on `/ask/`. The close button is already conditional on `! $eduai_inline`. |

**Binding constraint for whoever implements:** `chat.js` queries these by
`data-` attribute. Move the elements, keep the attributes, and do not rename
anything. If an element is dropped rather than moved, check `chat.js` tolerates
a null query before removing it.

### 4.3 Applying it

`panel.php:33` composes the class list. Add a `page` attribute to
`[eduai_panel]` (default off) rather than inferring from `tabs="chat"` — they
are different questions and the widget may one day want one without the other.
`setup.sh:149` then becomes `[eduai_panel tabs="chat" page="1"]`, and `height`
can be dropped there since it no longer applies.

**Setup-script note — forward-looking, nothing to reconcile today.** The Tech
Manager compared them: `setup.sh:149` and the live `/ask/` page content are
byte-identical at `[eduai_panel height="600" tabs="chat"]`. **There is no drift
right now.**

The warning is about what happens when you edit line 149. `setup.sh` rebuilds
the menu on every run, but `make_page` does not rewrite the content of a page
that already exists — so the new shortcode attributes reach a *fresh* install
only. Update the running page's content in the same change, or the work looks
done here and lands for real on the next clean install. Same class of drift as
item 0, which is how the menu title got out of step in the first place.

### 4.4 Acceptance

- `/ask/` has one scroll context, not two.
- The floating widget and the homepage `[eduai_panel]` are pixel-unchanged.
- New-chat still works — it is the binding most likely to break.

---

## Item 5 — the PrepareME report on the theme's components

**Files:** `chat.css`, `prepare.js` (`renderReport`, lines 299–378).
**Owner:** back-end (plugin-side).

### 5.1 The ship gates

**Settled 10 Aug 2026.** An earlier draft of this section quoted a version of
the first gate that said a full-marks short must carry "neither ✓ nor ✗". That
described `design/preview.html`, not the shipped renderer, and the Tech Manager
has corrected `docs/06-eduai-rebuild.md` §2.4. Quote below is that document as
it now reads (lines 223–224):

> Short-answer tone is derived from `awarded` against `of`, **never** from
> `correct`. A short answer at full marks must never render `✗`.

> Marks are floats — tabular figures, no rounding.

Both have live harnesses behind them, so a regression fails a test rather than
merely looking wrong.

**Nothing in any harness changed**, and the decisive evidence is one the
proposal missed: `tools/prepare-gate.html:171–172` already asserts

```js
q3mark.textContent.indexOf('✓') > -1 && q3mark.textContent.indexOf('✗') === -1
```

on the full-marks short answer — so the shipped renderer and its own gate were
in step with each other all along. Only the document was out, because it was
written from the demo before the tab existed. Gate green after the doc edit:
14/14.

**Implementation consequence: `prepare.js`'s existing tri-state handling is
correct and this item must not touch it.** The structural guarantee — `correct`
is never read outside the MCQ branch — is stronger than any assertion about
glyphs, and it is what a restyle is most likely to break by accident.

### 5.3 The restyle

`renderReport` builds three blocks. Only the first two change; the per-question
list keeps its structure.

**Score block** (`prepare.js:303–309`) — currently `.eduai-prep__score` with a
bespoke `1.9rem` figure. Replace with the theme's ledger tile, which already
carries `font-variant-numeric: tabular-nums` and the display serif at
`--step-3`, and so satisfies the float gate by construction:

```html
<div class="eduai-stat">
  <span class="eduai-stat__label">Score</span>
  <span class="eduai-stat__value">7.5 / 11</span>
  <span class="eduai-stat__note">68% · Exam title</span>
</div>
```

Port `.stat` from `main.css:383–397` into `chat.css` as `.eduai-stat`,
substituting the aliased tokens from item 3. Keep pass/fail colour on the note,
not the value — a red score reads as an error state rather than a result.

**Band rows** (`prepare.js:311–322`) — `.eduai-prep__meter` is a hand-rolled
bar. Port `.meter` from `main.css:568–572` as `.eduai-meter`; it already has
`data-tone="pass|mid|fail"`. Set the tone from the band ratio, and keep the
`awarded / of` figures as they are.

**Do not change:** the per-question loop (324–375), `mark()` (382–387), or any
`correct` handling. Item 5 is presentation only.

### 5.4 Acceptance

- A full-marks short answer renders per whatever the Tech Manager rules, and
  **never** `✗`.
- `2.5 / 4` survives to the page unrounded, tabular.
- Band subtotals still sum to the score and the paper total.
- Re-run the docs/06 §2.4 client-side check on the real page: search the live
  form DOM before submit for `answer_index`, `expected`, `explanation` and the
  fixture's explanation text. This item does not touch the paper stage, but it
  edits the file that renders it, and that check is a ship gate.
