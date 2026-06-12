# SOLA Talking Avatars: Pricing and Architecture Decision Doc

Date: 2026-06-11. Status: FUTURE DIRECTION, not a current feature and not in the text only baseline. This revives Appendix B.4 of `.drafts/sola-vendor-recommendations-2026-06-09.md` into a standalone decision, with pricing refreshed 2026-06-11 (the market moved since the appendix was written).

## The question

If SOLA ever puts a talking face on the coach, what should it be, what does it cost at our scale, and what is the cheapest way to find out whether learners even want it?

## What changed since the appendix

1. **HeyGen shipped a LiveAvatar Lite tier** at roughly $0.10 per streamed minute (1 credit/min; $475/mo buys 5,000 credits). The appendix had HeyGen at $0.10 to $0.20; Lite makes the low end real and subscription priced. This is now the clear cost leader.
2. **Tavus drifted up**: overage is now $0.37/min, Growth plan effective rate ~$0.32/min ($397/mo for 1,250 min). Still the cleanest compliance posture in the space.
3. **D-ID unchanged and uncompetitive** for streaming ($5.90/min on demand, persistent pricing transparency complaints).
4. **Anam** is a credible new entrant (realtime avatar API, positions against HeyGen and Soul Machines) but publishes no rates; would need a sales conversation. Watch, do not plan around.
5. Soul Machines remains in receivership (2026-02-05). Off the list.

## Architecture options for the SOLA widget

**Option A: animated SOLA avatar, no vendor (the demand probe).** SOLA already has a procedurally generated SVG avatar and an OpenAI TTS pipeline. Add a simple mouth animation driven by TTS audio amplitude (the cartoon lip flap technique) plus idle blinking. No new vendor, no biometric consent, no learner video stream leaving our stack; marginal cost is the TTS we already priced (~$500/mo at 100k MAU if fully enabled, far less at pilot scale). Ships through the normal release pipeline as JS plus CSS only. Estimated effort: a few days in `ui.js` and `speech.js`. Quality is deliberately cartoon, not photoreal, which also sidesteps uncanny valley and likeness rights entirely.

**Option B: live conversational avatar (vendor streamed).** A WebRTC video element in the chat drawer, lip synced in real time. Critically, both HeyGen LiveAvatar and Tavus CVI support bring your own LLM, so Gemini Flash, the SOLA system prompt, RAG, spend guards, and the jailbreak posture all stay ours; the vendor only turns our text into a talking head with their STT/TTS or ours. Integration shape mirrors the existing `realtime.js` voice mode (ephemeral session token endpoint, new AMD module, drawer video container). Estimated effort: 2 to 3 weeks including consent UX, spend guard wiring, and an emergency kill flag.

**Option C: rendered instructor videos (Synthesia).** Not interactive and not the chat widget; produced offline for course intros and summaries. ~$2.90 to $3.00 per finished minute, Personal Avatars included on paid plans, SCORM/LMS features gated to Enterprise. The Bolton College reference (10k+ learners, 400 videos/yr, 80% production time cut) is still the only solid HE case study in the space. This is a content production budget line, not a per MAU cost, and is independent of options A/B.

**Option D: defer (status quo).** Zero cost, zero signal.

## Cost at Saylor scale (options B vendors)

Usage model from the appendix: 25% of MAU use SOLA; 5% of SOLA users try the avatar; ~3 min/user/month.

| Scale | Avatar minutes/mo | HeyGen Lite (~$0.10) | HeyGen Full (~$0.20) | Tavus CVI (~$0.32 to 0.37) | D-ID ($5.90) |
|---|---|---|---|---|---|
| BUS101 A/B pilot (~25 users) | ~75 | $19/mo plan | $19 to 99/mo plan | free tier + Starter | not worth it |
| 50k MAU | ~1,875 | ~$190/mo | ~$375/mo | ~$600 to 690/mo | ~$11,000/mo |
| 100k MAU | ~3,750 | ~$375/mo | ~$750/mo | ~$1,200 to 1,390/mo | ~$22,000/mo |

For context the entire text baseline at 100k MAU is ~$1,370/mo. HeyGen Lite would add about 27% to that; Tavus would roughly double it. The appendix's "85% of baseline" figure was Tavus priced; the HeyGen Lite tier changes the conclusion.

## Compliance and risk

- **Biometric consent:** custom replicas (a real instructor's face) require consent videos at every vendor. Using a stock avatar avoids this entirely; recommended for any pilot.
- **Learner privacy:** option B streams learner audio (and optionally camera) to the vendor. Needs first run consent in the same pattern as the existing Zendesk consent gate, a DPA, and a no training commitment. Tavus has the cleanest documented posture; HeyGen has SOC 2 Type II but the no training and retention terms need confirmation in writing before any learner traffic. Option A sends nothing anywhere new.
- **African language gap is structural and unchanged:** nobody lip syncs Yoruba, Hausa, Igbo, Wolof, Bambara, or Oromo. HeyGen and Synthesia cover Afrikaans, Amharic, Somali, Swahili, Zulu. For uncovered languages the widget falls back to the text/TTS experience, same as today.
- **Vendor churn risk is real** (Soul Machines). Keep any integration behind a provider interface like the existing TTS/STT registries so the vendor is swappable.

## Recommendation: staged, evidence first

1. **Stage 0 (now, ~$0): build option A** as the demand probe. Animated mouth on the existing SVG avatar when TTS plays, behind a default off setting (`avatar_animation_enabled`, policy bundle eligible). Measure: TTS usage rate, session length, repeat usage on a course with it on vs off. If learners do not engage with a talking cartoon coach, they will not pay attention to a $0.32/min photoreal one, and we will have found out for free.
2. **Stage 1 (only on Stage 0 signal): BUS101 A/B with HeyGen LiveAvatar Lite**, stock avatar, bring your own LLM so the brain stays Gemini + RAG. Budget ceiling $99/mo. Measure 90 day completion vs control, per the appendix. Tavus CVI is the fallback if HeyGen quality or terms disappoint.
3. **Stage 2 (only on completion lift): fleet enable** at HeyGen Lite economics (~$375/mo at 100k MAU), with the provider interface keeping Tavus and Anam as alternates.
4. **Synthesia is a separate decision** about course content production, not the widget; revisit if faculty ask for instructor intro videos.

## Sources

Refreshed 2026-06-11: [Tavus pricing](https://www.tavus.io/pricing), [Tavus CVI](https://www.tavus.io/cvi), [HeyGen LiveAvatar pricing explained](https://help.heygen.com/en/articles/10060327-heygen-api-liveavatar-pricing-subscriptions-explained), [HeyGen LiveAvatar FAQ](https://help.heygen.com/en/articles/12758866-liveavatar-faq), [Synthesia pricing](https://www.synthesia.io/pricing), [D-ID API pricing](https://www.d-id.com/pricing/api/), [Anam pricing](https://anam.ai/pricing) (rates not published). Baseline analysis: `.drafts/sola-vendor-recommendations-2026-06-09.md` Appendix B.4 and its Appendix C source list.
