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

namespace local_ai_course_assistant\task;

use local_ai_course_assistant\answer_audit;
use local_ai_course_assistant\claim_checker;
use local_ai_course_assistant\conversation_manager;
use local_ai_course_assistant\provider\stub_provider;

/**
 * The claim check adhoc task (8.4.0): runs the check, never throws, never retries.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\task\check_answer_claims
 */
final class check_answer_claims_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        stub_provider::reset();
        set_config('provider', 'stub', 'local_ai_course_assistant');
        set_config('apikey', 'stub-key', 'local_ai_course_assistant');
        set_config('answer_safety_mode', 'watch', 'local_ai_course_assistant');
        set_config('answer_check_sample_percent_general', '100', 'local_ai_course_assistant');
    }

    protected function tearDown(): void {
        stub_provider::reset();
        answer_audit::$locktimeout = answer_audit::DEFAULT_LOCK_TIMEOUT;
        parent::tearDown();
    }

    /**
     * A recorded answer.
     *
     * @return array{auditid: int, courseid: int}
     */
    private function recorded(): array {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $conv = conversation_manager::get_or_create_conversation((int) $user->id, (int) $course->id);
        $msgid = conversation_manager::add_message((int) $conv->id, (int) $user->id, (int) $course->id, 'assistant',
            'The exam is on 3 May.');
        $id = answer_audit::record_answer(['messageid' => $msgid, 'conversationid' => (int) $conv->id,
            'userid' => (int) $user->id, 'courseid' => (int) $course->id, 'mode' => 'text',
            'question' => 'When is the exam?', 'answer' => 'The exam is on 3 May.'], [], [], []);
        return ['auditid' => (int) $id, 'courseid' => (int) $course->id];
    }

    public function test_get_name(): void {
        $this->assertSame(get_string('task:check_answer_claims', 'local_ai_course_assistant'),
            (new check_answer_claims())->get_name());
    }

    public function test_queued_task_writes_one_verdict(): void {
        global $DB;
        $turn = $this->recorded();
        stub_provider::program_response('chat', json_encode(['claims' => [['text' => 'The exam is on 3 May.',
            'type' => 'date', 'status' => 'contradicted', 'evidence' => 0]], 'question_wellbeing' => false]));
        $this->assertTrue(claim_checker::queue_for($turn['auditid']));
        ob_start();
        $this->runAdhocTasks(check_answer_claims::class);
        ob_end_clean();
        $verdicts = $DB->get_records(answer_audit::TABLE, ['supersedes' => $turn['auditid']]);
        $this->assertCount(1, $verdicts);
        $this->assertSame('needs_review', reset($verdicts)->status);
        $this->assertSame([], \core\task\manager::get_adhoc_tasks(check_answer_claims::class), 'not requeued');
    }

    public function test_invalid_custom_data_returns_silently(): void {
        foreach ([null, [], ['auditid' => 0], ['auditid' => -3]] as $data) {
            $task = new check_answer_claims();
            if ($data !== null) {
                $task->set_custom_data($data);
            }
            $task->execute();
        }
        $this->assertSame([], stub_provider::$calls);
    }

    public function test_a_failure_inside_the_check_never_escapes_the_task(): void {
        global $CFG, $DB;
        $turn = $this->recorded();
        stub_provider::program_response('chat', json_encode(['claims' => [], 'question_wellbeing' => false]));

        // Someone holds the course's audit lock: append_verdict() times out and throws.
        $CFG->lock_factory = '\\core\\lock\\db_record_lock_factory';
        $held = \core\lock\lock_config::get_lock_factory(answer_audit::LOCK_TYPE)
            ->get_lock(answer_audit::lock_resource($turn['courseid']), 0);
        $this->assertNotFalse($held);
        answer_audit::$locktimeout = 0;

        $task = new check_answer_claims();
        $task->set_custom_data(['auditid' => $turn['auditid']]);
        try {
            ob_start();
            $task->execute();
            $out = ob_get_clean();
        } finally {
            $held->release();
        }
        $this->assertStringContainsString('check_answer_claims: check failed', $out);
        $this->assertStringNotContainsString('3 May', $out, 'no learner text in the log');
        $this->assertSame(0, $DB->count_records(answer_audit::TABLE, ['supersedes' => $turn['auditid']]));
    }
}
