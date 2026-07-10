# Soapbox video + slides: design and cost model (2026-07-10)

**Author:** Tom Caswell. **Status:** design draft for review. **Scope:** expand Soapbox from audio-only speech practice to video and audio presentations with student-advanced slides, per-assignment instructor configuration, and course links, at low cost / low bandwidth / mobile-and-desktop.

---

## 1. The three decisions that set the cost

Everything downstream depends on these. The recommended option is in bold.

1. **Recording:** **in-browser MediaRecorder (getUserMedia)**, recorded locally and uploaded after, at a capped low bitrate. Not live streaming, not server-side capture. Works desktop and mobile; the bitrate cap is the single biggest cost and bandwidth lever.
2. **Transcription:** **self-hosted Whisper** (already supported since v6.3.0, free at the margin) rather than hosted OpenAI Whisper ($0.006/min, which becomes the dominant line item at scale).
3. **What the AI sees:** **transcript + extracted slide text + slide-change timings**, not the video frames. A vision model over full video is expensive and unnecessary for speech-and-slides feedback. An optional, cheap single-pass vision look at slide images (gpt-4o-mini) can be offered for slide-design feedback, off by default.

Get these right and the marginal cost per recording is dominated by download egress (about half a cent to a cent), not AI or storage.

---

## 2. How each requirement is met

### 2.1 Video and audio-only recording (desktop + mobile, low bandwidth)
- `getUserMedia` + `MediaRecorder`. Cap `videoBitsPerSecond` per the admin-selected quality preset (below); audio ~32 to 40 kbps Opus. Audio-only is ~0.25 MB per minute.
- **Video quality preset (admin setting `soapbox_video_quality`, default Standard).** Cost scales almost linearly with MB/min, so this is the main cost-and-bandwidth dial:
  - **Low (360p, 640x360, ~350 kbps): ~3 MB/min** — low-bandwidth regions, older phones. ~0.7x baseline cost.
  - **Standard (480p, 854x480, ~500 kbps): ~4 MB/min** — default; a talking-head presenter with slides is fully legible. Baseline for the cost tables.
  - **High (720p, 1280x720, ~1.2 Mbps): ~9 MB/min** — only when visual detail matters (physical materials, fine text). ~2.2x baseline cost.
- **Audio-only assignment mode:** skip the camera, record Opus only. Tiny files, works on the weakest connections.
- Record locally, then **resumable chunked upload** straight to object storage via presigned multipart, so flaky mobile connections can resume and the PHP server never handles the bytes.
- **Format for playback across devices:** record H.264/AAC MP4 where the browser supports it (Safari and iOS do natively; recent Chrome and Android increasingly do). Where only WebM/VP8/VP9 is available, same-device playback (the student reviewing their own recording) is fine; for cross-device viewing (an instructor on a different browser) either standardize on MP4 or run a self-hosted ffmpeg transcode on demand. **Do not use a managed transcoding service** (see cost caution).

### 2.2 Student-advanced slides without compositing
The cost trick: **do not burn slides into the video.** Instead:
- The slide deck is uploaded ahead of time as **PDF** (export from PowerPoint; optional one-time PPTX to PDF conversion via headless LibreOffice at upload, not per view).
- During recording, when the student advances a slide we record a **timestamp and slide index** into a small JSON timeline. No video compositing, no screen capture.
- On playback the player shows the camera video beside the slide images and advances them from the timeline. Storage is the video plus small slide images plus a few hundred bytes of timings.

### 2.3 Slide-aware rubric and feedback
- Extract slide text (PDF text layer, or OCR fallback) and pass the per-slide text, slide count, and the slide-change timeline to the scoring model.
- New rubric dimensions become available: slide design and clarity, slide-to-speech alignment, pacing (time per slide), and whether the spoken content matches the slide it is on.
- Optional: one cheap gpt-4o-mini vision pass over slide images for visual-design feedback. Off by default.

