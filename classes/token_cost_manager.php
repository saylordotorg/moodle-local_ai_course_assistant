<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_ai_course_assistant;

/**
 * Token cost manager — rate cards and cost estimation.
 *
 * Rates are USD per 1,000,000 tokens (industry standard as of early 2026).
 * Model strings are matched by prefix (longest match wins) so new dated
 * model variants (e.g. gpt-4o-2024-11-20) are covered automatically.
 *
 * v7.4.0: the table below is now only the BASELINE layer. Resolution happens in
 * {@see model_registry}, which merges this baseline with the legacy
 * `rate_card_overrides` blob and then with the admin-editable
 * local_ai_course_assistant_models table. Correcting a price no longer needs a
 * code edit — add or edit a registry row. Edit this table only when shipping a
 * release-time correction for every site.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_cost_manager {
    /**
     * Rate card: USD per 1,000,000 tokens.
     * Keys are model prefix strings matched with str_starts_with.
     * Values: ['input' => float, 'output' => float].
     *
     * Prices sourced from provider pricing pages (March 2026).
     */
    private static array $rate_cards = [
        // ── OpenAI Chat ───────────────────────────────────────────────────────
        'gpt-4o-mini'       => ['input' => 0.15, 'output' => 0.60],
        'gpt-4o'            => ['input' => 2.50, 'output' => 10.00],
        'gpt-4-turbo'       => ['input' => 10.00, 'output' => 30.00],
        'gpt-4'             => ['input' => 30.00, 'output' => 60.00],
        'gpt-3.5-turbo'     => ['input' => 0.50, 'output' => 1.50],
        'o1-mini'           => ['input' => 3.00, 'output' => 12.00],
        'o1-preview'        => ['input' => 15.00, 'output' => 60.00],
        'o1'                => ['input' => 15.00, 'output' => 60.00],
        'o3-mini'           => ['input' => 1.10, 'output' => 4.40],
        'o3'                => ['input' => 10.00, 'output' => 40.00],

        // ── OpenAI Realtime (voice) ─────────────────────────────────────────
        // v8.1.1: every audio model below carries an AUDIO marker in its name
        // (tts, audio, realtime, live, transcribe, whisper, stt, speech), and
        // model_registry only lets such a name match a key that is itself an audio
        // key (see is_audio_model()). Before that, gemini-2.5-flash-preview-tts
        // matched the gemini-2.5-flash chat card and gpt-4o-mini-tts matched the
        // gpt-4o-mini chat card, so speech was priced as chat text.
        //
        // A rate card row holds one input and one output rate, and a realtime
        // or live session mixes audio and text both ways. Every realtime/live row
        // below therefore carries the AUDIO rates, which are the dearer pair, so
        // text turns priced through it are over-counted rather than under-counted.
        // The text rates are recorded in the comments for the day a usage record
        // separates them.
        //
        // gpt-4o-realtime: the price SOLA has always used. It is also the name
        // sse.php's log-only realtime logger writes on EVERY realtime row,
        // whatever model the session really used, with duration x 50 written as
        // prompt tokens and no completion tokens. estimate_cost() prices such a
        // prompt-only realtime row at the OUTPUT rate (see
        // UNSEPARATED_DUPLEX_SEGMENTS), so it is no longer priced as text input.
        'gpt-4o-realtime'   => ['input' => 5.00, 'output' => 20.00],
        // OpenAI pricing page (developers.openai.com/api/docs/pricing), fetched
        // 2026-11-21, USD per 1M tokens. Audio in $10 (cached $0.30), audio out
        // $20; text in $0.60 (cached $0.06), text out $2.40.
        'gpt-realtime-2.1-mini' => ['input' => 10.00, 'output' => 20.00],
        'gpt-realtime-mini' => ['input' => 10.00, 'output' => 20.00],
        // Same page, same date. Audio in $32 (cached $0.40), audio out $64; text
        // in $4 (cached $0.40), text out $24 for 2.1 and 2, $16 for 1.5 and the
        // original gpt-realtime. The bare 'gpt-realtime' key is also the catch-all
        // for a future gpt-realtime-N: priced at the full model's audio rate,
        // which over-counts a cheaper newcomer rather than under-counting it.
        'gpt-realtime-2.1'  => ['input' => 32.00, 'output' => 64.00],
        'gpt-realtime-2'    => ['input' => 32.00, 'output' => 64.00],
        'gpt-realtime-1.5'  => ['input' => 32.00, 'output' => 64.00],
        'gpt-realtime'      => ['input' => 32.00, 'output' => 64.00],
        // NOT priced: gpt-live-1.1 and gpt-live-1. They bill per minute of
        // session ($0.05 active, $0.02 standby, per second) and no usage record
        // in SOLA says how long a session was active versus on standby, so they
        // stay unpriced and show in the unpriced-models banner if used.

        // ── OpenAI TTS ──────────────────────────────────────────────────────
        // TTS-1 charges per character. Approximated as per-token at ~4
        // chars/token for consistency with the token-based rate card (tts.php
        // logs ceil(chars / 4) as prompt tokens for OpenAI). OpenAI pricing page,
        // 2026-11-21: tts-1 $15 per 1M characters (= $60 per 1M of these
        // tokens), tts-1-hd $30 per 1M characters (= $120).
        'tts-1'             => ['input' => 60.00, 'output' => 0.00],
        'tts-1-hd'          => ['input' => 120.00, 'output' => 0.00],
        // OpenAI pricing page, 2026-11-21: text in $0.60, audio out $12.00 per
        // 1M tokens. OpenAI's speech endpoint returns bare audio with no usage
        // block, so tts.php records only the text estimate and no audio tokens;
        // estimate_cost() then adds a conservative audio estimate (see
        // AUDIO_TOKENS_PER_TEXT_TOKEN) instead of pricing the text alone.
        'gpt-4o-mini-tts'   => ['input' => 0.60, 'output' => 12.00],

        // ── OpenAI Embeddings ───────────────────────────────────────────────
        'text-embedding-3-small' => ['input' => 0.02, 'output' => 0.00],
        'text-embedding-3-large' => ['input' => 0.13, 'output' => 0.00],
        'text-embedding-ada'     => ['input' => 0.10, 'output' => 0.00],

        // ── Voyage AI embeddings + rerankers ────────────────────────────────
        // Rates from docs.voyageai.com/docs/pricing (2026-08-04), USD per 1M
        // tokens. Embeddings and rerankers are input-only; output stays 0.00.
        // Without these, RAG spend reported estimated_cost_usd = null while the
        // token counts were right, so indexing and rerank cost was invisible.
        // Rerank billing counts query + document tokens, which is exactly what
        // voyage_reranker::log_rerank_cost() records, so the input rate applies
        // to the logged total.
        // Prefix matching is longest-wins, so the -lite and -large variants must
        // stay as their own keys; they are cheaper/dearer than the base model and
        // would otherwise inherit the shorter prefix's rate.
        // Deliberately NO bare 'voyage' or 'rerank' catch-all: an unrecognized
        // future model should return null (unknown) rather than be priced at a
        // guessed rate. Admins can still add one as a model_registry row
        // without a code change.
        'voyage-4-large'         => ['input' => 0.12, 'output' => 0.00],
        'voyage-4-lite'          => ['input' => 0.02, 'output' => 0.00],
        'voyage-4'               => ['input' => 0.06, 'output' => 0.00],
        'voyage-context-4'       => ['input' => 0.12, 'output' => 0.00],
        // v7.0.3: was missing, so a site using it logged null cost while the
        // token counts were right -- the same invisibility this block exists to
        // prevent. Not relevant to course content, but a gap is a gap.
        'voyage-code-4'          => ['input' => 0.12, 'output' => 0.00],
        'voyage-3.5-lite'        => ['input' => 0.02, 'output' => 0.00],
        'voyage-3.5'             => ['input' => 0.06, 'output' => 0.00],
        'voyage-3-large'         => ['input' => 0.18, 'output' => 0.00],
        'voyage-code-3'          => ['input' => 0.18, 'output' => 0.00],
        'voyage-multimodal-3.5'  => ['input' => 0.12, 'output' => 0.00],
        'voyage-multimodal-3'    => ['input' => 0.12, 'output' => 0.00],
        'voyage-finance-2'       => ['input' => 0.12, 'output' => 0.00],
        'voyage-law-2'           => ['input' => 0.12, 'output' => 0.00],
        'rerank-2.5-lite'        => ['input' => 0.02, 'output' => 0.00],
        'rerank-2.5'             => ['input' => 0.05, 'output' => 0.00],
        'rerank-2-lite'          => ['input' => 0.02, 'output' => 0.00],
        'rerank-2'               => ['input' => 0.05, 'output' => 0.00],

        // ── OpenAI Whisper (transcription) ──────────────────────────────────
        // Whisper charges ~$0.006/min. Approximated per token for the rate card.
        // $6.00/1M tokens x the 1000-tokens-per-minute convention used by the
        // STT telemetry writers = $0.006/min, OpenAI's published Whisper rate.
        // The old 0.36 was the PER-HOUR price entered as a per-1M-token rate,
        // underpricing STT by a factor of 16.7.
        // Confirmed on the OpenAI pricing page 2026-11-21: whisper-1 $0.006/min.
        'whisper'           => ['input' => 6.00, 'output' => 0.00],
        // The newer transcription models, on the same 1000-tokens-per-minute
        // convention, so $X per minute is $X * 1000 per 1M tokens. OpenAI
        // pricing page, 2026-11-21; the page labels these per-minute figures
        // "estimated" (the models bill by token underneath), and the two gpt-4o
        // ones are given as "about". gpt-transcribe $0.0045/min,
        // gpt-live-transcribe $0.017/min, gpt-4o-mini-transcribe ~$0.003/min,
        // gpt-4o-transcribe ~$0.006/min. Without their own keys the two gpt-4o
        // names matched the gpt-4o-mini and gpt-4o chat cards.
        'gpt-transcribe'         => ['input' => 4.50, 'output' => 0.00],
        'gpt-live-transcribe'    => ['input' => 17.00, 'output' => 0.00],
        'gpt-4o-mini-transcribe' => ['input' => 3.00, 'output' => 0.00],
        'gpt-4o-transcribe'      => ['input' => 6.00, 'output' => 0.00],

        // ── Self-hosted inference (free at the API boundary) ────────────────
        // v7.4.4: transcribe.php prefixes a self-hosted STT model with
        // 'selfhosted-' so it cannot match the hosted 'whisper' rate above.
        // That name has to resolve to a rate of ZERO rather than to NO rate.
        // "No rate" is model_registry::unpriced_models()'s definition of a
        // defect, so a site running its own Whisper box was raising a daily
        // model_price_drift_check MISSING alert -- and a standing false alarm
        // is how the next genuinely unpriced model goes unread, which is the
        // exact failure the eol_surface gate exists to prevent.
        // Zero is also the true price: the call never leaves the institution.
        'selfhosted-'       => ['input' => 0.00, 'output' => 0.00],

        // ── Anthropic Claude ──────────────────────────────────────────────────
        // Corrected in v7.4.0: this block was a full generation stale. Opus was
        // listed at 15.00/75.00 and Haiku at 0.80/4.00 — the Claude 3/4-era
        // prices — so every premium-router escalation to claude-sonnet-5 and
        // every anti-cheat reference call to claude-haiku-4-5 was priced against
        // the wrong card. The bare-family prefixes now carry the CURRENT
        // generation's price, on the reasoning that an unrecognized future dated
        // variant is better priced at today's rate than at a rate three years
        // old; the explicitly named models below win on longest-prefix anyway.
        // Corrected again 2026-09-09: sonnet-5 was still carrying Sonnet 4.6's
        // 3.00/15.00. Verified 2.00/10.00 against claude.com/pricing that day.
        // The bare 'claude-sonnet' prefix deliberately stays at 3.00/15.00: it is
        // the fallback for sonnet-4/4-5/4-6, which really are that price, and
        // longest-prefix means no claude-sonnet-5* string ever reaches it.
        // claude-haiku-5-5 is $0.10/$0.50 for prompts up to 100k tokens (verified
        // 2026-10-08), a tenth of Haiku 4.5. With no row it fell through to the
        // bare 'claude-haiku' $1/$5 and priced every call 10x too high. Its price is
        // also below the auto-upgrade's 0.2x band against a Haiku 4.5 incumbent, so
        // discovery still offers it only to a role whose model is in that price range.
        // The long-prompt tier is not modelled; SOLA prompts sit far below 100k.
        'claude-haiku-5-5'  => ['input' => 0.10, 'output' => 0.50],
        'claude-haiku-4-5'  => ['input' => 1.00, 'output' => 5.00],
        'claude-haiku'      => ['input' => 1.00, 'output' => 5.00],
        'claude-sonnet-5'   => ['input' => 2.00, 'output' => 10.00],
        'claude-sonnet'     => ['input' => 3.00, 'output' => 15.00],
        // Opus 5.5 undercuts Opus 5 ($4/$20 against $5/$25), so without its own
        // row longest-prefix resolves it through 'claude-opus-5' and overprices
        // every call by 25%. Sonnet 5.5 needs no row: it is the same $2/$10 as
        // 'claude-sonnet-5', which already matches it.
        'claude-opus-5-5'   => ['input' => 4.00, 'output' => 20.00],
        'claude-opus-5'     => ['input' => 5.00, 'output' => 25.00],
        'claude-opus'       => ['input' => 5.00, 'output' => 25.00],

        // ── DeepSeek ──────────────────────────────────────────────────────────
        'deepseek-chat'     => ['input' => 0.14, 'output' => 0.28],
        'deepseek-reasoner' => ['input' => 0.55, 'output' => 2.19],

        // ── Google Gemini ─────────────────────────────────────────────────────
        // gemini-2.5-flash is SOLA's PRODUCTION chat tutor, and until v7.4.0 it
        // matched no prefix in this table at all. get_rates() returned null,
        // estimate_cost() returned null, and every consumer treats null as
        // "skip" rather than as an error — so 100% of production chat spend
        // computed as $0.00. The spend caps, the anomaly detector and the
        // dashboards all read zero and all agreed with each other, which is why
        // it survived a release. tests/model_registry_test.php now pins these
        // two numbers for exactly that reason.
        // Rates from ai.google.dev/gemini-api/docs/pricing (fetched 2026-09-08),
        // USD per 1M tokens, paid tier. The 0.30 input rate is text/image/video;
        // audio input is 1.00 and is NOT modeled here (SOLA sends audio through
        // Whisper/Realtime, not through the Gemini chat path).
        //
        // Thinking tokens bill AT the 2.50 output rate, so there is no separate
        // reasoning line in this table. That is a statement about the RATE, not
        // about the token COUNT, and v7.4.2 corrects what this comment used to
        // imply: Google's OpenAI-compatibility shim does not reliably include
        // thinking in the completion_tokens it reports, so pricing
        // completion_tokens alone under-charges every thinking call. The count
        // is carried separately in msgs.reasoning_tokens and added to output
        // for these prefixes by estimate_cost(); see
        // REASONING_OUTSIDE_COMPLETION_PREFIXES.
        'gemini-2.5-flash-lite' => ['input' => 0.10, 'output' => 0.40],
        'gemini-2.5-flash'  => ['input' => 0.30, 'output' => 2.50],
        'gemini-2.0-flash'  => ['input' => 0.10, 'output' => 0.40],
        'gemini-1.5-flash'  => ['input' => 0.075, 'output' => 0.30],
        'gemini-1.5-pro'    => ['input' => 1.25, 'output' => 5.00],
        'gemini-pro'        => ['input' => 0.50, 'output' => 1.50],

        // ── Google Gemini speech (TTS and Live) ───────────────────────────────
        // Google pricing page, 2026-11-21 (aistudio.google.com/docs/pricing),
        // USD per 1M tokens. Audio is 25 tokens per second.
        //
        // TTS: prompt tokens are the TEXT sent (input rate) and completion
        // tokens are the AUDIO returned (output rate); gemini_tts records
        // Google's own promptTokenCount and candidatesTokenCount in exactly those
        // two columns, so each side is priced at its own rate.
        // The page lists a 2027 price step for both TTS models (text $1.00, audio
        // $18.00 and $12.00); it says only "2027", and the other Gemini 3.x rows
        // on the same page step up on January 1, 2027, so that date is used. See
        // SCHEDULED_RATE_CHANGES below.
        'gemini-3.8-flash-tts'      => ['input' => 0.50, 'output' => 9.00],
        'gemini-3.8-flash-lite-tts' => ['input' => 0.50, 'output' => 6.00],
        // Live: audio in $3.00 (about $0.005/min), audio out $12.00 (about
        // $0.018/min); text in $0.75, text out $4.50; image or video in $1.00.
        // Audio rates carried, as for OpenAI Realtime above. No 2027 change listed.
        'gemini-3.8-live'                   => ['input' => 3.00, 'output' => 12.00],
        'gemini-3.8-live-extended-thinking' => ['input' => 3.00, 'output' => 12.00],
        'gemini-3.1-flash-live-preview'     => ['input' => 3.00, 'output' => 12.00],
        // NOT priced: gemini-2.5-flash-preview-tts, gemini-2.5-pro-preview-tts,
        // gemini-3.1-flash-tts-preview and gemini-2.5-flash-native-audio-*.
        // They retire on 2026-12-18 and 2027-03-31, no official price was
        // collected for them, and they no longer fall through to a chat card, so
        // they stay unpriced and show in the unpriced-models banner if used.

        // ── Mistral AI ────────────────────────────────────────────────────────
        'mistral-large'     => ['input' => 2.00, 'output' => 6.00],
        'mistral-medium'    => ['input' => 2.70, 'output' => 8.10],
        'mistral-small'     => ['input' => 0.20, 'output' => 0.60],
        'open-mistral'      => ['input' => 0.25, 'output' => 0.25],
        'open-mixtral'      => ['input' => 0.65, 'output' => 0.65],
        'codestral'         => ['input' => 0.30, 'output' => 0.90],

        // ── Together AI (open-weight models, OpenAI-compatible API) ──────────
        // Together's Serverless Inference tier. Llama 3.1 8B Instruct Turbo
        // is the FP8-quantized speed-optimized variant Saylor runs in prod.
        // Keys are lowercased because get_rates() lowercases the model name
        // before doing the str_starts_with match (longer prefixes win).
        'meta-llama/llama-3.1-8b-instruct-turbo'   => ['input' => 0.18, 'output' => 0.18],
        'meta-llama/llama-3.1-70b-instruct-turbo'  => ['input' => 0.88, 'output' => 0.88],
        'meta-llama/llama-3.1-405b-instruct-turbo' => ['input' => 3.50, 'output' => 3.50],
        // Together Serverless "Lite" tier — Llama 3 8B Instruct Lite. Live rate
        // from Together /v1/models pricing endpoint (2026-06-03): $0.14/M flat.
        'meta-llama/meta-llama-3-8b-instruct'      => ['input' => 0.14, 'output' => 0.14],

        // ── OpenRouter (routed open-weight) ───────────────────────────────────
        // Non-turbo llama-3.1-8b-instruct via OpenRouter default routing. Live
        // rate from OpenRouter /api/v1/models (2026-06-03): $0.02 in / $0.05 out.
        // Re-read from the same endpoint 2026-10-08: now $0.05 in / $0.08 out.
        'meta-llama/llama-3.1-8b-instruct'         => ['input' => 0.05, 'output' => 0.08],
        // gpt-oss-120b as Together serves it: $0.15 in / $0.60 out (together.ai/pricing,
        // 2026-10-08). Thinking is inside completion tokens. It had no row at all.
        'openai/gpt-oss-120b'                      => ['input' => 0.15, 'output' => 0.60],

        // ── Groq (open-source models) ─────────────────────────────────────────
        // Groq charges vary by model; these are approximate hosted rates.
        'llama-3.3-70b'     => ['input' => 0.59, 'output' => 0.79],
        'llama-3.1-70b'     => ['input' => 0.59, 'output' => 0.79],
        'llama-3.1-8b'      => ['input' => 0.05, 'output' => 0.08],
        'llama-3-70b'       => ['input' => 0.59, 'output' => 0.79],
        'llama-3-8b'        => ['input' => 0.05, 'output' => 0.08],
        'mixtral-8x7b'      => ['input' => 0.24, 'output' => 0.24],
        'gemma2-9b'         => ['input' => 0.20, 'output' => 0.20],

        // ── xAI (Grok) ───────────────────────────────────────────────────────
        // Live rates from xAI /v1/language-models + docs.x.ai (2026-06-03).
        // NOTE: the API silently aliases the (now-retired) name `grok-4-1-fast`
        // to grok-4.3, so it bills at grok-4.3 rates ($1.25/$2.50), NOT a cheap
        // "fast" tier. Benchmark "xai" rows actually ran grok-4.3.
        'grok-4.3'          => ['input' => 1.25, 'output' => 2.50],
        'grok-4.20'         => ['input' => 1.25, 'output' => 2.50],
        'grok-4-1-fast'     => ['input' => 1.25, 'output' => 2.50],
        'grok-4'            => ['input' => 1.25, 'output' => 2.50],
        'grok-3'            => ['input' => 3.00, 'output' => 15.00],
        'grok-3-mini'       => ['input' => 0.30, 'output' => 0.50],
        'grok-2'            => ['input' => 2.00, 'output' => 10.00],

        // ── MiniMax ───────────────────────────────────────────────────────────
        'abab5.5'           => ['input' => 0.50, 'output' => 0.50],
        'abab6.5'           => ['input' => 1.00, 'output' => 1.00],
    ];

    /**
     * Dated price steps for baseline keys, applied by {@see baseline_rate_cards()}.
     *
     * v8.1.1. key => list of ['from' => unix time (UTC), 'input' => float,
     * 'output' => float], oldest first; the last step whose 'from' has passed
     * replaces the key's rates in the baseline layer. Only the baseline layer is
     * stepped: a registry row an admin typed for the same key wins as always,
     * and it is then the admin's job to update it.
     *
     * The clock is "now" ({@see now()}), not the time of the usage row, because
     * every spend consumer prices SUMS of tokens grouped by model and never sees
     * a row's date. Consequence, chosen on purpose: once a step passes, usage
     * recorded before it is re-priced at the new (higher) rate wherever it is
     * summed again, for example a 30-day dashboard read in early January. That
     * over-counts history and never under-counts current spend, which is the
     * safe direction for spend caps. Monthly cap periods start on the 1st, so a
     * January cap sees January rows only and is exact.
     *
     * Google pricing page, 2026-11-21: "2027" prices for the two Gemini TTS
     * models. The page gives no day; the other Gemini 3.x rows on the same page
     * step up on January 1, 2027, so 2027-01-01 00:00 UTC (1798761600) is used.
     *
     * @var array<string, array<int, array{from: int, input: float, output: float}>>
     */
    private const SCHEDULED_RATE_CHANGES = [
        'gemini-3.8-flash-tts' => [
            ['from' => 1798761600, 'input' => 1.00, 'output' => 18.00],
        ],
        'gemini-3.8-flash-lite-tts' => [
            ['from' => 1798761600, 'input' => 1.00, 'output' => 12.00],
        ],
    ];

    /**
     * Name segments that mark a model as an audio model (speech in or out).
     *
     * v8.1.1. A model name is split on anything that is not a letter, digit or
     * dot, and any segment in this list makes it an audio model. Segments, not
     * substrings, so a word that merely contains one (an "olive" model) is not
     * caught. {@see model_registry} refuses to price an audio model through a
     * key that is not itself an audio key, so a speech model can never inherit
     * a chat family's text price again (the 8.1.0 known bug:
     * gemini-2.5-flash-preview-tts priced as gemini-2.5-flash chat text).
     *
     * @var string[]
     */
    public const AUDIO_NAME_SEGMENTS = ['tts', 'audio', 'realtime', 'live', 'transcribe', 'whisper', 'stt', 'speech'];

    /**
     * Baseline keys that are namespaces rather than model families.
     *
     * An audio model may match these even though they carry no audio marker:
     * 'selfhosted-' prices any self-hosted model, Whisper included, at zero.
     *
     * @var string[]
     */
    public const NAMESPACE_PREFIXES = ['selfhosted-'];

    /** @var string[] Segments of a text-in, audio-out (TTS) model name. */
    private const UNSEPARATED_TTS_SEGMENTS = ['tts'];

    /**
     * Segments of a speech-both-ways (realtime or live session) model name.
     *
     * Checked after the transcription segments, so gpt-live-transcribe is a
     * transcription model and not a live session.
     *
     * @var string[]
     */
    private const UNSEPARATED_DUPLEX_SEGMENTS = ['realtime', 'live'];

    /** @var string[] Segments of a speech-to-text model name: input only, nothing to estimate. */
    private const TRANSCRIPTION_SEGMENTS = ['transcribe', 'whisper', 'stt'];

    /**
     * Estimated audio tokens per text token, for a TTS usage record with no audio count.
     *
     * v8.1.1. OpenAI's speech endpoint returns bare audio, so tts.php can record
     * only ceil(characters / 4) as prompt tokens and nothing as output; pricing
     * that alone would bill gpt-4o-mini-tts at its $0.60 text rate and leave out
     * the $12.00 audio, which is nearly all of the cost. Gemini usually reports
     * both counts, but tts.php writes 0 output when it doesn't.
     *
     * Derivation, kept deliberately high: speech runs about 12 to 15 characters
     * a second (120 to 150 words a minute), which is 3 to 3.75 of these text
     * tokens a second. Gemini audio is 25 tokens a second (Google pricing page,
     * 2026-11-21), so 6.7 to 8.3 audio tokens per text token, and 10 is above
     * that range. OpenAI does not state gpt-4o-mini-tts audio tokens per second
     * in the figures collected on 2026-11-21, so the same 10 is assumed for it
     * (not verified). Applied only when the
     * record has prompt tokens, NO completion tokens, and the model's output
     * rate is above zero (tts-1 and tts-1-hd are priced per character and stay
     * as they are). Spend queries price per-model SUMS, so a model whose rows
     * are mixed (some with an audio count, some without) is priced as recorded;
     * that mix only arises for Gemini when Google omits a count.
     */
    public const AUDIO_TOKENS_PER_TEXT_TOKEN = 10;

    /** @var int|null Fixed clock for tests; null means the real time. */
    private static ?int $clockfortesting = null;

    /**
     * Model prefixes whose reported completion_tokens EXCLUDES thinking tokens.
     *
     * v7.4.2. The msgs table stores reasoning_tokens exactly as the provider
     * reported it and never folds it into completion_tokens, because whether
     * thinking is already inside that number is vendor-specific. This list is
     * where that vendor knowledge lives, and it is the ONLY place: both the
     * PHP pricing path ({@see estimate_cost}) and the SQL aggregate path
     * ({@see extra_output_tokens_sql}) derive from it, so they cannot drift.
     *
     * OpenAI (o-series, gpt-5) counts reasoning INSIDE completion_tokens, so
     * those models are deliberately absent: adding reasoning for them would
     * double-charge every call. Google's Gemini OpenAI-compatibility shim has
     * not reliably done so while still billing thinking at the output rate,
     * which is why gemini is here. A model absent from this list prices as
     * completion_tokens alone, which is the safe default for any provider
     * whose behaviour has not been confirmed.
     *
     * v7.8.0: the list itself lives in model_capabilities, beside every other
     * fact about how a model's request and usage are shaped; this constant
     * names it for the two pricing paths here.
     *
     * @var string[]
     */
    private const REASONING_OUTSIDE_COMPLETION_PREFIXES = model_capabilities::BILLED_OUTSIDE_COMPLETION_PREFIXES;

    /**
     * Marker written onto model_name when a call was served by an offline
     * batch tier: `batch/gpt-4o-mini`.
     *
     * v7.4.4. The Learning Radar's scheduled runs go through the OpenAI Batch
     * API, which is half price on input AND output. Something has to carry that
     * fact from the writer to the twelve places that price a row, and the
     * candidates were: a new column on msgs (a schema change every pricing
     * query would then have to remember to read), a new interaction_type (which
     * would have to be added to spend_rows_predicate AND to
     * spend_guard::capability_sql('analytics'), and would split the Radar's own
     * history in two), or a marker on the model string.
     *
     * The model string wins because pricing ALREADY funnels through exactly one
     * function that reads it -- {@see model_registry::rate_for()} -- so the
     * discount is applied once and every consumer inherits it with no change:
     * the AI Spend dashboard, spend_guard's cap accounting, the cost anomaly
     * detector, llm_optimizer, token_analytics, the CSV export and the
     * price-drift check. It also survives in the row forever, so a
     * reconciliation against the invoice six months later can still tell a
     * batched call from a synchronous one, which a discount applied at write
     * time and baked into a number could not.
     *
     * A trailing slash is deliberate: model prefixes are matched with
     * str_starts_with and longest-prefix-wins, so a separator that appears in no
     * vendor model id guarantees `batch/gpt-4o` can never accidentally match a
     * rate card key, and `gpt-4o` can never match this one.
     */
    public const BATCH_MODEL_PREFIX = 'batch/';

    /**
     * Fraction of list price an offline batch call is charged at.
     *
     * OpenAI and Google both discount their batch tiers by 50% on input and on
     * output. One number, used by {@see model_registry::rate_for()}.
     */
    public const BATCH_DISCOUNT = 0.5;

    /**
     * Mark a model string as having been served by the batch tier.
     *
     * Idempotent: marking an already-marked name returns it unchanged, so a
     * caller cannot produce `batch/batch/gpt-4o` and quietly halve twice.
     *
     * @param string $modelname
     * @return string
     */
    public static function batch_model_name(string $modelname): string {
        $modelname = trim($modelname);
        if ($modelname === '') {
            return $modelname;
        }
        return self::is_batch_model($modelname) ? $modelname : self::BATCH_MODEL_PREFIX . $modelname;
    }

    /**
     * Was this row's call served by the batch tier?
     *
     * @param string $modelname
     * @return bool
     */
    public static function is_batch_model(string $modelname): bool {
        return strpos(strtolower(trim($modelname)), self::BATCH_MODEL_PREFIX) === 0;
    }

    /**
     * The underlying model string, with any batch marker removed.
     *
     * @param string $modelname
     * @return string
     */
    public static function strip_batch_prefix(string $modelname): string {
        $modelname = trim($modelname);
        if (!self::is_batch_model($modelname)) {
            return $modelname;
        }
        return substr($modelname, strlen(self::BATCH_MODEL_PREFIX));
    }

    /**
     * Does this model bill thinking as output ON TOP OF completion_tokens?
     *
     * The batch marker is stripped first. `batch/gemini-2.5-flash` is still
     * Gemini and still bills thinking as output; without the strip it would
     * match no prefix, and the batched Learning Radar rows would be the ONE
     * place the v7.4.2 reasoning fix silently stopped applying.
     *
     * @param string $modelname Exact model string as recorded on the row.
     * @return bool True when reasoning_tokens must be ADDED to completion_tokens to price the call.
     */
    public static function reasoning_billed_as_extra_output(string $modelname): bool {
        $model = strtolower(self::strip_batch_prefix($modelname));
        foreach (self::REASONING_OUTSIDE_COMPLETION_PREFIXES as $prefix) {
            if (strpos($model, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * SQL expression for the reasoning tokens that must be ADDED to
     * completion_tokens for a row, or 0 when the provider already counted them.
     *
     * For aggregate queries that never see a model name in PHP (
     * {@see analytics::get_total_tokens}). Queries that group by model and
     * price in a PHP loop should sum reasoning_tokens plainly and pass it to
     * {@see estimate_cost}, which applies the same rule.
     *
     * @param string $alias Table alias for the msgs table.
     * @return string SQL scalar expression, already parenthesised.
     */
    public static function extra_output_tokens_sql(string $alias = 'm'): string {
        $whens = [];
        foreach (self::REASONING_OUTSIDE_COMPLETION_PREFIXES as $prefix) {
            // The prefixes are class constants, not user input, so there is
            // nothing here to parameterise; they contain no LIKE wildcards.
            $whens[] = "LOWER({$alias}.model_name) LIKE '" . $prefix . "%'";
            // v7.4.4: and the same model served by the batch tier, whose rows
            // carry the BATCH_MODEL_PREFIX marker. This is the SQL half of the
            // strip that reasoning_billed_as_extra_output() does in PHP; the two
            // must agree or get_total_tokens() and estimate_cost() disagree about
            // the same row, which is exactly the drift this method exists to stop.
            $whens[] = "LOWER({$alias}.model_name) LIKE '" . self::BATCH_MODEL_PREFIX . $prefix . "%'";
        }
        $condition = implode(' OR ', $whens);
        return "(CASE WHEN {$condition} THEN COALESCE({$alias}.reasoning_tokens, 0) ELSE 0 END)";
    }

    /**
     * Estimate the cost of a single API call in USD.
     *
     * Returns null if the model is not in the rate card (e.g. Ollama local models).
     *
     * v7.4.2 takes reasoning tokens. Passing them is what makes the figure
     * match the invoice on a thinking model: the reconciliation that prompted
     * this measured 0.35M completion tokens logged against 1.78M billed, and
     * the missing ~1.4M was thinking that Gemini bills at the output rate but
     * does not report inside completion_tokens. They are added to output ONLY
     * for models in REASONING_OUTSIDE_COMPLETION_PREFIXES, so an OpenAI call —
     * where the count is already inside completion_tokens — is not charged
     * twice. Defaulting to 0 keeps every existing two-token caller correct.
     *
     * @param string $modelname  Exact model string from the API response.
     * @param int    $prompttokens
     * @param int    $completiontokens
     * @param int    $reasoningtokens Thinking tokens as reported; 0 when none/unknown.
     * @return float|null  Cost in USD, or null if model is not known.
     */
    public static function estimate_cost(
        string $modelname,
        int $prompttokens,
        int $completiontokens,
        int $reasoningtokens = 0
    ): ?float {
        $rates = self::get_rates($modelname);
        if ($rates === null) {
            return null;
        }
        $billableoutput = $completiontokens;
        if ($reasoningtokens > 0 && self::reasoning_billed_as_extra_output($modelname)) {
            $billableoutput += $reasoningtokens;
        }
        $inputrate = (float) $rates['input'];
        // v8.1.1: an audio usage record that does not separate its two sides.
        // See audio_usage_kind(); both branches over-count rather than
        // under-count, and neither touches a record that has output tokens.
        if ($prompttokens > 0 && $billableoutput === 0) {
            $kind = self::audio_usage_kind($modelname);
            if ($kind === 'tts' && (float) $rates['output'] > 0.0) {
                $billableoutput = $prompttokens * self::AUDIO_TOKENS_PER_TEXT_TOKEN;
            } else if ($kind === 'duplex') {
                $inputrate = max($inputrate, (float) $rates['output']);
            }
        }
        $inputcost  = ($prompttokens / 1_000_000) * $inputrate;
        $outputcost = ($billableoutput / 1_000_000) * $rates['output'];
        return $inputcost + $outputcost;
    }

    /**
     * Is this an audio model (speech in or out), judged by its name?
     *
     * v8.1.1. See {@see AUDIO_NAME_SEGMENTS}. A `batch/` marker is ignored.
     *
     * @param string $modelname Model name or rate-card key.
     * @return bool
     */
    public static function is_audio_model(string $modelname): bool {
        return array_intersect(self::name_segments($modelname), self::AUDIO_NAME_SEGMENTS) !== [];
    }

    /**
     * May a rate-card key price this model, as far as audio is concerned?
     *
     * v8.1.1. A text model may match any key. An audio model may match only a
     * key that is itself audio, or a namespace key ({@see NAMESPACE_PREFIXES}).
     * Both {@see model_registry::rate_for()} and
     * {@see price_source::longest_prefix()} apply this, so "is this model
     * priced?" has one answer everywhere.
     *
     * @param string $modelname
     * @param string $key Rate-card key (prefix).
     * @return bool
     */
    public static function key_may_price(string $modelname, string $key): bool {
        if (!self::is_audio_model($modelname)) {
            return true;
        }
        $key = strtolower(trim($key));
        return in_array($key, self::NAMESPACE_PREFIXES, true) || self::is_audio_model($key);
    }

    /**
     * How a prompt-only usage record of this model must be estimated.
     *
     * 'tts': text in, audio out, the audio count missing; the audio is estimated
     * from the text ({@see AUDIO_TOKENS_PER_TEXT_TOKEN}).
     * 'duplex': a realtime or live session logged as one token count (sse.php's
     * realtime logger writes duration x 50 as prompt tokens); every token is
     * priced at the dearer of the two rates.
     * '' for anything else, including transcription, which is input only.
     *
     * @param string $modelname
     * @return string
     */
    public static function audio_usage_kind(string $modelname): string {
        $segments = self::name_segments($modelname);
        if (array_intersect($segments, self::TRANSCRIPTION_SEGMENTS) !== []) {
            return '';
        }
        if (array_intersect($segments, self::UNSEPARATED_TTS_SEGMENTS) !== []) {
            return 'tts';
        }
        if (array_intersect($segments, self::UNSEPARATED_DUPLEX_SEGMENTS) !== []) {
            return 'duplex';
        }
        return '';
    }

    /**
     * Lowercase name segments, split on anything but letters, digits and dots.
     *
     * @param string $modelname
     * @return string[]
     */
    private static function name_segments(string $modelname): array {
        $name = strtolower(self::strip_batch_prefix($modelname));
        return preg_split('/[^a-z0-9.]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * The time pricing is resolved at: the real time, or the test clock.
     *
     * @return int Unix time.
     */
    public static function now(): int {
        return self::$clockfortesting ?? time();
    }

    /**
     * Fix the pricing clock for a test; null returns to the real time.
     *
     * @param int|null $time Unix time, or null.
     * @return void
     */
    public static function set_clock_for_testing(?int $time): void {
        self::$clockfortesting = $time;
    }

    /**
     * A short string that changes whenever a scheduled price step takes effect.
     *
     * {@see model_registry} keeps its merged map for the life of a request and
     * compares this to decide when to rebuild it, so a cron run that crosses
     * midnight on a step date, or a test that moves the clock, sees the new rate.
     *
     * @param int|null $at Unix time; null means {@see now()}.
     * @return string
     */
    public static function rate_schedule_signature(?int $at = null): string {
        $at = $at ?? self::now();
        $active = 0;
        foreach (self::SCHEDULED_RATE_CHANGES as $steps) {
            foreach ($steps as $step) {
                if ($at >= (int) $step['from']) {
                    $active++;
                }
            }
        }
        return (string) $active;
    }

    /**
     * Look up rate card by model name (prefix match, longest prefix wins).
     *
     * @param string $modelname
     * @return array|null ['input' => float, 'output' => float] or null.
     */
    public static function get_rates(string $modelname): ?array {
        return model_registry::rate_for($modelname);
    }

    /**
     * The committed baseline layer, unmerged.
     *
     * {@see model_registry} reads this as layer 1 and then applies the legacy
     * override blob and the models table on top. Nothing else should read it:
     * a caller wanting "the price of this model" wants the merged view, which
     * is {@see get_rates()}.
     *
     * v8.1.1: with every {@see SCHEDULED_RATE_CHANGES} step that has taken
     * effect by $at applied.
     *
     * @param int|null $at Unix time; null means {@see now()}.
     * @return array<string, array{input: float, output: float}>
     */
    public static function baseline_rate_cards(?int $at = null): array {
        $at = $at ?? self::now();
        $cards = self::$rate_cards;
        foreach (self::SCHEDULED_RATE_CHANGES as $key => $steps) {
            foreach ($steps as $step) {
                if ($at >= (int) $step['from'] && isset($cards[$key])) {
                    $cards[$key] = ['input' => (float) $step['input'], 'output' => (float) $step['output']];
                }
            }
        }
        return $cards;
    }

    /**
     * Format a dollar amount for display.
     *
     * Uses more decimal places for sub-cent amounts so the value is meaningful.
     *
     * @param float|null $cost
     * @return string  e.g. "$0.000142", "$0.0183", "$1.24", or "—" if null.
     */
    public static function format_cost(?float $cost): string {
        if ($cost === null) {
            return '—';
        }
        if ($cost < 0.0001) {
            return '$' . number_format($cost, 6);
        }
        if ($cost < 0.01) {
            return '$' . number_format($cost, 4);
        }
        if ($cost < 1.0) {
            return '$' . number_format($cost, 3);
        }
        return '$' . number_format($cost, 2);
    }

    /**
     * Return all rate card entries for display in the admin UI.
     *
     * @return array  [['model', 'input_per_1m', 'output_per_1m'], ...]
     */
    public static function get_all_rates(): array {
        $result = [];
        foreach (self::get_effective_rate_cards() as $prefix => $rates) {
            $result[] = [
                'model'         => $prefix . '…',
                'input_per_1m'  => '$' . number_format($rates['input'], 2),
                'output_per_1m' => '$' . number_format($rates['output'], 2),
            ];
        }
        return $result;
    }

    /**
     * The merged rate card: committed baseline, then the legacy
     * `rate_card_overrides` blob, then the v7.4.0 models table.
     *
     * v7.4.0: the merge itself moved to {@see model_registry::effective_rates()}
     * so that pricing has ONE resolver with provenance, instead of a private
     * helper here plus a weekly job that overwrote the override blob wholesale.
     * This wrapper stays because get_all_rates() below is a display path and
     * reads the whole map, not a single model.
     *
     * @return array<string, array{input: float, output: float}>
     */
    private static function get_effective_rate_cards(): array {
        return model_registry::effective_rates();
    }
}
