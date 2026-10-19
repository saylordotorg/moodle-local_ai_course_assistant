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

require_once(__DIR__ . '/fixtures/presenterai_map_table.php');

/**
 * The SOLA half of the contract with PresenterAI's Soapbox importer.
 *
 * PresenterAI's importer copies a Soapbox recording by pointing its own row at
 * the same S3 key, so no bytes move. It records that ownership in its table
 * presenterai_migration_map, and SOLA's nightly cleanup must then leave the
 * recording alone, or the migrated attempt would point at a deleted object.
 *
 * Three literals and one formula are the whole contract, and they are pinned
 * here and in PresenterAI's own tests: the source table name, the map table
 * name, and the site id (sha1 of the Moodle site identifier, with no address in
 * it). A change on either side that the other does not follow makes the importer
 * and the cleanup disagree about which recordings are held, silently.
 *
 * SOLA only reads the map and only behind table_exists(), so a site without
 * PresenterAI behaves exactly as it did before. PresenterAI's tables are not
 * part of this plugin and may be absent from the PHPUnit database (CI). The
 * presenterai_map_table fixture then creates a temporary table of the same name,
 * so every held-path test, and the query that decides what the cleanup may
 * delete, runs on every database. The absent-table behavior is asserted through
 * a stub of the existence check (which runs everywhere, with a matching row in
 * place) and, where the table is genuinely absent, for real.
 *
 * @package    local_ai_course_assistant
 * @covers     \local_ai_course_assistant\presenterai_interlock
 */
final class presenterai_interlock_test extends \advanced_testcase {
    use presenterai_map_table;

    /**
     * A subclass whose existence check says the map table is absent.
     *
     * @return string Class name; call held_ids() on it.
     */
    private function absent_variant(): string {
        return get_class(new class extends presenterai_interlock {
            /**
             * Pretend PresenterAI is not installed.
             *
             * @return bool
             */
            protected static function map_table_exists(): bool {
                return false;
            }
        });
    }

    /**
     * The two literals and the formula are the shared contract.
     *
     * @return void
     */
    public function test_the_contract_literals_and_the_site_id_formula_are_pinned(): void {
        $this->assertSame('local_ai_course_assistant_sbx_rec', presenterai_interlock::SOURCE_TABLE);
        $this->assertSame('presenterai_migration_map', presenterai_interlock::MAP_TABLE);
        $this->assertSame(sha1(get_site_identifier()), presenterai_interlock::site_id());
        $this->assertSame(40, strlen(presenterai_interlock::site_id()), 'a sha1 hex digest');
    }

    /**
     * A change of address changes neither the site id nor what is held.
     *
     * A host rename or an http to https change must not make the cleanup forget
     * the recordings PresenterAI already points at. The id is the site
     * identifier's hash alone, so the address is not part of it.
     *
     * @return void
     */
    public function test_a_change_of_address_changes_neither_the_site_id_nor_the_held_result(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->require_map_table();

        $before = presenterai_interlock::site_id();
        $this->map_row(presenterai_interlock::SOURCE_TABLE, 601, $before);
        $this->assertSame([601], presenterai_interlock::held_ids([601, 602]));

        $CFG->wwwroot = 'https://renamed.example.org/learn';

        $this->assertSame($before, presenterai_interlock::site_id());
        $this->assertSame([601], presenterai_interlock::held_ids([601, 602]));
    }

    /**
     * The nightly task publishes the constant PresenterAI's importer looks for.
     *
     * @return void
     */
    public function test_the_cleanup_task_declares_the_interlock_constant(): void {
        $this->assertTrue(defined(task\soapbox_cleanup::class . '::PRESENTERAI_INTERLOCK'));
        $this->assertTrue(task\soapbox_cleanup::PRESENTERAI_INTERLOCK);
    }

    /**
     * Nothing to ask means no answer and no database work.
     *
     * @return void
     */
    public function test_an_empty_input_holds_nothing(): void {
        $this->resetAfterTest();
        $this->assertSame([], presenterai_interlock::held_ids([]));
    }

    /**
     * The production existence check is the database manager's.
     *
     * @return void
     */
    public function test_the_default_existence_check_asks_the_database(): void {
        global $DB;

        $method = new \ReflectionMethod(presenterai_interlock::class, 'map_table_exists');
        $method->setAccessible(true);

        $this->assertSame(
            $DB->get_manager()->table_exists('presenterai_migration_map'),
            $method->invoke(null)
        );
    }

    /**
     * A site without PresenterAI holds nothing, even if a row would match.
     *
     * The existence check is stubbed to false. A matching row is inserted first
     * (in the real table, or the fixture's temporary one), so only the guard
     * stands between this call and a non-empty answer: without it the query
     * would run and find it.
     *
     * @return void
     */
    public function test_a_missing_map_table_holds_nothing_even_when_a_row_would_match(): void {
        $this->resetAfterTest();
        $this->require_map_table();

        $this->map_row(presenterai_interlock::SOURCE_TABLE, 41, presenterai_interlock::site_id());
        $variant = $this->absent_variant();

        $this->assertSame([], $variant::held_ids([41, 42]));
    }

