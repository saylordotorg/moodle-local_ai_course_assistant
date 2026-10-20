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
 * The ad hoc task that upgrade step 2026101903 queues to rebuild a course's index (#341).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\task\rebuild_course_index
 */
final class rebuild_course_index_test extends \advanced_testcase {
    /**
     * Load the task double.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        require_once(__DIR__ . '/fixtures/rebuild_course_index_recording.php');
    }

    /**
     * A task for one course whose reindex is recorded instead of run.
     *
     * @param int $courseid
     * @param \Throwable|null $failure Thrown by the reindex when set.
     * @return fixtures\rebuild_course_index_recording
     */
    private function task(int $courseid, ?\Throwable $failure = null): fixtures\rebuild_course_index_recording {
        $task = new fixtures\rebuild_course_index_recording();
        $task->failure = $failure;
        $task->set_custom_data(['courseid' => $courseid]);
        return $task;
    }

    /**
     * An enabled site reindexes the named course once.
     */
    public function test_it_reindexes_the_course_named_in_the_custom_data(): void {
        $this->resetAfterTest();
        set_config('rag_enabled', 1, 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $task = $this->task((int) $course->id);

        $this->expectOutputRegex('/course ' . $course->id . ' indexed=1, skipped=2, errors=0/');
        $task->execute();

        $this->assertSame([(int) $course->id], $task->reindexed);
    }

    /**
     * Nothing to do is not a failure: no course, the site course, RAG off, a deleted course.
     */
    public function test_it_skips_quietly_when_there_is_nothing_to_rebuild(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        set_config('rag_enabled', 0, 'local_ai_course_assistant');
        $off = $this->task((int) $course->id);
        $this->expectOutputRegex('/RAG is disabled/');
        $off->execute();
        $this->assertSame([], $off->reindexed);

        set_config('rag_enabled', 1, 'local_ai_course_assistant');
        foreach ([0, (int) SITEID, 99999] as $courseid) {
            $task = $this->task($courseid);
            $task->execute();
            $this->assertSame([], $task->reindexed, "course {$courseid}");
        }
    }

    /**
     * A failure is retried while the backoff is young, and dropped (not retried forever) after the fifth run.
     */
    public function test_a_failure_is_retried_five_times_and_then_dropped(): void {
        $this->resetAfterTest();
        set_config('rag_enabled', 1, 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();

        $young = $this->task((int) $course->id, new \RuntimeException('embedding provider unavailable'));
        $young->set_fail_delay(60);
        try {
            $young->execute();
            $this->fail('a young failure is thrown so Moodle retries it');
        } catch (\RuntimeException $e) {
            $this->assertSame('embedding provider unavailable', $e->getMessage());
        }

        $old = $this->task((int) $course->id, new \RuntimeException('embedding provider unavailable'));
        $old->set_fail_delay(960);
        $this->expectOutputRegex('/failed again and was dropped: embedding provider unavailable/');
        $old->execute();
    }

    /**
     * The task has a readable name on the queue screens.
     */
    public function test_it_has_a_name(): void {
        $this->assertNotSame('', (new \local_ai_course_assistant\task\rebuild_course_index())->get_name());
    }
}
