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
abstract class openai_compatible_provider extends base_provider implements batch_capable_interface {
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
            // v7.4.4: the provider that ACTUALLY served this call. Consumers used
            // to re-derive it from course config, which is what config said should
            // serve the turn -- wrong whenever the premium router escalated, 'auto'
            // resolved, a spend cap forced a failover, or the failover chain moved
            // to a fallback. Reported here because shape_usage() is already the one
            // reader for both the streaming and non-streaming paths, which is
            // exactly the place a value like this must live to avoid drifting.
            'provider'          => $this->provider_id(),
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
    // ─────────────────────────────────────────────────────────────────────
    // OpenAI Batch API: submit / poll / collect.
    //
    // Half price on input AND output, for a completion window of up to 24
    // hours. That trade is only available to callers nobody is waiting on, so
    // the ONLY consumer in this plugin is the scheduled Learning Radar run
    // (classes/task/run_meta_ai_query.php submits, classes/task/
    // collect_meta_ai_batches.php collects). Every learner-facing call stays
    // synchronous; see the class docblock on collect_meta_ai_batches for the
    // caller analysis.
    //
    // The 50% discount is NOT applied here. It is applied where prices live,
    // by recording the model as `batch/<model>` and letting
    // model_registry::rate_for() halve the resolved rate -- so the dashboard,
    // the spend guard, the anomaly detector and the CSV export all see the
    // discount without any of them knowing that batch exists.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Hosts whose OpenAI-compatible endpoint also serves /v1/files and
     * /v1/batches with the JSONL contract implemented below.
     *
     * Deliberately an allow-list of ONE. Ten concrete providers inherit this
     * class and all ten speak /v1/chat/completions, but batch is a separate
     * product with a separate wire format, and most of those hosts return 404
     * for /v1/batches. Google does offer a 50%-off batch tier, and Gemini rows
     * would price correctly through the same `batch/` marker -- but it is
     * reached through the Gemini-native batches endpoint, NOT through the
     * OpenAI-compatibility shim this class talks to, so adding 'generativelanguage.googleapis.com'
     * here would submit a JSONL file to a route that does not exist and lose
     * the report silently. That is a new provider method, not a new host string.
     *
     * A site behind an OpenAI-compatible proxy therefore reports
     * supports_batch() === false and keeps the synchronous path, which is the
     * correct failure: a report that arrives is worth more than a discount.
     *
     * @var string[]
     */
    protected const BATCH_HOSTS = ['api.openai.com'];

    /** Completion window requested for every batch. The only value OpenAI accepts today. */
    protected const BATCH_COMPLETION_WINDOW = '24h';

    /** Cap on how many bytes of a batch error file are retained in a message. */
    private const BATCH_ERROR_LIMIT = 1024;

    public function supports_batch(): bool {
        $base = $this->batch_url('');
        if (!\local_ai_course_assistant\security::is_safe_provider_url($base . '/batches')) {
            return false;
        }
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        return $host !== '' && in_array($host, static::BATCH_HOSTS, true);
    }

    public function submit_batch(array $requests): string {
        if (empty($requests)) {
            throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null,
                'submit_batch called with no requests');
        }

