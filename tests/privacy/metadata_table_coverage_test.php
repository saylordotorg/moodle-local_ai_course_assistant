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

namespace local_ai_course_assistant\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Every table with a user-identifier column is declared in the privacy metadata.
 *
 * Moodle core already checks this, in privacy/tests/provider_test.php. That test
 * is not part of this plugin's testsuite and does not run in this plugin's CI,
 * so three tables added in v7.4.0 sat undeclared until someone ran PHPUnit with
 * a filter wide enough to pull core's suite in. A guard that only fires
 * somewhere you do not look is not a guard.
 *
 * @package    local_ai_course_assistant
 * @covers     \local_ai_course_assistant\privacy\provider::get_metadata
 */
final class metadata_table_coverage_test extends \advanced_testcase {

    /**
     * Column names core treats as identifying a user.
     *
     * WIDER than the list core's own test uses. Core flagged three tables here;
     * this list also catches 'usermodified', which found a fourth. That is
     * deliberate: the point is to declare what the plugin stores about people,
     * and core's list is a floor rather than the definition. Passing this test
     * therefore implies core passes, but not the reverse.
     */
    private const USERID_COLUMNS = ['userid', 'user_id', 'usermodified', 'addedby', 'createdby'];

    /**
     * Declare every table that carries one, or this fails before core does.
     *
     * @return void
     */
    public function test_every_table_with_a_userid_column_is_declared(): void {
        $this->resetAfterTest();

        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/db/install.xml');
        $this->assertNotFalse($xml, 'db/install.xml could not be parsed');

        $declared = [];
        foreach (provider::get_metadata(new \core_privacy\local\metadata\collection('local_ai_course_assistant'))->get_collection() as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\database_table) {
                $declared[$item->get_name()] = true;
            }
        }

        $missing = [];
        foreach ($xml->TABLES->TABLE as $table) {
            $name = (string) $table['NAME'];
            foreach ($table->FIELDS->FIELD as $field) {
                if (!in_array(strtolower((string) $field['NAME']), self::USERID_COLUMNS, true)) {
                    continue;
                }
                if (!isset($declared[$name])) {
                    $missing[] = $name . ' (' . (string) $field['NAME'] . ')';
                }
                break;
            }
        }
        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "A table carries a column core reads as a user identifier and is not declared in "
                . "get_metadata(). Declare it. If the column is site configuration rather than "
                . "learner data, declare it anyway and say so in its lang string, the way the "
                . "model-registry tables do, rather than leaving it out."
        );
    }
}
