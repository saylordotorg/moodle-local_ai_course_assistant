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

namespace local_ai_course_assistant\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_course_assistant\provider\base_provider;

/**
 * Generate AI-powered insights from user feedback and user testing data.
 *
 * Admin-only endpoint that sends aggregated feedback and user testing
 * results to the configured AI provider for analysis, returning
 * actionable suggestions about SOLA issues and feature requests.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_insights extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    public static function execute(int $courseid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
        ]);
        $courseid = $params['courseid'];

        // Admin-only.
        $syscontext = \context_system::instance();
        self::validate_context($syscontext);
        require_capability('moodle/site:config', $syscontext);

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        // Gather feedback data.
        $feedbackentries = $DB->get_records_sql(
            "SELECT f.id, f.rating, f.comment, f.browser, f.os, f.device, f.timecreated
               FROM {local_ai_course_assistant_feedback} f
              WHERE f.courseid = :courseid
              ORDER BY f.timecreated DESC",
            ['courseid' => $courseid],
            0, 100
        );

        $feedbacktext = '';
        if (!empty($feedbackentries)) {
            $feedbacktext = "## User Feedback (" . count($feedbackentries) . " entries)\n\n";
            foreach ($feedbackentries as $fb) {
                $date = userdate($fb->timecreated, '%Y-%m-%d');
                $comment = !empty($fb->comment) ? $fb->comment : '(no comment)';
                $feedbacktext .= "- Rating: {$fb->rating}/5 | Date: {$date} | "
                    . "Browser: {$fb->browser} | OS: {$fb->os} | Device: {$fb->device}\n"
                    . "  Comment: {$comment}\n\n";
            }
        }

        // Gather user testing data.
        $uttext = '';
        try {
            $utResults = \local_ai_course_assistant\usertesting_manager::get_results($courseid);
            if ($utResults['total_respondents'] > 0) {
                $uttext = "## User Testing Results ({$utResults['total_respondents']} respondents)\n\n";
                foreach ($utResults['tasks'] as $t) {
                    $uttext .= "### Task: {$t['instruction']}\n";
                    $uttext .= "Type: {$t['type']} | Responses: {$t['response_count']} | "
                        . "Avg messages: {$t['avg_messages']} | Avg session: {$t['avg_session_minutes']} min\n";

                    if ($t['type'] === 'action_then_rate' && isset($t['avg_rating'])) {
                        $uttext .= "Average rating: {$t['avg_rating']}/5\n";
                        if (!empty($t['comments'])) {
                            $uttext .= "Comments:\n";
                            foreach (array_slice($t['comments'], 0, 20) as $c) {
                                $uttext .= "  - {$c}\n";
                            }
                        }
                    }
                    if ($t['type'] === 'multiple_choice' && !empty($t['option_counts'])) {
                        $uttext .= "Responses:\n";
                        foreach ($t['option_counts'] as $label => $cnt) {
                            $uttext .= "  - {$label}: {$cnt}\n";
                        }
                    }
                    if ($t['type'] === 'free_response' && !empty($t['answers'])) {
                        $uttext .= "Answers:\n";
                        foreach (array_slice($t['answers'], 0, 20) as $a) {
                            $uttext .= "  - {$a}\n";
                        }
                    }
                    $uttext .= "\n";
                }
            }
        } catch (\Throwable $e) {
            // User testing tables may not exist.
        }

        // Gather survey data.
        $surveytext = '';
        try {
            $surveyResults = \local_ai_course_assistant\survey_manager::get_survey_results($courseid, 0);
            if ($surveyResults['total_responses'] > 0) {
                $surveytext = "## Survey Results ({$surveyResults['total_responses']} responses)\n\n";
                foreach ($surveyResults['questions'] as $q) {
                    $surveytext .= "### Q: {$q['text']}\n";
                    $surveytext .= "Type: {$q['type']} | Responses: {$q['response_count']}\n";

                    if ($q['type'] === 'multiple_choice' && !empty($q['option_counts'])) {
                        foreach ($q['option_counts'] as $label => $cnt) {
                            $surveytext .= "  - {$label}: {$cnt}\n";
                        }
                    }
                    if ($q['type'] === 'rating') {
                        $surveytext .= "Average: " . ($q['average'] ?? 'N/A') . "/5\n";
                    }
                    if ($q['type'] === 'long_text' && !empty($q['answers'])) {
                        foreach (array_slice($q['answers'], 0, 15) as $a) {
                            $surveytext .= "  - {$a}\n";
                        }
                    }
                    $surveytext .= "\n";
                }
            }
        } catch (\Throwable $e) {
            // Survey tables may not exist.
        }

        // Check we have data to analyze.
        if (empty($feedbacktext) && empty($uttext) && empty($surveytext)) {
            return [
                'success' => true,
                'insights' => get_string('insights:no_data', 'local_ai_course_assistant'),
            ];
        }

        // Build the AI prompt.
        $institution = get_config('local_ai_course_assistant', 'institution_name') ?: 'Saylor University';
        $systemprompt = "You are a product analyst for SOLA ({$institution} Online Learning Assistant), "
            . "an AI-powered learning coach embedded in Moodle course pages. "
            . "Analyze the following user feedback, survey responses, and user testing data "
            . "for the course \"{$course->fullname}\".\n\n"
            . "Provide a structured analysis with these sections:\n"
            . "1. **Key Issues** — Problems or pain points users are experiencing\n"
            . "2. **Feature Requests** — Features or improvements users are asking for (explicitly or implicitly)\n"
            . "3. **Positive Highlights** — What users like and what's working well\n"
            . "4. **Recommendations** — Specific, actionable suggestions for the SOLA team, prioritized by impact\n\n"
            . "Be concise and data-driven. Reference specific feedback when possible. "
            . "If a theme appears in multiple data sources, note that. "
            . "Format your response in Markdown.";

        $datablock = "";
        if (!empty($feedbacktext)) {
            $datablock .= $feedbacktext;
        }
        if (!empty($surveytext)) {
            $datablock .= $surveytext;
        }
        if (!empty($uttext)) {
            $datablock .= $uttext;
        }

        $messages = [
            ['role' => 'user', 'content' => $datablock],
        ];

        try {
            global $CFG;
            require_once($CFG->dirroot . '/lib/filelib.php');
            $provider = base_provider::create_from_config($courseid);
            $response = $provider->chat_completion($systemprompt, $messages);

            return [
                'success' => true,
                'insights' => $response,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'insights' => get_string('insights:error', 'local_ai_course_assistant') . ' ' . $e->getMessage(),
            ];
        }
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the analysis was successful'),
            'insights' => new external_value(PARAM_RAW, 'AI-generated insights in Markdown format'),
        ]);
    }
}
