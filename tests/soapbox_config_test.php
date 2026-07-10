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
 * Soapbox video config: admin caps, quality presets, and the clamp (v6.8.12).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\soapbox_config
 */
final class soapbox_config_test extends \advanced_testcase {

    public function test_retention_clamp_bounds(): void {
        $this->assertSame(1, soapbox_config::clamp_retention_days(0));
        $this->assertSame(1, soapbox_config::clamp_retention_days(-5));
        $this->assertSame(7, soapbox_config::clamp_retention_days(7));
        $this->assertSame(28, soapbox_config::clamp_retention_days(28));
        $this->assertSame(28, soapbox_config::clamp_retention_days(40));
    }

    public function test_retention_days_reads_and_clamps_config(): void {
        $this->resetAfterTest();
        set_config('soapbox_retention_days', 60, 'local_ai_course_assistant');
        $this->assertSame(28, soapbox_config::retention_days());
        set_config('soapbox_retention_days', '', 'local_ai_course_assistant');
        $this->assertSame(7, soapbox_config::retention_days());
    }

    public function test_quality_falls_back_and_resolves(): void {
        $this->resetAfterTest();
        // Unknown / unset resolves to the default preset.
        set_config('soapbox_video_quality', 'nonsense', 'local_ai_course_assistant');
        $this->assertSame(480, soapbox_config::quality()['height']);
        set_config('soapbox_video_quality', 'low_360p', 'local_ai_course_assistant');
        $this->assertSame(360, soapbox_config::quality()['height']);
        // Every preset carries the fields the recorder needs.
        foreach (soapbox_config::QUALITY_PRESETS as $preset) {
            $this->assertArrayHasKey('video_kbps', $preset);
            $this->assertArrayHasKey('audio_kbps', $preset);
        }
    }

    public function test_clamp_assignment_enforces_admin_caps(): void {
        $this->resetAfterTest();
        set_config('soapbox_max_seconds', 600, 'local_ai_course_assistant');
        set_config('soapbox_max_recordings', 3, 'local_ai_course_assistant');

        $clamped = soapbox_config::clamp_assignment([
            'mode' => 'video',
            'ptype' => 'Persuasive',
            'min_seconds' => 900,   // above the 600 cap
            'max_seconds' => 1200,  // above the 600 cap
            'max_attempts' => 0,
            'stored_attempts' => 9, // above the 3 cap
        ]);

        $this->assertSame(600, $clamped['max_seconds']);
        $this->assertSame(600, $clamped['min_seconds']); // min pulled down to max
        $this->assertSame(3, $clamped['stored_attempts']);
        $this->assertSame('persuasive', $clamped['ptype']); // lowercased
        $this->assertSame(0, $clamped['max_attempts']);     // unlimited preserved
    }

    public function test_clamp_assignment_defaults_invalid_mode_and_min_over_max(): void {
        $this->resetAfterTest();
        set_config('soapbox_max_seconds', 720, 'local_ai_course_assistant');

        $clamped = soapbox_config::clamp_assignment([
            'mode' => 'hologram',
            'min_seconds' => 400,
            'max_seconds' => 300, // min > max
        ]);

        $this->assertSame('video', $clamped['mode']);
        $this->assertSame(300, $clamped['max_seconds']);
        $this->assertSame(300, $clamped['min_seconds']);
        $this->assertSame('informative', $clamped['ptype']); // default when absent
    }

    public function test_effective_recording_cap(): void {
        $this->resetAfterTest();
        set_config('soapbox_max_recordings', 3, 'local_ai_course_assistant');
        $this->assertSame(3, soapbox_config::effective_recording_cap(0));  // unlimited -> admin cap
        $this->assertSame(2, soapbox_config::effective_recording_cap(2));  // below cap -> honored
        $this->assertSame(3, soapbox_config::effective_recording_cap(10)); // above cap -> clamped
    }
}
