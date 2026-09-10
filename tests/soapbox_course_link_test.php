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
 * Placing a Soapbox assignment on the course page.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\soapbox_course_link
 */
final class soapbox_course_link_test extends \advanced_testcase {

    /**
     * Create a course and one Soapbox assignment in it.
     *
     * @return array [courseid, assignid]
     */
    private function make_assignment(): array {
        $course = $this->getDataGenerator()->create_course();
        $assignid = soapbox_assignment_manager::create_assignment((int) $course->id, [
            'name' => 'Unit 1 speech',
            'ptype' => 'speech',
            'mode' => 'audio',
            'min_seconds' => 60,
            'max_seconds' => 300,
            'stored_attempts' => 3,
            'visible' => 1,
        ]);
        return [(int) $course->id, (int) $assignid];
    }

    /**
     * The URL stored on the activity must be root-relative.
     *
     * A local plugin cannot register a backup link encoder, so an absolute URL
     * would carry the origin site's wwwroot into every cross-site restore.
     */
    public function test_stored_url_is_root_relative(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$courseid, $assignid] = $this->make_assignment();
        $cmid = soapbox_course_link::add_to_course_page($assignid, 0);

        $cm = get_coursemodule_from_id('url', $cmid, 0, false, MUST_EXIST);
        $url = $DB->get_record('url', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertStringStartsWith('/local/ai_course_assistant/', $url->externalurl);
        $this->assertStringNotContainsString(
            $CFG->wwwroot,
            $url->externalurl,
            'An absolute URL would survive a cross-site restore pointing at the origin site.'
        );
    }

    /**
     * It must not render in an iframe, or the recorder loses camera and mic.
     */
    public function test_display_mode_does_not_embed(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/resourcelib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        [$courseid, $assignid] = $this->make_assignment();
        $cmid = soapbox_course_link::add_to_course_page($assignid, 0);
        $cm = get_coursemodule_from_id('url', $cmid, 0, false, MUST_EXIST);
        $url = $DB->get_record('url', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertNotEquals(
            RESOURCELIB_DISPLAY_EMBED,
            (int) $url->display,
            'EMBED renders an iframe with no allow="camera; microphone", so Permissions '
            . 'Policy blocks getUserMedia and recording fails silently.'
        );
        $this->assertNotEquals(
            RESOURCELIB_DISPLAY_AUTO,
            (int) $url->display,
            'AUTO detects an internal link only by matching $CFG->wwwroot, which a '
            . 'root-relative URL never satisfies, so it falls through to the download '
            . 'heuristics instead of opening the page.'
        );
        $this->assertEquals(RESOURCELIB_DISPLAY_OPEN, (int) $url->display);
    }

    /**
     * The placement must be discoverable again afterwards, so the nav can
     * de-duplicate and the list page can show its state.
     */
    public function test_placement_round_trips(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$courseid, $assignid] = $this->make_assignment();
        $this->assertNull(soapbox_course_link::find_module($courseid, $assignid));

        $cmid = soapbox_course_link::add_to_course_page($assignid, 0);

        $this->assertSame($cmid, soapbox_course_link::find_module($courseid, $assignid));
        $this->assertSame([$assignid => $cmid], soapbox_course_link::placed_in_course($courseid));
    }

    /**
     * A hand-made absolute link from before this feature must still be recognised,
     * or the teacher gets a duplicate.
     */
    public function test_legacy_absolute_url_is_recognised(): void {
        global $CFG;
        $this->resetAfterTest();

        $absolute = $CFG->wwwroot . '/local/ai_course_assistant/soapbox_present.php?id=42';
        $this->assertSame(42, soapbox_course_link::assign_id_from_url($absolute));
        $this->assertSame(42, soapbox_course_link::assign_id_from_url('/local/ai_course_assistant/soapbox_present.php?id=42'));
        $this->assertNull(soapbox_course_link::assign_id_from_url('https://example.com/other'));
    }

    /**
     * Placing twice must be refused rather than producing two activities for
     * one assignment.
     */
    public function test_second_placement_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$courseid, $assignid] = $this->make_assignment();
        soapbox_course_link::add_to_course_page($assignid, 0);

        $this->expectException(\moodle_exception::class);
        soapbox_course_link::add_to_course_page($assignid, 0);
    }

    /**
     * :manage without moodle/course:manageactivities must be refused explicitly,
     * not fail somewhere inside create_module().
     */
    public function test_manage_without_manageactivities_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$courseid, $assignid] = $this->make_assignment();

        $user = $this->getDataGenerator()->create_user();
        $context = \context_course::instance($courseid);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/ai_course_assistant:manage', CAP_ALLOW, $roleid, $context->id, true);
        assign_capability('moodle/course:manageactivities', CAP_PROHIBIT, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
        $this->setUser($user);

        $this->assertFalse(
            soapbox_course_link::can_place($courseid),
            'can_place() is what hides the control; if it says yes here the teacher gets '
            . 'an exception instead of a hidden button.'
        );
        $this->expectException(\required_capability_exception::class);
        soapbox_course_link::add_to_course_page($assignid, 0);
    }
}
