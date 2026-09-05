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
 * Lint: get_records_sql shapes whose first column collapses rows (v7.3.3 guard).
 *
 * Moodle's get_records_sql keys the result array by the FIRST selected column.
 * When that column repeats, later rows silently overwrite earlier ones. This
 * single behavior produced FOUR separate confirmed defects in this plugin,
 * found months apart: get_session_stats (v6.6.0), the transcript summaries
 * objectives batch (v7.3.0), the soapbox_cleanup prune pairs — which pruned
 * exactly one learner per assignment (v7.3.3) — and build_student_profiles
 * (v7.3.3). Each passed casual testing, because everything works for one
 * member of every group.
 *
 * Full uniqueness is undecidable statically, so this lint flags the two shapes
 * that are decidable and were each a real defect here:
 *   A. get_records_sql over `SELECT DISTINCT a, b, ...` — with 2+ selected
 *      columns under DISTINCT, the first is (definitionally) expected to
 *      repeat, or DISTINCT would be pointless.
 *   B. get_records_sql whose SQL has `GROUP BY x, y, ...` (2+ columns) where
 *      the FIRST selected column equals the FIRST grouping column — that
 *      column repeats across the other grouping dimensions.
 * Safe alternatives the codebase already uses: lead the SELECT with a unique
 * id, build a synthetic key via $DB->sql_concat(), or use get_recordset_sql.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class records_sql_keying_lint_test extends \basic_testcase {
    /**
     * Scan one PHP source body; return descriptions of offending call sites.
     *
     * Public-static so the self-test below can feed it the historical bug.
     *
     * @param string $body PHP source.
     * @param string $file Label for reporting.
     * @return string[]
     */
    public static function scan(string $body, string $file): array {
        $hits = [];
        // Each get_records_sql( ... "SQL" ... ) — grab the first string literal
        // after the call, which is the SQL (possibly concatenated; the head is
        // what determines the first selected column).
        if (!preg_match_all('/->get_records_sql\s*\(\s*(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\')/s',
                $body, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $hits;
        }
        foreach ($m as $hit) {
            $sql = $hit[1][0] !== '' ? $hit[1][0] : $hit[2][0];
            $line = substr_count(substr($body, 0, $hit[0][1]), "\n") + 1;
            $norm = strtolower(preg_replace('/\s+/', ' ', $sql));

            // Shape A: SELECT DISTINCT with 2+ columns.
            if (preg_match('/^\s*select\s+distinct\s+(.+?)\s+from\s/', $norm, $sm)) {
                if (substr_count($sm[1], ',') >= 1) {
                    $hits[] = "$file:$line SELECT DISTINCT over multiple columns "
                        . "feeds get_records_sql (first column will repeat)";
                    continue;
                }
            }

            // Shape B: GROUP BY 2+ cols, first selected col == first group col,
            // AND every grouping column shares the first column's table alias.
            // Mixed aliases (GROUP BY m.userid, u.firstname, ...) are the safe
            // ONLY_FULL_GROUP_BY idiom -- dependent columns pulled from a JOIN,
            // where the first column IS the unique group key. Same-alias
            // multi-column grouping (GROUP BY assignid, userid) is genuinely
            // multi-dimensional, which is the shape that pruned one learner per
            // assignment.
            if (preg_match('/group by\s+([a-z0-9_.]+(?:\s*,\s*[a-z0-9_.]+)+)/', $norm, $gm)) {
                $groupcols = array_map('trim', explode(',', $gm[1]));
                if (preg_match('/^\s*select\s+([a-z0-9_.]+)\s*(?:,|\s+as\s)/', $norm, $fm)) {
                    $aliasof = static function (string $col): string {
                        return str_contains($col, '.') ? explode('.', $col, 2)[0] : '';
                    };
                    $stripalias = static function (string $col): string {
                        return str_contains($col, '.') ? explode('.', $col, 2)[1] : $col;
                    };
                    $firstalias = $aliasof($fm[1]);
                    $samealias = true;
                    foreach ($groupcols as $gc) {
                        if ($aliasof($gc) !== $firstalias) {
                            $samealias = false;
                            break;
                        }
                    }
                    if ($samealias && $stripalias($fm[1]) === $stripalias($groupcols[0])) {
                        $hits[] = "$file:$line first selected column '{$fm[1]}' heads a same-table "
                            . "multi-column GROUP BY (it repeats across the other dimensions)";
                    }
                }
            }
        }
        return $hits;
    }

    /**
     * The scanner must catch the exact SQL that shipped the soapbox_cleanup
     * defect — otherwise this lint is decoration.
     */
    public function test_scanner_catches_the_historical_bug(): void {
        $historical = '<?php $pairs = $DB->get_records_sql(
            "SELECT DISTINCT assignid, userid
               FROM {local_ai_course_assistant_sbx_rec}
              WHERE status <> :deleted",
            [\'deleted\' => \'deleted\']
        );';
        $this->assertNotEmpty(self::scan($historical, 'fixture'),
            'the scanner failed to flag the exact shape that pruned one learner per assignment');
    }

    public function test_no_collapsing_get_records_sql_shapes(): void {
        global $CFG;
        $root = $CFG->dirroot . '/local/ai_course_assistant';
        $offenders = [];
        $scanned = 0;
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            $path = $f->getPathname();
            if (!str_ends_with($path, '.php')
                    || preg_match('#/(tests|\.git|\.drafts|\.wiki|node_modules|amd)/#', $path)) {
                continue;
            }
            $scanned++;
            $offenders = array_merge($offenders,
                self::scan(file_get_contents($path), str_replace($root . '/', '', $path)));
        }
        $this->assertGreaterThan(100, $scanned, 'scan found too few files; the walk is broken');
        $this->assertSame([], $offenders,
            "get_records_sql shapes whose first column collapses rows -- lead with a unique "
            . "id, build a synthetic key with \$DB->sql_concat(), or use get_recordset_sql:\n  - "
            . implode("\n  - ", $offenders));
    }
}
