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

namespace local_ai_course_assistant\provider;

use local_ai_course_assistant\model_capabilities;

/**
 * Reads a provider's 400 and names the ONE request parameter to change (v7.8.0).
 *
 * When a vendor ships a model that rejects a parameter SOLA sends, it says so in
 * the error body: "Unsupported parameter: 'max_tokens' ... Use
 * 'max_completion_tokens' instead", "'temperature' does not support 0.4 with
 * this model", "reasoning_effort is not supported". This class recognises those
 * sentences and returns the capability fact they imply, so the provider can
 * retry once with the fix and record it for every later call.
 *
 * Deliberately narrow. Every rule requires that the request actually carried the
 * parameter the message names, and that the fix differs from what was sent, so
 * a rule can never fire twice for the same request. A 400 that matches nothing
 * returns null and is not retried: guessing at an unrecognised rejection would
 * turn a clear error into a confusing second one.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class request_healer {
    /** @var int[] Statuses that mean "this request is malformed for this model". */
    public const HEALABLE_STATUSES = [400, 422];

    /**
     * Phrases vendors use for "you sent something this model does not take".
     *
     * Matched against the lowercased message, so they are lowercase here.
     *
     * @var string[]
     */
    private const REJECTION_PHRASES = [
        'unsupported parameter', 'unsupported value', 'not supported', 'unsupported',
        'unrecognized request argument', 'unrecognized', 'unknown parameter', 'unknown name',
        'unknown field', 'extra inputs are not permitted', 'extra_forbidden', 'is deprecated',
        'does not support', 'only the default', 'not allowed', 'not permitted', 'invalid',
        'cannot find field',
    ];

    /**
     * Diagnose a rejected request.
     *
     * @param int $status HTTP status.
     * @param string $errorbody Provider error body (redacted).
     * @param array $sent The request body that was rejected, decoded.
     * @param array $profile The capability profile the request was built from.
     * @return array{field: string, value: string, note: string}|null
     */
    public static function diagnose(int $status, string $errorbody, array $sent, array $profile): ?array {
        if (!in_array($status, self::HEALABLE_STATUSES, true)) {
            return null;
        }
        $message = self::message($errorbody);
        if ($message === '') {
            return null;
        }
        $lower = strtolower($message);

        foreach (
            [
            'output_limit',
            'token_param',
            'temperature',
            'reasoning_effort',
            'gemini_thinking',
            'claude_thinking',
            'tool_choice',
            ] as $rule
        ) {
            $fix = self::{'rule_' . $rule}($lower, $sent, $profile);
            if ($fix !== null) {
                $fix['note'] = \core_text::substr($message, 0, 300);
                return $fix;
            }
        }
        return null;
    }

    /**
     * The vendor's human-readable message, plus its `param` field when present.
     *
     * OpenAI and Anthropic nest it at error.message, Gemini's compatibility
     * endpoint wraps the same object in a list, FastAPI-based servers (vLLM)
     * put a list under `detail`, and some proxies return plain text.
     *
     * @param string $body
     * @return string
     */
    public static function message(string $body): string {
        $body = trim($body);
        if ($body === '') {
            return '';
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $body;
        }
        if (isset($decoded[0]) && is_array($decoded[0])) {
            $decoded = $decoded[0];
        }
        $parts = [];
        $error = $decoded['error'] ?? null;
        if (is_array($error)) {
            if (!empty($error['message'])) {
                $parts[] = (string) $error['message'];
            }
            if (!empty($error['param'])) {
                $parts[] = 'param: ' . (string) $error['param'];
            }
        } else if (is_string($error) && $error !== '') {
            $parts[] = $error;
        }
        if (!empty($decoded['message']) && is_string($decoded['message'])) {
            $parts[] = $decoded['message'];
        }
        if (!empty($decoded['detail'])) {
            if (is_string($decoded['detail'])) {
                $parts[] = $decoded['detail'];
            } else if (is_array($decoded['detail'])) {
                foreach ($decoded['detail'] as $item) {
                    if (is_array($item)) {
                        $loc = isset($item['loc']) && is_array($item['loc']) ? implode('.', $item['loc']) : '';
                        $parts[] = trim(($item['msg'] ?? '') . ' ' . $loc);
                    }
                }
            }
        }
        return $parts ? implode(' | ', $parts) : $body;
    }

    /**
     * "max_tokens is too large: 200000. This model supports at most 16384",
     * "max_tokens: 100000 > 64000, which is the maximum allowed".
     *
     * @param string $msg
     * @param array $sent
     * @param array $profile
     * @return array|null
     */
    private static function rule_output_limit(string $msg, array $sent, array $profile): ?array {
        $param = null;
        foreach (['max_completion_tokens', 'max_tokens'] as $name) {
            if (isset($sent[$name]) && str_contains($msg, $name)) {
                $param = $name;
                break;
            }
        }
        if ($param === null) {
            return null;
        }
        $limit = null;
        if (preg_match('/>\s*(\d{3,7}),?\s*which is the maximum/', $msg, $m)) {
            $limit = (int) $m[1];
        } else if (
            preg_match(
                '/(?:at most|maximum(?: allowed)?(?: value)?(?: is| of)?|less than or equal to|<=|up to)\s*:?\s*(\d{3,7})/',
                $msg,
                $m
            )
        ) {
            $limit = (int) $m[1];
        }
        if ($limit === null || $limit <= 0 || $limit >= (int) $sent[$param]) {
            return null;
        }
        $current = $profile['max_output_tokens'] ?? null;
        if ($current !== null && (int) $current === $limit) {
            return null;
        }
        return ['field' => 'max_output_tokens', 'value' => (string) $limit];
    }

    /**
     * max_tokens versus max_completion_tokens, in either direction.
     *
     * @param string $msg
     * @param array $sent
     * @param array $profile
     * @return array|null
     */
    private static function rule_token_param(string $msg, array $sent, array $profile): ?array {
        if (($profile['family'] ?? '') === model_capabilities::FAMILY_CLAUDE) {
            // Anthropic has one name and requires it.
            return null;
        }
        if (
            isset($sent['max_tokens']) && !isset($sent['max_completion_tokens'])
                && self::names($msg, 'max_tokens')
                && (str_contains($msg, 'max_completion_tokens') || self::rejects($msg))
        ) {
            return ['field' => 'token_param', 'value' => model_capabilities::TOKENS_MAX_COMPLETION];
        }
        if (
            isset($sent['max_completion_tokens'])
                && self::names($msg, 'max_completion_tokens') && self::rejects($msg)
                && !str_contains($msg, "use 'max_completion_tokens'")
        ) {
            return ['field' => 'token_param', 'value' => model_capabilities::TOKENS_MAX];
        }
        return null;
    }

    /**
     * Temperature rejected outright, deprecated, or limited to the default.
     *
     * @param string $msg
     * @param array $sent
     * @param array $profile
     * @return array|null
     */
    private static function rule_temperature(string $msg, array $sent, array $profile): ?array {
        if (!array_key_exists('temperature', $sent) || !self::names($msg, 'temperature')) {
            return null;
        }
        if (!self::rejects($msg) && !str_contains($msg, 'only supports')) {
            return null;
        }
        return ['field' => 'temperature', 'value' => model_capabilities::TEMP_OMIT];
    }

    /**
     * reasoning_effort: a value outside the supported list, or the field itself.
     *
     * @param string $msg
     * @param array $sent
     * @param array $profile
     * @return array|null
     */
    private static function rule_reasoning_effort(string $msg, array $sent, array $profile): ?array {
        if (!isset($sent['reasoning_effort']) || !self::names($msg, 'reasoning_effort')) {
            return null;
        }
        if (preg_match('/supported values are:?\s*(.+)$/', $msg, $m)) {
            preg_match_all("/'([a-z]+)'/", $m[1], $values);
            $allowed = array_values(array_intersect(model_capabilities::EFFORT_ORDER, $values[1] ?? []));
            $sentvalue = (string) $sent['reasoning_effort'];
            if (!empty($allowed) && !in_array($sentvalue, $allowed, true)) {
                return ['field' => 'reasoning_efforts', 'value' => implode(',', $allowed)];
            }
        }
        if (self::rejects($msg)) {
            return ['field' => 'reasoning', 'value' => model_capabilities::REASONING_NONE];
        }
        return null;
    }

    /**
     * Gemini thinking_config: a budget of 0 refused, or no thinking control at all.
     *
     * @param string $msg
     * @param array $sent
     * @param array $profile
     * @return array|null
     */
    private static function rule_gemini_thinking(string $msg, array $sent, array $profile): ?array {
        $budget = $sent['extra_body']['google']['thinking_config']['thinking_budget'] ?? null;
        if ($budget === null) {
            return null;
        }
        if (
            (int) $budget === 0 && (str_contains($msg, 'only works in thinking mode')
                || (str_contains($msg, 'budget') && str_contains($msg, 'invalid')))
        ) {
            return ['field' => 'thinking_off', 'value' => 'forbidden'];
        }
        $named = str_contains($msg, 'thinking') || str_contains($msg, 'extra_body');
        if ($named && self::rejects($msg)) {
            return ['field' => 'reasoning', 'value' => model_capabilities::REASONING_NONE];
        }
        return null;
    }

    /**
     * Anthropic extended thinking refused for this model.
     *
     * @param string $msg
     * @param array $sent
     * @param array $profile
     * @return array|null
     */
    private static function rule_claude_thinking(string $msg, array $sent, array $profile): ?array {
        if (!isset($sent['thinking']) || ($profile['family'] ?? '') !== model_capabilities::FAMILY_CLAUDE) {
            return null;
        }
        if (self::names($msg, 'thinking') && self::rejects($msg)) {
            return ['field' => 'reasoning', 'value' => model_capabilities::REASONING_NONE];
        }
        return null;
    }

    /**
     * Anthropic forced tool choice refused for this model.
     *
     * @param string $msg
     * @param array $sent
     * @param array $profile
     * @return array|null
     */
    private static function rule_tool_choice(string $msg, array $sent, array $profile): ?array {
        $type = $sent['tool_choice']['type'] ?? null;
        if (!in_array($type, ['tool', 'any'], true) || !self::names($msg, 'tool_choice')) {
            return null;
        }
        if (!self::rejects($msg)) {
            return null;
        }
        return ['field' => 'forced_tool_choice', 'value' => '0'];
    }

    /**
     * Does the message name this parameter as a whole word?
     *
     * @param string $msg
     * @param string $param
     * @return bool
     */
    private static function names(string $msg, string $param): bool {
        return preg_match('/(?<![a-z_])' . preg_quote($param, '/') . '(?![a-z_])/', $msg) === 1;
    }

    /**
     * Does the message say a parameter or value was refused?
     *
     * @param string $msg
     * @return bool
     */
    private static function rejects(string $msg): bool {
        foreach (self::REJECTION_PHRASES as $phrase) {
            if (str_contains($msg, $phrase)) {
                return true;
            }
        }
        return false;
    }
}
