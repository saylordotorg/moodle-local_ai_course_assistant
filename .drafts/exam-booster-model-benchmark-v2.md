# Exam Booster: AI Model Selection Report (v2)

**Date:** 2026-06-05

**Subject:** Re-benchmark of generators, reviewers, and reducers in Exam Booster after Qwen 2.5 7B AWQ became viable (vLLM `--max-model-len` bump from 8K to 98K) and all provider API keys were available locally.

**Author:** Tom Caswell

**Audience:** Saylor.org leadership

## Executive summary

The 2026-06-04 round narrowed the generator default to openai:gpt-5.4. The intent of this second round was to verify that result with two changes: (1) include saylor-vllm:qwen2.5-7b (Saylor's self-hosted free model, newly viable after David's max-model-len bump), and (2) include xAI Grok and Gemini Flash in the reviewer ablation now that local keys are in place. Five generators across two runs each, seven reviewer candidates against a shared output, four reducer candidates against a shared three-reviewer report set. Total cost: ~$11.

**The generator result holds: openai:gpt-5.4 is still the top choice on this fixture.** Across two runs it averaged 10.0 critical reviewer flags at $0.55/run and 96 seconds elapsed. Sonnet 4.6 sat at 13.5 critical flags, $0.77/run, 340 seconds (rate-limit-throttled). Opus 4.8 sat at 10.5 critical flags but at $2.42/run — same quality as GPT-5.4 for 4× the cost. Gemini 2.5 Flash had an unstable run-1 (124 items instead of 43; format compliance issue), which on its own disqualifies it from production generator use until investigated.

**Qwen 2.5 7B AWQ via Saylor's vLLM: viable but impractically slow as generator.** Initial benchmark runs timed out at 180s. After bumping the `SaylorVLLMProvider` HTTP timeout to 600s, a one-off post-benchmark run completed in 2,951 seconds (~49 minutes) for $0.39 in reviewer + completeness-gate costs (Qwen itself is free). The completeness gate fired three LO refills (1j, 1k, 1l), so Qwen lost track of those LOs in chunked generation and needed top-ups. **At ~30× the wall-clock of GPT-5.4 with quality on par at best, Qwen is not competitive as a primary generator on current infra.** It remains a useful free reviewer (see Phase 2).

**Reviewer and reducer findings are richer than last round because the missing keys are now in.** Notable new data points:
- **Qwen 2.5 7B as a reviewer flagged 15 items at $0.00 cost** — identical flag count to anthropic:opus-4.8 ($0.40) on the same items. This is the most cost-efficient reviewer if its flag quality holds up to manual inspection.
- **xAI Grok 4.3 raised the fewest flags of all working reviewers (6).** Whether this means most conservative or most permissive needs a per-flag look before promoting it to a primary slot.
- **Gemini Flash failed as a reviewer at first (non-JSON output) — fix confirmed.** Root cause: Gemini 2.5 Flash burns its `max_tokens` budget on internal "thinking" tokens before emitting visible output. At `max_tokens=16384` the entire budget went to thoughts and the JSON came back truncated mid-string. Setting `generationConfig.thinkingConfig.thinkingBudget=0` disables thinking and frees the full budget for the structured response. Post-fix verification: Gemini reviewer returns 8 valid flags with `stop_reason=end_turn`; Gemini reducer returns 4 flags (vs the suspect 1 from the prior round, which was the same silent-truncation bug). Same root cause for both. The full benchmark numbers below still reflect the pre-fix runs; a follow-up re-bench would tighten them.

## Roles defined

**Generator.** The only stage that writes new content. It reads the course's OER materials and the IDD (learning outcomes with their Bloom levels, plus the section-to-LO source mappings) and drafts the full item bank, one set of multiple-choice items per learning outcome, matching the source exam's numbering, option count, and conventions. Everything downstream only edits or checks what the generator produces, which is why its quality drives the most cost: a cleaner first draft means fewer items hit the regeneration loop.

**Reviewers.** One or more models, drawn from different providers, that each independently audit the generated bank against the QA rubric without seeing one another's verdicts. Each returns a structured list of flagged items with a reason code (Bloom misalignment, duplicate of source, format violation, missing items) and a suggested fix. Using a different lineage than the generator is deliberate: a model is poor at catching its own blind spots, so cross-model review surfaces problems the generator would otherwise rubber-stamp.

**Reducer.** A single model that consolidates the reviewers' separate flag lists into one de-duplicated, prioritized set of actions, each keyed to a specific item id. It resolves disagreements (two reviewers flagging the same item for different reasons, or one flagging what another passed), strips out noise, and decides which items truly need regeneration versus which flags to ignore. Its output is the single source of truth the regeneration step consumes.

