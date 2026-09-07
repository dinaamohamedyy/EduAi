# Tech Manager → General Manager: handover

**Written 7 Sep 2026.** The owner is retiring the Tech Manager session and
replacing it with the General Manager, which can consult a non-Claude model
before ruling (`scripts/consult-external.pl`, agent definition in
`.claude/agents/expert-advisors/general-manager.md`).

## How this handover was produced, and why it matters

The Tech Manager session last spoke on **25 August 2026**. Everything below
happened after that: the LearnDash conversion, the LMS seam, the retheme, the
PrepareME repair. Its ledger is therefore two weeks and one migration stale, and
copying it forward would have handed the GM a list of jobs that are already done
alongside jobs that never existed.

So this handover was rebuilt **from the repository as it stands**, and every
item was checked before being written down. That was not ceremony. Three of the
four items carried in the outgoing list did not survive the check:

| Carried forward as open | What the code actually says |
|---|---|
| Knowledge index still names Tutor post types; Q&A retrieves nothing | **Done.** `indexed_post_types()` now calls `EduAI_LMS::content_types()`, with the incident recorded in the comment above it. |
| Hardcoded post types in `class-sl-catalog.php`, `class-sl-console.php` | **Not a defect.** `'courses' => $courses` and `'topics' => $topics` are array keys in a return shape, not post types. Renaming them would break callers and fix nothing. |
| Remaining post types in `library-grid.php`, `front-page.php` | **Not found.** `front-page.php` already asks `scholaris_course_post_type()`. |
| `scholaris_fallback_menu()` still lists the seven old tabs | **Open.** Confirmed: four retired slugs still enumerated. |

This is the single most useful thing the GM can learn from its predecessor: on
this project a task list decays faster than the code does. A stale "still
broken" is as expensive as a stale "already fixed" — one wastes a session, the
other hides a live defect behind a tick.

**Standing rule for the GM: verify an item before assigning it.** One grep is
cheaper than one session.

## What is genuinely open

1. **`scholaris_fallback_menu()`** (`wp-content/themes/scholaris/functions.php`)
   still enumerates `summarise`, `calc`, `ask`, `prepare` as top-level tabs. The
   owner's nav is Home, Library, Progress, Profile. This is a second source of
   truth beside the real menu and has already caused one "the nav did not
   change" report.

2. **LearnDash focus-mode styling** — `assets/css/learndash.css` exists, is
   enqueued behind `class_exists( 'SFWD_LMS' )` and is served. It has **not been
   looked at in a browser by anyone.** The last commit touching it says so
   plainly. Someone with a logged-in session must open a lesson and look.

3. **AiCalc folded into the home chatbot** as a general assistant. Asked for on
   16 Aug, not yet done.

4. **Scope-leak harness** (`docs/14-learndash-conversion.md` §5) — buildable now
   that lessons 230/231/232 exist.

5. **Instructor Role and Gradebook** — the owner installed both and asked for
   their features to be used. Nothing consumes either yet.

## Environment facts the GM needs and cannot infer

- **LearnDash is not in this working tree.** `wp-content/plugins/` holds only
  `eduai-assistant`, `eduai-enquiry`, `scholaris-library`. LearnDash was
  installed through wp-admin into the container's volume, so it is live on
  `localhost:8080` and invisible to `grep`. Reading the tree will tell you the
  site has no LMS. It has one.
- **Tutor LMS is gone** from the tree. The deactivation that was pending is moot.
- **No Ollama.** No `OLLAMA_*` variable in `.env`, no server on `:11434`. The
  `ollama` provider is wired and refuses with instructions until one exists.
- `.env` holds `GROQ_API_KEY` and `ANTHROPIC_API_KEY`. Nothing else is
  configured, whatever a conversation may have implied.

## What changes with the GM

The TM coordinated. The GM adjudicates, and has one capability the TM did not:
it can ask a model outside the Claude family for a second opinion before ruling.

The constraint on that is written into its definition and is the point of the
whole design: **an outside model has not read this repository and cannot run the
stack.** Its answer is an opinion to be checked against the code, then relayed
with its source attached — never restated in the manager's own voice, where a
teammate could not tell a guess from a decision.

The GM also has **no `Edit` and no `Write`.** A manager that edits files becomes
another developer racing the rest in one shared working tree, which is the most
expensive recurring failure this project has (`docs/09-multi-agent-retrospective.md`).
It reads, reasons, and rules. Someone else types.
