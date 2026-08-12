# Admin UI — the owner's landing page and the material video control

Design response to the Tech Manager's brief of 10 Aug 2026. Two items only; the
ruling that this lives in wp-admin rather than a bespoke console is taken as
given and not revisited.

`dina` is an administrator on the live stack. Everything below assumes a
non-developer who knows their own material and does not know WordPress.

---

## 1. The landing page

### The design constraint that decides everything else

**Use wp-admin's own furniture, not the Scholaris design system.** `.wrap`,
`h1.wp-heading-inline`, `.button.button-primary`, dashicons, core's card
spacing. No Fraunces, no laurel, no bronze.

This is counterintuitive and it is the whole point of "lives comfortably
beside". A branded panel inside wp-admin does not read as *part of the
platform* — it reads as a plugin advertising itself, which is the visual
language every upsell banner in the WordPress ecosystem already uses. The theme
is the students' surface. The admin is the owner's tool, and it should look
like the tool he already has.

### The shape

A top-level admin page at menu position 2, directly under Dashboard, with a
distinctive dashicon. Four destinations, but **not four equal cards** — three of
them are "make a thing" and one is "look at data", and pretending otherwise
makes the page harder to read, not more consistent:

| Card | Primary action | Secondary |
|---|---|---|
| Study materials | **Add a lecture or document** | See all 48 |
| Courses | **Add a course** | See all 12 |
| Question banks | **Build a question bank** | See all 6 |
| Students | *(none — they register themselves)* | See all 137 · 3 new this week |

**Each card carries a live count.** For a non-developer the count is the
feedback loop: upload a material, come back, see 49. Without it the page is a
menu, and a menu tells you nothing about whether the last thing you did worked.

The students card is deliberately a different shape — a figure and a
"3 new this week", no primary button. It is the only one of the four he cannot
create into, and giving it a button anyway would be a lie about what the page
does.

### The labelling rule — and the one thing I could not verify

**Label every destination with the word he will see when he arrives.** The
router's only job is to survive the transition; a card that says "Question
banks" and lands on a screen headed "Quizzes" has actively made things worse
than no router at all, because now he doubts he clicked the right thing.

Where our word and the destination's word differ, show both:
`Question banks` with `in Tutor LMS → Quizzes` beneath it in small text.

**Unverified:** Tutor LMS is installed by `setup.sh` (line 63) and is not in the
repository, so I could not read its admin menu labels from disk and I am not
asserting them. Someone with the live admin open should walk the four
destinations and write down the exact heading each one lands on, then set the
card labels against that list. This is fifteen minutes and it is the difference
between a router that works and one that adds a step.

### Getting him there

Filter `login_redirect` for administrators to this page. `docs/05` §"Still
open" already lists per-role `login_redirect` as an intended extension point,
so this is using a documented seam rather than inventing one.

**Do not remove or hide other admin menu items.** It is tempting — the sidebar
is long and most of it is not his. But hiding Media breaks the file picker's
mental model, hiding Users breaks password resets, and a hidden item is
invisible when something goes wrong. The router earns its place by being the
best starting point, not by removing the alternatives.

---

## 2. The material video control

### The failure being designed out

Two input boxes where only one takes effect. A value valid where it is entered
and ignored where it is consumed, with nothing on screen saying so. The
requirement is that the person filling it in **cannot be wrong about which
source is live**.

### One control, not two fields

A single "Video" control with an explicit source choice. **Only the chosen
branch's input is rendered** — the other is absent, not disabled. A disabled
field still shows a value and invites exactly the question this design exists to
prevent: *my link is sitting right there, why is it not being used?* Absence
cannot be misread.

```
Video
  ( ) No video
  (•) Link to a video          recommended
      [ https://youtube.com/watch?v=… ]
      YouTube, Vimeo, or any link your students can open.
  ( ) Upload a video file
```

New materials start on **No video** — most materials are documents. Within the
video choices, **link is listed first and marked recommended**, and that
ordering is the steer the two constraints below justify. A default is the
strongest nudge available and costs nothing.

### Storage — the discriminator is load-bearing

