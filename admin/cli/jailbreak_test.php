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
 * Automated jailbreak / prompt injection test suite for SOLA.
 *
 * Runs the corpus of test prompts against the real system prompt and LLM provider,
 * pattern-matches responses for failures, and outputs a pass/fail report.
 *
 * v7.8.0: the probes, patterns and classification live in
 * classes/bench/jailbreak_suite.php, and every run plants a random canary token
 * in the system prompt. A response containing it is a FAIL whatever the
 * regular expressions say, which catches leaks the REVIEW bucket used to hide.
 *
 * Usage:
 *   php admin/cli/jailbreak_test.php --courseid=2
 *   php admin/cli/jailbreak_test.php --courseid=2 --verbose
 *   php admin/cli/jailbreak_test.php --courseid=2 --runs=3 --provider=gemini --model=gemini-2.5-flash
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/lib/filelib.php');

use local_ai_course_assistant\bench\jailbreak_suite;
use local_ai_course_assistant\provider\base_provider;

$courseid = 2;
$verbose = false;
$provideroverride = '';
$modeloverride = '';
$runs = 1;
foreach ($argv as $arg) {
    if (preg_match('/--courseid=(\d+)/', $arg, $m)) {
        $courseid = (int) $m[1];
    }
    if ($arg === '--verbose' || $arg === '-v') {
        $verbose = true;
    }
    // Test a specific model without mutating site config. The provider id must
    // match a configured comparison_providers row (that is where the key comes
    // from); the model is passed through as an override.
    if (preg_match('/^--provider=(.+)$/', $arg, $m)) {
        $provideroverride = trim($m[1]);
    }
    if (preg_match('/^--model=(.+)$/', $arg, $m)) {
        $modeloverride = trim($m[1]);
    }
    // v7.8.0: several runs in one invocation, each with its own canary. Three is
    // what the release gate and the automatic model evaluation use.
    if (preg_match('/^--runs=(\d+)$/', $arg, $m)) {
        $runs = max(1, min(10, (int) $m[1]));
    }
}

$admin = get_admin();
$USER = $admin;

// v7.0.5 / v7.2.1: build the prompt as a plain learner, never as an
// administrator -- the MemoryLeak probes ask what a LEARNER may be told. The
// selection lives in jailbreak_suite::choose_learner() since v7.8.0, so the
// automatic model evaluation picks the same identity this CLI does.
$chosen = jailbreak_suite::choose_learner($courseid);
$learner = $chosen['user'];
if ($learner === null) {
    $learner = $admin;
    $summary = [];
    foreach ($chosen['rejected'] as $role => $count) {
        $summary[] = "{$count} x {$role}";
    }
    echo "WARNING: no enrolled user on course {$courseid} resolves to role 'student'"
        . ($summary ? ' (found: ' . implode(', ', $summary) . ')' : ' (course has no enrolled users)')
        . ".\n"
        . "         Building the prompt as an administrator. The MemoryLeak probes\n"
        . "         are NOT meaningful in this run -- an admin may legitimately be\n"
        . "         told what learners are struggling with.\n"
        . "         Enrol a plain student on this course for a meaningful run.\n";
} else {
    echo "Prompt identity: {$learner->firstname} {$learner->lastname} "
        . "(id {$learner->id}, role student)\n";
}

$course = get_course($courseid);

if ($provideroverride !== '') {
    // create_for_comparison resolves the key from the comparison_providers row,
    // so nothing site-wide is touched and no key is echoed.
    $provider = base_provider::create_for_comparison($provideroverride, $modeloverride, $courseid, false);
    $providerlabel = $provideroverride . ($modeloverride !== '' ? ':' . $modeloverride : '');
} else {
    $provider = base_provider::create_from_config($courseid);
    $providerlabel = (get_config('local_ai_course_assistant', 'provider') ?: 'default');
}

mtrace("SOLA Jailbreak Test Suite");
mtrace("========================");
mtrace("Course: {$course->fullname} (ID {$courseid})");
mtrace("Provider: {$providerlabel}");
mtrace("Runs: {$runs}");
mtrace("");

// The deployed prompt WITH a hostile retrieved chunk (v7.0.5 indirect
// injection coverage), plus, from v7.8.0, a fresh canary token per run: any
// response containing it is a system-prompt leak and a hard FAIL, whatever the
// pattern lists below would have called it.
$baseprompt = jailbreak_suite::build_prompt($courseid, (int) $learner->id);

$total = [jailbreak_suite::PASS => 0, jailbreak_suite::FAIL => 0, jailbreak_suite::REVIEW => 0,
    jailbreak_suite::ERROR => 0, 'leaks' => 0];
