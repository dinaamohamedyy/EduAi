# The lesson list — which surface it is on, and how it should look

Design response to the Tech Manager's brief of 12 Aug 2026. **The brief's
premise is wrong about the surface**, so §1 establishes where the three lines
actually are before §2 designs anything. Geometry is front-end's to measure; no
pixel claim here is measured.

---

## 1. The lessons are not on the library card

The brief says the library card shows its lessons and they render as three bare
text lines. **The card has had no lesson titles since `c4ba888`**, which
reverted exactly that feature three commits ago.

Fetched anonymously, `/library/` renders per course:

```
sl-course__title · sl-course__meta ("3 lessons") · sl-course__from
```

No lesson titles, no lesson links. The three lines the owner screenshotted are
on **Tutor's course page**, `/courses/machine-learning/`:

```
3 × tutor-icon-lock-line     3 × Enroll     1 × tutor-course-entry-box
Supervised Learning Round 2 · Least Squares in d-Dimensions · Matrix Notation
```

This also reads his words more naturally than the card does. *"Change the shape
of the widget from outside… make the lessons from outside look better"* — the
lessons belong to Tutor's widget, and "from outside" is our theme restyling
someone else's markup. That is the job.

### Do not put them back on the card

`c4ba888` reverted the card version for a reason that has not changed: the card's
plain anchors went straight to lesson permalinks, **bypassing the padlock** and
landing an unenrolled student on the learning area's no-access path. In its own
words — *"I replaced an honest lock with a link to a broken page."*

That is the failure this team has been fixing all week, and re-styling those
links would make a more attractive version of it. The card as it stands —
title, count, source deck — is correct and should not change.

---

## 2. The markup we are actually styling

Verified from the live page. Each lesson is already richer than "a bare line":

```html
<ul class="tutor-course-content-list">
  <li class="tutor-course-content-list-item">
    <div class="tutor-d-flex">
      <span class="…-item-icon tutor-icon-document-text"></span>
      <h5 class="…-item-title">Supervised Learning Round 2</h5>
    </div>
    <div class="tutor-d-flex …">
      <span class="…-item-duration"></span>
      <span class="…-item-status"><i class="tutor-icon-lock-line"></i></span>
    </div>
  </li>
</ul>
```

Inside `tutor-accordion-item` / `-header` / `-body`. So there is an icon slot, a
title, a duration slot and a status slot per row, in a real `<ul>` — which is
what makes everything below possible from CSS alone, with no template override
and no fork of Tutor.

**It reads as bare because nothing distinguishes the rows from each other.**
Three identical document icons, three identical locks, three titles at the same
weight, no order expressed.

---

## 3. They are sections of one lecture — number them

The brief's first question. These are ordered, sequential parts of one 44-slide
deck, split on the lecturer's own section markers. A flat list says they are
interchangeable; they are not.

**A numbered spine, from a CSS counter on the existing `<ul>`:**

- `counter-reset` on `.tutor-course-content-list`, `counter-increment` on the
  item, and the number rendered into the row's left gutter in `--font-mono` —
  the face this design system already uses for meta, figures and table headers.
- A hairline rule running behind the numbers, connecting them vertically. That
  is the difference between "three things" and "one thing in three parts", and
  it costs one pseudo-element.

**Replace the document icon with the number rather than adding to it.** All
three rows carry `tutor-icon-document-text` — an icon that never varies is
decoration, while a number that always varies is structure. The gutter is one
slot and the number has the better claim on it.

If a course later mixes lessons with quizzes, the icon becomes informative again
and should come back *beside* the number, not instead of it.

---

## 4. Locked: dim the affordance, not the information

The brief's second question — should the card warn before the click? On this
surface the question dissolves: **Tutor already says it**, three times over, with
a lock per row, an entry box and an Enroll button. Nothing needs adding. What is
needed is making the state legible instead of incidental.

**The titles stay at full contrast. The lock is what dims.**

This is the judgement worth arguing. The instinct with a locked list is to grey
it out, and it is wrong here: the titles are the reason to enrol. Greyed titles
turn a table of contents into a wall, and the student loses the one thing that
would persuade them — *this is what is inside*. So:

- Title: full `--text` weight and colour, one line, ellipsis. Long titles are
  already real (`Least Squares in d-Dimensions`) and will get longer.
- Lock: `--text-faint`, small, in the status slot where Tutor already puts it.
- Row: **not** styled as a link. There is no anchor, and there must not appear to
  be one — that is precisely the promise `c4ba888` was reverted for making.

**Keep the per-row lock even though all three are identical today.** Tutor
supports preview lessons, so the row-level state is meaningful in general and
uniform only by accident of this course. Collapsing it to one list-level
sentence would look tidier now and be wrong the first time he marks a lesson as
a preview.

---

## 5. Courses and loose material are different kinds of thing

The brief's third question. **Not siblings.** A course is a container and a
destination; a material is a file, and the heading already calls it *"Material
not yet in a course"* — which is inbox language, a to-do rather than a
destination.

Rendering both as cards in a grid says "these are peers, pick one", and they are
not. Courses keep the card treatment. Loose material should be **visually
subordinate**: a denser list — title, type, date — without card chrome, so the
page reads as *"here are your courses, and here is what has not been filed
yet."* The styling should agree with the words already on the page.

Worth noting for whoever builds it: that section is closer to an owner's to-do
list than to a student's browse surface, and it will empty as material gets
filed. It should look like something that can be empty without the page looking
broken.

---

## Constraints — which still bind

The brief's constraints were written for the card. The surface changed, so:

- **Bounded height / cap at five:** does not apply here. The cap existed so a
  *card in a grid of cards* would not grow with content. A course page's own
  lesson list is the page's subject; it should show every lesson.
- **One line with ellipsis:** still binds, and matters more — see §4.
- **One row at 1400 / 900 / 375, no horizontal overflow:** unchanged as a
  requirement for the library grid, which this work does not touch.
- **Titles link to permalinks:** **dropped, deliberately.** They are not links
  on this surface and must not become them; see §1 and §4.

**Nothing above is measured.** The numbered gutter adds width in a row that
already has an icon slot, and long titles plus a duration and a status in one
row is exactly the kind of thing that reads fine in source and wraps in a
browser. Front-end owns the three widths.
