# SOLA: what the pilot actually costs, and what rollout costs

**Date:** 2026-09-15
**Supersedes:** section 2 ("What it costs") and recommendations 2 and 3 of
`sola-benchmarks-usage-and-cost-2026-08-07.md`. Everything else in that
document — adoption rates, model choice, retrieval configuration, the 31%
single-turn finding — stands unchanged and is not re-argued here.
**Sources:** AWS CUR (account 806862010366), provider cost APIs, and the plugin's
own `spend_export.php` metering, all via the AI spend dashboard.

---

## Headline

The August document reported SOLA at **about $11 a month** and concluded:

> At real volume the entire AI bill is an order of magnitude below the cost of a
> single working day of staff time. [...] There are no savings of consequence
> available.

Both halves need correcting, in opposite directions.

**SOLA's actual attributed spend is about $830 a month.** Not because the token
model was wrong — it was close on tokens — but because it counted only tokens.

**96% of that is a GPU instance that serves no traffic.** The
`saylor-sola-llm` stack has billed **$3,980 since 2026-04-14** and, as of
2026-09-15, has served zero successful requests in seven days.

So there is a saving of consequence available: **$803 a month, roughly 25x the
entire token bill.** It has nothing to do with models, prompts, retrieval or
adoption, which is precisely why a token-based analysis could not see it.

---

## 1. What the pilot actually costs

Invoice-attributed spend where `project == sola`:

| Month | LLM stack | Whisper box | Gemini | OpenAI | **Total** |
|---|---:|---:|---:|---:|---:|
| 2026-04 | 420.20 | — | — | — | **420.20** |
| 2026-05 | 808.64 | — | — | — | **808.64** |
| 2026-06 | 779.20 | — | 2.97 | — | **782.17** |
| 2026-07 | 803.26 | 5.36 | 12.80 | — | **821.42** |
| 2026-08 | 803.22 | 5.32 | 22.41 | — | **830.95** |
| 2026-09 | 365.67 | 5.20 | 16.75 | 3.05 | **390.66** (to 09-15) |

**Total to date: $4,054.**

Two caveats on that table, both making it an under-count rather than an
over-count:

- **OpenAI appears only from September.** Per-key attribution landed 2026-09-11;
  before that SOLA's OpenAI spend was bucketed as `general` and cannot be
  separated retrospectively.
- **Anthropic is not attributed to SOLA at all.** It bills to `general` and
  `course-builder` workspaces, so SOLA's Claude usage sits outside this table.

### The shape of it

| | $/month | Scales with learners? |
|---|---:|---|
| `saylor-sola-llm` GPU stack | 803.22 | **No** |
| Whisper box (stopped) | 5.32 | **No** |
| Tokens (chat, embed, rerank) | ~21–45 | Yes |

**About 95% of SOLA's cost is fixed and responds to nothing.** It is identical
at zero learners and at a hundred thousand. Every previous projection modelled
only the 5% that moves.

---

## 2. The fixed cost nobody counted

`saylor-sola-llm` is a CloudFormation stack: a **g5.xlarge** GPU instance in an
autoscaling group (min 1) behind an ALB, tagged `Project=SOLA`,
`Environment=sola-llm`, `Component=SelfHostedLLM`.

| | |
|---|---|
| Billing since | 2026-04-14, continuously |
| Cost | $24.14/day instance + ~$1.69/day EBS and ALB ≈ **$803/month** |
| Spent to date | **$3,980.19** |

### It is not serving anything

| Signal | Measurement |
|---|---|
| CPU, 14-day average | **1.34%** (daily max 6–22%) |
| ALB 2XX responses, 7 days | **zero** |
| ALB 4XX responses, 7 days | 7,254 — consistent with internet scanning of a public ALB |
| Average target response time | **0.5 ms** (slowest request all week: 0.44 s) |
| References in the SOLA plugin | **none** |

The 0.5 ms figure is the decisive one. Real inference on a g5 is seconds per
request; half a millisecond is a 404. The plugin's only "selfhosted" settings
are for the Whisper STT server, which is a different, currently stopped box.

It passes its `/healthz` check, reports healthy, and has therefore never
appeared in any alert.

**Shutdown is with David Ta.** Stopping the instance will not hold — the ASG has
`min=1` and will replace it. It needs the ASG scaled to 0 or the stack deleted.

### A measurement trap, recorded because it produced a plausible wrong answer

I first dated this stack from its current instance (`i-05ea32e8f94bb1e4b`,
launched 2026-07-21) and reported the spend as ~$1,365. The ASG replaces
instances: the stack had been billing since April, and the real figure is
$3,980 — **three times** my first answer. Date infrastructure from billed days
in the CUR, never from an instance's `LaunchTime`.

---

## 3. Token cost is higher than reported, and is a floor

The plugin's own metering, by site:

| Month | Learn | Degrees | Total |
|---|---:|---:|---:|
| 2026-07 | 10.71 | 0.66 | **11.38** |
| 2026-08 | 19.47 | 1.60 | **21.07** |
| 2026-09 (to 09-15) | 17.58 | 7.43 | **25.01** |

Two things are visible. Token spend has roughly **doubled since the August
document** — $11.38 in July against $21.07 in August — and September is already
past August's full-month figure at the halfway mark. Usage is growing, which is
the outcome the pilot wanted.

