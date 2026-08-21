# SOLA production query characterization + synthetic benchmark set

**Date:** 2026-08-21
**Author:** automated characterization run
**Purpose:** describe what learners actually send SOLA in production, so the
RAG/reranker benchmark can be run on queries that look like production traffic
rather than hand-written ideal questions. Feeds the Voyage AI vendor discussion.

---

## 0. Privacy statement (read first)

**No learner-authored text was read, copied, or exported at any point.**

The entire characterization was produced by aggregate SQL executed against the
production databases: every metric below is a `COUNT`, `SUM(<predicate>)`,
`AVG`, or `PERCENT_RANK` computed server-side. No `SELECT message` was ever
issued against a production table, so no message body was returned to the
analysis environment. Consequently there is no corpus of real learner text that
*could* have leaked into the fixture.

The fixture's semantic content is inherited from
`tests/golden/rag_fixtures_conv_1k_recall.json`, which was LLM-generated from
indexed **course content** chunks (not learner messages). Only the *surface
form* of those questions was re-realized to match the production statistics.

No learner names, emails, user ids, course free text, or conversation ids appear
in the fixture or in this document. Verification results are in section 7.

---

## 1. Data sources — what was and was not accessible

| Source | Result |
|---|---|
| **Prod: `learn.saylor.org`** (`saylor_mdl_prod`) | **USED.** 13,524 learner messages. Reached read-only via the Redash prod query runner (data source `learn.saylor.org`, id 2) over SSM on `redash-prod-02`. Aggregate SQL only. |
| **Prod: Degrees** (`saylor_degrees_prod`) | **USED.** 1,664 learner messages. Same host and data source. |
| `redash_export.php` on dev | **Not used as a shape source.** It serves the *dev* database, and dev has no organic traffic (below). It also refuses de-anonymized output unless `redash_allow_deanonymized` is set — that setting was **not** touched. |
| **Dev fleet DB** (dev / dev405 / dev500 / dev501 / dev503) | **REJECTED as a shape source.** 130 user messages total across all five sites, 19 distinct users. 79% of the rows on `dev` are exact duplicates of one another, drawn from a ~25-question canned pool in `classes/demo_seeder.php`. This is seeded demo data, not learner behavior; characterizing it would have produced a fabricated distribution. |

**Limits of what was measured**

- Only `role='user'` rows were counted. Assistant turns, system/telemetry rows
  (`[PremiumRouter]`, `[Rerank]`, `[Embedding]`) were excluded.
- **Typo rate could not be measured properly.** Detecting genuine misspellings
  requires reading the text. The proxies used are structural: missing
  apostrophes and chat shorthand (`dont`, `whats`, `u`, `pls`, `idk`, ...).
  True typo rate is unknown and is likely **higher** than the 1.8% shorthand figure.
- **Language mix is under-measured.** Script detection catches non-Latin scripts
  and accented Latin, but an English-script query written in, say, Indonesian or
  Swahili is invisible to it. Treat the language numbers as a floor.
- Two first-pass measurements were **discarded as invalid**: a `^[a-z]`
  case test and a `[à-ÿ]` accent test, both of which silently matched
  everything under MySQL's case-insensitive collation. Both were re-run with
  `COLLATE utf8mb4_bin`; the corrected numbers are the ones reported. A
  `typographic` (curly quote / en dash) probe was also discarded — MySQL does
  not honor `\uXXXX` escapes in string literals, so that regex was meaningless.
  It is **not** reported.
- Category predicates were evaluated against `LEFT(LOWER(message),200)` to stay
  inside MySQL's `regexp_time_limit`. Signals occurring only past character 200
  of a long message are missed.
- Percentages below are over all user messages on that site, so overlapping
  categories do not sum to 100%.

---

## 2. Volume and window

| | Learn | Degrees |
|---|---|---|
| Learner messages (`role='user'`) | **13,524** | **1,664** |
| Distinct learners | 3,040 | 316 |
| Distinct courses | 32 | 4 |
| Conversations | 3,141 | 325 |
| First → last message | 2026-04-14 → 2026-08-21 | 2026-06-23 → 2026-08-21 |
| Distinct message texts | 9,562 (70.7%) | 963 (57.9%) |
| `interaction_type` | 100% `chat` | 100% `chat` |

