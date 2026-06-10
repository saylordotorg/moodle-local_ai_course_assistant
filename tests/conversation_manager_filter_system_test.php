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
 * v6.0.1 regression test for the system-row-leak fix in
 * conversation_manager::get_messages().
 *
 * Before v6.0.1, get_messages() returned every row in
 * local_ai_course_assistant_msgs for a conversationid, including the
 * role='system' telemetry rows that premium_router (v5.12) writes for
 * every escalation decision. Those rows leaked into:
 *   1. The learner-facing chat-history web service (get_history.php),
 *      where the drawer JS would render "[PremiumRouter] trigger:derive".
 *   2. get_history_for_api(), which builds the LLM context window from
 *      get_messages() — so the next chat turn's prompt would include
 *      "[PremiumRouter] trigger:derive" as a system message, leaking the
 *      routing logic to the model and potentially confusing it.
 *
 * v6.0.1 adds a `role IN ('user', 'assistant')` filter in get_messages
 * so telemetry rows stay in the DB for analytics but never reach learners
 * or the LLM.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\conversation_manager::get_messages
 */
final class conversation_manager_filter_system_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Insert a row directly into the msgs table so we can verify
     * get_messages's filter independently of conversation_manager::add_*.
     */
    private function insert_msg(int $convid, int $userid, int $courseid,
            string $role, string $message, string $providerid = '',
            string $itype = 'chat'): int {
        global $DB;
        return (int) $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => $convid,
            'userid' => $userid,
            'courseid' => $courseid,
            'role' => $role,
            'message' => $message,
            'tokens_used' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'model_name' => '',
            'provider' => $providerid,
            'interaction_type' => $itype,
            'timecreated' => time(),
        ]);
    }

    public function test_get_messages_returns_only_user_and_assistant_rows(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $convid = 999;
        // Plant a realistic conversation plus three telemetry rows.
        $this->insert_msg($convid, $user->id, $course->id, 'user',      'derive the integral of x*ln(x)');
        $this->insert_msg($convid, $user->id, $course->id, 'assistant', "Let's set u = ln(x)...");
        $this->insert_msg($convid, $user->id, $course->id, 'system',    '[PremiumRouter] trigger:\\bderive\\b', 'premium_router', 'premium_route');
        $this->insert_msg($convid, 0,         SITEID,       'system',    '[Rerank]', 'rerank', 'rerank');
        $this->insert_msg($convid, 0,         SITEID,       'system',    '[Embedding]', 'embedding', 'embedding');

        $rows = conversation_manager::get_messages($convid);
        $this->assertCount(2, $rows, 'Expected only user + assistant rows (system telemetry should be filtered).');
        $roles = array_map(fn($r) => $r->role, array_values($rows));
        sort($roles);
        $this->assertEquals(['assistant', 'user'], $roles);
    }

    public function test_get_messages_does_not_leak_premium_router_message_text(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $convid = 1001;
        $this->insert_msg($convid, $user->id, $course->id, 'user',      'help with this');
        $this->insert_msg($convid, $user->id, $course->id, 'system',    '[PremiumRouter] trigger:\\bprove\\s+that\\b', 'premium_router', 'premium_route');
        $this->insert_msg($convid, $user->id, $course->id, 'assistant', 'Sure, let\'s walk through it.');

        $rows = conversation_manager::get_messages($convid);
        foreach ($rows as $r) {
            $this->assertStringNotContainsString('[PremiumRouter]', (string) $r->message);
            $this->assertNotEquals('system', $r->role);
        }
    }

    public function test_get_history_for_api_does_not_send_system_rows_to_llm(): void {
        // The LLM context-window builder calls get_messages() under the hood.
        // Confirm the system-row filter propagates so we don't leak routing
        // logic into the model's prompt.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $convid = 1002;
        $this->insert_msg($convid, $user->id, $course->id, 'user',      'q1');
        $this->insert_msg($convid, $user->id, $course->id, 'assistant', 'a1');
        $this->insert_msg($convid, $user->id, $course->id, 'system',    '[PremiumRouter] course_tag', 'premium_router', 'premium_route');
        $this->insert_msg($convid, $user->id, $course->id, 'user',      'q2');
        $this->insert_msg($convid, $user->id, $course->id, 'assistant', 'a2');

        $api = conversation_manager::get_history_for_api($convid);
        // 2 user + 2 assistant = 4 messages; no system row.
        $this->assertCount(4, $api);
        foreach ($api as $msg) {
            $this->assertNotEquals('system', $msg['role']);
            $this->assertStringNotContainsString('PremiumRouter', $msg['content']);
        }
    }

    public function test_get_messages_returns_messages_in_chronological_order(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $convid = 1003;
        // Insert out of order to verify the ORDER BY still holds with the filter.
        global $DB;
        $base = time() - 100;
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => $convid, 'userid' => $user->id, 'courseid' => $course->id,
            'role' => 'assistant', 'message' => 'second', 'tokens_used' => 0,
            'prompt_tokens' => 0, 'completion_tokens' => 0, 'model_name' => '',
            'provider' => '', 'interaction_type' => 'chat', 'timecreated' => $base + 10,
        ]);
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => $convid, 'userid' => $user->id, 'courseid' => $course->id,
            'role' => 'user', 'message' => 'first', 'tokens_used' => 0,
            'prompt_tokens' => 0, 'completion_tokens' => 0, 'model_name' => '',
            'provider' => '', 'interaction_type' => 'chat', 'timecreated' => $base,
        ]);
        $rows = array_values(conversation_manager::get_messages($convid));
        $this->assertEquals('first', $rows[0]->message);
        $this->assertEquals('second', $rows[1]->message);
    }
}
