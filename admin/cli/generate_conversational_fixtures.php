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
 * Generate conversational RAG fixtures (question + scoreable ground truth).
 *
 * For each sampled indexed chunk in the given courses, asks an OpenAI chat
 * model to write one natural, multi-sentence student question that the chunk
 * answers. Writes a fixtures JSON ({"fixtures":[{id,courseid,course,question,
 * expected_chunk_id,expected_substring}]}) that run_rag_fixture_benchmark.php
 * consumes for recall scoring, and also accepts via --judge --questions=...
 *
 * Every fixture carries BOTH a chunk id and a verbatim text anchor. The id is
 * the fast path; the anchor is what survives a reindex, which renumbers every
 * chunk. Fixtures written without an anchor become unrepairable the first time
 * their course is reindexed -- 189 of the 1,008 rows in the 2026-08-21 sets
 * were lost exactly that way. The anchor contract is enforced here rather than
 * left to the caller: see local_ai_course_assistant_genconv_anchor().
 *
 * This is the reproducible companion to tests/golden/rag_fixtures_bus101_pol101.json
 * (terse). Conversational phrasing is closer to real learner questions, on
 * which parent-document retrieval and rerank show a larger benefit (see the
 * 2026-07-21 RAG benchmark). Runs offline against indexed chunks; no students
 * involved. The OpenAI key is read from the plugin's embed_apikey unless
 * --apikey is given, and never printed.
 *
 * Usage:
 *   php generate_conversational_fixtures.php
 *   php generate_conversational_fixtures.php --courses=7,11 --per-course=20 \
 *       --out=tests/golden/rag_fixtures_conversational_bus101_pol101.json
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
global $DB, $CFG;
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'courses'    => '7,11',
        'per-course' => 20,
        'model'      => 'gpt-4o-mini',
        'apikey'     => '',
        'out'        => 'tests/golden/rag_fixtures_conversational.json',
        'help'       => false,
    ],
    ['h' => 'help']
);

if ($options['help']) {
    cli_writeln(<<<TXT
Generate conversational RAG fixtures (one per sampled chunk).

Each fixture carries a chunk id AND a verbatim text anchor unique in its first
50 bytes, so it stays scoreable after a reindex renumbers the chunks.

Options:
  --courses=CSV       Course ids to sample (default: 7,11)
  --per-course=N      Fixtures per course (default: 20)
  --model=NAME        OpenAI chat model (default: gpt-4o-mini)
  --apikey=KEY        OpenAI key (default: plugin embed_apikey)
  --out=PATH          Output JSON path, relative to the plugin root (default:
                      tests/golden/rag_fixtures_conversational.json)
  -h, --help          This help

Example:
  php generate_conversational_fixtures.php --courses=7,11 --per-course=20 \\
    --out=tests/golden/rag_fixtures_conversational_bus101_pol101.json
TXT
    );
    exit(0);
}

// The benchmark harness truncates a text anchor to its first 50 bytes before
// matching. An anchor must therefore be unique at THAT length, not merely as a
// whole string -- SOLA's chunks overlap, so a 50-byte span routinely appears in
// two or three neighbours, and a shorter-than-unique anchor silently credits a
// hit against the wrong chunk. Keep this in step with ANCHOR_MATCH_BYTES in
// run_rag_fixture_benchmark.php.
const GENCONV_ANCHOR_BYTES = 50;

$courses = array_values(array_filter(array_map(
    fn($c) => (int) trim($c),
    explode(',', (string) $options['courses'])
)));
$percourse = max(1, (int) $options['per-course']);
$model = (string) $options['model'];
$key = $options['apikey'] !== ''
    ? (string) $options['apikey']
    : (string) get_config('local_ai_course_assistant', 'embed_apikey');
if ($key === '') {
    cli_error('No OpenAI key: set the plugin embed_apikey or pass --apikey.');
}

