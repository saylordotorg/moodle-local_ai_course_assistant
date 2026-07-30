# SOLA model benchmark — Claude 5 family vs. incumbents (2026-07-30)

Natural-language re-run of the chat-tier evaluation, extending the 2026-07-24 bake-off to include the newly released **Claude Opus 5** and **Claude Sonnet 5**, plus a re-measurement of the retrieval layer (embeddings, reranker, RAG pipeline). Run against deployed v6.9.4 on dev.

**Bottom line: Opus 5 is not worth adopting anywhere in SOLA's current workload. Gemini 2.5 Flash stays the chat primary. The one clear upgrade available is Claude Haiku 4.5 for the quiz coach. On the retrieval side, keep OpenAI embeddings, keep the reranker opt-in, and keep parent-document retrieval off.**

## Method

- **Prompts:** the 50-prompt golden tutor set (`tests/golden/tutor_prompts.json`) — real natural-language learner messages, tagged by SOLA function: socratic explanation, quiz coach, illustration, anti-cheat, multilingual (10 each).
- **Harness:** `admin/cli/run_tutor_golden.php --mode=all`, judged by Claude Sonnet 4.6 on a 15-point rubric (Socratic guidance / factual accuracy / tone match). The judge is deliberately outside the contestant pool.
- **Scale:** 5 models x 50 prompts = **250 calls, 0 errors**, plus 250 judge calls.
- **Cost figures** come from the live rate card (`rate_card_overrides`, auto-refreshed from the LiteLLM manifest), verified current for all five models.
- **Total API spend: roughly $2.50** for the chat benchmark, plus about $0.20 across the retrieval runs.
- **Retrieval:** `admin/cli/run_rag_fixture_benchmark.php` against the live dev index for BUS101 and POLSC101, in two modes — recall against ground-truth chunk ids, and LLM-judged relevance. Two query styles: 40 human-written terse colloquial queries and 40 synthetic multi-sentence conversational questions.

## Results — overall

| Model | Rubric /15 | Cost (cents/call) | P50 TTFT | P95 TTFT | P50 total | Pareto |
|---|---|---|---|---|---|---|
| claude-sonnet-5 | **14.44** | 0.366 | 1740 ms | 6018 ms | 5688 ms | yes |
| claude-opus-5 | 14.42 | **2.273** | 6317 ms | 16454 ms | 14214 ms | no |
| gemini-2.5-flash | 14.16 | 0.052 | 2272 ms | 5967 ms | 3108 ms | yes |
| claude-haiku-4-5 | 14.10 | 0.115 | **612 ms** | 1412 ms | 3153 ms | no |
| gpt-4o-mini | 12.86 | **0.012** | 461 ms | 892 ms | 2698 ms | yes |

## Results — by SOLA function

| Model | Socratic | Quiz coach | Anti-cheat | Multilingual | Illustration |
|---|---|---|---|---|---|
| claude-sonnet-5 | **15.00** | 14.50 | 14.10 | **14.70** | 13.90 |
| claude-opus-5 | 14.40 | 14.70 | 14.30 | **14.70** | **14.00** |
| gemini-2.5-flash | 14.40 | 14.30 | **14.50** | 14.40 | 13.20 |
| claude-haiku-4-5 | 13.90 | **14.80** | **14.50** | 13.80 | 13.50 |
| gpt-4o-mini | 12.10 | 13.90 | 13.20 | 13.10 | 12.00 |

## What the data says

**1. Opus 5 does not earn its place.** It never wins a category by a meaningful margin, yet costs **6.2x Sonnet 5** and **44x Gemini 2.5 Flash**, and is the slowest model tested by a wide margin — 14.2 seconds median end-to-end versus Gemini's 3.1. For a learner waiting on a tutor reply, that latency is a product problem independent of cost. Opus 5 is an excellent model; SOLA's tutoring workload simply does not have a task hard enough to need it.

**2. Quality has converged at the top.** Four of five models land between 14.10 and 14.44 — a 0.34-point spread on a 15-point rubric, which is inside the noise of a single-pass judge. The meaningful differences in this table are **cost and latency**, not quality. That is a change from earlier bake-offs where quality genuinely separated the field.

**3. Gemini 2.5 Flash remains the right chat primary.** 14.16 rubric at 0.052 cents/call. Sonnet 5 buys 0.28 rubric points for 7x the cost. No change recommended.

