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
 * Clearing the remote config URL in the admin form takes effect immediately.
 *
 * get() caches for an hour. The setting had no set_updatedcallback, so an
 * administrator who cleared the field to switch remote configuration OFF kept
 * getting remote values, including the system prompt their learners see, until
 * the TTL expired. Issue #282.
 *
 * The 2026100100 upgrade step purged the cache for exactly this reason, so the
 * upgrade path was correct and the admin-form path was not. Both do it now.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\remote_config_manager::invalidate
 */
final class remote_config_invalidation_test extends \advanced_testcase {

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \cache::make('local_ai_course_assistant', 'remoteconfig')->purge();
    }

    /**
     * The callback empties the cache, so the next get() re-reads the setting.
     *
     * Seeds the cache the way a real fetch would, then runs the callback and
     * asserts a subsequent get() reflects the cleared setting rather than the
     * stale remote values.
     */
    public function test_the_callback_drops_a_cached_remote_config(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/ai_course_assistant/lib.php');

        $cache = \cache::make('local_ai_course_assistant', 'remoteconfig');
        $cache->set('config', ['model_default' => 'from-the-remote-file']);

        $this->assertSame('from-the-remote-file',
            remote_config_manager::get_value('model_default'),
            'sanity: the cached value should be in play before the callback runs');

        // The administrator clears the field and saves.
        set_config('remoteconfigurl', '', 'local_ai_course_assistant');
        local_ai_course_assistant_invalidate_remote_config();

        $this->assertSame([], remote_config_manager::get(),
            'After clearing the URL and saving, remote configuration is still being '
                . 'served from cache. An administrator who switched this off has not '
                . 'actually switched it off.');
    }

    /**
     * The setting is wired to the callback.
     *
     * Behavioural tests cannot save an admin form, so this pins the wiring that
     * makes the test above describe what really happens. Without it the
     * callback would be correct and never called, which is the original defect.
     */
    public function test_the_setting_declares_the_callback(): void {
        global $CFG;
        $src = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/settings.php');
        $this->assertNotFalse($src);

        $start = strpos($src, "'local_ai_course_assistant/remoteconfigurl'");
        $this->assertNotFalse($start, 'the remoteconfigurl setting must exist');

        // Look at the block around the setting, not the whole file.
        $block = substr($src, max(0, $start - 600), 1600);

        $this->assertStringContainsString('set_updatedcallback', $block,
            'remoteconfigurl has no set_updatedcallback, so clearing it in the admin '
                . 'form leaves up to an hour of cached remote configuration in place.');
        $this->assertStringContainsString(
            'local_ai_course_assistant_invalidate_remote_config', $block,
            'the callback name does not match the function in lib.php');
    }

    /**
     * The callback function exists under the name the setting gives.
     *
     * set_updatedcallback() takes a string, so a rename in one place and not
     * the other fails silently at save time with no error anywhere.
     */
    public function test_the_named_callback_function_exists(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/ai_course_assistant/lib.php');

        $this->assertTrue(
            function_exists('local_ai_course_assistant_invalidate_remote_config'),
            'settings.php names a callback that does not exist, so saving the setting '
                . 'silently does nothing.');
    }
}