$out = [
    'version'         => 1,
    'created'         => date('Y-m-d'),
    'description'     => 'Conversational RAG fixtures generated from indexed chunks.',
    'anchor_contract' => 'expected_substring is a verbatim substring of the chunk '
        . 'content whose first ' . GENCONV_ANCHOR_BYTES . ' bytes are unique within '
        . 'the course (that is the length the benchmark harness truncates to).',
    'fixtures'        => [],
];
$skippedanchor = 0;
foreach ($courses as $cid) {
    // Every chunk in the course, joined, so anchor uniqueness can be tested
    // against the same haystack the harness will search. Raw content, not
    // whitespace-normalised: the harness matches against what is in the column.
    $courseblob = implode("\x00", $DB->get_fieldset_select(
        'local_ai_course_assistant_chunks',
        'content',
        'courseid = :cid',
        ['cid' => $cid]
    ));

    // Pull a pool a bit larger than needed so short/boilerplate chunks can be
    // skipped while still reaching the per-course target.
    $rows = $DB->get_records_select(
        'local_ai_course_assistant_chunks',
        'courseid = ? AND (embedding IS NOT NULL OR embedding_bin IS NOT NULL)',
        [$cid],
        'id',
        'id, content',
        0,
        $percourse * 3
    );
    $n = 0;
    foreach ($rows as $r) {
        if ($n >= $percourse) {
            break;
        }
        $content = trim((string) $r->content);
        if (mb_strlen($content) < 200) {
            continue; // skip tiny/boilerplate chunks
        }
        $passage = mb_substr($content, 0, 1500);
        $prompt = "You are a student taking this course. Read the passage, then "
            . "write ONE natural, conversational question (two or three sentences, "
            . "in your own words, the way you'd actually ask a tutor) that this "
            . "passage answers. Do not quote or mention 'the passage'. Return only "
            . "the question.\n\nPASSAGE:\n" . $passage;
        // Derive the anchor BEFORE spending a chat call: a chunk with no unique
        // anchor cannot be scored on recall after a reindex, so it is not worth
        // generating a question for.
        $anchor = local_ai_course_assistant_genconv_anchor($content, $courseblob);
        if ($anchor === '') {
            $skippedanchor++;
            continue;
        }
        $question = local_ai_course_assistant_genconv_ask($key, $model, $prompt);
        if ($question !== '') {
            $out['fixtures'][] = [
                'id'                => sprintf('conv_%02d_%06d', $cid, (int) $r->id),
                'courseid'          => $cid,
                'course'            => 'course' . $cid,
                'question'          => $question,
                'expected_chunk_id' => (int) $r->id,
                'expected_substring' => $anchor,
            ];
            $n++;
            cli_write('.');
        }
    }
    cli_writeln(" course {$cid}: {$n}");
}

// A relative --out lands in dataroot, not in the plugin directory: dirroot is
// web-accessible and must stay read-only at runtime. An absolute path is the
// operator's explicit choice and is left alone.
$outpath = ($options['out'][0] === '/')
    ? $options['out']
    : make_writable_directory($CFG->dataroot . '/local_ai_course_assistant/runs')
        . '/' . basename($options['out']);
file_put_contents($outpath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
cli_writeln('Wrote ' . count($out['fixtures']) . " fixtures to {$outpath}");
if ($skippedanchor > 0) {
    cli_writeln("Skipped {$skippedanchor} chunk(s) with no anchor unique at "
        . GENCONV_ANCHOR_BYTES . ' bytes (usually near-duplicates of a neighbour).');
}

/**
 * Ask the OpenAI chat API for a single question. Returns '' on any error.
 *
 * @param string $key   OpenAI API key.
 * @param string $model Chat model name.
 * @param string $prompt
 * @return string The generated question, or '' on failure.
 */
function local_ai_course_assistant_genconv_ask(string $key, string $model, string $prompt): string {
    $body = json_encode([
        'model'       => $model,
        'messages'    => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.7,
        'max_tokens'  => 120,
    ]);
    try {
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_HTTPHEADER'     => ['Content-Type: application/json',
                'Authorization: Bearer ' . $key],
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT'        => 30,
        ]);
        $resp = $curl->post('https://api.openai.com/v1/chat/completions', $body);
        $data = json_decode($resp, true);
        return trim((string) ($data['choices'][0]['message']['content'] ?? ''));
    } catch (\Throwable $e) {
        return '';
    }
}

/**
 * Choose a text anchor for a chunk: a verbatim substring of its own content
 * whose first GENCONV_ANCHOR_BYTES bytes occur exactly once across the course.
 *
 * Verbatim matters because the harness matches against the raw content column,
 * so an anchor rebuilt from whitespace-normalised text will not be found.
 * Uniqueness is tested on the truncated prefix, not the whole candidate,
 * because the truncation is what the harness actually searches for.
 *
 * Candidates start on a word boundary and run to the end of a word, growing
 * until one is unique; a chunk that is a near-duplicate of its neighbour may
 * have none, which is reported rather than papered over.
 *
 * @param string $content Raw chunk content.
 * @param string $blob    Raw content of every chunk in the course, NUL-joined.
 * @return string The anchor, or '' if the chunk has no unique one.
 */
function local_ai_course_assistant_genconv_anchor(string $content, string $blob): string {
    $len = strlen($content);
    if ($len < GENCONV_ANCHOR_BYTES) {
        return '';
    }
    $starts = [0];
    for ($i = 1; $i < $len; $i++) {
        if (ctype_space($content[$i - 1]) && !ctype_space($content[$i])) {
            $starts[] = $i;
        }
    }
    foreach ($starts as $start) {
        foreach ([60, 70, 85, 100, 120] as $want) {
            if ($start + $want > $len) {
                continue;
            }
            $end = $start + $want;
            while ($end < $len && !ctype_space($content[$end])) {
                $end++; // Finish the word rather than cutting it.
            }
            $cand = rtrim(substr($content, $start, $end - $start));
            if (strlen($cand) < GENCONV_ANCHOR_BYTES) {
                continue;
            }
            if (substr_count($blob, substr($cand, 0, GENCONV_ANCHOR_BYTES)) === 1) {
                return $cand;
            }
        }
    }
    return '';
}