**Regeneration.** The generator model run a second time, but only on the items the reducer marked, rewriting each to address its specific flag while leaving everything that passed untouched. Because it reuses the generator, the generator choice also determines regeneration quality and cost.

## Methodology

**Input.** Same fixture as the 2026-06-04 round: the BUS101 Credit Final Exam (43 LOs across 5 units, 307 source items) used as a single fixed input across every run. The LO list was extracted from the exam's item ids and used as a synthetic IDD; the materials table was a brief placeholder pointing back to the source exam content.

**Phase 1: Generator bake-off.** Five candidate generators (saylor-vllm:qwen2.5-7b, gemini:2.5-flash, openai:gpt-5.4, anthropic:opus-4.8, anthropic:sonnet-4.6) were each run twice end-to-end against the same input with chunked per-unit generation enabled. Reviewer + reducer held constant (one openai:gpt-5.4 reviewer + anthropic:sonnet-4.6 reducer). Quality proxy: count of critical flags the reviewer raised against the generator's output. Lower is better. Ordering was fastest-first so the bulk of the data lands quickly; Sonnet was last because Anthropic's 30K-tok/min rate limit on chunked Sonnet calls eats wall-clock.

**Phase 2: Reviewer ablation.** A single anthropic:sonnet-4.6 generated exam (run 0) was used as the common input. Each candidate reviewer (anthropic:opus-4.8, anthropic:sonnet-4.6, openai:gpt-5.4, openai:gpt-5.4-mini, xai:grok-4.3, gemini:2.5-flash, saylor-vllm:qwen2.5-7b) ran individually against the same items.

**Phase 3: Reducer ablation.** A three-reviewer panel (openai:gpt-5.4 + anthropic:opus-4.8 + anthropic:sonnet-4.6) produced reports against the same Sonnet-4.6 output. Each candidate reducer (anthropic:opus-4.8, anthropic:sonnet-4.6, openai:gpt-5.4, gemini:2.5-flash) consolidated the same input.

**What this benchmark does not measure.** It does not measure correctness against human-rated ground truth. It does not measure pedagogical quality beyond what a reviewer model can detect. Sample sizes are small (2 per Phase 1 generator, 1 per Phase 2 reviewer and Phase 3 reducer). The Qwen 7B reviewer matched Opus 4.8 by flag count, but flag overlap with the strong reviewers was not measured; the next round should do that before promoting Qwen to a primary reviewer slot.

## Phase 1: Generator bake-off

Same input, same reviewer (openai:gpt-5.4), same reducer (anthropic:sonnet-4.6). Two runs per generator. Order: fastest-first.

| Generator | Runs | Avg cost | Avg elapsed | Avg critical flags | Verdict |
| :-: | :-: | :-: | :-: | :-: | :-: |
| **openai:gpt-5.4** | 2 | **$0.55** | **96s** | **10.0** | Still top: cheapest among full-strength generators, fastest, fewest flags |
| anthropic:opus-4.8 | 2 | $2.42 | 131s | 10.5 | Same flag count as GPT-5.4 at 4× the cost; not worth it |
| anthropic:sonnet-4.6 | 2 | $0.77 | 340s | 13.5 | More flags than GPT, much slower (rate-limit drag) |
| gemini:2.5-flash | 1 + 1 anomaly | $0.27 | 190s | 13.0 (clean run) | Unstable: 1 run produced 124 items instead of 43, 67 flags |
| saylor-vllm:qwen2.5-7b | 0 in bench / 1 ad-hoc | $0.39 reviewer+refill | **2,951s (~49 min)** | 10 critical | Viable after 600s timeout fix but ~30× slower than GPT-5.4 |

**Per-run detail:**

- gpt-5.4 run 0: 14 critical / 15 total, $0.66 → run 1: 6 critical / 6 total, $0.44
- opus-4.8 run 0: 15 critical / 17 total, $1.74 → run 1: 6 critical / 6 total, $3.10 (cost swing inside the Anthropic family is real)
- sonnet-4.6 run 0: 14 critical / 16 total, $0.80 → run 1: 13 critical / 14 total, $0.74
- gemini-2.5-flash run 0: 66 critical / 67 total, $0.32 — 124 items emitted (format compliance failure). run 1: 13 critical / 13 total, $0.21, 43 items (clean).
- qwen2.5-7b run 0 and run 1: both timed out after 180s on the first generation call.

The GPT-5.4 advantage over Sonnet from the 2026-06-04 round holds: 10.0 vs 13.5 average critical flags, $0.22/run cheaper, 244 seconds faster. Opus matches GPT on flags this round but at 4× the cost.

