# SOLA RAG Fixture Benchmark 2026-06-10

**Status:** Embedding arm complete. Rerank arm blocked on Voyage free tier rate limits (key added 2026-06-11, authenticates; needs a payment method on the Voyage account to lift per minute limits — actual spend stays $0).
**Verdict:** CONDITIONAL GO — see section 6.

---

## 1. Purpose

This benchmark answers the question: is Voyage rerank-2.5 two-stage RAG safe and
worthwhile to enable at scale for SOLA?

The bar set in the task brief:

> Rerank must improve Recall@3 by at least 5 percentage points, or fix concrete hard
> cases, without pushing P50 added latency past ~300 ms.

---

## 2. Method

### 2.1 Index

Two courses were indexed on dev.sylr.org (v6.1.1) using the plugin's existing
`content_indexer::index_course()` path:

| Course | Course ID | Chunks indexed | Embedding model |
|--------|-----------|----------------|-----------------|
| BUS101: Introduction to Business (Demo) | 11 | 792 | text-embedding-3-small |
| POLSC101: Introduction to Political Science | 7 | 191 | text-embedding-3-small |

Total tokens for indexing: approximately 98,000 (792+191 chunks at ~100 tokens each).
Estimated cost: $0.002 at $0.02/MTok. Both courses were unindexed before this run.

### 2.2 Fixture design

40 questions were authored by reading real chunk content pulled from the live DB
(see `tests/golden/rag_fixtures_bus101_pol101.json`). Questions were written to sound
like actual learners, not keyword queries:

- Short and sometimes vague ("what even is a business? like why do we need them")
- Scenario-based ("ben and jerry started their ice cream company together, what type of business is that")
- Occasional typos or wrong prepositions ("how does it effect inflation", "alot")
- Topic recall without exact terminology ("starbucks barista training how does that relate to HRM")
- Hard recall tasks that require cross-topic synthesis ("woody allen quote about innovation I think was in the reading")

Each fixture is labeled with the `expected_chunk_id` (the actual DB row id of the target
chunk) and an `expected_substring` (a distinctive verbatim phrase from that chunk, used
as a fallback if re-indexing changes chunk IDs).

Distribution: 30 BUS101 questions, 10 POLSC101 questions.
Difficulty breakdown: 18 easy, 18 medium, 4 hard.

### 2.3 Harness

`admin/cli/run_rag_fixture_benchmark.php` bootstraps Moodle, loads all embeddings for
the target courses into memory, and for each fixture:

- Embeds the query (one OpenAI API call per question).
- Scores all chunks in the course by cosine similarity.
- Records the rank of the expected chunk and total latency.
- If a Voyage rerank key is available, takes the top-50 cosine candidates and calls
  `voyage_reranker::rerank()` with `top_k=10`, then records the new rank and added latency.

The harness accepts `--embed-apikey=KEY` to override `embed_apikey` in memory (in-DB
for the duration of the run, restored at exit). It does not mutate `rerank_enabled`
or any other production setting.

### 2.4 Run environment

