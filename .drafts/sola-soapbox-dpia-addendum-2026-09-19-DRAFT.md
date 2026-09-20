# SOLA Soapbox DPIA — Addendum A (body-language vision, v7.5.1)

**Status: DRAFT, UNSIGNED. Prepared for review by the acting data-protection authority.**
This does not amend the signed assessment of 2026-07-11. It records what changed, so that
assessment can be re-signed or revised.

**Addendum to:** `.drafts/sola-soapbox-dpia-2026-07-11.md` (assessed version 6.8.31, signed by
Tom Caswell as Chief Data Officer)
**Covers versions:** 7.5.1 and 7.5.2
**Prepared:** 19 September 2026

---

## 0. Why this exists

The signed assessment closes with its own re-assessment trigger, in section 7:

> Revisit if a future mode stores video beyond the retention window, **sends video frames to a
> model**, or enables slide vision by default (each would require re-assessing section 5).

v7.5.1 sends video frames to a model. The trigger has fired. This addendum sets out what the feature
now does, what changed in the two releases since, and the four statements in the signed document that
are no longer accurate.

Two things are worth saying plainly at the top. The new processing is **off by default** and no
Saylor course has it enabled at the time of writing. And the retention defect this addendum describes
in section 3 was found and fixed before any production site ran the feature, so it produced no data.

---

## 1. What v7.5.1 added

Body-language and camera-presence feedback, behind the site setting `soapbox_gesture_vision`,
**off by default**, with no per-course override and no learner opt-in.

When it is on and a learner records in video mode:

1. The **browser** samples six still frames from the recorded video and composes them into one
   JPEG contact sheet, 3 by 2, 426x240 per cell. The frames never leave the learner's machine
   except as that sheet.
2. The sheet is uploaded to the same S3-compatible bucket as the recording, under its own key, and
   is recorded on the attempt as `frames_key`.
3. One vision pass sends the sheet to the configured vision model (default `gpt-4o-mini`) with a
   prompt that asks it to **describe** what the hands, posture and eyes are doing, and explicitly
   not to describe appearance, ethnicity, clothing or setting.
4. The description is capped at 900 characters, stored on the score row, and used to score two
   rubric criteria: Body Language and Gestures, and Eye Contact and Camera Presence.

Frame sampling happens in the browser because `ffmpeg` is not installed on the fleet. A server-side
sampler would have failed silently and the visual criteria would simply never have been assessed.

## 2. What this changes in the signed assessment

### 2.1 Section 2, personal data categories

Two rows are needed that the table does not have:

| Data | Where | Notes |
|------|-------|-------|
| Still frames of the learner's face and upper body | Object storage, same retention clock as the video | Derived from the video, not separately captured. Six stills rather than a continuous record. |
| Model-written description of the learner's body | `..._practice_scores.session_meta` | Prose about a named person's posture, hands and gaze. Not shown to the learner. |

The second row is the one that deserves attention. It is not a recording, and it is not a score. It
is free text a model wrote about a person's body, and nothing between the prompt and the database
enforces the appearance instruction the prompt gives.

### 2.2 Section 5, risk assessment

Three points are new:

**The frames are client-supplied.** They are validated on upload and the MIME type is re-sniffed
server-side before the sheet is used, and the read is bounded at 8 MB before a byte is fetched. But a
determined learner could substitute flattering frames. The feedback is formative and nothing depends
on it, so this is accepted rather than mitigated.

**The evidence is derived server-side, never accepted from the client.** An earlier design passed the
visual evidence as a web-service parameter. That would have let any learner holding the capability
award themselves both criteria. The scoring endpoint now takes only a recording id and checks that
the recording belongs to the caller.

**Automated inference about a person's body carries a different weight from automated scoring of
speech.** The design answer is that the model is asked to describe rather than judge, and the rubric
does the judging, so the judgement is visible to the learner and editable by an administrator. That
argument holds only while the description itself is not treated as a finding, which is why it is not
rendered and why section 3 below matters.

### 2.3 Section 7, outcome

The trigger has fired and should be replaced with one that reflects the current shape. Suggested:
revisit if the visual description is ever rendered to a learner, if frame analysis is enabled by
default, if the number or resolution of sampled frames increases materially, or if the vision pass
moves to a provider without a no-training commitment.

## 3. Four statements in the signed document that are no longer accurate

1. **"the heavy media is deleted after a short retention window"** (section 1) is now true of the
   frames too, but was not when v7.5.1 shipped. The cleanup task deleted the video and the sheet and
   left the **description** behind, in a score row that deliberately survives retention. So prose
   about a learner's body outlived the video, outlived the frames it was derived from, and was kept
   indefinitely in a field nothing read. **Fixed in v7.5.2**: the description is now dropped when the
   recording is retired. No production site had the feature enabled, so no such row was ever written.

2. **The learner-facing privacy notice** promised the recording is deleted "together with the still
   frames used for body-language feedback". Until the fix above, a learner reading that would
   reasonably conclude no visual material survived, and that was false. It is now true.

3. **Course-level erasure did not remove Soapbox recordings at all.** The course-context purge
   deleted seven tables including the scores and never touched the recordings table, so the score
   went and the video, and the transcript on that row, stayed, along with the object in the bucket.
   This predates v7.5.1 and affected audio-only sites too. **Fixed in v7.5.2.**

4. **Section 2 lists the transcript as "retained as part of the record"**, which is correct, but the
   signed document does not say that the transcript therefore survives a course purge. Given point 3,
   it did so unintentionally rather than by design. It is worth stating the intent explicitly when
   this is re-signed.

## 4. What the controller may want to decide

**Whether the stored description should exist at all.** It is written on every scored video attempt
and read by nothing. v7.5.1 decided not to render it, for the reasons in section 2.2. That leaves
data whose only justification is a debugging or dispute path that does not exist, because these
courses are self-paced with no instructor and no appeal process. Options: keep it for the retention
window as evidence behind the two criteria, which is the current state; or stop writing it and accept
that the two criterion comments are the only record of what was seen.

**Whether a learner should be told frame analysis ran.** Today the how-to card says body-language
feedback is available and the privacy notice says still frames are used and deleted. Neither says a
frame analysis happened on this particular attempt. Since the feature is off by default and
instructor-enabled, the honest options are a per-attempt statement or a course-level disclosure at
enrolment.

## 5. Recommendation

The processing added in v7.5.1 is proportionate to the stated purpose, is off by default, is bounded
by the same retention clock as the recording, and its highest-risk artefact is not shown to anyone.
The three defects in section 3 are fixed in v7.5.2 and none of them reached a production site with
the feature enabled.

Recommend re-signing the assessment with sections 2, 5 and 7 amended as above, and a decision
recorded on the two questions in section 4.

---

**NOTE TO TOM, not part of the document:** this is deliberately unsigned. You signed the original as
acting data-protection authority and I am not going to edit a signed governance document or add your
name to a new one. If you want it as a formal amendment rather than an addendum, say so and I will
restructure it as tracked changes against the original sections. The two open questions in section 4
are genuine decisions rather than rhetorical framing, and neither is urgent while the feature is off.
