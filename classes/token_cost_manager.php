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
        'gpt-4o-realtime'   => ['input' => 5.00, 'output' => 20.00],

        // ── OpenAI TTS ──────────────────────────────────────────────────────
        // TTS-1 charges per character (~$15/M chars). Approximated as per-token
        // at ~4 chars/token for consistency with the token-based rate card.
        'tts-1'             => ['input' => 60.00, 'output' => 0.00],
        'tts-1-hd'          => ['input' => 120.00, 'output' => 0.00],

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
        'whisper'           => ['input' => 6.00, 'output' => 0.00],

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
        'claude-haiku-4-5'  => ['input' => 1.00, 'output' => 5.00],
        'claude-haiku'      => ['input' => 1.00, 'output' => 5.00],
        'claude-sonnet-5'   => ['input' => 2.00, 'output' => 10.00],
        'claude-sonnet'     => ['input' => 3.00, 'output' => 15.00],
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
        'meta-llama/llama-3.1-8b-instruct'         => ['input' => 0.02, 'output' => 0.05],

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
     * @var string[]
     */
    private const REASONING_OUTSIDE_COMPLETION_PREFIXES = ['gemini-', 'gemini/', 'models/gemini-'];

    /**
     * Does this model bill thinking as output ON TOP OF completion_tokens?
     *
     * @param string $modelname Exact model string as recorded on the row.
     * @return bool True when reasoning_tokens must be ADDED to completion_tokens to price the call.
     */
    public static function reasoning_billed_as_extra_output(string $modelname): bool {
        $model = strtolower(trim($modelname));
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
        $inputcost  = ($prompttokens / 1_000_000) * $rates['input'];
        $outputcost = ($billableoutput / 1_000_000) * $rates['output'];
        return $inputcost + $outputcost;
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
     * @return array<string, array{input: float, output: float}>
     */
    public static function baseline_rate_cards(): array {
        return self::$rate_cards;
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
