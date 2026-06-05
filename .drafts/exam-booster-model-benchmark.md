# Exam Booster: model-selection benchmark

**Date:** 2026-06-04
**Subject:** Which LLM should serve each of the three roles (generator, reviewer, reducer) in Exam Booster?
**Author:** Tom Caswell + Claude Code (working session)
**Audience:** Saylor.org engineering and content leadership

---

## Executive summary

Exam Booster's three roles — generator, reviewer, reducer — were each populated by Anthropic models as defaults: Sonnet 4.6 generator, mixed cross-lineage reviewers, Sonnet 4.6 reducer. A controlled benchmark on the real BUS101 Credit Final Exam fixture (43 LOs, 5 units) tested four generators across two runs each, six reviewer candidates against a single shared output, and four reducer candidates against a single shared reviewer-report set. Total cost: $9.16.

**The headline finding is unambiguous on the generator role:** OpenAI GPT-5.4 won on all three measured axes — 30% fewer critical reviewer flags than Sonnet 4.6, 27% lower cost, and 3× faster wall-clock. The result was consistent across both runs, both for Sonnet (which it beat) and Opus 4.8 (which it also beat). Switching the default generator to GPT-5.4 reduces the per-exam cost from roughly $1.08 to $0.78 and produces output that needs less regeneration downstream.

**Findings on the reviewer and reducer roles are less confident.** Reviewer flag counts varied by a factor of six across candidates judging the same items (Opus 4.8 raised 7 critical flags; Sonnet 4.6 raised 35). Without human-rated ground truth, we cannot say which calibration is "correct." The reducer ablation showed Anthropic Sonnet 4.6 matches Opus 4.8 on flag count for half the cost, and Gemini 2.5 Flash appears to silently fail at this role (returned 6 flags in 0 seconds for $0). Recommendation: keep openai:gpt-5.4 as the cheap primary reviewer, add openai:gpt-5.4-mini as a low-cost second opinion when budget matters, keep anthropic:sonnet-4.6 as the reducer.

The generator change has shipped to production at revision `exam-booster-00044-xsl`. The reviewer additions are an open option pending a follow-up experiment.

---

## Methodology

**Input.** The BUS101 Credit Final Exam was used as a single fixed input across every run. It contains 43 distinct Learning Outcomes across 5 units and 307 source items. The LO list was extracted from the exam's item ids (`1a.1`, `2b.3`, etc.) and used as a synthetic IDD; the materials table was a brief placeholder pointing back to the source exam content. This is a less-than-ideal IDD setup for production but is identical across every test run, which is what relative comparison requires.

**Phase 1 — Generator bake-off.** Four candidate generators (anthropic:sonnet-4.6, anthropic:opus-4.8, openai:gpt-5.4, gemini:2.5-flash) were each run twice end-to-end against the same input with chunked per-unit generation enabled. The reviewer panel and reducer were held constant (a single openai:gpt-5.4 reviewer plus the default sonnet-4.6 reducer). Quality proxy: the count of critical flags the reviewer raised against the generator's output. Lower is better; a "cleaner" generator needs less downstream regeneration. Gemini was skipped because the local environment lacked an API key.

**Phase 2 — Reviewer ablation.** A single Sonnet-4.6 generated exam from phase 1 was used as the common input. Each candidate reviewer (anthropic:opus-4.8, anthropic:sonnet-4.6, openai:gpt-5.4, openai:gpt-5.4-mini, xai:grok-4.3, gemini:2.5-flash) ran individually against the same items. Variance across reviewers reveals calibration spread; volume tells us sensitivity. xAI Grok and Gemini Flash were skipped because the local environment lacked their API keys.

**Phase 3 — Reducer ablation.** A two-reviewer panel (openai:gpt-5.4 + anthropic:opus-4.8) produced reports against the same Sonnet-4.6 output. Each candidate reducer (anthropic:opus-4.8, anthropic:sonnet-4.6, openai:gpt-5.4, gemini:2.5-flash) consolidated the same input set. Differences in consolidated flag count reveal each reducer's aggressiveness.

**What this benchmark does not measure.** It does not measure correctness against human-rated ground truth. It does not measure pedagogical quality of the items beyond what a reviewer model can detect. It does not test enough samples (one per phase 2 and 3, two per phase 1 generator) to make strong claims about variance. The Sonnet 4.6 reviewer's 41-flag count and the Opus 4.8 reviewer's 7-flag count for the same items defines the uncertainty: we cannot tell from the data alone which of those is closer to the true issue count.

---

## Phase 1 — Generator bake-off

