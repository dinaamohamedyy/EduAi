# PrepareME — the wire contract

`docs/06-eduai-rebuild.md` §2.4 fixes the *model's* JSON: what the generator is
asked to return and what the grader is asked to return. This document fixes the
*wire* JSON: what the browser sends and what it gets back.

They are deliberately not the same shape, for the reason in §1 below. The
front-end builds against this file. Nothing here changes without a note to the
front-end session first.

Status: **pinned and implemented**, 9 Aug 2026. Routes live under `eduai/v1`,
same namespace and same `can_use()` gate as everything else.

`docs/06` §4 (owner's decisions) and §5 (the stored exam schema) are normative
and sit above this file. Where this file described something differently — exam
length, `marks` on MCQ, a stored total — it has been corrected to match them.

**One naming flip, now settled.** For about an hour this document showed `id`,
`answer` and `your_index`, because the first implementation used those. The
implementation has since moved to `exam_id`, `choice`/`text` and
`your_choice`/`your_text`, and those are final. They are also the better set:
`results[].id` is a *question* id, so a top-level `id` meaning the exam in the
same payload is a trap, and a typed `choice` (number) versus `text` (string)
cannot silently coerce a stray string into option 0 the way one polymorphic
`answer` field can.

---

## 1. The answers never leave the server

The generator returns, per §2.4, `answer_index` and `explanation` on every MCQ
and `expected` on every short answer. **None of those three fields may appear in
a response to the browser before the attempt is submitted.** They are stored in
the `eduai_exams` row and projected out on the way to the client.

This is not a hardening nicety. Anything the browser receives is one devtools
Network tab away from the student, so echoing the generator's JSON verbatim
would ship an exam with its own answer key attached and the feature would not
work at all. Every response shape below is written as the projection, and
`EduAI_Exams::for_client()` is the only function that builds one.

The reveal happens at submit time, which is also the first moment the student
can no longer change an answer.

---

## 2. `POST /eduai/v1/exam` — generate

Multipart (a file) or JSON (pasted text). The file field is `file`, matching
`/summarize`, so the existing upload handling is shared rather than forked.

**Request**

| Field | Type | Default | Notes |
|---|---|---|---|
| `file` | upload | — | PDF / PPTX / DOCX / TXT / MD. 20 MB cap, as `/summarize`. |
| `text` | string | — | Used when no file. Minimum 200 characters — an exam needs more source than a summary. |
| `count` | int | `10` | **Allowlist `{5, 10, 20}`, enforced server-side** (docs/06 §4). Anything else is a `400`. Bands 2/2/1, 4/4/2, 8/8/4. |
| `title` | string | `''` | Optional student-supplied label; the model titles it when blank. |
| `regenerate` | bool | `false` | Forces a new generation even when `source_hash` matches a stored exam. |

The allowlist is server-side because a dropdown is not a control: nothing stops
a client posting `count=500`, and generation is the most expensive call in the
product. Generation rate limiting is weighted by `count` for the same reason —
twenty questions costs two units of the hourly budget, five costs half.

**Response 200**

```jsonc
{
  "exam_id": 12,
  "title": "Fourier Series — Week 3",
  "reused": false,                        // true = served from source_hash, no model call
  "questions": [
    { "id": 1, "band": "easy",   "type": "mcq",
      "question": "…", "options": ["…","…","…","…"], "marks": 1 },
    { "id": 5, "band": "hard",   "type": "short",
      "question": "…", "marks": 2 }
  ]
}
```

The question `id` is stable within an exam and is what `submit` refers back to.
Questions arrive **ordered easy → medium → hard** with `id` running `1..n` in
that same presentation order (docs/06 §5.1), so the form renders them in the
order given and groups by `band` without sorting.

`marks` is present on both types — always `1` for MCQ — so the form can show a
mark allocation without special-casing.

**There is no `total` field, by design.** An exam's total is `sum(marks)`,
computed wherever it is needed. A stored total would be a second source of
truth that can drift from the questions it claims to summarise (docs/06 §5.1).
The submit response does carry a `total`, because an attempt records what the
paper was worth at the moment it was sat.

`options` is exactly four strings, always, in the order the student sees them,
and is **absent entirely on short-answer questions** rather than empty. The
index into this array is what gets submitted.

A `reused: true` response costs nothing and does not touch the generation rate
limit, so re-opening the same lecture is free.

**Errors** — same vocabulary as `/summarize`: `400` short/absent input, `413`
oversized file, `415` unsupported or legacy Office format, `422` no readable
text, `429` rate limited, `502` provider unreachable, `500` generation failed
after the repair retry (see §5).

---

## 3. `POST /eduai/v1/exam/<id>/submit` — mark

**Request**

```jsonc
{ "answers": [
    { "id": 1, "choice": 2 },        // mcq: 0-based index into options
    { "id": 5, "text": "…" }         // short: free text
] }
```

Two typed fields rather than one polymorphic one: `choice` is a number, `text`
is a string, and neither can be mistaken for the other. **`choice` is 0-based**
— `2` means `options[2]`, the third option. This is the single most dangerous
number in the product: a 0-vs-1 disagreement anywhere along the chain mis-marks
every MCQ while every test that checks "a mark came back" still passes.

Unanswered questions may be omitted entirely or sent as `null` / `""` — both
score zero and both are still reported, so the report has a row per question
either way. Ids not belonging to this exam are ignored rather than erroring, so
a stale tab cannot poison a mark. More than 100 entries is a `400`.

**Response 200**

```jsonc
{
  "attempt_id": 34,
  "score": 8.5,
  "total": 12,
  "percent": 71,
  "bands": {
    "easy":   { "awarded": 4,   "of": 4 },
    "medium": { "awarded": 3,   "of": 4 },
    "hard":   { "awarded": 1.5, "of": 4 }
  },
  "results": [
    { "id": 1, "type": "mcq", "band": "easy",
      "awarded": 1, "of": 1, "correct": true,
      "your_choice": 2, "your_text": null, "answer_index": 2,
      "expected": "", "explanation": "…", "comment": "" },
    { "id": 5, "type": "short", "band": "hard",
      "awarded": 1.5, "of": 2, "correct": null,
      "your_choice": null, "your_text": "…", "answer_index": null,
      "expected": "names the mechanism (1); gives the direction (1)",
      "explanation": "",
      "comment": "You named the mechanism but did not say which way it moves." }
  ]
}
```

**Every key above appears on every result, whatever the question type.** The
value is `null` or `""` where it does not apply, never absent. That is
deliberate: a renderer written against per-type keys has to test for existence
on every field, and the one test it will actually write — `if (r.correct)` —
reads a missing key as false and marks a full-credit short answer wrong.

Which key carries the correction depends on the type, and both are always
strings:

- **`explanation`** — MCQ only. Why the right option is right, from the
  generator. Empty string on short answers.
- **`comment`** — short answers only. The marker's one-line justification.
  Empty string on MCQ. **Guaranteed non-empty on every short answer, including
  at full marks** — the prompt asks for one and the server substitutes a
  fallback if the model returns nothing, because a blank space beside a mark is
  worse than a dull sentence.
- **`expected`** — short answers only, and it is a **mark scheme**, not a model
  answer: semicolon-separated award points with their marks in brackets. That
  is what makes marking checkable rather than a similarity judgement, and it
  also shows the student what each mark was for.

`results` is in question order, not submission order.

`correct` is a tri-state and the front-end must treat it as one: `true` / `false`
for MCQ, and **`null` for every short answer**, which are partially credited and
have no true/false reading. Render short answers off `awarded` vs `of`, never
off `correct`.

`awarded` is a float — halves are normal on short answers. `score` is the sum,
rounded to one decimal. `percent` is an integer, rounded.

`explanation` is populated for MCQ (why that option is right, from the
generator) and empty for short answers. `comment` is the opposite: the grader's
one-line justification on short answers, empty on MCQ. Both keys are always
present so the renderer needs no existence checks.

---

## 4. `GET /eduai/v1/exam/<id>` — re-open

```jsonc
{
  "exam_id": 12,
  "title": "Fourier Series — Week 3",
  "source_label": "week-3-fourier.pdf",   // "" for pasted text
  "created_at": "2026-08-09 18:20:11",
  "questions": [ /* §2 shape, answers still withheld */ ],
  "attempts":  [ /* every past attempt, newest first */ ]
}
```

One route serves both "resume the paper" and "look at my marked script":
`attempts` is empty for the first, populated for the second. There is no
separate `attempted` flag — `attempts.length` is the flag.

Only the requesting user's own exams are visible. Another student's exam and an
id that does not exist must answer **identically**, so that the response cannot
be used to discover which ids exist. Either status works as long as both cases
return the same one; the implementation uses `403`.

**`GET /exam/0` returns a fixture** — a complete, fixed exam in exactly this
shape, so the form and the report can be built and demoed before a real
generation has ever run.

---

## 5. Strict JSON and the one repair retry

Both model calls demand JSON and nothing else. `json_decode` is followed by a
structural check — right keys, right types, four options per MCQ, `answer_index`
in range, bands from the known set. A failure at either step triggers **exactly
one** retry that feeds the malformed output back with an instruction to return
only valid JSON.

If the retry also fails, the route returns an honest `500` and stores nothing.
A half-built exam is never rendered, and a half-graded attempt is never saved.

---

## 6. Rate limits

Generation is the most expensive call in the product and gets its own counter,
separate from and tighter than chat:

| Bucket | Limit | Window |
|---|---|---|
| chat / calc / summarise | `rate_limit` setting (default 20 calls) | rolling hour |
| **exam generation** | `exam_limit` setting (default 4 **units**) | rolling hour |
| exam submit | shares the chat bucket | rolling hour |

Generation is budgeted in units, not calls: a 10-question paper is 1 unit, 20 is
2, 5 is 0.5 (docs/06 §4). A per-call limit would price a 20-question generation
the same as a 5-question one when it costs four times as much.

Submitting is cheap when the paper is all MCQ — the model is only reached when
short answers exist — so it does not warrant its own bucket. Serving a reused
exam spends nothing from either.

---

## 7. `POST /eduai/v1/calc` — AiCalc

Not PrepareME, but it shares this file because it has the same property: the
response tells the client which of two very different things happened.

**Request** — `{ "input": "…" }`, 1–2000 characters.

**Response 200**

```jsonc
{
  "method": "exact",              // or "model"
  "input":  "2 + 3 * 4",
  "answer": "14",                 // "" on the model path
  "steps":  ["2 + 3 × 4", "2 + 12", "14"],   // [] on the model path
  "html":   "<p>…</p>"
}
```

`method` is the point. `exact` means the arithmetic was evaluated in PHP by
`EduAI_Calc` — no model was called, the result is exactly right, and the client
should say so, because "computed exactly" is a stronger claim than a model's
answer and students deserve to know which they have. `model` means the question
was symbolic, worded or unit-bearing and went to the strongest tier at
temperature 0 with the maths-and-science agent's prompt.

The exact path does not consume rate limit — it costs nothing to serve. The
model path shares the chat bucket.

---

## 8. `POST /eduai/v1/summarize` — Summarise

Predates this document and was undocumented until the tab was built. It is here
rather than in a file of its own for the reason §7 gives: one route, one place.

**Request** — `multipart/form-data`.

| Field | |
|---|---|
| `file` | PDF, PPTX, DOCX, TXT or MD, 20 MB max. Optional if `text` is given. |
| `text` | Pasted lecture text, at least 80 characters. Optional if `file` is given. |
| `style` | `detailed` (default), `brief`, `exam`, `critical`. |

**Response 200**

```jsonc
{
  "summary": "## Overview\n…",   // markdown
  "html":    "<h3>Overview</h3>…", // sanitised by EduAI_REST::to_html()
  "label":   "photosynthesis-lecture.pptx",
  "style":   "brief"
}
```

`label` is the uploaded filename, or empty for pasted text — the client supplies
its own wording in that case.

### What the server does with a deck

Extraction is `EduAI_PDF::extract($path, $ext)`, and the **extension must be
passed explicitly**: an upload arrives at `/tmp/phpXXXX.tmp` and the path says
nothing about the format. Getting this wrong is not a visible failure — it
silently returns an empty string, which is how DOCX summarising was broken for
a long time while every test still passed.

A PPTX is read **slide by slide in slide order, with the speaker notes**, and
the notes are located through `ppt/slides/_rels/slideN.xml.rels` rather than by
matching numbers: a deck where only slides 2 and 5 carry notes stores them as
`notesSlide1` and `notesSlide2`, so a numeric guess attaches them to the wrong
slides. The notes matter — they are usually where the lecturer wrote the
sentence the bullet only hints at, and summaries visibly draw on them.

### Errors worth handling by name

| Status | When |
|---|---|
| 400 | Neither a file nor 80+ characters of text |
| 413 | Over 20 MB |
| 415 | Unsupported type; legacy `.doc`/`.ppt`; or a scanned PDF on a provider that cannot read documents |
| 422 | Readable file, no text in it — an image-only deck. The message tells the student to export to PDF |
| 429 | Shares the chat rate-limit bucket |

Every message is already written for a student rather than a developer, so a
client should surface `message` verbatim rather than substituting its own.

### Cost

There is no deterministic path. Unlike AiCalc, **every summary calls the
model** — the strongest tier at temperature 0.2, since a whole lecture needs the
context window and real synthesis. A page cannot be demonstrated without a key.
