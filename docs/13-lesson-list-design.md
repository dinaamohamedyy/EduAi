# The lesson list — which surface it is on, and how it should look

Design response to the Tech Manager's brief of 12 Aug 2026. **The brief's
premise is wrong about the surface**, so §1 establishes where the three lines
actually are before §2 designs anything. Geometry is front-end's to measure; no
pixel claim here is measured.

---

## 1. The surface is the card — and its hazard is live

**This section originally argued the opposite and was wrong by the time it was
read.** The history is worth keeping, because the conclusion it produced is what
matters now:

```
d505135  lessons on the card, with links
c4ba888  reverted — "my premise was false"
d3f2126  restored — the owner saw it on screen and approved it
```

My check was made between `c4ba888` and `d3f2126`, so `/library/` genuinely had
no lesson titles when I fetched it and had them again by the time the design
arrived. The screenshot is the card, not Tutor's page — decisive tell: it shows
three plain lines with **no lock icons**, and Tutor puts a lock on every row.

### The hazard I raised is real and currently shipping

Verified anonymously on `/library/` just now:

```html
<ul class="sl-course__lessons">
  <li><a href=".../lessons/supervised-learning-round-2/">Supervised Learning Round 2</a></li>
  <li><a href=".../lessons/least-squares-in-d-dimensions/">Least Squares in d-Dimensions</a></li>
  <li><a href=".../lessons/matrix-notation/">Matrix Notation</a></li>
</ul>
```

Three anchors, no lock, served to a logged-out visitor. `c4ba888`'s sentence
applies to this markup word for word — *"I replaced an honest lock with a link
to a broken page"* — because those hrefs reach Tutor's learning area, which
emits three concatenated documents to anyone without access. **The owner is not
enrolled in his own course, so he is one of those people.**

So the design brief and the defect are the same piece of work: §4 is not a note
beside the visual treatment, it *is* the fix.

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

## 4. Locked: dim the affordance, not the information — and this is the fix

The brief's second question was whether the card should say so before the click.
It should, and the answer resolves the live hazard in §1 rather than merely
labelling it.

**A row the viewer cannot open must not be a link.**

Front-end already has the enrolment state, so the row has two forms:

| Viewer | Row |
|---|---|
| Enrolled | Anchor to the lesson permalink, as today. |
| Not enrolled | Same list, same legibility, **no anchor**, with a lock. |

Nobody is promised a page they will be bounced from, and nobody loses the table
of contents.

### One mistake, three surfaces

This is not a lesson-list detail. The same principle resolved three separate
defects on 12 Aug 2026:

| Surface | The broken promise |
|---|---|
| The material download button | Offered a file the request would refuse |
| The scoped Summarise button | Offered an action the viewer could not take |
| The lesson link (here) | Offers a page the viewer will be bounced from |

**The server decides what the control _is_, not merely where it points.** An
href that bounces you is a promise made by the affordance and broken by the
destination — and the affordance is the part the person believes, because it is
the part they can see before they act.

Naming it as one mistake rather than three is the point. Three separate fixes
teach nothing about the fourth surface; one rule applies to it before it is
built. **Wherever access is decided in one place and rendered in another, the
renderer must ask, not assume** — which is the same sentence as
`SL_Private::is_secured()` in `docs/12` §"The label", arrived at from a
different direction.

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

All of the brief's constraints bind. An earlier draft relaxed two of them on the
belief that the surface was Tutor's course page; on a card in a grid they hold:

- **Bounded height / cap at five, remainder as a count.** Applies. Its whole
  purpose is that a card must not grow with its content, and this is a card.
- **One line with ellipsis.** Binds, and matters more once a number sits in the
  gutter — see §3.
- **One row at 1400 / 900 / 375, no horizontal overflow.** Applies directly;
  this work is inside those cards.
- **Titles link to permalinks.** Not dropped — **earned by access.** Conditional
  on enrolment, per §4.

### One thing not to add

`ab7f388` records which pages of the deck each lesson covers, and a page range
would sit naturally beside a number — *"1 · Supervised Learning Round 2 ·
pp. 1–12"*. **Leave it off the card.**

The card is the tightest row on the page, titles already need ellipsis, and §3
is spending the gutter. Adding a second piece of metadata to the narrowest
column is how the wrap gets created that front-end then has to solve. The page
range is good information on the lesson itself or on the course page, where
there is room for it.

**Nothing above is measured.** The numbered gutter adds width in a row that
already has an icon slot, and long titles plus a duration and a status in one
row is exactly the kind of thing that reads fine in source and wraps in a
browser. Front-end owns the three widths.
