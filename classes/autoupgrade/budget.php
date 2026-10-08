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

namespace local_ai_course_assistant\autoupgrade;

use local_ai_course_assistant\model_capabilities;
use local_ai_course_assistant\model_registry;

/**
 * The monthly budget for automatic model testing (v7.8.0).
 *
 * A hard ceiling, default 10.00 USD per calendar month, on what evaluations
 * may spend: candidate and current model answers, both jailbreak runs, and the
 * judge. It is enforced BEFORE an evaluation starts, from a deliberately high
 * estimate (every answer is assumed to use its whole budget, thinking
 * headroom included), and the month's total is counted from the ACTUAL spend
 * each finished evaluation recorded. An evaluation that would take the month
 * over the ceiling does not start. A failed evaluation's partial spend counts.
 *
 * The calendar month is the site's server timezone, so "this month" on the
 * admin page means what an administrator reading it expects.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class budget {
    /** @var float Shipped monthly ceiling in USD. */
    public const DEFAULT_USD = 10.0;

    /** @var int Judge input tokens assumed per judgement (prompt + answer + rubric). */
    public const JUDGE_INPUT_TOKENS = 1500;

    /** @var int Judge output tokens assumed per judgement. */
    public const JUDGE_OUTPUT_TOKENS = 150;

    /**
     * The ceiling, from the setting.
     *
     * @return float
     */
    public static function limit(): float {
        $raw = get_config('local_ai_course_assistant', 'autoupgrade_budget_usd');
        if ($raw === false || trim((string) $raw) === '' || !is_numeric($raw)) {
            return self::DEFAULT_USD;
        }
        return max(0.0, (float) $raw);
    }

    /**
     * Start of the current calendar month.
     *
     * @param int|null $now
     * @return int
     */
    public static function month_start(?int $now = null): int {
        $tz = \core_date::get_server_timezone_object();
        $date = new \DateTime('@' . ($now ?? time()));
        $date->setTimezone($tz);
        $date->setDate((int) $date->format('Y'), (int) $date->format('m'), 1);
        $date->setTime(0, 0, 0);
        return $date->getTimestamp();
    }

    /**
     * What evaluations spent this calendar month, in USD.
     *
     * @param int|null $now
     * @return float
     */
    public static function spent(?int $now = null): float {
        global $DB;
        $sum = $DB->get_field_sql(
            'SELECT SUM(actual_cost_usd) FROM {' . evaluator::TABLE . '} WHERE timecreated >= :since',
            ['since' => self::month_start($now)]
        );
        return (float) ($sum ?: 0);
    }

    /**
     * What is left this month.
     *
     * @param int|null $now
     * @return float
     */
    public static function remaining(?int $now = null): float {
        return max(0.0, self::limit() - self::spent($now));
    }

    /**
     * A high estimate of what one evaluation will cost, in USD.
     *
     * Per side: the golden prompts with the system prompt the run will use and
     * the whole answer budget plus reasoning headroom as output; the jailbreak
     * runs at their 512-token budget plus headroom; and the judge for every
     * golden answer. A model with no price makes the estimate null, which
     * refuses the run: an evaluation that cannot be priced cannot be budgeted.
     *
     * @param array $sides Each: provider, model.
     * @param int $prompts Golden prompts per side.
     * @param int $systemtokens Estimated system prompt tokens.
     * @param int $maxtokens Answer budget.
     * @param array $judge provider, model.
     * @return float|null
     */
    public static function estimate(array $sides, int $prompts, int $systemtokens, int $maxtokens, array $judge): ?float {
        $total = 0.0;
        $jbcalls = count(\local_ai_course_assistant\bench\jailbreak_suite::TESTS) * gate::JAILBREAK_RUNS;
        foreach ($sides as $side) {
            $rate = model_registry::rate_for((string) $side['model']);
            if ($rate === null) {
                return null;
            }
            $profile = model_capabilities::profile((string) $side['provider'], (string) $side['model']);
            $headroom = !empty($profile['thinks']) ? model_capabilities::headroom($maxtokens) : 0;
            $jbheadroom = !empty($profile['thinks'])
                ? model_capabilities::headroom(\local_ai_course_assistant\bench\jailbreak_suite::MAX_TOKENS) : 0;
            $in = ($systemtokens + 100) * ($prompts + $jbcalls);
            $out = ($maxtokens + $headroom) * $prompts
                + (\local_ai_course_assistant\bench\jailbreak_suite::MAX_TOKENS + $jbheadroom) * $jbcalls;
            $total += ($in * (float) $rate['input'] + $out * (float) $rate['output']) / 1000000;
        }
        $jrate = model_registry::rate_for((string) $judge['model']);
        if ($jrate === null) {
            return null;
        }
        $judgecalls = $prompts * count($sides);
        $total += ($judgecalls * self::JUDGE_INPUT_TOKENS * (float) $jrate['input']
            + $judgecalls * self::JUDGE_OUTPUT_TOKENS * (float) $jrate['output']) / 1000000;
        return $total;
    }
}
