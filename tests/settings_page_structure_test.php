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

use local_ai_course_assistant\tests\settings_tree;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/settings_tree.php');

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

        // 7.10.0: five of the pages sit in the nested Advanced category.
        $category = settings_tree::category();

        $pages = 0;
        $problems = [];
        foreach (settings_tree::pages($category) as $page) {
            $pages++;
            $list = array_values((array) $page->settings);
            $this->assertNotEmpty($list, "{$page->name} has no settings");

            if (end($list) instanceof \admin_setting_heading) {
                $problems[] = "{$page->name} ends with an empty heading: "
                    . strip_tags(end($list)->visiblename);
            }

            // Essentials leads with the version banner by design. Every other page
            // must reach a heading before its first real setting; the navigation
            // notice (nav_*) is not a setting.
            if ($page->name === settings_layout::page_id('essentials')) {
                continue;
            }
            foreach ($list as $setting) {
                if (str_starts_with($setting->name, 'nav_')) {
                    continue;
                }
                if (!$setting instanceof \admin_setting_heading) {
                    $problems[] = "{$page->name} opens on {$setting->name} with no heading above it";
                }
                break;
            }
        }

        $this->assertGreaterThanOrEqual(10, $pages, 'expected every settings page');
        $this->assertSame([], $problems, "Settings pages strand a heading:\n  " . implode("\n  ", $problems));
    }

    /**
     * settings.php builds no table-of-contents anchor and titles its staging pages with their own ids.
     *
     * 7.10.0 stopped rendering the in-page table of contents and the section
     * anchors; 7.12.0 deleted them and the strings behind them. A staging page is
     * never registered, so its title is never shown and must not cost a lang string.
     */
    public function test_settings_php_has_no_section_anchors_and_no_lang_titled_staging_pages(): void {
        $src = file_get_contents(__DIR__ . '/../settings.php');
        $this->assertStringNotContainsString('$sectionanchor', $src);
        $this->assertStringNotContainsString('$tocstyle', $src);
        $this->assertStringNotContainsString('sola-section-anchor', $src);
        $this->assertSame(0, preg_match("~'local_ai_course_assistant/sec_[a-z_]*anchor'~", $src),
            'a sec_*_anchor setting is still built');

        $this->assertSame(11, preg_match_all("~new\\s+admin_settingpage\\(\\s*'local_ai_course_assistant_([a-z]+)',\\s*"
            . "'([a-z]+)'\\s*\\)~", $src, $m), 'the eleven staging pages');
        foreach ($m[1] as $i => $id) {
            $this->assertSame($id, $m[2][$i], 'a staging page is titled with its own id');
        }
        $this->assertSame(['general', 'ai', 'content', 'rerank', 'extraction', 'operations', 'safety',
            'engagement', 'feedback', 'branding', 'integrations'], $m[1]);
    }
}
