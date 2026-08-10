# Experiment: does `auto_open` drive SOLA discovery?

**Date:** 2026-08-10
**Status:** design, not yet applied. Written before the settings change so the hypothesis and success criteria are on record.

---

## Why

97% of learners who can already see SOLA never open it. That is a bigger pool than any retention problem: 44,370 active learners on Learn are in a SOLA-enabled course and 1,213 use it.

A natural experiment suggests `auto_open` moves that number. Degrees enabled it on 2026-08-03 21:21. Learn did not change, so it serves as a control:

| | Before | After | Ratio |
|---|---|---|---|
| **Degrees** (auto_open ON) | 3.0 new SOLA learners/day | **8.7** | **2.90x** |
| Learn (unchanged, control) | 36.7/day | 48.3/day | 1.31x |

Differencing out the common trend gives a **2.21x uplift**. Welch t = 3.91, p ≈ 0.0001.

That result is suggestive, not decisive, for three reasons: seven days of post-change data, small daily volumes (1–15), and several settings changed in the same batch. Only `auto_open` plausibly affects whether a learner *opens* the drawer — the others govern answer quality once open — but that is reasoning, not isolation.

This experiment is designed to settle it on Learn, where 95% of the learners are.

---

## Design

Five matched pairs. Each pair shares a subject family and sits within ~1.35x on size, so the pair differs mainly in the treatment.

| Pair | Turn ON | id | Active | Baseline | Leave OFF | id | Active | Baseline |
|---|---|---|---|---|---|---|---|---|
| 1 | **BUS206** | 41 | 864 | 2.31% | BUS601 | 686 | 745 | 2.28% |
| 2 | **CS107** | 65 | 1,853 | 1.40% | CS207 | 1,267 | 1,390 | 1.22% |
| 3 | **MA121** | 1,274 | 855 | 1.75% | MA007 | 880 | 654 | 2.29% |
| 4 | **BUS210** | 1,239 | 776 | 2.06% | BUS305 | 1,326 | 603 | 1.49% |
| 5 | **ESL003** | 792 | 2,674 | 2.73% | ESL004 | 888 | 2,049 | 1.71% |

**Treatment: 7,022 active learners. Control: 5,441.**

Size ratios within pairs: 1.16, 1.33, 1.31, 1.29, 1.31.
Adoption gaps within pairs: 0.03, 0.18, 0.54, 0.57, **1.02** pp.

Pair 5 is the loosest match — a 1.02 pp adoption gap, above the 0.7 pp threshold used for the others. It is included deliberately: ESL is the largest cohort on the platform and auto-opening a drawer may land differently for language learners than for CS or business students. Excluding ESL would have left the result hard to generalise. The looser match is a real cost, and pair 5 should be read separately as well as pooled.

### Applying it

Set `auto_open_course_<id> = 1` for the five treatment ids: **41, 65, 792, 1239, 1274**.

Course AI Settings page per course, or the per-course override directly. Nothing else changes. There are currently no `auto_open_course_*` overrides on Learn, so this is a clean slate.

Site-level `auto_open` stays 0 on Learn. Control courses need no change.

---

## Power

Baseline adoption in the treatment arm is 2.13%.

| Effect | Adoption moves | z |
|---|---|---|
| 2.2x (as observed on Degrees) | 2.13% → 4.69% | **8.4** |
| 1.5x (modest) | 2.13% → 3.20% | 3.9 |
| 1.3x (weak) | 2.13% → 2.77% | 2.5 |

**Smallest uplift detectable at z=3: 1.34x.** Twenty-eight days is sufficient; there is no need to run it longer for the primary metric.

---

## What gets measured

**Primary.** New SOLA learners per active learner, treatment vs control, differenced. Same estimator as the Degrees result.

**Secondary, and these decide whether the result is actually good:**

- **Return rate of auto-opened learners.** If auto-open buys first contact but those learners are one-and-done more often than self-initiated ones, the headline uplift overstates the value. This is the metric most likely to complicate a positive result.
- **Turns per adopting learner.** Currently 4.80. If it falls in the treatment arm, auto-open is recruiting less-engaged learners.
- **Complaints.** Auto-opening a drawer is intrusive. Worth watching support channels — the most likely reason to reverse regardless of the numbers.

**Cost.** Negligible either way: at 2.2x across the treatment arm, roughly $2/month more. Cost is not a decision input here.

---

## Pre-registered interpretation

Stated in advance so the result is not read to taste:

- **Uplift ≥ 1.6x and return rate holds** → roll out site-wide on Learn.
- **Uplift ≥ 1.6x but return rate drops materially** → auto-open recruits weakly-motivated learners. Consider a less intrusive prompt instead of a full drawer open.
- **Uplift < 1.34x** → the Degrees result was novelty, small-sample noise, or one of the other settings changed in that batch. Do not roll out; look elsewhere for discovery.
- **Complaints of any volume** → reverse first, analyse afterwards.

---

## Rollback

Delete the five config rows. No code, no release, no reindex, no data migration. The change is reversible within minutes at any point.

---

## What this design does not cover

- **The four largest courses are excluded** — ESL001 (10,065 active), CS101, BUS101, ESL002. None had a close match, and ESL001 alone is a fifth of Learn's active learners; treating it would risk a large population on an untested change. The consequence is that the treated set is 14% of the eligible pool, so a site-wide rollout extrapolates from mid-sized courses to large ones.
- **Novelty is not separable in 28 days.** A drawer that opens itself is most noticeable when it is new. A follow-up read at 90 days would show whether the uplift persists.
- **Learners are not randomised individually** — assignment is by course, so any course-level event during the window (an assignment deadline, a cohort intake) is a confound. Matching by family and size reduces this but does not remove it.
- **The Degrees comparison cannot be cleanly re-derived**, because several settings changed together there. This experiment is the isolation.
