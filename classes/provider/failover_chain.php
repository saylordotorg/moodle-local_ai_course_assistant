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

    /** @var int v7.8.8: seconds a learner's conversation is sent with its repaired or shortened history first. */
    public const HISTORY_MEMORY_SECONDS = 300;

    /** @var int v7.8.8: counted failures in a row that open a circuit. */
    public const FAILURES_TO_OPEN = 2;

    /**
     * @var int v7.8.8: seconds inside which those failures must fall. v7.8.9: 300, up from 60. A success between
     * failures already ends the run, so on a busy site the window hardly matters; on a quiet one, failures a
     * minute or two apart were never counted together and every learner waited out the primary's own timeout.
     */
    public const FAILURE_WINDOW = 300;

    /**
     * @var int v7.8.9: requests the provider refused as malformed, in a row with no success between, that
     * open its circuit. One conversation's refusals are not the provider's fault (see circuit_effect()), but a
     * provider that refuses EVERYTHING (a region block, a parameter error nobody has taught the healer) would
     * otherwise cost every turn a refused request, up to two retries and a fallback, indefinitely.
     */
    public const REFUSALS_TO_OPEN = 6;

    /**
     * @var int v7.8.9: how many of those refusals one learner can supply. A learner who can reliably get a 400
     * (a prompt the provider flags, one huge pasted message) must not be able to move every course on the
     * site key onto the fallback for 15 minutes, and the chat limit lets one learner send 20 messages a
     * minute. With 2 of 6, the run needs at least three different learners; a provider that refuses
     * everything reaches them within seconds.
     */
    public const REFUSALS_PER_LEARNER = 2;

    /**
     * @var int v7.8.9: seconds inside which those refusals must fall. Shorter than FAILURE_WINDOW on purpose:
     * a refusal is a verdict on one request, so only a tight burst of them says anything about the provider.
     */
    public const REFUSAL_WINDOW = 120;

    /**
     * @var string v7.8.8: provider-specific phrases for a 400 that is about the account or key, not the request.
     * Deliberately narrow. Some providers quote part of the rejected request in a 400 body, and a learner's
     * message or a retrieved course passage can contain everyday words such as "billing" or "usage limit" (a
     * business course is full of them), so bare words are never enough: each phrase is one a provider writes
     * about its own account. A 429 never reaches this check (its body is dropped before it gets here), so an
     * exhausted OpenAI quota is a counted failure, not an immediate one.
     */
    private const ACCOUNT_LEVEL_BODY = '/valid api key|api[_ ]key[_ ](?:not valid|invalid|is invalid|is missing|is expired)'
        . '|credit balance is too low|insufficient_quota|billing_hard_limit_reached'
        . '|reached your specified api usage limits|account (?:is )?(?:disabled|suspended|deactivated)/i';

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
            'history_memory'  => false,
        ];
    }

    /**
     * Let this chain remember, for a few minutes, which repaired or shortened history a learner's
     * conversation needed, and send it that way first.
     *
     * Off unless a chat caller asks for it. The chain wraps every provider call while per-call
     * failover is on (quiz, essay scoring, insights...), and a shortened history is right for a chat
     * turn but would silently cut a prompt another feature built on purpose.
     *
     * @return void
     */
    public function enable_history_memory(): void {
        $this->options['history_memory'] = true;
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
            if ($this->is_circuit_open($entry)) {
                continue;
            }
            $start = microtime(true);
            // v7.8.8: a conversation this member already needed a shorter history for
            // is sent that way at once, instead of paying a refused request first.
            $remembered = $this->remembered_variant($entry, $messages);
            $sent = $remembered ? $remembered['messages'] : $messages;
            try {
                $result = $entry['provider']->chat_completion($systemprompt, $sent, $options);
                $this->record_success($entry);
                $this->lastused = $entry['provider'];
                if ($remembered) {
                    $this->audit_trimmed($entry, 'remembered', count($messages), count($sent), null, null, $remembered['name']);
                }
                if ($primaryfailed) {
                    $this->audit_rescued($entry);
                }
                return $result;
            } catch (\Throwable $e) {
                // The first attempt's own time: a retry below must not inflate it.
                $elapsed = microtime(true) - $start;
                // v7.8.8: a provider that refused this request as malformed is not
                // necessarily broken. Ask again with the history repaired, then with
                // only the newest exchange, before calling it a failure.
                $variants = self::history_variants_for_retry($e, $messages, $entry);
                if ($remembered) {
                    // The provider refused the remembered variant itself: forget it, and try the
                    // others. A rate limit, outage or timeout says nothing about the history, so
                    // it leaves the memory alone.
                    if (self::circuit_effect($e) === 'none') {
                        $this->forget_variant($entry);
                    }
                    $variants = array_values(array_filter(
                        $variants,
                        static fn($v) => $v['name'] !== $remembered['name']
                    ));
                }
                foreach ($variants as $variant) {
                    try {
                        $result = $entry['provider']->chat_completion($systemprompt, $variant['messages'], $options);
                        $this->record_success($entry);
                        $this->lastused = $entry['provider'];
                        $this->remember_variant($entry, $variant['name']);
                        $this->audit_trimmed(
                            $entry,
                            'rescued',
                            count($messages),
                            count($variant['messages']),
                            $e,
                            null,
                            $variant['name']
                        );
                        if ($primaryfailed) {
                            $this->audit_rescued($entry);
                        }
                        return $result;
                    } catch (\Throwable $retryerror) {
                        $this->audit_trimmed(
                            $entry,
                            'failed',
                            count($messages),
                            count($variant['messages']),
                            $e,
                            $retryerror,
                            $variant['name']
                        );
                    }
                }
                $circuit = $this->note_failure($entry, $e);
                $this->audit_fallthrough($entry, $e, $elapsed, $circuit);
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
            if ($this->is_circuit_open($entry)) {
                continue;
            }
            $firsttokenreceived = false;
            $wrappedcb = function (string $chunk) use ($callback, &$firsttokenreceived) {
                $firsttokenreceived = true;
                $callback($chunk);
            };
            $start = microtime(true);
            $remembered = $this->remembered_variant($entry, $messages);
            $sent = $remembered ? $remembered['messages'] : $messages;
            try {
                $entry['provider']->chat_completion_stream($systemprompt, $sent, $wrappedcb, $options);
                if (!$firsttokenreceived) {
                    throw new \moodle_exception(
                        'error',
                        'local_ai_course_assistant',
                        '',
                        'No tokens received from ' . $entry['label']
                    );
                }
                $this->record_success($entry);
                $this->lastused = $entry['provider'];
                if ($remembered) {
                    $this->audit_trimmed($entry, 'remembered', count($messages), count($sent), null, null, $remembered['name']);
                }
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
                // v7.8.8: as in chat_completion(): the repaired history, then the newest
                // exchange only, still before anything has reached the learner.
                $variants = self::history_variants_for_retry($e, $messages, $entry);
                if ($remembered) {
                    // The provider refused the remembered variant itself: forget it, and try the
                    // others. A rate limit, outage or timeout says nothing about the history, so
                    // it leaves the memory alone.
                    if (self::circuit_effect($e) === 'none') {
                        $this->forget_variant($entry);
                    }
                    $variants = array_values(array_filter(
                        $variants,
                        static fn($v) => $v['name'] !== $remembered['name']
                    ));
                }
                foreach ($variants as $variant) {
                    try {
                        $entry['provider']->chat_completion_stream($systemprompt, $variant['messages'], $wrappedcb, $options);
                        if (!$firsttokenreceived) {
                            throw new \moodle_exception(
                                'error',
                                'local_ai_course_assistant',
                                '',
                                'No tokens received from ' . $entry['label']
                            );
                        }
                        $this->record_success($entry);
                        $this->lastused = $entry['provider'];
                        $this->remember_variant($entry, $variant['name']);
                        $this->audit_trimmed(
                            $entry,
                            'rescued',
                            count($messages),
                            count($variant['messages']),
                            $e,
                            null,
                            $variant['name']
                        );
                        if ($primaryfailed) {
                            $this->audit_rescued($entry);
                        }
                        return;
                    } catch (\Throwable $retryerror) {
                        if ($firsttokenreceived) {
                            $this->lastused = $entry['provider'];
                            throw $retryerror;
                        }
                        $this->audit_trimmed(
                            $entry,
                            'failed',
                            count($messages),
                            count($variant['messages']),
                            $e,
                            $retryerror,
                            $variant['name']
                        );
                    }
                }
                $circuit = $this->note_failure($entry, $e);
                $this->audit_fallthrough($entry, $e, $elapsed, $circuit);
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
     * Which circuit a chain member belongs to: its label plus a fingerprint of
     * the model, base URL and key it is using.
     *
     * v7.8.8. The circuit used to be keyed by label alone, so one course's stale
     * key (BUS101, 2026-10-11) or one failing model opened the circuit for every
     * course that shares the label and failed 232 of 705 messages across 15
     * courses. A member now only shares a circuit with members that would fail
     * the same way: same key, same model, same endpoint. Providers that do not
     * describe themselves (test doubles) keep the label-only behaviour.
     *
     * @param string $label
     * @param provider_interface $provider
     * @return string
     */
    public static function circuit_scope(string $label, provider_interface $provider): string {
        $identity = method_exists($provider, 'circuit_identity') ? (string) $provider->circuit_identity() : '';
        return $identity === '' ? $label : $label . '|' . $identity;
    }

    /**
     * What a failure should do to its circuit.
     *
     *  - 'none': the provider answered and called THIS request malformed (HTTP
     *    400, 413, 422). The provider is healthy; one conversation's history or
     *    one learner's message must not block everyone behind it. Only REFUSALS_TO_OPEN
     *    of them in a row with no success between (so nothing is getting through) open it.
     *  - 'immediate': a fault that will not clear on its own and applies to every
     *    request on this key and model: a rejected key (401, 403), an unknown
     *    model (404), or a 400 whose body speaks of the account (an invalid key,
     *    exhausted credit, a usage or spend limit, a disabled project).
     *  - 'counted': anything else (rate limits, 5xx, timeouts, empty streams).
     *    These come and go, so the circuit opens only on the second in a row
     *    inside FAILURE_WINDOW seconds.
     *
     * @param \Throwable $error
     * @return string 'none', 'immediate' or 'counted'
     */
    public static function circuit_effect(\Throwable $error): string {
        if ($error instanceof model_not_found_exception) {
            return 'immediate';
        }
        if ($error instanceof \moodle_exception && $error->errorcode === 'chat:error_auth') {
            return 'immediate';
        }
        if ($error instanceof provider_http_exception) {
            if (preg_match(self::ACCOUNT_LEVEL_BODY, $error->body)) {
                return 'immediate';
            }
            if (in_array($error->status, [400, 413, 422], true)) {
                return 'none';
            }
        }
        return 'counted';
    }

    /**
     * Check whether a circuit is currently open for a chain member.
     * Open == cache hit; the cache TTL is the back-off window.
     *
     * @param array $entry Chain entry: ['provider' => provider_interface, 'label' => string].
     * @return bool
     */
    private function is_circuit_open(array $entry): bool {
        $cache = \cache::make('local_ai_course_assistant', 'failover_circuit');
        $scope = self::circuit_scope($entry['label'], $entry['provider']);
        $found = $cache->get('circuit_' . $scope);
        return is_array($found) && !empty($found['opened_at']);
    }

    /**
     * Apply a failure to its circuit and say what happened.
     *
     * @param array $entry The failed chain member.
     * @param \Throwable $error
     * @return string 'opened', 'counting' (a passing fault still being counted) or 'unaffected' (a refused request
     *         still being counted).
     */
    private function note_failure(array $entry, \Throwable $error): string {
        $effect = self::circuit_effect($error);
        $scope = self::circuit_scope($entry['label'], $entry['provider']);
        $runs = self::runs_key($entry['label'], $entry['provider']);
        if ($effect === 'none') {
            // Not the provider's fault, unless it refuses everything: see REFUSALS_TO_OPEN.
            $userid = (int) ($this->options['userid'] ?? 0);
            $complete = $this->count_in_a_row(
                $runs,
                'refused',
                self::REFUSALS_TO_OPEN,
                self::REFUSAL_WINDOW,
                $userid,
                self::REFUSALS_PER_LEARNER
            );
            if (!$complete) {
                return 'unaffected';
            }
        } else if ($effect === 'counted') {
            if (!$this->count_in_a_row($runs, 'fails', self::FAILURES_TO_OPEN, self::FAILURE_WINDOW)) {
                return 'counting';
            }
        }
        $this->open_circuit($entry, $scope, $error);
        return 'opened';
    }

    /**
     * The cache key holding a member's runs of failures (both kinds, under one key so that a successful turn
     * needs a single read to clear them).
     *
     * @param string $label
     * @param provider_interface $provider
     * @return string
     */
    public static function runs_key(string $label, provider_interface $provider): string {
        return 'runs_' . self::circuit_scope($label, $provider);
    }

    /**
     * Count one more failure of a run, and say whether the run has reached its threshold.
     *
     * Not atomic (MUC has no increment): two failures at the same instant can both read n=0, so a burst may
     * need one more failure to open. Acceptable for a back-off. A run older than $window starts again.
     *
     * @param string $key The member's runs_key().
     * @param string $run 'fails' (a passing fault) or 'refused' (a request refused as malformed).
     * @param int $threshold Failures in a row that end the run.
     * @param int $window Seconds the run may span.
     * @param int $userid The user this failure belongs to.
     * @param int $perlearner Most failures one user can add to the run; 0 for no limit.
     * @return bool True when the run is complete (it is cleared), false while still counting.
     */
    private function count_in_a_row(
        string $key,
        string $run,
        int $threshold,
        int $window,
        int $userid = 0,
        int $perlearner = 0
    ): bool {
        $cache = \cache::make('local_ai_course_assistant', 'failover_circuit');
        $now = time();
        $state = $cache->get($key);
        $state = is_array($state) ? $state : [];
        $seen = $state[$run] ?? null;
        if (!is_array($seen) || ($now - (int) ($seen['first'] ?? 0)) > $window) {
            $seen = ['n' => 0, 'first' => $now, 'users' => []];
        }
        $mine = (int) ($seen['users'][$userid] ?? 0);
        if ($perlearner === 0 || $mine < $perlearner) {
            $seen['users'][$userid] = $mine + 1;
            $seen['n']++;
        }
        if ($seen['n'] >= $threshold) {
            unset($state[$run]);
            if ($state) {
                $cache->set($key, $state);
            } else {
                $cache->delete($key);
            }
            return true;
        }
        $state[$run] = $seen;
        $cache->set($key, $state);
        return false;
    }

    /**
     * Open the circuit for a chain member. Stores the opened-at timestamp plus
     * the error class so the audit row can describe what tripped it.
     *
     * @param array $entry
     * @param string $scope
     * @param \Throwable $error
     */
    private function open_circuit(array $entry, string $scope, \Throwable $error): void {
        $cache = \cache::make('local_ai_course_assistant', 'failover_circuit');
        $cache->set('circuit_' . $scope, [
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
                        'label'      => $entry['label'],
                        'failed_model' => method_exists($entry['provider'], 'model_id')
                            ? (string) $entry['provider']->model_id() : '',
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
     * Record a successful call: it ends any run of failures being counted for
     * this member, so only failures in a row open a circuit.
     *
     * An OPEN circuit still closes only when its TTL runs out: Moodle MUC has no
     * "decrement and close on N hits" primitive that avoids a race.
     *
     * @param array $entry
     */
    private function record_success(array $entry): void {
        $cache = \cache::make('local_ai_course_assistant', 'failover_circuit');
        $key = self::runs_key($entry['label'], $entry['provider']);
        // Read before deleting: almost every successful turn has nothing to clear, and on a
        // shared cache store a write per chat message is not free.
        if ($cache->get($key) !== false) {
            $cache->delete($key);
        }
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
     * @param string $circuit What the failure did to the circuit: opened, counting or unaffected.
     */
    private function audit_fallthrough(array $failed, \Throwable $error, float $elapsedseconds, string $circuit = ''): void {
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
                    // v7.8.8: opened, counting (first failure of a run) or unaffected
                    // (a request the provider called malformed).
                    'circuit'      => $circuit,
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
     * The histories to retry with after a refusal, or [] when a retry would not help.
     *
     * Only a request the provider rejected as malformed (HTTP 400, 413 or 422, and
     * not about the account) qualifies: the provider is up and answered, and the
     * one part of the request that grows with the conversation is its history.
     * Rate limits, outages, bad keys and unknown models say nothing about the
     * history, so they are never retried here. The variants come in order: the
     * whole history with its structure repaired, then the newest exchange only.
     *
     * @param \Throwable $error What the provider threw.
     * @param array $messages The history that was sent.
     * @param array $entry The chain entry that refused it.
     * @return array<int, array{name: string, messages: array}>
     */
    private static function history_variants_for_retry(\Throwable $error, array $messages, array $entry): array {
        if (!($error instanceof provider_http_exception) || self::circuit_effect($error) !== 'none') {
            return [];
        }
        return self::variants_for($entry, $messages);
    }

    /**
     * The history variants worth sending to a chain member.
     *
     * A provider that repairs the history itself before every request (Claude does) was already
     * sent the 'tidied' variant, so repeating it would only pay for the same refusal twice and
     * log "repair did not help" when the repair had already been applied.
     *
     * @param array $entry Chain entry.
     * @param array $messages The full history.
     * @return array<int, array{name: string, messages: array}>
     */
    private static function variants_for(array $entry, array $messages): array {
        $variants = \local_ai_course_assistant\history_trimmer::retry_variants($messages);
        if (method_exists($entry['provider'], 'repairs_history') && $entry['provider']->repairs_history()) {
            $variants = array_values(array_filter($variants, static fn($v) => $v['name'] !== 'tidied'));
        }
        return $variants;
    }

    /**
     * Cache key remembering which history variant a member needed for a learner's conversation.
     *
     * @param array $entry
     * @return string|null Null when memory is off or no learner is known (nothing to remember it by).
     */
    private function memory_key(array $entry): ?string {
        $userid = (int) $this->options['userid'];
        if ($userid <= 0 || empty($this->options['history_memory'])) {
            return null;
        }
        $scope = self::circuit_scope($entry['label'], $entry['provider']);
        return 'history_' . md5($scope) . '_' . $userid . '_' . (int) $this->options['courseid'];
    }

    /**
     * The history variant to send first, because the last request of this learner's
     * conversation on this member needed it. Remembered for HISTORY_MEMORY_SECONDS only:
     * if the cause was a passing fault the full conversation is tried again soon.
     *
     * @param array $entry
     * @param array $messages The full history for this request.
     * @return array{name: string, messages: array}|array{} Empty when nothing is remembered.
     */
    private function remembered_variant(array $entry, array $messages): array {
        $key = $this->memory_key($entry);
        if ($key === null) {
            return [];
        }
        $memory = \cache::make('local_ai_course_assistant', 'failover_circuit')->get($key);
        if (!is_array($memory) || (time() - (int) ($memory['at'] ?? 0)) > self::HISTORY_MEMORY_SECONDS) {
            return [];
        }
        foreach (self::variants_for($entry, $messages) as $variant) {
            if ($variant['name'] === ($memory['variant'] ?? '')) {
                return $variant;
            }
        }
        return [];
    }

    /**
     * Forget the remembered variant for this learner's conversation on this member.
     *
     * @param array $entry
     */
    private function forget_variant(array $entry): void {
        $key = $this->memory_key($entry);
        if ($key !== null) {
            \cache::make('local_ai_course_assistant', 'failover_circuit')->delete($key);
        }
    }

    /**
     * Remember that this member answered a learner's conversation only with a repaired or shortened history.
     *
     * @param array $entry
     * @param string $name Variant name.
     */
    private function remember_variant(array $entry, string $name): void {
        $key = $this->memory_key($entry);
        if ($key !== null) {
            \cache::make('local_ai_course_assistant', 'failover_circuit')->set($key, ['variant' => $name, 'at' => time()]);
        }
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
     * @param string $outcome 'rescued', 'failed', or 'remembered' (sent shortened first because an earlier request needed it).
     * @param int $before Messages in the rejected request.
     * @param int $kept Messages in the retry.
     * @param \Throwable|null $first Why the first request was refused (null when a remembered variant went first).
     * @param \Throwable|null $second Why the retry was refused, when it was.
     * @param string $variant 'tidied' (structure repaired, nothing cut) or 'trimmed' (newest exchange only).
     */
    private function audit_trimmed(
        array $entry,
        string $outcome,
        int $before,
        int $kept,
        ?\Throwable $first,
        ?\Throwable $second = null,
        string $variant = ''
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
                'variant'         => $variant,
                'messages_before' => $before,
                'messages_kept'   => $kept,
                'detail'          => $first !== null ? self::describe_failure($first) : '',
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
