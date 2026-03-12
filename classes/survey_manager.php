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
 * Survey manager — handles survey CRUD, response storage, and aggregation.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class survey_manager {

    /** @var string Table name for surveys. */
    private const TABLE_SURVEYS = 'local_ai_course_assistant_surveys';

    /** @var string Table name for survey responses. */
    private const TABLE_RESPONSES = 'local_ai_course_assistant_survey_resp';

    /** @var array Default 5-question survey structure. */
    const DEFAULT_QUESTIONS = [
        [
            'type' => 'multiple_choice',
            'text' => 'How often did you use the AI tutor in this course?',
            'options' => ['Daily', 'Several times per week', 'Once per week', 'Less than once per week', 'Never'],
        ],
        [
            'type' => 'long_text',
            'text' => 'How did interacting with the AI tutor impact your motivation or confidence in completing the course?',
        ],
        [
            'type' => 'long_text',
            'text' => 'How did the AI tutor help you understand difficult ideas or lessons better?',
        ],
        [
            'type' => 'long_text',
            'text' => 'Was the AI tutor a helpful part of your learning? Do you have any ideas to make it more useful or more trustworthy?',
        ],
        [
            'type' => 'rating',
            'text' => 'Overall, how happy were you with the AI tutor?',
            'min' => 1,
            'max' => 5,
        ],
    ];

    /**
     * Get the active survey for a course.
     *
     * Checks for a course-level survey first, then falls back to the global default (courseid=0).
     *
     * @param int $courseid
     * @return object|null Survey record with decoded questions, or null if none found.
     */
    public static function get_active_survey(int $courseid): ?object {
        global $DB;

        // Try course-specific first.
        $survey = $DB->get_record(self::TABLE_SURVEYS, [
            'courseid' => $courseid,
            'active' => 1,
        ]);

        // Fall back to global default.
        if (!$survey) {
            $survey = $DB->get_record(self::TABLE_SURVEYS, [
                'courseid' => 0,
                'active' => 1,
            ]);
        }

        if (!$survey) {
            return null;
        }

        $survey->questions = json_decode($survey->questions, true);
        return $survey;
    }

    /**
     * Create a survey.
     *
     * Deactivates any other active survey for the same courseid first.
     *
     * @param int $courseid 0 for global default, or a specific course ID.
     * @param string $title Survey title.
     * @param array $questions Array of question definitions.
     * @return int The new survey ID.
     */
    public static function create_survey(int $courseid, string $title, array $questions): int {
        global $DB;

        $now = time();

        // Deactivate any existing active survey for this scope.
        $DB->set_field(self::TABLE_SURVEYS, 'active', 0, [
            'courseid' => $courseid,
            'active' => 1,
        ]);

        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->title = $title;
        $record->questions = json_encode($questions);
        $record->active = 1;
        $record->timecreated = $now;
        $record->timemodified = $now;

        return $DB->insert_record(self::TABLE_SURVEYS, $record);
    }

    /**
     * Update an existing survey.
     *
     * @param int $surveyid
     * @param string $title
     * @param array $questions
     * @param bool $active
     */
    public static function update_survey(int $surveyid, string $title, array $questions, bool $active): void {
        global $DB;

        $record = $DB->get_record(self::TABLE_SURVEYS, ['id' => $surveyid], '*', MUST_EXIST);

        // If activating this survey, deactivate others for the same scope.
        if ($active) {
            $DB->set_field(self::TABLE_SURVEYS, 'active', 0, [
                'courseid' => $record->courseid,
                'active' => 1,
            ]);
        }

        $record->title = $title;
        $record->questions = json_encode($questions);
        $record->active = $active ? 1 : 0;
        $record->timemodified = time();

        $DB->update_record(self::TABLE_SURVEYS, $record);
    }

    /**
     * Save a user's survey response.
     *
     * If the user has already responded to this survey in this course, the existing
     * answers are deleted and replaced.
     *
     * @param int $surveyid
     * @param int $userid
     * @param int $courseid
     * @param array $answers Array of ['question_index' => int, 'answer' => string].
     */
    public static function save_response(int $surveyid, int $userid, int $courseid, array $answers): void {
        global $DB;

        $now = time();

        // Delete any previous response (upsert behaviour).
        $DB->delete_records(self::TABLE_RESPONSES, [
            'surveyid' => $surveyid,
            'userid' => $userid,
            'courseid' => $courseid,
        ]);

        foreach ($answers as $answer) {
            $record = new \stdClass();
            $record->surveyid = $surveyid;
            $record->userid = $userid;
            $record->courseid = $courseid;
            $record->question_index = (int) $answer['question_index'];
            $record->answer = (string) $answer['answer'];
            $record->timecreated = $now;

            $DB->insert_record(self::TABLE_RESPONSES, $record);
        }
    }

    /**
     * Check whether a user has already responded to a survey in a course,
     * respecting the admin frequency setting (once / monthly / quarterly / unlimited).
     *
     * @param int $surveyid
     * @param int $userid
     * @param int $courseid
     * @return bool True if the user should NOT be shown the survey again.
     */
    public static function has_user_responded(int $surveyid, int $userid, int $courseid): bool {
        global $DB;

        $frequency = get_config('local_ai_course_assistant', 'survey_frequency') ?: 'once';

        // Unlimited means always allow re-taking.
        if ($frequency === 'unlimited') {
            return false;
        }

        // Find the most recent response from this user for this survey+course.
        $sql = "SELECT MAX(r.timecreated) AS latest
                  FROM {" . self::TABLE_RESPONSES . "} r
                 WHERE r.surveyid = :surveyid
                   AND r.userid = :userid
                   AND r.courseid = :courseid";
        $latest = $DB->get_field_sql($sql, [
            'surveyid' => $surveyid,
            'userid' => $userid,
            'courseid' => $courseid,
        ]);

        if (!$latest) {
            return false; // Never responded.
        }

        // "once" means never show again after first response.
        if ($frequency === 'once') {
            return true;
        }

        // Time-based frequencies.
        $intervals = [
            'monthly'   => 30 * 86400,
            'quarterly' => 90 * 86400,
        ];
        $interval = $intervals[$frequency] ?? 0;
        if ($interval === 0) {
            return true; // Unknown frequency, treat as "once".
        }

        // If enough time has passed since the last response, allow re-taking.
        return (time() - (int) $latest) < $interval;
    }

    /**
     * Get aggregated survey results for a course.
     *
     * @param int $courseid
     * @param int $since Only include responses created after this timestamp (0 = all).
     * @return array Aggregated results with total_responses and per-question breakdowns.
     */
    public static function get_survey_results(int $courseid, int $since = 0): array {
        global $DB;

        $survey = self::get_active_survey($courseid);
        if (!$survey) {
            return ['total_responses' => 0, 'questions' => []];
        }

        $params = ['surveyid' => $survey->id, 'courseid' => $courseid];
        $timeclause = '';
        if ($since > 0) {
            $timeclause = ' AND r.timecreated > :since';
            $params['since'] = $since;
        }

        // Count unique respondents.
        $sql = "SELECT COUNT(DISTINCT r.userid) AS cnt
                  FROM {" . self::TABLE_RESPONSES . "} r
                 WHERE r.surveyid = :surveyid
                   AND r.courseid = :courseid" . $timeclause;
        $totalresponses = (int) $DB->count_records_sql($sql, $params);

        // Fetch all response rows.
        $sql = "SELECT r.*
                  FROM {" . self::TABLE_RESPONSES . "} r
                 WHERE r.surveyid = :surveyid
                   AND r.courseid = :courseid" . $timeclause .
               " ORDER BY r.question_index, r.id";
        $rows = $DB->get_records_sql($sql, $params);

        // Group by question_index.
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->question_index][] = $row->answer;
        }

        $questions = [];
        foreach ($survey->questions as $idx => $qdef) {
            $answers = $grouped[$idx] ?? [];
            $result = [
                'question_index' => $idx,
                'text' => $qdef['text'],
                'type' => $qdef['type'],
                'response_count' => count($answers),
            ];

            switch ($qdef['type']) {
                case 'multiple_choice':
                    $counts = [];
                    $options = $qdef['options'] ?? [];
                    foreach ($options as $opt) {
                        $counts[$opt] = 0;
                    }
                    foreach ($answers as $a) {
                        if (isset($counts[$a])) {
                            $counts[$a]++;
                        } else {
                            $counts[$a] = 1;
                        }
                    }
                    $result['option_counts'] = $counts;
                    break;

                case 'rating':
                    $numericvals = array_map('floatval', $answers);
                    $result['average'] = count($numericvals) > 0
                        ? round(array_sum($numericvals) / count($numericvals), 2)
                        : 0;
                    $distribution = [];
                    $min = $qdef['min'] ?? 1;
                    $max = $qdef['max'] ?? 5;
                    for ($i = $min; $i <= $max; $i++) {
                        $distribution[$i] = 0;
                    }
                    foreach ($numericvals as $v) {
                        $iv = (int) $v;
                        if (isset($distribution[$iv])) {
                            $distribution[$iv]++;
                        }
                    }
                    $result['distribution'] = $distribution;
                    break;

                case 'long_text':
                    $result['answers'] = $answers;
                    break;
            }

            $questions[] = $result;
        }

        return [
            'total_responses' => $totalresponses,
            'questions' => $questions,
        ];
    }

    /**
     * Get raw survey responses for export (e.g. Redash).
     *
     * @param int $courseid
     * @param int $since Only include responses created after this timestamp (0 = all).
     * @return array Array of raw response records.
     */
    public static function get_survey_responses_raw(int $courseid, int $since = 0): array {
        global $DB;

        $survey = self::get_active_survey($courseid);
        if (!$survey) {
            return [];
        }

        $params = ['surveyid' => $survey->id, 'courseid' => $courseid];
        $timeclause = '';
        if ($since > 0) {
            $timeclause = ' AND r.timecreated > :since';
            $params['since'] = $since;
        }

        $sql = "SELECT r.id, r.surveyid, r.userid, r.courseid,
                       r.question_index, r.answer, r.timecreated
                  FROM {" . self::TABLE_RESPONSES . "} r
                 WHERE r.surveyid = :surveyid
                   AND r.courseid = :courseid" . $timeclause .
               " ORDER BY r.userid, r.question_index, r.id";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Ensure the global default survey exists.
     *
     * Creates one with DEFAULT_QUESTIONS if no active global survey is found.
     */
    public static function ensure_default_survey(): void {
        global $DB;

        $exists = $DB->record_exists(self::TABLE_SURVEYS, [
            'courseid' => 0,
            'active' => 1,
        ]);

        if (!$exists) {
            self::create_survey(0, 'SOLA End-of-Course Survey', self::DEFAULT_QUESTIONS);
        }
    }
}
