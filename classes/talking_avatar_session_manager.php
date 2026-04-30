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

defined('MOODLE_INTERNAL') || die();

/**
 * CRUD + cost computation for the avatar session log.
 *
 * Three end paths converge on {@see end()}:
 *  1. Frontend heartbeat — posted from `ui.js` when the iframe is closed
 *     deliberately (close button, drawer close, beforeunload).
 *  2. Provider webhook — authoritative; overwrites a heartbeat row when
 *     the institution wires up vendor signing keys.
 *  3. Stale-session sweeper — caps abandoned sessions at the bundled
 *     `MAX_OPEN_SECONDS` so a tab-close does not leave a row open
 *     forever.
 *
 * `source` precedence: webhook > heartbeat > sweeper. Once a row is
 * marked `webhook` the other two paths leave it alone.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class talking_avatar_session_manager {

    /** Stale-session cap (1 hour). Matches Tavus's `max_call_duration` default. */
    public const MAX_OPEN_SECONDS = 3600;

    /** DB table name. */
    public const TABLE = 'local_ai_course_assistant_avatar_sess';

    /**
     * Open a session row when an avatar session is requested. Returns the
     * inserted id so the frontend can post heartbeats and webhooks can
     * correlate to the row.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $provider Provider key ('did', 'heygen', 'tavus', 'synthesia').
     * @param string $personaid Persona / avatar / replica / agent id (for analytics breakdown).
     * @param string $upstreamsessionid Upstream provider's session id (for webhook correlation).
     * @return int Row id.
     */
    public static function start(int $userid, int $courseid, string $provider, string $personaid, string $upstreamsessionid): int {
        global $DB;
        return (int) $DB->insert_record(self::TABLE, (object) [
            'userid'              => $userid,
            'courseid'            => $courseid,
            'provider'            => $provider,
            'persona_id'          => $personaid,
            'upstream_session_id' => $upstreamsessionid,
            'started_at'          => time(),
            'ended_at'            => null,
            'duration_sec'        => 0,
            'est_cost_usd'        => 0,
            'source'              => 'open',
        ]);
    }

    /**
     * Close a session row, computing duration + cost from the bundled
     * rate card. Idempotent and source-aware: webhook always wins; a
     * heartbeat will not overwrite a webhook; the sweeper only fires on
     * still-open rows.
     *
     * @param int $rowid Session row id.
     * @param string $source One of 'heartbeat', 'webhook', 'sweeper'.
     * @param int|null $endtime Override end time (provider webhooks may
     *                          report a precise upstream timestamp);
     *                          defaults to time().
     * @return bool True if the row was updated.
     */
    public static function end(int $rowid, string $source, ?int $endtime = null): bool {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['id' => $rowid]);
        if (!$row) {
            return false;
        }
        if ($row->source === 'webhook' && $source !== 'webhook') {
            return false;
        }
        if ($row->ended_at && $source === 'sweeper') {
            return false;
        }
        $endtime = $endtime ?? time();
        $duration = max(0, $endtime - (int) $row->started_at);
        if ($source === 'sweeper') {
            $duration = min($duration, self::MAX_OPEN_SECONDS);
            $endtime = (int) $row->started_at + $duration;
        }
        $row->ended_at = $endtime;
        $row->duration_sec = $duration;
        $row->est_cost_usd = talking_avatar_cost_manager::cost_for_session($row->provider, $duration);
        $row->source = $source;
        $DB->update_record(self::TABLE, $row);
        return true;
    }

    /**
     * Find a row by upstream session id (for webhook correlation). Returns
     * null when no matching row exists — webhook handlers should ignore
     * unknown ids rather than create new rows.
     *
     * @param string $provider
     * @param string $upstreamsessionid
     * @return \stdClass|null
     */
    public static function find_by_upstream(string $provider, string $upstreamsessionid): ?\stdClass {
        global $DB;
        $row = $DB->get_record(self::TABLE, [
            'provider' => $provider,
            'upstream_session_id' => $upstreamsessionid,
        ]);
        return $row ?: null;
    }

    /**
     * Close any open sessions older than {@see MAX_OPEN_SECONDS}. Called
     * from the scheduled task; idempotent.
     *
     * @return int Number of rows swept.
     */
    public static function sweep_stale(): int {
        global $DB;
        $cutoff = time() - self::MAX_OPEN_SECONDS;
        $rows = $DB->get_records_select(
            self::TABLE,
            'ended_at IS NULL AND started_at < :cutoff',
            ['cutoff' => $cutoff],
            '',
            'id'
        );
        $closed = 0;
        foreach ($rows as $row) {
            if (self::end((int) $row->id, 'sweeper')) {
                $closed++;
            }
        }
        return $closed;
    }

    /**
     * Aggregate minutes + cost grouped by provider over a date range.
     * Used by the token-analytics dashboard.
     *
     * @param int $from Unix start.
     * @param int $to Unix end.
     * @param int|null $courseid Optional course filter.
     * @return array<string, array{minutes: float, cost: float, sessions: int}>
     */
    public static function totals_by_provider(int $from, int $to, ?int $courseid = null): array {
        global $DB;
        $where = 'started_at >= :from AND started_at < :to';
        $params = ['from' => $from, 'to' => $to];
        if ($courseid !== null) {
            $where .= ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        $sql = "SELECT provider,
                       SUM(duration_sec) AS total_sec,
                       SUM(est_cost_usd) AS total_cost,
                       COUNT(*) AS sessions
                  FROM {" . self::TABLE . "}
                 WHERE $where
              GROUP BY provider";
        $rows = $DB->get_records_sql($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->provider] = [
                'minutes'  => round(((float) $r->total_sec) / 60.0, 1),
                'cost'     => round((float) $r->total_cost, 4),
                'sessions' => (int) $r->sessions,
            ];
        }
        return $out;
    }
}
