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

namespace local_ai_course_assistant\task;

use local_ai_course_assistant\autoupgrade\budget;
use local_ai_course_assistant\autoupgrade\candidates;
use local_ai_course_assistant\autoupgrade\discovery;
use local_ai_course_assistant\autoupgrade\evaluator;
use local_ai_course_assistant\autoupgrade\roles;
use local_ai_course_assistant\autoupgrade\switcher;

/**
 * Daily model discovery, and the evaluations it queues (v7.8.0).
 *
 * Lists what each configured provider offers, registers new chat models with
 * their rate-card prices, marks candidates per role, and queues at most
 * MAX_EVALS_PER_RUN evaluations, so the monthly testing budget is spread
 * across the month rather than spent on the first night. A candidate one
 * pass short of eligibility goes first, then the thinking-off variant of the
 * current model, then the cheapest by list price.
 *
 * Does nothing at all when the mode is off.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discover_models extends \core\task\scheduled_task {
    /** @var int Evaluations queued per run, at most. */
    public const MAX_EVALS_PER_RUN = 2;

    /** @var string[] Roles in the order their candidates are evaluated. */
    public const PRIORITY = [roles::CHAT, roles::PREMIUM, roles::FAILOVER];

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:discover_models', 'local_ai_course_assistant');
    }

    /**
     * Run discovery and queue evaluations.
     *
     * @return void
     */
    public function execute() {
        if (switcher::mode() === switcher::MODE_OFF) {
            mtrace('Model discovery: automatic model upgrades are off.');
            return;
        }
        \core_php_time_limit::raise(300);
        $summary = discovery::live()->run();
        foreach ($summary['providers'] as $provider => $count) {
            mtrace("Model discovery: {$provider} lists {$count} model(s).");
        }
        foreach ($summary['errors'] as $provider => $error) {
            mtrace("Model discovery: {$provider} could not be listed: {$error}");
        }
        foreach ($summary['candidates'] as $role => $marked) {
            mtrace("Model discovery: {$role} candidates: " . ($marked ? implode(', ', $marked) : 'none'));
        }
        $queued = self::queue_due();
        mtrace('Model discovery: ' . count($queued) . ' evaluation(s) queued.');
    }

    /**
     * Queue the evaluations that are due, within the per-run cap.
     *
     * @param int|null $now
     * @return int[] Evaluation ids.
     */
    public static function queue_due(?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        evaluator::fail_stale($now);
        if (budget::remaining($now) <= 0) {
            return [];
        }
        $queued = [];
        foreach (self::PRIORITY as $role) {
            $current = roles::current($role);
            if (!$current['inuse'] || !$current['evaluable']) {
                continue;
            }
            foreach (self::ordered(candidates::for_role($role), $current) as $cand) {
                if (count($queued) >= self::MAX_EVALS_PER_RUN) {
                    return $queued;
                }
                if ($cand->provider !== $current['provider'] || !candidates::due($cand, $now)) {
                    continue;
                }
                if (
                    $DB->record_exists_select(
                        evaluator::TABLE,
                        'candidateid = :c AND status IN (:q, :r)',
                        ['c' => $cand->id, 'q' => evaluator::QUEUED, 'r' => evaluator::RUNNING]
                    )
                ) {
                    continue;
                }
                $queued[] = evaluator::queue((int) $cand->id, 0);
            }
        }
        return $queued;
    }

    /**
     * Candidates in evaluation order.
     *
     * @param \stdClass[] $rows
     * @param array $current
     * @return \stdClass[]
     */
    private static function ordered(array $rows, array $current): array {
        usort($rows, static function ($a, $b) use ($current) {
            $rank = static function ($r): int {
                if ($r->status === candidates::PASSED) {
                    return 0;
                }
                return $r->variant !== roles::VARIANT_DEFAULT ? 1 : 2;
            };
            if ($rank($a) !== $rank($b)) {
                return $rank($a) <=> $rank($b);
            }
            $pa = discovery::known_price($current['provider'], (string) $a->model);
            $pb = discovery::known_price($current['provider'], (string) $b->model);
            $ca = $pa ? discovery::turn_cost($pa) : INF;
            $cb = $pb ? discovery::turn_cost($pb) : INF;
            return $ca <=> $cb ?: ((int) $a->id <=> (int) $b->id);
        });
        return $rows;
    }
}
