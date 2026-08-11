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

defined('MOODLE_INTERNAL') || die();

/**
 * Tests the course-list predicate behind the Redash export's `courses` section.
 *
 * The export used to build its list from an unfiltered SELECT DISTINCT courseid,
 * which produced course rows whose every metric was zero: embedding telemetry is
 * written against SITEID, so the site course appeared as a course whose only
 * content was the RAG indexing ledger, and the list ignored `since` while every
 * metric in the row honoured it.
 *
 * Exercises the same query the endpoint runs, since the endpoint is a script.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\analytics::conversation_rows_predicate
 */
final class redash_export_course_list_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Insert one msgs row.
     *
     * @param array $overrides
     */
    private function msg(array $overrides): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_msgs', (object) ($overrides + [
            'conversationid' => 0,
            'userid' => 0,
            'courseid' => SITEID,
            'role' => 'assistant',
            'message' => 'x',
            'tokens_used' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'model_name' => 'gpt-4o-mini',
            'provider' => 'openai',
            'interaction_type' => 'chat',
            'timecreated' => time(),
        ]));
    }

    /**
     * The exact course-list query the endpoint builds.
     *
     * @param int $since
     * @return array course ids
     */
    private function course_list(int $since = 0): array {
        global $DB;
        $params = [];
        $where = analytics::conversation_rows_predicate('m');
        if ($since > 0) {
            $where .= ' AND m.timecreated >= :since';
            $params['since'] = $since;
        }
        $ids = $DB->get_fieldset_sql(
            "SELECT DISTINCT m.courseid FROM {local_ai_course_assistant_msgs} m WHERE {$where}",
            $params
        );
        $ids = array_map('intval', $ids);
        sort($ids);
        return $ids;
    }

    public function test_embedding_telemetry_alone_does_not_create_a_course(): void {
        $this->msg(['role' => 'system', 'message' => '[Embedding]', 'provider' => 'embedding',
            'interaction_type' => 'embedding', 'model_name' => 'voyage-3.5',
            'prompt_tokens' => 50000, 'tokens_used' => 50000]);

        // The phantom row: SITEID used to appear here with all-zero metrics.
        $this->assertSame([], $this->course_list());
    }

    public function test_real_chat_on_the_site_course_is_still_listed(): void {
        // The widget appears on non-course pages, where chat legitimately carries
        // courseid = SITEID. A blanket SITEID exclusion would have dropped this.
        $this->msg(['role' => 'user', 'message' => 'hello on the site home']);

        $this->assertSame([(int) SITEID], $this->course_list());
    }

    public function test_courses_with_real_activity_are_listed(): void {
        $one = $this->getDataGenerator()->create_course();
        $two = $this->getDataGenerator()->create_course();
        $this->msg(['courseid' => $one->id, 'role' => 'user']);
        $this->msg(['courseid' => $two->id, 'role' => 'assistant']);
        // Telemetry on a third course must not add it.
        $three = $this->getDataGenerator()->create_course();
        $this->msg(['courseid' => $three->id, 'role' => 'system',
            'interaction_type' => 'premium_route', 'provider' => 'premium_router']);

        $expected = [(int) $one->id, (int) $two->id];
        sort($expected);
        $this->assertSame($expected, $this->course_list());
    }

    public function test_since_window_applies_to_the_list_as_well_as_the_metrics(): void {
        $now = time();
        $stale = $this->getDataGenerator()->create_course();
        $active = $this->getDataGenerator()->create_course();
        $this->msg(['courseid' => $stale->id, 'role' => 'user',
            'timecreated' => $now - (400 * DAYSECS)]);
        $this->msg(['courseid' => $active->id, 'role' => 'user',
            'timecreated' => $now - HOURSECS]);

        // A course last used over a year ago used to appear inside a 90-day
        // export, with every metric zero because the metrics honoured the window.
        $this->assertSame([(int) $active->id], $this->course_list($now - (90 * DAYSECS)));
        // All-time still sees both.
        $expected = [(int) $stale->id, (int) $active->id];
        sort($expected);
        $this->assertSame($expected, $this->course_list(0));
    }
}
