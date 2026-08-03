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
 * Backfill packed float32 vectors from the legacy JSON embedding column.
 *
 * Retrieval prefers `embedding_bin` and falls back to `embedding`, so this can
 * run incrementally on a live site: converted rows get the fast path, the rest
 * keep working unchanged. No embedding API calls are made — this is a pure
 * re-encoding of vectors already stored.
 *
 * Usage:
 *   php admin/cli/backfill_embedding_bin.php --dry-run
 *   php admin/cli/backfill_embedding_bin.php
 *   php admin/cli/backfill_embedding_bin.php --courseid=11 --batch=500
 *   php admin/cli/backfill_embedding_bin.php --verify
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_ai_course_assistant\rag_retriever;

list($options, $unrecognised) = cli_get_params([
    'help'     => false,
    'dry-run'  => false,
    'verify'   => false,
    'courseid' => 0,
    'batch'    => 200,
], ['h' => 'help']);

if ($options['help']) {
    cli_writeln(<<<TXT
Backfill packed float32 embedding vectors.

Options:
  --dry-run        Report how much work is outstanding, change nothing.
  --verify         Re-decode every converted row and confirm it matches the
                   JSON original exactly. Changes nothing.
  --courseid=N     Limit to one course (default: all).
  --batch=N        Rows per batch (default 200).
  -h, --help       This help.
TXT
    );
    exit(0);
}

$courseid = (int) $options['courseid'];
$batch = max(1, (int) $options['batch']);

$where = 'embedding IS NOT NULL';
$params = [];
if ($courseid > 0) {
    $where .= ' AND courseid = :courseid';
    $params['courseid'] = $courseid;
}

$total = $DB->count_records_select('local_ai_course_assistant_chunks', $where, $params);
$done = $DB->count_records_select('local_ai_course_assistant_chunks',
    $where . ' AND embedding_bin IS NOT NULL', $params);
$todo = $total - $done;

cli_writeln("chunks with a JSON embedding : {$total}");
cli_writeln("already converted            : {$done}");
cli_writeln("outstanding                  : {$todo}");

if ($options['verify']) {
    cli_writeln('');
    cli_writeln('Verifying converted rows...');
    $rs = $DB->get_recordset_select('local_ai_course_assistant_chunks',
        $where . ' AND embedding_bin IS NOT NULL', $params, 'id', 'id, embedding, embedding_bin');
    $checked = 0;
    $bad = 0;
    foreach ($rs as $row) {
        $checked++;
        $fromjson = json_decode($row->embedding, true);
        $frombin = rag_retriever::decode_vector($row->embedding_bin, null);
        if (!is_array($fromjson) || count($fromjson) !== count($frombin)) {
            $bad++;
            cli_writeln("  MISMATCH length on chunk {$row->id}");
            continue;
        }
        foreach ($fromjson as $i => $v) {
            // float32 round trip must be exact for these vectors; anything
            // else means the blob is corrupt, not merely imprecise.
            if ((float) $v !== (float) $frombin[$i]) {
                $bad++;
                cli_writeln("  MISMATCH value on chunk {$row->id} at index {$i}");
                break;
            }
        }
    }
    $rs->close();
    cli_writeln("verified {$checked} rows, {$bad} mismatched");
    exit($bad > 0 ? 1 : 0);
}

if ($options['dry-run']) {
    cli_writeln('');
    cli_writeln('Dry run: nothing written.');
    exit(0);
}

if ($todo === 0) {
    cli_writeln('');
    cli_writeln('Nothing to do.');
    exit(0);
}

cli_writeln('');
$converted = 0;
$skipped = 0;
$start = microtime(true);

// Re-query each batch rather than paging by offset: rows leave the candidate
// set as they are converted, so a moving offset would skip rows.
while (true) {
    $rows = $DB->get_records_select('local_ai_course_assistant_chunks',
        $where . ' AND embedding_bin IS NULL', $params, 'id', 'id, embedding', 0, $batch);
    if (empty($rows)) {
        break;
    }
    foreach ($rows as $row) {
        $vec = json_decode($row->embedding, true);
        if (!is_array($vec) || empty($vec)) {
            // Unparseable JSON: leave it alone and let retrieval's own
            // fallback deal with it, rather than writing a bogus blob.
            $skipped++;
            $DB->set_field('local_ai_course_assistant_chunks', 'embedding_bin', '', ['id' => $row->id]);
            continue;
        }
        $DB->set_field('local_ai_course_assistant_chunks', 'embedding_bin',
            rag_retriever::pack_vector($vec), ['id' => $row->id]);
        $converted++;
    }
    $pct = $todo > 0 ? round(($converted + $skipped) / $todo * 100) : 100;
    cli_writeln("  {$converted} converted, {$skipped} skipped ({$pct}%)");
}

$secs = microtime(true) - $start;
cli_writeln('');
cli_writeln(sprintf('Done: %d converted, %d skipped, %.1fs', $converted, $skipped, $secs));
cli_writeln('The JSON embedding column is left intact so this release can be');
cli_writeln('rolled back without a reindex.');
exit(0);
