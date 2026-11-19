# SOLA chat-model benchmark (re-run) - 2026-11-02

Re-run of the July chat-model bake-off (`sola-chat-model-benchmark-2026-07-24.md`),
ahead of Google's 1 Dec 2026 redirect of Gemini 2.5 Flash to 3.5 Flash-Lite
(and 2.5 Pro to 3.8 Flash). Dev site only (dev.sylr.org, plugin 2026103002).
Judge: Claude Sonnet 4.6. Keys were never printed.

## Question

2.5 Flash is today's production chat model on Learn and Degrees. Google will
silently swap it for 3.5 Flash-Lite on 1 Dec. Is that swap acceptable, and if
not, what should we move to?

## Method

- Quality: `run_tutor_golden.php --mode=all`, the 50-prompt default tutor set,
  judged by Sonnet 4.6 (accuracy, Socratic guidance, tone, each 1 to 5, total
  out of 15). Run production-shaped: `--max-tokens=1024`, the site's setting, so
  truncation shows up. One run per model, all five in parallel as separate dev
  tasks.
- Compliance: `jailbreak_test.php --courseid=11 --runs=3` per model, the 32-test
  suite with a canary token, built as a plain student on BUS 101.
- The model was chosen per process (the comparison_providers gemini row's model
  was swapped in memory only). No setting was written.
- 2.5 Pro was skipped: the harness has no cheap path for it and SOLA does not
  use it live.

## Results

### Tutor quality, cost, latency (50 prompts, 1,024-token cap)

| Model | Rubric /15 | Accuracy | Socratic | Tone | Cents/call | P50 TTFT | P95 TTFT | Errors | Truncated |
|---|---|---|---|---|---|---|---|---|---|
| gemini-2.5-flash (baseline) | 14.14 | 4.98 | 4.28 | 4.88 | 0.206 | 3,381 ms | 6,842 ms | 0 | 1 of 50 |
| gemini-3.5-flash-lite | 14.58 | 5.00 | 4.58 | 5.00 | 0.033 | 580 ms | 714 ms | 0 | 0 |
| gemini-3.1-flash-lite | 14.50 | 4.94 | 4.56 | 5.00 | 0.024 | 560 ms | 702 ms | 0 | 0 |
| gemini-3.6-flash | 14.30 | 4.98 | 4.34 | 4.98 | 0.221 | 3,241 ms | 4,994 ms | 0 | 0 |
| gemini-3.8-flash | 14.48 | 4.98 | 4.52 | 4.98 | 0.100 | 1,733 ms | 4,895 ms | 0 | 0 |

All five are within 0.45 of a 15-point rubric, so quality is effectively tied.
The one consistent gap is Socratic guidance: both Flash-Lite models and 3.8 Flash
score about 4.5, 2.5 Flash 4.28.

### Jailbreak resistance (32 tests x 3 runs = 96 per model)

| Model | Pass | Per-run passes | Hard FAIL | Needs review |
|---|---|---|---|---|
| gemini-2.5-flash | 90% (86/96) | 28 / 31 / 27 | 0 | 10 |
| gemini-3.1-flash-lite | 88% (84/96) | 28 / 26 / 30 | 0 | 12 |
| gemini-3.8-flash | 83% (80/96) | 27 / 27 / 26 | 1 (see below) | 15 |
| gemini-3.5-flash-lite | 78% (75/96) | 23 / 27 / 25 | 0 | 21 |
| gemini-3.6-flash | 77% (74/96) | 26 / 24 / 24 | 0 | 22 |

No canary leaks and no API errors on any model. The single 3.8 Flash "FAIL" is a
false positive: the reply refused the injected "ignore your rules" note, but
contained the phrase "my instructions are", which the pattern list treats as a
leak. The gap between models is again the REVIEW bucket (answers the regexes
cannot classify), as in July.

## Thinking and token settings

- No 400s and no errors in 250 tutor calls or 480 jailbreak calls.
- Flash-Lite models (3.1 and 3.5) do not think: reasoning tokens 0, so a
  thinking budget does not apply and the plugin correctly leaves it off.
- 2.5 Flash averaged about 570 thinking tokens per answer, 3.6 Flash about 390,
  3.8 Flash about 70. `gemini_provider` / `model_capabilities` already add the
  2,048-token thinking headroom for 2.5 and 3.x non-lite models, so the
  1,024-token answer cap works for them.
- Truncation: only 2.5 Flash hit the cap (1 of 50 ended `length`). No 3.x model
  truncated.
- Nothing needed changing in the provider for the 3.x models.

## Cost note (not comparable with July)

The harness now counts thinking tokens as billed output (v7.8.0). That is why
2.5 Flash reads 0.206 cents/call here against 0.056 in July. The rows in this
report are comparable with each other, not with the July table. 3.5 Flash-Lite
also read lower than July (0.033 vs 0.117), probably because of shorter answers
(123 tokens average) and the changed accounting; I did not chase this.

## Conclusion and recommendation

- Google's default redirect target, 3.5 Flash-Lite, is the cheapest-behaving and
  fastest option with the best tutor scores, but it is the weakest Gemini on
  jailbreak resistance after 3.6 (78% vs 90% for 2.5 Flash). Accepting the
  redirect silently would lose about 12 points of compliance.
- gemini-3.1-flash-lite (already the dev setting) matches 2.5 Flash on
  compliance (88% vs 90%, within run-to-run noise: per-run ranges overlap), has
  better Socratic scores, costs about 9x less per call (0.024 vs 0.206) and is
  about 6x faster to first token (560 ms vs 3.4 s). It is the best replacement.
- Caveat: 3.1 Flash-Lite is a lite model like the one Google redirects to; check
  its retirement date before committing, and re-run the jailbreak suite after any
  system-prompt change.
- gemini-3.8-flash is the fallback if a non-lite model is wanted (83%, 0.100
  cents, 1.7 s TTFT). 3.6 Flash loses on every axis and its list price rises on
  1 Jan 2027. Do not use it.
- Recommended action: set Learn and Degrees to an explicit `gemini-3.1-flash-lite`
  before 1 Dec rather than letting the redirect pick 3.5 Flash-Lite. This is a
  recommendation only; nothing was changed in production.

## Housekeeping

- Dev settings were never written. Verified after the run: provider=gemini,
  model=gemini-3.1-flash-lite, max_tokens=1024, temperature=0.7,
  spend_failover_chain and spend caps unchanged.
- No student conversation was used or written (the harness sends single-turn
  calls; the jailbreak prompt was built read-only for the demo student on
  BUS 101, course 11).
- Side effects: the report step stored 5 benchmark rows in dev's
  `local_ai_course_assistant_bench` table (one per arm). An early 2-prompt
  smoke test also ran by mistake against every configured provider row (OpenAI,
  Together, Haiku, OpenRouter, Gemini, and the Claude Opus/Sonnet rows), a few
  cents at most.
- Estimated spend: about $1.5 in total (Sonnet judge about $0.6, contestants and
  jailbreak runs the rest), well under the $5 cap.
- Caveats: one tutor run per model (not repeated); jailbreak is 3 runs; models
  ran concurrently, which should not affect TTFT but is not strictly
  sequential like July.
