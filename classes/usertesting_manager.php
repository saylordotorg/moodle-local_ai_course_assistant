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
 * User testing manager — handles task set CRUD, response storage, and aggregation.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usertesting_manager {
    /** @var string Table name for task sets. */
    private const TABLE_TASKS = 'local_ai_course_assistant_ut_tasks';

    /** @var string Table name for responses. */
    private const TABLE_RESPONSES = 'local_ai_course_assistant_ut_resp';

    /** @var array Default user testing tasks — useful out of the box. */
    const DEFAULT_TASKS = [
        [
            'type' => 'action_then_rate',
            'instruction' => 'Ask SOLA to explain a concept from your current course. After reading the response, rate how helpful it was.',
            'rating_label' => 'How helpful was the explanation?',
            'min' => 1,
            'max' => 5,
            'min_label' => 'Not helpful',
            'max_label' => 'Very helpful',
            'follow_up' => 'What would have made the explanation better?',
        ],
        [
            'type' => 'action_then_rate',
            'instruction' => 'Ask SOLA a follow up question about the same topic. Rate how well it built on the previous answer.',
            'rating_label' => 'How well did SOLA handle the follow up?',
            'min' => 1,
            'max' => 5,
            'min_label' => 'Poorly',
            'max_label' => 'Very well',
            'follow_up' => '',
        ],
        [
            'type' => 'action_then_rate',
            'instruction' => 'Try the Practice Quiz feature (click the Quiz tab at the bottom). Complete at least 3 questions, then come back here to rate the experience.',
            'rating_label' => 'How useful was the practice quiz?',
            'min' => 1,
            'max' => 5,
            'min_label' => 'Not useful',
            'max_label' => 'Very useful',
            'follow_up' => 'Any issues with the quiz questions or interface?',
        ],
        [
            'type' => 'action_then_rate',
            'instruction' => 'Ask SOLA something that is NOT related to your course (e.g. a recipe, celebrity gossip). Observe how it responds.',
            'rating_label' => 'Did SOLA handle the off topic question appropriately?',
            'min' => 1,
            'max' => 5,
            'min_label' => 'Poorly handled',
            'max_label' => 'Well handled',
            'follow_up' => '',
        ],
        [
            'type' => 'free_response',
            'instruction' => 'Think about your overall experience with SOLA. What is the most valuable thing it does for your learning?',
            'follow_up' => '',
        ],
        [
            'type' => 'free_response',
            'instruction' => 'What is one feature or improvement you wish SOLA had?',
            'follow_up' => '',
        ],
        [
            'type' => 'multiple_choice',
            'instruction' => 'How likely are you to recommend SOLA to a classmate?',
            'options' => ['Definitely would', 'Probably would', 'Not sure', 'Probably not', 'Definitely not'],
        ],
        [
            'type' => 'action_then_rate',
            'instruction' => 'Open the Settings panel (gear icon in the header). Try changing a setting such as your coaching style or language. Rate how easy it was to find and use.',
            'rating_label' => 'How easy was the settings panel to use?',
            'min' => 1,
            'max' => 5,
            'min_label' => 'Very confusing',
            'max_label' => 'Very easy',
            'follow_up' => 'Any settings you expected to find but did not?',
        ],
    ];

    /**
     * Get the active task set for a course.
     *
     * Checks for a course-level task set first, then falls back to global (courseid=0).
     *
     * @param int $courseid
     * @return object|null Task set record with decoded tasks, or null.
     */
    public static function get_active_taskset(int $courseid): ?object {
        global $DB;

        // Course-specific first.
        $taskset = $DB->get_record(self::TABLE_TASKS, [
            'courseid' => $courseid,
            'active' => 1,
        ]);

        // Fall back to global.
        if (!$taskset) {
            $taskset = $DB->get_record(self::TABLE_TASKS, [
                'courseid' => 0,
                'active' => 1,
            ]);
        }

        if (!$taskset) {
            return null;
        }

        $taskset->tasks = json_decode($taskset->tasks, true);
        return $taskset;
    }

    /**
     * Create a task set. Deactivates existing active sets for the same courseid.
     *
     * @param int $courseid 0 for global, or a specific course ID.
     * @param string $title
     * @param array $tasks
     * @param string $externalurl Optional external form URL.
     * @return int The new task set ID.
     */
    public static function create_taskset(int $courseid, string $title, array $tasks, string $externalurl = ''): int {
        global $DB;

        $now = time();

        $DB->set_field(self::TABLE_TASKS, 'active', 0, [
            'courseid' => $courseid,
            'active' => 1,
        ]);

        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->title = $title;
        $record->tasks = json_encode($tasks);
        $record->external_url = $externalurl;
        $record->active = 1;
        $record->timecreated = $now;
        $record->timemodified = $now;

        return $DB->insert_record(self::TABLE_TASKS, $record);
    }

    /**
     * Update an existing task set.
     *
     * @param int $tasksetid
     * @param string $title
     * @param array $tasks
     * @param string $externalurl
     * @param bool $active
     */
    public static function update_taskset(int $tasksetid, string $title, array $tasks, string $externalurl, bool $active): void {
        global $DB;

        $record = $DB->get_record(self::TABLE_TASKS, ['id' => $tasksetid], '*', MUST_EXIST);

        if ($active) {
            $DB->set_field(self::TABLE_TASKS, 'active', 0, [
                'courseid' => $record->courseid,
                'active' => 1,
            ]);
        }

        $record->title = $title;
        $record->tasks = json_encode($tasks);
        $record->external_url = $externalurl;
        $record->active = $active ? 1 : 0;
        $record->timemodified = time();

        $DB->update_record(self::TABLE_TASKS, $record);
    }

    /**
     * Save a single task response with session context.
     *
     * @param int $tasksetid
     * @param int $userid
     * @param int $courseid
     * @param int $taskindex
     * @param int|null $rating
     * @param string $answer
     * @param int $messagecount
     * @param int $sessionminutes
     */
    public static function save_response(
        int $tasksetid,
        int $userid,
        int $courseid,
        int $taskindex,
        ?int $rating,
        string $answer,
        int $messagecount,
        int $sessionminutes
    ): void {
        global $DB;

        $record = new \stdClass();
        $record->tasksetid = $tasksetid;
        $record->userid = $userid;
        $record->courseid = $courseid;
        $record->task_index = $taskindex;
        $record->rating = $rating;
        $record->answer = $answer;
        $record->message_count = $messagecount;
        $record->session_minutes = $sessionminutes;
        $record->timecreated = time();

        $DB->insert_record(self::TABLE_RESPONSES, $record);
    }

    /**
     * Get the number of tasks the user has completed in a task set for a course.
     *
     * @param int $tasksetid
     * @param int $userid
     * @param int $courseid
     * @return int Number of completed task indices.
     */
    public static function get_completed_count(int $tasksetid, int $userid, int $courseid): int {
        global $DB;

        $sql = "SELECT COUNT(DISTINCT r.task_index)
                  FROM {" . self::TABLE_RESPONSES . "} r
                 WHERE r.tasksetid = :tasksetid
                   AND r.userid = :userid
                   AND r.courseid = :courseid";
        return (int) $DB->count_records_sql($sql, [
            'tasksetid' => $tasksetid,
            'userid' => $userid,
            'courseid' => $courseid,
        ]);
    }

    /**
     * Get aggregated results for a task set in a course.
     *
     * @param int $courseid
     * @return array Results with total_respondents and per-task breakdowns.
     */
    public static function get_results(int $courseid): array {
        global $DB;

        $taskset = self::get_active_taskset($courseid);
        if (!$taskset) {
            return ['total_respondents' => 0, 'tasks' => []];
        }

        $params = ['tasksetid' => $taskset->id, 'courseid' => $courseid];

        // Count unique respondents.
        $sql = "SELECT COUNT(DISTINCT r.userid)
                  FROM {" . self::TABLE_RESPONSES . "} r
                 WHERE r.tasksetid = :tasksetid
                   AND r.courseid = :courseid";
        $total = (int) $DB->count_records_sql($sql, $params);

        // Fetch all rows.
        $sql = "SELECT r.*
                  FROM {" . self::TABLE_RESPONSES . "} r
                 WHERE r.tasksetid = :tasksetid
                   AND r.courseid = :courseid
                 ORDER BY r.task_index, r.id";
        $rows = $DB->get_records_sql($sql, $params);

        // Group by task_index.
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->task_index][] = $row;
        }

        $tasks = [];
        foreach ($taskset->tasks as $idx => $tdef) {
            $responses = $grouped[$idx] ?? [];
            $result = [
                'task_index' => $idx,
                'type' => $tdef['type'],
                'instruction' => $tdef['instruction'],
                'response_count' => count($responses),
            ];

            if ($tdef['type'] === 'action_then_rate') {
                $ratings = array_filter(array_map(function ($r) {
                    return $r->rating;
                }, $responses), function ($v) {
                    return $v !== null;
                });
                $result['avg_rating'] = count($ratings) > 0 ? round(array_sum($ratings) / count($ratings), 2) : 0;
                $result['comments'] = array_filter(array_map(function ($r) {
                    return $r->answer;
                }, $responses), function ($v) {
                    return $v !== '';
                });
            } else if ($tdef['type'] === 'free_response') {
                $result['answers'] = array_filter(array_map(function ($r) {
                    return $r->answer;
                }, $responses), function ($v) {
                    return $v !== '';
                });
            } else if ($tdef['type'] === 'multiple_choice') {
                $counts = [];
                foreach (($tdef['options'] ?? []) as $opt) {
                    $counts[$opt] = 0;
                }
                foreach ($responses as $r) {
                    if (isset($counts[$r->answer])) {
                        $counts[$r->answer]++;
                    } else {
                        $counts[$r->answer] = 1;
                    }
                }
                $result['option_counts'] = $counts;
            }

            // Session context averages.
            $msgcounts = array_map(function ($r) {
                return (int) $r->message_count;
            }, $responses);
            $sessionmins = array_map(function ($r) {
                return (int) $r->session_minutes;
            }, $responses);
            $result['avg_messages'] = count($msgcounts) > 0 ? round(array_sum($msgcounts) / count($msgcounts), 1) : 0;
            $result['avg_session_minutes'] = count($sessionmins) > 0 ? round(array_sum($sessionmins) / count($sessionmins), 1) : 0;

            $tasks[] = $result;
        }

        return [
            'total_respondents' => $total,
            'tasks' => $tasks,
        ];
    }

    /**
     * Ensure the global default task set exists.
     */
    public static function ensure_default_taskset(): void {
        global $DB;

        $exists = $DB->record_exists(self::TABLE_TASKS, [
            'courseid' => 0,
            'active' => 1,
        ]);

        if (!$exists) {
            self::create_taskset(0, 'SOLA Usability Test', self::DEFAULT_TASKS);
        }
    }
}
