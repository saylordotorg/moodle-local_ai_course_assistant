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
 * Upgrade step 2026101903 removes course-level chunks so they are rebuilt without hidden sections (#341), and
 * queues a rebuild for each course it cleaned, so a course the nightly index never visits isn't left without them.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\content_indexer::delete_course_level_chunks
 * @covers     \local_ai_course_assistant\content_indexer::reset_course_level_chunks
 * @covers     \local_ai_course_assistant\task\rebuild_course_index
 */
final class course_level_chunk_cleanup_upgrade_test extends \advanced_testcase {
    /** @var string Plugin component. */
    private const PLUGIN = 'local_ai_course_assistant';

    /** @var string Chunk table. */
    private const TABLE = 'local_ai_course_assistant_chunks';

    /** @var int The build just before the cleanup step. */
    private const BEFORE = 2026101902;

    /** @var int The cleanup step's build. */
    private const CLEANUP = 2026101903;

    /**
     * Run the plugin's real upgrade function as a site on the given build would.
     *
     * @param int $from The build the site is upgrading from.
     */
    private function upgrade_from(int $from): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php'); // Core's upgrade script has loaded this by the time a step runs.
        require_once($CFG->dirroot . '/local/ai_course_assistant/db/upgrade.php');
        set_config('version', $from, self::PLUGIN);
        $this->assertTrue(\xmldb_local_ai_course_assistant_upgrade($from));
    }

    /**
     * Store one chunk row.
     *
     * @param int $courseid
     * @param int|null $cmid
     * @param string $modtype
     * @param string $content
     * @param string $model Embedding model (a shadow-migration row carries the target model).
     * @return int The row id.
     */
    private function row(int $courseid, ?int $cmid, string $modtype, string $content, string $model = 'live-model'): int {
        global $DB;
        return (int) $DB->insert_record(self::TABLE, (object) [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'modtype' => $modtype,
            'chunkindex' => 0,
            'content' => $content,
            'contenthash' => sha1($content . $model),
            'embedding' => null,
            'embedding_bin' => 'x',
            'embed_model' => $model,
            'embed_dtype' => 'float',
            'timecreated' => time(),
            'timeindexed' => time(),
        ]);
    }

    /**
     * Course-level rows of every course and model go; activity and FAQ rows stay; a repeat deletes nothing.
     */
    public function test_the_step_deletes_only_course_level_chunks_and_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $a = $this->row(42, null, 'course', 'Course A overview with HIDDENSECTION summary');
        $ashadow = $this->row(42, null, 'course', 'Course A overview with HIDDENSECTION summary', 'target-model');
        $b = $this->row(43, null, 'course', 'Course B overview');
        $page = $this->row(42, 7, 'page', 'A page body');
        $faq = $this->row(SITEID, 0, faq_manager::MODTYPE, 'How do certificates work?');

        $this->expectOutputRegex('/SOLA: removed 3 course-level RAG chunk\(s\) for rebuilding \(#341\); queued 2 course rebuild/');
        $this->upgrade_from(self::BEFORE);

        foreach ([$a, $ashadow, $b] as $id) {
            $this->assertFalse($DB->record_exists(self::TABLE, ['id' => $id]), "course-level row {$id} is gone");
        }
        $this->assertTrue($DB->record_exists(self::TABLE, ['id' => $page]), 'activity chunks are untouched');
        $this->assertTrue($DB->record_exists(self::TABLE, ['id' => $faq]), 'FAQ chunks are untouched');
        $this->assertGreaterThanOrEqual(self::CLEANUP, (int) get_config(self::PLUGIN, 'version'), 'the savepoint was reached');

        // One rebuild per course that had course-level rows, none for the site course or for activities.
        $this->assertSame([42, 43], $this->queued_courseids());

        // A second pass has nothing left to delete and doesn't queue a course twice.
        $this->upgrade_from(self::BEFORE);
        $this->assertSame(0, content_indexer::delete_course_level_chunks());
        $this->assertSame(2, $DB->count_records(self::TABLE));
        $this->assertSame([42, 43], $this->queued_courseids());
    }

    /**
     * The courses that have a queued rebuild, in order.
     *
     * @return int[]
     */
    private function queued_courseids(): array {
        $ids = [];
        foreach (\core\task\manager::get_adhoc_tasks(\local_ai_course_assistant\task\rebuild_course_index::class) as $task) {
            $ids[] = (int) $task->get_custom_data()->courseid;
        }
        sort($ids);
        return $ids;
    }

    /**
     * A shared supplemental course with no enrolments is rebuilt even though the nightly index never visits it.
     */
    public function test_a_course_with_no_enrolments_still_gets_a_rebuild_queued(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->row((int) $course->id, null, 'course', 'Shared reference course overview');

        $reset = content_indexer::reset_course_level_chunks();

        $this->assertSame(['deleted' => 1, 'queued' => 1], $reset);
        $this->assertSame([(int) $course->id], $this->queued_courseids());
    }
}
