# SOLA Multi-Provider Optimization Plan

**Status:** Draft. Internal architecture memo for Tom + Saylor leadership review.
**Date:** 2026-06-04 (rev. 3 — aligned with the 2026-06-03 chat benchmark + 2026-06-04 domain bake-off; retired the 3-group pilot framing; universal-rollout baseline is now text-only; cost projections re-baselined to 25% SOLA adoption; voice + audio + avatars moved out of baseline scope).
**Anchored to:** SOLA v5.9.0 (learning-path map shipped). Per-call failover decorator shipped in v5.5.0 and verified on dev.sylr.org.
**Companion doc:** `.drafts/sola-pilot-to-scale-vendor-recommendations-2026-06-01.md` — single source of truth for which vendors SOLA uses at scale (Gemini 2.5 Flash chat primary, gpt-4o-mini specialty routing, Claude Haiku 4.5 integrity reference, Voyage-3.5 embeddings + rerank-2.5). This optimization plan covers the architecture, compliance posture, and operational mechanics that make those picks work in production.
**Source pings:** Jeff's Grok-sourced notes at `~/77876a9ce7ded637b94d6156e5b4fc0ef76ea002.txt` (verified, not taken at face value; flagged inline where claims didn't survive a primary-source check).

**Constraints driving provider selection (rev. 3, unchanged from rev. 2):**

1. SOC 2 Type II attestation. Required for vendor onboarding at Saylor's posture.
2. GDPR DPA + EU data residency for EU learners.
3. No training on student conversations by default. Important under GDPR even without FERPA; protects learner trust.

**Scope decisions baked into rev. 3:**

- **Text-only baseline.** TTS, STT, the Voice tab, and talking avatars are NOT in the universal-rollout baseline; they live in Appendix B of the companion doc with vendor analysis and pricing for a future opt-in. This optimization plan therefore focuses on chat, specialty routing, and RAG architecture. Voice-related sections in this document (timeouts, voice_registry, TTS providers) remain in admin for a future opt-in but are not exercised in the rollout build.
- **25% SOLA adoption assumption.** All cost projections and capacity math assume 25% of Saylor MAU uses SOLA in a given month. "100k Saylor MAU" therefore implies ~25k active SOLA users.
- **Retired the 3-group pilot framing.** The Q2 pilot's cost-led G1 (Together Lite) / G2 (Mistral Small) / G3 (xAI grok-4-1-fast) split was the right call to generate cross-vendor signal cheaply, and it produced the benchmark data that drove the universal pick. With the benchmark done, those cost-led providers no longer carry primary traffic in the universal rollout. They remain on the bench as compliance-verified failover candidates.

**Not a constraint (carried from rev. 2):**

- FERPA addendum / "school official" designation. Saylor is a free university and does not maintain FERPA-protected education records.

---

## Summary

1. **The chat-tier question is settled.** The 2026-06-03 chat benchmark and 2026-06-04 domain bake-off put Gemini 2.5 Flash on top of every domain and function bucket at roughly half the per-call cost of Claude Haiku 4.5. Universal-rollout chain: **Gemini 2.5 Flash (Vertex AI) primary → OpenAI gpt-4o-mini failover → Mistral Small ultimate fallback.** Specialty routing: **Claude Haiku 4.5 on suspected-cheat turns (~5% of volume); OpenAI gpt-4o-mini on quiz coach + mastery classifier + analytics / weekly digests.** See companion doc Appendices A.1–A.6 for the per-component evidence.

2. **The 3-group A/B/C pilot framing is retired.** Together Lite, Mistral Small, and xAI grok-4-1-fast carried the pilot through Q2 and produced the cross-vendor signal that drove the universal pick. They no longer carry primary traffic. **xAI is also dropped from the production failover chain in rev. 3** because primary-source SOC 2 Type II attestation still cannot be retrieved from their gated trust center; this matters less when they were a deliberately-time-boxed pilot bet and more now that any production lane would need a clean compliance posture.

3. **The TIER 1 set widened to 13 verified providers in rev. 2 and that classification stands.** Without FERPA as a constraint, providers with verified SOC 2 + DPA + clean training defaults are primary-eligible: OpenAI, Anthropic (direct US / via Bedrock or Vertex EU), AWS Bedrock, Azure OpenAI, Google Vertex AI, Mistral La Plateforme (with opt-out), Together AI, Fireworks AI, Groq, Cerebras, SambaNova, Baseten, Predibase. The baseline picks a subset; the rest stay viable for future workloads.

4. **Mistral still has the training-default foot-gun.** Their DPA defaults to using API inputs for training unless explicitly opted out at workspace setup. Important under GDPR. Ops must verify training-opt-out + Zero Data Retention before any Mistral traffic flows, including the ultimate-fallback chat lane.

5. **Prompt caching is still the biggest cost lever not turned on.** Anthropic gives 90% off cached prefix reads; OpenAI applies a 50% prefix cache discount automatically; Gemini does not currently expose a prefix cache discount. At 100k Saylor MAU (25% SOLA adoption = 25k SOLA users), turning on Anthropic `cache_control` on the Haiku integrity-reference lane and surfacing OpenAI's `prompt_cache_tokens` on the analytics lane could trim baseline cost a further 5–10%. §4 has the wiring.

6. **Embeddings migrate from OpenAI text-embedding-3-small to Voyage-3.5 (companion doc A.7).** SOLA adds Voyage rerank-2.5 as a runtime two-stage retrieval upgrade (companion doc A.8). Single biggest RAG-quality improvement available; absolute spend is trivial (~$5 one-time re-index of the 161-course corpus; ~$63/mo at 100k Saylor MAU for rerank). §10 below replaces the prior "OpenAI text-embedding-3-small unchanged" assumption.

7. **The per-call failover layer shipped in v5.5.0 (2026-05-13) and is verified on dev.sylr.org.** Settings: `failover_per_call_enabled` off by default, `failover_timeout_chat` 8s, `failover_timeout_voice` 3s. Audit rows fire on every `failover_fallthrough` and `failover_circuit_open`. Voice timeout is irrelevant in the baseline rollout (Voice tab off) but the setting remains in admin for a future opt-in.

8. **The pilot's cost-led baseline ($203/mo on Together Lite per the rev. 2 capacity plan) is no longer the live number.** The current working baseline cost at 100k Saylor MAU under 25% SOLA adoption is **~$1,370/mo for the text-only stack** (companion doc section 4). The capacity-plan rate corrections from rev. 2 (Together Lite $0.10/$0.10; grok-4-1-fast $0.20/$0.50; DeepInfra $0.02/$0.03 Llama 3.1 8B Turbo FP8) remain factually correct and the underlying tier classifications still apply; they just don't drive the headline number anymore.

9. **OpenRouter posture is unchanged from rev. 2.** Privacy-policy disclaimer on downstream training keeps OpenRouter out of TIER 1 for student-facing traffic. Stays useful as a benchmarking harness and non-PII workload egress with ZDR forced on per request.

10. **For the future vector-DB move**, pgvector if Saylor moves Moodle to Postgres; MySQL 8.0+ `VECTOR` type if staying on MySQL; do not introduce Pinecone/Qdrant for the universal rollout. No change. The Voyage migration is the only embedding/RAG change in scope.

