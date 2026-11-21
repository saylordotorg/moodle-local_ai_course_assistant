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

namespace local_ai_course_assistant\voice;

use local_ai_course_assistant\conversation_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Gemini Live voice sessions (8.2.0): the cost guardrails and the spend row.
 *
 * A Live session is billed per audio token for as long as it is open (very roughly $0.70 to $1.40 an hour, from
 * third-party sources), so three limits apply, each an admin setting with a safe default:
 *   - gemini_live_max_session_minutes: the browser ends the session at this length, and the token itself dies
 *     shortly after (gemini_live_token::EXPIRY_GRACE), so a modified browser can't hold it open longer;
 *   - gemini_live_idle_seconds: the browser ends a session in which nobody has spoken for this long;
 *   - gemini_live_daily_minutes: per learner per day (server time zone midnight). Counted on the server from the
 *     session rows written at mint time: a session the browser reported counts its reported length, one it never
 *     reported counts its full cap. A new session's cap is cut to what is left of the day.
 *
 * Each mint writes ONE system row in the msgs table, interaction type gemini_live (in the voice spend bucket),
 * which record_live_usage later fills with Google's token counts. The message text carries the session state:
 * "[Gemini Live session: open, cap 600s]" until the browser reports, then "[Gemini Live session: 312s]".
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gemini_live {
    /** @var string The msgs.interaction_type of a Live session row; voice_registry::interaction_type('gemini', 'live'). */
    public const INTERACTION_TYPE = 'gemini_live';

    /** @var int Default session cap in minutes. */
    public const DEFAULT_MAX_MINUTES = 10;

    /** @var int Default idle timeout in seconds. */
    public const DEFAULT_IDLE_SECONDS = 90;

    /** @var int Default daily minutes per learner (0 = no daily cap). */
    public const DEFAULT_DAILY_MINUTES = 30;

    /** @var int Bounds of the settings, also applied when reading them. */
    public const MAX_MINUTES_RANGE = [1, 30];

    /** @var int[] Idle timeout bounds in seconds. */
    public const IDLE_RANGE = [15, 600];

    /** @var int[] Daily minutes bounds. */
    public const DAILY_RANGE = [0, 600];

    /** @var int Shortest session worth starting, in seconds. Less than this left today refuses the mint. */
    public const MIN_SESSION_SECONDS = 60;

    /** @var int Highest token count accepted from the browser for one side of one session. */
    public const MAX_REPORTED_TOKENS = 5000000;

    /** @var string Prefix of every session row's message. */
    private const MSG_PREFIX = '[Gemini Live session: ';

    /**
     * Is Gemini Live switched on? Off by default: a Gemini voice row serves text to speech only until it is.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (bool) get_config('local_ai_course_assistant', 'gemini_live_enabled');
    }

    /**
     * A setting read as an int, defaulted when unset and clamped to its range.
     *
     * @param string $name
     * @param int $default
     * @param int[] $range [min, max]
     * @return int
     */
    private static function bounded(string $name, int $default, array $range): int {
        $raw = get_config('local_ai_course_assistant', $name);
        $value = ($raw === false || trim((string) $raw) === '' || !is_numeric($raw)) ? $default : (int) $raw;
        return max($range[0], min($range[1], $value));
    }

    /**
     * The session cap in seconds.
     *
     * @return int
     */
    public static function max_session_seconds(): int {
        return 60 * self::bounded('gemini_live_max_session_minutes', self::DEFAULT_MAX_MINUTES, self::MAX_MINUTES_RANGE);
    }

    /**
     * The idle timeout in seconds.
     *
     * @return int
     */
    public static function idle_seconds(): int {
        return self::bounded('gemini_live_idle_seconds', self::DEFAULT_IDLE_SECONDS, self::IDLE_RANGE);
    }

    /**
     * The daily allowance per learner in seconds, or 0 for none.
     *
     * @return int
     */
    public static function daily_seconds(): int {
        return 60 * self::bounded('gemini_live_daily_minutes', self::DEFAULT_DAILY_MINUTES, self::DAILY_RANGE);
    }

    /**
     * The seconds one session row counts against the day: its reported length, else its full cap.
     *
     * @param string $message The row's message text.
     * @return int
     */
    public static function counted_seconds(string $message): int {
        if (preg_match('~^\[Gemini Live session: (\d+)s\]$~', $message, $m)) {
            return (int) $m[1];
        }
        if (preg_match('~^\[Gemini Live session: open, cap (\d+)s\]$~', $message, $m)) {
            return (int) $m[1];
        }
        // A row this class didn't write the way it writes them: count the largest cap there can be.
        return 60 * self::MAX_MINUTES_RANGE[1];
    }

    /**
     * Seconds of Live voice this learner has used since midnight (server time zone).
     *
     * @param int $userid
     * @param int|null $now
     * @return int
     */
    public static function seconds_used_today(int $userid, ?int $now = null): int {
        global $DB;
        $now = $now ?? time();
        $midnight = usergetmidnight($now, \core_date::get_server_timezone());
        $rows = $DB->get_records_select(
            'local_ai_course_assistant_msgs',
            'userid = :userid AND interaction_type = :type AND role = :role AND timecreated >= :since',
            ['userid' => $userid, 'type' => self::INTERACTION_TYPE, 'role' => 'system', 'since' => $midnight],
            '',
            'id, message'
        );
        $total = 0;
        foreach ($rows as $row) {
            $total += self::counted_seconds((string) $row->message);
        }
        return $total;
    }

    /**
     * The cap for a new session: the session cap, cut to what is left of the daily allowance.
     *
     * Pure. Returns 0 when less than MIN_SESSION_SECONDS is left, which means: refuse.
     *
     * @param int $maxsession Session cap in seconds.
     * @param int $daily Daily allowance in seconds; 0 means no daily cap.
     * @param int $usedtoday Seconds used today.
     * @return int
     */
    public static function session_allowance(int $maxsession, int $daily, int $usedtoday): int {
        $allow = $maxsession;
        if ($daily > 0) {
            $allow = min($allow, $daily - $usedtoday);
        }
        return $allow >= self::MIN_SESSION_SECONDS ? $allow : 0;
    }

    /**
     * Write the session row at mint time. Returns its id, which the browser sends back with the usage.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $model
     * @param int $capseconds The cap this session was given.
     * @return int
     */
    public static function open_session(int $userid, int $courseid, string $model, int $capseconds): int {
        $conv = conversation_manager::get_or_create_conversation($userid, $courseid);
        return conversation_manager::add_message(
            (int) $conv->id,
            $userid,
            $courseid,
            'system',
            self::MSG_PREFIX . 'open, cap ' . $capseconds . 's]',
            0,
            'gemini',
            0,
            0,
            $model,
            \local_ai_course_assistant\voice_registry::interaction_type('gemini', 'live')
        );
    }

    /**
     * Clamp what the browser reported to what the server can believe. Pure.
     *
     * The length can't exceed the session's cap or the time since the row was written (plus a few seconds for
     * clocks); token counts are cut to [0, MAX_REPORTED_TOKENS].
     *
     * @param int $duration Reported seconds.
     * @param int $prompttokens Reported input tokens.
     * @param int $responsetokens Reported output tokens.
     * @param int $cap The session's cap in seconds.
     * @param int $elapsed Seconds since the row was written.
     * @return int[] [duration, prompt tokens, response tokens]
     */
    public static function clamp_usage(int $duration, int $prompttokens, int $responsetokens, int $cap,
            int $elapsed): array {
        $duration = max(0, min($duration, $cap, $elapsed + 5));
        $prompttokens = max(0, min($prompttokens, self::MAX_REPORTED_TOKENS));
        $responsetokens = max(0, min($responsetokens, self::MAX_REPORTED_TOKENS));
        return [$duration, $prompttokens, $responsetokens];
    }

    /**
     * Fill a session row with the browser's report, once. False when the row isn't this learner's open session.
     *
     * @param int $rowid
     * @param int $userid
     * @param int $duration
     * @param int $prompttokens
     * @param int $responsetokens
     * @param int|null $now
     * @return bool
     */
    public static function close_session(int $rowid, int $userid, int $duration, int $prompttokens,
            int $responsetokens, ?int $now = null): bool {
        global $DB;
        $now = $now ?? time();
        $row = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $rowid]);
        if (!$row || (int) $row->userid !== $userid || $row->interaction_type !== self::INTERACTION_TYPE
                || $row->role !== 'system'
                || !preg_match('~^\[Gemini Live session: open, cap (\d+)s\]$~', (string) $row->message, $m)) {
            return false;
        }
        [$duration, $prompttokens, $responsetokens] = self::clamp_usage(
            $duration, $prompttokens, $responsetokens, (int) $m[1], max(0, $now - (int) $row->timecreated));
        $update = (object) [
            'id' => $row->id,
            'message' => self::MSG_PREFIX . $duration . 's]',
            'prompt_tokens' => $prompttokens,
            'completion_tokens' => $responsetokens,
            'tokens_used' => $prompttokens + $responsetokens,
        ];
        $DB->update_record('local_ai_course_assistant_msgs', $update);
        return true;
    }
}
