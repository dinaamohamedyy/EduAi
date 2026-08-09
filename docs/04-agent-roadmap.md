# Phase 2 — agents

The current assistant is a single-turn RAG chatbot: retrieve passages, answer,
cite. That is deliberately the right starting point — it is cheap, fast and
predictable, and it covers most of what students actually ask.

Agents earn their keep when a task needs **multiple steps, tool use, or
persistence across sessions**. What follows is the ordered list, easiest and
highest-value first, with the specific hook in the existing code each one
attaches to.

---

## Where this now stands (v1.1.0)

Half of "agents" turned out to be worth having before any tool loop existed, so
it shipped: `EduAI_Agents` loads a registry of agent definitions from
`agents/*.md`, students pick one from the chat window, and the choice changes
the system prompt and the sampling temperature for that request.

What exists:

- **A registry, not a hard-coded persona.** Files use the front-matter format
  the claude-code-templates catalogue publishes, so
  `npx claude-code-templates@latest --agent expert-advisors/critical-thinking`
  produces a file that drops straight into `agents/`.
- **Four agents.** Knowledge synthesiser (the default), Study tutor, Critical
  thinking, Maths & science. Two are adapted from the claude-code-templates
  catalogue; the unmodified originals live in `.claude/agents/`.
- **Per-agent model and temperature.** A `model:` line in the front matter takes
  the short family name (`opus`, `sonnet`, `haiku`) or a full id, and is dropped
  if it names something the plugin does not offer.
- **House rules.** A shared block appended after every agent prompt, so no
  individual prompt — including one an administrator types into the settings
  box — can narrow the assistant's scope below answering an off-syllabus maths
  or science question.
- **Intent-sensitive generation.** A message that reads as a calculation drops
  the temperature to 0 and raises the token ceiling, whichever agent is active.

What does **not** exist yet, and is what the rest of this document is about:
tool use, multi-step loops, and anything that persists between sessions. An
agent here is a prompt and a temperature — nothing calls back into WordPress
mid-answer. Every item below still needs the tool loop described under
*What the codebase already gives you*.

When you build that loop, `EduAI_Agents` is the natural place to hang per-agent
tool definitions: add a `tools:` key to the front matter and read it in
`EduAI_Claude::message()` through the existing `eduai_request_body` filter. The
upstream catalogue files already carry a `tools:` line, which the parser
currently ignores.

---

## What the codebase already gives you

Three extension points were built in with this phase in mind:

```php
// Add tools to any outgoing request
add_filter( 'eduai_request_body', function ( $body ) {
    $body['tools'] = array( /* tool definitions */ );
    return $body;
} );

// Replace keyword retrieval with embeddings, or add a reranker
add_filter( 'eduai_retrieve', function ( $passages, $query, $limit ) {
    return my_vector_search( $query, $limit );
}, 10, 3 );

// Change what gets indexed
add_filter( 'eduai_indexed_post_types', fn( $types ) => array_merge( $types, array( 'my_cpt' ) ) );
```

Anthropic's Messages API supports tool use natively. An agent loop is: send the
question with tool definitions → the model asks to call a tool → you execute it
in PHP → send the result back → repeat until it answers. Roughly 150 lines of
PHP on top of `EduAI_Claude::message()`.

---

## Tier 1 — build these first

### 1. Quiz Coach

**What it does:** after a student fails a quiz, it looks at *which questions*
they got wrong, finds the material covering those topics, and produces a
targeted revision plan — not a generic "study harder".

**Tools it needs:**

| Tool | Backed by |
|---|---|
| `get_quiz_attempt` | `SL_Quiz_History::attempts()` |
| `get_wrong_answers` | `wp_tutor_quiz_attempt_answers` table |
| `search_material` | `EduAI_Knowledge::retrieve()` |

**Trigger:** `tutor_quiz/attempt_ended` action hook. Generate the plan
asynchronously, store it as user meta, surface it on `/dashboard/`.

**Why first:** it uses data you already have, it runs once per attempt so the
cost is bounded, and it is the single most useful thing an LLM can do in a
learning platform.

### 2. Quiz Generator