11. **Audio + voice + avatar architecture is captured in companion doc Appendix B.** This optimization plan does not duplicate the vendor selection there; if/when Saylor opts in for Voice tab or TTS, the relevant provider rows (Deepgram, ElevenLabs, Azure Speech, Google Chirp 3, Tavus, Synthesia) get added back to `comparison_providers` and `voice_registry` then. Today the rollout build ships with `realtimeenabled` off and the header voice button hidden.

12. **Grok's `Fireworks 0.17s TTFT` and `Groq 0.19s TTFT` claims still didn't survive primary-source verification.** Real numbers from Artificial Analysis: Groq Llama 3.1 8B is 704 tok/s output at **0.57s** latency; Cerebras is **2,358 tok/s** (genuinely "thousands per second"); Fireworks isn't in AA's tracker for Llama 3.1 8B. Fast-typing chat experience comes from Cerebras and Groq, not Fireworks. Carried forward from rev. 2 for the record.

---

## 1. Current state — what SOLA already supports

From the code as of v5.4.6:

| Surface | Behavior |
|---------|----------|
| Provider abstraction | `classes/provider/base_provider.php` registers 13 providers via `instantiate()`: claude, openai, ollama, minimax, deepseek, gemini, mistral, openrouter, together, xai, coreai, custom, stub |
| Per-course override | `course_config_manager::get_effective_config($courseid)` lets a single course pick a different provider/model/key from site default |
| Comparison registry | `comparison_providers` admin setting is a pipe-separated multi-line registry of "label|apikey|models|temperature" rows; used by the admin LLM comparison picker and as the lookup table for the failover chain |
| Voice registry | `voice_registry::parse_rows()` is the equivalent for realtime / TTS / STT |
| Budget guard | `spend_guard::check($courseid, $capability)` returns CAP_OK / CAP_WARN_80 / CAP_WARN_95 / CAP_BLOCKED based on `spend_cap_*` config + monthly/period rollups |
| Budget failover | When `spend_guard::check` returns BLOCKED, `base_provider::create_from_config` calls `spend_guard::resolve_failover($capability)` and swaps the provider/key for that call. Failover chain is configured one-line-per-entry in `spend_failover_chain` (`chat:label-name` or `voice:label-name`) |
| Emergency disable | `emergency_control::disable(['chat'|'voice'|'rag'|'outreach'])` via `admin/cli/emergency_disable.php` flips a config flag with backup-and-restore semantics |
| RAG latency telemetry | v5.4.6: every assistant row records `rag_latency_ms` for the turn's `rag_retriever::retrieve` wall-clock |

**Gaps relative to Grok's "fall over after 2 seconds" recommendation:**

- **No per-call circuit breaker.** Today's failover only fires when the monthly spend cap is reached. A provider that returns 503s, hangs at 30s, or starts emitting garbage will not be auto-routed away from until the cap kicks in.
- **No request-level retry policy.** A transient 502 from any backend will surface to the learner as a chat error.
- **No latency-based routing.** No way today to express "if Together AI's TTFT is over 3s, try Fireworks for this request."
- **No prompt caching infrastructure.** Anthropic's `cache_control` headers and OpenAI's automatic prefix caching are not currently surfaced through the provider abstraction. RAG context is sent fresh on every request.

These four gaps are addressable in the existing abstraction without major rework. §7 describes the wiring.

---

## 2. Compliance tier classification (primary-source verified, last refreshed 2026-05-13; rev. 3 retains the rev. 2 classification with one production-chain change — xAI dropped from the production chain pending compliance verification, see §3)

Saylor's constraints, ranked (FERPA removed in rev. 2):

1. **No training on student data by default.** Even with a signed DPA, if the default is opt-in to training you have a foot-gun. **Mistral still fails this test on default settings**; see TIER 1 note.
2. **SOC 2 Type II** attestation, primary-source verifiable. Required for vendor onboarding at Saylor's posture.
3. **GDPR DPA** + (ideally but not required) EU data residency for EU learners.

### TIER 1: Production-ready primaries (verified SOC 2 Type II + GDPR DPA + no-training-by-default)

| Provider | SOC 2 Type II | EU residency | Training default | Notes |
|----------|:-:|:-:|---|---|
| **OpenAI (API platform)** | Yes (ISO 27001:2022 + 27701:2019 also) | Yes (EU data residency available) | Opt-out is the default on API | DPA available; SOLA uses gpt-4o-mini as the chat first-failover and as primary for quiz / classifier / analytics in the universal rollout |
| **Anthropic (direct API)** | Yes (+ ISO 27001 + ISO 42001) | **No on direct API** (US or "global" only) | Commercial terms forbid training | For EU learners with Claude, route via Bedrock EU or Vertex EU. SOLA's `claude_provider` already handles direct API |
| **AWS Bedrock** | Yes (in-scope of AWS SOC1/2/3) | Yes: Frankfurt, Paris, Stockholm, Ireland, Spain | No training on customer data | Best multi-model hub (Llama, Claude, Mistral, Nova). Add via `openai_compatible_provider` with Bedrock Converse base URL |
| **Microsoft Azure OpenAI** | Yes (Azure inherits) | Yes (EU data zones) | No training on customer data | Strong SOC 2 + GDPR story; the FERPA-as-default advantage from rev. 1 no longer differentiating |
| **Google Vertex AI (Gemini family + Claude on Vertex)** | Yes (Gemini in 2024 SOC reassessment) | Yes: 10+ EU regions | "Google won't use your data to train or fine-tune any AI/ML models without your prior permission or instruction" | Strong fit if you want Gemini + Claude on one contract with EU residency |
| **Mistral La Plateforme** | Yes (claimed; SOC 2 Type vs Type II distinction in gated article; request the report) | Yes (default EU hosting, French jurisdiction, not CLOUD-Act-subject) | **DPA defaults to USING inputs for training** unless customer opts out at workspace setup | Strongest GDPR posture for EU. Foot-gun on training default. Universal-rollout ultimate-fallback chat lane; previously Q2 pilot's G2 primary. Verify training-opt-out + ZDR are enabled before any traffic flows. |
| **Together AI** | Yes (July 2025) + HIPAA BAA | Unverified | No training on managed models | Q2 pilot's G1 primary; retired from production chain in rev. 3 (Llama 8B benchmark scores too weak on anti-cheat for student-facing chat). Still registered for benchmarking. Request EU residency status and DPA for vendor file if ever re-promoted. |
| **Fireworks AI** | Yes + HIPAA | Unverified | "Fireworks does not log or store prompt or generation data for open models without explicit user opt-in" | TIER 1 backstop; not in production chain. Verify EU residency before any EU-learner promotion. |
| **Groq** | Yes (Aug 2024) + HIPAA BAA + GDPR DPA with SCCs | **No EU region documented** | Per DPA: no use of customer data to train models | Excellent latency leader. Use for US-learner traffic only, or accept the EU data transfer with SCCs. |
| **Cerebras Cloud** | Yes + GDPR + CCPA on trust page | Unverified | Trust page does not document training-on-customer-data; ask before signing | Top throughput option (2,358 tok/s on Llama 3.1 8B per Artificial Analysis). |
| **SambaNova / SambaCloud** | Yes (claimed) | Unverified | Explicit "never sees or collects your data or prompts" | Solid for long-context workloads (up to 164K on most current models). |
| **Baseten** | Yes + HIPAA + GDPR | **US-only infrastructure documented** | No training on customer data | Best fit if Saylor ever ships its own fine-tune. |
| **Predibase** | Yes | Unverified | VPC deployment is the strong fit (Predibase not a subprocessor) | Relevant for per-course LoRA fine-tuning if it ever becomes a feature. |

