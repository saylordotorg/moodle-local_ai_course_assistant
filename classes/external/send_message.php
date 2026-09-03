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
                (int) $params['courseid']
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
        if (!\local_ai_course_assistant\spend_guard::emergency_chat_stopped()
                && get_config('local_ai_course_assistant', 'rag_enabled')) {
            try {
                if (!content_indexer::is_course_indexed($params['courseid'])) {
                    content_indexer::index_course($params['courseid']);
                }
                $rawtopk = get_config('local_ai_course_assistant', 'rag_topk');
                $topk = ($rawtopk === false || $rawtopk === '') ? 5 : (int) $rawtopk;
                $ragstart = microtime(true);
                $retrievedchunks = rag_retriever::retrieve(
                    $params['courseid'],
                    $params['message'],
                    $topk,
                    (int) $params['pageid']
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
        $response = $provider->chat_completion($systemprompt, $history);
        $tokenusage = $provider->get_last_token_usage();

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
        conversation_manager::add_message(
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
            'complete'
        );

        return [
            'response' => $response,
            'success' => true,
        ];
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