Two meta keys, not one per source:

**Settled 10 Aug 2026 — these are the shipped keys, verified on the live stack.
Use them; the names I originally proposed here were a fourth spelling and are
gone.**

- `_scholaris_video_source` — `''` | `link` | `file`
- `_scholaris_video_url` — the URL
- `_scholaris_video_id` — the attachment ID

Storing the type explicitly is what makes the *consumer* unambiguous too. With
one key per source the renderer has to guess precedence, and every guess is a
place the two halves can disagree — the exact class of bug this project keeps
finding. Back-end reached the same conclusion independently.

Three sessions each described this enum under a different name, which is a poor
fate for a field whose entire purpose is to be the single source of truth. It
was settled by reading the shipped code rather than by choosing between
proposals — the right tiebreak, since one of the four spellings was already
running and verified and the others were only written down.

Note the shape differs from what I proposed: the source and the two values live
in **three** keys rather than two. The discriminator argument is unaffected —
`_scholaris_video_source` is still the single thing the renderer reads to know
which of the others to trust, and it never has to infer precedence from which
field happens to be populated.

**Switching source discards the other value on save, and the control says so
before saving:** *"The link will be removed when you save."* Silent retention is
how a value ends up valid where it was entered and ignored where it is consumed.
An uploaded file already in the media library stays there; it is simply no
longer referenced.

### The read-back

After saving, the control leads with what actually happens:

> **Students will see the linked video** — `youtu.be/8Kx2Qd`

He verifies against that sentence, not against the inputs. This is the part that
makes the requirement testable: if the read-back and the student page ever
disagree, that is a bug with an obvious symptom, rather than a silent
mismatch nobody can see.

### Constraint A — the 64 MB cap

`php/uploads.ini` sets `upload_max_filesize` and `post_max_size` to 64M. A
one-hour lecture recording is routinely several hundred MB to a few GB, so
**upload will fail for most real lecture video.**

- State the number **before** he picks a file, in the upload branch's hint.
- Reject an oversize file **at selection**, not at save, and name the remedy in
  the same breath: *"That file is 480 MB. The limit is 64 MB. Put it on YouTube
  or Vimeo and paste the link instead."*
**The number is 64 MB, and it is measured** — back-end pushed a real 10 MB mp4
through `async-upload.php` as `dina` in 0.4s. Use 64 in the copy.

The instinct to distrust the ini did catch something, though it was not what I
expected: **the `cli` container runs PHP's defaults (2M/8M)** because
`uploads.ini` is mounted into the web service only, so any wp-cli measurement
silently reports 2 MB. That misled two people before it was spotted. If a
future change moves this number, measure it **through the web service the owner
actually uploads to** — a wp-cli check will confirm a limit nobody is subject
to. Back-end is closing the compose gap so the two agree.

**Keep the steer to link regardless of gating.** 64 MB will not hold a
45-minute lecture, and that stays true whether or not the file is private.

### Constraint B — the gating hole (revised 10 Aug 2026, measured)

**My first draft of this section was wrong, and wrong in the direction that
matters.** I wrote that the warning belonged in the video control and not beside
the access select, because "the access select is doing exactly what it says".
Measurement says it is not. A `members`-only material's file returns **200 to an
anonymous request** at its raw `wp-content/uploads/…` URL while the plugin's own
download route correctly returns 403 — and the case actually measured was a
`.txt` on a document, not a video. This is already true of every PDF on the
site.

So the false belief is not "video behaves oddly". It is **"Who can download"
means the file is protected**, and it is created by the select itself. Two
consequences, both reversing what I originally specified:

- **The label goes next to the access select**, at the moment of choosing, not
  in the video box and not in a help panel.
- **It keys on "a file is attached", never on "the file is a video."** Scoping
  it to video would label the case we imagined and leave unlabelled the case we
  proved.

### The cheapest fix is the field's own name

The drafted warning spends two of its three sentences un-teaching the label
sitting directly beside it. That is a warning fighting its neighbour, which is a
design failing rather than a copy problem — and the label is the cheaper thing
to change.

