# SOLA Golden Tutor Benchmark Report

Generated: 2026-06-09T16:55:07-05:00

Sources:
- run: `2026-06-09-162331-run.csv`
- judge: `2026-06-09-165144-judge.csv`

## Per-provider summary (sorted by rubric mean desc, then cost asc)

| Provider | Model | Calls | Errors | Avg cost (cents/call) | P50 TTFT (ms) | P95 TTFT (ms) | P50 total (ms) | Avg rubric (max 15) | Pareto? |
|----------|-------|------:|------:|---:|---:|---:|---:|---:|:-:|
| claude:claude-opus-4-8 | claude-opus-4-8 | 40 | 0 | 1.377 | 852 | 1789 | 7385 | 14.98 | yes |
| gemini:gemini-2.5-pro | gemini-2.5-pro | 40 | 0 | 0.296 | 11539 | 16793 | 13128 | 14.85 | yes |
| claude:claude-sonnet-4-6 | claude-sonnet-4-6 | 40 | 0 | 0.884 | 1142 | 1868 | 8827 | 14.40 |  |
| openai:gpt-4o | gpt-4o | 40 | 0 | 0.437 | 417 | 850 | 3993 | 12.68 |  |

## Decision

Winner per the section 3.5 decision rule: **gemini:gemini-2.5-pro** (gemini / gemini-2.5-pro).
- Avg rubric: 14.85 (within 0.3 of the top score).
- Avg cost: 0.296 cents/call.
- Pareto frontier: yes.

## Pareto frontier (rubric vs. cost)

These providers are not dominated by any other on BOTH cost (lower) and rubric (higher):

- claude:claude-opus-4-8
- gemini:gemini-2.5-pro
