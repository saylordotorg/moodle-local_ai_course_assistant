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

use core\check\result;
use local_ai_course_assistant\check\primary_provider;

/**
 * The status check that says the main chat provider is failing (v7.8.1).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\check\primary_provider
 */
final class primary_provider_check_test extends \advanced_testcase {
    /**
     * Write one failover fall-through audit row.
     *
     * @param string $failed Label of the provider that failed.
     * @param string $primary Label of the primary.
     * @param int $age Seconds ago.
     * @param string $reason
     */
    private function fallthrough(string $failed, string $primary, int $age = 60, string $reason = 'HTTP 401'): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_audit', (object) [
            'action' => 'failover_fallthrough',
            'userid' => 0,
            'courseid' => 0,
            'ipaddress' => '',
            'useragent' => '',
            'details' => json_encode([
                'failed_label' => $failed, 'primary' => $primary,
                'failed_model' => 'gemini-2.5-flash', 'reason' => $reason,
            ]),
            'timecreated' => time() - $age,
        ]);
    }

    public function test_quiet_site_is_ok(): void {
        $this->resetAfterTest();
        $this->assertSame(result::OK, (new primary_provider())->get_result()->get_status());
    }

    public function test_backup_failures_do_not_count(): void {
        $this->resetAfterTest();
        for ($i = 0; $i < 5; $i++) {
            $this->fallthrough('openai', 'gemini');
        }
        $this->assertSame(result::OK, (new primary_provider())->get_result()->get_status());
    }

    public function test_old_failures_do_not_count(): void {
        $this->resetAfterTest();
        for ($i = 0; $i < 5; $i++) {
            $this->fallthrough('gemini', 'gemini', 2 * DAYSECS);
        }
        $this->assertSame(result::OK, (new primary_provider())->get_result()->get_status());
    }

    public function test_few_failures_are_info_some_warn_many_error(): void {
        $this->resetAfterTest();
        $this->fallthrough('gemini', 'gemini');
        $this->assertSame(result::INFO, (new primary_provider())->get_result()->get_status());
        $this->fallthrough('gemini', 'gemini');
        $this->fallthrough('gemini', 'gemini');
        $this->assertSame(result::WARNING, (new primary_provider())->get_result()->get_status());
        for ($i = 0; $i < 7; $i++) {
            $this->fallthrough('gemini', 'gemini');
        }
        $this->assertSame(result::ERROR, (new primary_provider())->get_result()->get_status());
    }

    public function test_message_names_the_model_and_hides_credentials(): void {
        $this->resetAfterTest();
        $this->fallthrough('gemini', 'gemini', 60, 'rejected key sk-proj-abcdefghijklmnopqrstuvwxyz123456');
        $text = (new primary_provider())->get_result()->get_summary();
        $this->assertStringContainsString('gemini-2.5-flash', $text);
        $this->assertStringNotContainsString('abcdefghijklmnop', $text);
    }

    public function test_error_html_is_escaped(): void {
        $this->resetAfterTest();
        $this->fallthrough('gemini', 'gemini', 60, '<b onmouseover=x>502</b> bad');
        $text = (new primary_provider())->get_result()->get_summary();
        $this->assertStringNotContainsString('<b ', $text);
    }

    public function test_plugin_registers_the_check(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/ai_course_assistant/lib.php');
        $checks = local_ai_course_assistant_status_checks();
        $this->assertInstanceOf(primary_provider::class, $checks[0]);
    }
}
