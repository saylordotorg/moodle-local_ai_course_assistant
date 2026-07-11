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
 * Slide-vision gating, sampling, and provider wiring (v6.8.31, Phase 2 issue 15).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\soapbox_slide_vision
 */
final class soapbox_slide_vision_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Build an assignment-like object with the two relevant flags.
     *
     * @param int $slides
     * @param int $vision
     * @return object
     */
    private function assign(int $slides, int $vision): object {
        return (object) ['slides_enabled' => $slides, 'slide_vision' => $vision, 'ptype' => 'informative'];
    }

    public function test_is_enabled_requires_site_and_assignment_and_slides(): void {
        $on = $this->assign(1, 1);

        // Site master off: never runs, even with both assignment flags on.
        set_config('soapbox_slide_vision', 0, 'local_ai_course_assistant');
        $this->assertFalse(soapbox_slide_vision::is_enabled($on));

        // Site master on, but each assignment precondition must also hold.
        set_config('soapbox_slide_vision', 1, 'local_ai_course_assistant');
        $this->assertTrue(soapbox_slide_vision::is_enabled($on));
        $this->assertFalse(soapbox_slide_vision::is_enabled($this->assign(1, 0)));
        $this->assertFalse(soapbox_slide_vision::is_enabled($this->assign(0, 1)));
    }

    public function test_sample_keeps_all_when_small_and_bounds_when_large(): void {
        $small = array_map(fn($i) => "data:image/png;base64,S$i", range(1, 5));
        $this->assertSame($small, soapbox_slide_vision::sample($small));

        $large = array_map(fn($i) => "data:image/png;base64,L$i", range(0, 39));
        $picked = soapbox_slide_vision::sample($large);
        $this->assertCount(soapbox_slide_vision::MAX_SLIDES, $picked);
        // First and last slides are always represented, and order is preserved.
        $this->assertSame($large[0], $picked[0]);
        $this->assertSame($large[39], $picked[count($picked) - 1]);
        $this->assertSame($picked, array_values(array_unique($picked)));
    }

    public function test_design_note_empty_without_images(): void {
        set_config('soapbox_slide_vision', 1, 'local_ai_course_assistant');
        $this->assertSame('', soapbox_slide_vision::design_note([], 'informative', 0));
    }

    public function test_design_note_calls_vision_provider_with_images(): void {
        // Route the pass through the stub provider and assert the slide images
        // were handed to it as image_datauris, and a note comes back.
        set_config('soapbox_vision_provider', 'stub', 'local_ai_course_assistant');
        set_config('soapbox_vision_model', 'stub', 'local_ai_course_assistant');
        provider\stub_provider::reset();

        $images = ['data:image/png;base64,AAAA', 'data:image/png;base64,BBBB'];
        $note = soapbox_slide_vision::design_note($images, 'persuasive', 0);

        $this->assertNotSame('', $note);
        $calls = provider\stub_provider::$calls;
        $this->assertNotEmpty($calls);
        $last = end($calls);
        $this->assertSame($images, $last['options']['image_datauris'] ?? null);
    }
}