### 2.4 Per-assignment instructor configuration
A new per-assignment model (not just the current per-course flag):
- Presentation **type** (informative, persuasive, and the existing register presets).
- **Topics:** a set of student-selectable topics, each with its own instructions and an optional uploaded **PDF case or scenario**.
- **Custom rubric** per assignment (reuse the existing rubric authoring, scoped to the assignment).
- **Length range** (default 5 to 7 minutes) within the admin max (default 12 minutes).
- **Attempts** (default unlimited) and **stored attempts** (default 2), within the admin max recordings per student per assignment (default 3).
- **Mode:** video, audio-only, or video-with-slides.

### 2.5 Links to assignments within the course
- Each assignment gets a stable URL (`soapbox.php?assignment=<id>`), and a helper to drop a labeled link into a course section.
- The clean long-term option is a proper Moodle activity module (`mod_`) so assignments appear in the activity chooser and the gradebook; that is a larger build and can be a later phase.

### 2.6 Storage, viewing, deletion
- Object storage (S3 or the existing Saylor bucket). Student can **view and download** their recording during the retention window.
- **Automatic deletion after a retention window** via an S3 lifecycle rule plus a scheduled cleanup task. After deletion the transcript, score, feedback, and slide timeline (all tiny) are retained as the record; only the heavy video bytes are dropped.
- **Retention is an admin setting (`soapbox_retention_days`), default 7, maximum 28**, clamped server-side. Retention barely affects cost (storage is negligible and egress is unchanged by shelf time), so this is a privacy decision, not a cost one: a longer window widens the exposure of a facial image in the DPIA for no real saving, a shorter window is better data minimization. Default to the shortest pedagogically acceptable window.
- Stored-attempts cap (default 2) and max-recordings cap (default 3) bound how much exists at any moment, so storage stays negligible.

---

## 3. Cost model

### 3.1 Assumptions
- Video ~4 MB per minute (480p, ~0.5 Mbps); audio-only ~0.25 MB per minute.
- Lean stack: self-hosted Whisper (free at margin), no managed transcoding, scoring by gpt-4o-mini on transcript + slide text, S3 storage held 7 days, CloudFront egress ~$0.085/GB.
- Egress estimated at ~3x the file size per recording (student reviews once or twice, instructor views once, one download).
- gpt-4o-mini scoring ~$0.001 to $0.002 per recording (transcript + slides + rubric).
- Storage for a 7-day hold is fractions of a cent and effectively negligible.
- Modeling default: **2 scored attempts per student per assignment** (unlimited allowed, but this is a realistic average; scale linearly for more).

### 3.2 Cost per single recording (lean stack)
| Duration | Size | STT (self-host) | LLM score | Storage (7d) | Egress (~3x) | Total |
|---|---|---|---|---|---|---|
| 3 min | 12 MB | ~$0 | ~$0.001 | ~$0.0001 | ~$0.003 | **~$0.004** |
| 5 min | 20 MB | ~$0 | ~$0.001 | ~$0.0001 | ~$0.005 | **~$0.006** |
| 7 min | 28 MB | ~$0 | ~$0.0015 | ~$0.0002 | ~$0.007 | **~$0.009** |
| 10 min | 40 MB | ~$0 | ~$0.0015 | ~$0.0002 | ~$0.010 | **~$0.012** |

If hosted OpenAI Whisper is used instead of self-hosted, add $0.018 / $0.030 / $0.042 / $0.060 respectively; STT then becomes the largest line.

Audio-only recordings: ~$0.001 to $0.002 each all-in (STT self-hosted, tiny egress). Roughly 5 to 10x cheaper than video.

### 3.3 Cost per student (2 scored attempts, lean stack)
| Duration | Self-hosted Whisper | With OpenAI Whisper |
|---|---|---|
| 3 min | ~$0.008 | ~$0.044 |
| 5 min | ~$0.012 | ~$0.072 |
| 7 min | ~$0.018 | ~$0.102 |
| 10 min | ~$0.024 | ~$0.144 |

