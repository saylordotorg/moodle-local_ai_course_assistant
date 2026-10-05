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
 * Every settings page opens with its own heading and none ends on an empty one.
 *
 * Issue #292 (v7.6.4) split one settings page into eleven, by inserting page
 * breaks at section boundaries. Four breaks landed one heading too late: the
 * heading stayed as the last thing on the previous page, with nothing under
 * it, and the next page opened on that section's settings with no heading.
 * Twenty-seven settings read as unlabelled, under page titles that did not
 * describe them, and the tests (which check each page's dependency budget, not
 * its layout) could not see it. Found by listing each page's sections while
 * updating the admin documentation.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class settings_page_structure_test extends \advanced_testcase {

    /**
     * No page ends with a heading, and every page but the first opens with one.
     */
    public function test_no_page_strands_a_heading(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $category = \admin_get_root(true, true)->locate('local_ai_course_assistant');
        $this->assertNotEmpty($category);

        $pages = 0;
        $problems = [];
        foreach ($category->children as $page) {
            if (!$page instanceof \admin_settingpage) {
                continue;
            }
            $pages++;
            $list = array_values((array) $page->settings);
            $this->assertNotEmpty($list, "{$page->name} has no settings");

            if (end($list) instanceof \admin_setting_heading) {
                $problems[] = "{$page->name} ends with an empty heading: "
                    . strip_tags(end($list)->visiblename);
            }

            // The general page leads with the enable switch and version banner by
            // design. Every other page must reach a heading before its first real
            // setting; the TOC anchors are not settings.
            if ($page->name === 'local_ai_course_assistant_general') {
                continue;
            }
            foreach ($list as $setting) {
                if (str_ends_with($setting->name, '_anchor')) {
                    continue;
                }
                if (!$setting instanceof \admin_setting_heading) {
                    $problems[] = "{$page->name} opens on {$setting->name} with no heading above it";
                }
                break;
            }
        }

        $this->assertGreaterThanOrEqual(11, $pages, 'expected every settings page');
        $this->assertSame([], $problems, "Settings pages strand a heading:\n  " . implode("\n  ", $problems));
    }
}