**Qwen as a generator is blocked by infrastructure, not quality.** The chunked-generation path makes five back-to-back vLLM calls per run with a system prompt that's ~30K tokens after caching; the server can't return a complete response within the 180-second HTTP timeout. Three workable fixes for future evaluation: raise the client-side timeout in `SaylorVLLMProvider`, switch to a streaming response so the first byte arrives within the timeout, or have David allocate more inference capacity. None are blocking the current GPT-5.4 default.

**Gemini's run-0 overshoot** (124 items, 67 critical flags) is the kind of failure that a chunked-generation prompt is supposed to bound. The chunking gives the model a per-unit target count and an explicit "do NOT exceed" instruction; Gemini 2.5 Flash apparently ignored it in one run and respected it in the other. Same-prompt instability disqualifies it from generator default use.

## Phase 2: Reviewer ablation

Same anthropic:sonnet-4.6 generated exam (run 0). Each candidate reviewer runs individually against the same items.

| Reviewer | Cost | Elapsed | Total flags | Critical | Cost/flag |
| :-: | :-: | :-: | :-: | :-: | :-: |
| xai:grok-4.3 | $0.05 | 12s | 6 | — | 0.9¢ — fewest flags by a wide margin |
| openai:gpt-5.4 | $0.08 | 27s | 12 | — | 0.7¢ |
| **saylor-vllm:qwen2.5-7b** | **$0.00** | 82s | 15 | — | **free — matches Opus on volume** |
| anthropic:opus-4.8 | $0.40 | 37s | 15 | — | 2.7¢ — same volume as Qwen at non-zero cost |
| openai:gpt-5.4-mini | $0.04 | 15s | 18 | — | 0.2¢ — most efficient by volume |
| anthropic:sonnet-4.6 | $0.22 | 104s | 24 | — | 0.9¢ — most aggressive |
| gemini:2.5-flash | — | — | failed in bench; **8 post-fix** | — | Pre-fix: thinking-budget burned the response. Post-fix (thinkingBudget=0): clean 8 flags, end_turn. |

The headline of this phase is **Qwen 2.5 7B AWQ reviewing for free with the same flag count as Opus 4.8**. If the flag overlap with the strong reviewers is high (i.e., Qwen catches the same items Opus catches, not 15 unique random items), this is a free third opinion to add to the panel. If overlap is low, Qwen is adding noise rather than signal. The next experiment should be a head-to-head on flag overlap.

xAI Grok 4.3's 6 flags is the conservative end of the spread (Sonnet's 24 is the aggressive end). Whether 6 is the correct floor or 24 is the correct ceiling is what human-rated ground truth would answer.

## Phase 3: Reducer ablation

Same three-reviewer reports (openai:gpt-5.4 + anthropic:opus-4.8 + anthropic:sonnet-4.6 against the same Sonnet-4.6 generated exam). Each candidate reducer consolidates the same input.

| Reducer | Cost | Elapsed | Flags out | Notes |
| :-: | :-: | :-: | :-: | :-: |
| anthropic:opus-4.8 | $0.53 | 48s | 26 | Anthropic family agrees… |
| **anthropic:sonnet-4.6** | $0.24 | 89s | 26 | …same count, half the cost |
| openai:gpt-5.4 | $0.16 | 37s | 24 | Drops only 2 flags vs Sonnet/Opus; cheapest working reducer |
| gemini:2.5-flash | $0.02 | 64s | 1 in bench; **4 post-fix** | Pre-fix was the same thinking-budget truncation as the reviewer. Post-fix returns a real consolidation (4 flags from a 3-reviewer panel). |

The Anthropic Sonnet 4.6 vs Opus 4.8 result repeats the prior round: same flag count, half the cost — keep Sonnet. GPT-5.4 as reducer is a viable alternative ($0.16 vs $0.24) but drops 2 flags relative to the Anthropic pair, which may or may not be a feature depending on how aggressive your regeneration budget is.

Gemini Flash's 1-flag output on a 50-flag input is the second time it has produced a silent under-consolidation in this role. Whatever the underlying issue is (output schema mismatch, prompt-cache miss, hallucination of an empty result), the option needs to be disabled from the production reducer dropdown until diagnosed.

## Recommendations

| Role | Current default | Recommended | Confidence | Why |
| :-: | :-: | :-: | :-: | :-: |
| **Generator** | openai:gpt-5.4 | **Keep openai:gpt-5.4** | High | Held the top spot under a wider candidate set (added Qwen + Gemini). Sonnet 35% behind on flags; Opus matched on flags at 4× the cost; Qwen unusable on current infra; Gemini unstable. |
| **Reviewer (primary)** | xai:grok-4.3 + anthropic:sonnet-4.6 + saylor-vllm:qwen2.5-7b | **Same panel** | Medium | Qwen now actually works (vLLM bump), keeps the free third opinion. xAI's low flag count needs investigation; if it turns out to be too permissive, swap for openai:gpt-5.4. |
| **Reviewer (cheap 2nd)** | (none) | Add openai:gpt-5.4-mini as opt-in | Low | 18 flags at $0.04 is striking but, as last round, flag overlap with the bigger models is untested. |
| **Reducer** | anthropic:sonnet-4.6 | **Keep anthropic:sonnet-4.6** | High | Matches Opus's flag count at half the cost; GPT-5.4 viable but drops 2 flags; Gemini still broken. |

