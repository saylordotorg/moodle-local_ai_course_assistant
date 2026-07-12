# SOLA Soapbox — Cost Guide

For Saylor finance, ops, and anyone sizing what the Soapbox spoken-presentation activity actually costs to run. Written against the shipped feature (SOLA v6.8.32), so the numbers reflect what is in the plugin today, not a design projection.

This is the plain-language cost guide. The engineering design and the cohort math it is derived from live in `.drafts/soapbox-video-design-2026-07-10.md`; the site-wide vendor and cost picture lives in `.drafts/sola-vendor-recommendations-2026-06-09.md` (Soapbox appears there as its own appendix).

---

## What it costs, in one paragraph

Soapbox is cheap to run when it is configured the lean way, and it is configured the lean way by default. A single 5 to 7 minute video presentation, transcribed on the self-hosted Whisper server SOLA already ships with, scored by gpt-4o-mini on the transcript and slide text, stored for a week and then auto-deleted, costs about **one to two cents per student** all in. Across a full 100,000-student cohort doing one assignment with two attempts each, that is roughly **$1,200 to $1,800** for the whole assignment. The cost is dominated by video download bandwidth, not by AI: transcription is free at the margin, scoring and storage are a rounding error. The two ways to make it expensive are both opt-in and both avoidable: switching transcription to hosted OpenAI Whisper (about 6x the total), and adding a managed video transcoder (about 12x on top of that). Neither is on by default.

---

## The three settings that set the cost

Everything below follows from three choices. Get them right and Soapbox is a rounding error next to the chat tutor; get them wrong and it is the biggest line in the plugin.

1. **Transcription path (`soapbox_stt_mode` and the self-hosted Whisper settings).** Self-hosted Whisper runs on infrastructure Saylor already has and is **free at the margin**. Hosted OpenAI Whisper is $0.006 per minute and becomes the single largest line the moment it is turned on. In-browser Web Speech is also free but lower quality. Default is server (self-hosted where configured).
2. **Video quality preset (`soapbox_video_quality`, default Standard 480p).** Cost scales almost linearly with megabytes per minute, and bandwidth is the dominant line, so this is the main dial. Low 360p is about 0.7x, Standard 480p is the baseline, High 720p is about 2.2x.
3. **What the AI looks at.** Scoring reads the **transcript plus extracted slide text plus slide-change timings**, never the video frames. The optional slide-vision pass (below) is the only thing that sends images to a model, it is off by default, and even on it is cheap.

---

## The cost model

### Assumptions

- Video about 4 MB per minute at 480p; audio-only about 0.25 MB per minute.
- Lean stack: self-hosted Whisper (free at margin), no managed transcoding, scoring by gpt-4o-mini on transcript and slide text, S3 storage held 7 days, CloudFront egress about $0.085/GB.
- Egress estimated at about 3x the file size per recording (the student reviews once or twice, the instructor views once, one download).
- gpt-4o-mini scoring about $0.001 to $0.002 per recording.
- Storage for a 7-day hold is fractions of a cent, effectively negligible.
- Modeling default: **two scored attempts per student per assignment**. Scale the cohort totals linearly for more.

### Per single recording (lean stack, self-hosted Whisper)

| Duration | Size | STT | Scoring | Storage (7d) | Egress (~3x) | Total (vision off) |
|---|---|---|---|---|---|---|
| 3 min | 12 MB | ~$0 | ~$0.001 | ~$0.0001 | ~$0.003 | **~$0.004** |
| 5 min | 20 MB | ~$0 | ~$0.001 | ~$0.0001 | ~$0.005 | **~$0.006** |
| 7 min | 28 MB | ~$0 | ~$0.0015 | ~$0.0002 | ~$0.007 | **~$0.009** |
| 10 min | 40 MB | ~$0 | ~$0.0015 | ~$0.0002 | ~$0.010 | **~$0.012** |

If hosted OpenAI Whisper is used instead of self-hosted, add $0.018 / $0.030 / $0.042 / $0.060 respectively; transcription then becomes the largest line.

**Audio-only** recordings are about $0.001 to $0.002 each all in (self-hosted STT, tiny egress), roughly 5 to 10x cheaper than video.

### Optional slide-vision pass (v6.8.31, off by default)

When both the site toggle (`soapbox_slide_vision`) and the per-assignment opt-in are on, one gpt-4o-mini vision call looks at a bounded sample of the rendered slide images (up to 12, evenly spread) and returns one short visual-design note. It runs once per scored recording. It adds about **$0.002 per recording**, i.e. it roughly doubles the AI line, which is still a rounding error next to egress. No slide images are stored. Turning it on raises the per-recording totals above by about $0.002 and the 100k-cohort total below by about $400.