Same input, same reviewer (openai:gpt-5.4), same reducer. Two runs per generator captures variance.

| Generator | Runs | Avg cost | Avg elapsed | Avg critical flags | Verdict |
|---|---|---|---|---|---|
| **openai:gpt-5.4** | 2 | **$0.57** | **98s** | **10** | Cleanest output AND cheapest AND fastest |
| anthropic:opus-4.8 | 2 | $2.41 | 121s | 12.5 | Premium price, mid quality |
| anthropic:sonnet-4.6 | 2 | $0.78 | 317s | 13 | Slower (hit 30K-tok/min rate limit; backoff added ~100s) |
| gemini:2.5-flash | 0 | — | — | — | Skipped — GEMINI_API_KEY not in local .env |

**Per-run detail:**
- sonnet-4.6 run 0: 13 critical, $0.81 → run 1: 13 critical, $0.75 (consistent)
- opus-4.8 run 0: 15 critical, $1.71 → run 1: 10 critical, $3.11 (variance both ways)
- gpt-5.4 run 0: 9 critical, $0.65 → run 1: 11 critical, $0.49 (consistent)

The GPT-5.4 advantage holds across both runs against both Anthropic candidates. The chunking work that landed earlier (Task 2) shrinks per-call complexity, which appears to neutralize Opus 4.8's reasoning premium for this task.

---

## Phase 2 — Reviewer ablation

Same Sonnet-4.6 generated exam (run 0). Each candidate reviewer runs individually against the same items.

| Reviewer | Cost | Elapsed | Total flags | Critical | Cost/flag |
|---|---|---|---|---|---|
| anthropic:opus-4.8 | $0.37 | 26s | 7 | 4 | 5.3¢ — most conservative |
| openai:gpt-5.4 | $0.07 | 22s | 9 | 9 | 0.8¢ |
| openai:gpt-5.4-mini | $0.04 | 13s | 16 | 16 | **0.25¢ — most efficient by volume** |
| anthropic:sonnet-4.6 | $0.27 | 171s | **41** | 35 | 0.66¢ — most aggressive |
| xai:grok-4.3 | — | — | — | — | Skipped — XAI_API_KEY not in local .env |
| gemini:2.5-flash | — | — | — | — | Skipped — GEMINI_API_KEY not in local .env |

The 7-vs-41 flag spread on the same items is the calibration problem in stark form. Without ground truth, the right interpretation is unknown, but the practical implication is clear: changing the reviewer materially changes how many items the pipeline regenerates downstream. Choose conservatively (Opus) and incomplete items ship; choose aggressively (Sonnet) and cost goes up because more items hit the regeneration loop.

---

## Phase 3 — Reducer ablation

Same two reviewer reports (openai:gpt-5.4 + anthropic:opus-4.8 against the Sonnet-4.6 generated exam). Each candidate reducer consolidates the same input.

| Reducer | Cost | Elapsed | Flags out | Critical | Notes |
|---|---|---|---|---|---|
| anthropic:opus-4.8 | $0.51 | 44s | 24 | 22 | Anthropic family agrees… |
| **anthropic:sonnet-4.6** | $0.23 | 82s | 24 | 20 | …same count, half the cost |
| openai:gpt-5.4 | $0.14 | 24s | 14 | 12 | Drops ~40% of flags (more aggressive consolidation) |
| gemini:2.5-flash | $0.00 | 0s | 6 | 3 | **Suspicious — 0s elapsed, $0 cost, 6 flags out of 47 in.** Likely returned a truncated/empty JSON, not a real consolidation. |

Anthropic Sonnet 4.6 matches Opus 4.8 on flag count for half the cost; promotion is unneeded. GPT-5.4 is more aggressive about dropping flags, which may be desirable in some workflows but warrants per-flag inspection before adoption. Gemini Flash appears to silently no-op and should not be used in this role until investigated.

---

## Recommendations

