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
 * Tests for the v5.4.2 post-deploy health check.
 *
 * The PHPUnit env is a freshly-installed plugin with full schema and
 * registered tasks — exactly the state a clean post-deploy site should
 * be in. Every check should return PASS by default; the tests pin that
 * baseline. A few targeted negative tests verify the failure paths fire
 * when the world is broken.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\health_check
 */
final class health_check_test extends \advanced_testcase {

    public function test_run_all_returns_summary_shape(): void {
        $this->resetAfterTest();
        $r = health_check::run_all();
        $this->assertArrayHasKey('checks', $r);
        $this->assertArrayHasKey('passed', $r);
        $this->assertArrayHasKey('failed', $r);
        $this->assertArrayHasKey('warned', $r);
        $this->assertNotEmpty($r['checks']);
        // Every entry must have name + status + message.
        foreach ($r['checks'] as $c) {
            $this->assertArrayHasKey('name', $c);
            $this->assertArrayHasKey('status', $c);
            $this->assertArrayHasKey('message', $c);
            $this->assertContains($c['status'], [
                health_check::STATUS_PASS,
                health_check::STATUS_FAIL,
                health_check::STATUS_WARN,
            ]);
        }
    }

    public function test_clean_install_passes_every_check(): void {
        $this->resetAfterTest();
        $r = health_check::run_all();
        $failures = array_filter($r['checks'],
            static fn($c) => $c['status'] === health_check::STATUS_FAIL);
        $this->assertEmpty($failures,
            'A freshly-installed PHPUnit env should pass every health check. Failed: '
            . implode(', ', array_map(static fn($c) => $c['name'] . ' — ' . $c['message'], $failures)));
        $this->assertEquals(0, $r['failed']);
    }

    public function test_plugin_version_check_flags_missing_version_row(): void {
        $this->resetAfterTest();
        unset_config('version', 'local_ai_course_assistant');

        $r = health_check::check_plugin_version_matches_savepoints();

        $this->assertEquals(health_check::STATUS_FAIL, $r['status']);
        $this->assertStringContainsString('No version row', $r['message']);
    }

    public function test_plugin_version_check_flags_db_behind_version_php(): void {
        $this->resetAfterTest();
        // Force the stored version far below current. health_check should
        // detect "DB behind plugin" without us having to actually break
        // version.php.
        set_config('version', '2020010100', 'local_ai_course_assistant');

        $r = health_check::check_plugin_version_matches_savepoints();

        $this->assertEquals(health_check::STATUS_FAIL, $r['status']);
        $this->assertStringContainsString('behind plugin version.php', $r['message']);
    }

    public function test_scheduled_tasks_check_passes_on_clean_install(): void {
        $this->resetAfterTest();
        $r = health_check::check_all_scheduled_tasks_registered();
        $this->assertEquals(health_check::STATUS_PASS, $r['status'],
            'Fresh install must register every scheduled task. Got: ' . $r['message']);
    }

    public function test_scheduled_tasks_check_flags_unregistered_task(): void {
        $this->resetAfterTest();
        global $DB;
        // Drop one SOLA task from the schedule. health_check should detect.
        $DB->delete_records_select('task_scheduled',
            "classname LIKE '%local_ai_course_assistant%audit_cleanup%'");

        $r = health_check::check_all_scheduled_tasks_registered();

        $this->assertEquals(health_check::STATUS_FAIL, $r['status']);
        $this->assertStringContainsString('audit_cleanup', $r['message']);
    }

    public function test_lang_strings_check_passes_on_clean_install(): void {
        $this->resetAfterTest();
        $r = health_check::check_lang_strings_load();
        $this->assertEquals(health_check::STATUS_PASS, $r['status'], $r['message']);
    }

    public function test_privacy_provider_metadata_check_passes(): void {
        $this->resetAfterTest();
        $r = health_check::check_privacy_provider_metadata();
        $this->assertEquals(health_check::STATUS_PASS, $r['status'], $r['message']);
        $this->assertStringContainsString('database_table item', $r['message']);
    }

    public function test_no_cron_tasks_stuck_check_passes_on_clean_install(): void {
        $this->resetAfterTest();
        $r = health_check::check_no_cron_tasks_stuck();
        $this->assertEquals(health_check::STATUS_PASS, $r['status'], $r['message']);
    }

    public function test_no_cron_tasks_stuck_check_flags_high_faildelay(): void {
        $this->resetAfterTest();
        global $DB;
        // Set faildelay > 1h on a SOLA task. health_check should flag it.
        $DB->set_field_select('task_scheduled', 'faildelay', 7200,
            "classname LIKE '%local_ai_course_assistant%audit_cleanup%'");

        $r = health_check::check_no_cron_tasks_stuck();

        $this->assertEquals(health_check::STATUS_FAIL, $r['status']);
        $this->assertStringContainsString('audit_cleanup', $r['message']);
        $this->assertStringContainsString('faildelay=7200', $r['message']);
    }

    public function test_audit_log_writable_check_passes(): void {
        $this->resetAfterTest();
        $r = health_check::check_audit_log_writable();
        $this->assertEquals(health_check::STATUS_PASS, $r['status'], $r['message']);
    }

    public function test_no_falsy_default_pattern_check_passes(): void {
        $this->resetAfterTest();
        $r = health_check::check_no_falsy_default_get_config_pattern();
        $this->assertEquals(health_check::STATUS_PASS, $r['status'],
            'classes/ should be free of `?: <numeric>` patterns after the '
            . 'v5.3.31-v5.3.34 sweep. Got: ' . $r['message']);
    }

    public function test_provider_resolves_check_skips_when_plugin_disabled(): void {
        $this->resetAfterTest();
        set_config('enabled', 0, 'local_ai_course_assistant');
        $r = health_check::check_provider_resolves_when_enabled();
        $this->assertEquals(health_check::STATUS_PASS, $r['status']);
        $this->assertStringContainsString('not enabled', $r['message']);
    }

    public function test_provider_resolves_check_warns_when_enabled_but_unset(): void {
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_ai_course_assistant');
        unset_config('provider', 'local_ai_course_assistant');

        $r = health_check::check_provider_resolves_when_enabled();

        $this->assertEquals(health_check::STATUS_WARN, $r['status']);
        $this->assertStringContainsString('no provider is configured', $r['message']);
    }

    public function test_provider_resolves_check_fails_on_unknown_provider_id(): void {
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_ai_course_assistant');
        set_config('provider', 'totally-not-a-provider', 'local_ai_course_assistant');

        $r = health_check::check_provider_resolves_when_enabled();

        $this->assertEquals(health_check::STATUS_FAIL, $r['status']);
        $this->assertStringContainsString('totally-not-a-provider', $r['message']);
    }
}
