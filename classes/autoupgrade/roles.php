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

namespace local_ai_course_assistant\autoupgrade;

use local_ai_course_assistant\model_capabilities;
use local_ai_course_assistant\spend_guard;

/**
 * The SOLA roles a model can be upgraded in, and where each one is configured (v7.8.0).
 *
 * A role is a job a model does for SOLA, with a SITE-level setting that says
 * which model does it. Three are tutoring roles the automatic evaluation can
 * measure on a tutor fixture set: chat (the primary model), premium (the
 * escalation tier) and failover. The other four are the extra functions the
 * recommender already knows about (quiz generation, the mastery classifier,
 * the safety reference and Soapbox scoring); discovery marks candidates for
 * them, but no harness measures their task, so they are never evaluated or
 * switched automatically. Comparing a quiz generator on tutoring answers would
 * be a false comparison, and this feature refuses those.
 *
 * Switching writes SITE settings only. A course with its own model override
 * keeps it, because that override is read before the site value. The premium
 * tier's switch writes its model and never its trigger rules.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class roles {
    /** @var string Primary chat model. */
    public const CHAT = 'chat';

    /** @var string Premium escalation tier. */
    public const PREMIUM = 'premium';

    /** @var string First chat entry of the failover chain. */
    public const FAILOVER = 'failover';

    /** @var string Variant: the role's reasoning setting as it stands. */
    public const VARIANT_DEFAULT = '';

    /** @var string Variant: thinking off (reasoning_effort = off). */
    public const VARIANT_THINKING_OFF = 'off';

    /**
     * Role definitions.
     *
     * `auto` is whether SOLA may switch the role by itself. Failover is
     * evaluated but never switched automatically: its model lives in the
     * comparison_providers setting, which holds an API key and is not on the
     * signed policy bundle's allowlist of settings that may change without a
     * person. `fixture` is the prompt set its evaluation uses; the premium
     * tier is measured on the 40 multi-step prompts it was chosen on, not on
     * the general tutor set it does not serve.
     *
     * @var array<string, array<string, mixed>>
     */
    public const ROLES = [
        self::CHAT => [
            'providerkey' => 'provider',
            'modelkey' => 'model',
            'variantkey' => 'reasoning_effort',
            'enabledkey' => null,
            'auto' => true,
            'evaluable' => true,
            'fixture' => 'fixtures/golden/tutor_prompts.json',
        ],
        self::PREMIUM => [
            'providerkey' => 'premium_escalation_provider',
            'modelkey' => 'premium_escalation_model',
            'variantkey' => null,
            'enabledkey' => 'premium_escalation_enabled',
            'auto' => true,
            'evaluable' => true,
            'fixture' => 'fixtures/golden/tutor_prompts_a10_premium_escalation.json',
        ],
        self::FAILOVER => [
            'providerkey' => null,
            'modelkey' => null,
            'variantkey' => null,
            'enabledkey' => null,
            'auto' => false,
            'evaluable' => true,
            'fixture' => 'fixtures/golden/tutor_prompts.json',
        ],
        'quiz' => [
            'providerkey' => 'quiz_provider',
            'modelkey' => 'quiz_model',
            'variantkey' => null,
            'enabledkey' => null,
            'auto' => false,
            'evaluable' => false,
            'fixture' => null,
        ],
        'classifier' => [
            'providerkey' => 'mastery_classifier_provider',
            'modelkey' => 'mastery_classifier_model',
            'variantkey' => null,
            'enabledkey' => null,
            'auto' => false,
            'evaluable' => false,
            'fixture' => null,
        ],
        'safety' => [
            'providerkey' => 'safety_provider',
            'modelkey' => 'safety_model',
            'variantkey' => null,
            'enabledkey' => null,
            'auto' => false,
            'evaluable' => false,
            'fixture' => null,
        ],
        'soapbox' => [
            'providerkey' => 'soapbox_vision_provider',
            'modelkey' => 'soapbox_vision_model',
            'variantkey' => null,
            'enabledkey' => null,
            'auto' => false,
            'evaluable' => false,
            'fixture' => null,
        ],
    ];

    /**
     * The model a role runs now.
     *
     * @param string $role
     * @return array{role: string, provider: string, model: string, variant: string, inuse: bool,
     *               auto: bool, evaluable: bool, fixture: ?string}
     */
    public static function current(string $role): array {
        $spec = self::spec($role);
        $out = [
            'role' => $role,
            'provider' => '',
            'model' => '',
            'variant' => self::VARIANT_DEFAULT,
            'inuse' => false,
            'auto' => (bool) $spec['auto'],
            'evaluable' => (bool) $spec['evaluable'],
            'fixture' => $spec['fixture'],
        ];

        if ($role === self::FAILOVER) {
            $chain = spend_guard::resolve_failover_chain('chat');
            if (!empty($chain[0]['model'])) {
                $out['provider'] = strtolower((string) $chain[0]['provider']);
                $out['model'] = (string) $chain[0]['model'];
                $out['inuse'] = true;
            }
            return $out;
        }

        $model = trim((string) get_config('local_ai_course_assistant', $spec['modelkey']));
        $provider = strtolower(trim((string) get_config('local_ai_course_assistant', $spec['providerkey'])));
        if ($model === '') {
            return $out;
        }
        if ($provider === '' || $provider === 'auto') {
            $provider = self::infer_provider($model);
        }
        $out['provider'] = $provider;
        $out['model'] = $model;
        if (
            $spec['variantkey'] !== null
                && model_capabilities::site_level() === self::VARIANT_THINKING_OFF
        ) {
            $out['variant'] = self::VARIANT_THINKING_OFF;
        }
        $enabled = $spec['enabledkey'] === null
            || (bool) get_config('local_ai_course_assistant', $spec['enabledkey']);
        // Chat is always in use once it has a model; the other roles count only
        // when an admin configured them on purpose, because an unset key means
        // "inherit the chat model", and the chat role already covers that.
        $out['inuse'] = $provider !== '' && $enabled;
        return $out;
    }

    /**
     * Every role, current state.
     *
     * @return array<string, array>
     */
    public static function all(): array {
        $out = [];
        foreach (array_keys(self::ROLES) as $role) {
            $out[$role] = self::current($role);
        }
        return $out;
    }

    /**
     * The definition of a role.
     *
     * @param string $role
     * @return array
     */
    public static function spec(string $role): array {
        if (!isset(self::ROLES[$role])) {
            throw new \coding_exception('Unknown role: ' . $role);
        }
        return self::ROLES[$role];
    }

    /**
     * The settings a switch of this role writes, and their new values.
     *
     * @param string $role
     * @param string $model
     * @param string $variant
     * @return array<string, string> setting => new value
     */
    public static function writes(string $role, string $model, string $variant): array {
        $spec = self::spec($role);
        if ($spec['modelkey'] === null) {
            return [];
        }
        $out = [$spec['modelkey'] => $model];
        if ($spec['variantkey'] !== null) {
            // Written only when it changes, from the same rule the evaluation
            // measured the candidate at (level_after()).
            $level = self::level_after($role, $variant);
            if ($level !== model_capabilities::site_level()) {
                $out[$spec['variantkey']] = $level;
            }
        }
        return $out;
    }

    /**
     * The reasoning level a model would run at in this role after a switch to it.
     *
     * The ONE rule both the switch (writes()) and the evaluation use, so a
     * candidate is measured at the level it will actually run at: the
     * thinking-off variant at 'off'; any other candidate of a role with a
     * reasoning setting at the level writes() would leave (the shipped default
     * when the site is currently off, the site level otherwise); a role with
     * no reasoning setting at the site level.
     *
     * @param string $role
     * @param string $variant
     * @return string
     */
    public static function level_after(string $role, string $variant): string {
        $spec = self::spec($role);
        if ($variant === self::VARIANT_THINKING_OFF) {
            return 'off';
        }
        $current = model_capabilities::site_level();
        if ($spec['variantkey'] !== null && $current === self::VARIANT_THINKING_OFF) {
            return model_capabilities::DEFAULT_LEVEL;
        }
        return $current;
    }

    /**
     * Guess a provider from a model name when the site provider is 'auto'.
     *
     * @param string $model
     * @return string Provider id, or '' when the name says nothing.
     */
    public static function infer_provider(string $model): string {
        $m = model_capabilities::normalise($model);
        if (str_starts_with($m, 'claude-')) {
            return 'claude';
        }
        if (str_starts_with($m, 'gemini-') || str_starts_with($m, 'gemma-')) {
            return 'gemini';
        }
        if (preg_match('/^(gpt-|o[134](?:[-.]|$)|chatgpt-)/', $m)) {
            return 'openai';
        }
        return '';
    }
}