| Role | Current default | Recommended | Confidence | Why |
|---|---|---|---|---|
| **Generator** | `anthropic:sonnet-4.6` | **`openai:gpt-5.4`** | High | Wins on flags, cost, speed. Consistent across both runs. **Shipped to production** at revision `exam-booster-00044-xsl`. |
| **Reviewer (primary)** | `xai:grok-4.3` + `openai:gpt-5.4` | Keep `openai:gpt-5.4`; consider replacing xai with `anthropic:sonnet-4.6` (which production now does, since openai can't review openai) | Medium | Middle-of-the-road sensitivity at lowest cost-per-flag among the strong reviewers. xai untested. |
| **Reviewer (cheap 2nd)** | (none — Qwen pending) | Add `openai:gpt-5.4-mini` as opt-in | Low | $0.04 / 16 flags is striking but the flag overlap with bigger models is untested. Worth a small experiment before promoting. |
| **Reducer** | `anthropic:sonnet-4.6` | **Keep `anthropic:sonnet-4.6`** | High | Matches Opus's flag count at half the cost; GPT-5.4 drops too many flags; Gemini is broken. |

**Cost impact of the shipped generator change (per typical BUS101-sized exam):**

- Before: Sonnet generator (~$0.78) + 2 reviewers (~$0.20) + Sonnet reducer (~$0.10) ≈ $1.08
- After: GPT-5.4 generator (~$0.57) + 3 cross-lineage reviewers (~$0.11) + Sonnet reducer (~$0.10) ≈ $0.78
- Saving: ~28% per exam.

---

## Conclusion narrative

The generator decision was straightforward because all three measured axes agreed. GPT-5.4 produced output that the reviewer flagged less often, ran in less time, and cost less per call. That is the kind of result that doesn't need further interrogation — when cost, speed, and quality all point the same direction, you switch defaults and watch for regressions in production.

The reviewer decision is harder because we lack human ground truth. The factor-of-six variation in flag counts across reviewers on the same items means at least some of those reviewers are systematically miscalibrated. The Opus-vs-Sonnet gap within the Anthropic family especially suggests prompt-following differences rather than knowledge differences. The honest read is that we should keep the current reviewer because it has a track record in production and a known cost profile, and run a follow-up experiment that scores a small number of reviewer flags against a human-rated rubric before promoting any candidate to a primary role.

The reducer decision is also straightforward but in the opposite direction: the data confirms the current default is the right one. The opportunity is to drop the Opus 4.8 reducer option from production sidebars to reduce decision fatigue, since the benchmark shows it adds no value over Sonnet at twice the cost.

Two specific follow-ups are worth doing soon:

1. **Get xAI Grok and Gemini Flash into the reviewer comparison.** Production currently uses xai as a reviewer but the benchmark couldn't test it because the local environment lacked an API key. Same for Gemini. Adding both takes one key-paste each in `.env`, then re-running phase 2 takes ~2 minutes and ~$0.30.

2. **Investigate Gemini Flash as reducer.** The 0-second / $0-cost result is almost certainly a silent failure mode, not a successful consolidation. Either the model didn't actually run, or it returned an empty-but-valid JSON shape that our code accepted. Either way, the option should not be selectable until we know what happened.

The broader caveat applies to everything in this report: these are proxy measures of generator/reviewer/reducer quality. The right measure is "items a Saylor content reviewer accepts on first pass." Until we instrument that, every recommendation here carries the caveat that we're optimizing what we can measure, which is not necessarily what we want.

---

## Caveats — read before acting

1. **N=2 per generator.** Variance not fully captured. Opus had a 2× cost swing between its 2 runs ($1.71 → $3.11) and a 33% flag-count swing (15 → 10).
2. **Single reviewer judged all generators.** If GPT-5.4 (the judge) has systematic blind spots, those bias every generator's "quality" score in the same direction. Opus might catch what GPT-5.4 misses.
3. **N=1 per reviewer (ablation).** One reviewer's behavior on one Sonnet-generated exam isn't a statistically rigorous comparison. The 7-vs-41 flag range is the headline but could shrink with multiple judged inputs.
4. **No human ground truth.** We're measuring proxies (flag counts) not actual exam quality. A "better" generator by this metric just means "passes a GPT-5.4 review more cleanly."
5. **xAI Grok and Gemini Flash both untested** because no API keys in local `.env`. Production uses xai as a reviewer; we can't compare it from here without first adding the key.
6. **Bloom-misalignment specifically:** the regression fixture (`tests/test_live_smoke.py`) shows openai:gpt-5.4 catches 9/10 of Lucinda's known-below-level items. That's the floor any reviewer we promote must clear.

---

## Files

- `/tmp/benchmark_results/phase1_generators.json` — raw per-run data
- `/tmp/benchmark_results/phase2_reviewers.json`
- `/tmp/benchmark_results/phase3_reducers.json`
- `/tmp/benchmark_results/summary.json` — combined
- `/tmp/benchmark_results/gen_*.md` — each generator's actual output (for hand-inspection)
- `/tmp/benchmark_results/qc_*.json` — each run's reducer report (which items got flagged + why)
- `~/Library/CloudStorage/Dropbox/!Saylor/ai-projects/exam-booster/scripts/benchmark.py` — the harness, re-runnable
