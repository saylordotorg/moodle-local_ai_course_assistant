# SOLA A.10 Premium-Escalation Tier Bake-off — 2026-06-09

**Author:** Tom Caswell. **Date:** 2026-06-09. **Run on:** dev.sylr.org (v5.9.0 base + run_tutor_golden.php from v5.11.0-vendor-optimizations branch). **Cost:** ~$2 API spend (~$1.20 run + ~$0.80 judge).

## What was tested

The 2026-06-03/06-04 chat benchmarks settled the **workhorse tier**: Gemini 2.5 Flash for primary chat, gpt-4o-mini for quiz / classifier / analytics, Claude Haiku 4.5 for the anti-cheat lane. A.10 asks the next question: **does SOLA need a premium escalation tier for prompts where the workhorse visibly struggles?** And if so, what model should it route to?

**Contestants** (4): Claude Sonnet 4.6, Claude Opus 4.8, Gemini 2.5 Pro, OpenAI gpt-4o (full, not mini).

**Judge:** OpenAI gpt-4.1 (out of the contestant set; not affected by the gpt-5+ `max_completion_tokens` API change).

**Prompt set** (40, in `tests/golden/tutor_prompts_a10_premium_escalation.json`):
- 20 multi-step-reasoning lifts from the existing `tutor_prompts.json` (all 10 socratic_explanation, 5 illustration, 5 anti_cheat)
- 20 fresh stress prompts: 5 hard_math (related rates, optimization, integration-by-parts, harmonic-series divergence, homogeneous ODE), 5 hard_cs (merge-sort proof, Dijkstra vs Bellman-Ford, TCO, hash-table amortization, correlated-subquery vs JOIN), 5 hard_philosophy (trolley problem, is-ought, tree-falls-in-forest qualia, Rawls's veil, Frankfurt-style compatibilism), 5 hard_science (second-law equivalence, CRISPR mechanism, Bohr vs orbital, Bayesian disease test, friction-area paradox).

**Method:** Each prompt streamed through each contestant via SSM-driven `run_tutor_golden.php --mode=run --prompts=tests/golden/tutor_prompts_a10_premium_escalation.json`. Judge phase scored each response on Socratic guidance (/5), factual accuracy (/5), tone match (/5). 160 chat calls + 160 judge calls, zero errors.

---

## Headline results

| Model | Overall /15 | Socratic /5 | Accuracy /5 | Tone /5 | Cost / call | P50 TTFT | P50 total | Pareto |
|---|---:|---:|---:|---:|---:|---:|---:|:-:|
| **Claude Opus 4.8** | **14.97** | 4.97 | 5.00 | 5.00 | 1.377¢ | 852 ms | 7,385 ms | yes |
| **Gemini 2.5 Pro** | 14.85 | 4.85 | 5.00 | 5.00 | **0.296¢** | 11,539 ms | 13,128 ms | yes |
| Claude Sonnet 4.6 | 14.40 | 4.40 | 5.00 | 5.00 | 0.884¢ | 1,142 ms | 8,827 ms | no |
| OpenAI gpt-4o | 12.68 | 2.70 | 4.97 | 5.00 | 0.437¢ | 417 ms | 3,993 ms | no |

**Pareto frontier**: Opus 4.8 (quality ceiling) and Gemini 2.5 Pro (best price-quality). Sonnet 4.6 and gpt-4o are both dominated.

---

## Per-category breakdown (rubric mean / 15)

| Provider | anti_cheat | hard_cs | hard_math | hard_phil | hard_sci | illustration | socratic | Overall |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| **Opus 4.8** | 14.80 | **15.00** | **15.00** | **15.00** | **15.00** | **15.00** | **15.00** | **14.97** |
| Gemini 2.5 Pro | **15.00** | 14.40 | 15.00 | 14.80 | 15.00 | 14.60 | 15.00 | 14.85 |
| Sonnet 4.6 | 14.80 | 14.40 | 13.80 | 14.80 | 14.20 | 13.40 | 14.90 | 14.40 |
| gpt-4o | 14.20 | 12.00 | 12.00 | 13.40 | 11.80 | 12.60 | 12.70 | 12.68 |

**Gaps between top and bottom per category** — where premium routing pays off most:

| Category | Gap | Implication |
|---|---:|---|
| hard_science | 3.20 | Biggest payoff for premium escalation |
| hard_cs | 3.00 | Same |
| hard_math | 3.00 | Same |
| illustration | 2.40 | Premium helps multi-paragraph worked examples |
| socratic_explanation | 2.30 | Premium helps deep concept-discovery prompts |
| hard_philosophy | 1.60 | Smaller payoff (still meaningful) |
| anti_cheat | 0.80 | Saturated — workhorse Haiku 4.5 already handles |

---

## Key findings

1. **Opus 4.8 is the clear quality leader: 14.97/15 — near-perfect across every category.** Wins or ties every hard-prompt bucket. Only category where it doesn't win outright is anti_cheat (where Gemini Pro hits 15.00 vs Opus 14.80).

2. **gpt-4o is the bake-off loser on hard prompts (12.68/15)** — driven by a brutal Socratic score of 2.70/5. It answers directly when it should coach. **gpt-4o is NOT a premium escalation candidate** — it is a workhorse-style answer-giver. Despite being a tier above gpt-4o-mini, it scores ~2 points BELOW Gemini 2.5 Flash on Socratic discipline (per 06-04 baseline: Gemini Flash Socratic = 14.20).

3. **Gemini 2.5 Pro is the price-quality Pareto winner** — within 0.12 of Opus on rubric at 22% of the per-call cost. BUT it has a 13.5x latency penalty (P50 TTFT 11.5s vs Opus 0.85s, P50 total 13.1s vs 7.4s). **At chat-UX scale, 11.5s first-byte is unusable for real-time tutoring.** Could work for async "explain in depth" requests or background analysis.

4. **Sonnet 4.6 is dominated** — Opus beats it on quality (14.97 vs 14.40), Gemini Pro beats it on price (0.30¢ vs 0.88¢). It currently serves as SOLA's benchmark judge (independent of contestant pool); A.10 confirms that's the right slot for it. Not the right pick for production escalation.

5. **Anti-cheat is saturated** — every contestant scored 14.20+. The workhorse Haiku 4.5 baseline (14.60/15 on the 06-04 function benchmark) is already at parity with the premium tier. No reason to escalate anti-cheat to Opus.

---

## Recommendation

**YES, SOLA should add a premium escalation tier — but only for the three hard-reasoning categories where the workhorse visibly struggles: hard_math, hard_cs, hard_science.**

**Premium model: Claude Opus 4.8.**

- Quality lead is decisive on hard prompts (3-point gap vs gpt-4o; ~0.6-point gap vs Sonnet 4.6).
- Latency is tolerable for real-time chat (P50 TTFT 0.85s, P50 total 7.4s).
- Cost is 23x the workhorse Gemini Flash baseline ($0.014/call vs $0.060/call), but applies only to ~5% of turns under recommended routing.

**Routing triggers (regex on user message + course tag):**

- Multi-step math markers: "derive", "prove that", "step by step", LaTeX math `$...$`, calculus operators (integral, derivative, limit)
- CS markers: "complexity proof", "big-O", "recurrence", "proof by induction", inline code blocks `\`\`\``
- Sci markers: "mechanism", "from first principles", "thermodynamics", "Bayesian", multi-paragraph "explain how... at the molecular level"
- Course-tag fast path: any MATH/CS/PHYS/CHEM/BIOL course can default to a higher escalation rate (~10%)

**Cost projection at 100k Saylor MAU (25% adoption = 25k SOLA users):**

| Escalation rate | Calls/mo | Opus 4.8 cost | % of baseline chat spend |
|---|---:|---:|---:|
| 5% | 50,000 | ~$700 | ~67% extra |
| 10% (STEM-heavy courses) | 100,000 | ~$1,400 | ~135% extra |
| 15% (worst case) | 150,000 | ~$2,100 | ~200% extra |

At 5% escalation, premium tier adds ~$700/mo at 100k MAU — meaningful but bounded. At 10%+ the premium tier costs more than the baseline chat; only worth it if engagement / learning-outcome data justifies. **Start at 5% with strict routing triggers; raise to 10% only with a per-course A/B confirming retention or completion lift.**

**Anti-recommendations:**

- **Do not use gpt-4o as the premium tier.** It scored last on hard prompts (12.68/15) — its Socratic discipline collapses on deep reasoning. It is a workhorse with answer-first bias, not a premium escalation target.
- **Do not route real-time chat to Gemini 2.5 Pro.** 11.5s P50 TTFT breaks the streaming-chat UX. Pro is fine for the weekly benchmark harness, async "explain in depth" worksheets, or background analysis, but not the chat drawer's live response path.
- **Do not switch the integrity (anti-cheat) lane off Haiku 4.5.** Saturated category; premium tier adds 0.80 quality points at 13x cost.

**Judge-bias caveat.** Judge was OpenAI gpt-4.1. Bias risk: gpt-4.1 may favour OpenAI-family contestants. gpt-4o (the only OpenAI contestant) finished LAST, which argues against gpt-4.1-favouring-OpenAI bias. No special caveat needed on the Anthropic vs Google verdict.

**Human spot-check pending.** Per the A.10 design spec, an instructor should review 20 of the 40 responses across contestants to confirm the LLM judge's calls. The data is consistent enough (Opus tops every hard category cleanly) that the spot check is likely to validate the verdict, but it should still happen before any production routing change.

---

## What this changes in the vendor recommendations doc

`.drafts/sola-vendor-recommendations-2026-06-09.md` Appendix A.10 currently says: "Deferred bake-off (~$5 + half day). Decides whether SOLA needs a premium tier at all."

That section should be replaced with: "A.10 bake-off ran 2026-06-09 (see `.drafts/sola-a10-premium-escalation-bakeoff-2026-06-09.md`). Verdict: yes, add Claude Opus 4.8 as premium tier for hard_math / hard_cs / hard_science prompts at ~5% escalation rate. Budget ~$700/mo at 100k MAU. Wire via regex triggers + course-tag fast path. gpt-4o disqualified (last place on hard prompts); Gemini 2.5 Pro disqualified for real-time chat (11.5s TTFT); Sonnet 4.6 dominated."

The cost projection table in section 2 should grow a row for premium escalation:

| Component | 6k SOLA users | 12.5k | 25k |
|---|---:|---:|---:|
| Premium escalation (Opus 4.8, 5% of turns) | $170 | $350 | $700 |

Total at 100k Saylor MAU: **$1,370 + $700 = ~$2,070/mo** when the premium tier ships. Still ~30% cheaper than running the chat tier on Claude Haiku, and a fraction of the $5,000+/mo Opus-as-primary fiction.

---

## Open follow-ups

1. **Build the routing logic** (regex + course-tag triggers). v5.12 scope. Estimated half-day engineering + 1 day prompt-tag curation.
2. **Instructor spot-check on 20 responses** from the bake-off (humans confirm or refute the judge calls). Tom + 1 Saylor instructor, ~1 hour together.
3. **Per-course adoption monitoring** once routing ships: track escalation rate per course, confirm it stays in the ~5% target band, raise per-course soft caps if needed.
4. **Watch Anthropic's API for further reasoning-class quirks.** Opus 4.7/4.8 already dropped temperature support (fixed in v5.11.0 claude_provider). Opus 4.9+ may add more constraints; the per-prefix denylist in `model_supports_temperature` is the canonical place to extend.
