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

defined('MOODLE_INTERNAL') || die();

use local_ai_course_assistant\audit_logger;

/**
 * Per-call failover chain decorator for provider_interface.
 *
 * Wraps a primary provider plus an ordered list of fallback providers.
 * On a chat or stream call:
 *   1. Try the primary. If a circuit is open against the primary's label,
 *      skip directly to the head of the fallback list.
 *   2. On exception (HTTP 5xx, connect timeout, provider-side error),
 *      open a 15-minute circuit on that provider's label and try the next.
 *   3. On three consecutive successful calls against a previously failing
 *      label, close the circuit and restore normal priority.
 *   4. Every fall-through emits an audit row (event=failover_fallthrough)
 *      with primary, fallback, reason, and elapsed-ms. This is the SOC2
 *      evidence of operational resilience.
 *
 * Streaming wrinkle: the chain falls over only if the first token hasn't
 * been received by the time the primary errors. Once tokens are arriving
 * the user is committed to that stream; falling over mid-response would
 * produce a disjointed conversation. The current PHP-side implementation
 * cannot enforce a hard TTFT deadline (would require per-provider curl
 * timeout shaping); instead it relies on the provider's natural HTTP
 * timeout and treats a zero-token response as a failure that triggers
 * a fall-through.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class failover_chain implements provider_interface {
    /** @var provider_interface The primary provider (head of the chain). */
    private provider_interface $primary;

    /** @var string The label used to track the primary's circuit state in cache. */
    private string $primarylabel;

    /** @var array<int, array{provider: provider_interface, label: string}> Ordered fallbacks. */
    private array $fallbacks;

    /** @var array Decorator options (timeout_seconds, audit, courseid, userid). */
    private array $options;

    /** @var provider_interface|null Tracks which provider served the most recent call (for get_last_token_usage). */
    private ?provider_interface $lastused = null;

    /** Default per-call deadline before falling through. Seconds. */
    public const DEFAULT_TIMEOUT_SECONDS = 8;

    /** Audit event name emitted on every fall-through. */
    public const AUDIT_EVENT_FALLTHROUGH = 'failover_fallthrough';

    /** Audit event name emitted when a circuit opens. */
    public const AUDIT_EVENT_CIRCUIT_OPEN = 'failover_circuit_open';

    /** @var string v7.8.0: one row per turn a fallback answered after the primary failed. */
    public const AUDIT_EVENT_RESCUED = 'failover_rescued';

    /** @var string v7.8.8: one row per retry with a shortened history (outcome rescued or failed). */
    public const AUDIT_EVENT_TRIMMED = 'failover_history_trimmed';

    /** @var int v7.8.8: longest provider error text a failover audit row keeps. */
    public const DETAIL_LIMIT = 300;

    /**
     * @param provider_interface $primary
     * @param string $primarylabel Label of the primary, used for circuit-state lookups.
     * @param array $fallbacks Ordered fallback list. Each entry: ['provider' => provider_interface, 'label' => string].
     * @param array $options Optional: ['timeout_seconds' => int, 'audit' => bool, 'courseid' => int, 'userid' => int].
     */
    public function __construct(provider_interface $primary, string $primarylabel, array $fallbacks, array $options = []) {
        $this->primary = $primary;
        $this->primarylabel = $primarylabel;
        $this->fallbacks = $fallbacks;
        $this->options = $options + [
            'timeout_seconds' => self::DEFAULT_TIMEOUT_SECONDS,
            'audit'           => true,
            'courseid'        => 0,
            'userid'          => 0,
        ];
    }

    /**
     * Non-streaming chat completion. Tries primary, then each fallback.
     * Throws the last error if every member of the chain fails.
     *
     * @param string $systemprompt
     * @param array $messages
     * @param array $options
     * @return string
     * @throws \Throwable If every provider in the chain fails.
     */
    public function chat_completion(string $systemprompt, array $messages, array $options = []): string {
        $chain = $this->build_chain();
        // Same reason as the providers' own reset: a chain that fails must not report the last success's usage.
        $this->lastused = null;
        // v7.2.8: keep the FIRST failure and a trail of every attempt.
        // Rethrowing only the last one meant the surfaced error came from
        // the end of the chain, so a gemini primary that failed was
        // reported as an openai "model not found" -- pointing the admin at
        // a correct setting and hiding which provider actually broke.
        $firsterr = null;
        $attempts = [];
        $primaryfailed = false;
        foreach ($chain as $i => $entry) {
            if ($this->is_circuit_open($entry['label'])) {
                continue;
            }
            $start = microtime(true);
            try {
                $result = $entry['provider']->chat_completion($systemprompt, $messages, $options);
                $this->record_success($entry['label']);
                $this->lastused = $entry['provider'];
                if ($primaryfailed) {
                    $this->audit_rescued($entry);
                }
                return $result;
            } catch (\Throwable $e) {
                // The first attempt's own time: a retry below must not inflate it.
                $elapsed = microtime(true) - $start;
                // v7.8.8: a provider that refused this request outright is not
                // necessarily broken; ask once more with a shorter history.
                $trimmed = self::trimmed_history_for_retry($e, $messages);
                if ($trimmed) {
                    try {
                        $result = $entry['provider']->chat_completion($systemprompt, $trimmed, $options);
                        $this->record_success($entry['label']);
                        $this->lastused = $entry['provider'];
                        $this->audit_trimmed($entry, 'rescued', count($messages), count($trimmed), $e);
                        if ($primaryfailed) {
                            $this->audit_rescued($entry);
                        }
                        return $result;
                    } catch (\Throwable $retryerror) {
                        $this->audit_trimmed($entry, 'failed', count($messages), count($trimmed), $e, $retryerror);
                    }
                }
                $this->open_circuit($entry['label'], $e);
                $this->audit_fallthrough($entry, $e, $elapsed);
                $primaryfailed = $primaryfailed || $i === 0;
                $firsterr = $firsterr ?? $e;
                $attempts[] = $entry['label'] . ': ' . $e->getMessage();
            }
        }
        if ($firsterr !== null) {
            if ($firsterr instanceof \moodle_exception && count($attempts) > 1) {
                $trail = 'Failover chain exhausted (' . count($attempts) . ' attempts) -- '
                    . implode(' | ', $attempts);
                $firsterr->debuginfo = trim((string) ($firsterr->debuginfo ?? '') . ' ' . $trail);
            }
            throw $firsterr;
        }
        throw new \moodle_exception('chat:error_notconfigured', 'local_ai_course_assistant');
    }

    /**
     * Streaming chat completion. Falls through to the next provider only if
     * the current one errors BEFORE emitting its first token. Once tokens are
     * arriving, errors propagate to the caller (the user already saw output
     * and switching mid-stream produces a worse experience than failing).
     *
     * @param string $systemprompt
     * @param array $messages
     * @param callable $callback
     * @param array $options
     * @throws \Throwable If every provider in the chain fails before first token.
     */
    public function chat_completion_stream(string $systemprompt, array $messages, callable $callback, array $options = []): void {
        $chain = $this->build_chain();
        // v7.2.8: keep the FIRST failure and a trail of every attempt.
        // Rethrowing only the last one meant the surfaced error came from
        // the end of the chain, so a gemini primary that failed was
        // reported as an openai "model not found" -- pointing the admin at
        // a correct setting and hiding which provider actually broke.
        $firsterr = null;
        $attempts = [];
        $primaryfailed = false;
        foreach ($chain as $i => $entry) {
            if ($this->is_circuit_open($entry['label'])) {
                continue;
            }
            $firsttokenreceived = false;
            $wrappedcb = function (string $chunk) use ($callback, &$firsttokenreceived) {
                $firsttokenreceived = true;
                $callback($chunk);
            };
            $start = microtime(true);
            try {
                $entry['provider']->chat_completion_stream($systemprompt, $messages, $wrappedcb, $options);
                if (!$firsttokenreceived) {
                    throw new \moodle_exception(
                        'error',
                        'local_ai_course_assistant',
                        '',
                        'No tokens received from ' . $entry['label']
                    );
                }
                $this->record_success($entry['label']);
                $this->lastused = $entry['provider'];
                if ($primaryfailed) {
                    $this->audit_rescued($entry);
                }
                return;
            } catch (\Throwable $e) {
                if ($firsttokenreceived) {
                    // Streaming had started; user already saw output.
                    // Don't fall through. Surface the error.
                    $this->lastused = $entry['provider'];
                    throw $e;
                }
                // The first attempt's own time: a retry below must not inflate it.
                $elapsed = microtime(true) - $start;
                // v7.8.8: as in chat_completion(): one retry with a shorter
                // history, still before anything has reached the learner.
                $trimmed = self::trimmed_history_for_retry($e, $messages);
                if ($trimmed) {
                    try {
                        $entry['provider']->chat_completion_stream($systemprompt, $trimmed, $wrappedcb, $options);
                        if (!$firsttokenreceived) {
                            throw new \moodle_exception(
                                'error',
                                'local_ai_course_assistant',
                                '',
                                'No tokens received from ' . $entry['label']
                            );
                        }
                        $this->record_success($entry['label']);
                        $this->lastused = $entry['provider'];
                        $this->audit_trimmed($entry, 'rescued', count($messages), count($trimmed), $e);
                        if ($primaryfailed) {
                            $this->audit_rescued($entry);
                        }
                        return;
                    } catch (\Throwable $retryerror) {
                        if ($firsttokenreceived) {
                            $this->lastused = $entry['provider'];
                            throw $retryerror;
                        }
                        $this->audit_trimmed($entry, 'failed', count($messages), count($trimmed), $e, $retryerror);
                    }
                }
                $this->open_circuit($entry['label'], $e);
                $this->audit_fallthrough($entry, $e, $elapsed);
                $primaryfailed = $primaryfailed || $i === 0;
                $firsterr = $firsterr ?? $e;
                $attempts[] = $entry['label'] . ': ' . $e->getMessage();
            }
        }
        if ($firsterr !== null) {
            if ($firsterr instanceof \moodle_exception && count($attempts) > 1) {
                $trail = 'Failover chain exhausted (' . count($attempts) . ' attempts) -- '
                    . implode(' | ', $attempts);
                $firsterr->debuginfo = trim((string) ($firsterr->debuginfo ?? '') . ' ' . $trail);
            }
            throw $firsterr;
        }
        throw new \moodle_exception('chat:error_notconfigured', 'local_ai_course_assistant');
    }

    /**
     * Token usage from the provider that actually served the most recent
     * call. Returns null if no call has been made or the serving provider
     * doesn't report usage.
     *
     * @return array|null
     */
    public function get_last_token_usage(): ?array {
        return $this->lastused?->get_last_token_usage();
    }

    /**
     * Finish reason from the provider that actually served the most recent
     * call, for the same reason usage is delegated: the chain itself never
     * generates anything.
     *
     * @return string|null
     */
    public function get_last_finish_reason(): ?string {
        return $this->lastused?->get_last_finish_reason();
    }

    /**
     * The provider that actually served the most recent call.
     *
     * base_provider derives this from the class name, which would report
     * 'failover_chain' -- a vendor that does not exist and cannot be priced. The
     * whole point of naming the serving provider is defeated if the wrapper
     * answers for itself, so delegate to whichever member actually ran. Falls
     * back to the chain's primary before any call has been made.
     *
     * @return string
     */
    public function provider_id(): string {
        if ($this->lastused !== null) {
            return $this->lastused->provider_id();
        }
        $chain = $this->build_chain();
        return $chain ? reset($chain)->provider_id() : 'unknown';
    }

    /**
     * The model of the provider that served (or last tried) the call.
     *
     * v7.8.0: a failed turn records the provider and model it failed on, so
     * the watcher of a model switch can count that model's failures. The
     * chain answers for its member, as provider_id() does.
     *
     * @return string
     */
    public function model_id(): string {
        $member = $this->lastused ?? $this->primary;
        return method_exists($member, 'model_id') ? (string) $member->model_id() : '';
    }

    /**
     * Build the full chain ordered (primary first, then fallbacks).
     *
     * @return array<int, array{provider: provider_interface, label: string}>
     */
    private function build_chain(): array {
        $head = [['provider' => $this->primary, 'label' => $this->primarylabel]];
        return array_merge($head, $this->fallbacks);
    }

    /**
     * Check whether a circuit is currently open against the given label.
     * Open == cache hit AND opened_at is within the back-off window.
     *
     * @param string $label
     * @return bool
     */
    private function is_circuit_open(string $label): bool {
        $cache = \cache::make('local_ai_course_assistant', 'failover_circuit');
        $entry = $cache->get('label_' . $label);
        if (!is_array($entry) || empty($entry['opened_at'])) {
            return false;
        }
        // TTL is 900s on the cache; a hit here means the back-off is still in effect.
        return true;
    }

    /**
     * Open the circuit for a label. Stores the opened-at timestamp plus
     * the error class so the audit row can describe what tripped it.
     *
     * @param string $label
     * @param \Throwable $error
     */
    private function open_circuit(string $label, \Throwable $error): void {
        $cache = \cache::make('local_ai_course_assistant', 'failover_circuit');
        $cache->set('label_' . $label, [
            'opened_at' => time(),
            'error'     => get_class($error),
        ]);
        if (!empty($this->options['audit'])) {
            try {
                audit_logger::log(
                    self::AUDIT_EVENT_CIRCUIT_OPEN,
                    (int) $this->options['userid'],
                    (int) $this->options['courseid'],
                    [
                        'label'      => $label,
                        'error_class' => get_class($error),
                        // v7.8.8: what the provider said, redacted and bounded.
                        // The class alone is 'moodle_exception' for every
                        // failure, which is why a Claude circuit that opened
                        // ten times a day could not be diagnosed from the log.
                        'detail'     => self::describe_failure($error),
                    ]
                );
            } catch (\Throwable $ignore) {
                // Audit must never break the chain.
            }
        }
    }

    /**
     * Record a successful call. Currently a no-op for the circuit cache
     * because Moodle MUC doesn't expose a "decrement and close on N hits"
     * primitive that would let us implement the three-consecutive-successes
     * closing rule without a race. The 900s TTL on the open-circuit entry
     * is the floor on how long a label stays open; in practice this means
     * an opened circuit stays open for the full 15-minute window, then is
     * tried again on the next call.
     *
     * Leaving the hook here so a future iteration can use a counter table
     * for fine-grained recovery.
     *
     * @param string $label
     */
    private function record_success(string $label): void {
        // Reserved for future fine-grained recovery. Today: rely on the
        // cache TTL to close the circuit.
    }

    /**
     * Record that a fallback answered a turn the primary failed on. One row
     * per turn, written only once the turn was answered, so the watcher of a
     * model switch can count the primary's failures the learner never saw
     * without counting a turn twice when the whole chain fails (that turn is
     * already a failed-turn row naming the primary).
     *
     * @param array $answered The chain entry that answered.
     */
    private function audit_rescued(array $answered): void {
        if (empty($this->options['audit'])) {
            return;
        }
        try {
            audit_logger::log(
                self::AUDIT_EVENT_RESCUED,
                (int) $this->options['userid'],
                (int) $this->options['courseid'],
                [
                    'failed_model' => method_exists($this->primary, 'model_id')
                        ? (string) $this->primary->model_id() : '',
                    'primary' => $this->primarylabel,
                    'answered_label' => $answered['label'],
                ]
            );
        } catch (\Throwable $ignore) {
            // Audit must never break the chain.
            unset($ignore);
        }
    }

    /**
     * Emit the audit row for a fall-through event.
     *
     * @param array $failed The failed chain entry: ['provider' => provider_interface, 'label' => string].
     * @param \Throwable $error What the provider threw.
     * @param float $elapsedseconds
     */
    private function audit_fallthrough(array $failed, \Throwable $error, float $elapsedseconds): void {
        if (empty($this->options['audit'])) {
            return;
        }
        $reason = (string) $error->getMessage();
        try {
            audit_logger::log(
                self::AUDIT_EVENT_FALLTHROUGH,
                (int) $this->options['userid'],
                (int) $this->options['courseid'],
                [
                    'failed_label' => $failed['label'],
                    // v7.8.0: the model that failed, so the watcher of a model
                    // switch counts a failure the chain rescued; the learner's
                    // answer row names the fallback model, not this one.
                    'failed_model' => method_exists($failed['provider'], 'model_id')
                        ? (string) $failed['provider']->model_id() : '',
                    'primary'      => $this->primarylabel,
                    'reason'       => mb_substr($reason, 0, 500),
                    // v7.8.8: the provider's own words. 'reason' is the
                    // exception's message, which for a provider failure is the
                    // same "Sorry, something went wrong" every time.
                    'detail'       => self::describe_failure($error),
                    'latency_ms'   => (int) round($elapsedseconds * 1000),
                ]
            );
        } catch (\Throwable $ignore) {
            // Audit must never break the chain.
        }
    }

    /**
     * What a failure said, safe to keep in the audit log.
     *
     * A moodle_exception's message is the learner-facing string; the provider's
     * actual answer ("HTTP 400: ...", a quota message, a transient flag) is in
     * its debuginfo. Credential-shaped text is replaced
     * ({@see \local_ai_course_assistant\security::redact_secrets()}), whitespace
     * is collapsed to one line, and the result is cut to DETAIL_LIMIT because a
     * rejection body can quote part of the request, which is learner text.
     *
     * @param \Throwable $error
     * @return string
     */
    public static function describe_failure(\Throwable $error): string {
        if ($error instanceof \moodle_exception && is_string($error->debuginfo) && trim($error->debuginfo) !== '') {
            $text = $error->debuginfo;
        } else {
            $text = (string) $error->getMessage();
        }
        $text = \local_ai_course_assistant\security::redact_secrets($text);
        // The /u form returns null on invalid UTF-8, which a vendor body can be.
        $oneline = preg_replace('/\s+/u', ' ', $text) ?? preg_replace('/\s+/', ' ', $text) ?? '';
        return \core_text::substr(trim($oneline), 0, self::DETAIL_LIMIT);
    }

    /**
     * The shortened history to retry with, or [] when a retry would not help.
     *
     * Only a request the provider rejected as malformed (HTTP 400, 413 or 422)
     * qualifies: the provider is up and answered, and the one part of the
     * request that grows with the conversation is its history. Rate limits,
     * outages, bad keys and unknown models say nothing about the history, so
     * they are never retried here.
     *
     * @param \Throwable $error What the provider threw.
     * @param array $messages The history that was sent.
     * @return array
     */
    private static function trimmed_history_for_retry(\Throwable $error, array $messages): array {
        if (!($error instanceof provider_http_exception) || !in_array($error->status, [400, 413, 422], true)) {
            return [];
        }
        return \local_ai_course_assistant\history_trimmer::retry_candidate($messages);
    }

    /**
     * Record a retry with a shortened history and how it ended.
     *
     * 'rescued' means the provider answered the shorter request, so the long
     * history was what it objected to, and its circuit stays closed: the
     * provider is healthy, one conversation was not. 'failed' means the
     * shorter request was refused too and the chain carries on as before.
     *
     * @param array $entry The chain entry that was retried.
     * @param string $outcome 'rescued' or 'failed'.
     * @param int $before Messages in the rejected request.
     * @param int $kept Messages in the retry.
     * @param \Throwable $first Why the first request was refused.
     * @param \Throwable|null $second Why the retry was refused, when it was.
     */
    private function audit_trimmed(
        array $entry,
        string $outcome,
        int $before,
        int $kept,
        \Throwable $first,
        ?\Throwable $second = null
    ): void {
        if (empty($this->options['audit'])) {
            return;
        }
        try {
            $details = [
                'label'           => $entry['label'],
                // The model that refused the long request, as the fall-through
                // row names it: the answer row of a rescued turn names the same
                // model, so only this row can say it needed a shorter history.
                'failed_model'    => method_exists($entry['provider'], 'model_id')
                    ? (string) $entry['provider']->model_id() : '',
                'outcome'         => $outcome,
                'messages_before' => $before,
                'messages_kept'   => $kept,
                'detail'          => self::describe_failure($first),
            ];
            if ($second !== null) {
                $details['retry_detail'] = self::describe_failure($second);
            }
            audit_logger::log(
                self::AUDIT_EVENT_TRIMMED,
                (int) $this->options['userid'],
                (int) $this->options['courseid'],
                $details
            );
        } catch (\Throwable $ignore) {
            // Audit must never break the chain.
            unset($ignore);
        }
    }
}
