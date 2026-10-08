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
 * Model capability profiles (v7.8.0): what request shape each model accepts.
 *
 * WHY THIS EXISTS. Until v7.8.0 the shape of a request was decided by name
 * checks scattered across the provider classes: uses_max_completion_tokens()
 * knew gpt-5 and the o-series, claude_provider kept a temperature allow-list and
 * a forced-tool-choice deny-list, gemini_provider kept a regex of thinking
 * models, and token_cost_manager kept its own list of models whose thinking is
 * billed outside completion_tokens. Each list was correct for the models that
 * existed when it was written and wrong for the next one: on 2026-10-07 every
 * gpt-6 call failed with HTTP 400 because the first list did not know the name,
 * and gpt-5 and gpt-6 rejected the temperature the OpenAI path always sent.
 *
 * Now one class answers, for (provider, model):
 *
 *   token_param          which output-token parameter the API takes
 *                        (max_tokens or max_completion_tokens);
 *   temperature          'any' (sent) or 'omit' (left to the model's default);
 *   reasoning            how thinking is controlled: none, openai_effort
 *                        (reasoning_effort), gemini_budget (thinking_config) or
 *                        claude_thinking;
 *   reasoning_efforts    the reasoning_effort values the model accepts;
 *   thinks               whether the model reasons by default, which is what
 *                        earns an answer reasoning headroom on top of its budget;
 *   thinking_off         whether thinking can be switched off;
 *   max_output_tokens    the most output tokens the API accepts, when known;
 *   billed_outside_completion  whether thinking is reported outside
 *                        completion_tokens and must be added to price a call;
 *   forced_tool_choice   whether tool_choice type 'tool' is accepted (Claude).
 *
 * Three layers, later winning: the RULES table below (longest prefix per
 * provider family, with a family default for names nobody has seen), the Claude
 * temperature setting an admin or a policy bundle can edit, and LEARNED facts in
 * local_ai_course_assistant_model_caps. A learned fact is written by the request
 * healer when a provider rejects a request with a 400 that names the parameter,
 * so the next release of a model stops failing after its first call rather than
 * after the next plugin release.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_capabilities {
    /** @var string Learned-fact table. */
    public const TABLE = 'local_ai_course_assistant_model_caps';

    /** @var string Output-token parameter: the classic name. */
    public const TOKENS_MAX = 'max_tokens';

    /** @var string Output-token parameter: OpenAI reasoning models. */
    public const TOKENS_MAX_COMPLETION = 'max_completion_tokens';

    /** @var string Temperature is sent. */
    public const TEMP_ANY = 'any';

    /** @var string Temperature is left out, so the model uses its default. */
    public const TEMP_OMIT = 'omit';

    /** @var string No reasoning control is sent. */
    public const REASONING_NONE = 'none';

    /** @var string OpenAI reasoning_effort. */
    public const REASONING_OPENAI = 'openai_effort';

    /** @var string Gemini thinking_config.thinking_budget via extra_body. */
    public const REASONING_GEMINI = 'gemini_budget';

    /** @var string Anthropic extended thinking. */
    public const REASONING_CLAUDE = 'claude_thinking';

    /** @var string Provider family: OpenAI's own API. */
    public const FAMILY_OPENAI = 'openai';

    /** @var string Provider family: Google's OpenAI-compatible Gemini endpoint. */
    public const FAMILY_GEMINI = 'gemini';

    /** @var string Provider family: Anthropic Messages API. */
    public const FAMILY_CLAUDE = 'claude';

    /** @var string Provider family: any other OpenAI-compatible endpoint. */
    public const FAMILY_COMPATIBLE = 'openai_compatible';

    /**
     * Fields a learned fact may set, with the values each accepts.
     *
     * A fixed list on purpose: a learned row is written from a vendor's error
     * text, and it must never be able to introduce a field or a value the
     * request builders do not understand.
     *
     * @var array<string, string[]|null> Null means a positive integer.
     */
    public const LEARNABLE = [
        'token_param'        => [self::TOKENS_MAX, self::TOKENS_MAX_COMPLETION],
        'temperature'        => [self::TEMP_ANY, self::TEMP_OMIT],
        'reasoning'          => [self::REASONING_NONE, self::REASONING_OPENAI, self::REASONING_GEMINI,
            self::REASONING_CLAUDE],
        'reasoning_efforts'  => ['csv'],
        'thinking_off'       => ['allowed', 'forbidden'],
        'max_output_tokens'  => null,
        'forced_tool_choice' => ['0', '1'],
    ];

    /** @var string[] reasoning_effort values from least to most thinking. */
    public const EFFORT_ORDER = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh'];

    /**
     * The reasoning levels an admin can choose, in the order they are offered.
     *
     * 'off' means "think as little as the model allows": thinking_budget 0 on
     * Gemini, reasoning_effort none or minimal on OpenAI.
     *
     * @var string[]
     */
    public const SETTING_LEVELS = ['off', 'low', 'medium', 'high'];

    /** @var string Shipped reasoning level for chat: answers, not essays. */
    public const DEFAULT_LEVEL = 'low';

    /**
     * Reasoning headroom bounds, in tokens: 2 x the requested answer, clamped.
     *
     * The same rule v7.7.6 measured for Gemini (thinking peaked at 1,079
     * tokens against a 2,048 budget on the 50-prompt golden set), applied to
     * every model that thinks. Reasoning is billed only as used, so the
     * ceiling costs nothing on calls that think less; what it buys is that
     * thinking can no longer eat the answer, which is how gpt-5-mini returned
     * 4 empty answers out of 50 at 1,024 on 2026-10-07.
     */
    public const HEADROOM_MIN = 512;

    /** @var int Largest max_tokens Gemini 2.5 and 3 models accept. */
    public const GEMINI_OUTPUT_LIMIT = 65536;

    /** @var int See HEADROOM_MIN. */
    public const HEADROOM_MAX = 2048;

    /**
     * Model-name prefixes whose thinking is reported OUTSIDE completion_tokens.
     *
     * Kept as a name list, not a lookup by provider, because it is consumed by
     * SQL aggregates that only have msgs.model_name. It lives here so the one
     * class that describes a model also says how its thinking is billed;
     * token_cost_manager reads it for both the PHP and the SQL pricing paths.
     *
     * @var string[]
     */
    public const BILLED_OUTSIDE_COMPLETION_PREFIXES = ['gemini-', 'gemini/', 'models/gemini-'];

    /**
     * Baseline profile every family starts from.
     *
     * @var array<string, mixed>
     */
    private const BASE = [
        'token_param'               => self::TOKENS_MAX,
        'temperature'               => self::TEMP_ANY,
        'reasoning'                 => self::REASONING_NONE,
        'reasoning_efforts'         => [],
        'thinks'                    => false,
        'thinking_off'              => 'allowed',
        'max_output_tokens'         => null,
        'billed_outside_completion' => false,
        'forced_tool_choice'        => true,
    ];

    /**
     * Profile of OpenAI's non-reasoning chat snapshots (gpt-5.x-chat-latest).
     *
     * @var array<string, mixed>
     */
    private const OPENAI_CHAT_SNAPSHOT = [
        'token_param' => self::TOKENS_MAX_COMPLETION,
        'temperature' => self::TEMP_OMIT,
        'reasoning' => self::REASONING_NONE,
        'thinks' => false,
        'max_output_tokens' => 16384,
    ];

    /**
     * OpenAI model rules, shared by OpenAI's API and by OpenAI-compatible
     * endpoints (LiteLLM, Azure-style proxies and vLLM front OpenAI models under
     * the same names and reject the same parameters).
     *
     * @var array<string, array<string, mixed>>
     */
    private const OPENAI_MODEL_RULES = [
        'gpt-4o-mini' => ['max_output_tokens' => 16384],
        'gpt-4o'      => ['max_output_tokens' => 16384],
        // GPT-5 and later: reasoning models that take max_completion_tokens and
        // reject any temperature but the default.
        'gpt-5' => [
            'token_param' => self::TOKENS_MAX_COMPLETION,
            'temperature' => self::TEMP_OMIT,
            'reasoning' => self::REASONING_OPENAI,
            'reasoning_efforts' => ['minimal', 'low', 'medium', 'high'],
            'thinks' => true,
            'max_output_tokens' => 128000,
        ],
        // 5.1 onward replaced 'minimal' with 'none'.
        'gpt-5.' => [
            'token_param' => self::TOKENS_MAX_COMPLETION,
            'temperature' => self::TEMP_OMIT,
            'reasoning' => self::REASONING_OPENAI,
            'reasoning_efforts' => ['none', 'low', 'medium', 'high'],
            'thinks' => true,
            'max_output_tokens' => 128000,
        ],
        // The non-reasoning chat snapshots. Each later generation names its own
        // (gpt-5.1-chat-latest), which the 'gpt-5.' reasoning rule would
        // otherwise catch.
        'gpt-5.1-chat' => self::OPENAI_CHAT_SNAPSHOT,
        'gpt-5.2-chat' => self::OPENAI_CHAT_SNAPSHOT,
        'gpt-5.3-chat' => self::OPENAI_CHAT_SNAPSHOT,
        'gpt-5.4-chat' => self::OPENAI_CHAT_SNAPSHOT,
        'gpt-5.5-chat' => self::OPENAI_CHAT_SNAPSHOT,
        'gpt-5-chat' => [
            'token_param' => self::TOKENS_MAX_COMPLETION,
            'temperature' => self::TEMP_OMIT,
            'reasoning' => self::REASONING_NONE,
            'thinks' => false,
            'max_output_tokens' => 16384,
        ],
        // The gpt-6 effort values are as OpenAI listed them in a live 400 on
        // 2026-10-07 ("Supported values are: 'none', 'low', 'medium', 'high',
        // and 'xhigh'"); a later change is learned the same way.
        'gpt-6' => [
            'token_param' => self::TOKENS_MAX_COMPLETION,
            'temperature' => self::TEMP_OMIT,
            'reasoning' => self::REASONING_OPENAI,
            'reasoning_efforts' => ['none', 'low', 'medium', 'high', 'xhigh'],
            'thinks' => true,
            'max_output_tokens' => 128000,
        ],
        'o1' => [
            'token_param' => self::TOKENS_MAX_COMPLETION,
            'temperature' => self::TEMP_OMIT,
            'reasoning' => self::REASONING_OPENAI,
            'reasoning_efforts' => ['low', 'medium', 'high'],
            'thinks' => true,
        ],
        // The first o1 releases think but take no reasoning_effort.
        'o1-mini' => [
            'token_param' => self::TOKENS_MAX_COMPLETION,
            'temperature' => self::TEMP_OMIT,
            'reasoning' => self::REASONING_NONE,
            'thinks' => true,
        ],
        'o1-preview' => [
            'token_param' => self::TOKENS_MAX_COMPLETION,
            'temperature' => self::TEMP_OMIT,
            'reasoning' => self::REASONING_NONE,
            'thinks' => true,
        ],
        'o3' => [
            'token_param' => self::TOKENS_MAX_COMPLETION,
            'temperature' => self::TEMP_OMIT,
            'reasoning' => self::REASONING_OPENAI,
            'reasoning_efforts' => ['low', 'medium', 'high'],
            'thinks' => true,
        ],
        'o4' => [
            'token_param' => self::TOKENS_MAX_COMPLETION,
            'temperature' => self::TEMP_OMIT,
            'reasoning' => self::REASONING_OPENAI,
            'reasoning_efforts' => ['low', 'medium', 'high'],
            'thinks' => true,
        ],
        // Open-weight gpt-oss as Together and others serve it: max_tokens, but
        // it reasons by default (36% of its golden answers were cut off at 1,024
        // on 2026-10-07) and takes reasoning_effort.
        'gpt-oss' => [
            'reasoning' => self::REASONING_OPENAI,
            'reasoning_efforts' => ['low', 'medium', 'high'],
            'thinks' => true,
        ],
        'openai/gpt-oss' => [
            'reasoning' => self::REASONING_OPENAI,
            'reasoning_efforts' => ['low', 'medium', 'high'],
            'thinks' => true,
        ],
    ];

    /**
     * Gemini rules. Names are matched with any "models/" prefix removed.
     *
     * Thinking models get a budget; Flash-Lite, TTS, image, audio, live and
     * embedding variants of the same generation do not (on Flash-Lite a budget
     * would switch thinking ON), which is what the `exclude` substrings say.
     *
     * @var array<string, array<string, mixed>>
     */
    private const GEMINI_RULES = [
        'gemini-2.5' => [
            'reasoning' => self::REASONING_GEMINI,
            'thinks' => true,
            'max_output_tokens' => self::GEMINI_OUTPUT_LIMIT,
            'exclude' => ['lite', 'tts', 'image', 'audio', 'live', 'transcribe', 'embedding'],
        ],
        // 2.5 Pro thinks and cannot be switched off.
        'gemini-2.5-pro' => [
            'reasoning' => self::REASONING_GEMINI,
            'thinks' => true,
            'thinking_off' => 'forbidden',
            'max_output_tokens' => self::GEMINI_OUTPUT_LIMIT,
            'exclude' => ['tts', 'image', 'audio', 'live', 'transcribe', 'embedding'],
        ],
        'gemini-3' => [
            'reasoning' => self::REASONING_GEMINI,
            'thinks' => true,
            'max_output_tokens' => self::GEMINI_OUTPUT_LIMIT,
            'exclude' => ['lite', 'tts', 'image', 'audio', 'live', 'transcribe', 'embedding'],
        ],
        'gemini-flash-latest' => [
            'reasoning' => self::REASONING_GEMINI,
            'thinks' => true,
            'max_output_tokens' => self::GEMINI_OUTPUT_LIMIT,
        ],
        'gemini-pro-latest' => [
            'reasoning' => self::REASONING_GEMINI,
            'thinks' => true,
            'thinking_off' => 'forbidden',
            'max_output_tokens' => self::GEMINI_OUTPUT_LIMIT,
        ],
    ];

    /**
     * Claude models that reject a forced tool choice (tool_choice type 'tool'
     * or 'any' returns HTTP 400 "not supported for this model").
     *
     * Turned into rules by {@see rules_for()}. Temperature is NOT a rule: it
     * comes from the claude_temperature_allow_prefixes setting, which an admin
     * or a signed policy bundle can correct without a release.
     *
     * @var string[]
     */
    public const CLAUDE_FORCED_TOOL_CHOICE_DENY = [
        'claude-opus-5-5',
        'claude-sonnet-5-5',
        'claude-fable-5-1',
        'claude-mythos-5-1',
    ];

    /**
     * Shipped Claude prefixes that still accept sampling parameters.
     *
     * Moved here from claude_provider, which keeps a constant of the same name
     * pointing at this one for anything that read it there.
     *
     * @var string[]
     */
    public const CLAUDE_TEMPERATURE_ALLOW_PREFIXES = [
        // Aliases.
        'claude-opus-4-6', 'claude-opus-4-5', 'claude-opus-4-1', 'claude-opus-4-0',
        'claude-sonnet-4-6', 'claude-sonnet-4-5', 'claude-sonnet-4-0',
        'claude-haiku-4-5', 'claude-haiku-3-5', 'claude-haiku-3',
        // Dated full IDs for the 4.0 generation, whose alias form does not
        // prefix-match them: claude-sonnet-4-20250514 and claude-opus-4-20250514.
        // A bare 'claude-opus-4' prefix would also match the denied 4-7 / 4-8.
        'claude-opus-4-2025', 'claude-sonnet-4-2025',
        // Claude 3.x and 2.x, all of which accept sampling parameters.
        'claude-3', 'claude-2',
    ];

    /** @var array<string, array<string, array<string, string>>>|null Request-scoped learned facts. */
    private static ?array $learned = null;

    /**
     * The provider family a provider id belongs to.
     *
     * @param string $provider Provider id as base_provider::provider_id() reports it.
     * @return string One of the FAMILY_* constants.
     */
    public static function family(string $provider): string {
        $provider = strtolower(trim($provider));
        switch ($provider) {
            case 'openai':
                return self::FAMILY_OPENAI;
            case 'gemini':
                return self::FAMILY_GEMINI;
            case 'claude':
                return self::FAMILY_CLAUDE;
            default:
                return self::FAMILY_COMPATIBLE;
        }
    }

    /**
     * The capability profile for one provider and model.
     *
     * Always returns a complete profile: a model nobody has described gets its
     * family's default, which is today's request shape for that family. The
     * `sources` entry says which layer supplied each field, for the admin page.
     *
     * @param string $provider Provider id (openai, gemini, claude, together, ...).
     * @param string $model Model id as configured.
     * @return array<string, mixed>
     */
    public static function profile(string $provider, string $model): array {
        $family = self::family($provider);
        $name = self::normalise($model);
        $profile = self::BASE;
        $sources = array_fill_keys(array_keys(self::BASE), 'default');

        if ($family === self::FAMILY_GEMINI) {
            $profile['billed_outside_completion'] = true;
            $sources['billed_outside_completion'] = 'family';
        }

        $rule = self::match_rule($name, self::rules_for($family));
        if ($rule !== null) {
            foreach ($rule['rule'] as $field => $value) {
                if ($field === 'exclude') {
                    continue;
                }
                $profile[$field] = $value;
                $sources[$field] = 'rule:' . $rule['key'];
            }
        }

        if ($family === self::FAMILY_CLAUDE) {
            $profile['temperature'] = self::claude_accepts_temperature($name) ? self::TEMP_ANY : self::TEMP_OMIT;
            $sources['temperature'] = 'setting';
            $profile['reasoning'] = self::REASONING_CLAUDE;
            $sources['reasoning'] = 'family';
        }

        foreach (self::learned_for($provider, $name) as $field => $value) {
            $profile[$field] = self::decode_value($field, $value);
            $sources[$field] = 'learned';
        }

        $profile['family'] = $family;
        $profile['provider'] = strtolower(trim($provider));
        $profile['model'] = $name;
        $profile['sources'] = $sources;
        return $profile;
    }

    /**
     * The reasoning_effort to send for a requested level, or null for none.
     *
     * 'off' asks for the least thinking the model accepts, by EFFORT_ORDER
     * ('none' before 'minimal' before 'low'). Any other level is sent when the
     * model accepts it, otherwise the nearest accepted value ABOVE it, so a
     * model that has no 'low' thinks a little more rather than being refused.
     *
     * @param array $profile From {@see profile()}.
     * @param string $level One of SETTING_LEVELS, or an explicit effort value.
     * @return string|null
     */
    public static function effort_for(array $profile, string $level): ?string {
        if (($profile['reasoning'] ?? '') !== self::REASONING_OPENAI) {
            return null;
        }
        $allowed = array_values(array_filter((array) ($profile['reasoning_efforts'] ?? []), 'is_string'));
        if (empty($allowed)) {
            return null;
        }
        $level = strtolower(trim($level));
        if ($level === 'off') {
            return self::lowest($allowed);
        }
        if (in_array($level, $allowed, true)) {
            return $level;
        }
        $rank = array_search($level, self::EFFORT_ORDER, true);
        if ($rank === false) {
            return self::lowest($allowed);
        }
        foreach (array_slice(self::EFFORT_ORDER, (int) $rank + 1) as $higher) {
            if (in_array($higher, $allowed, true)) {
                return $higher;
            }
        }
        return self::lowest($allowed);
    }

    /**
     * A profile with one fact applied, as a learned row would apply it.
     *
     * Used by a provider that has just healed a request: the retry must carry
     * the fix even when the fact could not be stored.
     *
     * @param array $profile
     * @param string $field One of LEARNABLE.
     * @param string $value
     * @return array
     */
    public static function apply_fact(array $profile, string $field, string $value): array {
        if (!self::valid_value($field, $value)) {
            return $profile;
        }
        $profile[$field] = self::decode_value($field, $value);
        $profile['sources'][$field] = 'learned';
        return $profile;
    }

    /**
     * Reasoning headroom for an answer budget: 2 x requested, clamped.
     *
     * @param int $requested Answer tokens the caller asked for.
     * @return int
     */
    public static function headroom(int $requested): int {
        if ($requested <= 0) {
            return 0;
        }
        return max(self::HEADROOM_MIN, min(self::HEADROOM_MAX, 2 * $requested));
    }

    /**
     * The site's reasoning level setting, validated.
     *
     * @return string One of SETTING_LEVELS.
     */
    public static function site_level(): string {
        $raw = strtolower(trim((string) get_config('local_ai_course_assistant', 'reasoning_effort')));
        return in_array($raw, self::SETTING_LEVELS, true) ? $raw : self::DEFAULT_LEVEL;
    }

    /**
     * Record a fact the provider taught us, once per (provider, model, field).
     *
     * Writes the learned-fact row, invalidates the cache, logs an audit row and
     * fires {@see event\model_capability_learned}. The evidence is the vendor's
     * own error text, redacted and truncated: it is what an operator needs to
     * judge whether the fact is right, and it must never carry a credential.
     *
     * @param string $provider
     * @param string $model
     * @param string $field One of LEARNABLE.
     * @param string $value
     * @param string $evidence The provider's error text.
     * @return bool True when the stored fact changed.
     */
    public static function learn(string $provider, string $model, string $field, string $value, string $evidence = ''): bool {
        global $DB;

        $provider = strtolower(trim($provider));
        $model = self::normalise($model);
        if ($provider === '' || $model === '' || !self::valid_value($field, $value)) {
            return false;
        }
        $evidence = \core_text::substr(security::redact_secrets(trim($evidence)), 0, 400);
        $now = time();

        try {
            $existing = $DB->get_record(self::TABLE, ['provider' => $provider, 'modelkey' => $model, 'field' => $field]);
            if ($existing && (string) $existing->value === $value) {
                $DB->update_record(self::TABLE, (object) [
                    'id' => $existing->id,
                    'hits' => (int) $existing->hits + 1,
                    'timemodified' => $now,
                ]);
                return false;
            }
            if ($existing) {
                $DB->update_record(self::TABLE, (object) [
                    'id' => $existing->id,
                    'value' => $value,
                    'evidence' => $evidence,
                    'hits' => 1,
                    'timemodified' => $now,
                ]);
                $id = (int) $existing->id;
            } else {
                $id = (int) $DB->insert_record(self::TABLE, (object) [
                    'provider' => $provider,
                    'modelkey' => $model,
                    'field' => $field,
                    'value' => $value,
                    'evidence' => $evidence,
                    'hits' => 1,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            // A learned fact is an optimisation of the next call, never a
            // precondition of this one: the caller still retries with the fix.
            debugging('SOLA could not store a learned model capability: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }

        self::reset_cache();

        try {
            audit_logger::log('model_capability_learned', 0, 0, [
                'provider' => $provider,
                'model' => $model,
                'field' => $field,
                'value' => $value,
                'evidence' => $evidence,
            ]);
        } catch (\Throwable $e) {
            unset($e);
        }
        try {
            event\model_capability_learned::create([
                'context' => \context_system::instance(),
                'objectid' => $id,
                'other' => ['provider' => $provider, 'model' => $model, 'field' => $field, 'value' => $value],
            ])->trigger();
        } catch (\Throwable $e) {
            unset($e);
        }
        return true;
    }

    /**
     * Remove one learned fact, so the next call uses the rules again.
     *
     * @param int $id Row id.
     * @param int $userid Administrator who removed it.
     * @return bool True when a row was removed.
     */
    public static function forget(int $id, int $userid = 0): bool {
        global $DB;
        $row = $id > 0 ? $DB->get_record(self::TABLE, ['id' => $id]) : false;
        if (!$row) {
            return false;
        }
        $DB->delete_records(self::TABLE, ['id' => $id]);
        self::reset_cache();
        // Forgetting changes request shape just as learning does, so it is
        // audited the same way, with the administrator who did it.
        try {
            audit_logger::log('model_capability_forgotten', $userid, 0, [
                'provider' => $row->provider, 'model' => $row->modelkey, 'field' => $row->field, 'value' => $row->value,
            ]);
        } catch (\Throwable $e) {
            unset($e);
        }
        return true;
    }

    /**
     * Every learned fact, newest first, for the admin page.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function learned_rows(): array {
        global $DB;
        $out = [];
        try {
            foreach ($DB->get_records(self::TABLE, null, 'timemodified DESC, id DESC') as $row) {
                $out[] = [
                    'id' => (int) $row->id,
                    'provider' => (string) $row->provider,
                    'model' => (string) $row->modelkey,
                    'field' => (string) $row->field,
                    'value' => (string) $row->value,
                    'evidence' => (string) ($row->evidence ?? ''),
                    'hits' => (int) $row->hits,
                    'timecreated' => (int) $row->timecreated,
                    'timemodified' => (int) $row->timemodified,
                ];
            }
        } catch (\Throwable $e) {
            unset($e);
        }
        return $out;
    }

    /**
     * Drop the request-scoped and shared caches of learned facts.
     *
     * @return void
     */
    public static function reset_cache(): void {
        self::$learned = null;
        try {
            \cache::make('local_ai_course_assistant', 'modelcaps')->purge();
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * Does a Claude model accept temperature? Reads the admin allow-list.
     *
     * An ALLOW-list on purpose: omitting temperature from a model that accepts
     * it is harmless, sending it to one that rejects it is a 400 on every call.
     * Plain str_starts_with, exactly as before v7.8.0, because the shipped
     * list relies on it ('claude-opus-4-2025' matches the dated 4.0 IDs).
     *
     * @param string $model
     * @return bool
     */
    public static function claude_accepts_temperature(string $model): bool {
        $model = strtolower(trim($model));
        if ($model === '') {
            return false;
        }
        foreach (self::claude_temperature_prefixes() as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The effective Claude temperature allow-list: setting, else shipped default.
     *
     * @return string[]
     */
    public static function claude_temperature_prefixes(): array {
        $raw = (string) get_config('local_ai_course_assistant', 'claude_temperature_allow_prefixes');
        $out = [];
        foreach (preg_split('/[\r\n,]+/', $raw) as $line) {
            $line = strtolower(trim($line));
            if ($line !== '' && $line[0] !== '#') {
                $out[] = $line;
            }
        }
        return $out ?: self::CLAUDE_TEMPERATURE_ALLOW_PREFIXES;
    }

    /**
     * Lowercase, trim, and drop Gemini's "models/" prefix.
     *
     * @param string $model
     * @return string
     */
    public static function normalise(string $model): string {
        $model = strtolower(trim($model));
        if (str_starts_with($model, 'models/')) {
            $model = substr($model, strlen('models/'));
        }
        return $model;
    }

    /**
     * Rule keys for a family, for the admin page and the tests.
     *
     * @param string $family
     * @return array<string, array<string, mixed>>
     */
    public static function rules_for(string $family): array {
        switch ($family) {
            case self::FAMILY_OPENAI:
            case self::FAMILY_COMPATIBLE:
                return self::OPENAI_MODEL_RULES;
            case self::FAMILY_GEMINI:
                return self::GEMINI_RULES;
            case self::FAMILY_CLAUDE:
                $rules = [];
                foreach (self::CLAUDE_FORCED_TOOL_CHOICE_DENY as $key) {
                    $rules[$key] = ['forced_tool_choice' => false];
                }
                return $rules;
            default:
                return [];
        }
    }

    /**
     * Longest rule key that prefixes the model at a name boundary.
     *
     * A boundary is the end of the name or one of - . _ : @ /, so 'o1' matches
     * 'o1-mini' and 'o1' but not a hypothetical 'o10', and 'gpt-5' matches
     * 'gpt-5-mini' and 'gpt-5.1' but not 'gpt-50'. A rule whose `exclude`
     * substrings appear in the name is skipped and the next-longest key tried.
     *
     * @param string $name Normalised model name.
     * @param array $rules
     * @return array{key: string, rule: array}|null
     */
    private static function match_rule(string $name, array $rules): ?array {
        if ($name === '') {
            return null;
        }
        $keys = array_keys($rules);
        usort($keys, static function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });
        foreach ($keys as $key) {
            if (!self::prefix_at_boundary($name, $key)) {
                continue;
            }
            $excluded = false;
            foreach ((array) ($rules[$key]['exclude'] ?? []) as $needle) {
                if (str_contains($name, $needle)) {
                    $excluded = true;
                    break;
                }
            }
            if (!$excluded) {
                return ['key' => $key, 'rule' => $rules[$key]];
            }
        }
        return null;
    }

    /**
     * Does $key prefix $name, ending at a name boundary?
     *
     * @param string $name
     * @param string $key
     * @return bool
     */
    private static function prefix_at_boundary(string $name, string $key): bool {
        if (!str_starts_with($name, $key)) {
            return false;
        }
        if (strlen($name) === strlen($key)) {
            return true;
        }
        $last = substr($key, -1);
        if (in_array($last, ['-', '.', '_', ':', '@', '/'], true)) {
            return true;
        }
        return in_array($name[strlen($key)], ['-', '.', '_', ':', '@', '/'], true);
    }

    /**
     * Learned facts for one provider and model, exact match.
     *
     * Exact, not prefix: a fact was learned from one model's rejection, and a
     * sibling model is as likely to differ as to agree. The rules table is the
     * place for family knowledge.
     *
     * @param string $provider
     * @param string $model Normalised.
     * @return array<string, string> field => stored value
     */
    private static function learned_for(string $provider, string $model): array {
        $all = self::all_learned();
        return $all[strtolower(trim($provider))][$model] ?? [];
    }

    /**
     * All learned facts, cached for the request and in MUC.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    private static function all_learned(): array {
        if (self::$learned !== null) {
            return self::$learned;
        }
        $cache = null;
        try {
            $cache = \cache::make('local_ai_course_assistant', 'modelcaps');
            $hit = $cache->get('all');
            if (is_array($hit)) {
                self::$learned = $hit;
                return $hit;
            }
        } catch (\Throwable $e) {
            $cache = null;
        }

        global $DB;
        $map = [];
        try {
            $rs = $DB->get_recordset(self::TABLE, null, 'id ASC', 'id, provider, modelkey, field, value');
            foreach ($rs as $row) {
                if (!self::valid_value((string) $row->field, (string) $row->value)) {
                    continue;
                }
                $map[(string) $row->provider][(string) $row->modelkey][(string) $row->field] = (string) $row->value;
            }
            $rs->close();
        } catch (\Throwable $e) {
            // Mid-upgrade the table may not exist yet: the rules still apply.
            $map = [];
        }
        self::$learned = $map;
        if ($cache !== null) {
            try {
                $cache->set('all', $map);
            } catch (\Throwable $e) {
                unset($e);
            }
        }
        return $map;
    }

    /**
     * Is this a value a learned row may hold for this field?
     *
     * @param string $field
     * @param string $value
     * @return bool
     */
    private static function valid_value(string $field, string $value): bool {
        if (!array_key_exists($field, self::LEARNABLE)) {
            return false;
        }
        $allowed = self::LEARNABLE[$field];
        if ($allowed === null) {
            return ctype_digit($value) && (int) $value > 0;
        }
        if ($allowed === ['csv']) {
            $parts = array_filter(array_map('trim', explode(',', $value)));
            return !empty($parts) && !array_diff($parts, self::EFFORT_ORDER);
        }
        return in_array($value, $allowed, true);
    }

    /**
     * Stored string to the profile's typed value.
     *
     * @param string $field
     * @param string $value
     * @return mixed
     */
    private static function decode_value(string $field, string $value) {
        switch ($field) {
            case 'max_output_tokens':
                return (int) $value;
            case 'forced_tool_choice':
                return $value === '1';
            case 'reasoning_efforts':
                return array_values(array_filter(array_map('trim', explode(',', $value))));
            default:
                return $value;
        }
    }

    /**
     * The least-thinking value in a list, by EFFORT_ORDER.
     *
     * @param string[] $allowed
     * @return string
     */
    private static function lowest(array $allowed): string {
        foreach (self::EFFORT_ORDER as $value) {
            if (in_array($value, $allowed, true)) {
                return $value;
            }
        }
        return (string) reset($allowed);
    }
}
