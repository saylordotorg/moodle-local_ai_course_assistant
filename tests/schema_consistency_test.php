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
 * Meta-test: schema consistency between install.xml and upgrade.php (v5.3.19).
 *
 * Catches the class of bug where a table is added to db/install.xml (so
 * fresh installs get it) but no matching upgrade savepoint is added to
 * db/upgrade.php (so existing installs never get it). The symptom on
 * production is a "Table doesn't exist" exception in code that touches
 * the new table — only ever seen on installs that upgraded from before
 * the table was added.
 *
 * Also catches the inverse: a table created in upgrade.php that is not
 * declared in install.xml (so fresh installs miss it).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schema_consistency_test extends \basic_testcase {

    /**
     * Tables that pre-date the v3.x upgrade-savepoint era. They are
     * created by Moodle's xmldb_main_install on a fresh install via
     * install.xml; existing installs have always had them. They do NOT
     * need a corresponding create_table call in upgrade.php, and never
     * will. This list exists ONLY to suppress false positives in
     * test_every_install_table_has_upgrade_create — adding a new entry
     * here is a strong signal that you are skipping a real check, and
     * should normally not be done.
     */
    const GRANDFATHERED_TABLES = [
        'local_ai_course_assistant_avatar_sess',
        'local_ai_course_assistant_convs',
        'local_ai_course_assistant_feedback',
        'local_ai_course_assistant_msgs',
        'local_ai_course_assistant_obj_att',
        'local_ai_course_assistant_practice_scores',
        'local_ai_course_assistant_radar_sched',
        'local_ai_course_assistant_reminders',
        'local_ai_course_assistant_ut_resp',
    ];

    /**
     * Plugin DB directory.
     *
     * @return string
     */
    private function db_dir(): string {
        global $CFG;
        return $CFG->dirroot . '/local/ai_course_assistant/db';
    }

    /**
     * Parse install.xml and return the list of declared SOLA table names.
     *
     * @return array<int, string>
     */
    private function install_xml_tables(): array {
        $path = $this->db_dir() . '/install.xml';
        $this->assertFileExists($path, 'install.xml must exist');
        $xml = simplexml_load_file($path);
        $this->assertNotFalse($xml, 'install.xml must be valid XML');
        $tables = [];
        foreach ($xml->TABLES->TABLE as $t) {
            $name = (string)$t['NAME'];
            if (strpos($name, 'local_ai_course_assistant') === 0) {
                $tables[] = $name;
            }
        }
        sort($tables);
        return $tables;
    }

    /**
     * Scan upgrade.php and return the list of SOLA table names that are
     * created via `new xmldb_table('NAME')` followed by a `create_table`.
     *
     * @return array<int, string>
     */
    private function upgrade_php_creates(): array {
        $path = $this->db_dir() . '/upgrade.php';
        $this->assertFileExists($path, 'upgrade.php must exist');
        $body = file_get_contents($path);
        // Match every `new xmldb_table('local_ai_course_assistant_FOO')`
        // — those are the candidates for create_table or alter_table.
        // We only want the ones followed by `$dbman->create_table(...)`
        // (which signals an actual creation, not a column add).
        $tables = [];
        if (preg_match_all(
            "/new\s+xmldb_table\(['\"](local_ai_course_assistant[^'\"]+)['\"]\)/",
            $body, $m)) {
            foreach ($m[1] as $candidate) {
                // Find the surrounding block (1500-char window) and check
                // for create_table on the same table object.
                $idx = strpos($body, $candidate);
                $window = substr($body, $idx, 1500);
                if (strpos($window, 'create_table') !== false) {
                    $tables[] = $candidate;
                }
            }
        }
        $tables = array_values(array_unique($tables));
        sort($tables);
        return $tables;
    }

    /**
     * Every TABLE in install.xml MUST have a corresponding create_table
     * in upgrade.php so existing installs get the table when upgrading
     * past the savepoint that introduced it.
     */
    public function test_every_install_table_has_upgrade_create(): void {
        $install = $this->install_xml_tables();
        $upgrade = $this->upgrade_php_creates();

        // Exclude grandfathered tables (created by xmldb_main_install before
        // the upgrade-savepoint era). New tables added since v3.x must
        // have an upgrade create.
        $checkable = array_diff($install, self::GRANDFATHERED_TABLES);
        $missing = array_diff($checkable, $upgrade);
        $this->assertEmpty($missing,
            "Tables in install.xml but missing a matching create_table in upgrade.php "
            . "(upgrades from older releases will fail to create them): "
            . implode(', ', $missing));
    }

    /**
     * Inverse: every table created in upgrade.php must also be declared
     * in install.xml so fresh installs get it on first run.
     */
    public function test_every_upgrade_create_has_install_table(): void {
        $install = $this->install_xml_tables();
        $upgrade = $this->upgrade_php_creates();

        $orphan = array_diff($upgrade, $install);
        $this->assertEmpty($orphan,
            "Tables created in upgrade.php but NOT declared in install.xml "
            . "(fresh installs will miss them): " . implode(', ', $orphan));
    }
}