Learn is the primary reference corpus (8× the volume, 8× the courses). Degrees
agrees with Learn on every shape metric within a few points, which is
reassuring — the distribution is a property of learner behavior, not of one site.

**Note:** `interaction_type` is `chat` for 100% of production rows. There are
zero `quiz` and zero `voice` learner rows in either prod database, despite both
features shipping. Either they are not enabled in prod or their turns are not
being logged with the right type. Worth a separate look; it means this
characterization describes the chat path only.

---

## 3. Length — the headline finding

Production queries are **far shorter** than any existing fixture.

Character length, Learn (n=13,524):

| p10 | p25 | p50 | p75 | p90 | p99 | mean | max |
|---|---|---|---|---|---|---|---|
| 12 | 22 | **39** | 108 | 187 | 947 | 135.6 | 107,544 |

Bucketed (Learn):

| Bucket | Count | Share |
|---|---|---|
| ≤ 20 chars | 3,176 | **23.5%** |
| 21–50 | 4,608 | **34.1%** |
| 51–100 | 1,883 | 13.9% |
| 101–200 | 3,326 | **24.6%** |
| 201–400 | 258 | 1.9% |
| > 400 | 273 | 2.0% |

Word count: mean 22.3; 18.8% are 1–3 words, 35.3% are 4–8 words.

**The distribution is bimodal.** A 23.5% mode of near-keyword queries (≤20
chars — "job satisfaction", "explain this") and a second 24.6% mode of
well-formed multi-clause questions (101–200 chars), with a comparative trough
in between. A benchmark that samples only the second mode — which is what the
current fixtures do — measures roughly a quarter of real traffic and the
easiest quarter at that. Degrees is the same shape (p50 43, 25% ≤20 chars).

The 107KB maximum message is a paste of bulk material, not a typed query; the
p99 of 947 chars is the more useful upper reference.

---

## 4. Form: most queries are not questions

Learn, share of all 13,524 learner messages:

| Signal | Count | Share |
|---|---|---|
| Contains a `?` | 3,939 | **29.1%** |
| No terminal punctuation at all | 8,625 | **63.8%** |
| Starts with an imperative (`explain`, `summarize`, `quiz me`, ...) | 2,830 | **20.9%** |
| Starts with `what` | 925 | 6.8% |
| Starts with `how` | 553 | 4.1% |
| Starts with `can/could/is/does/...` (polar) | 601 | 4.4% |
| Starts with `when/where/who/which` | 252 | 1.9% |
| Starts with `why` | 90 | 0.7% |
| Starts uppercase | 9,279 | 68.6% |
| Starts lowercase | 3,551 | 26.3% |

Interrogative and imperative openers together account for **38.8%**. The
remaining **~61%** are bare noun phrases, topic fragments, sentence fragments, or
declaratives — a learner naming a concept rather than asking about it. Only
29.1% carry a question mark.

This matters for retrieval specifically: an embedding model tuned on
question-shaped queries and an asymmetric query/document `input_type` (which is
how the Voyage integration is configured today) is being fed keyword-shaped
input 60% of the time.

---

## 5. Conversational context, topic drift, and content type

**Follow-ups dominate.** Learn: 13,528 user messages across 3,141 conversations.

| Conversation length (user turns) | Conversations |
|---|---|
| 1 | 1,068 (34.0%) |
| 2–3 | 1,054 (33.6%) |
| 4–6 | 532 (16.9%) |
| 7–15 | 335 (10.7%) |
| 16+ | 152 (4.8%) |

**76.8% of all learner messages are turn 2 or later** — they arrive with prior
context the retriever does not see. Consistent with that, 21.6% contain a bare
deictic reference (`this`, `that`, `it`, `these`, `above`, `you said`) and 6.1%
*open* with a continuation token (`ok so`, `and`, `also`, `what about`).

