# SOLA volumes, language mix, and rerank token size — measured from production

**Date:** 2026-08-21
**Method:** aggregate SQL against both production databases (`learn.saylor.org`,
`degrees.sayor.org`) through the Redash query runner. Counts and aggregates only;
no message body was returned at any point. The Redash credential stayed on the
host and was never printed.

---

## 1. Language mix: overwhelmingly English, and the 46-language capability is not being exercised

| Signal | Learn | Degrees | Combined |
|---|---|---|---|
| Learner messages measured | 13,659 | 1,676 | **15,335** |
| Interface language preference = English (`en_us`/`en`) | 3,071 of 3,071 users | 311 of 320 users | **99.7% of users** |
| Non-English interface preference | none | 8 Arabic, 1 Vietnamese | 9 users |
| Messages containing a **non-Latin script** | 401 (2.94%) | 24 (1.43%) | **425 (2.77%)** |
| — of which Arabic | 381 | 23 | 404 |
| — Cyrillic / CJK / Devanagari | 9 / 8 / 3 | 0 / 1 / 0 | 21 total |
| Messages containing **any** non-ASCII character | 1,294 (9.47%) | 55 (3.28%) | **1,349 (8.80%)** |

**The honest answer is a range, not a number: English is between 91.2% and 97.2%
of traffic.**

- The **97.2%** end is the hard floor on non-English: only 2.77% of messages
  contain a character from a non-Latin script, and Arabic is 95% of that.
- The **91.2%** end treats every message containing any non-ASCII character as
  potentially non-English. That is certainly too pessimistic, because the same
  test fires on curly quotes pasted from course material, accented names, degree
  signs and mathematical symbols.
- **What cannot be measured this way at all:** Latin-script foreign languages.
  Spanish, French, Portuguese and German are indistinguishable from English by
  character class, and the plugin does not persist the language it detects — the
  `_msgs` table has no language column. Detection happens at runtime and is
  thrown away.

**Two things follow for the vendor conversation.**

1. Today the multilingual capability is latent, not load-bearing. SOLA supports 46
   languages and essentially nobody is using them: 9 of 3,391 learners have set a
   non-English interface language. So a multilingual embedding model is insurance,
   not a current requirement — do not pay a premium for it as though it were
   already in production.
2. Arabic is the one real non-English signal, at 2.8% of Learn traffic, and it is
   coming from learners who have *not* changed their interface language. If
   multilingual retrieval quality is worth probing with Voyage, Arabic is the
   language to ask about.

**Recommended follow-up on our side:** persist the detected language on the
message row. It is one column, it makes this question answerable exactly rather
than by inference, and without it we will be re-estimating this every time.

---

## 2. Embeddings volume

### The distinction that matters commercially

`interaction_type='embedding'` counts **two different things**, and conflating
them overstates query volume by roughly 4x. Splitting by token size:

| Band | Learn calls | Degrees calls | What it is |
|---|---|---|---|
| ≤60 tokens | 31,651 | 5,399 | query-side embeddings (mean 19.2 / 25.3 tokens) |
| 61–200 | 16,251 | 6,519 | mixed |
| 201–800 | 27,664 | 28,420 | document chunks (mean ~480) |
| >800 | 14,438 | 6,438 | bulk indexing batches (mean 1,946 / 1,554) |

The April 2026 spike proves the point: 42,268 "embedding" calls on Learn in a month
with **88** learner turns. That was the initial corpus index, not traffic.

### August 2026 — actual

| | Learn | Degrees | Combined |
|---|---|---|---|
| Learner turns | 6,566 | 1,042 | **7,608 / month** |
| Query embeddings | 14,596 | 2,632 | **17,228 / month = 574 / day** |
| Document embeddings (ongoing re-index) | 4,398 | 737 | 5,135 / month |
| Reranks | 231 | 1 | 232 |

### Documents searched — the number you asked for, with a caveat attached

Computed exactly, by joining every learner turn to the chunk count of the course
it happened in:

| | Learn | Degrees | Combined |
|---|---|---|---|
| Mean documents compared per query | 188 | 459 | — |
| **Documents compared, August** | 1,232,282 | 477,912 | **1,710,194 / month** |
| **Per day** | ~41,000 | ~16,000 | **~57,000 / day** |

**Do not quote this as a Voyage cost driver.** Retrieval is scoped to one course,
and the cosine comparison across that course's vectors happens locally in PHP
against the stored index. Voyage never sees it. What Voyage bills is the **17,228
query embeddings** (about 331K tokens/month) plus document tokens at index time.
The 57,000/day figure is an infrastructure and latency number; the billable
number is three orders of magnitude smaller.

### The index itself

| | Learn | Degrees | Total |
|---|---|---|---|
| Chunks (documents) | 46,757 | 39,449 | **86,206** |
| Courses indexed | 189 | 77 | **266** |
| Courses with live chat traffic | 32 | 4 | **36 (14%)** |
| Mean chunk size | 3,917 chars | 2,966 chars | — |
| Corpus | 183.1M chars | 117.0M chars | **300.1M chars ≈ 75M tokens** |