`Who can download` is a promise the system does not keep. What the setting
actually does is decide whether the download button appears on the material
page. Name it that:

```
Show the download button to
  ( ) Anyone
  (•) Signed-in students
```

Rename first, and the warning gets shorter, stops contradicting anything, and
has only one job left.

### The label — INVERTED 12 Aug 2026, and the trigger changed with it

`SL_Private` closed the leak: files on a `members` material move to an
Apache-denied directory and are served only through the nonced handler. The
warning below was true when written and is **false now**, on exactly the
materials it was shown on.

**Key the copy on `SL_Private::is_secured( $material_id )`, never on
`_scholaris_access === 'members'`.** This is the whole lesson of the original
bug repeating one level up. The old label described what the *setting meant*
rather than what the *system did*, and inverting it to describe intent again —
"access is members, therefore protected" — would rebuild the same defect with
the sign flipped, which is strictly worse: a false reassurance is the failure
mode this project has hit all week, and a false warning is at least
conservative.

The predicate already exists and back-end's own docblock names its purpose:
*"the question the rest of the plugin should ask before it claims a file is
protected."* Ask it.

**Three states, not two.** The middle one is the reason this cannot be a
boolean on the access level:

| Condition | Copy |
|---|---|
| `public` + file | **Nothing.** The file being fetchable is the point of "Anyone"; a notice there is noise. |
| `members` + file + `is_secured()` | Reassurance, below. |
| `members` + file + **not** `is_secured()` | A placement **failure**. Rare, real, and reportable from nothing else on screen. |

**State 2 — the protection works:**

> **This file is protected.** It is stored outside the public folder and served
> only to signed-in students. Its web address returns "Forbidden" to everyone,
> including you.
>
> [`…/scholaris-private/lecture-3.mp4`] — *open it in a private window; the
> "Forbidden" page is the protection working.*

The last clause is load-bearing. The proof-of-safety demo produces an **error
page**, and an error page shown to an owner without framing reads as *my file is
broken*. Say what he will see before he clicks it, or the demonstration
undermines the sentence it is meant to prove.

**State 3 — a fault, not a backlog (revised 12 Aug 2026):**

> **This file could not be protected.** It is set to signed-in students, but
> moving it out of the public folder failed, so its address still works for
> anyone. This one needs fixing on the server — file permissions or disk space
> are the usual causes.

An earlier draft said *"not protected **yet** … Save this material to move it."*
That was backlog framing, and it is now wrong in a way that would waste the
owner's time: placement runs on upload, on meta change, on save, and on
activation, plus `maybe_upgrade()` at `plugins_loaded` for sites that were
already active. **"Exposed" is no longer a state the system reaches on its
own**, so if `is_secured()` is false, something *tried and failed* —
`reconcile()` returns a `failed` array precisely because moving a file can fail
on permissions or disk. Telling him to save it again invites him to repeat an
action that already didn't work and to conclude the warning is noise when it
doesn't clear.

So the copy must not imply an editor-side remedy. Name it as a server fault, say
what usually causes it, and be honest that this is not one he fixes by clicking
Update.

### Show him the URL — same mechanism, opposite conclusion

The clickable address was the strongest part of the original design and it
survives the inversion intact, which is the point worth noticing: it was never
an argument, it was an **instrument**. It proved danger when there was danger and
it proves safety now, and it will keep telling the truth if these facts change a
third time. Copy that asserts a conclusion goes stale; copy that hands the owner
a way to check does not.

In state 3 it still shows the public address that works. In state 2 it shows the
private one that does not.

### Not a flag any more — a predicate

Two earlier drafts said this copy hung off one boolean someone would eventually
switch off. **Delete that idea.** A flag is a statement about the world made
once, by hand, and it goes stale silently — which is precisely how the label
ended up lying today. `is_secured()` is asked per material, per render, and
cannot drift from the thing it describes.

The flag was the right design when the answer was the same for every material.
It stopped being right the moment the answer became per-file, and per-file is
what it now is: state 3 exists for individual materials while state 2 is true of
their neighbours.