**4. The real upgrade is the quiz coach — and it was never on the model we documented.** The vendor docs say quiz coaching runs on gpt-4o-mini. It does not. `generate_quiz.php` called `base_provider::create_from_config()`, which resolves the **chat** provider, and there was no quiz-specific setting to override it. On the dev fleet that means quiz generation has been running on **Gemini 2.5 Flash**, not gpt-4o-mini. The documented recommendation was never implementable.

**Haiku 4.5 wins quiz coach outright at 14.80** — ahead of Gemini's 14.30 (what actually runs today) and gpt-4o-mini's 13.90 (what we thought ran) — with the best latency in the field (612 ms TTFT). PR #165 adds the missing `quiz_provider` / `quiz_model` settings so the switch is possible at all.

**5. Sonnet 5 is the standout on Socratic explanation** — a clean 15.00/15, the only perfect category score in the run. That is SOLA's core tutoring behaviour, and it is worth noting even though the cost still favours Gemini.

## Recommendations

| Function | Current | Recommendation |
|---|---|---|
| Chat tutor | Gemini 2.5 Flash | **Keep.** Best cost/quality; re-confirmed. |
| Quiz coach | Chat provider (Gemini 2.5 Flash on dev) — see note | **Switch to Haiku 4.5** — wins the category (14.80 vs 13.90), best latency, still very cheap. Requires PR #165. |
| Anti-cheat / integrity | Haiku 4.5 | **Keep.** Ties for the category win (14.50). |
| Premium escalation | Opus 4.8 | **Evaluate Sonnet 5** — but not on this evidence; see caveats. |
| Chat failover | gpt-4o-mini | **Keep.** Weakest on quality but cheapest and the most compliant model in the 2026-07-24 jailbreak run (98%). |
| Embeddings | OpenAI text-embedding-3-small | **Keep.** Voyage-3.5 is no better and slightly behind on every judged metric. |
| Reranker | Voyage rerank-2.5, off by default | **Keep opt-in.** Real recall gain (+17.5 pp curated, +12.5 pp conversational) but near-tied under independent judging; ~$0.001/query. |
| Parent-doc retrieval | Off by default | **Keep off.** Cuts judged precision@5 from 0.695 to 0.435. |

## Retrieval — embeddings, reranker, RAG

The chat benchmark above measures the model that writes the answer. This section measures the retrieval layer that decides what the model sees. Both were re-run on dev against the live index (BUS101 + POLSC101, 40 fixtures each).

### Embeddings: OpenAI vs Voyage

**Voyage is measurably slightly worse on our corpus. Keep OpenAI.** This now has two independent confirmations.

The recall-mode arm reproduced the 2026-07-08 OpenAI baseline **exactly** — R@1 32.5%, R@3 55.0%, R@5 65.0%, MRR 0.468 — which confirms the harness and the index are stable and the earlier comparison is still valid ground.

The head-to-head came from the judged run, scoring both providers on the conversational question set:

| Arm | Model | nDCG@5 | P@5 | hit@5 | Mean relevance |
|---|---|---|---|---|---|
| openai:3-small | text-embedding-3-small | **0.951** | **0.700** | 1.000 | **1.89** |
| voyage:3.5 | voyage-3.5 | 0.943 | 0.665 | 1.000 | 1.84 |

Voyage trails on every metric. The gaps are small enough to be near the noise floor of a 40-question single-pass judge, so the honest reading is **"no better, possibly slightly worse"** rather than "clearly worse." Either way there is no case for migrating: the 2026-07-08 finding holds, now on a second query style and a different scoring method.

**Note a harness gap found along the way.** `--voyage-apikey` is honored by judge mode but ignored by the `--embed-provider` recall path, which reads the key from `embed_apikey` config instead. That is why the recall-mode Voyage arm died with an authentication error while the judged arm ran fine on the same credential. Worth a small fix so the two paths behave alike; it does not affect any number above.

### Reranker: Voyage rerank-2.5

This is the finding that holds up. Two-stage retrieval (embed a pool of 30, rerank to top-10) was measured on both query styles.

| Query set | Metric | Embedding only | With rerank-2.5 | Delta |
|---|---|---|---|---|
| Curated colloquial (40) | R@3 | 55.0% | **72.5%** | **+17.5 pp** |
| | R@5 | 65.0% | 82.5% | +17.5 pp |
| | MRR | 0.468 | 0.577 | +0.109 |
| Synthetic conversational (40) | R@3 | 72.5% | **85.0%** | **+12.5 pp** |
| | R@5 | 77.5% | 95.0% | +17.5 pp |
| | MRR | 0.646 | 0.745 | +0.099 |

