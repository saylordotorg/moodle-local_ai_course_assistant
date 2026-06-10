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
     * Whether the given Anthropic model accepts a `temperature` parameter.
     * Opus 4.7 and 4.8 (reasoning-class models) reject temperature with HTTP
     * 400; their successors will likely behave the same way. Maintain this
     * as a per-prefix denylist so the model-specific 400 doesn't bubble up
     * as the generic "something went wrong" error.
     *
     * @param string $model
     * @return bool
     */
    private static function model_supports_temperature(string $model): bool {
        $denyprefixes = ['claude-opus-4-7', 'claude-opus-4-8', 'claude-opus-4-9'];
        foreach ($denyprefixes as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return false;
            }
        }
        return true;
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

        $this->http_post_stream($url, $this->get_headers($options), $body, function ($data) use ($callback, &$buffer) {
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

                // Only forward text deltas; skip thinking deltas and tool input deltas.
                if ($eventtype === 'content_block_delta') {
                    $deltatype = $event['delta']['type'] ?? '';
                    if ($deltatype === 'text_delta') {
                        $text = $event['delta']['text'] ?? '';
                        if ($text !== '') {
                            $callback($text);
                        }
                    }
                }
            }
        });
    }
}
