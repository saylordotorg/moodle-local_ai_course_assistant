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

namespace local_ai_course_assistant\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_course_assistant\conversation_manager;
use local_ai_course_assistant\context_builder;
use local_ai_course_assistant\provider\base_provider;
use local_ai_course_assistant\content_indexer;
use local_ai_course_assistant\rag_retriever;
use local_ai_course_assistant\answer_audit;
use local_ai_course_assistant\answer_flow;
use local_ai_course_assistant\protocol_markers;
use local_ai_course_assistant\response_safety;
use local_ai_course_assistant\runtime_guard;

/**
 * Send a message to the AI tutor (non-streaming fallback).
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_message extends external_api {

    /**
     * Interaction type recorded for rows written by this endpoint.
     *
     * sse.php takes this from an `interaction_type` request parameter and
     * defaults it to 'chat'. This endpoint does not accept that parameter, so
     * it writes the same default explicitly -- rows from the two paths have to
     * be comparable in token analytics and the audit trail, and until v7.2.7
     * this one passed null and raised a TypeError instead.
     */
    private const INTERACTION_TYPE = 'chat';

    /** @var \Closure|null Test hook: called at the start of the guidance-rule check (after runtime_guard). */
    public static ?\Closure $beforeanswercheck = null;

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'message' => new external_value(PARAM_RAW, 'User message'),
            'pageid' => new external_value(
                PARAM_INT,
                'Course-module id of the document the learner is on (0 if none); scopes RAG retrieval',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $courseid
     * @param string $message
     * @param int $pageid Course-module id of the current document (0 if none).
     * @return array
     */
    public static function execute(int $courseid, string $message, int $pageid = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'message' => $message,
            'pageid' => $pageid,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        $userid = $USER->id;

        // v7.2.7: an internal failure must not travel to the caller verbatim.
        // The TypeError this endpoint raised on every call arrived at the client
        // complete with the class name and the dirroot path of the file that
        // threw. A moodle_exception is deliberate operator-facing text -- the
        // quiz lock, the emergency stop, the spend cap all use it -- so those
        // are re-thrown unchanged; anything else is logged in full server-side
        // and reported generically.
        try {
            return self::handle($params, $userid);
        } catch (\moodle_exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            debugging(
                'send_message failed: ' . get_class($e) . ': ' . $e->getMessage()
                    . ' at ' . $e->getFile() . ':' . $e->getLine(),
                DEBUG_DEVELOPER
            );
            return [
                'response' => \local_ai_course_assistant\branding::str('chat:turn_failed'),
                'success' => false,
            ];
        }
    }

    /**
     * The body of the request, so execute() can be a thin error boundary.
     *
     * @param array $params Validated parameters.
     * @param int $userid
     * @return array
     */
    private static function handle(array $params, int $userid): array {
        global $USER;

        // F82 (v7.3.3): same per-user budget as the streaming path (sse.php).
        // This fallback had no throttle at all before RAG retrieval, so each
        // request spent an embedding (and rerank) call with nothing in the way;
        // base_provider's 120/60 floor only fires after retrieval has paid.
        // Shares the 'sse_stream' bucket deliberately: one 20/60 budget across
        // both transports of the same action, so alternating endpoints cannot
        // double throughput. Checked before anything is written.
        if (\local_ai_course_assistant\rate_limiter::is_rate_limited($userid, 'sse_stream', 20, 60)) {
            return [
                'response' => get_string('chat:error_ratelimit', 'local_ai_course_assistant'),
                'success'  => false,
            ];
        }

        // v7.2.9 (S11): refuse before writing anything.
        //
        // base_provider is the enforcement chokepoint and it does refuse this
        // path, but it is reached AFTER the user message and the `message_sent`
        // audit row are written. A learner blocked mid-quiz therefore ended up
        // with a question in their transcript that has no answer under it, and
        // the audit log recorded `message_sent` for a turn that was refused --
        // the one row an academic-integrity review would read, saying the
        // opposite of what happened. Checking here costs a single indexed query
        // and leaves base_provider as the backstop for every other surface.
        $lockedattempt = (!CLI_SCRIPT && !empty($USER->id))
            ? \local_ai_course_assistant\quiz_lock::active_attempt(
                (int) $USER->id,
                \local_ai_course_assistant\support_mode::integrity_scope((int) $params['courseid'])
            )
            : null;
        if ($lockedattempt !== null) {
            \local_ai_course_assistant\quiz_lock::record_refusal(
                (int) $USER->id,
                (int) $params['courseid'],
                $lockedattempt,
                'webservice'
            );
            return [
                'response' => \local_ai_course_assistant\branding::str('quizlock:blocked'),
                'success'  => false,
            ];
        }

        $conv = conversation_manager::get_or_create_conversation($userid, $params['courseid']);

        // Save user message.
        conversation_manager::add_message(
            $conv->id,
            $userid,
            $params['courseid'],
            'user',
            $params['message'],
            0,
            '',
            null,
            null,
            null,
            self::INTERACTION_TYPE,
            ((int) $params['pageid']) ?: null
        );

        // v7.2.7: audit the turn, as sse.php does. This path wrote no
        // message_sent row at all, so every call through the mobile fallback
        // was invisible to the audit log -- and because the endpoint threw
        // after committing the learner's question, the staging probe left three
        // questions in a learner's visible history with no reply and no audit
        // trail explaining why. Same action and same detail keys as the
        // streaming path, or the two cannot be read together.
        \local_ai_course_assistant\audit_logger::log(
            'message_sent',
            $userid,
            (int) $params['courseid'],
            [
                'conversation_id' => $conv->id,
                'role' => 'user',
                'message_length' => strlen($params['message']),
            ]
        );

        // RAG retrieval.
        // v5.4.6: time the retrieve call so we can attribute it to the assistant row.
        $retrievedchunks = [];
        $raglatencyms = null;
        // v7.2.2: skip retrieval while the emergency stop is engaged, so a paused
        // site stops spending on embeddings and reranking too. The provider
        // factory refuses further down; retrieval runs first. See sse.php.
        // F82 (v7.3.3): honor the per-course override exactly as sse.php does;
        // unset means enabled. An admin disabling RAG for one course was
        // silently ignored on this transport.
        $ragcourseraw = get_config('local_ai_course_assistant', 'rag_enabled_course_' . $params['courseid']);
        $ragcourseenabled = ($ragcourseraw === false) || (bool) $ragcourseraw;
        if (!\local_ai_course_assistant\spend_guard::emergency_chat_stopped()
                && get_config('local_ai_course_assistant', 'rag_enabled')
                && $ragcourseenabled) {
            try {
                if (!content_indexer::is_course_indexed($params['courseid'])) {
                    content_indexer::index_course($params['courseid']);
                }
                $rawtopk = get_config('local_ai_course_assistant', 'rag_topk');
                $topk = ($rawtopk === false || $rawtopk === '') ? 5 : (int) $rawtopk;
                $ragstart = microtime(true);
                // Issue #341: filtered to what this learner can see, as in sse.php.
                $retrievedchunks = rag_retriever::retrieve(
                    $params['courseid'],
                    $params['message'],
                    $topk,
                    (int) $params['pageid'],
                    (int) $userid
                );
                $raglatencyms = (int) round((microtime(true) - $ragstart) * 1000);
            } catch (\Throwable $e) {
                // \Throwable, not \Exception, matching sse.php and the rest of
                // the RAG paths. Retrieval decodes packed binary vectors and
                // calls pack()/unpack(), which raise Error (a TypeError on a
                // malformed argument) rather than Exception — and an Error here
                // must degrade to "answer without citations", exactly as an API
                // failure does, not fail the whole web service call. This
                // asymmetry with sse.php predates v7.0.3 but matters more now
                // that quantized encodings add more ways to reach an Error.
                debugging('RAG retrieval failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                $retrievedchunks = [];
                $raglatencyms = null;
            }
        }

        // Build context and get history (history_mode-aware: semantic keeps
        // only recent turns relevant to the question; recency keeps last N).
        $systemprompt = context_builder::build_system_prompt($params['courseid'], $userid, '', $retrievedchunks);
        $history = \local_ai_course_assistant\history_selector::select_for_api($conv->id, $params['message']);

        // Get AI response.
        //
        // v7.2.7: the courseid was missing. Without it this path ignored every
        // per-course provider and model override -- 30 courses carry one in
        // production, so the mobile fallback was silently answering with the
        // site default while the widget used the course's chosen model. It also
        // scoped the spend cap and the quiz lock to course 0 rather than to the
        // course the learner is actually in.
        $effectivecfg = \local_ai_course_assistant\course_config_manager::get_effective_config(
            (int) $params['courseid']
        );
        $provider = base_provider::create_from_config((int) $params['courseid']);
        if ($provider instanceof \local_ai_course_assistant\provider\failover_chain) {
            // A chat turn: remember a repaired or shortened history that worked (v7.8.8).
            $provider->enable_history_memory();
        }
        $response = $provider->chat_completion($systemprompt, $history);
        $tokenusage = $provider->get_last_token_usage();

        // Strip protocol markers BEFORE the row is stored, not after it is read.
        //
        // This endpoint had no server-side strip at all. The mobile template's
        // only defence (classes/output/mobile.php) matched the closed form
        // \[SOLA_NEXT\]...\[/SOLA_NEXT\], so an unterminated marker -- which is
        // what a truncated response produces, and what the 2026-09-12 production
        // run actually caught -- went to the learner verbatim, and the raw row
        // went on to the teacher-facing transcript CSV. Cleaning here fixes both
        // consumers at once, and the stored text stops being a liability.
        // v7.8.4: take the source pill from the raw reply first (#310).
        $sourcetag = \local_ai_course_assistant\protocol_markers::derive_source((string) $response);
        $response = \local_ai_course_assistant\protocol_markers::strip($response);
        // 8.4.0: the output scrub sse.php always had (leaked prompt fragments, credentials). It runs before
        // runtime_guard and the guidance rules, so what is guarded, audited, stored and returned is the scrubbed text.
        $response = response_safety::filter((string) $response);

        // 8.4.0: the same protections as the streamed path (sse.php). runtime_guard runs with the retrieved chunks
        // (a no-op unless validators_runtime_mode is set), then the guidance rules (answer_flow::finalize()). The
        // answer is returned whole, so there is no replace event. As in sse.php, a correct rule retries only on a
        // held (risky, enforce) question and warns otherwise. What is stored is exactly what is returned.
        $answercheck = self::check_answer((string) $response, $params, $userid, $provider, $systemprompt, $history,
            $retrievedchunks, $tokenusage);
        $response = $answercheck['text'];
        if ($answercheck['retryraw'] !== null) {
            // The learner gets the retry: its markers give the source pill.
            $sourcetag = protocol_markers::derive_source($answercheck['retryraw']);
        }

        // Save assistant response.
        //
        // v7.2.7: argument 11 is $interactiontype, which is typed `string` and
        // was being passed an explicit null -- a TypeError on every single call,
        // thrown AFTER the provider request had completed and been billed. The
        // endpoint has therefore never returned a reply to anyone; it charged
        // for one and raised.
        //
        // The remaining arguments were 0/''/null, so even had it worked the row
        // would have carried no tokens, no provider and no model: invisible to
        // token analytics and to every cost report. Matched to what sse.php
        // writes for an equivalent turn so rows from the two paths are
        // comparable.
        $assistantmsgid = conversation_manager::add_message(
            $conv->id,
            $userid,
            $params['courseid'],
            'assistant',
            $response,
            0,
            (string) ($effectivecfg['provider']
                ?? get_config('local_ai_course_assistant', 'provider')),
            $tokenusage['prompt_tokens'] ?? null,
            $tokenusage['completion_tokens'] ?? null,
            $tokenusage['model'] ?? null,
            self::INTERACTION_TYPE,
            ((int) $params['pageid']) ?: null,
            $raglatencyms,
            $tokenusage['cached_tokens'] ?? null,
            // v7.7.6: 'truncated' when the reply ran out of output tokens, as sse.php records it.
            conversation_manager::turn_outcome(false, $provider->get_last_finish_reason()),
            null,
            null,
            // v7.4.2: thinking tokens, as sse.php writes them. This path is the
            // mobile/web-service fallback for the SAME chat turn, so omitting
            // the field here would leave its rows carrying NULL — which reads
            // as "this provider does not report thinking" and is
            // indistinguishable from a provider that genuinely does not. The
            // two chat paths had already drifted once over token capture; the
            // comment above says these arguments are deliberately matched.
            isset($tokenusage['reasoning_tokens']) ? (int) $tokenusage['reasoning_tokens'] : null,
            $sourcetag
        );

        $result = [
            'response' => $response,
            'success' => true,
        ];

        // 8.4.0: the answer audit row, after the save and outside any transaction. It never throws into the
        // response: record_answer() catches and counts its own failures, and this catch is the backstop.
        try {
            answer_audit::record_answer([
                'messageid' => (int) $assistantmsgid,
                'conversationid' => (int) $conv->id,
                'userid' => (int) $userid,
                'courseid' => (int) $params['courseid'],
                'cmid' => ((int) $params['pageid']) ?: null,
                'mode' => 'text',
                'question' => (string) $params['message'],
                'answer' => $response,
                'model' => $tokenusage['model'] ?? null,
                'provider' => (string) ($effectivecfg['provider'] ?? get_config('local_ai_course_assistant', 'provider')),
            ], $retrievedchunks, [], $answercheck['validators'], $answercheck['checked']);
        } catch (\Throwable $e) {
            debugging('send_message: answer audit failed: ' . get_class($e), DEBUG_DEVELOPER);
        }

        return $result;
    }

    /**
     * runtime_guard and the guidance rules on the finished reply (8.4.0), as sse.php applies them to a held answer.
     *
     * Mode off: nothing new (runtime_guard alone, which is itself off unless validators_runtime_mode is set). Watch:
     * the rules run and what fired is returned for the audit row; the text never changes. Enforce: warn adds the
     * check line, block swaps in the safe message, correct (only on a question sse.php would hold: risky, in enforce;
     * otherwise it warns) asks the same provider once more with the failure stated (skipped, and blocked, when the
     * course's chat spend is blocked; a retry that fails or throws is blocked too).
     * Any failure in the rule check delivers the reply as runtime_guard left it and is counted with
     * answer_audit::log_rules_error() (exception class only, no learner text).
     *
     * @param string $response the reply with the protocol markers stripped
     * @param array $params validated parameters
     * @param int $userid
     * @param base_provider $provider the provider that answered (used again for a correct retry)
     * @param string $systemprompt
     * @param array $history the history sent with the question
     * @param array $retrievedchunks rag_retriever::retrieve() rows
     * @param array|null $tokenusage the turn's usage; a retry's tokens are added to it (billed on the same row)
     * @return array{text: string, validators: array[], checked: array, retryraw: ?string} text is what to store and
     *     return; validators and checked go to answer_audit::record_answer(); retryraw is the delivered retry's raw
     *     reply, or null when the first reply (or a safe message) is delivered
     */
    private static function check_answer(string $response, array $params, int $userid, base_provider $provider,
            string $systemprompt, array $history, array $retrievedchunks, ?array &$tokenusage): array {
        $courseid = (int) $params['courseid'];
        $question = (string) $params['message'];
        $guardctx = [
            'input' => $question,
            'userid' => $userid,
            'courseid' => $courseid,
            'rag_chunks' => runtime_guard::chunk_texts($retrievedchunks),
        ];
        $guarded = runtime_guard::apply($response, $guardctx);
        $out = ['text' => $guarded, 'validators' => runtime_guard::last_results(),
            'checked' => ['categories' => [], 'rules' => []], 'retryraw' => null];
        $decided = false;
        try {
            if (self::$beforeanswercheck) {
                (self::$beforeanswercheck)();
            }
            $plan = answer_flow::plan($question, $courseid);
            $out['checked']['categories'] = $plan['categories'];
            if ($plan['mode'] === 'off') {
                return $out;
            }
            $retryraw = null;
            $retry = function (string $instruction) use ($provider, $systemprompt, $history, $guarded, $guardctx,
                    &$tokenusage, &$retryraw): string {
                $retryhistory = $history;
                $retryhistory[] = ['role' => 'assistant', 'content' => $guarded];
                $retryhistory[] = ['role' => 'user', 'content' => $instruction];
                $raw = (string) $provider->chat_completion($systemprompt, $retryhistory);
                $retryusage = $provider->get_last_token_usage();
                foreach (['prompt_tokens', 'completion_tokens', 'reasoning_tokens'] as $k) {
                    if (isset($retryusage[$k])) {
                        $tokenusage[$k] = (int) ($tokenusage[$k] ?? 0) + (int) $retryusage[$k];
                    }
                }
                $retryraw = $raw;
                $scrubbed = response_safety::filter(protocol_markers::strip($raw));
                return runtime_guard::apply($scrubbed, $guardctx);
            };
            $final = answer_flow::finalize($guarded, $plan, [
                'question' => $question,
                // Held exactly when sse.php would hold it (a risky question in enforce mode): only then may a correct
                // rule retry; otherwise it is downgraded to warn, as on the streamed path.
                'buffered' => $plan['hold'],
                'validator_results' => $out['validators'],
                'chunks' => $guardctx['rag_chunks'],
                'courseid' => $courseid,
            ], $retry);
            // The decision is applied before anything else can throw, so the catch below never undoes a block.
            $decided = true;
            $out['checked'] = ['categories' => $final['categories'], 'rules' => $final['rules']];
            if ($final['replace']) {
                // finalize() sets replace exactly when the text to deliver differs from the reply.
                $out['text'] = (string) $final['text'];
                if ($final['action'] === 'correct' && $retryraw !== null) {
                    $out['retryraw'] = $retryraw;
                }
            }
            if ($final['error'] !== null) {
                answer_audit::log_rules_error($userid, $courseid, (string) $final['error']);
            }
        } catch (\Throwable $e) {
            if (!$decided) {
                $out['text'] = $guarded;
                $out['retryraw'] = null;
            }
            answer_audit::log_rules_error($userid, $courseid, get_class($e));
        }
        return $out;
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'response' => new external_value(PARAM_RAW, 'AI response'),
            'success' => new external_value(PARAM_BOOL, 'Success flag'),
        ]);
    }
}