The +17.5 pp headline from the 2026-06-11 benchmark **reproduces exactly**, and the lift survives the switch to realistic multi-sentence questions. Added latency is modest: P50 238 ms on curated, 273 ms on conversational. Measured cost is roughly **$0.001 per query** ($0.00097 curated, $0.00109 conversational), which is the ~$485/mo figure at the 100k MAU usage model.

**Where it is weaker than the headline suggests.** The gain is not uniform, and two results argue for keeping it opt-in:

- **It can hurt the top slot.** On POLSC101 (curated), reranking moved R@1 *down* 60.0% to 50.0% and MRR down 0.704 to 0.654, while still improving R@3. The reranker reorders a good pool well but is not strictly better at rank 1.
- **Independent judging shows far less lift.** Scored by an LLM judge on relevance rather than against a ground-truth chunk, baseline and rerank are nearly tied — nDCG@5 0.952 vs 0.969 — and precision@5 slightly *declines* (0.695 to 0.660). Recall@k rewards surfacing one specific chunk; the judge asks whether the whole returned set is useful. Rerank clearly wins the first framing and roughly ties on the second.

**Recommendation: enable on dev at `rerank_candidates=30`, keep it off by default for prod** until the cost model is revisited at scale. Unchanged from 2026-06-11, now with a second query style and an independent judge behind it.

### Full pipeline comparison (judged)

The judged run also scored the other retrieval configurations, which is the clearest view of how the pieces interact:

| Pipeline arm | nDCG@5 | P@5 | hit@5 | Mean relevance |
|---|---|---|---|---|
| baseline (embedding only) | 0.952 | **0.695** | 1.000 | **1.89** |
| rerank only | **0.969** | 0.660 | 1.000 | 1.83 |
| parent-doc only | 0.951 | 0.435 | 1.000 | 1.15 |
| full: rerank + parent (window) | **0.969** | 0.450 | 1.000 | 1.22 |
| full: rerank + parent (page) | 0.965 | 0.450 | 1.000 | 1.21 |

**The notable result is parent-document retrieval.** It cuts precision@5 by more than a third (0.695 to 0.435) and mean relevance from 1.89 to 1.15, while nDCG barely moves. That is the expected shape — expanding a matched chunk to its parent document pulls in surrounding material that is topically adjacent but not what was asked — but the size of the drop is larger than the feature's framing suggests. Enabling rerank alongside it does not recover the loss.

Parent-doc retrieval is **off by default**, and this run supports keeping it that way. If it is ever turned on for a specific course, it should be measured on that course rather than assumed neutral.

Two caveats on this table. `hit@5` is 1.000 for every arm, meaning at least one relevant chunk always appeared in the top 5 — the corpus is small and the questions are on-topic, so this metric is saturated and carries no signal here. And the judge is gpt-4o-mini, the weakest model in the chat benchmark above; a stronger judge would make these comparisons more trustworthy.

### On the realism of the query set

Worth stating plainly, because it changes how to read the numbers above. The synthetic conversational questions are **easier than the curated ones, not harder**. Baseline R@3 is 72.5% on the synthetic set versus 55.0% on the curated set, and target-chunk cosine is much higher (mean 0.638 vs 0.486).

The reason is leakage: each synthetic question was generated *from* its target chunk, so it inherits that chunk's vocabulary. That makes it easy to retrieve. Real learners do not phrase questions using the source material's wording — the curated set, written as terse colloquial openers ("what even is a business? like why do we need them"), is the more honest proxy for production difficulty.

So the two sets bracket the problem usefully: **treat the curated numbers as the conservative estimate and the conversational ones as the optimistic bound.** Both agree the reranker helps; they disagree only on how much.

One reassurance from both runs: with `rag_min_similarity` at 0.25, **0 of 40 target chunks fell below the production floor** on either set. The floor is not silently dropping good material.

## Caveats — what this run does *not* establish

These matter, and they bound how far the recommendations should be pushed.

