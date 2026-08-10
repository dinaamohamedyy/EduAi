# EduAi — information architecture and the four AI tabs

The product is being restructured. The assistant stops being a widget bolted to
the side of a library site and becomes the site: four AI tools, each a
first-class destination in the main navigation.

This document is the single source of truth for that change. It records the
decisions so six sessions do not each invent their own version of them.

---

## 1. What changes

| Before | After |
|---|---|
| Name: Scholaris | Name: **EduAi** |
| Nav: Home · Library · Material · My Progress | Nav: **Home · Library · Summarise · AiCalc · Q&A · PrepareME** |
| Material is a top-level destination | Material is reached **through** Library |
| Floating assistant widget on every page | Widget retired — the four tabs *are* the assistant |

### Naming — strings only, not slugs

Rename the **display name** everywhere a human reads it: site title, theme
header, plugin labels, page titles, `README`, docs.

Do **not** rename directories, text domains, CSS class prefixes, database
tables, option keys, REST namespaces, post types or shortcode names.
`wp-content/themes/scholaris/`, the `eduai` text domain, `sl-` CSS prefixes,
`wp_eduai_chunks`, `eduai/v1` and `[scholaris_library]` all stay exactly as
they are.

The reason is blunt: renaming an active theme directory deactivates the theme,
renaming post types orphans every row that references them, and renaming a REST
namespace breaks every client at once. None of that buys the user anything —
they never see a directory name. A cosmetic rename is an afternoon; a
structural one is a migration with a rollback plan, and nobody asked for it.

### Navigation and routes

```
/                     Home
/library/             Library — course and subject browsing
/library/<course>/      course view (new)
/material/<slug>/       material view (existing template, reached from a course)
/summarise/           Summarise helper
/calc/                AiCalc
/ask/                 Q&A helper
/prepare/             PrepareME
/dashboard/           My Progress   ← see open question 1
```

Material keeps its existing permalink. What changes is that it is entered from
Library and carries a breadcrumb `Library → Course → Material`, rather than
sitting in the main nav.

---

## 2. The four AI tabs

All four share the existing plumbing and must not fork it: `EduAI_Claude` for
the provider gateway, `EduAI_PDF` for extraction, `EduAI_Agents` for prompts and
house rules, `EduAI_REST::can_use()` and `check_rate_limit()` for the gate.

### Model choice per feature

Groq's free tier is what actually runs today. Features name a **tier**, never a
model id, so the mapping stays in one place (`EduAI_Claude::providers()`).

| Feature | Tier | On Groq | Temp | Why |
|---|---|---|---|---|
| Summarise | `strongest` | `openai/gpt-oss-120b` | 0.2 | Whole lectures; needs the 131k window and real synthesis. |
| AiCalc | `strongest` | `openai/gpt-oss-120b` | 0 | Only reached for symbolic and word problems — see below. |
| Q&A | `balanced` | `llama-3.3-70b-versatile` | 0.2 | Chat volume is highest here; escalates to `strongest` when the question is computational. |
| PrepareME — generate | `strongest` | `openai/gpt-oss-120b` | 0.3 | Hardest reasoning task in the product. Some variety is wanted. |
| PrepareME — grade | `strongest` | `openai/gpt-oss-120b` | 0 | A mark must not change between runs. |

### 2.1 Summarise helper

A move, not a rewrite. `POST /eduai/v1/summarize` already does this, including
PPTX slide-by-slide with speaker notes and the four styles (full notes, quick,
exam prep, critical review). It becomes a full page instead of a tab inside a
widget.

**Do not reimplement extraction.** `EduAI_PDF::extract($path, $ext)` handles
PDF, PPTX, DOCX, TXT, MD. The extension must be passed explicitly — uploads
arrive as `/tmp/phpXXXX.tmp` and the path tells you nothing.

### 2.2 AiCalc

**The model must not be the calculator.** Sending `12 * 8` to a language model
to be told the answer is slower, costs money, and is occasionally wrong. Route
on the input instead:

1. **Deterministic path.** A pure arithmetic expression is evaluated in code —
   tokenise, shunting-yard, exact result, every step shown. There is a working
   reference implementation in `design/preview.html` (`expression()`, `tokens()`,
   `reduce()`, `solve()`) that already handles precedence, parentheses,
   right-associative `^` and unary minus, verified against 12 cases. Port it to
   PHP; do not rewrite it from scratch.
2. **Model path.** Anything symbolic, worded, or unit-bearing — derivatives,
   integrals, simultaneous equations, "how long until it cools to 20 °C" —
   goes to the model at temperature 0 with the house rules, which already
   demand restating the givens, every step, units, and a closing sanity check.