### Per student (two scored attempts, vision off)

| Duration | Self-hosted Whisper | With OpenAI Whisper |
|---|---|---|
| 3 min | ~$0.008 | ~$0.044 |
| 5 min | ~$0.012 | ~$0.072 |
| 7 min | ~$0.018 | ~$0.102 |
| 10 min | ~$0.024 | ~$0.144 |

Slide vision, when enabled, adds about $0.004 per student (two attempts).

### Cohort totals for one assignment (two attempts each, lean self-hosted, vision off)

| Duration | 5k | 20k | 50k | 100k |
|---|---|---|---|---|
| 3 min | $40 | $160 | $400 | $800 |
| 5 min | $60 | $240 | $600 | $1,200 |
| 7 min | $90 | $360 | $900 | $1,800 |
| 10 min | $120 | $480 | $1,200 | $2,400 |

Same cohorts with hosted OpenAI Whisper (5 and 10 min shown):

| Duration | 5k | 20k | 50k | 100k |
|---|---|---|---|---|
| 5 min | $360 | $1,440 | $3,600 | $7,200 |
| 10 min | $720 | $2,880 | $7,200 | $14,400 |

With slide vision enabled, add about $400 to the 5 min / 100k self-hosted figure (200,000 vision passes at about $0.002 each), scaling proportionally.

### The one thing to never turn on: managed transcoding

A managed transcoder such as AWS MediaConvert (about $0.015/min) would add $0.03 to $0.15 per recording and dominate everything: at 100,000 students x 2 attempts x 5 minutes that is roughly **$15,000 in transcoding alone** for one assignment. SOLA records MP4-first and, where a cross-browser view genuinely needs it, transcodes on self-hosted ffmpeg on demand. Do not wire in a managed transcoder.

---

## Reading the numbers

- The lean stack keeps a 5 to 7 minute video assignment at roughly **1 to 2 cents per student**, i.e. about **$1.2k to $1.8k at 100k students** for one assignment with two attempts.
- Cost is dominated by **download bandwidth (egress)**, then by transcription only if it is the hosted path. AI scoring, slide vision, and storage are all rounding error.
- The big levers, in order: hosted vs self-hosted Whisper, managed vs no transcoding, video bitrate, number of views (egress), attempts per student, and video vs audio-only.
- **This is a per-assignment cost, not a monthly per-user cost.** Unlike the chat tutor, which bills every active learner every month, Soapbox only costs anything when a student records against an assignment. A course with two Soapbox assignments a term costs about twice the single-assignment figure for the students who complete them, and nothing for students who do not.

## How this sits next to the rest of SOLA

For context, the site-wide text baseline (chat tutor, quiz coach, embeddings, reranker, classifier, analytics) runs about **$1,370 per month at 100k MAU** under the 25% adoption model. A single 100k-student Soapbox video assignment at 5 to 7 minutes is a comparable one-time spend to about one month of that entire baseline, which is why the transcription path and bitrate defaults matter: the lean defaults keep it there, and the two opt-in switches (hosted Whisper, managed transcoding) can move it by 6x and 12x respectively.

## Keeping it cheap: the settings checklist

- [ ] `soapbox_stt_mode` = server, with self-hosted Whisper configured (free transcription). Only fall back to hosted OpenAI Whisper if no self-hosted endpoint exists, and know it becomes the largest line.
- [ ] `soapbox_video_quality` = Standard (480p) or Low (360p) for low-bandwidth cohorts. Reserve High (720p) for assignments that genuinely need visual detail.
- [ ] Offer **audio-only** assignments where video adds nothing pedagogically; they are 5 to 10x cheaper and lower the DPIA sensitivity too.
- [ ] Keep attempts and stored-attempts caps modest (defaults: max 3 recordings, 2 stored). Both bound egress and storage.
- [ ] Leave slide vision off unless slide-design feedback is a stated goal; it is cheap but not free.
- [ ] Never add a managed transcoder.
- [ ] Keep retention short (default 7 days, max 28). This is a privacy decision more than a cost one, but a short window is the right default.

---

## Sources

- `.drafts/soapbox-video-design-2026-07-10.md` — the engineering design and the full cost derivation these tables condense.
- `.drafts/sola-vendor-recommendations-2026-06-09.md` — the site-wide vendor and cost picture; Soapbox is Appendix C there.
- `.drafts/sola-soapbox-dpia-2026-07-11.md` — the data-protection assessment (retention, minimization, the slide-vision pass).
- SOLA v6.8.32 release notes — the shipped feature set the numbers are measured against.
