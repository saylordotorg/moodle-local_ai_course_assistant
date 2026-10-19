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

/**
 * Which Soapbox recordings PresenterAI's importer now owns.
 *
 * PresenterAI (mod_presenterai) copies a Soapbox recording by pointing its own
 * row at the same S3 key, so no bytes move. It records that ownership in its
 * table presenterai_migration_map. SOLA's nightly soapbox_cleanup task asks this
 * class before it deletes an object or changes a row, because a migrated attempt
 * would otherwise point at an object SOLA's retention had already removed.
 *
 * The contract with PresenterAI's migrator is two table names, three columns and
 * one formula, all kept here and nowhere else (each side pins them in a test):
 * a recording is held when the map has a row with sourcetable = SOURCE_TABLE,
 * sourceid = the recording id and sourcesiteid = site_id().
 *
 * SOLA only READS the map, and only after checking that the table exists, so a
 * site without PresenterAI behaves exactly as it did before this class existed.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class presenterai_interlock {
    /** @var string The source table name a map row carries for a Soapbox recording. */
    public const SOURCE_TABLE = 'local_ai_course_assistant_sbx_rec';

    /** @var string PresenterAI's migration map (Moodle table name, no prefix). */
    public const MAP_TABLE = 'presenterai_migration_map';

    /**
     * @var int Most ids sent to the database in one query. The nightly lists are
     * far shorter; the cap keeps a very large backlog under the bound-parameter
     * limit of every database Moodle supports.
     */
    private const CHUNK = 1000;

    /**
     * The id PresenterAI's map uses for this site's rows.
     *
     * It is the sha1 of Moodle's site identifier and nothing else. The address is
     * deliberately NOT part of it: a host rename or an http to https change would
     * otherwise change the id, every migrated recording would stop being held, and
     * the next nightly cleanup would delete media PresenterAI still points at. The
     * identifier is created at install and survives a change of address. A database
     * cloned onto a second site keeps it too, so the clone holds the same
     * recordings, which errs on the side of not deleting.
     *
     * @return string sha1 hex digest
     */
    public static function site_id(): string {
        return sha1(get_site_identifier());
    }

    /**
     * The subset of the given recording ids that PresenterAI's map holds.
     *
     * One query answers a whole list (more only past CHUNK ids). Returns an empty
     * array for an empty input and when PresenterAI is not installed.
     *
     * @param array $recids Soapbox recording ids to check.
     * @return array The held ids, as ints, each once.
     */
    public static function held_ids(array $recids): array {
        global $DB;

        $ids = array_values(array_unique(array_map('intval', $recids)));
        if (!$ids) {
            return [];
        }
        if (!static::map_table_exists()) {
            return [];
        }

        $held = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'rec');
            $params['srctable'] = self::SOURCE_TABLE;
            $params['siteid'] = self::site_id();
            $found = $DB->get_fieldset_select(
                self::MAP_TABLE,
                'sourceid',
                "sourcetable = :srctable AND sourcesiteid = :siteid AND sourceid $insql",
                $params
            );
            // The map's unique index means one row per id, and the ids were made
            // distinct above, so no id can come back from two chunks.
            $held = array_merge($held, array_map('intval', $found));
        }
        return $held;
    }

    /**
     * Whether PresenterAI's map table exists on this site.
     *
     * Protected so a test can stand in for a site on which PresenterAI is
     * present or absent without creating a table in the test database.
     *
     * @return bool
     */
    protected static function map_table_exists(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(self::MAP_TABLE);
    }
}