### Full capacity

"Full capacity" here means every already-indexed course seeing traffic at the
intensity its active peers see today — Learn 205 turns/course/month, Degrees 260.

| | Per month | Per day |
|---|---|---|
| Learner turns | 58,839 | ~1,960 |
| Query embeddings | 133,238 | ~4,441 |
| Documents compared | 16,497,574 | ~550,000 |

That 133,238 lands within 5% of the 08-01 cost model's independent 25,000-user
projection of 126,700 RAG queries/month, which is a useful cross-check on both.

**Embedding spend at that volume is negligible in every scenario:**

| Scenario | OpenAI 3-small | voyage-3.5 | voyage-3.5-lite |
|---|---|---|---|
| Today | $0.08/mo | $0.24/mo | $0.08/mo |
| All courses active | $0.61/mo | $1.82/mo | $0.61/mo |
| One-time full reindex (75M tok) | $1.50 | $4.50 | $1.50 |

---

## 3. Reranking: production tokens are 41% above the benchmark

**This is the most consequential number in this document, and it corrects our own
cost model.**

Reranking was live in production from **23 June to 4 August 2026** and is now off
(`rerank_enabled=0` on both sites). That window left 1,359 real rerank calls:

| | Learn | Degrees | Weighted |
|---|---|---|---|
| Rerank calls | 1,250 | 109 | 1,359 |
| **Tokens per rerank** | **21,143** | **20,901** | **21,124** |
| Min / max | 54 / 258,449 | 111 / 47,837 | — |

The 2026-08-01 and 2026-08-21 benchmarks both measured **15,018** tokens per
rerank at `rerank_candidates=20`. Production is **41% higher**, and the reason is
straightforward: prod chunks average 3,917 characters on Learn against the
2,808-character dev corpus the fixtures run on. Same pool size, bigger documents,
more tokens.

**Corrected rerank cost, at $0.05/MTok:**

| Volume | Queries/month | Tokens | Cost |
|---|---|---|---|
| Today's actual | 17,228 | 0.36 GTok | **$18.20/mo** |
| All indexed courses active | 133,238 | 2.81 GTok | **$140.72/mo** |
| 08-01 model, 25,000 users | 126,700 | 2.68 GTok | **$133.82/mo** |

**Cost per 1,000 queries: $1.06, not the $0.75 the benchmark reported.** And the
"always-on rerank costs 40.6% of chat spend" figure becomes roughly **57%** at
production chunk sizes.

This makes the case for the embedding switch stronger rather than weaker: the
cheap intervention is unchanged at pennies per month, and the expensive one is
41% more expensive than we told ourselves.

---

## 4. Two corrections to what I previously reported

**`rerank_candidates` in production is 20, not 50.** Both sites read
`rerank_candidates=20`, `rerank_enabled=0`, `rag_min_similarity=0.25`,
`embed_provider=openai`, `text-embedding-3-small`, 1536 dimensions. The meeting
brief listed "prod is still at 50, which costs 2.5x pool 20 for no gain" as an
open item in two places. **That was wrong and there is nothing to fix.** The
figure came from an earlier document and I repeated it without checking it against
production.

**`interaction_type` is not 100% `chat`.** I reported that production contained
only `chat` rows. It does not: Learn has 89,995 `embedding` and 1,250 `rerank`
rows, Degrees 46,776 and 109. That claim came from a query restricted to
`role='user'` rows, where `chat` is the only value by construction — the telemetry
rows have no role. The part that does hold: there are still **zero `quiz` and zero
`voice`** rows on either site, despite both features shipping.

---

## 5. One unexplained finding worth a look

**We issue 2.26 query embeddings per learner turn** — 17,228 against 7,608 turns
in August, and the ratio is stable across July (2.22) and June (1.73). Candidates:
a second retrieval pass per turn, a retry path, query expansion, or short document
chunks landing in the ≤60-token band and being miscounted as queries.

It is not a cost problem — the entire embedding line is under a dollar a month —
but if it is a genuine second retrieval per turn, it is latency the learner pays
on every message, and it would double whatever we eventually pay for embeddings.
Worth confirming before quoting per-query volumes to a vendor.

---

## 6. Numbers to take into the Voyage meeting

- **Query embeddings: 17,228/month today, 133,238/month at full capacity** — about
  331K and 2.6M tokens respectively. Tiny.
- **Corpus: 86,206 chunks, 266 courses, ~75M tokens.** One-time reindex $4.50 on
  voyage-3.5, $1.50 on lite.
- **Rerank: 21,124 tokens per query, measured in production.** $1.06 per 1,000
  queries, $140/month at full capacity. This is the line that actually costs money.
- **Language: 91–97% English.** The 46-language support is not currently exercised;
  Arabic at ~2.8% of Learn traffic is the only real non-English signal.
- **Do not quote "57,000 documents searched per day"** as a Voyage volume. It is
  local cosine work against the stored index and they never see it.
