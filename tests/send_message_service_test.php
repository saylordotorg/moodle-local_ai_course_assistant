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

namespace local_ai_course_assistant;

use local_ai_course_assistant\external\send_message;

/**
 * The non-streaming fallback endpoint must actually work.
 *
 * `local_ai_course_assistant_send_message` is registered as the non-streaming
 * fallback and is exposed to MOODLE_OFFICIAL_MOBILE_SERVICE, yet it threw a
 * TypeError on every call: argument 11 of add_message() is $interactiontype,
 * typed string, and this path passed an explicit null. The throw happened after
 * the provider request had completed, so each call billed an AI request and
 * returned nothing.
 *
 * It survived because no shipped client calls it -- the widget streams through
 * sse.php -- so the only consumer is the mobile app, which nothing in the test
 * suite exercised. Hence this file: the contract is checked without a provider,
 * because the defect was in the bookkeeping around the provider call, not in
 * the call itself.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\send_message
 */
final class send_message_service_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;

    /** @var \stdClass */
    private $student;

    /**
     * An enrolled student in a course with the assistant available.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->student = $gen->create_user();
        $gen->enrol_user($this->student->id, $this->course->id, 'student');
        $this->setUser($this->student);
        // No provider is configured, so the call fails at the factory. That is
        // deliberate: it exercises everything around the provider, which is
        // where the defect lived.
        set_config('rag_enabled', 0, 'local_ai_course_assistant');
    }

    /**
     * The user's own row is written before anything can fail.
     *
     * This is the row that proves the endpoint got as far as the provider, and
     * it must carry the same interaction type the streaming path writes or the
     * two are not comparable in analytics.
     */
    public function test_user_row_matches_what_the_streaming_path_writes(): void {
        global $DB;

        try {
            send_message::execute((int) $this->course->id, 'What is management?', 0);
        } catch (\Throwable $e) {
            // No provider configured; the user row is already committed.
            $this->assertInstanceOf(\moodle_exception::class, $e);
        }

        $row = $DB->get_record('local_ai_course_assistant_msgs', [
            'courseid' => $this->course->id,
            'userid' => $this->student->id,
            'role' => 'user',
        ]);
        $this->assertNotFalse($row, 'The learner question was never recorded.');
        $this->assertSame(
            'chat',
            $row->interaction_type,
            'sse.php defaults interaction_type to "chat"; this path must agree.'
        );
    }

    /**
     * add_message() must never be handed a null interaction type.
     *
     * Pinned as a property of the call rather than of one code path: argument
     * 11 is typed `string`, so a null anywhere is a TypeError after the model
     * has already been paid for.
     */
    public function test_add_message_rejects_a_null_interaction_type(): void {
        global $DB;

        $conv = conversation_manager::get_or_create_conversation(
            (int) $this->student->id,
            (int) $this->course->id
        );

        $this->expectException(\TypeError::class);
        conversation_manager::add_message(
            (int) $conv->id,
            (int) $this->student->id,
            (int) $this->course->id,
            'assistant',
            'text',
            0,
            '',
            null,
            null,
            null,
            // Argument 11. This is exactly what send_message passed.
            null
        );
    }

    /**
     * An internal failure returns a generic message, not a class path.
     *
     * The TypeError reached the caller complete with the class name and the
     * dirroot path of the file that threw. A learner-facing endpoint must not
     * describe its own internals to whoever called it.
     */
    public function test_internal_failure_is_not_described_to_the_caller(): void {
        // A course the student cannot see makes context validation fail deep in
        // the call rather than at the parameter check.
        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('apikey', '', 'local_ai_course_assistant');

        try {
            $result = send_message::execute((int) $this->course->id, 'Hello', 0);
            // If it returned rather than threw, the body must be clean.
            $blob = json_encode($result);
            foreach (['TypeError', 'dirroot', '.php:', 'local_ai_course_assistant\\\\'] as $leak) {
                $this->assertStringNotContainsString($leak, (string) $blob);
            }
            $this->assertFalse($result['success']);
        } catch (\moodle_exception $e) {
            // A deliberate operator-facing message is allowed through, but it
            // still must not carry a file path or a class name.
            $this->assertStringNotContainsString('.php:', $e->getMessage());
            $this->assertStringNotContainsString('TypeError', $e->getMessage());
        }
    }

    /**
     * The endpoint is registered for the mobile service.
     *
     * Recorded because the staging report proposed deleting it as unreachable.
     * No shipped client calls it, which is true and is why the TypeError went
     * unnoticed -- but it is the declared non-streaming fallback and is exposed
     * to the Moodle Mobile app, so removing it would break that.
     */
    public function test_endpoint_is_still_registered_for_mobile(): void {
        global $CFG;
        $services = [];
        $functions = [];
        require($CFG->dirroot . '/local/ai_course_assistant/db/services.php');

        $this->assertArrayHasKey('local_ai_course_assistant_send_message', $functions);
        $this->assertContains(
            MOODLE_OFFICIAL_MOBILE_SERVICE,
            $functions['local_ai_course_assistant_send_message']['services'],
            'The non-streaming fallback must stay available to the mobile app.'
        );
    }
}
