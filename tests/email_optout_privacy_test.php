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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

/**
 * An email opt-out is reachable by a data request (issue #284).
 *
 * The row stores a learner's email address. Its only production writer is the
 * unsubscribe link, which is followed by someone who is not logged in, so
 * email_optout::record() stored a null userid for every real opt-out. Every
 * discovery method and both erasure paths key on userid, and null matched none
 * of them, so the address could not be exported on a subject access request,
 * could not be erased on request, and survived deletion of the account.
 *
 * It was also invisible to the per-course discovery the rest of the plugin
 * uses, because the row has no courseid. Fixing the userid alone would not
 * have been enough: delete_data_for_user() only runs for a context that
 * discovery returned, so the provider needed a system-context path too.
 *
 * These tests use the real writer path rather than asserting on SQL, because
 * the defect was that nothing ran, not that a query was wrong.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\email_optout::resolve_userid
 * @covers     \local_ai_course_assistant\privacy\provider::get_contexts_for_userid
 */
final class email_optout_privacy_test extends \advanced_testcase {

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Unsubscribing the way a learner actually does it claims the row.
     *
     * record() is called with the address and the type only, exactly as
     * email_unsubscribe.php calls it, so this fails if the resolution is
     * dropped or moved somewhere the real caller does not reach.
     */
    public function test_an_unsubscribe_is_attributed_to_the_account_that_owns_the_address(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['email' => 'learner@example.com']);

        email_optout::record('learner@example.com', email_optout::TYPE_STUDY_REMINDER);

        $row = $DB->get_record('local_ai_course_assistant_email_optout',
            ['email' => 'learner@example.com']);
        $this->assertNotEmpty($row, 'the opt-out should have been stored');
        $this->assertEquals($user->id, $row->userid,
            'the opt-out was stored without an owner, so no data request can reach it');
    }

    /**
     * An address nobody owns stays unattributed, and that is correct.
     */
    public function test_an_address_with_no_account_stays_unattributed(): void {
        global $DB;

        email_optout::record('ops@vendor.example', email_optout::TYPE_SPEND_ALERT);

        $row = $DB->get_record('local_ai_course_assistant_email_optout',
            ['email' => 'ops@vendor.example']);
        $this->assertNotEmpty($row);
        $this->assertNull($row->userid,
            'an address with no Moodle account has no owner to attribute it to');
    }

    /**
     * Two accounts sharing an address means no single owner.
     *
     * Guessing would attach one person's opt-out to another person's subject
     * access response.
     */
    public function test_an_ambiguous_address_is_not_attributed_to_either_account(): void {
        global $DB;

        $this->getDataGenerator()->create_user(['email' => 'shared@example.com', 'username' => 'one']);
        $second = $this->getDataGenerator()->create_user(['username' => 'two']);
        // Moodle's generator enforces unique emails, so collide them directly.
        $DB->set_field('user', 'email', 'shared@example.com', ['id' => $second->id]);

        email_optout::record('shared@example.com', email_optout::TYPE_LEARNER_DIGEST);

        $row = $DB->get_record('local_ai_course_assistant_email_optout',
            ['email' => 'shared@example.com']);
        $this->assertNull($row->userid,
            'with two owners there is no single owner, and picking one is worse than picking none');
    }

    /**
     * A deleted account does not count as an owner.
     */
    public function test_a_deleted_account_does_not_claim_the_address(): void {
        global $DB;

        $gone = $this->getDataGenerator()->create_user(['email' => 'gone@example.com']);
        $DB->set_field('user', 'deleted', 1, ['id' => $gone->id]);

        email_optout::record('gone@example.com', email_optout::TYPE_OUTREACH);

        $row = $DB->get_record('local_ai_course_assistant_email_optout',
            ['email' => 'gone@example.com']);
        $this->assertNull($row->userid);
    }

    /**
     * The learner is discoverable at the system context.
     *
     * This is the half that makes erasure run at all. Without a context,
     * delete_data_for_user() is never called for this component.
     */
    public function test_the_learner_is_discoverable_at_the_system_context(): void {
        $user = $this->getDataGenerator()->create_user(['email' => 'findme@example.com']);
        email_optout::record('findme@example.com', email_optout::TYPE_STUDY_REMINDER);

        $contexts = privacy\provider::get_contexts_for_userid((int) $user->id);
        $ids = array_map('intval', $contexts->get_contextids());

        $this->assertContains((int) \context_system::instance()->id, $ids,
            'the learner holds an email opt-out and no context was returned for it, '
                . 'so neither export nor erasure will ever visit it');
    }

    /**
     * A learner holding nothing is not discoverable.
     *
     * The negative control. Without it, a discovery query that returned the
     * system context unconditionally would pass the test above.
     */
    public function test_a_learner_without_an_optout_gets_no_system_context(): void {
        $bystander = $this->getDataGenerator()->create_user(['email' => 'nothing@example.com']);

        $contexts = privacy\provider::get_contexts_for_userid((int) $bystander->id);
        $ids = array_map('intval', $contexts->get_contextids());

        $this->assertNotContains((int) \context_system::instance()->id, $ids,
            'a learner with no system-context data should not be handed the system context');
    }

    /**
     * The address appears in the learner's export.
     */
    public function test_the_address_is_exported(): void {
        $user = $this->getDataGenerator()->create_user(['email' => 'export@example.com']);
        email_optout::record('export@example.com', email_optout::TYPE_STUDY_REMINDER);

        $context = \context_system::instance();
        $contextlist = new approved_contextlist(
            $user, 'local_ai_course_assistant', [$context->id]);

        privacy\provider::export_user_data($contextlist);

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data(),
            'the subject access export contained nothing, so the address was not disclosed');
    }

    /**
     * An erasure request removes the address.
     */
    public function test_an_erasure_request_removes_the_address(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['email' => 'erase@example.com']);
        email_optout::record('erase@example.com', email_optout::TYPE_STUDY_REMINDER);

        $other = $this->getDataGenerator()->create_user(['email' => 'keep@example.com']);
        email_optout::record('keep@example.com', email_optout::TYPE_STUDY_REMINDER);

        $contextlist = new approved_contextlist(
            $user, 'local_ai_course_assistant', [\context_system::instance()->id]);
        privacy\provider::delete_data_for_user($contextlist);

        $this->assertFalse(
            $DB->record_exists('local_ai_course_assistant_email_optout',
                ['email' => 'erase@example.com']),
            'the learner asked for their data to be erased and their email address is still stored');

        $this->assertTrue(
            $DB->record_exists('local_ai_course_assistant_email_optout',
                ['email' => 'keep@example.com']),
            'erasing one learner removed another learner opt-out');
    }

    /**
     * Deleting the account removes the address.
     *
     * The path the original audit cared about: a hard-deleted user must not
     * leave their email address behind.
     */
    public function test_deleting_the_account_removes_the_address(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        $user = $this->getDataGenerator()->create_user(['email' => 'byebye@example.com']);
        email_optout::record('byebye@example.com', email_optout::TYPE_STUDY_REMINDER);

        delete_user($DB->get_record('user', ['id' => $user->id]));

        $this->assertFalse(
            $DB->record_exists('local_ai_course_assistant_email_optout',
                ['email' => 'byebye@example.com']),
            'a hard-deleted user left an opt-out row containing their email address');
    }
}