> **Why TIER 1 widened in rev. 2**: with FERPA off the table, providers with verified SOC 2 + DPA + clean training defaults move into the primary-eligible pool even without "school official" contractual support. The cost-arbitrage providers (Together, Groq, Fireworks, Cerebras) are now first-class options, not just failover candidates.

### TIER 2: Workable for non-PII / failover with configuration (verified SOC 2 but a meaningful caveat applies)

| Provider | Verified posture | The caveat |
|----------|------------------|------------|
| **DeepInfra** | SOC 2 Type II + ISO 27001 verified (June 2025) | **GDPR listed as "in progress"** on their own trust center as of recent review. Use for non-PII benchmarking, analytics summarization, and as a US-only failover. Verify GDPR completion before EU rollout. Per-MTok rate is dramatic ($0.02/$0.03 on Llama 3.1 8B Turbo FP8 is ~5x cheaper than Together Lite). |
| **Anyscale** | SOC 2 Type II verified | Everything else (HIPAA, GDPR specifics, ISO 27001) gated by NDA. Higher uncertainty. Better as infrastructure for fine-tuning workloads than as a direct inference API for SOLA. |

### TIER 3: Not appropriate for production (compliance posture unverifiable from primary sources)

| Provider | Reason |
|----------|--------|
| **xAI (Grok API)** | Marketing claims SOC 2 / GDPR / CCPA but **trust center is gated** (HTTP 403 on x.ai/security; auth wall on trust.x.ai). Primary-source SOC 2 Type II attestation could not be retrieved. Q2 pilot used xAI grok-4-1-fast as the G3 primary as a time-boxed bet; **rev. 3 drops xAI from the production failover chain** (see §3). It remains registered for benchmarking. If xAI publishes a primary-source SOC 2 Type II report + signs a DPA, they can move to TIER 1 and re-enter the failover pool. |
| **Novita AI** | Trust center exists but third-party analyses describe "limited SOC 2 documentation." No primary-source verification of SOC 2 Type II or GDPR. |
| **Atlas Cloud** | Marketing claims "SOC I/II + HIPAA" but no primary-source attestation could be retrieved. Model catalog also does not include Llama (per the performance research), which removes the cost-arbitrage rationale. |
| **Lepton AI / NVIDIA DGX Cloud Lepton** | Marketplace-style aggregator; compliance inherits from the underlying GPU partner. No Lepton-specific SOC 2 attestation. Absorbed into NVIDIA's DGX Cloud Lepton in 2025. |
| **OpenRouter (aggregator)** | Privacy policy **explicitly disclaims responsibility for downstream training behavior**: *"We do not control, and are not responsible for, LLMs' handling of your Inputs or Outputs, including for use in their model training."* SOC 2 type unverified at primary source (trust.openrouter.ai is gated). Default OpenRouter retention is no-log unless customer opts in (good), but the downstream-disclaimer keeps it out of TIER 1 for student traffic. Use for benchmarking and non-PII workloads; if used for any student-facing path, force `provider.zdr: true` per request. |
| **OctoAI** | **Wound down October 2024 after NVIDIA acquisition.** Not a usable provider anymore. |

### Verified takeaways (rev. 2)

1. **TIER 1 is now 13 providers** rather than 4. The Q2 pilot's cost-led picks (Together Lite, Mistral Small, xAI grok-4-1-fast) remain TIER 1 (Mistral) or compliance-pending (xAI) but none of them carries production primary traffic in rev. 3. The failover pool is much deeper than rev. 1.
2. **xAI remains the soft spot.** Without FERPA they don't lose tier ground for not having FERPA addenda, but their *underlying* SOC 2 attestation can't be primary-source verified today. Still a go/no-go gate for any post-pilot scope expansion.
3. **Mistral's training default is still unsafe** even without FERPA in scope. Opt-out + ZDR is non-negotiable.
4. **OpenRouter softens but doesn't graduate**: still benchmarking-first; can be used for non-PII production paths with ZDR forced on per request.
5. **Anthropic still needs the Bedrock/Vertex EU bridge for EU learners.** No direct EU residency on the Anthropic API.

---

## 3. Per-component baseline (supersedes the 3-group pilot framing)

The Q2 pilot's 3-group cost-led split (G1 Together, G2 Mistral, G3 xAI) was a deliberate cross-vendor signal-generator; it produced the benchmark data that drove the universal pick. In rev. 3 the per-group assignments are retired in favor of a single universal-rollout baseline with specialty routing for distinct workloads.

The canonical per-component picks live in companion doc Appendices A.1–A.9. The table below shows the production wiring that this optimization plan adds (the `comparison_providers` rows and the `spend_failover_chain` entries the admin needs to set).

| Component | Capability | Primary | 1st failover | 2nd failover | Companion doc |
|---|---|---|---|---|---|
| Core chat tutor (incl. ESL) | `chat` | **Gemini 2.5 Flash** (Vertex AI) | OpenAI gpt-4o-mini | Mistral Small (EU) | A.1, A.4 |
| Anti-cheat / integrity | `chat_integrity` | **Claude Haiku 4.5** | Gemini 2.5 Flash | — | A.3 |
| Quiz coach | `chat_quiz` | **OpenAI gpt-4o-mini** | Mistral Small | — | A.2 |
| Mastery classifier (adhoc) | `classifier` | **OpenAI gpt-4o-mini** | Mistral Small | — | A.5 |
| Analytics + weekly digests | `analytics` | **OpenAI gpt-4o-mini** | Gemini 2.5 Flash | — | A.6 |
| Embeddings (RAG indexing) | `embed` | **Voyage-3.5** | OpenAI text-embedding-3-small | — | A.7 |
| RAG re-ranker (runtime) | `rerank` | **Voyage rerank-2.5** | Cohere Rerank 3.5 | — | A.8 |
| Judge / benchmark harness | `bench_judge` | **Claude Sonnet 4.6** | Gemini 2.5 Pro | — | A.9 |

**Out of the baseline production chain** (compared to the rev. 2 doc):

