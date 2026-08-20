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
 * The admin settings page must rebrand completely (v7.0.0).
 *
 * v6.8.0 white-labelled the product by tokenising every brand-bearing
 * get_string() call. Settings whose title or description is a hardcoded PHP
 * literal were never in scope, so eight of them kept saying "SOLA" or "Saylor"
 * to an administrator at an institution that had rebranded — including one that
 * told every installer what "Saylor sites" must configure.
 *
 * The existing branding_test covers lang strings. This covers what an admin
 * actually reads on the settings page, which is where the literals were.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\branding
 */
final class settings_branding_test extends \advanced_testcase {
    public function test_settings_page_carries_no_default_brand_after_rebranding(): void {
        $this->resetAfterTest();
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->setAdminUser();

        // Rebrand all four brand settings. Overriding only some of them is how
        // an earlier version of this check produced false positives: strings
        // using [[unishort]] still resolved to the Saylor default.
        foreach ([
            'short_name'             => 'ACMEBRAND',
            'display_name'           => 'Acme Learning Assistant',
            'institution_name'       => 'Acme College',
            'institution_short_name' => 'AcmeInst',
        ] as $key => $value) {
            set_config($key, $value, 'local_ai_course_assistant');
        }

        $page = admin_get_root(true, true)->locate('local_ai_course_assistant_general');
        $this->assertInstanceOf(\admin_settingpage::class, $page, 'Settings page must be reachable.');

        $leaks = [];
        $rawtokens = [];
        $scanned = 0;
        foreach ((array) $page->settings as $setting) {
            $text = (string) $setting->visiblename . ' ' . (string) $setting->description;
            $scanned++;
            if (strpos($text, 'SOLA') !== false || strpos($text, 'Saylor') !== false) {
                $leaks[] = $setting->get_full_name();
            }
            // A token that reached the page unresolved means the string was
            // tokenised but read with get_string() instead of branding::str().
            if (preg_match('/\[\[[a-z]+\]\]/', $text)) {
                $rawtokens[] = $setting->get_full_name();
            }
        }

        $this->assertGreaterThan(
            100,
            $scanned,
            'Scanned too few settings — the page did not build, so this guard proves nothing.'
        );
        $this->assertSame([], $leaks, 'Default brand still visible after rebranding: ' . implode(', ', $leaks));
        $this->assertSame([], $rawtokens, 'Unresolved brand tokens rendered to admins: ' . implode(', ', $rawtokens));
    }
}
