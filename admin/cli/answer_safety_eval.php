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
 * Measure the answer safety checks on the recorded fixtures (8.4.0).
 *
 * Runs tests/fixtures/answer_safety/*.json through the guidance rules and the claim check judge and prints the
 * false-alarm rate, the added latency and the checker cost per 1,000 answers. By default the judge is an offline
 * stub (no network, no spend, cost estimated at a price model's list price). A real provider runs only with --real.
 * Writes nothing to the database and never prints a key. The logic is classes/answer_safety_eval.php.
 *
 * Usage:
 *   php local/ai_course_assistant/admin/cli/answer_safety_eval.php
 *   php local/ai_course_assistant/admin/cli/answer_safety_eval.php --categories=grades,deadlines --json
 *   php local/ai_course_assistant/admin/cli/answer_safety_eval.php --real --limit=20
 *   php local/ai_course_assistant/admin/cli/answer_safety_eval.php --real --judge-provider=openai --judge-model=gpt-4o-mini
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
global $CFG;
require_once($CFG->dirroot . '/lib/clilib.php');
require_once($CFG->dirroot . '/lib/filelib.php');

use local_ai_course_assistant\answer_safety_eval;

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'real' => false, 'judge-provider' => '', 'judge-model' => '', 'courseid' => 0,
        'price-model' => '', 'categories' => '', 'limit' => 0, 'no-judge' => false, 'json' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error('Unknown option(s): ' . implode(', ', $unrecognised) . '. Try --help.');
}
if ($options['help']) {
    cli_writeln(
        "Measures the answer safety checks on the recorded fixtures: false-alarm rate, added latency and checker\n"
        . "cost per 1,000 answers. Writes nothing to the database and never prints a key.\n\n"
        . "  (no options)          every category, rules plus the offline stub judge (no network, no spend)\n"
        . "  --categories=a,b      only these categories (grades, deadlines, policy, wellbeing, advice,\n"
        . "                        integrity, numbers)\n"
        . "  --limit=N             only the first N answers\n"
        . "  --no-judge            rules only\n"
        . "  --price-model=NAME    the model the stub's cost estimate is priced at (default: the\n"
        . "                        answer_check_model setting, else gpt-4o-mini)\n"
        . "  --real                call a real provider for the judge. THIS COSTS MONEY: one call per answer.\n"
        . "                        Without --judge-provider/--judge-model it uses the site's claim check\n"
        . "                        provider (or the course chat provider). Spend is printed, not logged.\n"
        . "  --judge-provider=ID   with --real: a Providers and keys provider id\n"
        . "  --judge-model=NAME    with --real: the judge model\n"
        . "  --courseid=N          with --real: resolve the provider for this course (default: the site)\n"
        . "  --json                print the report as JSON\n"
        . "  -h, --help            this help"
    );
    exit(0);
}

$problem = answer_safety_eval::check_options($options);
if ($problem !== null) {
    cli_error($problem);
}

\core\session\manager::set_user(get_admin());

$categories = array_values(array_filter(array_map('trim', explode(',', (string) $options['categories']))));
try {
    $fixtures = answer_safety_eval::load_fixtures(null, $categories);
    $judge = answer_safety_eval::judge_for($options);
} catch (\Throwable $e) {
    cli_error('Could not start the evaluation (' . get_class($e) . '). Check --categories and the provider settings.');
}
if ($options['real']) {
    $count = (int) $options['limit'] > 0 ? min((int) $options['limit'], count($fixtures)) : count($fixtures);
    cli_writeln("Calling a real provider for {$count} answers.");
}

$report = answer_safety_eval::run($fixtures, $judge,
    ['judge' => !$options['no-judge'], 'limit' => (int) $options['limit']]);

if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    echo answer_safety_eval::render_text($report);
}
exit(0);
