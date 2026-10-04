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

/**
 * Bounds for the transient-error retry loops, shared by chat and embeddings.
 *
 * Issue #295. Both loops read backend_retry_attempts and backend_retry_max_wait
 * and bounded each at one end only. With attempts mistyped as 20000 and a
 * provider returning 429 or 503, which is exactly the condition the setting
 * exists for, one request sat in usleep() for about 17 hours. usleep accrues no
 * CPU time, so max_execution_time never ended it, and the PHP-FPM worker and
 * its DB session stayed pinned. A handful of learners under a provider rate
 * limit would exhaust the pool. max_wait capped only a provider-supplied
 * Retry-After, so a large configured value let a misconfigured or hostile
 * provider ask for a single multi-hour sleep.
 *
 * The admin setting now refuses out-of-range input (admin_setting_bounded_int),
 * but a validator cannot protect a site whose value is already bad, or was set
 * by $CFG->forced_plugin_settings, an upgrade or a CLI set_config, none of which
 * run validate(). So both ends are clamped again here, and on top of that the
 * TOTAL time one call may spend waiting is capped, because even the bounded
 * maximum of ten attempts at sixty seconds each would still pin a worker for
 * ten minutes.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class retry_policy {

    /** @var int Fewest retries an admin may configure. */
    public const MIN_ATTEMPTS = 0;

    /** @var int Most retries an admin may configure. */
    public const MAX_ATTEMPTS = 10;

    /**
     * Retries when the setting is unset: none.
     *
     * Not the admin default of 2. settings.php registers nothing in a
     * sessionless CLI, so test and some CLI contexts run with the setting
     * genuinely unset, and both loops have always read that as 0 via
     * (int) false. Changing it is a behaviour change unrelated to #295, so it
     * is preserved here; a site configured through the admin UI gets 2.
     */
    public const DEFAULT_ATTEMPTS = 0;

    /** @var int Shortest Retry-After ceiling, in seconds. */
    public const MIN_WAIT = 0;

    /** @var int Longest Retry-After ceiling an admin may configure, in seconds. */
    public const MAX_WAIT = 60;

    /** @var int Retry-After ceiling when the setting is unset, in seconds. */
    public const DEFAULT_WAIT = 5;

    /**
     * Most time one call may spend waiting between retries, in seconds.
     *
     * The defaults (2 attempts, waits of 0.5s and 1.5s, or up to 5s each on a
     * Retry-After) stay far below this. It only bites on a configuration that
     * would otherwise hold a worker for minutes.
     */
    public const MAX_TOTAL_WAIT = 30.0;

    /**
     * The effective attempts and per-wait ceiling, clamped at both ends.
     *
     * @return array{attempts: int, maxwait: int}
     */
    public static function from_config(): array {
        $raw = get_config('local_ai_course_assistant', 'backend_retry_attempts');
        $attempts = ($raw === false || $raw === '') ? self::DEFAULT_ATTEMPTS : (int) $raw;

        $raw = get_config('local_ai_course_assistant', 'backend_retry_max_wait');
        $maxwait = ($raw === false || $raw === '') ? self::DEFAULT_WAIT : (int) $raw;

        return [
            'attempts' => max(self::MIN_ATTEMPTS, min(self::MAX_ATTEMPTS, $attempts)),
            'maxwait'  => max(self::MIN_WAIT, min(self::MAX_WAIT, $maxwait)),
        ];
    }

    /**
     * Whether one more wait of $wait seconds fits in the call's total budget.
     *
     * @param float $waited Seconds already spent waiting in this call.
     * @param float $wait The wait about to be taken.
     * @return bool
     */
    public static function within_budget(float $waited, float $wait): bool {
        return ($waited + $wait) <= self::MAX_TOTAL_WAIT;
    }
}
