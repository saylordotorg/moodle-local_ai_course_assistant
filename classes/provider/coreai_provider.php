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
 * Provider adapter for Moodle's core_ai subsystem (Moodle 4.5+).
 *
 * Routes SOLA chat calls through \core_ai\manager::process_action() using the
 * generate_text action. The actual LLM connection, keys, and model selection
 * are configured site-wide by the admin under Site admin > AI.
 *
 * Limitations (tracked in .wiki/Roadmap-Moodle-AI-Provider.md):
 *
 *  - core_ai has no streaming API. chat_completion_stream() falls back to a
 *    single non-streamed call and emits the full response as one chunk.
 *  - core_ai's generate_text action accepts a single prompttext string, so
 *    SOLA's system prompt and conversation history are flattened into one
 *    prompt. Multi-turn nuance and prompt caching are lost versus direct
 *    providers.
 *  - No structured outputs, tool_use, adaptive thinking, or cache_control.
 *  - Per-course provider overrides still work for selecting core_ai vs a
 *    direct provider, but they cannot steer core_ai to a specific site-level
 *    provider (that is a site-wide decision inside core_ai itself).
 *
 * Moodle's own AI rules are honored before a request is sent
 * (coreai_declined_exception). core_ai's process_action() checks none of them
 * itself: core leaves them to the caller (the course assistance placement
 * checks the policy in its browser code and the context rules in
 * utils::get_actions_available()). So a learner who hasn't accepted the site's
 * AI usage policy, a course or activity where Moodle's AI tools are off, and a
 * learner past core_ai's per-user hourly limit are declined here, and the
 * failover chain moves the turn on to SOLA's own next provider when there is one.
 *
 * Recommended for courses that do not need streaming or provider-specific
 * features. For the best student experience, use a direct provider.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coreai_provider extends base_provider {
    /** @var string Model name recorded when core_ai doesn't say which model served the call (Moodle 4.5 to 5.2). */
    public const FALLBACK_MODEL = 'moodle_core_ai';

    /** @var int Longest model name kept: the width of the msgs.model_name column. */
    private const MODEL_MAX = 100;

    /** @var array|null Token usage from the last successful call. */
    private ?array $lasttokenusage = null;

    /** @var int The course this instance answers for (0 = none), so core_ai gets the course context. */
    private int $courseid = 0;

    /**
     * Whether the Moodle core_ai subsystem is present AND can run the
     * generate_text action: an enabled provider that is configured (has its
     * credentials) and has Generate text turned on. Used by the 'auto'
     * chat-provider default so a fresh install on a site with central AI setup
     * routes chat through core_ai with no SOLA key, and by the backend self-test.
     *
     * Asks core's own manager::is_action_available(), which exists from Moodle
     * 4.5 (static there, an instance method from 5.0) and applies the rules core
     * applies when it picks a provider. Before this, a 5.x site counted any
     * enabled row in {ai_providers}, so a provider with Generate text turned
     * off, or one with no API key yet, sent 'auto' into core_ai and every turn
     * failed.
     *
     * @return bool
     */
    public static function is_available(): bool {
        if (!self::core_present()) {
            return false;
        }
        try {
            return self::generate_text_available(\core\di::get(\core_ai\manager::class));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether a core_ai manager says generate_text can run. Split out so a test can pass a fake manager.
     *
     * @param object $manager A \core_ai\manager (or a stand-in with is_action_available()).
     * @return bool
     */
    public static function generate_text_available(object $manager): bool {
        if (!method_exists($manager, 'is_action_available')) {
            return false;
        }
        return (bool) $manager->is_action_available(\core_ai\aiactions\generate_text::class);
    }

    /**
     * Whether the core_ai classes SOLA needs exist (Moodle 4.5+).
     *
     * @return bool
     */
    private static function core_present(): bool {
        return class_exists('\\core_ai\\manager') && class_exists('\\core_ai\\aiactions\\generate_text');
    }

    /**
     * Override: core_ai has its own provider config surface, so skip the
     * apikey/model/baseurl parsing the parent does. Temperature is still read
     * for consistency even though core_ai does not expose it through
     * generate_text today.
     *
     * @param array $overrides Optional per-course config overrides. 'temperature'
     *                         and 'courseid' (set by create_from_config, so the
     *                         action runs in the course context) are read;
     *                         apikey/model/baseurl are ignored because core_ai
     *                         owns them.
     */
    public function __construct(array $overrides = []) {
        $this->apikey = '';
        $this->model = self::FALLBACK_MODEL;
        $this->baseurl = '';
        $this->courseid = max(0, (int) ($overrides['courseid'] ?? 0));
        if (isset($overrides['temperature']) && $overrides['temperature'] !== '') {
            $this->temperature = (float) $overrides['temperature'];
        } else {
            $rawtemp = get_config('local_ai_course_assistant', 'temperature');
            $this->temperature = ($rawtemp === false || $rawtemp === '') ? 0.7 : (float) $rawtemp;
        }
    }

    protected function get_default_model(): string {
        return self::FALLBACK_MODEL;
    }

    protected function get_default_base_url(): string {
        return '';
    }

    public function chat_completion(string $systemprompt, array $messages, array $options = []): string {
        global $USER;

        // A failed call must not leave the previous call's usage or finish reason behind.
        $this->lasttokenusage = null;
        $this->last_finish_reason = null;

        if (!self::core_present()) {
            throw new \moodle_exception(
                'chat:error',
                'local_ai_course_assistant',
                '',
                null,
                'Moodle core_ai subsystem not available. The Moodle provider requires Moodle 4.5 or later with an aiprovider plugin configured.'
            );
        }

        $context = $this->action_context($options);
        $userid = (int) ($USER->id ?? 0);

        // Moodle 5.0 gave core_ai\manager a required \moodle_database
        // constructor argument, so `new manager()` is a fatal
        // ArgumentCountError on 5.0-5.3 -- and this provider is what
        // `auto` resolves to whenever no SOLA key is set, so the shipped
        // default was broken on every Moodle 5.x (issue #218). Core made
        // the same move: ai/placement/courseassist and
        // ai/classes/external/set_action.php both use di::get(). The
        // container autowires the DB on 4.5 too, where the constructor
        // takes no arguments.
        $manager = \core\di::get(\core_ai\manager::class);

        $declined = self::gate(
            !(defined('CLI_SCRIPT') && CLI_SCRIPT),
            self::policy_status($userid),
            self::enabled_in_context($manager, $context)
        );
        if ($declined !== null) {
            throw self::declined($declined, $this->courseid);
        }

        $action = new \core_ai\aiactions\generate_text(
            contextid: (int) $context->id,
            userid: $userid,
            prompttext: self::flatten_conversation($systemprompt, $messages),
        );
        $response = $manager->process_action($action);

        if (!$response->get_success()) {
            throw self::failure(
                (int) $response->get_errorcode(),
                method_exists($response, 'get_error') ? (string) $response->get_error() : '',
                (string) $response->get_errormessage()
            );
        }

        $data = (array) $response->get_response_data();
        $text = self::extract_text($data);
        if ($text === '') {
            // A "successful" call with no text would otherwise be stored as an empty answer.
            throw new \moodle_exception(
                'chat:error',
                'local_ai_course_assistant',
                '',
                null,
                'Moodle core_ai reported success but returned no generated text.'
            );
        }
        $this->last_finish_reason = self::finish_reason($data);

        // core_ai has used these key names since 4.5 (numeric strings or null;
        // see response_generate_text::get_response_data()).
        $prompttokens = (int) ($data['prompttokens'] ?? 0);
        $completiontokens = (int) ($data['completiontokens'] ?? 0);
        if ($prompttokens > 0 || $completiontokens > 0) {
            $this->lasttokenusage = [
                'prompt_tokens' => $prompttokens,
                'completion_tokens' => $completiontokens,
                // Moodle 5.3 says which model served the call; earlier versions
                // don't, and then FALLBACK_MODEL is recorded. Either way the rate
                // card decides the price: a model it knows is priced, anything
                // else (FALLBACK_MODEL included) has no rate, so it is listed by
                // model_registry::unpriced_models() and never counted as free.
                'model' => self::model_used(
                    method_exists($response, 'get_model_used') ? $response->get_model_used() : null,
                    $data
                ),
                // Name the provider like every other path does, or a coreai-served
                // ancillary call falls back to config-derived attribution -- the exact
                // thing v7.4.4 removed everywhere else.
                'provider' => $this->provider_id(),
            ];
        }

        return $text;
    }

    public function chat_completion_stream(
        string $systemprompt,
        array $messages,
        callable $callback,
        array $options = []
    ): void {
        $text = $this->chat_completion($systemprompt, $messages, $options);
        if ($text !== '') {
            $callback($text);
        }
    }

    public function get_last_token_usage(): ?array {
        return $this->lasttokenusage;
    }

    /**
     * Decide whether Moodle's own AI rules decline this request. Pure.
     *
     * The context rule (a site admin, teacher or activity turned AI off) applies
     * to every caller. The policy rule applies only to a person at a browser: a
     * scheduled task runs as the admin or as no one, and must not stop because
     * that account never opened a Moodle AI feature. Null for either input means
     * "this Moodle can't tell", which never declines.
     *
     * @param bool $interactive Whether a person is making the request (not CLI or cron).
     * @param bool|null $policyaccepted The user's AI usage policy status, null if unknown.
     * @param bool|null $enabledincontext Whether generate_text is allowed in the context, null if unknown.
     * @return string|null A coreai_declined_exception reason, or null to go ahead.
     */
    public static function gate(bool $interactive, ?bool $policyaccepted, ?bool $enabledincontext): ?string {
        if ($enabledincontext === false) {
            return coreai_declined_exception::CONTEXT;
        }
        if ($interactive && $policyaccepted === false) {
            return coreai_declined_exception::POLICY;
        }
        return null;
    }

    /**
     * Whether the user has accepted Moodle's AI usage policy, or null when that can't be read.
     *
     * @param int $userid
     * @return bool|null
     */
    private static function policy_status(int $userid): ?bool {
        if ($userid <= 0 || !method_exists('\\core_ai\\manager', 'get_user_policy_status')) {
            return null;
        }
        try {
            return (bool) \core_ai\manager::get_user_policy_status($userid);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether generate_text may run in this context, or null on a Moodle without the check.
     *
     * Moodle 5.0+ has manager::is_action_enabled_in_context(): false when the
     * course has AI tools turned off, or the activity has them off or has
     * Generate text unticked. 4.5 has no such settings, so null there.
     *
     * @param object $manager A \core_ai\manager (or a stand-in).
     * @param \context $context
     * @return bool|null
     */
    public static function enabled_in_context(object $manager, \context $context): ?bool {
        if (!method_exists($manager, 'is_action_enabled_in_context')) {
            return null;
        }
        try {
            return (bool) $manager->is_action_enabled_in_context($context, \core_ai\aiactions\generate_text::class);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The context the action runs in: an explicit contextid option, else the course, else the system.
     *
     * The course context matters on Moodle 5.x: core checks the course's AI
     * tools setting against it, and its AI usage report attributes the call to
     * the course from it. Before this every call ran in the system context,
     * because no caller sets the contextid option.
     *
     * @param array $options Call options; 'contextid' wins when it names an existing context.
     * @return \context
     */
    private function action_context(array $options): \context {
        $contextid = (int) ($options['contextid'] ?? 0);
        if ($contextid > 0) {
            $context = \context::instance_by_id($contextid, IGNORE_MISSING);
            if ($context) {
                return $context;
            }
        }
        if ($this->courseid > 0 && $this->courseid != SITEID) {
            $context = \context_course::instance($this->courseid, IGNORE_MISSING);
            if ($context) {
                return $context;
            }
        }
        return \context_system::instance();
    }

    /**
     * The exception for a declined request, carrying what the learner should do next.
     *
     * The messages avoid the words the chat widget pattern-matches into its
     * generic authentication and request-limit notices ('auth', 'rate' and
     * HTTP status numbers), so they reach the learner as written.
     *
     * @param string $reason A coreai_declined_exception reason.
     * @param int $courseid Where the policy page sends the learner back to.
     * @return coreai_declined_exception
     */
    public static function declined(string $reason, int $courseid): coreai_declined_exception {
        switch ($reason) {
            case coreai_declined_exception::POLICY:
                $url = new \moodle_url('/local/ai_course_assistant/coreai_policy.php', ['courseid' => $courseid]);
                $message = \local_ai_course_assistant\branding::str('coreai:policy_needed', $url->out(false));
                break;
            case coreai_declined_exception::USER_LIMIT:
                $message = \local_ai_course_assistant\branding::str('coreai:user_limit');
                break;
            default:
                $reason = coreai_declined_exception::CONTEXT;
                $message = \local_ai_course_assistant\branding::str('coreai:context_off');
        }
        return new coreai_declined_exception($reason, $message);
    }

    /**
     * The exception for a core_ai response that reports failure.
     *
     * core_ai's errorcode is the vendor's HTTP status, core's own 429 from its
     * rate limiter, or -1 when no provider could take the action. An HTTP status
     * becomes a provider_http_exception so the failover chain classifies it like
     * any other vendor error (a 400 is one bad request, a 5xx counts toward the
     * circuit). core's PER-USER limit is one learner's quota, not the provider's
     * health, so it is declined instead: counted, it would open the site-wide
     * core_ai circuit after two messages from one learner.
     *
     * @param int $code
     * @param string $error core_ai's short error name (Moodle 5.x; '' on 4.5).
     * @param string $message core_ai's error message.
     * @return \moodle_exception
     */
    public static function failure(int $code, string $error, string $message): \moodle_exception {
        if ($code === 429 && self::is_user_limit($error, $message)) {
            return self::declined(coreai_declined_exception::USER_LIMIT, 0);
        }
        $words = implode(': ', array_filter([trim($error), trim($message)], static fn($p) => $p !== ''));
        $detail = \core_text::substr(
            \local_ai_course_assistant\security::redact_secrets('Moodle core_ai: ' . ($words !== '' ? $words : 'error')),
            0,
            500
        );
        if ($code >= 400 && $code <= 599) {
            return new provider_http_exception($code, $detail);
        }
        $errorcode = $code === -1 ? 'chat:error_notconfigured' : 'chat:error';
        return new \moodle_exception($errorcode, 'local_ai_course_assistant', '', null, "Moodle core_ai error ({$code}): {$detail}");
    }

    /**
     * Whether a core_ai 429 is its per-user limit (core's string error:429:internaluser).
     *
     * @param string $error
     * @param string $message
     * @return bool
     */
    private static function is_user_limit(string $error, string $message): bool {
        if (!get_string_manager()->string_exists('error:429:internaluser', 'core_ai')) {
            return false;
        }
        $limit = trim(get_string('error:429:internaluser', 'core_ai'));
        return $limit !== '' && (trim($error) === $limit || trim($message) === $limit);
    }

    /**
     * The model to record for a call: the one core_ai says served it, else FALLBACK_MODEL. Pure.
     *
     * @param mixed $used response_base::get_model_used() (Moodle 5.3+), or null.
     * @param array $data The response data; its 'model' key carries the same fact on 5.3.
     * @return string Printable ASCII, at most MODEL_MAX characters.
     */
    public static function model_used($used, array $data): string {
        $candidate = (is_string($used) && trim($used) !== '') ? $used : ($data['model'] ?? null);
        if (!is_string($candidate)) {
            return self::FALLBACK_MODEL;
        }
        $clean = trim((string) preg_replace('/[^\x20-\x7E]/', '', $candidate));
        return $clean === '' ? self::FALLBACK_MODEL : substr($clean, 0, self::MODEL_MAX);
    }

    /**
     * The vendor's finish reason as core_ai passes it on, or null. Pure.
     *
     * @param array $data
     * @return string|null
     */
    public static function finish_reason(array $data): ?string {
        $reason = $data['finishreason'] ?? null;
        return (is_string($reason) && trim($reason) !== '') ? trim($reason) : null;
    }

    /**
     * Pull the generated text out of a core_ai response-data array.
     *
     * 'generatedcontent' is the only text key response_generate_text has had
     * from 4.5 to 5.3. The content/response/completion/text guesses that used
     * to follow it matched nothing in any version, so they are gone.
     *
     * @param array $data Response data from get_response_data().
     * @return string The generated text, or '' if there is none.
     */
    private static function extract_text(array $data): string {
        $text = $data['generatedcontent'] ?? null;
        return is_string($text) ? $text : '';
    }

    /**
     * Flatten the system prompt + chat history into a single prompttext string,
     * since core_ai's generate_text action does not accept multi-turn messages.
     *
     * @param string $systemprompt
     * @param array $messages Array of ['role' => 'user'|'assistant', 'content' => '...'].
     * @return string
     */
    private static function flatten_conversation(string $systemprompt, array $messages): string {
        $parts = [];
        if (trim($systemprompt) !== '') {
            $parts[] = "[System instructions]\n" . $systemprompt;
        }
        foreach ($messages as $m) {
            $role = ($m['role'] ?? '') === 'assistant' ? 'Assistant' : 'User';
            $content = (string) ($m['content'] ?? '');
            if ($content === '') {
                continue;
            }
            $parts[] = $role . ': ' . $content;
        }
        $parts[] = 'Assistant:';
        return implode("\n\n", $parts);
    }
}