### 3.4 Cohort totals (one assignment, 2 attempts/student, lean self-hosted)
| Duration | 5k | 20k | 50k | 100k |
|---|---|---|---|---|
| 3 min | $40 | $160 | $400 | $800 |
| 5 min | $60 | $240 | $600 | $1,200 |
| 7 min | $90 | $360 | $900 | $1,800 |
| 10 min | $120 | $480 | $1,200 | $2,400 |

Same cohorts if hosted OpenAI Whisper is used (5 and 10 min shown):
| Duration | 5k | 20k | 50k | 100k |
|---|---|---|---|---|
| 5 min | $360 | $1,440 | $3,600 | $7,200 |
| 10 min | $720 | $2,880 | $7,200 | $14,400 |

### 3.5 Cost caution: managed transcoding
A managed transcoder (for example AWS MediaConvert at ~$0.015/min) would add ~$0.03 to $0.15 per recording and dominate everything: at 100k students x 2 x 5 min that is roughly $15,000 in transcoding alone. Avoid it. Record MP4-first, or transcode on self-hosted ffmpeg only when a cross-browser view actually needs it.

### 3.6 Reading the numbers
- The lean stack keeps a 5 to 7 minute video assignment at roughly **1 to 2 cents per student**, i.e. **about $1.2k to $1.8k at 100k students** for one assignment with 2 attempts.
- Cost is dominated by **download egress**, then optionally STT if hosted. AI scoring and storage are rounding error.
- The big levers, in order: hosted vs self-hosted Whisper, managed vs no transcoding, video bitrate, number of views (egress), attempts per student, video vs audio-only.

---

## 4. Two-phase build

**Phase 1 — Video/audio Soapbox with the per-assignment framework (the usable MVP).**
The whole activity works end to end, without slides. Instructors create assignments with a type, topics (each with instructions and an optional PDF case), a custom rubric, a length range, attempts and stored-attempts caps, and a mode (video or audio-only); the assignment gets a course link. Students pick a topic, record video or audio in the browser at a capped bitrate, upload resumably to object storage, and get transcript-based rubric feedback. Recordings are viewable and downloadable, and auto-delete after 7 days while the transcript, score, and feedback are retained. Admin caps (max length 12 min, max recordings 3) enforced.

**Phase 2 — Slides and slide-aware feedback (additive layer).**
Deck upload (PDF, optional PPTX to PDF), in-recording slide-advance capture into a timeline, synced video-and-slides playback, slide text extraction, and new slide-aware rubric dimensions (slide design, slide-to-speech alignment, pacing). Optional cheap slide-image vision pass, off by default.

A native `mod_` activity (activity chooser + gradebook) is noted as possible future work beyond these two phases; Phase 1 covers course links via a per-assignment URL and an insert-link helper.

---

## 5. Data-protection note
Video adds a facial image, which is more sensitive than the current audio-and-transcript. The privacy-positive design already planned (low resolution, student-controlled, 7-day deletion, no vision analysis by default) supports minimization. When this is built, update the SOLA DPIA data inventory and retention rows and confirm the lawful basis and transparency for camera capture. Voice and face here are used for coaching, not identification, so this is not biometric identification processing, but the DPIA should say so explicitly.

**Built-state privacy note (v6.8.20):** unlike the original audio-only Soapbox (which stored neither audio nor transcript), the video/audio flow **stores the STT transcript** on the recording row (`sbx_rec.transcript`) so feedback can be shown after the media object is retention-deleted, and so a subject-access request returns something meaningful. This is a new item in the DPIA inventory. Coverage shipped in the privacy provider: `sbx_rec` is declared in metadata (incl. the transcript field), discoverable in `get_contexts_for_userid` / `get_users_in_context` (via a join to `sbx_assign`), exported in `export_user_data`, and erased in both delete paths — erasure also best-effort deletes the stored media object, not just the row. The media object itself is never persisted beyond the retention window (default 7 days, admin max 28).