- **No compliance testing.** This run measured quality, cost and latency only. Compliance was the *deciding factor* in the 2026-07-24 benchmark (Gemini 2.5 Flash 86%, gpt-4o-mini 98%). **Sonnet 5 and Opus 5 have not been jailbreak-tested against the v6.9.4 hardened prompt.** No learner-facing switch to either should happen before a 3-run jailbreak pass.
- **The premium-escalation question is unanswered.** The premium router targets hard multi-step STEM prompts. This golden set is general tutoring, so it does not exercise that workload. Sonnet 5 looks promising as a cheaper premium tier, but that needs the 40-prompt domain set (`tutor_prompts_domains.json`) or the original A.10 hard-prompt corpus before acting.
- **Structured-output functions untested.** The mastery classifier, analytics digests and Soapbox speech scoring are JSON/rubric tasks, not conversational. gpt-4o-mini's weak conversational showing does not imply weakness there, and prior analysis found that function saturated.
- **Single pass.** One run per prompt, one judge pass. The 2026-07-24 work averaged 3 runs to smooth judge variance; treat sub-0.5-point gaps as ties.
- **The retrieval judge is gpt-4o-mini.** Every judged retrieval number above was scored by the weakest model in the chat benchmark. The recall-mode numbers do not depend on it (they use ground-truth chunk ids), but the nDCG / precision comparisons do. Re-running the judge on Sonnet 4.6, as the chat benchmark does, would firm them up.
- **Retrieval was measured on two courses.** BUS101 and POLSC101 only, 40 fixtures each. Rerank lift already varies noticeably between just these two (+20.0 pp on BUS101 vs +10.0 pp on POLSC101 for the curated set), so per-course variance across the full catalog is likely wider than these averages suggest.
- **Gemini cost carries a question mark.** The rate card prices Gemini 2.5 Flash at $0.30/$2.50 per MTok; the 2026-07-24 doc used $0.10/$0.40. If the lower figure is correct, Gemini is ~3x cheaper still — the direction of the error only strengthens the recommendation, but the absolute figure should be confirmed.

## Suggested next steps

1. **Jailbreak-test Sonnet 5 and Opus 5** (3 runs each) against the v6.9.4 prompt — required before any learner-facing change.
2. **Run the domain set** (math / science / cs_tech) with Sonnet 5 vs Opus 4.8 vs Opus 5 to settle the premium-escalation tier properly.
3. **Switch the quiz coach to Haiku 4.5** — the best-supported change in this run. Needs PR #165 merged first.
4. **Confirm the Gemini rate** against Google's current list price.
5. **Re-run the retrieval judge on Sonnet 4.6** instead of gpt-4o-mini, so the rerank and parent-doc comparisons rest on the same judge quality as the chat benchmark.
6. **Fix `--voyage-apikey` in the recall path** so the embedding A/B can run in both modes on the same credential.

## Appendix — reproducing

```
php admin/cli/run_tutor_golden.php --mode=all --delay=0.3 \
  --providers=claude:claude-opus-5,claude:claude-sonnet-5,claude:claude-haiku-4-5,gemini,openai \
  --judge-provider=claude --judge-model=claude-sonnet-4-6 --out=/tmp/golden
```

Requires the Claude 5 temperature deny-list fix (PR #163) — without it every Opus 5 / Sonnet 5 call returns HTTP 400, because those models reject sampling parameters.

Retrieval runs:

```
# Rerank lift, curated colloquial queries
php admin/cli/run_rag_fixture_benchmark.php \
  --fixtures=tests/golden/rag_fixtures_bus101_pol101.json \
  --candidates=30 --topk=10 --voyage-apikey=<key> --out=/tmp/rerank.json

# Rerank lift, synthetic conversational queries (recall mode)
php admin/cli/run_rag_fixture_benchmark.php \
  --fixtures=tests/golden/rag_fixtures_conversational_recall_bus101_pol101.json \
  --candidates=30 --topk=10 --voyage-apikey=<key> --out=/tmp/conv_recall.json

# Judged pipeline + embedding comparison
php admin/cli/run_rag_fixture_benchmark.php --judge \
  --questions=tests/golden/rag_fixtures_conversational_bus101_pol101.json \
  --topk=5 --voyage-apikey=<key> --out=/tmp/conv_judge.json
```

`rag_fixtures_conversational_recall_bus101_pol101.json` was added for this run. The conversational set was originally built for judge mode only (`{"questions": [...]}`, no ground truth), so it could not be scored with recall@k. Each question was generated from a specific chunk and the fixture ids encode it (`conv_<courseid>_<chunkid>`), so the recall-mode file reuses the same 40 questions with `expected_chunk_id` recovered from the id — verified against the live index, 40 of 40 resolving to a chunk in the stated course.