**What it does:** an instructor uploads a lecture PDF; the agent drafts 10 quiz
questions with correct answers, distractors and explanations, then writes them
straight into Tutor LMS as a draft quiz for the instructor to review and edit.

**Tools:** `get_material_text`, `create_tutor_quiz`, `add_quiz_question`.

**Trigger:** a "Generate quiz from this document" button on the study-material
editor screen.

**Why:** the single biggest time saver for whoever runs the courses. Always
leave the output as a **draft** — a human must approve every question.

### 3. Study Planner

**What it does:** takes an exam date and the student's current scores, and
produces a day-by-day revision schedule weighted toward their weakest topics.
Re-plans automatically when new quiz results come in.

**Tools:** `list_courses`, `get_all_attempts`, `list_material`, `save_plan`.

**Storage:** user meta, rendered on the dashboard, optional weekly email.

---

## Tier 2 — once Tier 1 is proven

### 4. Content Gap Detector

Runs weekly over the chat logs in `wp_eduai_messages`, clusters the questions
students actually asked, and reports which topics the library covers badly.
Output: an admin email listing "17 students asked about X this week; no
material covers it."

This closes the loop between what you publish and what students need, and it is
information no other part of the system produces.

### 5. Grading Assistant

For Tutor LMS open-ended questions (`attempt_status = 'review_required'`), the
agent drafts a mark and written feedback against a rubric you define. The
instructor reviews and approves — never auto-publish a grade.

### 6. Onboarding Guide

A conversational intake for new students: assesses current level with a few
questions, recommends a starting course, enrols them.

---

## Tier 3 — bigger lifts

### 7. Voice Tutor

Continuous spoken conversation — the model asks a question, listens, evaluates
the spoken answer, follows up. Needs real speech infrastructure
(Whisper or Deepgram for input, ElevenLabs or OpenAI TTS for output) rather
than the browser API, because the browser API cannot do continuous
interruption-tolerant listening.

### 8. Multi-agent research assistant

For project work: one agent plans, several search different sources in
parallel, one synthesises. Meaningful cost per run — gate it behind an
instructor-granted permission.

---

## Suggested sequence

| Phase | Work | Effort |
|---|---|---|
| **2a** | Tool-use loop in `EduAI_Claude`, plus Quiz Coach | 1–2 weeks |
| **2b** | Quiz Generator | 1 week |
| **2c** | Study Planner | 1 week |
| **2d** | Content Gap Detector | 3–4 days |
| **3** | Embeddings retrieval, replacing FULLTEXT | 1 week |
| **4** | Real voice pipeline | 2 weeks |

---

## Rules to hold to

1. **Every agent that writes anything writes a draft.** Quizzes, grades,
   enrolments — a human approves before it is visible to students.
2. **Log every tool call.** When an agent does something unexpected, the log is
   the only way to find out why.
3. **Cap the loop.** Hard limit of 5 tool-use iterations per request, and a
   token ceiling per agent run. Runaway loops are the main way agent costs
   surprise people.
4. **Fail visibly, not silently.** If an agent cannot complete a task, say so.
   A confidently wrong revision plan is worse than none.
5. **Keep the plain chatbot as the default.** Agents are for specific triggered
   tasks; general questions should still take the cheap path.
6. **Never let an agent see another student's data.** Every tool must filter by
   the current user ID server-side — not by asking the model nicely.

---

## When to move off FULLTEXT

Current retrieval is MySQL keyword matching. It works well up to roughly
**500 documents**, and it fails in one specific way: a student asking about
"how cells make energy" will not match a document that only ever says
"ATP synthesis".

Symptoms that it is time to switch: students report the assistant "can't find"
material that plainly exists, and the *no material matched* path fires often.

The upgrade is embeddings: generate a vector per chunk (Voyage AI is
Anthropic's recommended embedding provider), store them, and rank by cosine
similarity. Options, cheapest first:

- **Vectors in MySQL** — store as JSON, compute similarity in PHP. Fine to
  ~5,000 chunks, no new infrastructure.
- **SQLite + sqlite-vec** — a single file on the server, good to ~100k chunks.
- **Qdrant or Pinecone** — a managed service, when you outgrow both.

All three plug into the same `eduai_retrieve` filter. Nothing else in the
plugin changes.
