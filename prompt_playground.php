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
 * Admin prompt playground — assemble the system prompt with admin-supplied
 * simulated content and inspect the result.
 *
 * Lets an admin inject simulated retrieved chunks (and choose a course / page)
 * and see the exact assembled system prompt plus the per-section size
 * breakdown, so they can experiment with what different course content does to
 * the prompt without running a live chat turn. Admin-only (site:config); it
 * assembles as the current admin user, so it never exposes another learner's
 * data.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\context_builder;

require_login();
$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);

$courseid   = optional_param('courseid', 0, PARAM_INT);
$pageid     = optional_param('pageid', 0, PARAM_INT);
$simchunks  = optional_param('simchunks', '', PARAM_RAW);
$query      = optional_param('query', '', PARAM_RAW);
$go         = optional_param('go', 0, PARAM_INT);

$pageurl = new moodle_url('/local/ai_course_assistant/prompt_playground.php');
$PAGE->set_url($pageurl);
$PAGE->set_context($syscontext);
$PAGE->set_title('SOLA Prompt Playground');
$PAGE->set_heading('SOLA Prompt Playground');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
echo $OUTPUT->heading('SOLA Prompt Playground');

echo html_writer::div(
    'Assemble the system prompt and inspect the result. Two modes: (1) enter a '
    . '<strong>test question</strong> to run the REAL retriever and see exactly which chunks it '
    . 'selects for that question, with relevance scores — the way to verify chunk selection; or '
    . '(2) paste your own passages to simulate retrieved chunks. Pick a course (and optionally a '
    . 'current-page course-module id), then assemble. You see the chunks chosen, the exact prompt '
    . 'the model would receive, and the per-section size breakdown. Nothing is sent to any AI '
    . 'provider, and it assembles as you (an admin), so no learner data is exposed.',
    'text-muted mb-3'
);

// Input form.
echo '<form method="post" action="' . $pageurl->out(false) . '" class="mb-4">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
echo '<input type="hidden" name="go" value="1">';
echo '<div class="form-group mb-2"><label for="pp-courseid"><strong>Course id</strong></label>'
    . '<input type="number" id="pp-courseid" name="courseid" class="form-control" style="max-width:200px;" value="'
    . ($courseid ?: '') . '" min="1" required></div>';
echo '<div class="form-group mb-2"><label for="pp-pageid"><strong>Current page cmid</strong> (optional)</label>'
    . '<input type="number" id="pp-pageid" name="pageid" class="form-control" style="max-width:200px;" value="'
    . ($pageid ?: '') . '" min="0"></div>';
echo '<div class="form-group mb-2"><label for="pp-query"><strong>Test question (live RAG retrieval)</strong> '
    . '(optional — enter a learner question to run the real retriever and see the top chunks it selects, '
    . 'with scores; when set, these are used instead of the pasted chunks below)</label>'
    . '<input type="text" id="pp-query" name="query" class="form-control" value="' . s($query) . '"></div>';
echo '<div class="form-group mb-2"><label for="pp-chunks"><strong>Simulated retrieved chunks</strong> '
    . '(used only when no test question above; separate chunks with a line containing only <code>---</code>; '
    . 'leave both empty to assemble with RAG off)</label>'
    . '<textarea id="pp-chunks" name="simchunks" class="form-control" rows="8" '
    . 'style="font-family:monospace;font-size:13px;">' . s($simchunks) . '</textarea></div>';
echo '<button type="submit" class="btn btn-primary">Assemble prompt</button>';
echo '</form>';

