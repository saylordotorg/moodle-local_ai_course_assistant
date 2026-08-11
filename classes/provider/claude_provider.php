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

/**
 * Claude (Anthropic) provider.
 *
 * Uses the Anthropic Messages API with x-api-key authentication.
 * Supports prompt caching, adaptive thinking, and structured outputs.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class claude_provider extends base_provider {
    /** @var string Anthropic API version */
    private const API_VERSION = '2023-06-01';

    /**
     * @var string stop_reason returned when the model's own safety layer
     * declines the request. The response carries NO content blocks at all,
     * so it must be handled explicitly or it looks like a malformed reply.
     */
    public const STOP_REASON_REFUSAL = 'refusal';

    /** @var array|null Token usage from the last streaming call */
    private ?array $last_token_usage = null;

    /**
     * Get token usage from the last streaming call.
     *
     * @return array|null ['prompt_tokens', 'completion_tokens', 'model',
     *                      'cache_creation_tokens', 'cache_read_tokens'] or null.
     */
    public function get_last_token_usage(): ?array {
        return $this->last_token_usage;
    }

    protected function get_default_model(): string {
        return 'claude-sonnet-4-20250514';
    }

    /**
     * Prefixes of Anthropic models known to ACCEPT sampling parameters.
     *
     * Used as the default when the `claude_temperature_allow_prefixes` setting
     * is empty. Deliberately an allow-list, not a deny-list: see
     * {@see model_supports_temperature()} for why.
     *
     * @var string[]
     */
    public const DEFAULT_TEMPERATURE_ALLOW_PREFIXES = [
        // Aliases.
        'claude-opus-4-6', 'claude-opus-4-5', 'claude-opus-4-1', 'claude-opus-4-0',
        'claude-sonnet-4-6', 'claude-sonnet-4-5', 'claude-sonnet-4-0',
        'claude-haiku-4-5', 'claude-haiku-3-5', 'claude-haiku-3',
        // Dated full IDs for the 4.0 generation, whose alias form does not
        // prefix-match them: e.g. claude-sonnet-4-20250514 (this provider's
        // own get_default_model()) and claude-opus-4-20250514. We cannot use a
        // bare 'claude-opus-4' prefix here because it would also match the
        // denied claude-opus-4-7 / 4-8.
        'claude-opus-4-2025', 'claude-sonnet-4-2025',
        // Claude 3.x and 2.x, all of which accept sampling parameters.
        'claude-3', 'claude-2',
    ];

    /**
     * Whether the given Anthropic model accepts a `temperature` parameter.
     *
     * This is an ALLOW-list: a model we do not recognise is assumed NOT to
     * accept sampling parameters, and temperature is omitted.
     *
     * The reason is that the two failure modes are asymmetric:
     *   - Omitting temperature from a model that accepts it: the model uses
     *     its own default. Harmless.
     *   - Sending temperature to a model that rejects it: HTTP 400 on EVERY
     *     call, surfacing as the generic "something went wrong" error.
     *
     * Anthropic has removed sampling parameters from every reasoning-class
     * model since Opus 4.7 (Opus 4.7/4.8, and the whole Claude 5 family), so
     * an unrecognised model is now more likely to reject them than accept
     * them. Defaulting to "omit" means a newly released model works on day
     * one instead of failing every request until the plugin is updated.
     *
     * The list is read from the `claude_temperature_allow_prefixes` setting so
     * it can be corrected without a plugin release — by an admin, or pushed
     * fleet-wide via a signed policy bundle (the key is on
     * {@see \local_ai_course_assistant\policy_bundle::ALLOWED_KEYS}). This
     * mirrors how `rate_card_overrides` keeps per-model pricing current
     * without a redeploy.
     *
     * @param string $model
     * @return bool
     */
    private static function model_supports_temperature(string $model): bool {
        $model = strtolower(trim($model));
        if ($model === '') {
            return false;
        }
        foreach (self::temperature_allow_prefixes() as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The effective allow-list: the admin/policy-bundle setting when set and
     * parseable, otherwise the shipped default.
     *
     * @return string[]
     */
    private static function temperature_allow_prefixes(): array {
        $raw = (string) get_config('local_ai_course_assistant', 'claude_temperature_allow_prefixes');
        $out = [];
        foreach (preg_split('/[\r\n,]+/', $raw) as $line) {
            $line = strtolower(trim($line));
            if ($line !== '' && $line[0] !== '#') {
                $out[] = $line;
            }
        }
        return $out ?: self::DEFAULT_TEMPERATURE_ALLOW_PREFIXES;
    }

    protected function get_default_base_url(): string {
        return 'https://api.anthropic.com';
    }

    /**
     * Get request headers for Anthropic API.
     *
     * @param array $options Request options (may enable thinking via beta header).
     * @return array
     */
    private function get_headers(array $options = []): array {
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apikey,
            'anthropic-version: ' . self::API_VERSION,
        ];

        $betas = ['prompt-caching-2024-07-31'];
        if (!empty($options['thinking'])) {
            $betas[] = 'interleaved-thinking-2025-05-14';
        }
        $headers[] = 'anthropic-beta: ' . implode(',', $betas);

        return $headers;
    }

    /**
     * Build the request body for Anthropic Messages API.
     *
     * @param string $systemprompt
     * @param array $messages
     * @param bool $stream
     * @param array $options Keys: max_tokens, temperature, thinking (bool),
     *                       thinking_budget (int), response_schema (array).
     * @return string JSON body.
     */
    private function build_body(string $systemprompt, array $messages, bool $stream, array $options): string {
        $apimessages = array_map(function ($msg) {
            return [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }, $messages);

        // Multimodal: Claude accepts image content blocks on user messages.
        // Attach the student's image to the latest user turn as a base64
        // source block, preserving the original text as a text block.
        if (!empty($options['attachment']['base64']) && !empty($options['attachment']['mime'])) {
            for ($i = count($apimessages) - 1; $i >= 0; $i--) {
                if (($apimessages[$i]['role'] ?? '') === 'user') {
                    $text = is_string($apimessages[$i]['content']) ? $apimessages[$i]['content'] : '';
                    $apimessages[$i]['content'] = [
                        ['type' => 'text', 'text' => $text],
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $options['attachment']['mime'],
                                'data' => $options['attachment']['base64'],
                            ],
                        ],
                    ];
                    break;
                }
            }
        }

        $body = [
            'model' => $this->model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'system' => [
                [
                    'type' => 'text',
                    'text' => $systemprompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages' => $apimessages,
        ];

        // Adaptive thinking: Claude decides when and how much to reason.
        if (!empty($options['thinking'])) {
            $body['thinking'] = ['type' => 'adaptive'];
            $body['temperature'] = 1;
        } else if (!self::model_supports_temperature($this->model)) {
            // v5.11.0: Opus 4.7+ (and other reasoning-class models) reject the
            // temperature parameter with HTTP 400 "temperature is deprecated
            // for this model." Sending no temperature lets the model pick.
        } else {
            $body['temperature'] = $options['temperature'] ?? $this->temperature;
        }

        // Structured output via tool_use pattern (Claude's native structured output).
        if (!empty($options['response_schema'])) {
            $schema = $options['response_schema'];
            $body['tools'] = [[
                'name' => $schema['name'] ?? 'structured_output',
                'description' => $schema['description'] ?? 'Return structured data',
                'input_schema' => $schema['schema'],
            ]];
            $body['tool_choice'] = [
                'type' => 'tool',
                'name' => $schema['name'] ?? 'structured_output',
            ];
        }

        if ($stream) {
            $body['stream'] = true;
        }

        return json_encode($body);
    }

    public function chat_completion(string $systemprompt, array $messages, array $options = []): string {
        $url = $this->baseurl . '/v1/messages';
        $body = $this->build_body($systemprompt, $messages, false, $options);
        $response = $this->http_post($url, $this->get_headers($options), $body);

        $data = json_decode($response, true);

        // A model-level safety refusal is a WELL-FORMED response that carries
        // no content blocks, so it must be checked before the empty-content
        // guard below -- otherwise it is misreported as a transport failure
        // and the learner sees the generic "something went wrong" string.
        if (is_array($data) && ($data['stop_reason'] ?? '') === self::STOP_REASON_REFUSAL) {
            return get_string('chat:refused', 'local_ai_course_assistant');
        }

        if (!$data || empty($data['content'])) {
            throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null, 'Invalid API response');
        }

        // Capture token usage including cache metrics.
        if (isset($data['usage'])) {
            $this->last_token_usage = [
                'prompt_tokens'          => (int) ($data['usage']['input_tokens'] ?? 0),
                'completion_tokens'      => (int) ($data['usage']['output_tokens'] ?? 0),
                'model'                  => $data['model'] ?? $this->model,
                'cache_creation_tokens'  => (int) ($data['usage']['cache_creation_input_tokens'] ?? 0),
                'cache_read_tokens'      => (int) ($data['usage']['cache_read_input_tokens'] ?? 0),
            ];
        }

        // Structured output: extract tool_use input directly.
        if (!empty($options['response_schema'])) {
            foreach ($data['content'] as $block) {
                if (($block['type'] ?? '') === 'tool_use') {
                    return json_encode($block['input']);
                }
            }
        }

        // Standard text extraction: skip thinking blocks.
        foreach ($data['content'] as $block) {
            if (($block['type'] ?? '') === 'text') {
                return $block['text'];
            }
        }

        throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null, 'No text in response');
    }

    public function chat_completion_stream(string $systemprompt, array $messages, callable $callback, array $options = []): void {
        $url = $this->baseurl . '/v1/messages';
        $body = $this->build_body($systemprompt, $messages, true, $options);

        $buffer = '';
        $this->last_token_usage = null;
        // Tracks whether any text reached the learner, so a refusal notice is
        // only emitted when the stream produced nothing.
        $sentanytext = false;

        $this->http_post_stream(
            $url,
            $this->get_headers($options),
            $body,
            function ($data) use ($callback, &$buffer, &$sentanytext) {
                $buffer .= $data;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $line = trim($line);

                    if (empty($line) || !str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $json = substr($line, 6);
                    if ($json === '[DONE]') {
                        return;
                    }

                    $event = json_decode($json, true);
                    if (!$event) {
                        continue;
                    }

                    $eventtype = $event['type'] ?? '';

                    if ($eventtype === 'message_start') {
                        $usage = $event['message']['usage'] ?? [];
                        $this->last_token_usage = [
                        'prompt_tokens'          => (int) ($usage['input_tokens'] ?? 0),
                        'completion_tokens'      => 0,
                        'model'                  => $event['message']['model'] ?? $this->model,
                        'cache_creation_tokens'  => (int) ($usage['cache_creation_input_tokens'] ?? 0),
                        'cache_read_tokens'      => (int) ($usage['cache_read_input_tokens'] ?? 0),
                        ];
                    }

                    if ($eventtype === 'message_delta' && isset($event['usage']['output_tokens'])) {
                        if ($this->last_token_usage !== null) {
                            $this->last_token_usage['completion_tokens'] = (int) $event['usage']['output_tokens'];
                        }
                    }

                    // A safety refusal streams as a message_delta carrying
                    // stop_reason "refusal" with no content_block_delta events at
                    // all, so without this the learner would see an empty reply.
                    if (
                        $eventtype === 'message_delta'
                        && ($event['delta']['stop_reason'] ?? '') === self::STOP_REASON_REFUSAL
                        && !$sentanytext
                    ) {
                        $callback(get_string('chat:refused', 'local_ai_course_assistant'));
                        $sentanytext = true;
                    }

                    // Only forward text deltas; skip thinking deltas and tool input deltas.
                    if ($eventtype === 'content_block_delta') {
                        $deltatype = $event['delta']['type'] ?? '';
                        if ($deltatype === 'text_delta') {
                            $text = $event['delta']['text'] ?? '';
                            if ($text !== '') {
                                $sentanytext = true;
                                $callback($text);
                            }
                        }
                    }
                }
            }
        );
    }
}
