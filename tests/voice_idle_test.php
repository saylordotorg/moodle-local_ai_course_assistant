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

use local_ai_course_assistant\voice\gemini_live;
use local_ai_course_assistant\voice\voice_idle;

/**
 * Tests for the voice silence limits (8.3.1): the defaults, the bounds, and the upgrade from the old Live default.
 *
 * @package    local_ai_course_assistant
 * @category   test
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\voice\voice_idle
 * @covers     \local_ai_course_assistant\voice\gemini_live
 */
final class voice_idle_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_defaults_are_five_seconds_for_live_and_ten_for_every_other_mode(): void {
        $this->assertSame(5, gemini_live::idle_seconds());
        $this->assertSame(10, voice_idle::seconds());
    }

    public function test_the_limits_are_clamped_and_junk_reads_as_the_default(): void {
        set_config('gemini_live_idle_seconds', 1, 'local_ai_course_assistant');
        $this->assertSame(gemini_live::IDLE_RANGE[0], gemini_live::idle_seconds());
        set_config('gemini_live_idle_seconds', 99999, 'local_ai_course_assistant');
        $this->assertSame(gemini_live::IDLE_RANGE[1], gemini_live::idle_seconds());
        set_config('voice_idle_seconds', 2, 'local_ai_course_assistant');
        $this->assertSame(voice_idle::RANGE[0], voice_idle::seconds());
        set_config('voice_idle_seconds', 99999, 'local_ai_course_assistant');
        $this->assertSame(voice_idle::RANGE[1], voice_idle::seconds());
        set_config('voice_idle_seconds', 'abc', 'local_ai_course_assistant');
        $this->assertSame(10, voice_idle::seconds());
        set_config('voice_idle_seconds', '', 'local_ai_course_assistant');
        $this->assertSame(10, voice_idle::seconds());
    }

    public function test_the_bounds_allow_the_defaults(): void {
        $this->assertGreaterThanOrEqual(gemini_live::IDLE_RANGE[0], gemini_live::DEFAULT_IDLE_SECONDS);
        $this->assertLessThanOrEqual(gemini_live::IDLE_RANGE[1], gemini_live::DEFAULT_IDLE_SECONDS);
        $this->assertGreaterThanOrEqual(voice_idle::RANGE[0], voice_idle::DEFAULT_SECONDS);
        $this->assertLessThanOrEqual(voice_idle::RANGE[1], voice_idle::DEFAULT_SECONDS);
    }

    public function test_upgrade_moves_exactly_the_old_default_and_keeps_a_chosen_value(): void {
        set_config('gemini_live_idle_seconds', '90', 'local_ai_course_assistant');
        $this->assertTrue(voice_idle::migrate_old_gemini_live_default());
        $this->assertSame(5, gemini_live::idle_seconds());

        foreach (['30', '120', '15', '5'] as $chosen) {
            set_config('gemini_live_idle_seconds', $chosen, 'local_ai_course_assistant');
            $this->assertFalse(voice_idle::migrate_old_gemini_live_default(), "$chosen is a choice and stays");
            $this->assertSame((int) $chosen, gemini_live::idle_seconds());
        }
    }

    public function test_upgrade_does_nothing_when_the_setting_was_never_saved(): void {
        unset_config('gemini_live_idle_seconds', 'local_ai_course_assistant');
        $this->assertFalse(voice_idle::migrate_old_gemini_live_default());
        $this->assertFalse(get_config('local_ai_course_assistant', 'gemini_live_idle_seconds'));
        $this->assertSame(5, gemini_live::idle_seconds());
    }

    public function test_upgrade_twice_changes_nothing_the_second_time(): void {
        set_config('gemini_live_idle_seconds', '90', 'local_ai_course_assistant');
        $this->assertTrue(voice_idle::migrate_old_gemini_live_default());
        $this->assertFalse(voice_idle::migrate_old_gemini_live_default());
    }

    public function test_the_upgrade_step_and_the_setting_are_wired(): void {
        global $CFG;
        $upgrade = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/db/upgrade.php');
        $this->assertStringContainsString('$oldversion < 2026112200', $upgrade);
        $this->assertStringContainsString('migrate_old_gemini_live_default()', $upgrade);

        $version = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/version.php');
        $this->assertStringContainsString('2026112200', $version, 'the step runs at this build');

        $settings = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/settings.php');
        $this->assertStringContainsString("'local_ai_course_assistant/voice_idle_seconds'", $settings);
        $strings = get_string_manager();
        foreach (['settings:voice_idle_seconds', 'settings:voice_idle_seconds_desc', 'voice:idle_stopped',
                'voice:resume', 'voice:resume_aria'] as $id) {
            $this->assertTrue($strings->string_exists($id, 'local_ai_course_assistant'), $id);
        }
    }
}