if ($go && $courseid > 0) {
    require_sesskey();

    // Build the chunk set: a live RAG retrieval for the test question takes
    // precedence; otherwise the pasted simulated chunks.
    $chunks = [];
    $livemode = (trim($query) !== '');
    $ragerror = '';
    if ($livemode) {
        try {
            $topk = (int) (get_config('local_ai_course_assistant', 'rag_topk') ?: 5);
            $chunks = \local_ai_course_assistant\rag_retriever::retrieve(
                $courseid, $query, $topk, $pageid);
        } catch (\Throwable $e) {
            $ragerror = $e->getMessage();
        }
    } else if (trim($simchunks) !== '') {
        $chunks = \local_ai_course_assistant\prompt_debug::sim_chunks_from_text($simchunks);
    }

    // Show what the live retriever selected, so the admin can verify the
    // top-k chunk selection (the "5 best pieces") with scores and source.
    if ($livemode) {
        echo '<div class="card mb-3"><div class="card-body">';
        echo '<h4>Live RAG retrieval</h4>';
        echo '<p class="text-muted mb-2">Question: <em>' . s($query) . '</em></p>';
        if ($ragerror !== '') {
            echo $OUTPUT->notification('Retrieval failed: ' . s($ragerror)
                . ' (RAG must be enabled for the course and the course indexed).',
                \core\output\notification::NOTIFY_ERROR);
        } else if (empty($chunks)) {
            echo '<p class="text-muted">No chunks cleared the relevance floor for this question — '
                . 'an off-topic question, or the course is not indexed. The prompt below falls back '
                . 'to the page-and-structure behavior.</p>';
        } else {
            echo '<p class="mb-2">Selected <strong>' . count($chunks) . '</strong> chunk(s), ranked by relevance:</p>';
            echo '<table class="table table-sm mb-0"><thead><tr>'
                . '<th>#</th><th>Score</th><th>cmid</th><th>Type</th><th>Content</th></tr></thead><tbody>';
            foreach (array_values($chunks) as $i => $ch) {
                $score = isset($ch['score']) ? number_format((float) $ch['score'], 4) : 'n/a';
                echo '<tr><td>c:' . $i . '</td><td>' . $score . '</td>'
                    . '<td>' . (int) ($ch['cmid'] ?? 0) . '</td>'
                    . '<td>' . s((string) ($ch['modtype'] ?? '')) . '</td>'
                    . '<td><div style="max-height:140px;overflow:auto;font-size:12px;white-space:pre-wrap;">'
                    . s((string) ($ch['content'] ?? '')) . '</div></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div></div>';
    }

    try {
        $prompt = context_builder::build_system_prompt($courseid, $USER->id, '', $chunks, $pageid, '', '');
        $breakdown = context_builder::$last_breakdown;

        $chars  = strlen($prompt);
        $tokens = (int) round($chars / 4);

        echo '<div class="card mb-3"><div class="card-body">';
        echo '<h4>Result</h4>';
        echo '<p class="mb-2">' . ($livemode ? 'Live-retrieved' : 'Simulated')
            . ' chunks fed to the prompt: <strong>' . count($chunks) . '</strong>. '
            . 'Assembled prompt: <strong>' . number_format($chars) . '</strong> chars '
            . '(~' . number_format($tokens) . ' tokens).</p>';

        if (!empty($breakdown)) {
            echo '<h5>Per-section breakdown</h5>';
            echo '<pre style="background:#f6f8fa;padding:10px;border-radius:6px;font-size:13px;overflow:auto;">'
                . s(\local_ai_course_assistant\prompt\builder::format_breakdown($breakdown))
                . '</pre>';
        }
        echo '</div></div>';

        echo '<div class="card"><div class="card-body">';
        echo '<h5>Assembled system prompt</h5>';
        echo '<pre style="background:#f6f8fa;padding:10px;border-radius:6px;font-size:13px;'
            . 'white-space:pre-wrap;word-break:break-word;max-height:600px;overflow:auto;">'
            . s($prompt) . '</pre>';
        echo '</div></div>';
    } catch (\Throwable $e) {
        echo $OUTPUT->notification(
            'Could not assemble the prompt: ' . s($e->getMessage())
            . ' (check the course id exists).',
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

echo $OUTPUT->footer();
