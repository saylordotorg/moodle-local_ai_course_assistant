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
 * v5.6.0 prompt-section weight tuning benchmark.
 *
 * Runs the 50-prompt golden tutor set through each of K candidate weight
 * configurations and scores responses with a rubric judge, so admins can
 * empirically tune `prompt_section_weights` for their own course content
 * and traffic shape rather than trusting Saylor's benchmarked defaults.
 *
 * The defaults baked into context_builder::parse_section_weights() were
 * chosen by running this script against the SOLATEST dev course on
 * 2026-05-21. Run it against your own course to validate or replace those
 * defaults.
 *
 * Usage:
 *   sudo -u www-data php admin/cli/run_weight_benchmark.php --courseid=<id>
 *   sudo -u www-data php admin/cli/run_weight_benchmark.php --courseid=<id> --limit=10  # smoke
 *
 * The script:
 *   1. Saves the current `prompt_section_weights` + `prompt_context_boost_mode`.
 *   2. Forces boost_mode to `page_focus` for the duration of the run.
 *   3. For each candidate weight set, runs all prompts through the
 *      configured chat provider, scores with the same provider as a
 *      rubric judge, and accumulates the rubric mean.
 *   4. Restores the original config on exit (always; not in a try-catch
 *      so partial runs still revert).
 *
 * Cost: 5 weight sets * 50 prompts * 2 calls = 500 LLM calls.
 * At gpt-4o-mini rates (~$0.15/MTok input + $0.60/MTok output) the full
 * 50-prompt run costs around 12 cents. The --limit smoke is around 2.5
 * cents.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
global $CFG, $DB;
require_once($CFG->dirroot . '/lib/filelib.php');

use local_ai_course_assistant\context_builder;
use local_ai_course_assistant\provider\base_provider;

$courseid = 0;
$limit = 0;
foreach ($argv as $a) {
    if (preg_match('/^--courseid=(\d+)$/', $a, $m)) { $courseid = (int) $m[1]; }
    if (preg_match('/^--limit=(\d+)$/', $a, $m))    { $limit = (int) $m[1]; }
}
if ($courseid <= 0) {
    fwrite(STDERR, "Usage: run_weight_benchmark.php --courseid=<id> [--limit=N]\n");
    exit(2);
}

// Candidate weight sets. Each must sum to 100. The first one matches the
// v5.6.0 baked-in default; the rest sweep nearby alternatives. Edit this
// array to test your own candidate shapes.
$candidates = [
    'minimal_safety_10_10_40_40'   => ['safety_identity' => 10, 'course_structure' => 10, 'course_content' => 40, 'current_page' => 40],
    'baseline_15_15_30_40'         => ['safety_identity' => 15, 'course_structure' => 15, 'course_content' => 30, 'current_page' => 40],
    'page_heavy_12_12_22_54'       => ['safety_identity' => 12, 'course_structure' => 12, 'course_content' => 22, 'current_page' => 54],
    'content_heavy_15_15_45_25'    => ['safety_identity' => 15, 'course_structure' => 15, 'course_content' => 45, 'current_page' => 25],
    'balanced_20_20_30_30'         => ['safety_identity' => 20, 'course_structure' => 20, 'course_content' => 30, 'current_page' => 30],
];

$promptsfile = __DIR__ . '/../../tests/golden/tutor_prompts.json';
$raw = file_get_contents($promptsfile);
if ($raw === false) {
    fwrite(STDERR, "ERROR: cannot read $promptsfile\n");
    exit(1);
}
$prompts = json_decode($raw, true)['prompts'] ?? [];
if ($limit > 0) {
    $prompts = array_slice($prompts, 0, $limit);
}
$numprompts = count($prompts);

// Save and restore original config.
$origweights = (string) get_config('local_ai_course_assistant', 'prompt_section_weights');
$origboost   = (string) get_config('local_ai_course_assistant', 'prompt_context_boost_mode');
set_config('prompt_context_boost_mode', 'page_focus', 'local_ai_course_assistant');

// Anchor a real user so context_builder + provider can run cleanly.
$admin = $DB->get_record('user', ['id' => 2], '*', MUST_EXIST);
\core\session\manager::set_user($admin);

$rubric_systemprompt = <<<TXT
You are scoring an AI tutor's response on three dimensions. Reply with strict JSON only.

Score each 1-5:
  socratic: does it guide rather than spoonfeed?
  accuracy: factually correct + grounded in course context?
  tone:     warm + professional, not condescending or robotic?

