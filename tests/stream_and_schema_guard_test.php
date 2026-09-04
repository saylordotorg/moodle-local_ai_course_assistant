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
 * Two guards against silent data loss (v7.3.2, findings F34 and F69).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stream_and_schema_guard_test extends \basic_testcase {
    /**
     * F34: the SSE holdback must never split a multi-byte character.
     *
     * The stream filter holds back the trailing 24 bytes so a control marker
     * straddling a chunk boundary still reassembles. It split on a raw byte
     * offset, so a multi-byte character sitting across that offset left the
     * emitted segment ending in a partial sequence. json_encode() then returned
     * false and the frame went out as a bare "data:", dropping the WHOLE segment
     * -- not just the character. Every non-English stream is affected, as is
     * English containing curly quotes or em dashes, and the copy written to the
     * database stayed correct, so what the learner saw and what history holds
     * diverged with nothing logged.
     *
     * This reproduces the split arithmetic exactly as sse.php performs it.
     */
    public function test_holdback_split_never_breaks_a_character(): void {
        $holdback = 24;

        // Walk the boundary so a multi-byte character lands across it.
        foreach (['é', '—', '“', '日', '🙂'] as $mb) {
            for ($pad = 0; $pad < 8; $pad++) {
                $buf = str_repeat('a', 10 + $pad) . $mb . str_repeat('b', $holdback - 1);
                if (strlen($buf) <= $holdback) {
                    continue;
                }

                $cut = strlen($buf) - $holdback;
                while ($cut > 0 && (ord($buf[$cut]) & 0xC0) === 0x80) {
                    $cut--;
                }
                $emit = substr($buf, 0, $cut);
                $carry = substr($buf, $cut);

                $this->assertTrue(mb_check_encoding($emit, 'UTF-8'),
                    "emitted segment is not valid UTF-8 for {$mb} at pad {$pad}");
                $this->assertNotFalse(json_encode(['token' => $emit]),
                    "json_encode would drop the frame for {$mb} at pad {$pad}");
                $this->assertSame($buf, $emit . $carry,
                    'the split must be lossless');
            }
        }
    }

    /**
     * F69: every column named in a *_select predicate against the audit table
     * must actually exist in install.xml.
     *
     * Three readers queried `event` and selected `payload`; the table has
     * `action` and `details`. Each threw a dml_exception into a bare
     * catch { /* ignore *\/ }, so the instructor review queue's integrity
     * section and the "integrity flags open" figure read as clean rather than
     * broken. A swallowed schema error is indistinguishable from good news.
     */
    public function test_audit_queries_name_real_columns(): void {
        global $CFG;
        $root = $CFG->dirroot . '/local/ai_course_assistant';

        $xml = file_get_contents($root . '/db/install.xml');
        $this->assertMatchesRegularExpression('/local_ai_course_assistant_audit/', $xml,
            'audit table not found in install.xml; the scan is broken');
        preg_match('/<TABLE NAME="local_ai_course_assistant_audit"(.*?)<\/TABLE>/s', $xml, $m);
        preg_match_all('/<FIELD NAME="([^"]+)"/', $m[1], $f);
        $columns = $f[1];

        $this->assertContains('action', $columns);
        $this->assertContains('details', $columns);
        $this->assertNotContains('event', $columns, 'if event now exists, this guard needs revisiting');
        $this->assertNotContains('payload', $columns);

        // No source file may filter the audit table on a column it lacks.
        $offenders = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, '.php') || preg_match('#/(tests|\.git|\.drafts)/#', $path)) {
                continue;
            }
            $body = file_get_contents($path);
            if (strpos($body, 'local_ai_course_assistant_audit') === false) {
                continue;
            }
            foreach (['event =', 'event=', 'payload'] as $bad) {
                if (strpos($body, "'" . $bad) !== false || strpos($body, '"' . $bad) !== false) {
                    $offenders[] = basename($path) . " uses '{$bad}'";
                }
            }
        }
        $this->assertSame([], $offenders,
            'audit queries must use action/details, the columns that exist');
    }
}
