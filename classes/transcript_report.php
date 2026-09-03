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
 * Anonymized chat transcript and summary reporting.
 *
 * v7.2.10. Filterable by course, unit (course section), topic, date range and
 * learning outcome. Two shapes: `transcripts` (one row per message) and
 * `summaries` (one row per conversation).
 *
 * Where each filter comes from, because only four of the five have a column:
 *   course      -> msgs.courseid
 *   unit        -> msgs.cmid, resolved to its section through modinfo
 *   date range  -> msgs.timecreated
 *   outcome     -> obj_att.msgid joins an objective attempt to the exact message,
 *                  and obj_att.iscorrect gives met / not met
 *   topic       -> NO backing column. Matched as text against msgs.message, with
 *                  the course keyword list offered as suggestions. Callers should
 *                  treat topic as a search, not a facet.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transcript_report {

    /** Hard ceiling on rows returned, so a wide filter cannot exhaust memory. */
    public const MAX_ROWS = 5000;

    /** Roles that are learner-visible conversation. Excludes role='system' telemetry. */
    public const CONVERSATION_ROLES = ['user', 'assistant'];

    /**
     * A per-report pseudonym for a learner.
     *
     * Deliberately NOT anonymizer::name(). That helper is
     * 'Student ' . (crc32('sola_anon_' . $userid) % 9999 + 1): a constant salt
     * in open-source code over a 9,999-label space, so labels are reversible by
     * brute force and they collide (two real user ids on this site render the
     * same label). Propagating it into a new export surface would bake a known
     * defect into exported files that leave the building.
     *
     * This uses a keyed HMAC over the site secret plus a per-report salt, so a
     * label is stable within one report -- you can follow a learner through a
     * conversation -- and carries no meaning across reports, and cannot be
     * walked back to a user id without the site secret.
     *
     * @param int $userid
     * @param string $salt Per-report salt; the same salt must be used throughout one report.
     * @return string
     */
    public static function pseudonym(int $userid, string $salt): string {
        $secret = get_site_identifier();
        $hash = hash_hmac('sha256', $salt . ':' . $userid, $secret);
        // 6 hex chars = ~16.7M label space, so collisions are negligible at
        // course scale rather than near-certain as with a 9,999 space.
        return 'Learner ' . strtoupper(substr($hash, 0, 6));
    }

    /**
     * Generate a fresh per-report salt.
     *
     * @return string
     */
    public static function new_salt(): string {
        return bin2hex(random_bytes(8));
    }

    /**
     * Escape one CSV cell against spreadsheet formula injection.
     *
     * A cell whose first character is = + - @ (or a tab/CR that Excel strips
     * back to one of those) is executed as a formula when the file is opened.
     * Learner-authored message text lands in these cells verbatim, so this is
     * reachable by anyone who can type into the chat box.
     *
     * @param string|null $value
     * @return string
     */
    public static function csv_cell(?string $value): string {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        if (preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Build the WHERE clause and parameters shared by both report shapes.
     *
     * @param array $filters courseid, section, topic, from, to, objectiveid, outcome
     * @return array [string $where, array $params]
     */
    private static function build_where(array $filters): array {
        global $DB;

        $where = ['m.courseid = :courseid'];
        $params = ['courseid' => (int) $filters['courseid']];

        [$rolesql, $roleparams] = $DB->get_in_or_equal(
            self::CONVERSATION_ROLES, SQL_PARAMS_NAMED, 'role');
        $where[] = "m.role {$rolesql}";
        $params += $roleparams;

        if (!empty($filters['from'])) {
            $where[] = 'm.timecreated >= :fromts';
            $params['fromts'] = (int) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'm.timecreated <= :tots';
            $params['tots'] = (int) $filters['to'];
        }

        // Unit: a section holds a set of cmids, resolved from modinfo rather
        // than from a column, because msgs records the cmid the learner was on.
        if (isset($filters['section']) && $filters['section'] !== '' && $filters['section'] !== null) {
            $cmids = self::cmids_in_section((int) $filters['courseid'], (int) $filters['section']);
            if (empty($cmids)) {
                // A real section with no modules must return nothing, not everything.
                $where[] = '1 = 0';
            } else {
                [$cmsql, $cmparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
                $where[] = "m.cmid {$cmsql}";
                $params += $cmparams;
            }
        }

        // Topic: text search, bound as a parameter. No LIKE metacharacter can
        // reach the SQL because sql_like() escapes and binds it.
        if (!empty($filters['topic'])) {
            $where[] = $DB->sql_like('m.message', ':topic', false, false);
            $params['topic'] = '%' . $DB->sql_like_escape($filters['topic']) . '%';
        }

        // Outcome: obj_att.msgid ties an attempt to the exact message.
        if (!empty($filters['objectiveid'])) {
            $where[] = 'EXISTS (SELECT 1 FROM {local_ai_course_assistant_obj_att} oa
                                 WHERE oa.msgid = m.id AND oa.objectiveid = :objid'
                     . (isset($filters['outcome']) && $filters['outcome'] !== ''
                         ? ' AND oa.iscorrect = :iscorrect' : '')
                     . ')';
            $params['objid'] = (int) $filters['objectiveid'];
            if (isset($filters['outcome']) && $filters['outcome'] !== '') {
                $params['iscorrect'] = (int) $filters['outcome'];
            }
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * cmids belonging to one section of a course.
     *
     * @param int $courseid
     * @param int $section
     * @return int[]
     */
    public static function cmids_in_section(int $courseid, int $section): array {
        $modinfo = get_fast_modinfo($courseid);
        $out = [];
        foreach ($modinfo->get_cms() as $cm) {
            if ((int) $cm->sectionnum === $section) {
                $out[] = (int) $cm->id;
            }
        }
        return $out;
    }

    /**
     * One row per message, anonymized.
     *
     * @param array $filters
     * @param string $salt
     * @param int $limit
     * @return array
     */
    public static function transcripts(array $filters, string $salt, int $limit = self::MAX_ROWS): array {
        global $DB;
        [$where, $params] = self::build_where($filters);
        $limit = max(1, min($limit, self::MAX_ROWS));

        $sql = "SELECT m.id, m.conversationid, m.userid, m.role, m.message, m.cmid,
                       m.interaction_type, m.timecreated
                  FROM {local_ai_course_assistant_msgs} m
                 WHERE {$where}
              ORDER BY m.conversationid ASC, m.timecreated ASC, m.id ASC";
        $rows = $DB->get_records_sql($sql, $params, 0, $limit);

        $sectionof = self::section_map((int) $filters['courseid']);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'conversation' => (int) $r->conversationid,
                'learner'      => self::pseudonym((int) $r->userid, $salt),
                'role'         => (string) $r->role,
                'unit'         => $r->cmid !== null ? ($sectionof[(int) $r->cmid] ?? '') : '',
                'type'         => (string) ($r->interaction_type ?? ''),
                'when'         => userdate((int) $r->timecreated, get_string('strftimedatetimeshort')),
                'message'      => (string) $r->message,
            ];
        }
        return $out;
    }

    /**
     * One row per conversation: counts, span, and the objectives it touched.
     *
     * @param array $filters
     * @param string $salt
     * @param int $limit
     * @return array
     */
    public static function summaries(array $filters, string $salt, int $limit = self::MAX_ROWS): array {
        global $DB;
        [$where, $params] = self::build_where($filters);
        $limit = max(1, min($limit, self::MAX_ROWS));

        $sql = "SELECT m.conversationid,
                       MIN(m.userid) AS userid,
                       COUNT(m.id) AS msgs,
                       MIN(m.timecreated) AS firstts,
                       MAX(m.timecreated) AS lastts
                  FROM {local_ai_course_assistant_msgs} m
                 WHERE {$where}
              GROUP BY m.conversationid
              ORDER BY MIN(m.timecreated) DESC";
        $rows = $DB->get_records_sql($sql, $params, 0, $limit);

        $out = [];
        foreach ($rows as $r) {
            $objs = $DB->get_fieldset_sql(
                "SELECT DISTINCT o.code
                   FROM {local_ai_course_assistant_obj_att} oa
                   JOIN {local_ai_course_assistant_objs} o ON o.id = oa.objectiveid
                   JOIN {local_ai_course_assistant_msgs} mm ON mm.id = oa.msgid
                  WHERE mm.conversationid = :cid
               ORDER BY o.code", ['cid' => (int) $r->conversationid]);
            $out[] = [
                'conversation' => (int) $r->conversationid,
                'learner'      => self::pseudonym((int) $r->userid, $salt),
                'messages'     => (int) $r->msgs,
                'first'        => userdate((int) $r->firstts, get_string('strftimedatetimeshort')),
                'last'         => userdate((int) $r->lastts, get_string('strftimedatetimeshort')),
                'outcomes'     => implode(' ', array_map('strval', $objs ?: [])),
            ];
        }
        return $out;
    }

    /**
     * cmid => section name, for labelling the unit column.
     *
     * @param int $courseid
     * @return array
     */
    private static function section_map(int $courseid): array {
        $modinfo = get_fast_modinfo($courseid);
        $out = [];
        foreach ($modinfo->get_cms() as $cm) {
            $out[(int) $cm->id] = get_section_name($courseid, $cm->sectionnum);
        }
        return $out;
    }

    /**
     * Render rows as CSV text, formula-escaped.
     *
     * @param array $rows
     * @return string
     */
    public static function to_csv(array $rows): string {
        if (empty($rows)) {
            return '';
        }
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'csv_cell'], array_values($row)));
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }
}