    /**
     * Where the table is genuinely absent the real path must not throw.
     *
     * @return void
     */
    public function test_a_genuinely_absent_map_table_holds_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        // Deliberately does not call require_map_table(): the table must be absent.
        if ($DB->get_manager()->table_exists(presenterai_interlock::MAP_TABLE)) {
            $this->markTestSkipped('PresenterAI is installed here; the stubbed test covers the absent path.');
        }

        $this->assertSame([], presenterai_interlock::held_ids([41, 42]));
    }

    /**
     * A recording the map holds for this site is held; the others are not.
     *
     * @return void
     */
    public function test_a_recording_mapped_for_this_site_is_held_and_an_unmapped_one_is_not(): void {
        $this->resetAfterTest();
        $this->require_map_table();

        $this->map_row(presenterai_interlock::SOURCE_TABLE, 101, presenterai_interlock::site_id());
        $this->map_row(presenterai_interlock::SOURCE_TABLE, 103, presenterai_interlock::site_id());

        $held = presenterai_interlock::held_ids([101, 102, 103, 104]);
        sort($held);

        $this->assertSame([101, 103], $held);
        foreach ($held as $id) {
            $this->assertIsInt($id);
        }
    }

    /**
     * Another site's mapping is not this site's hold.
     *
     * A database copied onto a second site would carry the first site's map
     * rows. Those recordings are not owned by an importer run on THIS site.
     *
     * @return void
     */
    public function test_a_recording_mapped_for_another_site_is_not_held(): void {
        $this->resetAfterTest();
        $this->require_map_table();

        $this->map_row(presenterai_interlock::SOURCE_TABLE, 201, sha1('another-site-identifier'));

        $this->assertSame([], presenterai_interlock::held_ids([201]));
    }

    /**
     * A map row for some other source table never holds a Soapbox recording.
     *
     * The same numeric id in another table's namespace is a different row.
     *
     * @return void
     */
    public function test_a_map_row_for_a_different_source_table_is_not_held(): void {
        $this->resetAfterTest();
        $this->require_map_table();

        $this->map_row('local_ai_course_assistant_sbx_topic', 301, presenterai_interlock::site_id());

        $this->assertSame([], presenterai_interlock::held_ids([301]));
    }

    /**
     * Repeated ids in the input come back once.
     *
     * @return void
     */
    public function test_a_repeated_id_is_reported_once(): void {
        $this->resetAfterTest();
        $this->require_map_table();

        $this->map_row(presenterai_interlock::SOURCE_TABLE, 401, presenterai_interlock::site_id());

        $this->assertSame([401], presenterai_interlock::held_ids([401, 401, 402]));
    }

    /**
     * The whole list is one query, not one per recording.
     *
     * The nightly task asks about every candidate in a loop at once, so the
     * cost of the interlock must not grow with the number of recordings.
     *
     * @return void
     */
    public function test_the_whole_list_is_answered_in_one_query(): void {
        global $DB;
        $this->resetAfterTest();
        $this->require_map_table();

        $this->map_row(presenterai_interlock::SOURCE_TABLE, 501, presenterai_interlock::site_id());
        // Warm the table-list cache so the measured call is only the lookup.
        presenterai_interlock::held_ids([501]);

        $before = $DB->perf_get_reads();
        $held = presenterai_interlock::held_ids(range(500, 540));
        $reads = $DB->perf_get_reads() - $before;

        $this->assertSame([501], $held);
        $this->assertSame(1, $reads, 'one SELECT for the whole list');
    }

    /**
     * A long list is read in chunks, and an id repeated across chunks is one hold.
     *
     * 2,500 distinct ids go to the database as three reads (1,000 + 1,000 + 500),
     * not one oversized IN list. One held id sits in the first chunk and another
     * in the third, and the first is repeated at the end of the input, where it
     * would land in the third chunk too if the input were not made distinct
     * first. Each held id is reported once.
     *
     * @return void
     */
    public function test_a_long_list_is_read_in_three_chunks_and_a_repeated_id_counts_once(): void {
        global $DB;
        $this->resetAfterTest();
        $this->require_map_table();

        $this->map_row(presenterai_interlock::SOURCE_TABLE, 10005, presenterai_interlock::site_id());
        $this->map_row(presenterai_interlock::SOURCE_TABLE, 12100, presenterai_interlock::site_id());
        // Warm the table-list cache so the measured call is only the lookups.
        presenterai_interlock::held_ids([10005]);

        $ids = range(10000, 12499);
        $ids[] = 10005;

        $before = $DB->perf_get_reads();
        $held = presenterai_interlock::held_ids($ids);
        $reads = $DB->perf_get_reads() - $before;

        sort($held);
        $this->assertSame([10005, 12100], $held);
        $this->assertSame(3, $reads, '2,500 distinct ids are read in three chunks of at most 1,000');
    }
}
