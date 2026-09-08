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
 * The analytics "Export CSV" button must actually produce CSV (v7.2.10).
 *
 * The button has always pointed at redash_export.php, which accepted no format
 * parameter and unconditionally emitted application/json with no
 * Content-Disposition -- so it downloaded a JSON body the browser rendered
 * inline. A CSV-producing external function existed and nothing called it.
 *
 * Pinned in the source because redash_export.php is a top-level script that
 * authenticates, queries and exits; standing it up under PHPUnit would test the
 * harness more than the wiring.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class analytics_export_wiring_test extends \advanced_testcase {

    /**
     * Read a plugin file.
     *
     * @param string $rel
     * @return string
     */
    private static function src(string $rel): string {
        global $CFG;
        return (string) file_get_contents($CFG->dirroot . '/local/ai_course_assistant/' . $rel);
    }

    /**
     * The button asks for CSV.
     */
    public function test_export_button_requests_csv(): void {
        $s = self::src('analytics.php');
        $pos = strpos($s, 'export_csv_url');
        $this->assertNotFalse($pos, 'the export_csv_url template variable is gone');
        $window = substr($s, $pos, 700);
        $this->assertStringContainsString("'format' => 'csv'", $window,
            'the Export CSV button does not request format=csv, so it downloads JSON');
    }

    /**
     * The endpoint honours it, with a real attachment header.
     */
    public function test_endpoint_emits_csv_with_attachment_headers(): void {
        $s = self::src('redash_export.php');
        $this->assertStringContainsString("optional_param('format'", $s,
            'redash_export.php reads no format parameter');
        $this->assertStringContainsString("text/csv", $s,
            'redash_export.php never sets a CSV content type');
        $this->assertStringContainsString('Content-Disposition: attachment', $s,
            'without Content-Disposition the browser renders the export inline');
        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $s,
            'a downloadable export should not be content-sniffed');
    }

    /**
     * Its cells go through the same formula-injection escaping as the
     * transcript report, rather than a second, divergent implementation.
     */
    public function test_csv_cells_share_one_escaping_implementation(): void {
        $s = self::src('redash_export.php');
        $this->assertStringContainsString("transcript_report::class, 'csv_cell'", $s,
            'redash_export.php does not reuse transcript_report::csv_cell for escaping');
    }
}
