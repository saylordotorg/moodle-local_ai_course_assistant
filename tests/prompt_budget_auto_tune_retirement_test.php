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
 * 7.12.0 retires the deprecated prompt_budget_auto_tune setting and its daily task.
 *
 * What stays is pinned too: the manual "Apply recommendation" button on the prompt metrics page and the
 * logger method behind it, which are the supported way to tune the budget by hand.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\prompt_metrics_logger
 */
final class prompt_budget_auto_tune_retirement_test extends \advanced_testcase {
    /** @var string The plugin component, which is also its config_plugins name. */
    private const PLUGIN = 'local_ai_course_assistant';

    /** @var int The build just before the retirement, the one a 7.11.0 site upgrades from. */
    private const BEFORE = 2026101802;

    /** @var int The build that retires the setting. */
    private const RETIRING = 2026101803;

    /**
     * Run the plugin's real upgrade function as a site on the given build would.
     *
     * upgrade_plugin_savepoint() refuses to move a version backwards, and phpunit's database holds the build the
     * tests were initialised at, so the stored version is first set to the one the site is upgrading from.
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

    public function test_the_upgrade_step_removes_a_stored_flag(): void {
        $this->resetAfterTest();
        set_config('prompt_budget_auto_tune', '1', self::PLUGIN);
        $this->assertSame('1', get_config(self::PLUGIN, 'prompt_budget_auto_tune'));

        $this->upgrade_from(self::BEFORE);

        $this->assertFalse(get_config(self::PLUGIN, 'prompt_budget_auto_tune'), 'the stored flag is gone');
        $this->assertSame((string) self::RETIRING, get_config(self::PLUGIN, 'version'), 'the savepoint was reached');
    }

    public function test_a_site_with_the_flag_on_and_a_fixed_budget_upgrades_cleanly_and_keeps_its_budget(): void {
        $this->resetAfterTest();
        // The one configuration the old task ever acted on: flag on, budget mode fixed, a last tuned value.
        set_config('prompt_budget_auto_tune', '1', self::PLUGIN);
        set_config('prompt_budget_mode', 'fixed', self::PLUGIN);
        set_config('prompt_budget_chars', '18500', self::PLUGIN);

        $this->upgrade_from(self::BEFORE);

        $this->assertFalse(get_config(self::PLUGIN, 'prompt_budget_auto_tune'));
        $this->assertSame('fixed', get_config(self::PLUGIN, 'prompt_budget_mode'), 'the mode is the admin\'s choice');
        $this->assertSame('18500', get_config(self::PLUGIN, 'prompt_budget_chars'), 'the budget keeps its last tuned value');
        $this->assertDebuggingNotCalled();
    }

    public function test_the_upgrade_step_is_harmless_when_the_flag_was_never_stored(): void {
        $this->resetAfterTest();
        // A database installed before 7.12.0 holds the setting's default; a site that never saved it holds nothing.
        unset_config('prompt_budget_auto_tune', self::PLUGIN);
        $this->assertFalse(get_config(self::PLUGIN, 'prompt_budget_auto_tune'));
        set_config('prompt_budget_chars', '24000', self::PLUGIN);

        $this->upgrade_from(self::BEFORE);

        $this->assertFalse(get_config(self::PLUGIN, 'prompt_budget_auto_tune'));
        $this->assertSame('24000', get_config(self::PLUGIN, 'prompt_budget_chars'));
    }

    public function test_the_upgrade_step_touches_nothing_else_and_writes_no_config_log_row(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('prompt_budget_auto_tune', '1', self::PLUGIN);
        set_config('prompt_verbosity', 'verbose', self::PLUGIN);
        set_config('prompt_metrics_enabled', '0', self::PLUGIN);
        $logged = $DB->count_records('config_log', ['plugin' => self::PLUGIN, 'name' => 'prompt_budget_auto_tune']);

        $this->upgrade_from(self::BEFORE);

        $this->assertSame('verbose', get_config(self::PLUGIN, 'prompt_verbosity'));
        $this->assertSame('0', get_config(self::PLUGIN, 'prompt_metrics_enabled'));
        $this->assertSame(
            $logged,
            $DB->count_records('config_log', ['plugin' => self::PLUGIN, 'name' => 'prompt_budget_auto_tune']),
            'unset_config() is called without its log argument: the upgrade is not an admin edit'
        );
    }

    public function test_the_step_is_in_the_upgrade_file_for_the_retiring_build(): void {
        // A tripwire for the shape of the step, next to the behavioural tests above: its own savepoint, and the
        // plain two-argument unset_config() that the plan's constraint asks for.
        global $CFG;
        $src = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/db/upgrade.php');
        $this->assertNotFalse($src);
        $start = strpos($src, 'oldversion < ' . self::RETIRING);
        $this->assertNotFalse($start, 'db/upgrade.php has no step for ' . self::RETIRING);
        $block = substr($src, $start);
        $block = substr($block, 0, (int) strpos($block, 'upgrade_plugin_savepoint'));
        $this->assertMatchesRegularExpression(
            "~unset_config\(\s*'prompt_budget_auto_tune'\s*,\s*'local_ai_course_assistant'\s*\)~",
            $block
        );
    }

    public function test_the_daily_task_is_no_longer_scheduled(): void {
        global $DB;
        $this->resetAfterTest();
        $classname = '\\local_ai_course_assistant\\task\\auto_tune_prompt_budget';

        // Core runs this after a plugin's upgrade steps, and it deletes every task db/tasks.php no longer lists.
        \core\task\manager::reset_scheduled_tasks_for_component(self::PLUGIN);

        $this->assertFalse($DB->record_exists('task_scheduled', ['classname' => $classname]), 'the task row is gone');
        $this->assertFalse(class_exists('local_ai_course_assistant\\task\\auto_tune_prompt_budget'), 'the class is gone');
        $this->assertFileDoesNotExist(__DIR__ . '/../classes/task/auto_tune_prompt_budget.php');
        $this->assertStringNotContainsString(
            'auto_tune_prompt_budget',
            file_get_contents(__DIR__ . '/../db/tasks.php'),
            'db/tasks.php no longer lists the task'
        );
    }

    public function test_the_manual_recommendation_still_applies_by_hand(): void {
        $this->resetAfterTest();
        $this->assertTrue(method_exists(prompt_metrics_logger::class, 'apply_recommendation'));
        $page = file_get_contents(__DIR__ . '/../prompt_metrics.php');
        $this->assertStringContainsString('prompt_metrics_logger::apply_recommendation()', $page);
        $this->assertStringContainsString("'apply_url'", $page, 'the prompt metrics page keeps its Apply recommendation button');
        // With no samples there is nothing to recommend, and calling it by hand must not throw or write.
        set_config('prompt_budget_chars', '16000', self::PLUGIN);
        $result = prompt_metrics_logger::apply_recommendation();
        $this->assertFalse($result['applied']);
        $this->assertSame('16000', get_config(self::PLUGIN, 'prompt_budget_chars'));
    }
}
