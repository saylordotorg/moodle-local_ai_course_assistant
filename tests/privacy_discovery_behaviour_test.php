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
 * Privacy discovery is proved by running it, not by reading the source.
 *
 * privacy_discovery_coverage_test reads db/install.xml and the provider's
 * table list and compares the two. That is a useful guard and it is not
 * enough: it passed while a learner was undiscoverable.
 *
 * Two holes it could not see. It never ran the generated SQL, so a column
 * present in install.xml but dropped by a later upgrade step would still look
 * covered. And it trusted its own INDIRECT exemption list, which claimed
 * msgs and msg_ratings were reachable through convs. They were not:
 * clear_conversation() deletes convs and msgs and leaves msg_ratings, so a
 * learner who rated a reply and then pressed the trash button held rows in a
 * course that neither discovery method returned. Their ratings were never
 * exported on a subject access request and never deleted on an erasure
 * request.
 *
 * So these tests insert real rows and call the real methods. The per-table
 * test fails if any listed table is misspelled, loses a column, or stops
 * being queried. The ratings test reproduces the orphan directly.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\privacy\provider::get_contexts_for_userid
 * @covers     \local_ai_course_assistant\privacy\provider::get_users_in_context
 */
final class privacy_discovery_behaviour_test extends \advanced_testcase {

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * The provider's own list, read through reflection rather than regex.
     *
     * @return string[]
     */
    private function declared_tables(): array {
        $ref = new \ReflectionClass(\local_ai_course_assistant\privacy\provider::class);
        $tables = $ref->getConstant('COURSE_USER_TABLES');
        $this->assertIsArray($tables, 'COURSE_USER_TABLES must exist and be an array');
        $this->assertNotEmpty($tables);
        return $tables;
    }

    /**
     * Context ids a learner is discoverable in, as ints.
     *
     * contextlist yields whatever the database driver returned, which is
     * strings on some drivers, so a strict comparison against an int context
     * id fails for the wrong reason.
     *
     * @param int $userid
     * @return int[]
     */
    private function discovered_context_ids(int $userid): array {
        return array_map('intval',
            privacy\provider::get_contexts_for_userid($userid)->get_contextids());
    }

    /** @var int Counter making each generated column value unique. */
    private static $filler = 0;

    /**
     * Insert one minimal row, filling whatever the schema insists on.
     *
     * Every table in the list takes a different set of not-null columns, and
     * hand-writing 20 row shapes would rot the moment one changed. Reading
     * the columns from the live database instead means this test exercises
     * the schema as upgraded, which is the thing install.xml cannot tell us.
     *
     * @param string $table
     * @param int $userid
     * @param int $courseid
     */
    private function insert_minimal_row(string $table, int $userid, int $courseid): void {
        global $DB;

        $record = [];
        foreach ($DB->get_columns($table) as $name => $column) {
            if ($name === 'id') {
                continue;
            }
            if ($name === 'userid') {
                $record[$name] = $userid;
                continue;
            }
            if ($name === 'courseid') {
                $record[$name] = $courseid;
                continue;
            }
            if (empty($column->not_null)) {
                continue;
            }

            // Every not-null column gets a value, and every value is distinct.
            //
            // Two earlier versions of this were wrong in ways worth recording.
            // Filling with a constant 0 broke as soon as a second row was
            // inserted, because msg_ratings has a unique index on messageid.
            // Skipping columns that report a default then broke on reminders,
            // whose unique unsubscribe_token is a NOTNULL char that MySQL
            // reports as defaulting to the empty string, so two rows both got
            // '' and collided.
            //
            // Both surfaced as a dml_write_exception, which phpunit reports as
            // an ERROR, which the mutation harness counted as the test catching
            // its defect. A test broken in this way produces a false CAUGHT and
            // looks like proof.
            $next = ++self::$filler;
            if ($column->meta_type === 'I' || $column->meta_type === 'N'
                    || $column->meta_type === 'F') {
                // Narrow columns cannot hold the counter. outreach_log.dryrun is
                // one digit, and a value of 141 is rejected outright under
                // strict mode. Wrap into the column's range instead of clamping,
                // so a narrow column still varies between rows rather than
                // becoming a constant that could collide in a unique index.
                $digits = (int) ($column->max_length ?? 0);
                $cap = ($digits >= 1 && $digits <= 9) ? ((int) str_repeat('9', $digits)) : PHP_INT_MAX;
                $record[$name] = $cap === PHP_INT_MAX ? $next : ($next % ($cap + 1));
            } else {
                $value = 'x' . $next;
                $max = (int) ($column->max_length ?? 0);
                $record[$name] = ($max > 0 && strlen($value) > $max)
                    ? substr($value, 0, $max)
                    : $value;
            }
        }

        $this->assertArrayHasKey('userid', $record, $table . ' must have a userid column');
        $this->assertArrayHasKey('courseid', $record, $table . ' must have a courseid column');

        $DB->insert_record($table, (object) $record);
    }

