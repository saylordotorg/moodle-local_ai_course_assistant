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
 * Regression tests: analytics::get_overview() must count learner messages only.
 *
 * The msgs table is not only chat. Three writers persist role='system' telemetry
 * into it: base_embedding_provider::log_embedding_cost() (one row per embedding
 * call, written against SITEID), premium_router (one row per escalated turn,
 * written against the real course id) and voyage_reranker. get_overview's
 * total_messages had no role filter, so it counted all of them as learner
 * messages. Observed on dev.sylr.org 2026-08-03: the site course reported 50,766
 * "messages" alongside 0 conversations and 0 active students, because every one
 * of those rows was a RAG indexing cost-ledger entry.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\analytics::get_overview
 */
final class analytics_overview_roles_test extends \advanced_testcase {
    /**
     * Insert one msgs row.
     *
     * @param int $courseid
     * @param string $role
     * @param int $userid
     * @param int $convid
     * @param string $text
     * @param string $itype interaction_type
     * @param int $timecreated 0 for now.
     */
    private function msg(
        int $courseid,
        string $role,
        int $userid,
        int $convid,
        string $text,
        string $itype,
        int $timecreated = 0
    ): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => $convid,
            'userid' => $userid,
            'courseid' => $courseid,
            'role' => $role,
            'message' => $text,
            'tokens_used' => 5,
            'prompt_tokens' => 5,
            'completion_tokens' => 0,
            'model_name' => 'test-model',
            'provider' => 'test',
            'interaction_type' => $itype,
            'timecreated' => $timecreated ?: time(),
        ]);
    }

    /**
     * Mirror of base_embedding_provider::log_embedding_cost(): role=system,
     * courseid=SITEID, userid 0, conversationid 0.
     *
     * @param int $count how many rows to write.
     */
    private function embedding_telemetry(int $count): void {
        for ($i = 0; $i < $count; $i++) {
            $this->msg(SITEID, 'system', 0, 0, '[Embedding]', 'embedding');
        }
    }

    public function test_site_course_with_only_embedding_telemetry_reports_no_messages(): void {
        $this->resetAfterTest();
        $this->embedding_telemetry(3);

        $overview = analytics::get_overview(SITEID, 0);

        // The three numbers must agree: no learner activity anywhere.
        $this->assertSame(0, (int) $overview['total_messages']);
        $this->assertSame(0, (int) $overview['total_conversations']);
        $this->assertSame(0, (int) $overview['active_students']);
    }

    public function test_premium_router_telemetry_does_not_inflate_a_real_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $this->msg($course->id, 'user', $user->id, 11, 'hello', 'chat');
        $this->msg($course->id, 'assistant', $user->id, 11, 'hi', 'chat');
        // What premium_router writes: role=system against the REAL course id.
        $this->msg($course->id, 'system', $user->id, 11, '[PremiumRouter] stem', 'premium_route');

        $overview = analytics::get_overview($course->id, 0);

        $this->assertSame(2, (int) $overview['total_messages']);
        $this->assertSame(1, (int) $overview['active_students']);
        // Derived metric moves with the corrected count: 2 messages / 1 student.
        $this->assertEquals(2.0, (float) $overview['avg_messages_per_student']);
    }

    public function test_reranker_telemetry_is_also_excluded(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $this->msg($course->id, 'user', $user->id, 12, 'q', 'chat');
        $this->msg($course->id, 'system', $user->id, 12, '[Rerank] 30 candidates', 'rerank');

        $this->assertSame(1, (int) analytics::get_overview($course->id, 0)['total_messages']);
    }

    public function test_has_data_flag_is_false_for_a_telemetry_only_course(): void {
        $this->resetAfterTest();
        $this->embedding_telemetry(5);

        // analytics.php gates the dashboard on total_messages > 0, so telemetry
        // alone must not make an empty course look active.
        $this->assertSame(0, (int) analytics::get_overview(SITEID, 0)['total_messages']);
    }

    public function test_genuine_messages_are_still_counted_both_sides(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();

        $this->msg($course->id, 'user', $one->id, 21, 'a', 'chat');
        $this->msg($course->id, 'assistant', $one->id, 21, 'b', 'chat');
        $this->msg($course->id, 'user', $two->id, 22, 'c', 'chat');
        $this->msg($course->id, 'assistant', $two->id, 22, 'd', 'chat');

        $overview = analytics::get_overview($course->id, 0);
        // Both halves of the exchange count; only telemetry is excluded.
        $this->assertSame(4, (int) $overview['total_messages']);
        $this->assertSame(2, (int) $overview['active_students']);
    }

    public function test_role_filter_composes_with_the_since_window(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $now = time();

        $this->msg($course->id, 'user', $user->id, 31, 'old', 'chat', $now - (30 * DAYSECS));
        $this->msg($course->id, 'user', $user->id, 32, 'new', 'chat', $now - HOURSECS);
        $this->msg($course->id, 'system', $user->id, 32, '[Rerank]', 'rerank', $now - HOURSECS);

        // Inside the window: the recent learner message only, not the telemetry.
        $this->assertSame(1, (int) analytics::get_overview($course->id, $now - DAYSECS)['total_messages']);
        // All time: both learner messages, still no telemetry.
        $this->assertSame(2, (int) analytics::get_overview($course->id, 0)['total_messages']);
    }

    public function test_other_courses_are_unaffected_by_site_level_telemetry(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $this->embedding_telemetry(4);
        $this->msg($course->id, 'user', $user->id, 41, 'hello', 'chat');

        // Embedding rows land on SITEID, so they never belonged to this course,
        // but pin it so a future courseid change in the writer is caught here.
        $this->assertSame(1, (int) analytics::get_overview($course->id, 0)['total_messages']);
        $this->assertSame(0, (int) analytics::get_overview(SITEID, 0)['total_messages']);
    }
}
