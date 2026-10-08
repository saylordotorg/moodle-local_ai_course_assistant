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
 * The notice that lists what a settings save changed (v7.8.1).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\settings_save_report
 */
final class settings_save_report_test extends \advanced_testcase {
    public function test_names_each_setting_old_and_new(): void {
        $r = settings_save_report::describe(['provider' => ['gemini', 'auto'], 'support_enabled' => ['1', '0']]);
        $this->assertSame(2, $r['changed']);
        $this->assertStringContainsString('<code>provider</code>', $r['message']);
        $this->assertStringContainsString('gemini', $r['message']);
        $this->assertStringContainsString('auto', $r['message']);
        $this->assertTrue($r['warn'], 'a provider change is worth a warning');
    }

    public function test_a_quiet_change_is_not_a_warning(): void {
        $r = settings_save_report::describe(['greeting' => ['hi', 'hello']]);
        $this->assertFalse($r['warn']);
    }

    public function test_credentials_are_never_shown(): void {
        $r = settings_save_report::describe(['apikey' => ['sk-OLDOLDOLDOLDOLD', 'sk-NEWNEWNEWNEWNEW']]);
        $this->assertStringNotContainsString('OLDOLD', $r['message']);
        $this->assertStringNotContainsString('NEWNEW', $r['message']);
        $this->assertStringContainsString('apikey', $r['message']);
    }

    public function test_line_ending_only_rewrite_is_labelled(): void {
        $r = settings_save_report::describe(['comparison_providers' => ["a|b\r\nc|d", "a|b\nc|d"]]);
        $this->assertStringContainsString(get_string('savereport:lineendings', 'local_ai_course_assistant'), $r['message']);
        $this->assertStringNotContainsString('&rarr;', $r['message']);
    }

    public function test_values_are_escaped_and_shortened(): void {
        $r = settings_save_report::describe(['greeting' => ['<script>x</script>', str_repeat('z', 200)]]);
        $this->assertStringNotContainsString('<script>', $r['message']);
        $this->assertStringNotContainsString(str_repeat('z', 60), $r['message']);
    }

    public function test_many_changes_get_the_advice(): void {
        $changes = [];
        for ($i = 0; $i <= settings_save_report::MANY; $i++) {
            $changes['s' . $i] = ['0', '1'];
        }
        $r = settings_save_report::describe($changes);
        $this->assertTrue($r['warn']);
        $this->assertStringContainsString((string) count($changes), $r['message']);
    }

    public function test_a_huge_save_lists_a_capped_number(): void {
        $changes = [];
        for ($i = 0; $i < 350; $i++) {
            $changes['s' . $i] = ['0', '1'];
        }
        $r = settings_save_report::describe($changes);
        $this->assertSame(350, $r['changed']);
        $this->assertSame(settings_save_report::LISTED, substr_count($r['message'], '<code>'));
    }

    public function test_observer_collects_only_this_plugin_and_flush_clears_it(): void {
        global $SESSION;
        $this->resetAfterTest();
        $this->setAdminUser();
        settings_save_report::assume_web(true);
        $fire = static function (string $plugin, string $name, string $old, string $new): void {
            settings_save_report::observe(\core\event\config_log_created::create([
                'objectid' => 1,
                'context' => \context_system::instance(),
                'other' => ['name' => $name, 'plugin' => $plugin, 'oldvalue' => $old, 'value' => $new],
            ]));
        };
        $fire('local_ai_course_assistant', 'provider', 'gemini', 'auto');
        $fire('local_ai_course_assistant', 'provider', 'auto', 'claude');
        $fire('mod_other', 'unrelated', 'a', 'b');
        $pending = $SESSION->local_ai_course_assistant_savedchanges;
        $this->assertSame(['provider'], array_keys($pending));
        $this->assertSame(['gemini', 'claude'], $pending['provider'], 'first old value, last new value');

        settings_save_report::flush();
        $this->assertEmpty($SESSION->local_ai_course_assistant_savedchanges ?? []);
        $notices = \core\notification::fetch();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('provider', $notices[0]->get_message());
        settings_save_report::assume_web(null);
    }

    public function test_cli_changes_are_not_collected(): void {
        global $SESSION;
        $this->resetAfterTest();
        settings_save_report::assume_web(null);
        settings_save_report::observe(\core\event\config_log_created::create([
            'objectid' => 1,
            'context' => \context_system::instance(),
            'other' => ['name' => 'provider', 'plugin' => 'local_ai_course_assistant', 'oldvalue' => 'a', 'value' => 'b'],
        ]));
        $this->assertEmpty($SESSION->local_ai_course_assistant_savedchanges ?? []);
    }
}
