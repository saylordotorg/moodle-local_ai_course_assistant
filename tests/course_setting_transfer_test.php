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
 * The per-course backup allowlist.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\course_setting_transfer
 */
final class course_setting_transfer_test extends \basic_testcase {

    public function test_ordinary_overrides_travel(): void {
        $this->assertTrue(course_setting_transfer::is_transferable('socratic_mode_course_42'));
        $this->assertTrue(course_setting_transfer::is_transferable('rag_enabled_course_7'));
        $this->assertTrue(course_setting_transfer::is_transferable('starters_course_1319'));
    }

    public function test_anything_off_the_allowlist_is_refused(): void {
        // The archive is user-supplied and restore capability does not imply
        // site:config, so an unknown key must not be written back whatever the
        // file claims.
        $this->assertFalse(course_setting_transfer::is_transferable('apikey_course_42'));
        $this->assertFalse(course_setting_transfer::is_transferable('zendesk_key_course_42'));
        $this->assertFalse(course_setting_transfer::is_transferable('spend_cap_site'));
        $this->assertFalse(course_setting_transfer::is_transferable('emergency_chat_disabled'));
        // A denylist of credential-looking substrings missed this one: it
        // contains "key" but not "apikey".
        $this->assertFalse(course_setting_transfer::is_transferable('some_key_course_9'));
    }

    public function test_only_a_course_id_may_follow_the_prefix(): void {
        $this->assertFalse(course_setting_transfer::is_transferable('rag_enabled_course_'));
        $this->assertFalse(course_setting_transfer::is_transferable('rag_enabled_course_7_apikey'));
        $this->assertFalse(course_setting_transfer::is_transferable('rag_enabled_course_abc'));
    }

    /**
     * Every per-course setting the code reads must have been decided about.
     *
     * This is the durable half of the allowlist: it fails when someone adds a
     * per-course setting and does not say whether it should travel, which is the
     * failure mode a denylist has no way to catch.
     */
    public function test_every_per_course_setting_in_the_codebase_is_accounted_for(): void {
        global $CFG;
        $root = $CFG->dirroot . '/local/ai_course_assistant';
        $found = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($rii as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }
            if (str_contains($file->getPathname(), '/tests/')) {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            if (preg_match_all("/'([a-z0-9_]+_course_)'\s*\./", $src, $m)) {
                foreach ($m[1] as $prefix) {
                    $found[$prefix] = true;
                }
            }
        }
        // Built from a variable ("<feature>_enabled_course_"); its members are
        // enumerated individually in the allowlist.
        unset($found['_enabled_course_']);

        $allowed = array_flip(course_setting_transfer::TRANSFERABLE_PREFIXES);
        $undecided = array_values(array_diff_key($found, $allowed));

        $this->assertSame(
            [],
            $undecided,
            "Per-course setting prefixes are read in the plugin but are not listed in "
                . "course_setting_transfer::TRANSFERABLE_PREFIXES. Add them if they should "
                . "survive a course copy, or add a comment there saying why they must not: "
                . implode(', ', $undecided)
        );
    }
}
