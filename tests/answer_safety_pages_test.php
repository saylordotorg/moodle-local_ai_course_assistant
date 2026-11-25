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

/**
 * The two pages a teacher uses for answer safety, run as the real scripts (8.4.0).
 *
 * answer_audit.php and instructor_dashboard.php are included as a teacher of the course, with recorded answers
 * that give a claim item, a rule item, a redacted row and a detail view. PHPUnit turns any PHP notice or warning
 * and any debugging() call into a failure, so a clean run means the pages render without one. Also pins the links
 * between the two pages.
 *
 * Neither script is run in a separate process, so the branches that exit (the course picker, the exports) are not
 * covered here: answer_audit_report_test covers the export writers.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class answer_safety_pages_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;

    /** @var int a claim item's answer row */
    private $claimanswer;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['fullname' => 'Audit <Course> & Co']);
        $teacher = $gen->create_and_enrol($this->course, 'editingteacher');
        $student = $gen->create_and_enrol($this->course, 'student');
        set_config('answer_safety_mode', 'enforce', 'local_ai_course_assistant');
        $record = fn(int $n, array $rules, string $question): int => answer_audit::record([
            'messageid' => $n, 'conversationid' => $n, 'userid' => (int) $student->id,
            'courseid' => (int) $this->course->id, 'question' => $question, 'answer' => 'The final is due on Friday.',
            'categories' => ['deadlines'], 'rules' => $rules,
            'chunks' => [['cmid' => 0, 'score' => 0.5, 'ref' => 'chunk:1']],
        ]);
        $this->claimanswer = $record(1, [['id' => 'deadlines.date', 'action' => 'warn'],
            ['id' => 'mode.enforce', 'action' => 'none'], ['id' => 'outcome.warned', 'action' => 'none']],
            'When is the final due?');
        answer_audit::append_verdict($this->claimanswer, ['status' => 'needs_review', 'severity' => 'high',
            'judge_model' => 'judge-model', 'claims' => [['text' => 'The final is due on Friday.', 'type' => 'deadline',
            'status' => 'contradicted', 'evidence_ref' => 'chunk:1', 'severity' => 'high']]]);
        $record(2, [['id' => 'grades.promise', 'action' => 'block']], 'Will I pass?');
        $record(3, [], 'What is a cell?');
        $this->setUser($teacher);
    }

    /**
     * Run a page script as the current user and return what it printed.
     *
     * @param string $script file name in the plugin root
     * @param array $get query parameters
     * @return string
     */
    private function run_page(string $script, array $get): string {
        global $CFG, $PAGE, $OUTPUT, $DB, $USER, $SESSION, $COURSE, $SITE;
        $PAGE = new \moodle_page();
        $OUTPUT = new \bootstrap_renderer();
        $_GET = $get;
        $_POST = [];
        ob_start();
        try {
            include($CFG->dirroot . '/local/ai_course_assistant/' . $script);
        } finally {
            $html = ob_get_clean();
            $_GET = [];
        }
        return $html;
    }

    public function test_the_audit_list_renders_cleanly_and_links_to_the_dashboard(): void {
        $cid = (int) $this->course->id;
        $html = $this->run_page('answer_audit.php', ['courseid' => $cid]);
        $this->assertStringContainsString('local-ai-course-assistant-answer-audit', $html);
        $this->assertStringContainsString('instructor_dashboard.php?courseid=' . $cid . '"', $html);
        $this->assertStringContainsString(get_string('answeraudit:back_dashboard', 'local_ai_course_assistant'), $html);
        $this->assertStringContainsString('deadlines.date: '
            . get_string('answeraudit:action_warn', 'local_ai_course_assistant'), $html);
        $this->assertStringNotContainsString('<Course>', $html);
    }

    public function test_the_audit_detail_and_a_filter_with_no_match_render_cleanly(): void {
        $cid = (int) $this->course->id;
        $html = $this->run_page('answer_audit.php', ['courseid' => $cid, 'id' => $this->claimanswer,
            'verdict' => 'needs_review']);
        $this->assertStringContainsString('id="aica-aa-detail"', $html);
        $this->assertStringContainsString(get_string('answeraudit:claim_contradicted', 'local_ai_course_assistant'),
            $html);
        $this->assertStringContainsString('judge-model', $html);

        $html = $this->run_page('answer_audit.php', ['courseid' => $cid, 'verdict' => 'blocked', 'from' => '2026-01-01',
            'to' => 'not-a-date']);
        $this->assertStringContainsString(get_string('answeraudit:norows', 'local_ai_course_assistant'), $html);
    }

    public function test_the_dashboard_renders_cleanly_with_claim_and_rule_items_and_links_to_the_audit(): void {
        $cid = (int) $this->course->id;
        $html = $this->run_page('instructor_dashboard.php', ['courseid' => $cid]);
        $this->assertStringContainsString('id="aica-id-review"', $html);
        $review = substr($html, strpos($html, 'id="aica-id-review"'));
        $str = static fn(string $id): string => get_string($id, 'local_ai_course_assistant');
        $this->assertStringContainsString($str('instructor_dashboard:review_source_claim'), $review);
        $this->assertStringContainsString($str('instructor_dashboard:review_source_rule'), $review);
        $this->assertStringContainsString('answer_audit.php?courseid=' . $cid . '">'
            . $str('instructor_dashboard:review_audit_link'), $review);
        $this->assertStringContainsString('<code>grades.promise</code>: ' . $str('answeraudit:action_block'), $review);
    }

    public function test_the_dashboard_with_nothing_to_review_says_so_and_has_no_audit_link_when_safety_never_ran(): void {
        global $DB;
        set_config('answer_safety_mode', 'off', 'local_ai_course_assistant');
        $other = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($other, 'editingteacher');
        $this->setUser($teacher);
        $html = $this->run_page('instructor_dashboard.php', ['courseid' => (int) $other->id]);
        $this->assertStringContainsString(get_string('instructor_dashboard:review_empty', 'local_ai_course_assistant'),
            $html);
        $this->assertStringNotContainsString('answer_audit.php', $html);
    }
}