    /**
     * A learner holding a row in any one listed table is discoverable.
     *
     * Each table gets its own fresh course and its own fresh user, so a pass
     * cannot borrow discovery from a row another table left behind.
     */
    public function test_a_row_in_any_listed_table_makes_the_learner_discoverable(): void {
        $undiscoverable = [];
        $uncounted = [];
        $overreaching = [];
        $overcounted = [];

        foreach ($this->declared_tables() as $table) {
            $course = $this->getDataGenerator()->create_course();
            $user = $this->getDataGenerator()->create_user();
            // The negative control, and it has to hold a row of its own in a
            // DIFFERENT course to be worth anything.
            //
            // A bystander with no rows anywhere only tests half of what it looks
            // like it tests. get_users_in_context() runs SELECT DISTINCT userid
            // FROM {table} WHERE courseid = :courseid, and a query over a table
            // cannot return a userid that is not in that table. However wrong
            // the course predicate was, a learner holding nothing could never be
            // returned, so that assertion could not fail. Giving them a row
            // elsewhere makes them a learner the query could wrongly reach.
            //
            // What each half catches once the row exists: the contexts assertion
            // catches a lost userid filter, which would return this course for
            // everybody; the userlist assertion catches a lost or wrong courseid
            // filter, which would hand a course-level deletion a learner whose
            // data lives in another course entirely. Discovery that returns too
            // much is its own privacy defect, not merely an inefficiency.
            $bystander = $this->getDataGenerator()->create_user();
            $elsewhere = $this->getDataGenerator()->create_course();
            $context = \context_course::instance($course->id);

            $this->insert_minimal_row($table, (int) $user->id, (int) $course->id);
            $this->insert_minimal_row($table, (int) $bystander->id, (int) $elsewhere->id);

            if (!in_array((int) $context->id, $this->discovered_context_ids((int) $user->id), true)) {
                $undiscoverable[] = $table;
            }
            if (in_array((int) $context->id, $this->discovered_context_ids((int) $bystander->id), true)) {
                $overreaching[] = $table;
            }

            $userlist = new \core_privacy\local\request\userlist($context, 'local_ai_course_assistant');
            privacy\provider::get_users_in_context($userlist);
            $found = array_map('intval', $userlist->get_userids());
            if (!in_array((int) $user->id, $found, true)) {
                $uncounted[] = $table;
            }
            if (in_array((int) $bystander->id, $found, true)) {
                $overcounted[] = $table;
            }
        }

        $this->assertSame([], $undiscoverable,
            "A learner holding a row in these tables and nothing else is not returned by "
                . "get_contexts_for_userid(), so a subject access request exports nothing of "
                . "theirs and an erasure request leaves the rows in place:\n  "
                . implode("\n  ", $undiscoverable));

        $this->assertSame([], $uncounted,
            "get_users_in_context() does not return a learner holding a row in these tables, "
                . "so a course-level deletion skips them:\n  " . implode("\n  ", $uncounted));

        $this->assertSame([], $overreaching,
            "get_contexts_for_userid() returned this course for a learner who holds no row "
                . "in it at all, so the query behind these tables is not filtering on userid. "
                . "One learner's course would appear in another learner's data request:\n  "
                . implode("\n  ", $overreaching));

        $this->assertSame([], $overcounted,
            "get_users_in_context() listed a learner whose only row in these tables belongs "
                . "to a different course, so the course predicate is not filtering. A "
                . "course-level deletion would delete another course's data:\n  "
                . implode("\n  ", $overcounted));
    }