The split must be visible to the student: a result computed exactly should say
so, because that is a stronger claim than a model's answer.

### 2.3 Q&A helper

The existing `/chat` endpoint, promoted to a page. Keeps RAG over the indexed
library, keeps the agent picker, keeps the house rules that guarantee an
off-syllabus maths or science question is answered under a "Beyond the course
material" heading rather than deflected.

### 2.4 PrepareME — the flagship

Upload a lecture → sit an exam generated from it → get it marked with
corrections. Stateful, and the only feature here that needs new storage.

```
upload ──▶ extract ──▶ generate ──▶ [student answers] ──▶ grade ──▶ report
           PDF/PPTX     JSON exam      rendered form       mixed      mark +
                        stored                             marking    corrections
```

**Question mix.** Every exam is three bands: **easy → medium → hard**. Default
10 questions as 4 / 4 / 2. Two types, deliberately:

- **MCQ** — four options, exactly one correct.
- **Short answer** — one or two sentences.

**Marking is split, and this is the important decision.** MCQ is graded in PHP
by comparing indices: deterministic, instant, free, and defensible if a student
disputes the mark. Only short answers go to the model, in a single batched call
with the model told the question, the expected answer, and the student's
response, returning a score and a one-line justification per item.

Grading every question with a model would make the mark non-reproducible and
cost several times more for no gain on the questions that have exactly one
right answer.

**The model must return strict JSON.** Ask for a fixed schema, parse with
`json_decode`, and on a parse failure retry **once** with the malformed output
fed back and an instruction to return only valid JSON. If the retry fails,
surface an honest error — never a half-built exam.

```jsonc
// generate
{ "title": "...",
  "questions": [
    { "id": 1, "band": "easy", "type": "mcq",
      "question": "...", "options": ["...","...","...","..."],
      "answer_index": 2, "explanation": "..." },
    { "id": 5, "band": "hard", "type": "short",
      "question": "...", "expected": "...", "marks": 2 }
  ] }

// grade (short answers only)
{ "results": [ { "id": 5, "awarded": 1.5, "of": 2, "comment": "..." } ] }
```

**Storage.** Two tables, following the existing `EduAI_Knowledge` pattern
(`dbDelta`, `$wpdb->prefix`, created in `EduAI_Assistant::install()`, dropped in
`uninstall.php`, and bumped in `EDUAI_DB_VERSION` so `maybe_upgrade()` fires):

- `eduai_exams` — id, user_id, source_label, source_hash, title, questions JSON, created_at
- `eduai_exam_attempts` — id, exam_id, user_id, answers JSON, results JSON, score, total, created_at

`source_hash` lets the same lecture be re-sat without re-generating, and makes
"regenerate" an explicit choice rather than an accidental cost.

**New endpoints.**

| Route | Does |
|---|---|
| `POST /eduai/v1/exam` | file or text in → exam JSON out, row stored |
| `POST /eduai/v1/exam/<id>/submit` | answers in → mark, per-question corrections |
| `GET /eduai/v1/exam/<id>` | re-open a past attempt |

Same gate as everything else: `can_use()` then `check_rate_limit()`. Generation
is the most expensive call in the product — rate-limit it separately and more
tightly than chat.

### PrepareME cannot ship until this is re-run

**The answer-leak check is only half-done, and the untested half is the half
that ships.** `scripts/projection-leak.php` proves the **server** projection:
`answer_index`, `expected` and `explanation` are absent from
`GET /eduai/v1/exam/<id>`, as keys *and* as content, over the real route with a
real `student`. That is verified — 12/12, including a control that first
confirms the detector *can* find those answers in an unprojected exam, so an
"absent" result can never come from a blind search.

What is **not** verified is the client. The plugin has no exam template yet, so
the `stripForClient`-and-DOM layer that will actually render a paper to a
student does not exist to test. The demo in `design/preview.html` was checked
and is clean, but it is not evidence about the shipped page: the demo
necessarily holds the whole fixture — answers included — in a JavaScript global,
because it has no server to project it. Different data source, different
guarantee.

So when the PrepareME tab is built, **re-run item 1 against the real page before
it ships**: render a paper as a signed-in student and search the live form DOM,
before submit, for `answer_index`, `expected`, `explanation`, the fixture's
actual explanation text, and any `(1);` mark-scheme fragment. Search by content
as well as by key name — a leak that renamed a field or inlined an answer into
the question text passes a key-name check and still hands the student the paper.

This is a ship gate, not a nice-to-have. A student who opens dev-tools on a
leaking page gets full marks, and no static check anywhere in this repository
can see it.

---

## 3. Retiring the widget

Only once all four tabs are live:

1. Default `enable_floating` to `false`.
2. Stop printing the launcher in `EduAI_Assistant::render_launcher()`.
3. Keep `[eduai_panel]` and `[eduai_summarizer]` working — they are how the
   tools embed into pages, and removing them would break any page already
   using them.

---

## 4. Owner's decisions — settled 9 Aug 2026

All three are answered. No longer open.

1. **My Progress stays.** Nav is therefore **seven** tabs, not six:
   Home · Library · Summarise · AiCalc · Q&A · PrepareME · My Progress.
   PrepareME attempts appear there alongside Tutor LMS attempts.
2. **Exam length is the student's choice** — *not* the fixed 10 assumed above.
   Offer **5 / 10 / 20**, band split held at the existing 40 / 40 / 20 ratio:

   | Length | easy | medium | hard |
   |---|---|---|---|
   | 5  | 2 | 2 | 1 |
   | 10 | 4 | 4 | 2 |
   | 20 | 8 | 8 | 4 |

   Two consequences, both back-end's to enforce:

   - `POST /eduai/v1/exam` takes a `count`, and it is validated against the
     allowlist `{5, 10, 20}` **server-side**. A UI dropdown is not a control —
     nothing stops a client posting `count=500`, and generation is the most
     expensive call in the product.
   - Generation rate limiting should weight by `count`, not by call. Twenty
     questions is roughly twice the spend of ten, and a per-call limit prices
     them identically.
3. **Score and corrections together**, on one screen after marking.

---

## 5. Exam JSON schema — normative

Freeze this before writing code against it. Two additions to the sketch in
§2.4, both load-bearing:

- **`marks` on MCQ.** §2.4 gave `marks` to short answers only. MCQ is graded in
  PHP and short answers by the model; without a marks value on MCQ the two
  halves cannot be summed into one score, and the PHP scorer and the model's
  `of` values would drift apart silently. Default `1`.
- **`schema_version`** at the top level. Exams are *stored*, so a schema change
  meets rows written under the old shape. A version field makes that a
  migration; its absence makes it a crash.

```jsonc
{ "schema_version": 1,
  "title": "...",
  "questions": [
    { "id": 1, "band": "easy", "type": "mcq",
      "question": "...", "options": ["...","...","...","..."],
      "answer_index": 2, "marks": 1, "explanation": "..." },
    { "id": 5, "band": "hard", "type": "short",
      "question": "...", "expected": "...", "marks": 2 }
  ] }
```

### 5.1 Field rules — normative

Everything below is load-bearing across three sessions. Where two components
could each pick a reasonable convention and disagree, the convention is fixed
here rather than discovered in a mis-marked exam.

**`answer_index` is 0-based.** `"answer_index": 2` means `options[2]`, the
**third** option. This is the single most dangerous field in the schema: a
0-vs-1 disagreement between the generator and the PHP scorer mis-marks every
MCQ in the product while every test that checks "a mark came back" still
passes. The generator prompt must say 0-based explicitly, the scorer must
compare 0-based, and the fixture exercises a non-zero, non-last index so an
off-by-one cannot pass silently.

**`options` has exactly 4 entries** for `type: "mcq"`, exactly one of which is
correct. Not 3, not 5 — the UI lays out four.

**`questions` is ordered easy → medium → hard** and `id` runs `1..n` in that
same presentation order. Front-end groups by band for display; it must not have
to sort, and back-end must not assume it can reorder. `id` is a unique positive
integer and is the only key the grade response is joined on.

**There is no declared total.** An exam's total is always `sum(marks)` over all
questions, computed wherever it is needed. A stored `total_marks` field would be
a second source of truth that can drift from the questions it summarises; the
attempts row stores the computed `total` for the record, not as the authority.

**`marks` is a positive integer**, default 1 for MCQ. `type` is `"mcq"` or
`"short"`. `band` is `"easy"`, `"medium"` or `"hard"`.

### 5.2 Grade response — normative

The model grades **short answers only**; MCQ never appears in this payload.

```jsonc
{ "results": [ { "id": 5, "awarded": 1.5, "of": 2, "comment": "..." } ] }
```

- `awarded` is a number in `[0, of]`, at most one decimal place. The grader
  **clamps** rather than trusting it: a model returning `awarded: 3` on a
  2-mark question must not produce 150%.
- `of` must equal that question's `marks`. On mismatch, the question's `marks`
  wins — the exam is the authority, not the grading call.
- **A `short` question missing from `results` scores 0** and is reported to the
  student as ungraded rather than silently dropped from the total.
- **An `id` in `results` that is not a `short` question in this exam is
  ignored.** A hallucinated id must never create marks out of nothing.

These four rules exist because the model is the one component here that can
return well-formed JSON that is nonetheless wrong. Schema validation catches
shape; only these catch a plausible lie.
