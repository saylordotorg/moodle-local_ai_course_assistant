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
 * Every course-linked learner table is discoverable by the privacy provider.
 *
 * Ten were not. flashcards, obj_att, learner_goals, streak, learner_memory,
 * profiles, struggle_signal, outreach_log, avatar_sess and email_optout each
 * hold a userid and a courseid, and none appeared in get_contexts_for_userid()
 * or get_users_in_context(). A learner who had only used flashcards in a course
 * was returned by neither, so a subject access request exported nothing of
 * theirs and a deletion request left it in place.
 *
 * It happened because both methods carried one hand-written SQL block per
 * table. Adding a table meant remembering to add two more blocks, and over
 * several releases nobody did. The fix is structural: both methods now iterate
 * one list, and this test reads db/install.xml and fails when a table is
 * missing from it.
 *
 * Reported twice. Issue #77 in June, then again as an approval blocker in
 * issue #268 (CONTRIB-10574) after the first fix proved partial.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class privacy_discovery_coverage_test extends \basic_testcase {

    /**
     * Tables reachable through another table's row, so not listed directly.
     *
     * One entry only, and it earns it. sbx_rec has no courseid of its own, so
     * it cannot be queried the way the listed tables are; it reaches a course
     * through its assignment and has its own join in both discovery methods.
     *
     * msgs and msg_ratings used to sit here on the grounds that they hang off
     * a conversation. That was wrong, and the exemption is what hid it:
     * clear_conversation() deletes convs and msgs but leaves msg_ratings, so a
     * learner who rated a reply and then pressed Clear held rows in a course
     * that neither discovery method returned. Anything added here needs a
     * behavioural test proving the orphan case is still discoverable, not an
     * argument that it ought to be. See
     * privacy_discovery_behaviour_test::test_a_rating_outliving_its_conversation_is_still_discoverable.
     */
    private const INDIRECT = [
        'local_ai_course_assistant_sbx_rec',
    ];

    /**
     * Tables carrying both a userid and a courseid, read from the schema.
     *
     * @return string[]
     */
    private function course_user_tables(): array {
        $xml = file_get_contents(__DIR__ . '/../db/install.xml');
        $this->assertNotFalse($xml, 'db/install.xml must be readable');

        $found = [];
        if (preg_match_all('/<TABLE NAME="(local_ai_course_assistant_[^"]+)".*?<\/TABLE>/s',
                $xml, $tables, PREG_SET_ORDER)) {
            foreach ($tables as $t) {
                $fields = [];
                if (preg_match_all('/<FIELD NAME="([^"]+)"/', $t[0], $f)) {
                    $fields = $f[1];
                }
                if (in_array('userid', $fields, true) && in_array('courseid', $fields, true)) {
                    $found[] = $t[1];
                }
            }
        }
        $this->assertNotEmpty($found, 'sanity: the schema should hold course-linked user tables');
        return $found;
    }

    /**
     * The provider's own list, read from source.
     *
     * @return string[]
     */
    private function declared_tables(): array {
        $src = file_get_contents(__DIR__ . '/../classes/privacy/provider.php');
        $this->assertNotFalse($src);

        $start = strpos($src, 'COURSE_USER_TABLES = [');
        $this->assertNotFalse($start, 'the provider must keep its table list in one place');
        $end = strpos($src, '];', $start);
        $block = substr($src, $start, $end - $start);

        preg_match_all("/'(local_ai_course_assistant_[a-z_]+)'/", $block, $m);
        return $m[1];
    }

    /**
     * Every course-linked learner table is in the provider's list.
     */
    public function test_every_course_linked_user_table_is_discoverable(): void {
        $declared = $this->declared_tables();
        $missing = [];

        foreach ($this->course_user_tables() as $table) {
            if (in_array($table, self::INDIRECT, true)) {
                continue;
            }
            if (!in_array($table, $declared, true)) {
                $missing[] = $table;
            }
        }

        $this->assertSame([], $missing,
            "These tables hold a learner's userid and a courseid but are not in "
                . "provider::COURSE_USER_TABLES, so a subject access request will "
                . "neither export nor delete their rows:\n  "
                . implode("\n  ", $missing)
                . "\nAdd them to the list, or to this test's INDIRECT set with a "
                . "note saying which table reaches them.");
    }

    /**
     * The list holds nothing that is not a real table.
     *
     * A typo would silently discover nothing rather than fail, which is the
     * same invisible failure in the other direction.
     */
    public function test_the_list_holds_no_unknown_tables(): void {
        $real = $this->course_user_tables();
        $unknown = [];

        foreach ($this->declared_tables() as $table) {
            if (!in_array($table, $real, true)) {
                $unknown[] = $table;
            }
        }

        $this->assertSame([], $unknown,
            'These names are in the provider list but are not tables with a '
                . 'userid and a courseid: ' . implode(', ', $unknown));
    }

    /**
     * Both discovery methods use the list rather than hand-written blocks.
     *
     * The regression this prevents is someone adding a table to the list and a
     * separate hand-written query beside it, which reintroduces the drift the
     * list exists to remove.
     */
    public function test_both_discovery_methods_iterate_the_list(): void {
        $src = file_get_contents(__DIR__ . '/../classes/privacy/provider.php');
        $this->assertNotFalse($src);

        foreach (['get_contexts_for_userid', 'get_users_in_context'] as $method) {
            $start = strpos($src, 'function ' . $method);
            $this->assertNotFalse($start, $method . ' must exist');
            $end = strpos($src, 'public static function', $start + 20);
            $body = substr($src, $start, $end - $start);

            $this->assertStringContainsString('self::COURSE_USER_TABLES', $body,
                $method . '() must iterate the shared table list rather than '
                    . 'carrying its own per-table queries');
        }
    }
}
