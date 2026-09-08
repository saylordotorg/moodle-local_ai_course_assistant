# SOLA chat-model benchmark (thorough) — 2026-07-24

Thorough re-run of the chat-tier evaluation, extending the 2026-07-22 bake-off
(`sola-gemini-chat-model-bakeoff-2026-07-22.md`) with multiple jailbreak runs
per model (to average out REVIEW-bucket variance) and an explicit
cost/latency matrix. Run against the deployed v6.9.4 hardened system prompt
on dev (md5 e02b16d7). Keys pulled from comparison_providers, never printed.

## Question

Among the Gemini chat candidates (gemini-2.5-flash, gemini-3.6-flash,
gemini-3.5-flash-lite), which is the most compliant and cost-effective? If
gemini-2.5-flash still wins both, adopt it as the dev-fleet chat model.
gpt-4o-mini included as a reference/control.

## Method

- Quality: `run_tutor_golden.php --mode=all` over the full 50-prompt default
  tutor set, judged by Claude Sonnet 4.6 (rubric out of 15). Reports cost
  (cents/call) and latency (P50/P95 TTFT, P50 total).
- Compliance: the 32-test `jailbreak_test.php` suite run 3 times per model
  against the v6.9.4 hardened prompt; reported as mean PASS with min/max and
  total hard FAILs across the 3 runs.

## Results

### Compliance (jailbreak, 3 runs; higher is better)

| Model | Mean pass | Range | Hard FAILs (3 runs) |
|---|---|---|---|
| gpt-4o-mini (reference) | 98% (31.3/32) | 31-32 | 0 |
| gemini-2.5-flash | 86% (27.7/32) | 26-29 | 0 |
| gemini-3.5-flash-lite | 79% (25.3/32) | 25-26 | 0 |
| gemini-3.6-flash | 62% (20.0/32) | 18-21 | 0 |

Zero hard FAILs on every model and run: no model actually leaked or complied
with an attack. The spread is in the REVIEW bucket. Among the Gemini models,
2.5-flash is clearly the most compliant. flash-lite improved and stabilized
under the v6.9.4 hardening (79%, tight 25-26 band, up from 66-72% pre-harden)
but remains below 2.5-flash. 3.6-flash is the lowest-scoring of all four.

### Quality, cost, latency

| Model | Rubric /15 | Cost cents/call | P50 TTFT | P95 TTFT | P50 total |
|---|---|---|---|---|---|
| gemini-2.5-flash | 14.14 | 0.056 | 1964 ms | 4568 ms | 2839 ms |
| gemini-3.6-flash | 14.36 | n/a | 3657 ms | 5729 ms | 4881 ms |
| gemini-3.5-flash-lite | 14.66 | 0.117 | 426 ms | 483 ms | 787 ms |
| gpt-4o-mini | 12.74 | 0.013 | 417 ms | 698 ms | 1920 ms |

Quality among the three Gemini models is effectively tied (14.14 to 14.66, a
0.5-point spread on a 15-point rubric). gpt-4o-mini is the quality laggard
(12.74). On cost, 2.5-flash is the cheapest Gemini at 0.056 cents/call, about
half of flash-lite (0.117) despite lite's lower headline per-token price
(lite's output tokens are ~6x pricier and it is more verbose). 3.6-flash's
cost was not computed by the harness, but its list price (~$1.50/$7.50 per 1M
tokens) is roughly 10x 2.5-flash's ($0.10/$0.40) and it is the slowest model.

## Conclusion

Among the Gemini candidates, **gemini-2.5-flash is both the most compliant
(86% vs 79% and 62%) and the most cost-effective (0.056 vs 0.117 cents/call;
3.6-flash far higher), with quality effectively tied.** flash-lite's only
edge is latency (787 ms vs 2839 ms), which does not justify 2x the cost and
7 points less compliance for a learner tutor. 3.6-flash loses on every axis
except a negligible quality bump.

The decision rule was met, so **gemini-2.5-flash is set as the dev-fleet chat
model**, replacing the flash-lite dev-only experiment and aligning dev with
Saylor production (which already runs Gemini 2.5 Flash as its chat primary).
gpt-4o-mini remains the OpenAI failover and the shipped `auto` fallback; it is
the most compliant overall but trails on tutor quality.

## Action taken (2026-07-24)

- Set `provider=gemini`, `model=gemini-2.5-flash` on 4 of 5 dev sites: dev,
  dev405, dev500, dev501 (all on the legacy instance). Each site's main
  `apikey` was set to the shared dev Gemini key, propagated on-box without
  exposure (only dev.sylr.org previously had a Gemini key configured).
- dev503 (separate instance) still needs its Gemini key set: the dev EC2 role
  cannot write S3 or put SSM parameters, and the key was deliberately not
  routed through the operator session. Set it via the admin UI (SOLA settings:
  Provider = Gemini, Model = gemini-2.5-flash, paste the Gemini key) or a
  direct CLI on that box.
- The shipped default stays `auto` (unchanged). This is a dev-fleet config
  change only, not a plugin default or a policy-bundle change.
