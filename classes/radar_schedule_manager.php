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
 * CRUD layer for Learning Radar scheduled queries.
 *
 * Each row in {local_ai_course_assistant_radar_sched} captures one recurring
 * query: the prompt, provider/model selection, scope filters, schedule
 * frequency, and one or more delivery channels (email, Slack webhook, Teams
 * webhook). Replaces the v3.x single-schedule metaai_cron_* config.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class radar_schedule_manager {
    /** @var string[] Allowed frequency values. */
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    /** @var string[] Allowed delivery format values. */
    public const FORMATS = ['text', 'csv', 'json', 'markdown'];

    /**
     * Fetch all schedules ordered by name.
     *
     * @param bool $enabledonly If true, exclude disabled rows.
     * @return array
     */
    public static function all(bool $enabledonly = false): array {
        global $DB;
        $where = $enabledonly ? ['enabled' => 1] : [];
        return $DB->get_records('local_ai_course_assistant_radar_sched', $where, 'name ASC');
    }

    /**
     * Fetch a single schedule by id.
     *
     * @param int $id
     * @return \stdClass|null
     */
    public static function get(int $id): ?\stdClass {
        global $DB;
        $row = $DB->get_record('local_ai_course_assistant_radar_sched', ['id' => $id]);
        return $row ?: null;
    }

    /**
     * Create or update a schedule from validated input.
     *
     * @param array $data Already-sanitized input keyed by column name.
     * @param int $userid Admin doing the save (used as creator on insert).
     * @return int Schedule id.
     */
    public static function save(array $data, int $userid): int {
        global $DB;

        $now = time();
        $row = new \stdClass();
        $row->name = (string) ($data['name'] ?? 'Untitled schedule');
        $row->query = (string) ($data['query'] ?? '');
        $row->provider = (string) ($data['provider'] ?? '');
        $row->model = (string) ($data['model'] ?? '');
        $row->frequency = self::normalize_frequency((string) ($data['frequency'] ?? 'weekly'));
        $row->recipient_email = (string) ($data['recipient_email'] ?? '');
        $row->slack_webhook = (string) ($data['slack_webhook'] ?? '');
        $row->teams_webhook = (string) ($data['teams_webhook'] ?? '');
        $row->format = self::normalize_format((string) ($data['format'] ?? 'text'));
        $row->courseids = (string) ($data['courseids'] ?? '');
        $row->filterprovider = (string) ($data['filterprovider'] ?? '');
        $row->range_days = isset($data['range_days']) && $data['range_days'] !== ''
            ? (int) $data['range_days'] : null;
        $row->enabled = !empty($data['enabled']) ? 1 : 0;
        $row->timemodified = $now;

        $id = (int) ($data['id'] ?? 0);
        if ($id > 0 && $DB->record_exists('local_ai_course_assistant_radar_sched', ['id' => $id])) {
            $row->id = $id;
            $DB->update_record('local_ai_course_assistant_radar_sched', $row);
            return $id;
        }

        $row->creator = $userid;
        $row->timecreated = $now;
        return (int) $DB->insert_record('local_ai_course_assistant_radar_sched', $row);
    }

    /**
     * Delete a schedule.
     *
     * @param int $id
     * @return void
     */
    public static function delete(int $id): void {
        global $DB;
        $DB->delete_records('local_ai_course_assistant_radar_sched', ['id' => $id]);
    }

    /**
     * Update execution result columns after a cron run.
     *
     * @param int $id
     * @param string $status 'success' or 'error'.
     * @param string $error Error message (empty on success).
     * @return void
     */
    public static function record_run(int $id, string $status, string $error = ''): void {
        global $DB;
        $row = new \stdClass();
        $row->id = $id;
        $row->last_run = time();
        $row->last_status = in_array($status, ['success', 'error'], true) ? $status : 'error';
        $row->last_error = $error;
        $DB->update_record('local_ai_course_assistant_radar_sched', $row);
    }

    /**
     * Decide whether a schedule should run today based on its frequency.
     *
     * @param string $frequency
     * @return bool
     */
    public static function should_run_today(string $frequency): bool {
        $dow = (int) date('N');
        $dom = (int) date('j');
        switch ($frequency) {
            case 'daily':
                return true;
            case 'weekly':
                return $dow === 1;
            case 'monthly':
                return $dom === 1;
        }
        return false;
    }

    /**
     * Translate frequency into the date range the report should cover.
     *
     * @param string $frequency
     * @return int Days back from "now".
     */
    public static function frequency_to_days(string $frequency): int {
        switch ($frequency) {
            case 'daily':
                return 1;
            case 'weekly':
                return 7;
            case 'monthly':
                return 30;
        }
        return 7;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function normalize_frequency(string $value): string {
        return in_array($value, self::FREQUENCIES, true) ? $value : 'weekly';
    }

    /**
     * @param string $value
     * @return string
     */
    private static function normalize_format(string $value): string {
        return in_array($value, self::FORMATS, true) ? $value : 'text';
    }
}
