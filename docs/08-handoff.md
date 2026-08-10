# Handoff — where EduAi stands

Written at the end of the session that built the four AI tabs. It records what
is true, what is merely written, and the traps this project has paid for more
than once. Read §4 before trusting any green result, including your own.

---

## 1. The product

Seven tabs, all serving on the running stack at `http://localhost:8080`:

| Tab | Route | State |
|---|---|---|
| Home | `/` | Done |
| Library | `/library/` | Done. Material is reached through it, not from the nav |
| Summarise | `/summarise/` | Done, proven end to end against Groq |
| AiCalc | `/calc/` | Done, proven end to end. **Needs no API key** for arithmetic |
| Q&A | `/ask/` | Done — the existing chat panel, minus its duplicate Summarise tab |
| PrepareME | `/prepare/` | Proven end to end against Groq from pasted text. **Not from an uploaded file** — see §3 |
| My Progress | `/progress/` | Done. Not `/dashboard/` — that belongs to Tutor LMS |

The floating assistant widget is retired. `[eduai_panel]` and
`[eduai_summarizer]` still work, because pages embed them.

The provider is **Groq** (`openai/gpt-oss-120b` at the strongest tier); the key
lives server-side only. Anthropic and Z.ai are wired but have no key.

---

## 2. What was proven by running it

Everything below was executed, not inspected:

- **AiCalc** through `rest_do_request()`: `2+3*4=14`, `-3^2=-9`, `(-2)^2=4`,
  `2^3^2=512`, `0.1+0.2=0.3`, and symbolic input routing to the model.
- **Summarise** with a real `.pptx`, built by `scripts/make-lecture-fixture.php`:
  extraction, then a live summary naming thylakoid, Calvin, RuBisCO and NADPH.
  The deck's speaker notes sit in `notesSlide1` while belonging to slide 2, so a
  numeric guess mis-attaches them — and the summary's "one thing students
  usually get wrong" came from the note, which appears nowhere on the slides.
  The generator is committed rather than the deck: the traps are the point, and
  a zip cannot be reviewed. Its header explains all three.
- **PrepareME's renderer**, both ship gates, against the *shipped* markup
  emitted by `do_shortcode()` rather than a hand-copied replica.
- **The database migration** for the exam tables, by watching them appear.
- **The redirect and route changes**, by fetching every page.

---

## 3. What is not proven, and must not be reported as done

**PrepareME's round trip is proven — from pasted text, not from a file.** The
tester ran generate → answer → mark against live Groq after this was first
written. Generation returned schema-valid JSON on the first call, the repair
retry never fired, the 4/4/2 band mix came back as asked, and marking respected
all four §5.2 rules. `answer_index` is genuinely 0-based, established the strong
way: answering every MCQ with the paper's *own* key scored 7/7, which a 1-based
paper could not do. Eleven seconds and ~6.6k tokens for a complete exam.

**Extraction inside that round trip is still uncovered.** The run used pasted
text, so the `notesSlide1`-belongs-to-slide-2 trap was never exercised on the
PrepareME path. That is the half where failure is silent: the exam gets built
from less than the deck contains and nothing downstream can tell. The deck is
built and waiting at `scripts/roundtrip-deck.pptx` (543 chars extracted, three
slides, note on slide 2). Note that `scripts/` is **not** mounted into
`scholaris-wp` — only into the compose `cli` service — so a harness running
there needs a `docker cp` first, or it silently falls back to pasted text.

Also outstanding:

- `scripts/grade-adversarial.php`, `projection-leak.php`, `submit-contract.php`,
  `page-drift.php` and `download-gate.php` **need a live WordPress**, so none of
  them run in CI. They are on the pre-release checklist in docs/03. A guard that
  never runs is a guard that passes forever.
- Nothing is deployed. `localhost:8080` works on one machine, while Docker runs.

---

## 4. Traps this project has paid for repeatedly

**`setup.sh` describes a fresh install, not your install.** It cost four
separate bugs: `/ask/` and `/prepare/` returning 404 while present in the
script; a content change that reached only the script; and finally the nav and
the site title, which left the owner looking at a site still called Scholaris
with four tabs missing. `scripts/page-drift.php` catches the *pages* half. It
does **not** check the menu or the title — if you change either, re-run
`setup.sh` against the stack and look at the rendered page.

**A 200 is not a working page.** Every one of those bugs returned 200. The
progress page returned 200 for weeks while rendering Tutor LMS's login form.
Verifying that a URL responds, or that a redirect points somewhere, says nothing
about what a student sees. Look at the rendered output.

**A check that cannot fail is worse than no check.** Four instances in one week:
two scripts that exited 0 without running (`defined( 'ABSPATH' ) || exit` with
no define — see `abspath-tripwire-order`), a pre-commit hook committed as an
empty file, and a redaction guard wired into no CI step. Before believing a
green, ask what would make it red.

**Mutate, and confirm the mutation applied.** Twice, a `sed` silently did
nothing and the "check survived" reading was meaningless. Count the target
string before and after. This is now the standing rule.

**Substring matching is not equality.** The AiCalc label check passed a
truncated label because it was a prefix of the real one; a PrepareME assertion
counted `eduai-prep__q` twice because it matches `eduai-prep__qhead`. Assert on
a delimited match.

**Test harnesses here have been wrong more often than the code.** That is a
reason to check the assertion first when something goes red — and never a reason
to conclude the code is fine. Prove the assertion can see the thing at all, then
decide which side broke.

---

## 5. Running the checks

Local, no WordPress needed:

```bash
perl scripts/php-sanity.pl        # bracket balance, stands in for php -l
perl scripts/contract-tests.pl    # 14 cross-file contracts
perl scripts/check-no-secrets.pl  # API keys in anything shippable
node scripts/check-inline-js.js   # inline <script> blocks in the HTML pages
node scripts/calc-parity.js       # the JS arithmetic reference
```

Needs the stack up:

```bash
docker exec -i scholaris-wp php /path/to/scripts/calc-parity.php
docker exec -i scholaris-wp php /path/to/scripts/redaction-guard.php
docker compose --profile tools run --rm cli wp eval-file /scripts/page-drift.php --allow-root
```

`tools/prepare-gate.html` re-runs PrepareME's two ship gates in a browser. It
inlines the shipped template — rebuild it from `do_shortcode()` output if the
template changes, or it tests a replica.

**Windows note:** predicting CI locally needs
`git -c core.autocrlf=false checkout-index -a -f --prefix=DIR/`. Without the
flag the export carries CRLF no Linux runner sees, and checks fail for a reason
that does not exist. Expect one `skip` there — `preview-copies-in-sync` needs
the gitignored root `preview.html`.

---

## 6. Documents that matter

- `docs/06-eduai-rebuild.md` — the restructure: IA, the four tabs, the exam
  schema (§5 is normative), the owner's settled decisions (§4).
- `docs/07-prepareme-contract.md` — every AI route's request and response,
  including `/calc` (§7) and `/summarize` (§8).
- `docs/05-frontend-handoff.md` — page inventory and the auth seam.
- `docs/03-hosting-deployment.md` — the pre-release checklist the WordPress-only
  checks live on.
