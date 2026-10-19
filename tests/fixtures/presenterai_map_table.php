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

/**
 * Test fixture: PresenterAI's migration map, present for every test that needs it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_course_assistant;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/ddllib.php');

/**
 * Gives a test class PresenterAI's presenterai_migration_map, whether or not PresenterAI is installed.
 *
 * The map is PresenterAI's table, not this plugin's, so a Moodle that does not have PresenterAI (CI) has no such
 * table, and every test that needs map rows would otherwise skip and the one query that decides what the nightly
 * cleanup may delete would never run on that database. require_map_table() uses the real table when it exists and
 * otherwise creates a TEMPORARY table of the same name with the columns the tests read and write, and the same
 * unique index. tearDown() drops a table the test created (a class with its own tearDown() calls
 * drop_temp_map_table()). A test that needs the table to be genuinely absent
 * must not call require_map_table() and should check table_exists() itself.
 *
 * Use it in an advanced_testcase. The temporary table is dropped before Moodle resets the test database.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait presenterai_map_table {
    /** @var bool Whether this test created the temporary map table and so must drop it. */
    private bool $maptabletemp = false;

    /**
     * The definition of the temporary map table.
     *
     * Only the columns the tests insert or the interlock reads, plus the NOT NULL ones with PresenterAI's defaults,
     * and PresenterAI's unique index on (sourcetable, sourceid, sourcesiteid).
     *
     * @param string $name Table name without the prefix.
     * @return \xmldb_table
     */
    protected static function build_map_table(string $name = presenterai_interlock::MAP_TABLE): \xmldb_table {
        $table = new \xmldb_table($name);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('runid', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL);
        $table->add_field('sourcetable', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('sourceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('sourcesiteid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('state', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'planned');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('srcunique', XMLDB_INDEX_UNIQUE, ['sourcetable', 'sourceid', 'sourcesiteid']);
        return $table;
    }

    /**
     * Make sure presenterai_migration_map exists for this test: the real table, else a temporary one.
     *
     * @return void
     */
    protected function require_map_table(): void {
        global $DB;
        $dbman = $DB->get_manager();
        if ($dbman->table_exists(presenterai_interlock::MAP_TABLE)) {
            return;
        }
        $dbman->create_temp_table(self::build_map_table());
        $this->maptabletemp = true;
    }

    /**
     * Whether the map table in use is the temporary one this test created.
     *
     * @return bool
     */
    protected function map_table_is_temporary(): bool {
        return $this->maptabletemp;
    }

    /**
     * Insert one map row, with only the columns PresenterAI requires.
     *
     * @param string $sourcetable
     * @param int $sourceid
     * @param string $sourcesiteid
     * @return void
     */
    protected function map_row(string $sourcetable, int $sourceid, string $sourcesiteid): void {
        global $DB;
        $DB->insert_record(presenterai_interlock::MAP_TABLE, (object) [
            'runid' => 'testrun',
            'sourcetable' => $sourcetable,
            'sourceid' => $sourceid,
            'sourcesiteid' => $sourcesiteid,
            'state' => 'planned',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Drop the temporary map table when this test created it.
     *
     * @return void
     */
    protected function drop_temp_map_table(): void {
        global $DB;
        if ($this->maptabletemp) {
            $this->maptabletemp = false;
            $DB->get_manager()->drop_table(self::build_map_table());
        }
    }

    /**
     * Drop the temporary map table after each test.
     *
     * A class that defines its own tearDown() replaces this one, and must call drop_temp_map_table() itself.
     *
     * @return void
     */
    protected function tearDown(): void {
        $this->drop_temp_map_table();
        parent::tearDown();
    }
}
