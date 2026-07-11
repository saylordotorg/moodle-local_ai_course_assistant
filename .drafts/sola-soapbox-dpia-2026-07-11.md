# SOLA Soapbox — Data Protection Impact Assessment (video, audio, slides)

**Feature:** Soapbox spoken-presentation practice (video/audio recording, student-advanced slides, optional slide-vision feedback)
**Plugin:** local_ai_course_assistant (SOLA — Saylor Online Learning Assistant)
**Assessed version:** 6.8.31
**Date:** 2026-07-11
**Data controller:** Saylor University
**Sign-off:** Tom Caswell, Chief Data Officer (acting data-protection authority; Saylor has no separate DPO)
**Status:** Complete for the video/audio/slides Soapbox feature. Companion to the SOLA Security Review (2026-06-12) and the Soapbox design and build-plan drafts (2026-07-10).

Saylor context: SOLA is a tuition-free university's learning assistant. FERPA does not apply; the binding constraints are SOC 2, GDPR, and the no-training commitments with AI vendors. This DPIA covers the processing that Soapbox adds beyond the text baseline: capture of a learner's voice and (in video mode) face, plus any personal data a learner places in a slide deck.

---

## 1. Description of the processing

Soapbox lets an instructor set a spoken-presentation assignment. A learner records a presentation in the browser (video or audio only), optionally uploads a PDF slide deck and advances it while speaking, then the recording is transcribed and scored against a speech rubric. The score, written feedback, and (for slides) the slide-change timeline are kept as the record of the attempt; the heavy media is deleted after a short retention window.

**Nature:** capture, transient transcription, automated formative scoring, short-term storage, automatic deletion.
**Scope:** opt-in per course (`feature_flags::resolve('soapbox')`) and per assignment; off by default site-wide.
**Context:** adult learners in a voluntary, tuition-free online university; presentations are a pedagogical exercise, not an assessment of record.
**Purpose:** formative practice and feedback on public-speaking and presentation skills.

## 2. Personal data categories

| Data | Where | Notes |
|------|-------|-------|
| Voice recording (audio) | Object storage (S3), retention-limited | Biometric-adjacent; a voice is identifying. |
| Facial image / video (video mode) | Object storage (S3), retention-limited | The highest-sensitivity item; video mode is the learner's choice of assignment. |
| Transcript (text) | `..._sbx_rec.transcript` | Derived from audio; retained as part of the record. |
| Slide deck (PDF) + rendered page images | Object storage; images rendered transiently at scoring/preview time | May contain learner-authored personal data (a name on a title slide, a photo, a personal example). |
| Slide-change timeline | `..._sbx_rec.slide_timeline` (JSON) | Timing metadata; not sensitive on its own. |
| Score, rubric feedback, tips | `..._practice_scores` (+ `session_meta`) | Includes the optional slide visual-design note. |
| Assignment metadata | `..._sbx_assign`, `..._sbx_topic` | Instructor-authored; not learner PII. |

## 3. Recipients / processors

- **Self-hosted Whisper (STT):** default transcription path; runs on Saylor-controlled infrastructure, free at the margin, no third-party disclosure of audio. Hosted OpenAI Whisper is an alternative admin path (covered by the OpenAI no-training/DPA terms) if self-hosted is not configured.
- **Object storage (S3, shared archive bucket, `soapbox/` prefix):** stores the media within the retention window; lifecycle rule enforces deletion.
- **Scoring model (gpt-4o-mini):** receives the transcript plus extracted slide text and timing — never the video frames.
- **Optional slide-vision model (gpt-4o-mini, v6.8.31, off by default):** when both the site toggle and the per-assignment toggle are on, a single vision pass receives a bounded sample of the rendered slide page images (not video, not audio) and returns one short visual-design note. Images are sent transiently and never stored by SOLA. Covered by the OpenAI no-training/DPA terms.

No audio, video, transcript, or slide image is used to train any vendor model (no-training commitments in place).

## 4. Necessity and proportionality

- **Lawful basis:** performance of the educational service the learner voluntarily enrolled in and initiated (the learner starts each recording). Consent-like control is preserved by the feature being opt-in and by the learner choosing whether to record and whether to use video or audio-only mode.
- **Data minimization:**
  - Audio-only assignment mode avoids capturing a facial image entirely.
  - The scoring model sees text (transcript + slide text), not the video.
  - Slide vision sees only slide images, only when explicitly enabled, only a bounded sample, and never stores them.
  - Bitrate is capped by an admin quality preset (default 480p), limiting how much visual detail is captured.
- **Storage limitation:** media is auto-deleted after `soapbox_retention_days` (default 7, hard max 28), enforced by both an S3 lifecycle rule and the `soapbox_cleanup` scheduled task. Only the small derived record (transcript, score, feedback, timeline) is retained. Retention barely affects cost, so a short window is chosen purely for minimization.
- **Transparency:** the recorder screen states that the microphone (and, in video mode, camera) will be recorded; audio-only mode states the camera is not used. Assignment instructions carry the instructor's framing. First-run consent disclosure applies as elsewhere in SOLA.

## 5. Risks and mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Facial image exposed longer than needed | Low | Medium | Short default retention (7 days), hard 28-day cap, dual deletion (lifecycle + task); audio-only mode available. |
| Third-party disclosure of voice/face | Low | Medium | Self-hosted Whisper default; frames never leave for scoring; vision sees slide images only, transiently; no-training terms. |
| Learner personal data inside a slide deck | Medium | Low/Medium | Covered by export and erasure (below); slide images rendered transiently, not persisted; DPIA flags decks as potentially containing learner-authored PII. |
| Unauthorized access to another learner's recording | Low | Medium | Capability + assignment-visibility checks on the upload/finalize and view paths; presigned URLs are short-lived. |
| Over-collection via long/high-res video | Low | Low | Admin bitrate preset and per-assignment length caps, clamped server-side. |
| Slide-vision note leaks non-design content | Low | Low | Prompt constrains the model to visual-design commentary; note is length-bounded and stored where existing score data lives. |

## 6. Data-subject rights (SAR / erasure)

The privacy provider covers the new tables so subject-access export and erasure include Soapbox data:
- **Export:** `..._sbx_rec` metadata (transcript, duration, timecreated) and the `..._practice_scores` row incl. `session_meta` (which holds the tips and the optional slide visual-design note).
- **Erasure:** deletes `..._sbx_rec` rows and purges the associated storage objects (`storage_key` and `deck_key`); scores are deleted via the practice-scores erasure path.
- **Retention backstop:** the media is already removed by the retention task within the window regardless of a request.

## 7. Outcome

The residual risk after mitigations is **low**, and proportionate to the pedagogical purpose. The controlling factors — feature off by default, learner-initiated capture, audio-only option, transcript-not-video scoring, transient and opt-in slide vision, short enforced retention, and full SAR/erasure coverage — keep the processing within SOLA's SOC 2 + GDPR + no-training posture. Approved to ship.

Follow-ups: none blocking. Revisit if a future mode stores video beyond the retention window, sends video frames to a model, or enables slide vision by default (each would require re-assessing section 5).