September's Degrees figure carries a one-off: **$5.79 of it is embedding**, a
re-index, against $0.07 in a normal month. Ongoing Degrees is nearer $3/month.

### The metering under-counts, measurably

Where an invoice exists to check against, the plugin under-reports:

| Month | Gemini invoice (SOLA) | Plugin says | Ratio |
|---|---:|---:|---:|
| 2026-07 | 12.80 | 5.00 | **2.56x** |
| 2026-08 | 22.41 | 10.49 | **2.14x** |
| 2026-09 | 16.75 | 9.52 | 1.76x |

The causes are documented: reasoning/thinking tokens were never logged, and
several call paths persisted no usage at all. v7.4.6 fixes both, so reported
figures will rise after that deploy **with no change in actual spend**.

So August's true token cost is not $21.07. Gemini alone was $22.41 on the
invoice. Taking the invoice where we have one and applying the observed ~2x
under-count to the rest gives **$33–45/month** for August. Call it **$45** for
planning, and treat it as a floor until v7.4.6 is deployed and the metering can
be trusted directly.

---

## 4. Rollout projections

Volume model unchanged from the August document — adoption 2.73% (Learn) and
6.70% (Degrees) of active learners who can see SOLA, at ~4.8 and ~5.0 turns per
adopting learner:

| | Learners | Turns/month |
|---|---:|---:|
| Learn today (30 courses) | 1,213 | 5,822 |
| Learn, full catalogue | 1,828 | 8,776 |
| Degrees today (4 courses) | 115 | 573 |
| Degrees, full catalogue | 238 | 1,183 |
| **Today, both** | **1,328** | **6,395** |
| **Full, both** | **2,066** | **9,959** |

Token cost scaled from measured per-turn rates, then held to the same ~2x
under-count correction:

| Scenario | Turns/mo | Tokens | Fixed | **Total/mo** |
|---|---:|---:|---:|---:|
| **A. Today** | 6,395 | ~$45 | $808 | **~$853** |
| **B. All of Degrees** | 7,005 | ~$49 | $808 | **~$857** |
| **C. Learn + Degrees, full** | 9,959 | ~$70 | $808 | **~$878** |
| **D. Full rollout, LLM stack shut down** | 9,959 | ~$70 | $5 | **~$75** |

### What the projections say

**Enabling all of Degrees costs about $4 a month.** Degrees roughly doubles in
volume — 573 turns to 1,183 — and the marginal token cost is under $5. There is
no budget argument against it of any kind.

**Full rollout across Learn and Degrees costs about $25 a month more than
today.** Volume rises 1.56x, because the courses already enabled are the large
ones. This is unchanged in substance from the August document and its
recommendation 3 still holds.

**Shutting down the idle GPU stack saves $803 a month — about 32x the cost of
the entire full-catalogue rollout.** Scenario D costs less than one-eleventh of
scenario A while serving 56% more traffic.

### Per-learner cost

The August document reported **$0.008 per SOLA user per month**. Against total
attributed spend:

| | Users | $/month | Per user |
|---|---:|---:|---:|
| Today, as billed | 1,328 | ~$853 | **$0.64** |
| Full rollout, as billed | 2,066 | ~$878 | **$0.42** |
| Full rollout, stack shut down | 2,066 | ~$75 | **$0.036** |

The $0.008 figure was a per-turn token cost, not a per-learner cost of running
SOLA. As billed today the real figure is **80x** that.

---

## 5. What this changes

1. **Shut down or scale `saylor-sola-llm` to zero.** It is 96% of SOLA's cost
   and has served nothing since at least 2026-09-08. If it is wanted for
   planned work, note that it has cost $3,980 while idle and that an ASG at
   min=0 restores it in minutes.
2. **Enable SOLA on the rest of Degrees.** ~$4/month. Recommendation 3 of the
   August document, reaffirmed with better cost data.
3. **Retire "$11 a month" as the pilot's cost.** It was the chat-token cost and
   was reasonable as that. As a description of what the pilot costs it is out
   by a factor of 75.
4. **Correct the conclusion that no savings are available.** The savings were
   never in the tokens. They were in an idle asset that a token-based analysis
   could not, by construction, detect.
5. **Re-baseline after v7.4.6.** It fixes reasoning-token logging and the
   unlogged call paths, so metering becomes directly usable and the ~2x
   correction applied here can be dropped.
6. **Attribute Anthropic to SOLA.** It is the one provider still invisible in
   the project view, via the same per-key mechanism used for OpenAI.

---

## 6. What this does not establish

- **Whether the LLM stack was ever wired in.** It runs a healthy service on
  port 8080 that nothing calls. Whether that is an abandoned experiment or
  staged-but-unlaunched work is David's to say, not mine.
- **Traffic reaching the instance directly.** My figures come from the load
  balancer. Anything calling the private IP (172.31.35.164) would not appear,
  though 1.34% average CPU argues against meaningful use by any route.
- **SOLA's true Anthropic and pre-September OpenAI spend.** Neither is
  attributable to the project for the pilot period, so section 1's totals are a
  floor.
- **That adoption holds on rollout.** Unchanged from the August document: the
  enabled courses were chosen, not sampled.
- **The per-turn token rates past the current model mix.** They are derived from
  August, the last month with no re-index distortion, and v7.4.6 will move them
  upward on reporting alone.
