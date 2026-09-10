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
 * Base provider for all OpenAI-compatible APIs.
 *
 * Handles the standard OpenAI chat completions format. Subclasses only need
 * to override default base URL, auth header, and endpoint path.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class openai_compatible_provider extends base_provider {
    /** @var array|null Token usage from the last call, streaming or not.
     *  v5.11.0 adds `cached_tokens` so dashboards can see the OpenAI auto-prefix
     *  discount hit rate (cached_tokens get 50% off input; auto-fires on any
     *  prompt >=1024 tokens with a stable prefix; no opt-in needed).
     *  v7.4.2 adds `reasoning_tokens` (completion_tokens_details.reasoning_tokens),
     *  recorded as reported and never folded into completion_tokens -- see
     *  {@see shape_usage()} for why. */
    protected ?array $last_token_usage = null;

    /**
     * Get token usage from the last streaming call.
     *
     * @return array|null ['prompt_tokens', 'completion_tokens', 'model',
     *                      'cached_tokens', 'reasoning_tokens'] or null.
     */
    public function get_last_token_usage(): ?array {
        return $this->last_token_usage;
    }

    /**
     * Shape a provider `usage` object into SOLA's canonical usage array.
     *
     * ONE reader for BOTH the streaming and the non-streaming path. Those two
     * drifted once already: usage capture lived only in
     * chat_completion_stream() until v7.0.6, so every non-streaming caller
     * reported no tokens at all. Doing the shaping in a single place means a
     * newly-added counter cannot land on one path and be forgotten on the other.
     *
     * WHY reasoning_tokens is stored SEPARATELY and never folded into
     * completion_tokens: whether thinking tokens are ALREADY counted inside
     * completion_tokens differs by vendor. OpenAI includes them. Google's
     * Gemini OpenAI-compatibility shim has not reliably done so, yet Google
     * bills thinking as output. So adding it into completion_tokens would
     * double-count OpenAI, and ignoring it keeps under-counting Gemini -- which
     * is the largest remaining source of the spend-log undercount (a
     * reconciliation against the Gemini invoice measured 0.35M completion
     * tokens logged against 1.78M billed). We therefore record exactly what the
     * provider reported, in its own field, and leave the add-or-not decision to
     * the consumer, which knows which provider served the call.
     *
     * @param array|null  $usage The raw `usage` object from the API response.
     * @param string|null $model Model id the response reported, if any.
     * @return array|null Canonical usage array, or null when the call reported no usage.
     */
    protected function shape_usage(?array $usage, ?string $model): ?array {
        if (empty($usage)) {
            return null;
        }

        // reasoning_tokens stays null -- not 0 -- when the provider reported no
        // completion_tokens_details block at all, so the spend pipeline can tell
        // "this model does not report thinking" apart from "it thought zero
        // tokens this call".
        $reasoning = $usage['completion_tokens_details']['reasoning_tokens'] ?? null;

        return [
            'prompt_tokens'     => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'model'             => ($model !== null && $model !== '') ? $model : $this->model,
            'cached_tokens'     => (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0),
            'reasoning_tokens'  => $reasoning === null ? null : (int) $reasoning,
        ];
    }

    /**
     * Get the chat completions endpoint path.
     *
     * @return string
     */
    protected function get_endpoint(): string {
        return '/v1/chat/completions';
    }

    /**
     * Get request headers.
     *
     * @return array
     */
    protected function get_headers(): array {
        $headers = [
            'Content-Type: application/json',
        ];
        if (!empty($this->apikey)) {
            $headers[] = 'Authorization: Bearer ' . $this->apikey;
        }
        return $headers;
    }

    /**
     * Whether this model expects max_completion_tokens instead of max_tokens.
     *
     * GPT-5 and OpenAI reasoning-series chat models reject max_tokens.
     *
     * @return bool
     */
    protected function uses_max_completion_tokens(): bool {
        $model = strtolower(trim($this->model));
        if ($model === '') {
            return false;
        }

        if (str_starts_with($model, 'gpt-5')) {
            return true;
        }

        return preg_match('/^o(?:1|3|4)(?:[-.]|$)/', $model) === 1;
    }

    /**
     * Build the request body.
     *
     * @param string $systemprompt
     * @param array $messages
     * @param bool $stream
     * @param array $options
     * @return string JSON body.
     */
    protected function build_body(string $systemprompt, array $messages, bool $stream, array $options): string {
        // Prepend system message.
        $apimessages = [];
        if (!empty($systemprompt)) {
            $apimessages[] = ['role' => 'system', 'content' => $systemprompt];
        }

        foreach ($messages as $msg) {
            $apimessages[] = [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        // Multimodal: attach one or more images to the latest user message as a
        // content-block array, matching the OpenAI chat/completions schema that
        // Gemini, xAI, and other compatible endpoints also accept.
        // options['attachment']     => single {base64, mime} image
        // options['image_datauris'] => list of full data: URI strings (slide vision)
        $imageurls = [];
        if (!empty($options['attachment']['base64']) && !empty($options['attachment']['mime'])) {
            $imageurls[] = 'data:' . $options['attachment']['mime']
                . ';base64,' . $options['attachment']['base64'];
        }
        if (!empty($options['image_datauris']) && is_array($options['image_datauris'])) {
            foreach ($options['image_datauris'] as $uri) {
                $uri = (string) $uri;
                if (str_starts_with($uri, 'data:image/')) {
                    $imageurls[] = $uri;
                }
            }
        }
        if (!empty($imageurls)) {
            for ($i = count($apimessages) - 1; $i >= 0; $i--) {
                if (($apimessages[$i]['role'] ?? '') === 'user') {
                    $text = is_string($apimessages[$i]['content']) ? $apimessages[$i]['content'] : '';
                    $content = [['type' => 'text', 'text' => $text]];
                    foreach ($imageurls as $url) {
                        $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
                    }
                    $apimessages[$i]['content'] = $content;
                    break;
                }
            }
        }

        $body = [
            'model' => $this->model,
            'messages' => $apimessages,
            'temperature' => $options['temperature'] ?? $this->temperature,
        ];

        if (isset($options['max_tokens'])) {
            $tokenfield = $this->uses_max_completion_tokens() ? 'max_completion_tokens' : 'max_tokens';
            $body[$tokenfield] = $options['max_tokens'];
        }

        if (!empty($options['response_schema'])) {
            $schema = $options['response_schema'];
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schema['name'] ?? 'structured_output',
                    // Accept both the wrapped shape ['name'=>..,'schema'=>[..]]
                    // and a bare JSON Schema. score_speech, score_essay and
                    // generate_flashcards all passed bare schemas, so this read
                    // yielded null and the request carried "schema": null --
                    // which upstream rejects, taking every speech, essay and
                    // flashcard scoring call down with a generic provider error.
                    'schema' => $schema['schema'] ?? $schema,
                    'strict' => true,
                ],
            ];
        }

        if ($stream) {
            $body['stream'] = true;
            $body['stream_options'] = ['include_usage' => true];
        }

        return self::encode_payload($body);
    }

    public function chat_completion(string $systemprompt, array $messages, array $options = []): string {
        $url = $this->baseurl . $this->get_endpoint();
        $body = $this->build_body($systemprompt, $messages, false, $options);
        $response = $this->http_post($url, $this->get_headers(), $body);

        $data = json_decode($response, true);
        if (!$data || !isset($data['choices'][0]['message']['content'])) {
            throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null, 'Invalid API response');
        }

        // v7.0.6: capture usage on the NON-streaming path too. Until now only
        // chat_completion_stream() populated this, so every non-streaming
        // caller -- quiz generation, the mastery classifier, digests, essay
        // scoring -- reported no tokens at all and contributed nothing to
        // spend_guard's totals. The non-streaming response carries the same
        // usage object; there was no reason to drop it.
        $this->last_token_usage = $this->shape_usage(
            isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : null,
            isset($data['model']) ? (string) $data['model'] : null
        );

        return $data['choices'][0]['message']['content'];
    }

    public function chat_completion_stream(string $systemprompt, array $messages, callable $callback, array $options = []): void {
        $url = $this->baseurl . $this->get_endpoint();
        $body = $this->build_body($systemprompt, $messages, true, $options);

        $buffer = '';
        $this->last_token_usage = null;

        $this->http_post_stream($url, $this->get_headers(), $body, function ($data) use ($callback, &$buffer) {
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

                // Capture usage from the final usage-only chunk (stream_options: include_usage: true).
                // This chunk has empty choices[] and a populated usage object.
                if (!empty($event['usage']) && is_array($event['usage'])) {
                    $this->last_token_usage = $this->shape_usage(
                        $event['usage'],
                        isset($event['model']) ? (string) $event['model'] : null
                    );
                }

                $content = $event['choices'][0]['delta']['content'] ?? '';
                if ($content !== '') {
                    $callback($content);
                }
            }
        });
    }
}