**Cost impact (no recommended change):** the per-exam math from the 2026-06-04 round still holds at ~$0.78 per BUS101-sized exam with the current defaults. Qwen as a free third reviewer is in production now so the marginal cost of broadening the panel is zero. Saving Opus 4.8 from getting promoted by mistake is the main monetary win of this round.

## Conclusion narrative

The generator decision sticks. Adding Qwen and getting Gemini through the door didn't shake the GPT-5.4 lead. Opus matched GPT on flag count in this round, which is more than it did last round — but at four times the cost. Even granting that Opus deserves consideration when the generator output is going through human review the same day, no scenario justifies the cost without a quality measurement that disagrees with this one.

The reviewer story got more interesting. Qwen 2.5 7B AWQ reviewing for free, with the same flag count as the most expensive reviewer in the panel, is the kind of result that's hard to take at face value without checking what Qwen actually flagged. The plan is to take the union of Qwen's flag list and Opus's flag list against the same Sonnet output and measure the overlap. If they're 80%+ overlapping, Qwen is doing real work and should stay default. If the overlap is low, Qwen is generating its own list rather than catching what other reviewers catch, and the right read is "free but not signal."

xAI Grok 4.3 raised six flags. That's such a wide gap from everyone else that two readings are plausible: (a) Grok is the calibrated reviewer and the others are paranoid, or (b) Grok is missing real issues. The honest answer is we don't know without human spot-checks. Production uses Grok as a primary reviewer right now, so this is worth resolving before the next merge.

The reducer story is "no news is good news." Sonnet 4.6 matched Opus 4.8 on consolidated flag count for half the cost, exactly as last round. Gemini Flash silently dropped 50-to-1, exactly as last round. Keep Sonnet, drop Gemini from the dropdown.

The two follow-ups that came out of this round:

1. **Qwen-vs-Opus reviewer flag overlap.** Take the 15 Qwen flags and the 15 Opus flags on the same Sonnet-4.6 generated exam, compute the overlap, and decide whether Qwen is signal or noise. Cost: ~$0.02, time: ~2 minutes.
2. **Qwen-as-generator timeout investigation.** Either raise the client timeout in `SaylorVLLMProvider`, add streaming support to the provider, or get David to allocate more capacity. The reviewer-only mode wastes most of the free compute.

The broader caveat from the 2026-06-04 round still applies: every result here is a proxy. The right measure remains "items a Saylor content reviewer accepts on first pass." Until that is instrumented, every recommendation carries the caveat that we are optimizing what we can measure, which is not necessarily what we want.

## Caveats — read before acting

1. **N=2 per generator.** Variance not fully captured. GPT-5.4 swung from 14 critical (run 0) to 6 (run 1); Opus swung from 15 to 6 — both candidates were lucky on run 1.
2. **Single reviewer judged all generators.** openai:gpt-5.4 was the judge again. If GPT-5.4 has systematic blind spots, all "quality" scores carry the same bias. A second-pass with a different judge would be cheap insurance.
3. **N=1 per reviewer (Phase 2 ablation) and per reducer (Phase 3).** The 6-vs-24 flag spread is the biggest variation; with two or three judged exams instead of one, that gap might shrink.
4. **No human ground truth.** Same as last round; same caveat.
5. **Qwen 2.5 7B reviewer flag content was not compared to other reviewers' flag content.** Same flag count does not mean same flags. Flag overlap analysis is the next experiment.
6. **Gemini Flash reviewer failure was on a single payload.** It is possible the JSON-parse error is prompt-specific rather than provider-wide. Still, the same lineage failed silently as reducer in two consecutive rounds — pattern suggests structural fragility under structured-output prompts.

## Files

- /tmp/benchmark_results/phase1_generators.json — raw per-run data (Phase 1)
- /tmp/benchmark_results/phase2_reviewers.json — raw per-reviewer data (Phase 2)
- /tmp/benchmark_results/phase3_reducers.json — raw per-reducer data (Phase 3)
- /tmp/benchmark_results/summary.json — combined
- /tmp/benchmark_results/gen_*.md — each generator's actual output for hand-inspection
- /tmp/benchmark_results/qc_*.json — each run's reducer report (which items got flagged + why)
- ~/Library/CloudStorage/Dropbox/!Saylor/ai-projects/exam-booster/scripts/benchmark.py — the harness, re-runnable