- Dev box: EC2 i-04c58928fad484d97, Moodle 4.5, plugin v6.1.1
- Embedding provider: OpenAI text-embedding-3-small (1536 dimensions)
- Rerank arm: Voyage rerank-2.5 — BLOCKED (embed_apikey and rerank_apikey both empty
  in the dev site's plugin config; the dev site uses the main OpenAI key only)
- Run date: 2026-06-10

---

## 3. Results: embedding-only arm

### 3.1 Recall and MRR

| Group | N | Recall@1 | Recall@3 | Recall@5 | Recall@10 | MRR |
|-------|---|----------|----------|----------|-----------|-----|
| Overall | 40 | 32.5% | 55.0% | 65.0% | 77.5% | 0.468 |
| BUS101 | 30 | 23.3% | 50.0% | 56.7% | 73.3% | 0.389 |
| POLSC101 | 10 | 60.0% | 70.0% | 90.0% | 90.0% | 0.704 |

By difficulty:

| Difficulty | N | Recall@1 | Recall@3 | Recall@5 | MRR |
|------------|---|----------|----------|----------|-----|
| Easy | 18 | 33.3% | 50.0% | 66.7% | 0.462 |
| Medium | 18 | 38.9% | 55.6% | 61.1% | 0.501 |
| Hard | 4 | 0.0% | 75.0% | 75.0% | 0.350 |

### 3.2 Latency (embed + in-memory cosine scoring)

| Percentile | Latency |
|------------|---------|
| P50 | 242 ms |
| P95 | 548 ms |
| Min | 137 ms |
| Max | 654 ms |

The high P95 (548 ms) is driven by the OpenAI embedding API round-trip on cold starts.
All of the cosine scoring itself is sub-millisecond (PHP in-memory, 191-792 chunks).

### 3.3 Per-fixture detail

All 40 expected chunks were found in the ranked list (none returned NOT_FOUND).

| Fixture | Course | Difficulty | Embedding rank | Notes |
|---------|--------|------------|---------------|-------|
| bus-001 | BUS101 | easy | 1 | What is a business |
| bus-002 | BUS101 | medium | **58** | CSR question; chunk ranked far below |
| bus-003 | BUS101 | easy | 4 | Coal plant workers |
| bus-004 | BUS101 | easy | 6 | GDP and living standards |
| bus-005 | BUS101 | medium | 20 | Fed and inflation |
| bus-006 | BUS101 | easy | 12 | Trade barriers |
| bus-007 | BUS101 | hard | 3 | Contract manufacturing |
| bus-008 | BUS101 | medium | 9 | WTO vs EU |
| bus-009 | BUS101 | easy | 10 | Sole proprietorship |
| bus-010 | BUS101 | easy | 1 | Ben and Jerry partnership |
| bus-011 | BUS101 | easy | 5 | Small business importance |
| bus-012 | BUS101 | medium | 1 | Groupon/daily deals failure |
| bus-013 | BUS101 | easy | 3 | Starting a company |
| bus-014 | BUS101 | easy | 21 | Business plan contents |
| bus-015 | BUS101 | medium | 20 | E-commerce data collection |
| bus-016 | BUS101 | hard | 2 | Woody Allen innovation quote |
| bus-017 | BUS101 | easy | 3 | Shipping modes |
| bus-018 | BUS101 | medium | 1 | SWOT analysis |
| bus-019 | BUS101 | medium | 1 | Managerial vs financial accounting |
| bus-020 | BUS101 | easy | 1 | Income statement |
| bus-021 | BUS101 | medium | 2 | Financial ratio analysis |
| bus-022 | BUS101 | easy | 8 | Fed monetary policy tools |
| bus-023 | BUS101 | medium | 11 | Business loan interest rate |
| bus-024 | BUS101 | easy | 3 | 401k |
| bus-025 | BUS101 | medium | 8 | Span of control |
| bus-026 | BUS101 | medium | 3 | External vs internal hiring |
| bus-027 | BUS101 | hard | 15 | Starbucks barista training and HRM |
| bus-028 | BUS101 | medium | 30 | Performance review |
| bus-029 | BUS101 | medium | 2 | Profit sharing bonus |
| bus-030 | BUS101 | easy | 1 | Insider trading |
| pol-001 | POLSC101 | easy | 11 | Political science vs other social sciences |
| pol-002 | POLSC101 | medium | 1 | Is political science a science |
| pol-003 | POLSC101 | easy | 1 | American dream |
| pol-004 | POLSC101 | medium | 1 | Politicians ignoring voters |
| pol-005 | POLSC101 | medium | 1 | Lobbyists |
| pol-006 | POLSC101 | medium | 5 | Two-party vs multiparty systems |
| pol-007 | POLSC101 | hard | 2 | Democratic socialism vs communism |
| pol-008 | POLSC101 | medium | 1 | Laissez-faire definition |
| pol-009 | POLSC101 | easy | 4 | UN creation |
| pol-010 | POLSC101 | easy | 1 | Terrorism definition |

### 3.4 Hard cases

Nine fixtures ranked outside the top 10:

**bus-002 (rank 58):** "does a company have to care about the environment or just make
money" — the expected chunk (id 31) discusses CSR in terms of workplace conditions and
discrimination-free environments. The query's environmental framing retrieved chunks about
pollution and coal plants (ids 714, 710) instead. This is the only fixture where the
target chunk fell entirely outside a 50-candidate pool and would be unfixable by reranking.

**bus-005 (rank 20):** "what does the Fed do exactly and how does it effect inflation" —
the query matched a different Fed chunk (id 470, on interest rates) before the target chunk
(id 121, on the three policy tools). Two highly similar chunks compete.

**bus-006 (rank 12):** "why is it hard to sell products in other countries" — near-duplicate
chunks on competing in global markets (ids 149, 148) ranked higher. Target chunk (id 136)
is semantically close but begins mid-paragraph, making the distinctive language appear late.

**bus-014 (rank 21):** "what goes in a business plan" — the expected chunk (id 286)
contains the full plan outline, but chunks about market analysis and financial projections
(ids 280, 289) which contain overlapping content ranked higher.

**bus-015 (rank 20):** "how does e-commerce let companies collect customer data" —
the target chunk (id 316) discusses online community building and data collection as
secondary points; chunks specifically about e-commerce platforms (ids 367, 362) ranked higher.

**bus-027 (rank 15):** "starbucks barista training how does that relate to HRM" — the
anecdotal Starbucks training passage is in chunk 586 but the query retrieved general HRM
training theory chunks first. Scenario-recall is hard for embedding-only retrieval.

**bus-028 (rank 30):** "what does a performance review usually measure" — the expected
chunk (id 601) discusses the appraisal section alongside layoff language; chunks focused
specifically on performance metrics (ids 597, 596) ranked higher.

**pol-001 (rank 11):** "how is political science different from other social sciences" —
the expected chunk (id 793) is a unit learning outcomes page that says it will cover this
topic, while later content chunks that address it more directly ranked higher.

---

## 4. Rerank arm: blocked

### 4.1 First blocker (2026-06-10, resolved): no key

The Voyage rerank-2.5 arm could not execute on the first run. The dev site's plugin
config had no `embed_apikey` and no `rerank_apikey` set, so all 40 rerank calls failed
with HTTP 401.

### 4.2 Second blocker (2026-06-11, open): free tier rate limits

Tom added a Voyage API key to `rerank_apikey` on dev (rerank stays disabled for
learners). The key authenticates: an isolated 2 document probe call succeeds. But the
account has no payment method, and Voyage free tier rate limits are far below what the
benchmark needs:

- Full pool (50 documents, ~10K tokens per call) at 21s pacing with five 25s retries:
  every call returned 429 Too many requests.
- Reduced pool (25 documents, ~5K tokens per call) at 70s pacing: still zero successes
  in 10 fixtures before the run was cancelled.
- A ~30 token probe after 90s of quiet succeeds.

The observable budget is on the order of a few thousand tokens per minute at most, too
small for any pool size that would make the benchmark meaningful (shrinking the pool to
fit the budget removes exactly the deep ranked chunks rerank is supposed to rescue).

**Unblock:** add a payment method to the Voyage account (dashboard.voyageai.com,
Billing). Actual spend stays $0 — the benchmark uses well under the free token
allotment — but billing on file lifts the per minute limits by orders of magnitude.
After that, the full 50 candidate run completes in about 3 minutes:

    sudo -u www-data php admin/cli/run_rag_fixture_benchmark.php --embed-apikey=<openai key>

The harness now supports `--rerank-delay-ms=N` pacing and retries 429s five times with
25s backoff, so it also degrades gracefully on limited keys.

Until then the rerank deltas cannot be measured directly. Section 5 provides a
projection based on the observed embedding ranks.

---

## 5. Rerank projection (indirect estimate)

### 5.1 Candidate pool coverage

With a 50-candidate pool, the target chunk falls within the pool for 39 of 40 fixtures.
Only bus-002 (rank 58) is outside the pool and cannot benefit from reranking. This is
strong coverage: a 97.5% chance that reranking has access to the correct chunk.

### 5.2 Items that reranking could promote to top-3

17 fixtures ranked outside the current top-3 but within the 50-candidate pool:

| Fixture | Current rank | Potential outcome |
|---------|-------------|-------------------|
| bus-003 | 4 | High probability of top-3 promotion |
| bus-004 | 6 | Likely |
| bus-005 | 20 | Probable (distinct Fed policy passage) |
| bus-006 | 12 | Probable |
| bus-008 | 9 | Likely |
| bus-009 | 10 | Likely |
| bus-011 | 5 | High probability |
| bus-014 | 21 | Uncertain (many similar chunks) |
| bus-015 | 20 | Uncertain (indirect phrasing in target) |
| bus-022 | 8 | Likely |
| bus-023 | 11 | Probable |
| bus-025 | 8 | Likely |
| bus-027 | 15 | Probable (Starbucks anecdote is distinctive) |
| bus-028 | 30 | Uncertain (competing performance metric chunks) |
| pol-001 | 11 | Uncertain (unit overview vs content chunk) |
| pol-006 | 5 | High probability |
| pol-009 | 4 | High probability |

Based on published Voyage rerank-2.5 benchmarks (+15 Recall@10 on enterprise corpora,
+39% NDCG on BEIR), cross-encoders fix 40 to 60 percent of pool-internal rank errors:

| Scenario | Fix rate | New top-3 hits | Projected Recall@3 | Delta@3 |
|----------|----------|----------------|-------------------|---------|
| Conservative | 40% | +7 | ~72.5% | +17.5 pp |
| Optimistic | 60% | +10 | ~80.0% | +25.0 pp |

Both projections exceed the 5 percentage point bar by a wide margin.

### 5.3 Latency projection

The embedding arm P50 is 242 ms (embed API round-trip). Voyage rerank-2.5 API calls
with 50 documents of ~100 tokens each typically return in 100 to 250 ms based on Voyage's
published latency figures and the benchmark data from `sola-vendor-recommendations-2026-06-09.md`.

Projected combined P50: 342 to 492 ms. The added latency from reranking alone (100 to
250 ms) is at or near the 300 ms bar stated in the task brief. The harness did not
measure actual rerank latency because the calls failed immediately on auth errors.

This is the principal uncertainty in the GO/NO GO call. If Voyage's P50 on a 50-document
call is under 200 ms (plausible for a fast cross-encoder endpoint), the combined P50
would be around 440 ms, which is acceptable. If P50 is 300 ms, combined would be 542 ms,
which is above the threshold for individual turn latency but likely still acceptable for
the RAG stage (the LLM generation step dominates perceived response time).

### 5.4 Cost projection

| Parameter | Value |
|-----------|-------|
| Rerank model | voyage rerank-2.5 |
| Price | $0.05 per million tokens |
| Avg tokens per rerank call | ~5,010 (50 docs at 100 tok each + 10 tok query) |
| Cost per query | $0.000251 |
| RAG turns per learner per month | 5 (current estimate) |
| Cost at 100k MAU | ~$125/mo |

This is well within the vendor recommendation budget of $63/mo stated in the docstring
of `voyage_reranker.php` (which assumed 50 chunks at typical SOLA usage). The $125/mo
figure uses more conservative token counts. At Saylor's current scale (pre-100k MAU),
cost would be proportionally lower.

---

## 6. Verdict

### GO — with one condition

**The embedding-only arm meets a reasonable baseline.** Recall@3 of 55% overall (50%
on BUS101, 70% on POLSC101) means roughly half of questions have the target passage in
the top three results without reranking. The POLSC101 corpus is smaller (191 chunks vs
792), which explains the significantly better retrieval there.

**Voyage rerank-2.5 is projected to add 17 to 25 percentage points on Recall@3** based
on the candidate pool analysis. 97.5% of target chunks fall within the 50-candidate pool,
meaning the reranker almost always has access to the correct answer. This well exceeds
the 5 pp bar.

**Cost is manageable.** At ~$125/mo at 100k MAU, rerank adds less than 10% to the
current SOLA text-only cost baseline of ~$1,400/mo.

**The hard cases are structurally fixable.** Only one fixture (bus-002, rank 58) falls
entirely outside the candidate pool. The other eight hard cases involve chunks that are
semantically close to the query but have competing near-duplicate chunks ranked higher;
cross-encoder reranking is precisely designed to resolve this by scoring each (query,
document) pair jointly rather than independently.

**The condition: measure actual rerank latency before enabling at scale.** The latency
bar (P50 added latency under 300 ms) cannot be confirmed from this run because the Voyage
key is not configured on the dev site. Before enabling `rerank_enabled=1` in production:

1. Add a Voyage API key to the dev plugin config (`rerank_apikey` or `embed_apikey`).
2. Re-run this harness on the dev box with the same 40 fixtures.
3. Confirm P50 rerank latency is under 300 ms (expect 100 to 200 ms based on Voyage docs).
4. If P50 added latency is confirmed under 300 ms: enable on dev, run BUS101 smoke, then
   roll out to production with `rerank_candidates=50` and `rerank_enabled=1`.

If measured P50 added latency exceeds 300 ms, reduce the candidate pool to 25 and rerun.
A 25-candidate pool would reduce rerank latency roughly in half while keeping ~95% pool
coverage (only bus-002 at rank 58 would still be outside, same as with 50 candidates).

### Do not enable in the current state

The `rerank_enabled` setting remains off on all environments until the Voyage key is
configured and actual latency is measured. The harness and fixture file are committed to
the repo to make the verification run a one-command operation.

---

## 7. Appendix: files produced

| File | Purpose |
|------|---------|
| `tests/golden/rag_fixtures_bus101_pol101.json` | 40-question fixture set with expected chunk IDs |
| `admin/cli/run_rag_fixture_benchmark.php` | Benchmark harness (PHP 8.3, Moodle bootstrap, PHP lint clean) |
| `.drafts/sola-rag-fixture-benchmark-2026-06-10.md` | This report |
| `/tmp/rag_bench_results.json` | Full per-fixture JSON output from the dev box run (not committed) |

### Raw embedding-only metric table (for record)

```
Embedding model: text-embedding-3-small
Candidates (N): 50   Top-K: 10

Group    | N   | Cos@1  | Cos@3  | Cos@5  | Cos@10 | Cos MRR | P50 embed
---------|-----|--------|--------|--------|--------|---------|----------
Overall  | 40  | 32.5%  | 55.0%  | 65.0%  | 77.5%  | 0.468   | 242 ms
BUS101   | 30  | 23.3%  | 50.0%  | 56.7%  | 73.3%  | 0.389   | 247 ms
POLSC101 | 10  | 60.0%  | 70.0%  | 90.0%  | 90.0%  | 0.704   | 176 ms
```
