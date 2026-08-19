# What the transcript gate can see, and what it cannot

**Status: implemented, 19 Aug 2026.** Covers `EduAI_Transcript_Guard`, its
evidence harness `scripts/transcript-boundary.php`, and one measurement that is
deliberately not built yet.

Whisper does not raise an error on silence. It returns confident text, because
low-signal audio in its training data was subtitled with boilerplate. A silent
video on this stack came back as `" ."`, and the better-known form is worse —
*"Thank you for watching"*, *"Subtitles by the Amara.org community"*. Every
character valid, nothing downstream suspicious, and a language model will then
write fluent, confident study notes from it. Every surface looks like it works.

So the transcript is gated before anything downstream believes it, and the gate
refuses rather than degrades.

---

## 1. The four refusals

| Code | Asks | Owner's next move |
|---|---|---|
| `eduai_transcript_empty` | Did anything come back at all? (< 5 words) | Check the audio track exists |
| `eduai_transcript_boilerplate` | Is subtitle furniture the *whole* transcript? | Re-record; the audio is silent or too quiet |
| `eduai_transcript_repetitive` | Does any 200-word window repeat one phrase? | Re-record; the audio is unintelligible |
| `eduai_transcript_short` | Does this much audio hold this little speech? | Check the video has usable audio |
| `eduai_transcript_truncated` | Did the transcriber get through the whole recording? | Transcribe again — the video is fine |

The last two are separated on purpose. They read similarly and they are not the
same failure: one means the recording is quiet, the other means the transcriber
stopped early, and the owner has to do a different thing about each.

## 2. Why two durations, and why they must never be summed

`usable( string $text, ?float $heard = null, ?float $recorded = null )`

```
heard     Groq's own `duration` from verbose_json — how much audio Whisper processed
recorded  the file's duration, read off the media — how long the recording actually is
```

**Rate** — is this speech or is it noise? — divides words by `heard`, because
that is the audio these words are supposed to account for.

**Completeness** — did we get the whole thing? — compares `heard` with
`recorded`, and *cannot* be a rate. A transcriber that stops at minute four of
fifty reports a four-minute duration. Numerator and denominator shrink together,
words-per-minute stays a perfectly healthy 125, and a rate check passes on
precisely the failure it was built to catch.

> A denominator produced by the same process as the numerator is not evidence
> about that process.

This is the same shape that once let a coverage figure read 112% on a
half-indexed document: the 200-character chunk overlap inflated the numerator
while the denominator sat still. The fix there was contiguity — chunk indices
running `0..n-1` — rather than volume. Two independent durations disagreeing is
the audio form of that, and it reports *how much* is missing rather than merely
that something is.

`MIN_COVERAGE` (0.9) and `COVERAGE_SLACK` (30s) both have to trip. A container's
declared length and its audio stream's differ by a second or two often enough,
which on a six-second clip is a third of the ratio; and a proportionally small
shortfall on a long recording is still minutes of lost lecture. Either test
alone misfires.

### When `recorded` is missing

`file_duration()` cannot always measure. The rate check still runs on `heard` and
answers its own question honestly — a span of audio holding almost no speech is
a real finding about that span. **Completeness makes no claim at all**, and
`completeness_checked( $heard, $recorded )` returns false so the caller can say
so rather than imply a check that did not run.

That convention lives in the *wording* as much as the logic. The sparse-audio
message used to read "most of it is missing from the transcript" — a truncation
claim, emitted from a branch that runs whether or not the truncation check
executed. It now says the audio was quiet, which is what it measured.

## 3. What this still cannot see — scheduled, not built

**A hole.** Twenty-five minutes of speech spread across a fifty-minute recording
reads about 60 wpm and passes every check above clean. Coverage compares two
endpoints; rate divides two totals. Neither can see *where* the speech sits.

The measurement that can is already on the wire and currently discarded. Groq's
`verbose_json` returns `segments[]`, each carrying `start` and `end`. The gaps
between consecutive segments are literally the missing stretches — the direct
analogue of the chunk-index contiguity check, and the only thing here that
answers "is it all there?" rather than "is there enough of it?".

**Blocking on:** `EduAI_Transcript` storing `segments` alongside `text`,
`duration` and `language`. Cheap while the transcription half is being built,
awkward afterwards, because it means re-fetching every transcript already
cached.

## 4. A test may not carry its own copy of the judgement

`scripts/video-transcript-accept.php` defined `vt_is_real_transcript()` —
`chars >= 200 && words >= 40 && distinct >= 25` — and never called
`EduAI_Transcript_Guard`. It had a good control block proving that function
rejects `" ."`, boilerplate and a repeated word. Every assertion in it was true.

**The shipped guard could have been deleted from the plugin outright and that
acceptance run would still have passed every control it had.**

It diverged from the shipped gate on 5 of 13 measured cases, in both directions
— refusing a genuine six-second clip, and *accepting* the truncated lecture that
`eduai_transcript_truncated` exists to refuse. The failure the test was written
to catch was greenlit by the test.

This is the project's recurring defect wearing a test's clothes: a true
measurement attached to the wrong object. A passing test names a function. If
that function is a copy, the test is measuring the copy.

The same rule caught two false controls inside this file's own harness. The
buried-loop control passed while printing a false conclusion, because the
synthetic lecture recycled fifteen sentences — a 6,000-word transcript with 150
distinct words *is* what a Whisper loop looks like, so the global ratio was
already under threshold and the window had proved nothing. It failed a second
time at 0.082 because both halves of the fixture shared a term numbering and so
shared a vocabulary. **Every ratio measured against a repetitive fixture
describes the fixture.** Control 4 now asserts its own premise before it is
allowed to claim anything.

## 5. Running the evidence

```
docker compose --profile tools run --rm cli \
  wp eval-file /scripts/transcript-boundary.php --allow-root
```

19 assertions. The five that carry the design:

1. **The second duration earns its place** — the same four minutes of lecture
   from the same fifty-minute file, judged with and without `recorded`:
   invisible without it, caught with it. If those agreed, the argument should be
   deleted rather than defended.
2. **The gate reads the recording** — identical text at 6s and 50min, opposite
   verdicts.
3. **`completeness_checked()` reports unknown** when there is nothing to check
   against.
4. **The repetition window earns its place** — a loop buried in good speech,
   with the fixture's global ratio asserted to be above the old threshold first.
5. **No verdict asserts a check that did not run** — the truncation code is
   unreachable without both durations, as an invariant over every case rather
   than on the one input that exercises it.
