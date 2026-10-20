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
 * 7.12.2 (#339): the email opt-out owner back-fill runs, and runs again for sites that missed it.
 *
 * Upgrade step 2026100102 read $CFG->mnet_localhost_id without declaring it, so on Moodle 5.2.3 the back-fill
 * bound a null host id and claimed nothing. The step now calls one shared function, and a later step (build
 * 2026101902) calls it again for every site that already passed the old savepoint.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\email_optout::claim_owners
 */
final class email_optout_owner_backfill_test extends \advanced_testcase {
    /** @var string The plugin component, which is also its config_plugins name. */
    private const PLUGIN = 'local_ai_course_assistant';

    /** @var string The opt-out table. */
    private const TABLE = 'local_ai_course_assistant_email_optout';

    /** @var int The latest build a 7.12.1 site holds: it passed the old step, with the null host id. */
    private const BEFORE = 2026101901;

    /** @var int The build whose step claims the opt-outs again. */
    private const REPAIRING = 2026101902;

    /**
     * Run the plugin's real upgrade function as a site on the given build would.
     *
     * @param int $from The build the site is upgrading from.
     */
    private function upgrade_from(int $from): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php'); // Core's upgrade script has loaded this by the time a step runs.
        require_once($CFG->dirroot . '/local/ai_course_assistant/db/upgrade.php');
        set_config('version', $from, self::PLUGIN);
        $this->assertTrue(\xmldb_local_ai_course_assistant_upgrade($from));
    }

    /**
     * Store an opt-out row the way the unsubscribe link left it before 7.8: no owner, address lower-cased.
     *
     * @param string $email The address, already lower-cased.
     * @param string $type The opt-out type (the table holds one row per address and type).
     * @param int|null $userid The owner, when the row has one.
     * @return int The row id.
     */
    private function row(string $email, string $type = email_optout::TYPE_STUDY_REMINDER, ?int $userid = null): int {
        global $DB;
        return $DB->insert_record(self::TABLE, (object) [
            'email' => $email,
            'optout_type' => $type,
            'userid' => $userid,
            'courseid' => null,
            'timecreated' => 1700000000,
        ]);
    }

    /**
     * Create a user and give it an exact address, bypassing the generator's unique-address rule.
     *
     * @param string $email The address, stored exactly as given (case included).
     * @param array $extra Further user fields to set (deleted, mnethostid).
     * @return int The user id.
     */
    private function user(string $email, array $extra = []): int {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $DB->update_record('user', (object) (['id' => $user->id, 'email' => $email] + $extra));
        return (int) $user->id;
    }

    /**
     * The stored owner of a row.
     *
     * @param int $rowid The row id.
     * @return int|null The owner, or null.
     */
    private function owner(int $rowid): ?int {
        global $DB;
        $userid = $DB->get_field(self::TABLE, 'userid', ['id' => $rowid], MUST_EXIST);
        return $userid === null ? null : (int) $userid;
    }

    /**
     * The upgrade function's global declarations and the Moodle globals its body reads.
     *
     * Read from the token stream rather than the text, so a comment or a string that merely mentions $CFG counts
     * for nothing and one inside "{$...}" interpolation counts for what it is.
     *
     * @return array Two lists, the declared names then the used ones, each without the dollar sign.
     */
    private function upgrade_function_globals(): array {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/db/upgrade.php');
        $tokens = token_get_all($source);
        $count = count($tokens);

        $start = null;
        for ($i = 0; $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        continue;
                    }
                    if (
                        is_array($tokens[$j]) && $tokens[$j][0] === T_STRING
                            && $tokens[$j][1] === 'xmldb_local_ai_course_assistant_upgrade'
                    ) {
                        $start = $j;
                    }
                    break;
                }
            }
            if ($start !== null) {
                break;
            }
        }
        $this->assertNotNull($start, 'the upgrade function was not found in db/upgrade.php');

        // Walk from the opening brace to its match.
        $depth = 0;
        $opened = false;
        $declared = [];
        $used = [];
        $inglobal = false;
        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                $opened = true;
            } else if ($token === '}') {
                $depth--;
                if ($opened && $depth === 0) {
                    break;
                }
            }
            if (is_array($token) && $token[0] === T_GLOBAL) {
                $inglobal = true;
                continue;
            }
            if ($inglobal && $token === ';') {
                $inglobal = false;
                continue;
            }
            if (is_array($token) && $token[0] === T_VARIABLE) {
                $name = ltrim($token[1], '$');
                if ($inglobal) {
                    $declared[] = $name;
                } else {
                    $used[] = $name;
                }
            }
        }
        return [array_values(array_unique($declared)), array_values(array_unique($used))];
    }

    /**
     * The source of one upgrade step: from its guard to its savepoint.
     *
     * @param int $build The step's build number.
     * @return string The text of the step.
     */
    private function step_source(int $build): string {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/db/upgrade.php');
        $guard = 'if ($oldversion < ' . $build . ') {';
        $from = strpos($source, $guard);
        $this->assertNotFalse($from, "there is no step guarded by build {$build}");
        $savepoint = "upgrade_plugin_savepoint(true, {$build}, 'local', 'ai_course_assistant');";
        $to = strpos($source, $savepoint, $from);
        $this->assertNotFalse($to, "step {$build} does not end with its savepoint");
        // Comment lines are dropped: a step that only talks about the shared function must not pass for one that calls it.
        return preg_replace('~^\s*//.*$~m', '', substr($source, $from, $to - $from));
    }

    // The declaration (the reviewer's finding, pinned at the source).

    public function test_the_upgrade_function_declares_cfg(): void {
        [$declared] = $this->upgrade_function_globals();
        $this->assertContains(
            'CFG',
            $declared,
            'xmldb_local_ai_course_assistant_upgrade() must declare global $CFG; without it a step that reads '
            . '$CFG->... gets a null on Moodle 5.2 and the query binds a null'
        );
    }

    public function test_the_upgrade_function_declares_every_moodle_global_it_reads(): void {
        [$declared, $used] = $this->upgrade_function_globals();
        $moodleglobals = ['CFG', 'DB', 'USER', 'SITE', 'PAGE', 'OUTPUT', 'SESSION', 'COURSE', 'PERF', 'FULLME', 'ME'];
        foreach ($moodleglobals as $name) {
            if (in_array($name, $used, true)) {
                $this->assertContains($name, $declared, "the upgrade function reads \${$name} without declaring it global");
            }
        }
        $this->assertContains('DB', $declared, 'the function uses $DB all through');
    }

    public function test_both_steps_run_the_one_shared_back_fill(): void {
        foreach ([2026100102, self::REPAIRING] as $build) {
            $step = $this->step_source($build);
            $this->assertStringContainsString(
                'email_optout::claim_owners()',
                $step,
                "step {$build} must call the shared back-fill, not carry its own copy"
            );
            $this->assertStringNotContainsString(
                'get_recordset_sql',
                $step,
                "step {$build} still runs its own query instead of the shared function"
            );
            $this->assertStringNotContainsString(
                'mnet_localhost_id',
                $step,
                "step {$build} still reads the host id itself"
            );
        }
    }

    // The shared back-fill.

    public function test_it_claims_the_one_row_with_exactly_one_live_owner_and_leaves_the_rest(): void {
        $this->resetAfterTest();
        $owner = $this->user('one@example.com');
        $this->user('two@example.com');
        $this->user('two@example.com');
        $someone = $this->user('someone-else@example.com');
        $this->user('already@example.com');

        $single = $this->row('one@example.com');
        $ambiguous = $this->row('two@example.com');
        $nobody = $this->row('nobody@vendor.example');
        // An owned row whose address happens to match a different single live account: it must not be reassigned.
        $owned = $this->row('already@example.com', email_optout::TYPE_OUTREACH, $someone);

        $this->assertSame(1, email_optout::claim_owners(), 'exactly one row has exactly one live owner');

        $this->assertSame($owner, $this->owner($single), 'the row with one live owner is claimed by that account');
        $this->assertNull($this->owner($ambiguous), 'two accounts share the address, so there is no single owner');
        $this->assertNull($this->owner($nobody), 'an address with no account stays unowned');
        $this->assertSame($someone, $this->owner($owned), 'a row that already has an owner is not touched');
    }

    public function test_running_it_twice_claims_nothing_the_second_time(): void {
        $this->resetAfterTest();
        $owner = $this->user('once@example.com');
        $this->user('shared@example.com');
        $this->user('shared@example.com');
        $row = $this->row('once@example.com');
        $ambiguous = $this->row('shared@example.com');

        $this->assertSame(1, email_optout::claim_owners());
        $this->assertSame(0, email_optout::claim_owners(), 'the second run finds nothing left to claim');

        $this->assertSame($owner, $this->owner($row));
        $this->assertNull($this->owner($ambiguous));
    }

    public function test_the_address_is_compared_lower_cased(): void {
        $this->resetAfterTest();
        // Rows are stored lower-cased; an account can hold the same address in any case.
        $owner = $this->user('Mixed.Case@Example.COM');
        $row = $this->row('mixed.case@example.com');

        $this->assertSame(1, email_optout::claim_owners());
        $this->assertSame($owner, $this->owner($row));
    }

    public function test_a_deleted_account_does_not_count_as_an_owner_or_as_a_second_one(): void {
        $this->resetAfterTest();
        $this->user('gone@example.com', ['deleted' => 1]);
        $gone = $this->row('gone@example.com');

        $live = $this->user('both@example.com');
        $this->user('both@example.com', ['deleted' => 1]);
        $both = $this->row('both@example.com');

        $this->assertSame(1, email_optout::claim_owners());
        $this->assertNull($this->owner($gone), 'a deleted account is not an owner');
        $this->assertSame($live, $this->owner($both), 'a deleted account is not a second claimant either');
    }

    public function test_only_accounts_on_this_sites_mnet_host_count(): void {
        global $CFG;
        $this->resetAfterTest();
        $elsewhere = (int) $CFG->mnet_localhost_id + 100;

        $this->user('remote@example.com', ['mnethostid' => $elsewhere]);
        $remoteonly = $this->row('remote@example.com');

        $local = $this->user('mixedhosts@example.com');
        $this->user('mixedhosts@example.com', ['mnethostid' => $elsewhere]);
        $mixed = $this->row('mixedhosts@example.com');

        $this->assertSame(1, email_optout::claim_owners());
        $this->assertNull(
            $this->owner($remoteonly),
            'an account on another mnet host is not this site\'s account and must not be claimed'
        );
        $this->assertSame(
            $local,
            $this->owner($mixed),
            'an account on another host is not a second owner on this one'
        );
    }

    public function test_the_host_is_read_from_the_live_config_not_assumed(): void {
        global $CFG;
        $this->resetAfterTest();
        $original = (int) $CFG->mnet_localhost_id;
        $moved = $original + 7;

        $stays = $this->user('stays@example.com');
        $stayrow = $this->row('stays@example.com');
        $moves = $this->user('moves@example.com', ['mnethostid' => $moved]);
        $moverow = $this->row('moves@example.com');

        // This site's host is now $moved: the account that was local is the foreign one.
        $CFG->mnet_localhost_id = $moved;

        $this->assertSame(1, email_optout::claim_owners());
        $this->assertNull($this->owner($stayrow), 'the account on the old host is foreign now');
        $this->assertSame($moves, $this->owner($moverow), 'the account on the configured host is claimed');
        $this->assertNotSame($stays, $this->owner($moverow));
    }

    public function test_it_agrees_with_record_on_who_owns_an_address(): void {
        $this->resetAfterTest();
        $this->user('single@example.com');
        $this->user('double@example.com');
        $this->user('double@example.com');
        $this->user('Cased@Example.com');
        $this->user('deleted@example.com', ['deleted' => 1]);

        $addresses = ['single@example.com', 'double@example.com', 'cased@example.com', 'deleted@example.com',
            'nobody@vendor.example'];
        $rows = [];
        foreach ($addresses as $address) {
            $rows[$address] = $this->row($address);
        }

        email_optout::claim_owners();

        foreach ($addresses as $address) {
            $this->assertSame(
                email_optout::resolve_userid($address),
                $this->owner($rows[$address]),
                "the back-fill and record() must name the same owner for {$address}"
            );
        }
    }

    // The new upgrade step.

    public function test_a_site_that_passed_the_old_step_with_the_null_host_gets_its_rows_claimed(): void {
        $this->resetAfterTest();
        $owner = $this->user('learner@example.com');
        $this->user('shared@example.com');
        $this->user('shared@example.com');
        $left = $this->row('learner@example.com');
        $ambiguous = $this->row('shared@example.com');
        $nobody = $this->row('ops@vendor.example');

        $this->expectOutputRegex('/SOLA: claimed 1 email opt-out row\(s\) for their owner/');
        $this->upgrade_from(self::BEFORE);

        $this->assertSame($owner, $this->owner($left), 'the row the old step left null is claimed');
        $this->assertNull($this->owner($ambiguous));
        $this->assertNull($this->owner($nobody));
        // Later steps run too (7.12.2 added 2026101903), so the stored build is this step's or a later one.
        $this->assertGreaterThanOrEqual(self::REPAIRING, (int) get_config(self::PLUGIN, 'version'), 'the savepoint was reached');
    }

    public function test_the_step_does_not_claim_a_row_twice_or_touch_one_that_has_an_owner(): void {
        $this->resetAfterTest();
        $first = $this->user('first@example.com');
        $other = $this->user('other@example.com');
        $this->user('taken@example.com');
        $row = $this->row('first@example.com');
        $owned = $this->row('taken@example.com', email_optout::TYPE_OUTREACH, $other);

        $this->expectOutputRegex('/claimed 1 email opt-out row/');
        $this->upgrade_from(self::BEFORE);
        $this->assertSame($first, $this->owner($row));

        // The same upgrade again (a site restored from backup, a retried upgrade): nothing more is claimed or moved.
        $this->upgrade_from(self::BEFORE);
        $this->assertSame($first, $this->owner($row));
        $this->assertSame($other, $this->owner($owned), 'an owned row is never reassigned');
    }

    public function test_a_site_already_on_the_new_build_is_left_alone(): void {
        $this->resetAfterTest();
        $this->user('late@example.com');
        $row = $this->row('late@example.com');

        $this->upgrade_from(self::REPAIRING);

        $this->assertNull($this->owner($row), 'the step is guarded by its build number and does not run again');
    }
}