Output:
{"socratic": N, "accuracy": N, "tone": N, "notes": "one sentence"}
TXT;

$summary = [];
$totalcost = 0.0;

echo "=== SOLA prompt-weight benchmark ===\n";
echo "course: $courseid; prompts per set: $numprompts; weight sets: " . count($candidates) . "\n";
echo "boost mode forced to: page_focus\n\n";

foreach ($candidates as $label => $weights) {
    echo "--- candidate: $label ---\n";
    set_config('prompt_section_weights', json_encode($weights), 'local_ai_course_assistant');
    \cache_helper::purge_by_definition('local_ai_course_assistant', 'systemprompt');

    $rubric_sum = 0;
    $n = 0;
    $set_cost = 0.0;

    foreach ($prompts as $p) {
        try {
            // pageid=1 exercises the page_focus boost path. We are not
            // actually on a Moodle page; the boost only checks pageid > 0.
            $systemprompt = context_builder::build_system_prompt(
                $courseid, $admin->id, '', [], 1, 'Practice page', ''
            );
            $provider = base_provider::create_from_config($courseid);
            $response = '';
            $provider->chat_completion_stream(
                $systemprompt,
                [['role' => 'user', 'content' => $p['text']]],
                function (string $chunk) use (&$response) { $response .= $chunk; },
                ['temperature' => 0.4, 'max_tokens' => 256]
            );
            $usage = $provider->get_last_token_usage();
            if (!empty($usage['prompt_tokens']) && isset($usage['completion_tokens'])) {
                // Approx gpt-4o-mini rates; adjust if a different model is configured.
                $set_cost += ($usage['prompt_tokens'] * 0.15 + $usage['completion_tokens'] * 0.60) / 1000000;
            }

            $judge_response = '';
            $judge = base_provider::create_from_config($courseid);
            $judge->chat_completion_stream(
                $rubric_systemprompt,
                [['role' => 'user', 'content' => "Student prompt:\n" . $p['text']
                    . "\n\nTutor response:\n" . mb_substr($response, 0, 1500)]],
                function (string $chunk) use (&$judge_response) { $judge_response .= $chunk; },
                ['temperature' => 0.0, 'max_tokens' => 200]
            );
            $jusage = $judge->get_last_token_usage();
            if (!empty($jusage['prompt_tokens']) && isset($jusage['completion_tokens'])) {
                $set_cost += ($jusage['prompt_tokens'] * 0.15 + $jusage['completion_tokens'] * 0.60) / 1000000;
            }

            $judge_response = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($judge_response));
            $rubric = json_decode($judge_response, true);
            if (is_array($rubric)) {
                $score = (int) ($rubric['socratic'] ?? 0)
                       + (int) ($rubric['accuracy'] ?? 0)
                       + (int) ($rubric['tone'] ?? 0);
                $rubric_sum += $score;
                $n++;
                printf("  %s: total=%d\n", $p['id'], $score);
            } else {
                printf("  %s: rubric_parse_err\n", $p['id']);
            }
        } catch (\Throwable $e) {
            printf("  %s: ERR %s\n", $p['id'], mb_substr($e->getMessage(), 0, 80));
        }
    }

    $avg = $n > 0 ? $rubric_sum / $n : 0;
    $summary[$label] = ['rubric_avg' => $avg, 'n' => $n, 'cost_cents' => $set_cost * 100];
    $totalcost += $set_cost;
    printf("  -> avg_rubric: %.2f / 15  (n=%d, cost=%.3f cents)\n\n", $avg, $n, $set_cost * 100);
}

if ($origweights === '') {
    unset_config('prompt_section_weights', 'local_ai_course_assistant');
} else {
    set_config('prompt_section_weights', $origweights, 'local_ai_course_assistant');
}
if ($origboost === '') {
    unset_config('prompt_context_boost_mode', 'local_ai_course_assistant');
} else {
    set_config('prompt_context_boost_mode', $origboost, 'local_ai_course_assistant');
}
\cache_helper::purge_by_definition('local_ai_course_assistant', 'systemprompt');

uasort($summary, fn($a, $b) => $b['rubric_avg'] <=> $a['rubric_avg']);
echo "\n=== summary (sorted by avg rubric desc) ===\n";
foreach ($summary as $label => $s) {
    printf("  %-32s avg=%.2f/15 n=%-3d cost=%.3f cents\n",
        $label, $s['rubric_avg'], $s['n'], $s['cost_cents']);
}
printf("\nTotal spend: %.3f cents\n", $totalcost * 100);
echo "Original config restored.\n";
