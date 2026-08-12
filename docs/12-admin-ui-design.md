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

- `_scholaris_video_type` — `none` | `link` | `upload`
- `_scholaris_video` — the URL, or the attachment ID

Storing the type explicitly is what makes the *consumer* unambiguous too. With
one key per source the renderer has to guess precedence, and every guess is a
place the two halves can disagree — the exact class of bug this project keeps
finding. Back-end reached the same conclusion independently.

**Agree the exact key names before anyone writes them.** Three sessions have now
described this enum and each used a different name for it. A discriminator whose
whole purpose is to be the single source of truth is a poor thing to have two
spellings of; the names above are a proposal, not a claim on the namespace.

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

### The label

Shown whenever a file is attached and access is `Signed-in students` — if access
is "Anyone" there is no false belief to correct.

> **The file itself is not protected.** Anyone with its address can open it,
> signed in or not — this setting only controls the button on the page.

Then one remedy line, and **it must branch**, because the drafted single version
is impossible to follow in the case that was measured:

- **Video attached:** *For a lecture that must stay inside the class, paste an
  unlisted YouTube or Vimeo link instead of uploading.*
- **Document attached:** *Don't upload anything here that must stay inside the
  class.*

A PDF cannot be pasted as a Vimeo link. Telling the owner to do the impossible
in exactly the case we proved would spend the credibility this label needs.

### Show him the URL, and invite him to test it

The spec's instinct to print the file's real public URL is the strongest part of
the draft, and it should go further: make it clickable, with

> *Open this in a private window to see what a stranger sees.*

That converts a claim into something he can verify in ten seconds. It is the
same method this project has used on everything else, applied to the one belief
we most need him not to hold by accident.

### It is interim, and must be driven by one flag

**Write this as production text, not as a placeholder.** An earlier draft of
this section said the label had a near end date, on a measurement that has since
been overturned: Apache appeared to satisfy byte ranges *above* PHP, which would
have made gated video free. Re-measured at 2 MB rather than 86 bytes, the
handler **ignores the range and sends the whole file from byte 0** — Apache only
slices small buffered responses, and the boundary sits between 1 KB and 64 KB.

The fix still works, but gated video now needs real streaming in PHP rather than
something inherited for nothing. **The warning is live for considerably longer
than first implied**, so it should read as finished copy that an owner relies on,
not as scaffolding someone will delete next week.

*(The measurement lesson is the same one this project keeps re-learning: 86
bytes and 2 MB are not the same test. A probe run below the scale where the
mechanism engages returns a confident answer about nothing — the sibling of the
wp-cli upload check that reports a limit nobody is subject to.)*

**Drive every part of it from one flag** — the label, the list column, the
remedy lines. I had planned this as insurance against the Tester's finding going
either way; it is load-bearing now, because whoever eventually switches it off
must not have to hunt for hard-coded copy to do it.

Worth knowing how bad the interim actually is, because it justifies the
bluntness of the wording: `/wp-json/wp/v2/media` publishes `source_url` for
every attachment **anonymously**. There is a public index of the paths, so this
is not even security by obscurity — "anyone with the file address" understates
it, since anyone can list the addresses.

### It warns, it does not block

A members-only material with a deliberately public file is a reasonable thing to
want, and hard-blocking a combination the platform can express only teaches him
to route around the tool. What must be impossible is *arriving* there without
having read this.

### One addition, now that the leak is confirmed

**The same flag belongs on the study-material list screen**, as a column. A
warning that only appears while editing is invisible to someone auditing what is
already published — and since this is true of every file on the site today, the
material that leaks is far likelier to be one created months ago than the one
currently open.