### The list column — invert it, and it stops being un-failable

The column was flagging materials whose files were exposed. With the leak closed
that would be empty forever, and an always-empty check is one that cannot fail —
the exact pattern in `docs/09` §6.7.

**Flag state 3 instead: `members` materials that are not `is_secured()`.** That
column is not empty today and it is not decorative:

- Every material created before `SL_Private` shipped and not re-saved since —
  nothing has changed its meta, so nothing has reconciled it.
- Any material whose move **failed**.

**On `migrate()` — my earlier claim that it had no caller was stale, and the
correction makes the point sharper rather than dissolving it.** It is called at
`scholaris-library.php:73`, inside `activate()`, added in `a714af5`. But
`activate()` fires on **plugin activation**, and the plugin was already active
when that code landed — so on this site, and on every existing install,
**`migrate()` has still never run.** Every correctly-placed file got there
through `reconcile()` on a meta write, not through the sweep. The sweep protects
fresh installs, which is what its comment says it is for; it does nothing for
the install we have.

**What the column reports has changed, and its wording should change with it.**
It is no longer a migration backlog. Head it on placement rather than on
exposure — *File placement*, values *OK* / *Failed* — because non-empty is now a
**fault report**: this material tried to protect its file and could not.

That is a better column than the one I proposed, and it is better for the reason
that makes checks trustworthy: it reports a condition the system cannot reach on
its own, so a row appearing is genuinely information. The version I designed
would have counted work not yet done, which drains to zero and then sits empty
forever — the un-failable check, arrived at from the honest direction.

**Keep the epistemic note attached to it.** Empty-because-clear and
empty-because-unable are the same data with opposite meanings, and only knowing
*why* distinguishes them. A future reader who finds this column empty and
concludes it can be deleted is one step from rebuilding the defect.

### What the reassurance deliberately does not claim

State 2 says "**this file** is protected", not "your uploads are protected", and
the narrowness is load-bearing — **do not broaden it in a later copy pass.**

The reason is a gap outside this control's frame entirely: media uploaded
straight into the library and attached to **no material** has no access setting,
no editor screen and no `is_secured()` answer, because there is no material to
ask about. Measured on the live stack, a 4.6 MB PDF in that state answered
**HTTP 200 anonymously** and appeared in the public `/wp-json/wp/v2/media` index.

A sentence on the material screen cannot fix files that are not on the material
screen, and attempting it would be the wrong instrument. But an owner who reads
a broad reassurance may reasonably conclude it covers everything he uploads, and
it does not. Scoped to "this file", the sentence stays true. Raised with
back-end as its own item, alongside the REST index.

### It warns, it does not block

A members-only material with a deliberately public file is a reasonable thing to
want, and hard-blocking a combination the platform can express only teaches him
to route around the tool. What must be impossible is *arriving* there without
having read what is true.

### The upload window — closed, and refusing to write around it is why

**Fixed in `3e49af2`; verified at `class-sl-private.php:84`.** Nothing here needs
copy.

It was worse than described when I declined to paper over it. "Until the post is
saved" is user-controlled, and if the editor **abandons the draft it means
forever** — with the file at a real, anonymously-indexed URL the whole time. It
was a hole, not a transient.

Worth recording *why* the technical fix was available when the material-shaped
one was not, because it is the same frame problem as unattached media seen from
the other side. It could not go through `reconcile()`: at `add_attachment` the
material does not reference the file yet — `_scholaris_video_id` is written on
save — so the material's own meta says there is nothing to move. The rule had to
be about the **attachment**: *a file uploaded into restricted material is
restricted from the moment it exists.* A control that reasons per material is
blind to a file that has not been claimed by one yet.

**The general lesson for this document: a caveat is what you write when you have
decided not to fix something.** Raising it as a technical item instead of
drafting a careful sentence about it is what got it closed — and a sentence would
have been on every material forever, describing a hole rather than closing it.

`maybe_upgrade()` at `plugins_loaded` (same commit) closes the other gap I
flagged: a plugin already active when new code arrives never fires `activate()`,
which is the deploy shape of every existing site including this one.
