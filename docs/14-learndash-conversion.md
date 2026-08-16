# Tutor LMS → LearnDash, and the EduAi restructure

The owner's direction, 12 Aug 2026. Two changes, done as **one sequence** rather
than two projects, because they touch the same files: the AI features move into
the lesson view, and the lesson view is the thing being migrated.

---

## 1. What is actually being asked

**LMS.** Replace Tutor LMS with LearnDash. Every course, lesson, topic,
enrolment and progress record moves. The site depends on LearnDash afterwards,
and LearnDash's own features are used rather than worked around.

**Navigation.** The header carries four items and no more:

    Home · Library · Progress · Profile

Everything else that is a tab today stops being one.

**The AI features move inside a lesson.** Open the Library, open a lesson, and
that lesson offers:

| Feature | Scope |
|---|---|
| Summarise | **this lesson only** |
| PrepareME | **this lesson only** |
| Q&A | **this lesson only** — it must not answer from other lessons |

**AiCalc leaves the lesson.** It is not a per-lesson tool. It folds into the
home-page chatbot, which becomes the **general** assistant: general questions and
arithmetic, not scoped to any lesson.

**Design.** Rebuilt, professional, UI/UX and front-end working as a pair rather
than in sequence.

---

## 2. What the conversion actually costs — measured, not estimated

The reference count is misleading and was nearly the basis of a bad estimate.
`grep -ri tutor` returns 94 hits across 23 files. The real coupling is far
smaller:

**Six calls to Tutor's API**, all of them post-type lookups:

    tutor()->topics_post_type   ×3
    tutor()->lesson_post_type   ×2
    tutor()->course_post_type   ×1

**Hardcoded post-type strings**, which are the actual surface:

| File | Strings |
|---|---|
| `scholaris-library/includes/class-sl-catalog.php` | `courses`, `lesson`, `topics` |
| `scholaris-library/includes/class-sl-console.php` | `courses` ×4 |
| `scholaris-library/templates/admin/console.php` | `courses` ×4 |
| `scholaris-library/templates/library-grid.php` | `courses`, `topics` |
| `eduai-assistant/includes/class-eduai-knowledge.php` | `lesson` ×3, `courses` ×2 |
| `eduai-assistant/` (lessons, scope, fields, main) | `lesson` ×4 |
| `themes/scholaris/front-page.php` | `courses` ×2 |

**Tutor tables and meta in use:** `tutor_quiz_attempts`, `tutor_quiz_option`,
`tutor_course_id_for_lesson`, `tutor_instructor`, `tutor_enrolled`.

**Content to migrate:** 2 courses, 4 lessons, 2 topics, 3 enrolments,
**0 quizzes, 0 quiz attempts.**

That last line matters more than any other number here. The quiz half of the
migration — normally the hard part — **does not exist**. Nothing is at risk in
it because nothing is in it.

---

## 3. The target model

LearnDash, verified against its developer documentation rather than memory:

| Concept | Tutor | LearnDash |
|---|---|---|
| Course | `courses` | `sfwd-courses` |
| Section | `topics` | `sfwd-topic` |
| Lesson | `lesson` | `sfwd-lessons` |
| Quiz | `tutor_quiz` | `sfwd-quiz` |
| Enrolment | `tutor_enrolled` post | user meta + `learndash_user_activity` |
| Progress | `tutor_*` meta | user meta + `learndash_user_activity(_meta)` |

Progress and enrolment are **not** post rows in LearnDash. They are user meta
plus two activity tables, written through LearnDash's own functions. Writing
those tables directly is the way to produce a migration that looks complete and
leaves the LMS unable to see it.

---

## 4. Sequence

**Phase 1 — the LMS seam. Unblocked; start now.**

One class, `EduAI_LMS`, answering: what post type is a course, a section, a
lesson; is this user enrolled; what is their progress; which course does this
lesson belong to. Every one of the touchpoints in §2 calls it instead of naming
a post type.

This is worth doing even though the destination is settled, for a reason that is
not hypothetical: it makes the migration **testable before LearnDash exists**.
The adapter can be exercised against Tutor today, and against LearnDash the hour
the plugin arrives, with the same assertions.

**Phase 2 — migration. Needs the plugin.** LearnDash is commercial and cannot be
downloaded here; the owner supplies the zip. Then: install, map content, migrate
enrolments and progress **through LearnDash's own API**, and verify by reading
the site rather than the database.

**Phase 3 — restructure. Unblocked; runs alongside Phase 1.** Nav down to four.
AI features into the lesson view, scoped. AiCalc out of the lesson and into the
general assistant on the home page.

**Phase 4 — design.** UI/UX and front-end as a pair.

---

## 5. The scoping rule, which is the part that can silently go wrong

"Q&A answers about **this lesson only**" is a claim about a retrieval boundary,
and a retrieval boundary that leaks produces answers that look perfect and cite
the wrong lesson. It cannot be verified by reading the code.

So it gets a harness, and the harness needs the case that makes leaking
detectable: **two lessons whose content is distinguishable, and a question whose
answer exists only in the other one.** A correct implementation must decline. An
implementation that ignores scope will answer confidently and correctly from the
wrong source — and every assertion that only checks "an answer came back" will
pass.

Same shape for Summarise and PrepareME: a summary of lesson A that contains a
fact only present in lesson B is a scope leak, not a good summary.

---

## 6. What is already built

Not starting from nothing. `templates/lesson-panel.php` exists and already
carries a `lesson_id` and a `scope` concept, and `class-eduai-scope.php`,
`class-eduai-lessons.php` and `class-eduai-lesson-fields.php` are lesson-aware.

What none of it survives is the post-type rename — `class-eduai-lessons.php`
builds content through `tutor()->topics_post_type` directly. That is the file
where Phases 1 and 2 meet, and it should be one person's work rather than two.