        $endpoint = $this->batch_request_endpoint();
        $lines = [];
        foreach ($requests as $customid => $req) {
            $customid = (string) $customid;
            if ($customid === '') {
                throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null,
                    'submit_batch requires a non-empty custom_id for every request');
            }
            // Reuse build_body() rather than assembling a second payload shape.
            // The batch line body must be byte-identical in MEANING to what the
            // synchronous call would have sent, or a site that switches batch
            // off gets a different answer from the same schedule -- and the
            // response_format / max_tokens / temperature handling in there is
            // exactly the part that is easy to get subtly wrong twice.
            $body = json_decode($this->build_body(
                (string) ($req['systemprompt'] ?? ''),
                (array) ($req['messages'] ?? []),
                false,
                (array) ($req['options'] ?? [])
            ), true);
            $lines[] = self::encode_payload([
                'custom_id' => $customid,
                'method'    => 'POST',
                'url'       => $endpoint,
                'body'      => $body,
            ]);
        }
        $jsonl = implode("\n", $lines) . "\n";

        $fileid = $this->upload_batch_input($jsonl);

        $created = json_decode($this->batch_http(
            'POST',
            $this->batch_url('/batches'),
            array_merge($this->get_headers(), ['Content-Type: application/json']),
            self::encode_payload([
                'input_file_id'     => $fileid,
                'endpoint'          => $endpoint,
                'completion_window' => static::BATCH_COMPLETION_WINDOW,
            ])
        ), true);

        if (empty($created['id'])) {
            throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null,
                'Batch creation returned no id');
        }
        return (string) $created['id'];
    }

    public function fetch_batch(string $batchid): array {
        $batchid = trim($batchid);
        if ($batchid === '') {
            throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null,
                'fetch_batch called with an empty batch id');
        }

        $batch = json_decode($this->batch_http(
            'GET',
            $this->batch_url('/batches/' . rawurlencode($batchid)),
            $this->get_headers(),
            null
        ), true);

        $raw = strtolower((string) ($batch['status'] ?? ''));
        $status = self::map_batch_status($raw);
        $out = ['status' => $status, 'results' => [], 'error' => null];

        if ($status === batch_capable_interface::BATCH_PENDING) {
            return $out;
        }

        // A failed / expired / cancelled batch may still carry an error file
        // naming WHY, and "the nightly report stopped arriving" is not a
        // diagnosis an operator can act on.
        if (!empty($batch['error_file_id'])) {
            $out['error'] = $this->read_batch_error_file((string) $batch['error_file_id']);
        } else if (!empty($batch['errors']['data'][0]['message'])) {
            $out['error'] = (string) $batch['errors']['data'][0]['message'];
        }

        if ($status !== batch_capable_interface::BATCH_COMPLETED) {
            return $out;
        }

        if (empty($batch['output_file_id'])) {
            // Completed with nothing to collect is a failure from our side,
            // whatever the vendor calls it: there is no report to deliver.
            $out['status'] = batch_capable_interface::BATCH_FAILED;
            $out['error'] = $out['error'] ?? 'Batch completed with no output file';
            return $out;
        }

        $content = $this->batch_http(
            'GET',
            $this->batch_url('/files/' . rawurlencode((string) $batch['output_file_id']) . '/content'),
            $this->get_headers(),
            null
        );

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row) || empty($row['custom_id'])) {
                continue;
            }
            $out['results'][(string) $row['custom_id']] = $this->shape_batch_result($row);
        }

        if (empty($out['results'])) {
            $out['status'] = batch_capable_interface::BATCH_FAILED;
            $out['error'] = $out['error'] ?? 'Batch output file contained no parseable results';
        }

        return $out;
    }

    public function cancel_batch(string $batchid): bool {
        $batchid = trim($batchid);
        if ($batchid === '') {
            return false;
        }
        try {
            $this->batch_http(
                'POST',
                $this->batch_url('/batches/' . rawurlencode($batchid) . '/cancel'),
                array_merge($this->get_headers(), ['Content-Type: application/json']),
                '{}'
            );
            return true;
        } catch (\Throwable $e) {
            // A batch that already finished cannot be cancelled, and that is
            // not an error worth propagating into a cron run.
            return false;
        }
    }

    /**
     * One JSONL output line -> the canonical per-request result shape.
     *
     * @param array $row Decoded output line.
     * @return array{content: ?string, usage: ?array, error: ?string}
     */
    private function shape_batch_result(array $row): array {
        $status = (int) ($row['response']['status_code'] ?? 0);
        $body = $row['response']['body'] ?? null;

        if (!empty($row['error']['message'])) {
            return ['content' => null, 'usage' => null, 'error' => (string) $row['error']['message']];
        }
        if ($status >= 400 || !is_array($body)) {
            $msg = is_array($body) && !empty($body['error']['message'])
                ? (string) $body['error']['message']
                : ('Batch request returned HTTP ' . $status);
            return ['content' => null, 'usage' => null, 'error' => $msg];
        }

        $content = $body['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || $content === '') {
            return ['content' => null, 'usage' => null, 'error' => 'Batch response carried no message content'];
        }

        // shape_usage() is the SAME reader the streaming and non-streaming
        // paths use, so a batch row records prompt / completion / cached /
        // reasoning tokens in exactly the shape the spend pipeline already
        // understands -- and, critically, REAL counts rather than the strlen/4
        // approximation the Learning Radar used to persist.
        return [
            'content' => $content,
            'usage'   => $this->shape_usage(
                isset($body['usage']) && is_array($body['usage']) ? $body['usage'] : null,
                isset($body['model']) ? (string) $body['model'] : null
            ),
            'error'   => null,
        ];
    }

    /**
     * Upload the JSONL input file and return its file id.
     *
     * The multipart body is assembled by hand rather than handed to \curl as
     * an array, so the exact bytes on the wire are visible here and do not
     * depend on how Moodle's curl wrapper decides to encode a CURLFile. It
     * also means no temp file is written for what is a few kilobytes of JSON.
     *
     * @param string $jsonl
     * @return string Provider file id.
     */
    private function upload_batch_input(string $jsonl): string {
        $boundary = 'sola' . bin2hex(random_bytes(16));
        $eol = "\r\n";
        $body = '--' . $boundary . $eol
            . 'Content-Disposition: form-data; name="purpose"' . $eol . $eol
            . 'batch' . $eol
            . '--' . $boundary . $eol
            . 'Content-Disposition: form-data; name="file"; filename="sola_radar_batch.jsonl"' . $eol
            . 'Content-Type: application/jsonl' . $eol . $eol
            . $jsonl . $eol
            . '--' . $boundary . '--' . $eol;

        $headers = $this->get_headers();
        // get_headers() hardcodes application/json, which would make the
        // upload a 400 with a body that says nothing about the real cause.
        $headers = array_values(array_filter($headers, static function ($h) {
            return stripos((string) $h, 'content-type:') !== 0;
        }));
        $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;

        $decoded = json_decode($this->batch_http('POST', $this->batch_url('/files'), $headers, $body), true);
        if (empty($decoded['id'])) {
            throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null,
                'Batch input upload returned no file id');
        }
        return (string) $decoded['id'];
    }

    /**
     * Read a batch error file and return a truncated, human-usable message.
     *
     * @param string $fileid
     * @return string|null
     */
    private function read_batch_error_file(string $fileid): ?string {
        try {
            $raw = $this->batch_http(
                'GET',
                $this->batch_url('/files/' . rawurlencode($fileid) . '/content'),
                $this->get_headers(),
                null
            );
        } catch (\Throwable $e) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $first = strtok($raw, "\n");
        $decoded = json_decode((string) $first, true);
        $msg = $decoded['error']['message']
            ?? $decoded['response']['body']['error']['message']
            ?? (string) $first;
        return \core_text::substr((string) $msg, 0, self::BATCH_ERROR_LIMIT);
    }

    /**
     * Base URL for the batch-family endpoints, always ending at `/v1`.
     *
     * Mirrors detect_context_window(): most configured base URLs stop short of
     * /v1, but some already include it, and doubling it produces a 404 that
     * looks exactly like "this host has no batch support".
     *
     * @param string $path Path under /v1, e.g. '/batches'. Empty returns the base.
     * @return string
     */
    private function batch_url(string $path): string {
        $base = rtrim($this->baseurl, '/');
        if (strpos($base, '/v1') === false) {
            $base .= '/v1';
        }
        return $base . $path;
    }

    /**
     * The endpoint path each batched request targets, normalised to /v1/....
     *
     * @return string
     */
    private function batch_request_endpoint(): string {
        $ep = $this->get_endpoint();
        if (strpos($ep, '/v1') !== 0) {
            $ep = '/v1' . (str_starts_with($ep, '/') ? $ep : '/' . $ep);
        }
        return $ep;
    }

    /**
     * One HTTP call against the batch family, with the plugin's URL guard and
     * error decoding applied.
     *
     * Deliberately NOT routed through http_post(): that method wraps every call
     * in with_transient_retry(), which is right for a chat turn a learner is
     * waiting on and wrong here. A batch submission is not idempotent -- a
     * retried POST /v1/batches creates a SECOND batch, which is a second
     * invoice and a second copy of the report -- and a poll that fails is
     * simply retried by the next cron run half an hour later.
     *
     * @param string      $method  'GET' or 'POST'.
     * @param string      $url
     * @param array       $headers
     * @param string|null $body    Raw request body for POST.
     * @return string Response body.
     * @throws \moodle_exception
     */
    private function batch_http(string $method, string $url, array $headers, ?string $body): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if (!\local_ai_course_assistant\security::is_safe_provider_url($url)) {
            throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null,
                'Refusing to call an unsafe batch URL');
        }

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_HTTPHEADER'     => $headers,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT'        => 120,
        ]);

        $response = $method === 'GET' ? $curl->get($url) : $curl->post($url, (string) $body);
        if ($curl->error) {
            throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null,
                'Transport error: ' . $curl->error);
        }
        $this->check_http_error((int) ($curl->get_info()['http_code'] ?? 0), (string) $response);

        return (string) $response;
    }

    /**
     * Map the vendor's batch status onto the interface's five states.
     *
     * Every transitional vendor state collapses to BATCH_PENDING, including
     * 'cancelling': a cancellation in flight is still in flight, and treating
     * it as terminal would leave a job row closed while the batch was still
     * capable of producing output.
     *
     * @param string $raw Lowercased vendor status.
     * @return string One of the batch_capable_interface constants.
     */
    private static function map_batch_status(string $raw): string {
        switch ($raw) {
            case 'completed':
                return batch_capable_interface::BATCH_COMPLETED;
            case 'failed':
                return batch_capable_interface::BATCH_FAILED;
            case 'expired':
                return batch_capable_interface::BATCH_EXPIRED;
            case 'cancelled':
            case 'canceled':
                return batch_capable_interface::BATCH_CANCELLED;
            case 'validating':
            case 'in_progress':
            case 'finalizing':
            case 'cancelling':
            case 'canceling':
                return batch_capable_interface::BATCH_PENDING;
            default:
                // An unknown status is treated as still running rather than as
                // failed: the age guard in radar_batch_manager closes a job out
                // eventually, whereas declaring a live batch dead loses a report
                // that was already paid for.
                return batch_capable_interface::BATCH_PENDING;
        }
    }
}
