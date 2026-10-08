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

namespace local_ai_course_assistant\bench;

/**
 * CSV reading and writing for the benchmark harnesses, RFC 4180 style (v7.8.0).
 *
 * PHP's fputcsv() and fgetcsv() default to a backslash ESCAPE character, which
 * is not part of RFC 4180 and makes them disagree with each other: a value with
 * a backslash before a quote, or ending in a backslash, is written in a form
 * the reader takes as an escaped quote, so the field never ends and swallows
 * the fields after it. Tutor answers are full of LaTeX, so on 2026-10-07 one
 * judged row was skipped and --mode=report broke on the combined file.
 *
 * Every harness CSV is written and read here with the escape character
 * switched off, so a quote inside a value is always doubled and a backslash is
 * just a backslash.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class golden_csv {
    /** @var string No escape character: RFC 4180 quote doubling only. */
    public const ESCAPE = '';

    /**
     * Write one row.
     *
     * @param resource $fh
     * @param array $row
     * @return void
     */
    public static function write_row($fh, array $row): void {
        fputcsv($fh, array_map(static function ($v) {
            return $v === null ? '' : (string) $v;
        }, $row), ',', '"', self::ESCAPE);
    }

    /**
     * Read one row, or false at end of file.
     *
     * @param resource $fh
     * @return array|false
     */
    public static function read_row($fh) {
        return fgetcsv($fh, null, ',', '"', self::ESCAPE);
    }

    /**
     * Read a whole CSV with a header row into associative rows.
     *
     * A row with a different number of fields from the header is skipped
     * rather than combined, so a damaged line cannot shift every column after
     * it into the wrong key.
     *
     * @param string $path
     * @return array<int, array<string, string>>|null Null when the file cannot be read.
     */
    public static function read_all(string $path): ?array {
        if (!is_readable($path)) {
            return null;
        }
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return null;
        }
        $header = self::read_row($fh);
        if (!is_array($header)) {
            fclose($fh);
            return [];
        }
        $rows = [];
        while (($r = self::read_row($fh)) !== false) {
            if ($r === [null] || count($r) !== count($header)) {
                continue;
            }
            $rows[] = array_combine($header, $r);
        }
        fclose($fh);
        return $rows;
    }
}
