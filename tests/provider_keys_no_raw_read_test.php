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
 * Nothing reads the provider table around the accessor, because rows hold encrypted keys.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class provider_keys_no_raw_read_test extends \advanced_testcase {

    public function test_no_code_reads_the_provider_table_around_provider_keys(): void {
        $root = dirname(__DIR__);
        $allowed = ['classes/provider_keys.php'];
        $offenders = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $path = $file->getPathname();
            $rel = ltrim(substr($path, strlen($root)), '/');
            if (substr($path, -4) !== '.php' || preg_match('~^(tests|lang|vendor|scripts|node_modules|cdn)/~', $rel)
                    || in_array($rel, $allowed, true)) {
                continue;
            }
            if (preg_match('~get_config\([^)]*[\'"]comparison_providers[\'"]~', (string) file_get_contents($path))) {
                $offenders[] = $rel;
            }
        }
        $this->assertSame([], $offenders,
            'These read the raw comparison_providers setting. Use provider_keys::rows(), row_for(), for_provider() '
            . 'or key_for(): rows hold encrypted keys and the accessor decrypts them.');
    }
}
