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

use local_ai_course_assistant\provider\base_provider;
use local_ai_course_assistant\provider\provider_interface;

/**
 * The answer safety evaluation (8.4.0): the logic of admin/cli/answer_safety_eval.php.
 *
 * Runs the recorded fixtures (tests/fixtures/answer_safety/<category>.json: known good answers, expect 'none', and
 * known bad ones) through the guidance rules exactly as answer_rules_test does (enforce mode, a held answer,
 * validators off) and through the claim checker's judge, and reports the three numbers the spec asks for before
 * enforcing: the false-alarm rate, the added latency and the checker cost per 1,000 answers.
 *
 * - False alarm (rules): a good answer on which any rule fired (any action but 'none'), which in watch mode is a
 *   review item for a teacher and in enforce mode a warning, retry or block. 'good_blocked' counts the blocks.
 * - Added latency: the in-request time of the rule check per answer (question categories plus evaluate()), which is
 *   all a learner waits for. The judge runs in a background task after the answer is delivered; its time is reported
 *   separately and is not added to the answer.
 * - Cost: the judge's token usage priced through token_cost_manager, per 1,000 checked answers. With the offline stub
 *   judge (the default) it is an estimate from the request size at the price model's list price.
 *
 * Nothing is written to the database: the judge is called through claim_checker::judge_with() with no spend hook,
 * and no audit row, message or conversation is created. No key is read here or reported; a real provider is built
 * only by judge_for() with 'real' set.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answer_safety_eval {

    /** @var string The default price model for the stub judge's estimate when no answer_check_model is set. */
    public const DEFAULT_PRICE_MODEL = 'gpt-4o-mini';

    /** @var string[] Options that select or point a real provider; refused without 'real'. */
    public const REAL_ONLY_OPTIONS = ['judge-provider', 'judge-model', 'courseid'];

    /**
     * The fixtures, all categories or the ones named, in answer_rules::CATEGORIES order.
     *
     * @param string|null $dir the fixture directory; null = tests/fixtures/answer_safety in this plugin
     * @param string[] $categories empty = all
     * @return array[] each {id, category, question, reply, expect, chunks?}
     * @throws \invalid_parameter_exception for an unknown category or an unreadable file
     */
    public static function load_fixtures(?string $dir = null, array $categories = []): array {
        $dir = $dir ?? dirname(__DIR__) . '/tests/fixtures/answer_safety';
        $unknown = array_diff($categories, answer_rules::CATEGORIES);
        if ($unknown) {
            throw new \invalid_parameter_exception('Unknown category: ' . implode(', ', $unknown));
        }
        $all = [];
        foreach (answer_rules::CATEGORIES as $category) {
            if ($categories && !in_array($category, $categories, true)) {
                continue;
            }
            $path = $dir . '/' . $category . '.json';
            $items = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (!is_array($items) || !array_is_list($items)) {
                throw new \invalid_parameter_exception('Fixture file missing or not a JSON list: ' . $category . '.json');
            }
            foreach ($items as $item) {
                if (is_array($item) && isset($item['id'], $item['question'], $item['reply'], $item['expect'])) {
                    $item['category'] = $item['category'] ?? $category;
                    $all[] = $item;
                }
            }
        }
        return $all;
    }

    /**
     * Why these options cannot run, or null when they can. A real provider (and anything that chooses one) needs
     * the explicit 'real' flag, and with it judge-provider and judge-model are given both or neither.
     *
     * @param array $options CLI options: real (bool), judge-provider, judge-model, courseid
     * @return string|null
     */
    public static function check_options(array $options): ?string {
        if (!empty($options['real'])) {
            $provider = trim((string) ($options['judge-provider'] ?? ''));
            $model = trim((string) ($options['judge-model'] ?? ''));
            if (($provider === '') !== ($model === '')) {
                return '--judge-provider and --judge-model go together: give both, or neither to use the site\'s '
                    . 'claim check provider.';
            }
            return null;
        }
        foreach (self::REAL_ONLY_OPTIONS as $name) {
            if (isset($options[$name]) && $options[$name] !== '' && $options[$name] !== false
                    && $options[$name] !== null && $options[$name] !== 0 && $options[$name] !== '0') {
                return "--{$name} chooses a real provider, which costs money. Add --real to use one, "
                    . 'or leave it out to run the offline stub judge.';
            }
        }
        return null;
    }

    /**
     * The judge to use: the offline stub unless 'real' is set; with 'real', judge-provider plus judge-model when both
     * are given, else the site's claim check provider for the course (claim_checker::judge_provider()).
     *
     * @param array $options as check_options(), plus price-model for the stub
     * @return provider_interface
     */
    public static function judge_for(array $options): provider_interface {
        if (empty($options['real'])) {
            $price = trim((string) ($options['price-model'] ?? ''));
            if ($price === '') {
                $price = trim((string) get_config('local_ai_course_assistant', 'answer_check_model'));
            }
            return new answer_safety_eval_stub_judge($price !== '' ? $price : self::DEFAULT_PRICE_MODEL);
        }
        $courseid = (int) ($options['courseid'] ?? 0) ?: SITEID;
        $providerid = trim((string) ($options['judge-provider'] ?? ''));
        $model = trim((string) ($options['judge-model'] ?? ''));
        if ($providerid !== '' && $model !== '') {
            return base_provider::create_for_comparison($providerid, $model, $courseid);
        }
        return claim_checker::judge_provider($courseid);
    }

    /**
     * Run the evaluation. Never throws for a judge failure (it is counted).
     *
     * @param array[] $fixtures from load_fixtures()
     * @param provider_interface $judge
     * @param array $options judge (bool, default true: call the judge), limit (int, 0 = all)
     * @return array the report: answers, good, bad, categories, rules {...}, judge {...}
     */
    public static function run(array $fixtures, provider_interface $judge, array $options = []): array {
        $limit = (int) ($options['limit'] ?? 0);
        if ($limit > 0) {
            $fixtures = array_slice($fixtures, 0, $limit);
        }
        $calljudge = !array_key_exists('judge', $options) || !empty($options['judge']);
        $stub = $judge instanceof answer_safety_eval_stub_judge;

        $report = ['answers' => 0, 'good' => 0, 'bad' => 0, 'categories' => [],
            'rules' => ['mode' => 'enforce', 'false_alarms' => 0, 'false_alarm_ids' => [], 'good_blocked' => 0,
                'known_limit_ids' => [], 'misses' => 0, 'miss_ids' => [], 'mismatches' => 0, 'mismatch_ids' => [], 'errors' => 0,
                'false_alarm_rate' => null, 'latency_ms' => null],
            'judge' => ['stub' => $stub, 'called' => $calljudge, 'calls' => 0, 'failed' => 0, 'model' => null,
                'false_alarms' => 0, 'false_alarm_ids' => [], 'flagged_bad' => 0, 'false_alarm_rate' => null,
                'unpriced' => 0, 'cost_total' => 0.0, 'cost_per_1000' => null, 'prompt_tokens_mean' => null,
                'completion_tokens_mean' => null, 'latency_ms' => null],
        ];
        $rulems = [];
        $judgems = [];
        $judgegood = 0;
        $priced = 0;
        $tokens = ['prompt' => 0, 'completion' => 0, 'n' => 0];

        foreach ($fixtures as $f) {
            $good = $f['expect'] === 'none';
            $report['answers']++;
            $report[$good ? 'good' : 'bad']++;
            $report['categories'][$f['category']] = ($report['categories'][$f['category']] ?? 0) + 1;
            $chunks = array_values(array_filter(array_map('strval', (array) ($f['chunks'] ?? [])),
                static fn(string $c): bool => trim($c) !== ''));

            $start = hrtime(true);
            $result = answer_rules::evaluate((string) $f['reply'], [
                'question' => (string) $f['question'],
                'categories' => answer_rules::categories_for_question((string) $f['question']),
                'chunks' => $chunks,
                'buffered' => true,
                'mode' => 'enforce',
                'validators_mode' => 'off',
            ]);
            $rulems[] = (hrtime(true) - $start) / 1e6;
            if ($result['error'] !== null) {
                $report['rules']['errors']++;
            }
            $action = (string) $result['action'];
            if ($good && $action !== 'none') {
                $report['rules']['false_alarms']++;
                $report['rules']['false_alarm_ids'][] = (string) $f['id'];
                if (isset($f['known_limit'])) {
                    $report['rules']['known_limit_ids'][] = (string) $f['id'];
                }
                if ($action === 'block') {
                    $report['rules']['good_blocked']++;
                }
            }
            if (!$good && $action === 'none') {
                $report['rules']['misses']++;
                $report['rules']['miss_ids'][] = (string) $f['id'];
            }
            if ($action !== $f['expect']) {
                $report['rules']['mismatches']++;
                $report['rules']['mismatch_ids'][] = (string) $f['id'];
            }

            if (!$calljudge) {
                continue;
            }
            $passages = [];
            foreach ($chunks as $i => $c) {
                $passages[] = ['ref' => 'fixture:' . ($i + 1), 'content' => $c];
            }
            $start = hrtime(true);
            $verdict = claim_checker::judge_with($judge, (string) $f['question'], (string) $f['reply'], $passages);
            $judgems[] = (hrtime(true) - $start) / 1e6;
            $report['judge']['calls']++;
            if ($verdict['status'] === 'check_failed') {
                $report['judge']['failed']++;
                continue;
            }
            if ($good) {
                $judgegood++;
            }
            if ($verdict['status'] === 'needs_review') {
                if ($good) {
                    $report['judge']['false_alarms']++;
                    $report['judge']['false_alarm_ids'][] = (string) $f['id'];
                } else {
                    $report['judge']['flagged_bad']++;
                }
            }
            if ($verdict['judge_model'] !== null) {
                $report['judge']['model'] = $verdict['judge_model'];
            }
            if ($verdict['judge_cost'] === null) {
                $report['judge']['unpriced']++;
            } else {
                $report['judge']['cost_total'] += (float) $verdict['judge_cost'];
                $priced++;
            }
            $usage = $judge->get_last_token_usage();
            if (is_array($usage)) {
                $tokens['prompt'] += (int) ($usage['prompt_tokens'] ?? 0);
                $tokens['completion'] += (int) ($usage['completion_tokens'] ?? 0);
                $tokens['n']++;
            }
        }

        $report['rules']['latency_ms'] = self::summary($rulems);
        if ($report['good'] > 0) {
            $report['rules']['false_alarm_rate'] = $report['rules']['false_alarms'] / $report['good'];
        }
        $report['judge']['latency_ms'] = $judgems ? self::summary($judgems) : null;
        if ($judgegood > 0) {
            $report['judge']['false_alarm_rate'] = $report['judge']['false_alarms'] / $judgegood;
        }
        if ($priced > 0 && $report['judge']['unpriced'] === 0) {
            $report['judge']['cost_per_1000'] = $report['judge']['cost_total'] / $priced * 1000;
        }
        if ($tokens['n'] > 0) {
            $report['judge']['prompt_tokens_mean'] = $tokens['prompt'] / $tokens['n'];
            $report['judge']['completion_tokens_mean'] = $tokens['completion'] / $tokens['n'];
        }
        return $report;
    }

    /**
     * Mean, median, 95th percentile (nearest rank) and max of a list of milliseconds.
     *
     * @param float[] $ms
     * @return array{n: int, mean: float, p50: float, p95: float, max: float}
     */
    private static function summary(array $ms): array {
        if (!$ms) {
            return ['n' => 0, 'mean' => 0.0, 'p50' => 0.0, 'p95' => 0.0, 'max' => 0.0];
        }
        sort($ms);
        $n = count($ms);
        $rank = static fn(float $p): float => $ms[max(0, (int) ceil($p * $n) - 1)];
        return ['n' => $n, 'mean' => array_sum($ms) / $n, 'p50' => $rank(0.5), 'p95' => $rank(0.95),
            'max' => $ms[$n - 1]];
    }

    /**
     * The report as plain text. Carries no question, answer or key text, only fixture ids and numbers.
     *
     * @param array $report from run()
     * @return string
     */
    public static function render_text(array $report): string {
        $pct = static fn(?float $r): string => $r === null ? 'n/a' : sprintf('%.1f%%', $r * 100);
        $r = $report['rules'];
        $j = $report['judge'];
        $lines = [];
        $lines[] = 'SOLA answer safety evaluation';
        $lines[] = sprintf('Answers: %d (%d known good, %d known bad). By category: %s', $report['answers'],
            $report['good'], $report['bad'], self::pairs($report['categories']));
        $lines[] = '';
        $lines[] = 'Guidance rules (enforce mode, held answer, validators off):';
        $lines[] = sprintf('  False-alarm rate: %s (%d of %d good answers flagged; %d blocked)',
            $pct($r['false_alarm_rate']), $r['false_alarms'], $report['good'], $r['good_blocked']);
        if ($r['false_alarm_ids']) {
            $lines[] = '    flagged: ' . implode(', ', $r['false_alarm_ids']);
        }
        if ($r['known_limit_ids']) {
            $lines[] = sprintf('    %d of these are a known limit (marked known_limit in the fixtures, not fixed yet)',
                count($r['known_limit_ids']));
        }
        $lines[] = sprintf('  Missed bad answers: %d of %d', $r['misses'], $report['bad']);
        if ($r['miss_ids']) {
            $lines[] = '    missed: ' . implode(', ', $r['miss_ids']);
        }
        $lines[] = sprintf('  Action different from expected: %d; rule errors: %d', $r['mismatches'], $r['errors']);
        $l = $r['latency_ms'];
        $lines[] = sprintf('  Added latency per answer (rule check, in the request): mean %.2f ms, p50 %.2f ms, '
            . 'p95 %.2f ms, max %.2f ms', $l['mean'], $l['p50'], $l['p95'], $l['max']);
        $lines[] = '';
        if (!$j['called']) {
            $lines[] = 'Claim check judge: not run (--no-judge).';
            return implode("\n", $lines) . "\n";
        }
        $lines[] = $j['stub']
            ? 'Claim check judge: offline stub (no network, no spend). It never flags, so its false-alarm rate is '
                . 'not a measurement; tokens and cost are an estimate from the request size.'
            : 'Claim check judge: real provider.';
        $lines[] = sprintf('  Calls: %d; failed (check_failed): %d; model: %s', $j['calls'], $j['failed'],
            $j['model'] ?? 'n/a');
        if (!$j['stub']) {
            $lines[] = sprintf('  Judge false-alarm rate: %s (%d good answers marked needs_review); bad answers '
                . 'flagged: %d', $pct($j['false_alarm_rate']), $j['false_alarms'], $j['flagged_bad']);
            if ($j['false_alarm_ids']) {
                $lines[] = '    flagged: ' . implode(', ', $j['false_alarm_ids']);
            }
        }
        if ($j['prompt_tokens_mean'] !== null) {
            $lines[] = sprintf('  Tokens per answer%s: %.0f in, %.0f out', $j['stub'] ? ' (estimate)' : '',
                $j['prompt_tokens_mean'], $j['completion_tokens_mean']);
        }
        if ($j['cost_per_1000'] === null) {
            $lines[] = sprintf('  Cost per 1,000 answers checked: unpriced (%d calls had no price for model %s)',
                $j['unpriced'], $j['model'] ?? 'n/a');
        } else {
            $lines[] = sprintf('  Cost per 1,000 answers checked%s: $%.4f (total for this run $%.6f)',
                $j['stub'] ? ' (estimate at ' . $j['model'] . ' list price)' : '', $j['cost_per_1000'],
                $j['cost_total']);
        }
        if (!$j['stub']) {
            $lines[] = '  Note: the cost excludes failed calls (check_failed). A judge false alarm is a known-good '
                . 'fixture (expect none) that the judge marked needs_review.';
        }
        if ($j['stub']) {
            $lines[] = '  Note: fixture answers and passages are short; a real answer with up to 8 retrieved passages '
                . 'sends several times more input, so read this as a floor.';
        }
        if ($j['latency_ms'] !== null) {
            $jl = $j['latency_ms'];
            $lines[] = sprintf('  Judge time per answer (background task, not added to the answer): mean %.1f ms, '
                . 'p95 %.1f ms', $jl['mean'], $jl['p95']);
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * "a 3, b 4".
     *
     * @param array $counts
     * @return string
     */
    private static function pairs(array $counts): string {
        $out = [];
        foreach ($counts as $k => $v) {
            $out[] = $k . ' ' . $v;
        }
        return implode(', ', $out);
    }
}