**Content type — the surprising part:**

| Signal | Learn count | Share |
|---|---|---|
| Fenced code block (```` ``` ````) | **0** | **0.00%** |
| LaTeX (`$$`, `\frac`, `\int`, `\sum`, `\begin{`) | **0** | **0.00%** |
| Arithmetic expression (`12 * 3 =`) | 46 | 0.34% |
| Math vocabulary (`calculate`, `derivative`, `integral`, `formula`, ...) | 58 | 0.43% |
| Code-like tokens (`def `, `import `, `print(`, `select ... from`) | 15 | 0.11% |
| Vague / non-specific ("i dont get", "im lost", "confused", "idk") | 99 | 0.73% |
| Emotional (stress, anxiety, overwhelm, frustration, failing) | 13 | 0.10% |
| Meta / off-topic ("are you an AI", "your name", weather, jokes) | 8 | 0.06% |
| Greeting-opener (`hi`, `hello`, `thanks`, ...) | 382 | 2.8% |
| Chat shorthand / missing apostrophe | 249 | 1.8% |

Two things stand out.

1. **The premium-escalation router's STEM markers essentially never fire.**
   `classes/premium_router.php` ships default triggers built around fenced code
   blocks, LaTeX math, big-O, integrals, and thermodynamics. Across 13,524 real
   learner messages there are **zero** code fences and **zero** LaTeX
   sequences, and arithmetic appears in 0.34%. Whatever the router would cost at
   scale, on this traffic mix it would route almost nothing. (It is off by
   default, so this is not a live cost issue — but the 5%-escalation assumption
   behind the ~$700/mo-at-100k-MAU estimate is not supported by Saylor's own
   traffic.)
2. **Emotional and off-topic traffic is negligible** (0.10% and 0.06%). Any
   benchmark or guardrail work weighted toward "learner is venting" or
   "learner is testing the bot" is sized against roughly 1 message in 1,000.

**Language mix** (script detection, Learn; floor not ceiling):

| | Count | Share |
|---|---|---|
| Any non-ASCII character | 1,283 | 9.5% |
| Arabic script | **370** | **2.7%** |
| Accented Latin (Spanish/Portuguese/French-like) | 579 | 4.3% |
| Cyrillic | 9 | 0.07% |
| CJK | 8 | 0.06% |
| Devanagari | 3 | 0.02% |

Arabic is the largest non-Latin script in production traffic. Degrees is much
more monolingual (3.2% non-ASCII, 23 Arabic).

---

## 6. The synthetic fixture

**Path:** `tests/golden/rag_fixtures_prodshape_2026-08-21.json`
**Count:** **1,008 queries** across the 16 courses that have a dense RAG index
on dev, each with a unique ground-truth chunk id.

**Construction.** Semantic content and ground truth are inherited unchanged from
`rag_fixtures_conv_1k_recall.json` (LLM-generated from indexed course chunks —
course content, never learner text), so `expected_chunk_id` remains valid. Only
the surface form is re-realized, per-item, to reproduce the section 3–5
statistics: six length realizations (keyword / short phrase / terse question /
full question / verbose / rambling) allocated to the measured length buckets,
then overlays applied against measured budgets (imperative opener, deictic
opener and deictic reference, greeting prefix, vague phrasing, shorthand,
Arabic and Spanish code-switch prefixes wrapping the English course term, and
the rare arithmetic/pseudocode ask). Question marks are stripped and re-added to
exactly 29.1% of items. Generation is seeded (`20260821`) and reproducible.

Short realizations are built by extracting the most distinctive (highest-IDF)
content phrase from the seed question, so a 15-character keyword query still
points at its ground-truth chunk — just far more weakly, which is exactly the
regime where a reranker should earn its cost.

**Fit against production:**

| Metric | Prod (Learn) | prodshape | conv_1k_recall |
|---|---|---|---|
| n | 13,524 | 1,008 | 1,008 |
| p25 chars | 22 | **24** | 129 |
| p50 chars | **39** | **45** | 149 |
| p75 chars | 108 | **123** | 177 |
| p90 chars | 187 | **187** | 204 |
| ≤20 chars | 23.5% | **20.4%** | 0.0% |
| 21–50 | 34.1% | **37.1%** | 0.0% |
| 51–100 | 13.9% | **14.0%** | 3.3% |
| 101–200 | 24.6% | **24.6%** | 85.1% |
| 201–400 | 1.9% | **2.0%** | 11.6% |
| >400 | 2.0% | **1.9%** | 0.0% |
| Has `?` | 29.1% | **29.1%** | 100.0% |
| No terminal punctuation | 63.8% | **70.9%** | 0.0% |
| Non-ASCII | 9.5% | 7.5% | 4.8% |

**Known gaps — state these to the vendor.**

1. **Multi-turn context is not reproduced.** 76.8% of production messages are
   follow-ups that depend on prior turns. Each fixture here is a standalone
   query; deictic openers and references are present at the measured *rates*,
   but there is no conversation history behind them, so a fixture reading
   "and how does that connect to the rest of the unit" is under-specified in a
   way the real turn was not. This is the single largest remaining divergence,
   and it biases measured recall **upward** relative to production.
2. **Language coverage is approximated by code-switching**, not translation. The
   Arabic and Spanish items are a non-English request wrapper around the English
   course term (a real and common pattern) rather than fully translated queries.
   A genuine multilingual retrieval test needs translated queries with the same
   ground truth.
3. **Real typo/misspelling noise is absent.** Only structural shorthand is
   modeled, because the true typo rate could not be measured without reading
   learner text.
4. Semantic content still traces to 16 courses on dev, so topic coverage is that
   of the dev RAG index, not of the 32 courses live on Learn.
5. The long realizations draw their filler preamble from a pool of only six
   phrasings, so the 39 items over 200 characters (3.9% of the set, matching
   production's 3.9%) share prefixes. Fine for a recall measurement; do not
   read anything into per-item results in that bucket.

---

## 7. Verification performed

- **Harness compatibility — PASS.** The file matches the
  `{"fixtures":[{id, courseid, course, question, expected_chunk_id,
  expected_substring, difficulty, notes}]}` schema read by
  `admin/cli/run_rag_fixture_benchmark.php`. The full 1,008-item file was
  uploaded to dev and parsed by PHP (`parsed fixtures=1008`), and a 12-item
  subset was run end-to-end through the harness with `--topk=3 --candidates=10`:
  it loaded, ran both the embed-only and rerank arms, and printed a complete
  results table. `difficulty` carries the realization name so recall can be
  sliced by query form.
- **Schema — PASS.** All 1,008 items have identical key sets, integer
  `courseid` and `expected_chunk_id`, and a non-empty `question`. 1,008 distinct
  ground-truth chunk ids; 1,006 distinct question strings.
- **PII — PASS.** Zero `@` characters, zero email-like strings, zero URLs, zero
  `userid`/`user_id` keys anywhere in the file. The only long digit runs are
  chunk ids inside the `id` field.
- **Verbatim leakage — PASS.** Structurally, no production message body was
  ever read, so there was nothing to copy. Empirically, all 1,008 fixture
  questions were tested for **exact equality** against every learner message in
  both production databases (18 batched queries): **0 matches out of 1,008,
  against 15,188 production messages.**

  A second, deliberately over-sensitive probe tested 120 randomly-chosen 6-word
  shingles from the fixture for *containment* anywhere in a production message.
  **119 of 120 returned zero.** One matched, on one message per site. The
  matching string was `subject line?` — a two-word keyword-realization fixture
  short enough that the shingle builder fell back to using the whole query, so
  the probe degenerated into "does any learner message anywhere contain the
  phrase 'subject line?'". It does.

  That is coincidental vocabulary overlap, not copied text, and it is
  unavoidable by construction: 20% of this fixture is 1–3 word keyword queries
  drawn from the same course vocabulary learners use, so short substrings *will*
  collide. The meaningful test is whole-message equality, which is 0/1,008.
  No learner phrasing, and nothing identifying, is reproduced.
- The fixture was also checked against the `classes/demo_seeder.php` canned
  question pool (the source of the dev rows): 170 candidate seeder strings
  extracted, **0 exact matches and 0 six-word shingle matches** against the
  1,008 fixture questions.

---

## 8. Incidental finding: RAG retrieval is currently broken on the dev fleet

Found while validating the fixture, and it blocks the vendor benchmark.

On `dev.sylr.org` the plugin is configured `embed_provider=voyage`,
`embed_model=voyage-3.5`, `embed_dimensions=1024`, but **every stored chunk
vector is 1,536-dimensional** — i.e. the index was built with OpenAI
`text-embedding-3-small` and never reindexed after the provider switch. Query
and document vectors are therefore in different spaces, and cosine similarity
collapses to noise.

Measured on dev: target-chunk cosine has mean **0.0289** (min −0.031, max 0.067)
against a configured `rag_min_similarity` of **0.25**. **12 of 12** target
chunks fall below the production floor, so the retriever returns nothing and the
tutor is answering without retrieval on dev.

**This is not a property of the new fixture.** The existing
`rag_fixtures_conv_1k_recall.json` was run as a control through the identical
command and scored the same: 0.0% recall@1/3/5, mean target cosine 0.0289.
**Any** fixture run on dev today reports 0% recall.

Before the vendor benchmark, either reindex the dev courses under voyage-3.5, or
use the harness's `--embed-provider` A/B mode, which re-embeds chunk contents
per arm in memory and so compares arms like-against-like regardless of what is
stored. The A/B path is the safer choice for a vendor-facing number.

---

## 9. Recommendation: which fixture should the benchmark use?

**Use both, reported separately. Lead with `prodshape`.**

- **`rag_fixtures_prodshape_2026-08-21.json` — the headline number.** It is the
  only fixture whose length, punctuation, question-type and language profile
  match production. Recall measured here is the number that predicts what
  learners will experience, and it is where a cross-encoder reranker should show
  its largest lift: the 20% of items under 20 characters are precisely the
  weak-signal queries a bi-encoder handles worst. Slice the result by the
  `difficulty` field (realization type) — a single aggregate recall over a
  bimodal distribution hides the effect. The per-form breakdown is the most
  useful artifact for the Voyage conversation.
- **`rag_fixtures_conv_1k_recall.json` — keep as the regression baseline.** It
  is not production-shaped (p50 149 chars, 100% question marks, 0% short
  queries; it samples only the upper mode, ~25% of real traffic), so it
  overstates recall. But it is the fixture every prior measurement used,
  including the 2026-06-11 rerank GO decision (recall@3 55% → 72.5%). Dropping
  it breaks comparability with that decision. Run it unchanged so the new
  numbers can be reconciled against the old ones.
- **Do not** use `rag_fixtures_bus101_pol101.json` for this: 40 items, and 0%
  in either the ≤20 or the 101–200 bucket.

Expect `prodshape` recall to come in **materially below** the conv_1k numbers.
That is the point — it is a correction to an optimistic baseline, not a
regression. Any cost/benefit case put to Voyage should be built on the
prodshape figures, and should note gap (1) above: because multi-turn context is
absent, even prodshape is somewhat optimistic relative to live traffic.

### Provenance of the existing `conv_1k` fixtures

`rag_fixtures_conv_1k.json` and `rag_fixtures_conv_1k_recall.json` are dated
`2026-07-30` and were **not** derived from production traffic. They were
generated by `admin/cli/generate_conversational_fixtures.php`, which samples
indexed chunks and asks gpt-4o-mini to write "ONE natural, conversational
question (two or three sentences...)" per chunk. The prompt's own instruction is
what produces the 149-character, always-punctuated, always-interrogative shape.
So this is not distribution *drift* — the set never matched production; it
matched a prompt. Its header comment claims conversational phrasing is "closer
to real learner questions", and against the terse fixture it is; against
measured production traffic it is not.