- **xAI grok-4-1-fast** is dropped from the failover chain. Primary-source SOC 2 Type II attestation still cannot be retrieved from their gated trust center; that was workable for a deliberately-time-boxed pilot bet but does not meet the bar for the universal rollout. The model rows can stay registered in `comparison_providers` for benchmarking only; they should NOT appear in any `spend_failover_chain` entry.
- **Together Lite / Fireworks / DeepInfra / Groq** are no longer in the production chat chain. They remain TIER 1 (or TIER 2 in DeepInfra's case) and remain registered for benchmarking and for non-PII analytics workloads if cost-led routing ever returns. None of them out-scored Gemini 2.5 Flash on tutoring quality, so retaining them in the production chain adds no upside.
- **The Voice tab / TTS / STT lanes** are deferred (companion doc Appendix B). `voice_registry` stays in admin so the wiring is intact for a future opt-in, but no rows are required for the universal rollout.

### 3a. Production failover chain — concrete settings

```text
# Site Admin -> SOLA -> comparison_providers
# Format: label | apikey | models | temperature

# Chat tutor lane (primary + failover)
gemini-flash-25  | vai-XXX     | gemini-2.5-flash                         | 0.4
openai-mini      | sk-oa-XXX   | gpt-4o-mini                              | 0.4
mistral-small    | sk-ms-XXX   | mistral-small-latest                     | 0.4
bedrock-mistral  | aws-XXX     | mistral.mistral-small-2503-v1:0          | 0.4   # EU residency for chat failover

# Integrity-reference lane (Claude Haiku 4.5 on suspected-cheat turns)
anthropic-haiku  | sk-ant-XXX  | claude-haiku-4-5                         | 0.4

# Specialty lanes (quiz / classifier / analytics all share gpt-4o-mini)
# No separate rows needed unless EU-residency lane is desired; otherwise reuse `openai-mini`

# Judge / benchmark harness
anthropic-sonnet | sk-ant-XXX  | claude-sonnet-4-6                        | 0.0   # judges run deterministic

# Embeddings + rerank
voyage-3-5       | vy-XXX      | voyage-3.5                               | -
voyage-rerank-25 | vy-XXX      | rerank-2.5                               | -
openai-embed-3sm | sk-oa-XXX   | text-embedding-3-small                   | -    # failover only

# Site Admin -> SOLA -> spend_failover_chain
# Format: capability:label
chat:openai-mini
chat:mistral-small
chat:bedrock-mistral

chat_integrity:gemini-flash-25         # integrity lane falls back to the primary chat tier on Haiku 429

chat_quiz:mistral-small
classifier:mistral-small
analytics:gemini-flash-25

embed:openai-embed-3sm
rerank:voyage-rerank-25                # no second failover; degrade to single-stage retrieval on rerank failure

# Voice lane is unused in the baseline rollout (Voice tab off); leave empty
```

This chain activates on monthly cap (existing behavior since v5.4.x) AND on per-call timeout / 5xx (v5.5.0 and later, when `failover_per_call_enabled` is flipped on).

### 3b. Course-level override pattern (still supported, lighter use)

`course_config_manager::get_effective_config($courseid)` still supports per-course overrides; the universal-rollout default makes this unnecessary for most courses. The override path remains useful for:

- A future single-course Voice tab pilot (Appendix B): override `realtimeenabled = true` on the pilot course only.
- A future single-course talking-avatar A/B test (Appendix B): override the avatar provider config on the test course only.
- A future per-course premium escalation tier pilot (companion doc A.10): override `chat = anthropic-sonnet` on the test course's hard-content sections.

Document the override procedure in `.wiki/Provider-Configuration.md` if not already there.

### 3c. Capacity-plan numbers carried forward

The rev. 2 rate corrections (Together Lite $0.10/$0.10; grok-4-1-fast $0.20/$0.50; DeepInfra Llama 3.1 8B Turbo FP8 $0.02/$0.03) remain factually correct. They no longer drive the production cost baseline since none of those vendors carries primary traffic. The current cost baseline lives in companion doc section 4: ~$1,370/mo at 100k Saylor MAU under 25% SOLA adoption.

---

## 3.5. Fast-path benchmark — what we ran (historical reference)

This section was a forward-looking 1-week protocol in rev. 2. In late May / early June 2026 we executed it; the results drove the rev. 3 universal pick. Kept here so the rationale chain is auditable.

### What was actually run

- **Candidate set:** 8 providers — Together Llama 3.1 8B Lite, Fireworks Llama 3.1 8B, Groq Llama 3.1 8B Instant, Mistral Small, OpenAI gpt-4o-mini, xAI grok-4-1-fast, Anthropic Claude Haiku 4.5, DeepInfra Llama 3.1 8B Turbo FP8 — plus Google Gemini 2.5 Flash, added late as a candidate after Vertex AI procurement closed.
- **Two benchmark passes** rather than one:
  - **2026-06-03 chat benchmark** (`.drafts/sola-benchmark-decision-2026-06-03.md`) — 8 providers × 50-prompt golden tutor set drawn from real BUS101 / ESL001 / CS401 traffic; categorized as Socratic, quiz, illustration, anti-cheat, multilingual (10 each). Judge: Claude Sonnet 4.6.
  - **2026-06-04 domain bake-off** (`.drafts/sola-benchmark-domain-routing-2026-06-04.md`) — 8 providers × 40-prompt subject-domain set covering 5 subject domains (math, science, humanities, CS, ESL) × 5 function buckets. Verified whether different providers should route by domain.
- **Cost:** ~$5 in API spend across both passes (matches the rev. 2 estimate).
- **Harness:** the proposed `admin/cli/run_tutor_golden.php` was built and is reusable for weekly re-runs (see §8).

### What the benchmarks settled

1. **Gemini 2.5 Flash wins every subject domain and ranks at or near the top in all five function buckets** (Socratic, quiz, illustration, anti-cheat, multilingual). 14.35/15 overall on the domain benchmark; the only model above 14 on every subject.
2. **Claude Haiku 4.5 is the consistent runner-up** at ~0.5 points lower than Gemini on overall rubric but distinctly first on anti-cheat refusal discipline (14.60/15 vs Gemini 14.30/15). This is the basis for the integrity-reference routing decision in companion doc A.3.
3. **Function saturation on quiz coach:** every provider except the two budget Llamas and Qwen scored 14.0–14.7 — quality is uniform across the cheap-to-premium band, so the right pick is the cheapest TIER 1 provider that scores high. That's gpt-4o-mini (companion doc A.2).
4. **Budget Llamas (Together Lite, Fireworks Llama 3.1 8B) score 10.30–11.50 on anti-cheat** — they cave and supply graded answers when pressed. Disqualifying for any chat lane on student traffic.
5. **Multilingual lead is decisive:** Gemini 2.5 Flash 14.50/15, ahead of a Claude / xAI / OpenAI tier near 13.7. Budget Llamas 9.3–10.0 — disqualifying for any course with meaningful ESL enrollment.
6. **No domain × provider win calls for per-domain routing.** Gemini wins every domain, so the proposed "route math to provider X, route humanities to provider Y" framing the 2026-06-04 bake-off was set up to test does not pay.

### What this changed in rev. 3

The benchmark settled the §3 baseline picks directly. The 3-group framing in rev. 2 was the cross-vendor signal generator; once the benchmark produced a decisive winner with a clear runner-up, the production stack collapses to: Gemini Flash chat primary + Claude Haiku 4.5 integrity reference + gpt-4o-mini for the saturated functions (quiz / classifier / analytics). The companion doc Appendices A.1–A.6 hold the detailed per-component rationale.

### Operational follow-on: the §8 weekly harness

The fast-path harness is now the production drift-detection mechanism. It re-runs nightly on a small (5-prompt × 8-provider) canary slice and weekly on the full 50-prompt set. A drop of >0.5 rubric points on the primary triggers an alert and a failover-chain review. §8 covers the protocol.

---

## 4. Prompt caching — the biggest cost lever we're not using

The biggest single-shot cost reduction available is provider-side prompt caching for the RAG context block, which is the same on every turn within a course session. Today SOLA doesn't surface cache headers, so every turn pays full input price even when the prefix is identical.

Per-provider caching support and the SOLA work needed:

| Provider | Cache mechanism | Cached-read discount | SOLA work to enable |
|----------|----------------|---------------------|---------------------|
| Anthropic | `cache_control` header on a message block; explicit | **0.10× input** (90% discount on cached read). Writes are 1.25× (5-min TTL) or 2× (1-hour TTL). | Add `cache_control` to the system prompt and retrieved-chunks block in `claude_provider::chat_completion`. ~50 lines. |
| OpenAI | Automatic for ≥1024-token prefixes | **0.50× input** ($0.075/MTok cached vs $0.150/MTok base for gpt-4o-mini) | Already on, automatic. Surface the `prompt_cache_tokens` field in usage block (we don't read it today) so the dashboard can show cache-hit rate. |
| OpenRouter (any backend with caching) | Pass-through via sticky routing — once a model is first hit, follow-up requests are pinned to the same upstream provider to keep its cache warm | per upstream — Anthropic 0.10×, OpenAI 0.25-0.50×, DeepSeek reduced, Gemini reduced | `cache_control` blocks survive pass-through to Anthropic via OpenRouter. Surface `usage.prompt_tokens_details.cached_tokens` and `cache_discount` from OpenRouter's response. |
| Together AI | "Prompt cache" feature, paid plan tier | varies | Confirm Saylor's Together plan includes it; non-trivial cost-control benefit |
| Mistral | Not generally available as of pricing-page check | — | None today. Watch for it. |
| xAI Grok | Automatic on supported models per OpenRouter's pass-through docs | varies | None today; surface usage details when present |
| Fireworks | Automatic for repeated prefixes per their docs | varies | Verify; nothing to do client-side if so |

**Estimated impact under the rev. 3 baseline:** Gemini 2.5 Flash (primary chat) does not currently expose a prefix cache discount, so the primary chat lane sees no caching benefit until Google ships that. The wins concentrate on the **Claude Haiku 4.5 integrity lane** (90% discount on cached system prompt + RAG block) and the **OpenAI gpt-4o-mini specialty lanes** (50% automatic prefix discount, currently uncounted in analytics). Aggregate impact at 100k Saylor MAU under 25% SOLA adoption: roughly 5–10% reduction on the baseline ($70–$140/month saved on a $1,370/month bill). Worth wiring for the SOC2 evidence and the dashboard visibility even at modest absolute dollars.

---

## 5. Per-call health failover — design (shipped v5.5.0, kept here for the architectural record)

This section documented the design before v5.5.0 shipped on 2026-05-13. It is preserved as the architecture reference; the implementation matches the design, verified end-to-end on dev.sylr.org. New SOLA contributors touching `failover_chain` should read §5a–§5d before changing behavior.

### 5a. What to add

A new class `classes/provider/failover_chain.php` that wraps a list of providers and:

1. Tries the head of the list with a configurable per-call timeout (default 8s for chat, 3s for voice realtime).
2. On `provider_exception`, HTTP 5xx, or timeout, opens a per-provider circuit (15-minute back-off) and rotates to the next in the chain.
3. On three consecutive successful calls, closes the circuit and restores normal priority.
4. Emits an audit row on every fall-through: `event=failover_fallthrough`, fields `primary`, `fallback`, `reason`, `latency_ms`, `userid`, `courseid`. Critical for SOC2 evidence of operational resilience.

State storage:

- Per-provider circuit state in MUC cache (`local_ai_course_assistant/provider_circuit`, TTL 15 minutes). Per-host, not per-key.
- Counter of fall-throughs per period in a new analytics table `local_ai_course_assistant_failover_log` or piggyback on the audit table.

### 5b. How it plugs in

`base_provider::create_from_config` currently returns one provider. Wrap its return value in a `failover_chain` decorator when the chain has more than one entry:

```php
// Pseudocode
$primary = base_provider::instantiate($provider, $overrides);
$chain   = spend_guard::resolve_failover_chain($capability); // returns array of [provider,apikey,label]
if (!empty($chain)) {
    return new failover_chain($primary, array_map(fn($r) => base_provider::instantiate($r['provider'], $r + $overrides), $chain), [
        'timeout_seconds' => 8,
        'audit'           => true,
    ]);
}
return $primary;
```

The `failover_chain` class implements `provider_interface` and delegates `chat_completion` and `stream_completion` to whichever underlying provider has a closed circuit.

### 5c. SSE-streaming wrinkle

Per-call failover is tricky when the first provider has already started streaming tokens but stalls mid-response. The simple thing: bail to fallback only if we haven't received the first token yet (`TTFT > timeout`). Once tokens are arriving, ride out the response. This matches Grok's "fall over after 2 seconds" pattern — they meant TTFT, not total response time.

`sse.php` already has the streaming loop; the failover_chain decorator's `stream_completion` method needs to wrap the SSE start with a `microtime()` deadline and switch chains if the deadline fires before the first chunk.

### 5d. Scoping

This change touches:

- New file: `classes/provider/failover_chain.php` (~150 lines)
- `classes/provider/base_provider.php`: wrap return in `create_from_config`
- `classes/spend_guard.php`: extend `resolve_failover` to return an ordered list rather than just first match
- `sse.php`: pass `chain_state` through to provider so streaming-deadline behavior works
- New PHPUnit test: simulate primary timing out, assert fallback was hit and audit row exists
- New admin setting: `failover_timeout_chat` (default 8), `failover_timeout_voice` (default 3)

Estimate: 1 day implementation + 0.5 day test. Ship as v5.5.0 (minor bump — adds a new failure mode that admins must understand).

---

## 6. Aggregator strategy — OpenRouter (verified 2026-05-12)

OpenRouter is a credible aggregator with documented production semantics. Two distinct use cases for SOLA:

### 6a. Verified facts (primary-sourced)

| Capability | Verified |
|-----------|----------|
| Markup on inference | **None.** Quote from FAQ: *"We pass through the pricing of the underlying providers without any markup on inference pricing."* |
| Credit purchase fee | 5.5% (or 5% crypto) with $0.80 minimum on Stripe |
| BYOK fee | 5% of equivalent OpenRouter cost, debited from credit balance. **First 1M BYOK requests/month free.** A flat monthly subscription is in roadmap. |
| Provider routing API | Per-request `provider` object: `order` (ordered list), `allow_fallbacks` (bool, default true), `only` / `ignore`, `require_parameters`, `data_collection`, `quantizations`, `sort: "price"|"throughput"|"latency"`, `preferred_min_throughput`, `preferred_max_latency` (number or p50/p75/p90/p99 object), `max_price`, `zdr` (bool — restrict to zero-data-retention endpoints) |
| Default routing | Prioritizes providers without 30-second outages, weights by inverse-square-of-price. Append `:nitro` for throughput-first; `:floor` for price-first. |
| Fallback triggers | **Hard-error driven only.** Latency is *declarative preference*, not a runtime auto-fallback. If you need active mid-stream failover on slow tokens, build it yourself (§5). |
| Prompt caching | OpenRouter doesn't run its own cache — uses **provider sticky routing** so cache stays warm on repeat hits. `cache_control` (Anthropic) flows through. `usage.prompt_tokens_details` returns `cached_tokens`, `cache_write_tokens`, `cache_discount`. |
| Data retention default | **No retention** on prompts or completions by default. Two opt-in toggles (private logging dashboard, consent-to-product-improvement with 1% discount). Anonymous prompt categorization runs regardless. |
| ZDR mode | Account-level toggle or per-request `provider.zdr: true`. Endpoint list at `https://openrouter.ai/api/v1/endpoints/zdr`. |
| DPA | **Available** for enterprise per their enterprise page. |
| SOC2 Type II | **UNVERIFIED.** Enterprise page says "SOC-2 compliant partner" — ambiguous between Type I and Type II. Their public trust center didn't render in research. **Action: request the actual SOC 2 report from their team before contracting for student data.** |
| GDPR + EU residency | GDPR compliance asserted; "EU region locking" and "in-region routing" advertised for enterprise. Control plane is US-based with SCCs for EU transfers. |
| Streaming SSE | Normalized OpenAI shape (re-emitted, not raw passthrough). Inline SSE "comment" payloads exist and should be ignored. `usage` lands in final chunk. |
| Tool calling | Standardized across providers — send OpenAI-style `tools`, OpenRouter transforms for non-OpenAI backends. **Gotcha:** `tools` array must be present on EVERY turn, not just the first. If SOLA's loop sends tools only on turn 1, fix that before routing through OpenRouter. |
| OpenAI compatibility | Mostly drop-in. Differences: (a) model slug must include provider prefix (`openai/gpt-4o-mini`); (b) optional `HTTP-Referer` + `X-Title` headers for app attribution; (c) responses carry both `finish_reason` and `native_finish_reason`; (d) **unsupported parameters are silently ignored** (`logit_bias` on non-OpenAI, `top_k` on OpenAI) — biggest testing gotcha. |

### 6b. Verified per-MTok rates (OpenRouter, 2026-05-12)

| Model slug on OpenRouter | Input ($/MTok) | Output ($/MTok) |
|--------------------------|---------------:|----------------:|
| `meta-llama/llama-3.1-8b-instruct` | $0.02 | $0.05 |
| `meta-llama/llama-3.1-70b-instruct` | $0.40 | $0.40 |
| `openai/gpt-4o-mini` | $0.15 | $0.60 |
| `anthropic/claude-haiku-4.5` | $1.00 | $5.00 (cache reads at $0.10) |
| `mistralai/mistral-small-2603` (Mistral Small 4) | $0.15 | $0.60 |
| `mistralai/mistral-small-3.2-24b-instruct` | $0.075 | $0.20 |
| `x-ai/grok-3-mini` | $0.30 | $0.50 |

> Note: `grok-3-mini` is still listed on OpenRouter but **xAI itself has deprecated it** from their direct-API model list. For direct xAI use, switch to `grok-4-1-fast` ($0.20 / $0.50). The OpenRouter slug may continue to work for some time but expect deprecation.

To see per-upstream-provider variation on a model (e.g., DeepInfra vs. Fireworks routes for Llama 3.1 70B), call `GET https://openrouter.ai/api/v1/models/<slug>/endpoints` rather than the model page.

### 6c. As a benchmarking harness (was used in the §3.5 fast-path)

Add OpenRouter rows to `comparison_providers` and use the admin LLM comparison picker:

```text
# Site Admin → SOLA → comparison_providers
# Format: label | apikey | models | temperature
openrouter-llama8b   | or-XXX | meta-llama/llama-3.1-8b-instruct  | 0.4
openrouter-mistral   | or-XXX | mistralai/mistral-small-2603      | 0.4
openrouter-haiku     | or-XXX | anthropic/claude-haiku-4.5        | 0.4
openrouter-gpt4omini | or-XXX | openai/gpt-4o-mini                | 0.4
openrouter-grok      | or-XXX | x-ai/grok-3-mini                  | 0.4
```

Real cost in week 1 is the 5% BYOK fee — but since we'll be routing through OpenRouter's *own* credit pool for the benchmark (no BYOK), it's the upstream-provider rate with no markup. For a 1-week comparison on a non-production traffic slice (say 5% of total messages mirrored), the marginal spend is in single-digit dollars per provider.

### 6d. As a production egress

OpenRouter's `provider.order` + `provider.allow_fallbacks` would let SOLA implement the §5 per-call failover **inside one API call** by configuring the chain in the request body:

```json
{
  "model": "meta-llama/llama-3.1-8b-instruct",
  "messages": [...],
  "provider": {
    "order": ["DeepInfra", "Together", "Fireworks", "OpenAI"],
    "allow_fallbacks": true,
    "zdr": true,
    "max_price": { "prompt": 0.20, "completion": 0.30 }
  }
}
```

Trade-off:

- **Pro:** single API key, single contract, single billing surface; fallback handled upstream (no client-side circuit breaker needed); ZDR mode is a per-request flag; built-in transparent retry on upstream errors.
- **Pro:** at 0% inference markup + 5% BYOK fee (with 1M free requests/month), the financial cost is genuinely small for the pilot's volume.
- **Con:** all student traffic flows through one third party. If OpenRouter has a control-plane incident, every group goes down at once.
- **Con:** SOC2 Type II attestation is unverified. Until that's contractually nailed down, FERPA-grade student data shouldn't go through them as the sole egress.
- **Con:** stream protocol is re-emitted (not raw passthrough); SOLA's `sse.php` would need to tolerate inline SSE comments and the `native_finish_reason` extra field.

**Recommendation:**

1. **Week 1**: register OpenRouter rows in `comparison_providers` and use it as benchmark harness for the 5-provider A/B.
2. **Week 2-4**: route only non-PII workloads (the validator suite, the §8 golden-tutor eval harness, admin analytics summarization) through OpenRouter.
3. **Pre-production (when SOC2 Type II is confirmed)**: OpenRouter could in principle host a non-PII workload egress (analytics summarization, benchmark runs) with ZDR mode forced on per request. The universal-rollout primary chat / specialty / integrity lanes go direct-to-vendor in rev. 3; promoting OpenRouter into any production lane requires a signed DPA + primary-source SOC 2 Type II report.

### 6e. SOLA code changes to enable OpenRouter use

The existing `classes/provider/openrouter_provider.php` extends `openai_compatible_provider`. To make it production-grade per the verified docs above:

1. Pass-through the `provider` request object — add a `provider_options` constructor parameter and serialize into the request body. ~30 lines.
2. Add `HTTP-Referer: https://learn.saylor.org` and `X-Title: SOLA` headers in the constructor. 2 lines.
3. Surface `usage.prompt_tokens_details.cached_tokens` and `cache_discount` into the analytics row when present. ~10 lines + DB column (separate migration, can wait).
4. Tolerate `native_finish_reason` in the response shape. Probably a no-op given how `openai_compatible_provider` already discards unknown fields, but verify.
5. Verify `sse.php` ignores inline SSE comments (lines starting with `:`). Standard SSE behavior; likely already correct.

Ship as v5.4.7 (patch) or fold into v5.5.0 (with §5 failover).

---

## 7. Reliability + observability — what else to wire

In priority order, beyond §5's failover layer:

1. **Per-provider TTFT histogram**. Today we have `rag_latency_ms` per assistant row. Add `ttft_ms` (provider response → first token) and `total_latency_ms` (request submit → done). Three columns total. Decision-grade signal for which provider is actually winning under real student load.

2. **Per-message provider+model audit**. Already in place via `messages.provider` + `messages.model_name`. Make sure the Learning Radar provider-comparison view filters on the last 7 days only — older runs are misleading after a provider routing change.

3. **Synthetic canary**. A cron-scheduled task that runs a fixed 5-prompt "is the model alive" set against each registered provider every 5 minutes during business hours. Median latency + error rate per provider plotted on a single dashboard panel. Acts as the early warning that something is degrading before students hit it. Estimate: 0.5 day.

4. **SLO dashboard**. Per-provider availability % (request success / request total), median + P95 TTFT, daily cost. Surface in the existing Admin → SOLA → Learning Radar.

5. **Alerting integration**. `audit_logger::log` already records key events. Wire critical events (`failover_fallthrough`, `circuit_open`, `spend_cap_reached`) to the existing email-recipient list with rate-limiting (no more than 1 per hour per event type).

These are 2-3 days of work in total. Worth doing before generalizing the provider mix further.

---

## 8. Accuracy testing — weekly drift-detection protocol

This section was a "propose a real evaluation protocol" proposal in rev. 2. The 50-prompt golden tutor set was built and the harness ran in the §3.5 fast-path; rev. 3 carries it forward as the weekly drift-detection mechanism that catches quality regressions on the production primary.

### 8a. The 50-prompt golden tutor set (built; lives at `tests/golden/tutor_prompts.json`)

Roughly 10 prompts per category, drawn from real BUS101 / ESL001 / CS401 student logs:

- "I don't understand this paragraph" (Socratic explanation)
- "Quiz me on chapter 3" (quiz-mode coach)
- "Give me an example" (illustration)
- "What's the answer to question 5" (anti-cheat redirect)
- "Translate this to Spanish" (multilingual)

Save in `tests/golden/tutor_prompts.json` with the expected behavior (not the expected text — the right behavior). Use the validator-suite pattern that's already in `admin/cli/run_validators.php`.

### 8b. Run weekly against the registered comparison_providers set

`admin/cli/run_tutor_golden.php` (built during the §3.5 fast-path) iterates the golden set × each `comparison_providers` row, captures the response, scores it via:

- A rubric LLM judge: Claude Sonnet 4.6 (the more expensive model is justified — cost is amortized across 50 prompts × N providers / week)
- Three dimensions: (1) Socratic vs. spoonfeeding, (2) factual accuracy, (3) tone match to Saylor's style
- 1–5 score per dimension, weighted average

Output a CSV: provider | model | week | golden_id | category | score_socratic | score_accuracy | score_tone | latency_ms | cost_cents.

### 8c. Run cost: ~$5/week

50 prompts × N providers × ~1000 tokens × $1/MTok input average ≈ $0.30. Plus rubric judge at ~2000 tokens × $5/MTok output × 50 ≈ $0.50. Trivial cost for the resulting drift signal.

### 8d. Drift-detection rule (rev. 3 — replaces the rev. 2 "next semester's primary" rule)

The primary's role is now settled (Gemini 2.5 Flash; see §3.5). The harness's job in rev. 3 is to catch quality drift on the production primary, not to re-decide it.

- **Alert** if the production primary's rubric score drops more than 0.5 points compared to the prior 4-week rolling mean.
- **Trigger a failover-chain review** if two consecutive weekly runs show a >0.5-point drop. The first failover (gpt-4o-mini) becomes the temporary primary while the Gemini regression is investigated.
- **Promotion / demotion** of any TIER 1 provider into or out of the production chain still requires a procurement + DPO review, not just a benchmark win.

Estimate: harness already built; ongoing cost ~$5/week.

---

## 9. Security hardening already shipped

For the record so this doc is self-contained:

- v5.4.5 production security hardening: Dependabot (npm + github-actions), secrets sweep clean, key-scoping documentation per provider in `.wiki/Provider-Key-Scoping.md`, logging audit confirming no prompt/response text in debug logs, emergency-disable CLI + runbook in `.wiki/Security-Incident-Response.md`.
- GDPR Articles 15 + 17 via the privacy provider (`classes/privacy/provider.php`). Tested.
- HMAC-signed unsubscribe tokens (RFC 8058) for email outreach.
- SOC2 Type II posture: audit logging in `classes/audit_logger.php` records `event`, `userid`, `courseid`, `metadata_json`. No PII in metadata.

**Items still open against full SOC2 Type II readiness for the AI inference layer specifically:**

1. **Key rotation evidence**. The `Provider-Key-Scoping.md` runbook says "90-day rotation cadence" but there's no enforcement or audit log proving rotations happened. Add a `local_ai_course_assistant_key_rotation_log` table + admin page showing last rotation per provider. Roughly 0.5 day.

2. **Per-call failover audit** (§5). The fall-through events are themselves SOC2 evidence of operational resilience. Once §5 ships, add a Learning Radar panel that surfaces the count.

3. **Provider DPA signed inventory**. Maintain a `.wiki/Provider-DPAs.md` listing each registered provider, the DPA's effective date, and which Saylor entity signed. Updated whenever a new provider is registered. 0.5 day to set up + ongoing process discipline.

4. **Quarterly access review of API keys**. Already standard practice at Saylor's compliance posture; just make sure SOLA's keys are in the inventory list reviewed each quarter.

---

## 10. Vector DB and embedding provider — Voyage migration

### Vector DB (no change)

Stay on the current MySQL + JSON embedding setup. The pgvector or MySQL 8.0+ `VECTOR` migration trigger is still: any single course exceeds 3,000 chunks OR per-message `rag_latency_ms` P95 sustains over 250ms. v5.4.6 telemetry remains the early-warning signal. No production decision needed today.

### Embedding provider — migrating from OpenAI text-embedding-3-small to Voyage-3.5

Rev. 2 of this doc said "changing providers does not change the vector-DB story" and assumed the OpenAI embedder stayed. **The companion doc reverses that:** the universal-rollout baseline migrates embeddings to **Voyage-3.5** and adds **Voyage rerank-2.5** as a runtime two-stage retrieval upgrade. See companion doc A.7 (embeddings) and A.8 (re-ranker) for the per-component analysis.

Why this is a step-change:

- **Better retrieval** than OpenAI 3-small (+~4 points MTEB) and meaningfully better on multilingual (Voyage trains multilingual from scratch; OpenAI 3-small is English-leaning, a real issue for SOLA's 46-language coverage).
- **4x the context window** (32k vs 8k) — relevant for long PDFs and full lecture transcripts where 8k forced aggressive splitting.
- **TIER 1 compliance** (SOC 2 Type II + GDPR DPA + no training by default; enterprise tier).
- **Voyage rerank-2.5 is 40x cheaper than Cohere Rerank 3.5** for near-equivalent recall lift on educational content.

One-shot migration cost: re-embedding the full 161-course corpus (~80M tokens) on Voyage-3.5 is **$4.80 one-time**. Quarterly re-indexing of half the corpus is under $10. Voyage rerank-2.5 at runtime adds ~$63/mo at 100k Saylor MAU (25% SOLA adoption) — ~6% of chat spend, easily justified by the recall lift.

Implementation: one config change in `sse.php` + `rag_admin.php` to point the embedding lane at Voyage; one new `rag_retriever::rerank()` call between embedding retrieval and chat to plug in Voyage rerank-2.5. Half-day work + a dev validation pass.

Fallback: OpenAI text-embedding-3-small stays registered as a one-flag-flip fallback in case Voyage has reliability issues. Re-indexing on the fallback embedder costs $1.60 for the whole corpus.

### Open-weight upgrade path (deferred)

If Voyage-3.5 multilingual recall on non-English course materials proves weak, the upgrade is self-hosted **Qwen3-Embedding-8B** on a g5.xlarge (~$735/mo all-in for an A10G GPU at sustained utilization). Cost-justifies above ~12M tokens/mo of embedding work; SOLA is well below that today, so it does not pay yet.

---

## 11. Recommended sequencing (rev. 3)

With the benchmark complete and v5.5.0 failover shipped, the remaining work is configuring the universal-rollout chain, migrating embeddings to Voyage, and shipping prompt-caching for the integrity + analytics lanes. Voice-related work is deferred.

| Phase | Work | Why |
|---|---|---|
| Done (v5.5.0, 2026-05-13) | Per-call failover decorator shipped and verified end-to-end on dev.sylr.org | Reliability gap from rev. 1 closed (§5). |
| Done (2026-06-03/04) | Fast-path benchmark executed; companion doc Appendix A documents per-component decisions | §3.5 above; settled the universal-rollout chat tier. |
| Done (2026-05-12) | Capacity plan rate corrections (Together Lite, grok-4-1-fast) | Factually correct; no longer driving the production cost number (companion doc section 4 supersedes). |
| Week 1 | Populate `comparison_providers` + `spend_failover_chain` per §3a (universal-rollout chain); flip `failover_per_call_enabled` on after the chain is configured | Activates both the existing budget-cap failover AND the per-call failover for the named primaries. |
| Week 1 | Confirm Voice tab is disabled in the rollout build: `realtimeenabled` off, header voice button hidden, `tts.php` and STT entry points not surfaced in the learner UI | Baseline ships text-only; see companion doc section 6 operational checklist. |
| Week 2 | Migrate embeddings to Voyage-3.5 (§10): config update in `sse.php` + `rag_admin.php`; one-time re-index of the 161-course corpus (~$4.80) | Companion doc A.7. Better retrieval, 4x context window, multilingual ceiling. Half-day work. |
| Week 2 | Wire Voyage rerank-2.5 as the second stage in `rag_retriever`: top-50 embeddings → rerank to top-5 (§10) | Companion doc A.8. ~$63/mo at 100k Saylor MAU; ~6% of chat spend. Half-day work. |
| Week 3 | Ship §4's prompt-caching wiring for the Anthropic Haiku integrity lane (`cache_control` on system prompt + RAG block) and surface OpenAI `prompt_cache_tokens` on the gpt-4o-mini specialty lanes | Highest-ROI remaining cost work. 5–10% reduction on baseline at scale. |
| Week 3 | Ship §7's synthetic canary + Learning Radar SLO panel | Observability catches issues before students do. |
| Week 4 | Vendor tier-up negotiations (companion doc section 6): Vertex AI Tier 3+, OpenAI Tier 4, Anthropic Tier 3, Voyage enterprise. 2–4 week procurement window | Capacity headroom for 50k → 100k Saylor MAU scale-out. |
| Deferred | Premium escalation tier bake-off (companion doc A.10, ~half day, ~$5 in API spend) | Decides whether SOLA needs a premium tier for hardest tutoring prompts. |
| Deferred | Audio / voice / avatar opt-in (companion doc Appendix B) | Revisit only if post-rollout learner research surfaces clear demand. |

Total remaining engineering: roughly 3 days across 4 weeks. The architectural work (failover, caching, embeddings migration) is well-bounded; the procurement work is the longest pole.

---

## 12. What this doc is NOT doing

- Not changing the v5.9.0 production architecture beyond the explicit migration steps in §11.
- Not promoting any TIER 3 provider to TIER 2 without legal review of their DPA terms. xAI remains TIER 3 and is dropped from the production failover chain in rev. 3.
- Not adopting OpenRouter as the sole production egress; OpenRouter stays as benchmarking harness + non-PII workload egress with ZDR forced on.
- Not enabling the Voice tab / TTS / STT / talking-avatar lanes in the universal rollout. Those analyses live in companion doc Appendix B for a future opt-in.
- Not committing to a vector-DB migration today; the trigger criteria in §10 govern that decision.

---

## Open follow-ups (rev. 3)

### Closed since rev. 2

- [x] **v5.5.0 per-call failover shipped + verified on dev.sylr.org** (2026-05-13). Audit rows for `failover_fallthrough` and `failover_circuit_open` confirmed in live test.
- [x] **Capacity plan patched** with Together Lite rate ($0.10/$0.10) and grok-4-1-fast substitution ($0.20/$0.50). Note: the headline baseline number from the rev. 2 capacity plan ($203/mo) is superseded by the universal-rollout figure of ~$1,370/mo at 100k Saylor MAU under 25% SOLA adoption (companion doc section 4). The rev. 2 capacity plan is now a historical pilot-scale artifact, not the live cost baseline.
- [x] **Fast-path benchmark executed** (2026-06-03 chat + 2026-06-04 domain bake-off). Drove rev. 3's universal pick. See `.drafts/sola-benchmark-decision-2026-06-03.md` and `.drafts/sola-benchmark-domain-routing-2026-06-04.md`.

### Open

- [ ] **Populate `comparison_providers` + `spend_failover_chain`** per §3a (universal-rollout chain). Flip `failover_per_call_enabled` on after the chain is configured.
- [ ] **Migrate embeddings to Voyage-3.5** + wire Voyage rerank-2.5 in `rag_retriever`. §10. ~1 day total.
- [ ] **Ship prompt-caching wiring** for Anthropic Haiku (integrity lane) and surface OpenAI `prompt_cache_tokens` on specialty lanes. §4. Estimated 5–10% baseline cost reduction at scale.
- [ ] **Ops: verify Mistral workspace has training opt-out + ZDR enabled** (ultimate-fallback chat lane). Default is unsafe and still matters under GDPR.
- [ ] **Vendor: tier-up negotiations** — Vertex AI Tier 3+, OpenAI Tier 4, Anthropic Tier 3, Voyage enterprise. 2–4 week procurement window.
- [ ] **For EU rollout**: bridge Claude through Bedrock or Vertex EU region rather than direct Anthropic API (Anthropic direct still doesn't support EU residency).
- [ ] **Update `.wiki/Provider-DPAs.md`** to reflect the rev. 3 baseline vendor list and drop the retired pilot-only vendors. Compliance and DPO sign-off (companion doc section 6).
- [ ] **Confirm Voice tab is disabled** in the universal-rollout build; remove TTS / STT entry points from the learner UI for the rollout cohort.
- [ ] **Run the deferred premium-escalation-tier bake-off** (companion doc A.10, ~half day, ~$5 in API spend) — decides whether SOLA needs a premium tier.
