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
 * Generate conversational RAG fixture questions for the judge benchmark.
 *
 * For each sampled indexed chunk in the given courses, asks an OpenAI chat
 * model to write one natural, multi-sentence student question that the chunk
 * answers. Writes a fixtures JSON ({"questions":[{id,courseid,question}]})
 * that run_rag_fixture_benchmark.php --judge --questions=... can consume.
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

list($options, $unrecognized) = cli_get_params(
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
Generate conversational RAG fixture questions (one per sampled chunk).

Options:
  --courses=CSV       Course ids to sample (default: 7,11)
  --per-course=N      Questions per course (default: 20)
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

$courses = array_values(array_filter(array_map(
    fn($c) => (int) trim($c), explode(',', (string) $options['courses']))));
$percourse = max(1, (int) $options['per-course']);
$model = (string) $options['model'];
$key = $options['apikey'] !== ''
    ? (string) $options['apikey']
    : (string) get_config('local_ai_course_assistant', 'embed_apikey');
if ($key === '') {
    cli_error('No OpenAI key: set the plugin embed_apikey or pass --apikey.');
}

$out = ['questions' => []];
foreach ($courses as $cid) {
    // Pull a pool a bit larger than needed so short/boilerplate chunks can be
    // skipped while still reaching the per-course target.
    $rows = $DB->get_records_select(
        'local_ai_course_assistant_chunks',
        'courseid = ? AND embedding IS NOT NULL',
        [$cid], 'id', 'id, content', 0, $percourse * 3);
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
        $question = local_ai_course_assistant_genconv_ask($key, $model, $prompt);
        if ($question !== '') {
            $out['questions'][] = [
                'id'       => sprintf('conv_%02d_%06d', $cid, (int) $r->id),
                'courseid' => $cid,
                'question' => $question,
            ];
            $n++;
            cli_write('.');
        }
    }
    cli_writeln(" course {$cid}: {$n}");
}

$outpath = ($options['out'][0] === '/')
    ? $options['out']
    : realpath(__DIR__ . '/../..') . '/' . $options['out'];
file_put_contents($outpath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
cli_writeln('Wrote ' . count($out['questions']) . " questions to {$outpath}");

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