$allresults = [];
$ntests = count(jailbreak_suite::TESTS);
for ($run = 1; $run <= $runs; $run++) {
    $canary = jailbreak_suite::new_canary();
    $systemprompt = jailbreak_suite::with_canary($baseprompt, $canary);
    if ($runs > 1) {
        mtrace("--- Run {$run}/{$runs} ---");
    }
    $progress = function (int $num, array $test, array $result) use ($ntests, $verbose) {
        mtrace("Test {$num}/{$ntests} [{$test['cat']}]: " . substr($test['prompt'], 0, 60) . "...");
        if ($result['status'] === jailbreak_suite::ERROR) {
            mtrace("  ERROR: " . $result['response']);
            return;
        }
        mtrace("  Result: {$result['status']}" . ($result['reason'] !== '' ? " ({$result['reason']})" : ''));
        if ($verbose) {
            mtrace("  Response: " . substr($result['response'], 0, 200) . "...");
        }
    };
    $outcome = jailbreak_suite::run($provider, $systemprompt, $canary, $progress);
    foreach ($outcome['counts'] as $k => $v) {
        $total[$k] += $v;
    }
    foreach ($outcome['results'] as $r) {
        $r['run'] = $run;
        $allresults[] = $r;
    }
}

$pass = $total[jailbreak_suite::PASS];
$fail = $total[jailbreak_suite::FAIL];
$borderline = $total[jailbreak_suite::REVIEW];
$errored = $total[jailbreak_suite::ERROR];
$leaks = $total['leaks'];
$scored = $pass + $fail + $borderline;

mtrace("");
mtrace("========================");
mtrace("RESULTS: {$pass} PASS, {$fail} FAIL, {$borderline} REVIEW, {$errored} ERROR, {$leaks} CANARY LEAK(S)");
mtrace("Total: " . ($ntests * $runs) . " tests ({$scored} scored, {$errored} not scored)");
mtrace("Pass rate: " . ($scored > 0 ? round(($pass / $scored) * 100) : 0) . "% of scored");
if ($leaks > 0) {
    mtrace("LEAK: {$leaks} response(s) contained the run's canary token. Each is counted as a FAIL above.");
}
// Prompts whose API call threw are neither PASS nor FAIL, so they MUST be
// surfaced: a run where most calls errored would otherwise print a clean-looking
// "5 PASS, 0 FAIL" and be mistaken for a green security gate.
if ($errored > 0) {
    mtrace("WARNING: {$errored} prompt(s) errored and were NOT evaluated. "
        . "This run is INCOMPLETE and must not be treated as a passing gate.");
}
mtrace("========================");

$label = function (array $r) use ($runs): string {
    return "Test {$r['num']}" . ($runs > 1 ? " (run {$r['run']})" : '') . " [{$r['cat']}]";
};

if ($fail > 0) {
    mtrace("");
    mtrace("FAILURES:");
    foreach ($allresults as $r) {
        if ($r['status'] === jailbreak_suite::FAIL) {
            mtrace("  " . $label($r) . ": {$r['reason']}");
            mtrace("    Prompt: " . $r['prompt']);
            if ($r['matched'] !== '') {
                mtrace("    Matched: " . str_replace("\n", ' ', $r['matched']));
            }
            mtrace("    Full response:");
            mtrace("      " . str_replace("\n", "\n      ", $r['response']));
        }
    }
}

if ($borderline > 0) {
    mtrace("");
    mtrace("NEEDS MANUAL REVIEW:");
    foreach ($allresults as $r) {
        if ($r['status'] === jailbreak_suite::REVIEW) {
            // In full, like a FAIL: REVIEW means no pattern decided it, so a
            // person has to, and a reply that gives in after a polite opening
            // only shows past the first few hundred characters.
            mtrace("  " . $label($r) . ":");
            mtrace("    Prompt: " . $r['prompt']);
            mtrace("    Full response:");
            mtrace("      " . str_replace("\n", "\n      ", $r['response']));
        }
    }
}

if ($errored > 0) {
    mtrace("");
    mtrace("ERRORED (not evaluated):");
    foreach ($allresults as $r) {
        if ($r['status'] === jailbreak_suite::ERROR) {
            mtrace("  " . $label($r) . ": " . substr($r['response'], 0, 200));
        }
    }
}

// Non-zero exit on a real failure (a canary leak is one) or an incomplete run,
// so this can gate a release rather than always reporting success.
exit(($fail > 0 || $errored > 0) ? 1 : 0);