    /**
     * A rating outliving its conversation is still discoverable.
     *
     * This is the orphan the source-reading test could not see. The provider
     * exempted msg_ratings on the grounds that it hangs off a conversation,
     * and clear_conversation() removed the conversation and left the rating,
     * so the learner held rows in a course that neither discovery method
     * returned.
     *
     * clear_conversation() now deletes the ratings too, which is the right
     * retention behaviour and is pinned by the next test. It is deliberately
     * not used here. Testing the provider through one caller would mean this
     * test passes for as long as that caller keeps behaving, and the whole
     * point is that discovery must not depend on it. The conversation is
     * removed directly instead, which is the state any future delete path
     * could leave behind.
     */
    public function test_a_rating_outliving_its_conversation_is_still_discoverable(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $context = \context_course::instance($course->id);

        $conv = conversation_manager::get_or_create_conversation((int) $user->id, (int) $course->id);
        $messageid = conversation_manager::add_message(
            (int) $conv->id,
            (int) $user->id,
            (int) $course->id,
            'assistant',
            'Photosynthesis converts light energy into chemical energy.'
        );

        $DB->insert_record('local_ai_course_assistant_msg_ratings', (object) [
            'messageid' => $messageid,
            'userid' => $user->id,
            'courseid' => $course->id,
            'rating' => 1,
            'is_hallucination' => 0,
            'timecreated' => 1759276800,
        ]);

        $this->assertContains((int) $context->id, $this->discovered_context_ids((int) $user->id),
            'sanity: the learner should be discoverable while the conversation exists');

        // The orphan: conversation and messages gone, rating left behind.
        $DB->delete_records('local_ai_course_assistant_msgs', ['conversationid' => $conv->id]);
        $DB->delete_records('local_ai_course_assistant_convs', ['id' => $conv->id]);

        $this->assertTrue(
            $DB->record_exists('local_ai_course_assistant_msg_ratings', ['userid' => $user->id]),
            'sanity: the rating should have outlived the conversation'
        );

        $this->assertContains((int) $context->id, $this->discovered_context_ids((int) $user->id),
            'The learner holds a msg_ratings row in this course and no conversation, and '
                . 'get_contexts_for_userid() does not return the course. A subject access '
                . 'request will not export the rating and an erasure request will not delete '
                . 'it, because both only visit contexts that discovery returned.');

        $userlist = new \core_privacy\local\request\userlist($context, 'local_ai_course_assistant');
        privacy\provider::get_users_in_context($userlist);
        $this->assertContains((int) $user->id, array_map('intval', $userlist->get_userids()),
            'get_users_in_context() does not return a learner whose only row in the course '
                . 'is a rating, so a course-level deletion skips them.');
    }

    /**
     * Clearing a conversation takes the ratings with it.
     *
     * Separate from discovery: a learner who asks for the conversation to be
     * cleared should not keep leaving thumbs behind in the table.
     */
    public function test_clearing_a_conversation_removes_its_ratings(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $conv = conversation_manager::get_or_create_conversation((int) $user->id, (int) $course->id);
        $messageid = conversation_manager::add_message(
            (int) $conv->id, (int) $user->id, (int) $course->id, 'assistant', 'A reply.');

        $keepconv = conversation_manager::get_or_create_conversation((int) $user->id, (int) $other->id);
        $keepmessage = conversation_manager::add_message(
            (int) $keepconv->id, (int) $user->id, (int) $other->id, 'assistant', 'Another reply.');

        foreach ([[$messageid, $course->id], [$keepmessage, $other->id]] as [$mid, $cid]) {
            $DB->insert_record('local_ai_course_assistant_msg_ratings', (object) [
                'messageid' => $mid,
                'userid' => $user->id,
                'courseid' => $cid,
                'rating' => -1,
                'is_hallucination' => 0,
                'timecreated' => 1759276800,
            ]);
        }

        conversation_manager::clear_conversation((int) $conv->id, (int) $user->id);

        $this->assertFalse(
            $DB->record_exists('local_ai_course_assistant_msg_ratings', ['messageid' => $messageid]),
            'The rating on the cleared conversation outlived the conversation it belonged to'
        );
        $this->assertTrue(
            $DB->record_exists('local_ai_course_assistant_msg_ratings', ['messageid' => $keepmessage]),
            'Clearing one conversation deleted a rating belonging to another'
        );
    }
}
