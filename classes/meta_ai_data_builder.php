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
 * Builds anonymized, enriched context for the AI Analysis Chat and cron reports.
 *
 * Includes conversation transcripts with provider/model metadata, aggregate
 * statistics (feedback, token costs, provider comparison, daily usage),
 * and anonymized student learning profiles.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class meta_ai_data_builder {

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are an analytics assistant for an institution's AI learning assistant (SOLA).

You have access to:
1. Anonymized conversation transcripts between students and the AI tutor (with provider and model metadata per message)
2. Aggregate statistics: feedback ratings, token costs by provider, daily usage trends, provider comparison metrics
3. Anonymized student learning profiles (strengths, weaknesses, learning style)

Student names have been replaced with pseudonyms like "Student 4217."

Your job is to answer the admin's questions about patterns, themes, engagement, quality, cost, and provider performance based on the data provided. Be specific: cite student pseudonyms, course names, and provider names when relevant. If the data is insufficient to answer a question, say so.

Do not attempt to identify real students. Do not fabricate data.
PROMPT;

    /**
     * Build the full system prompt with all available data.
     *
     * @param array $courseids Array of course IDs (empty = all courses).
     * @param int $since Unix timestamp (0 = all time).
     * @param string $filterprovider Filter messages by this LLM provider (empty = all).
     * @param int $maxchars Max chars for transcript block.
     * @return string Complete system prompt.
     */
    public static function build_system_prompt(
        $courseids = [],
        int $since = 0,
        string $filterprovider = '',
        int $maxchars = 100000
    ): string {
        // Handle legacy single-int courseid parameter.
        if (is_int($courseids)) {
            $courseids = $courseids > 0 ? [$courseids] : [];
        }

        $parts = [self::SYSTEM_PROMPT];

        // Aggregate stats first (cheap, gives the LLM numerical context).
        $parts[] = "\n\n## Aggregate Statistics\n\n" . self::build_aggregate_stats($courseids, $since, $filterprovider);

        // Provider comparison.
        $parts[] = "\n\n## Provider Comparison\n\n" . self::build_provider_stats($courseids, $since);

        // Feedback summary.
        $parts[] = "\n\n## Feedback Summary\n\n" . self::build_feedback_stats($courseids, $since);

        // Student profiles (anonymized).
        $profiles = self::build_student_profiles($courseids);
        if (!empty($profiles)) {
            $parts[] = "\n\n## Student Learning Profiles\n\n" . $profiles;
        }

        // Transcripts with provider metadata (largest block, goes last).
        $parts[] = "\n\n## Conversation Transcripts\n\n" . self::build_transcript($courseids, $since, $filterprovider, $maxchars);

        return implode('', $parts);
    }

    /**
     * Build aggregate statistics.
     */
    public static function build_aggregate_stats(array $courseids = [], int $since = 0, string $filterprovider = ''): string {
        global $DB;

        list($wcl, $params) = self::build_where($courseids, $since, $filterprovider);

        $totalmsg = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {local_ai_course_assistant_msgs} m WHERE {$wcl}", $params);
        $totalusers = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT m.userid) FROM {local_ai_course_assistant_msgs} m WHERE {$wcl} AND m.role = 'user'", $params);
        $totalcourses = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT m.courseid) FROM {local_ai_course_assistant_msgs} m WHERE {$wcl}", $params);

        // Token cost totals.
        $tokensql = "SELECT SUM(COALESCE(m.prompt_tokens, 0)) AS prompt_total,
                            SUM(COALESCE(m.completion_tokens, 0)) AS completion_total
                       FROM {local_ai_course_assistant_msgs} m
                      WHERE {$wcl} AND m.role = 'assistant'";
        $tokens = $DB->get_record_sql($tokensql, $params);
        $prompttokens = (int) ($tokens->prompt_total ?? 0);
        $completiontokens = (int) ($tokens->completion_total ?? 0);

        // Daily active users (last 7 days).
        $weekago = time() - (7 * 86400);
        $dau = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT m.userid) FROM {local_ai_course_assistant_msgs} m
              WHERE {$wcl} AND m.role = 'user' AND m.timecreated >= :weekago",
            array_merge($params, ['weekago' => $weekago])
        );

        $lines = [];
        $lines[] = "Total messages: " . number_format($totalmsg);
        $lines[] = "Unique students: " . number_format($totalusers);
        $lines[] = "Active courses: " . number_format($totalcourses);
        $lines[] = "Active students (last 7 days): " . number_format($dau);
        $lines[] = "Total prompt tokens: " . number_format($prompttokens);
        $lines[] = "Total completion tokens: " . number_format($completiontokens);

        $scope = [];
        if (!empty($courseids)) {
            $scope[] = "Filtered to course IDs: " . implode(', ', $courseids);
        }
        if (!empty($filterprovider)) {
            $scope[] = "Filtered to provider: {$filterprovider}";
        }
        if ($since > 0) {
            $scope[] = "Since: " . userdate($since, '%Y-%m-%d');
        }
        if (!empty($scope)) {
            array_unshift($lines, "Scope: " . implode('. ', $scope));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Build provider comparison stats.
     */
    public static function build_provider_stats(array $courseids = [], int $since = 0): string {
        global $DB;

        list($wcl, $params) = self::build_where($courseids, $since);

        $sql = "SELECT m.provider, m.model_name,
                       COUNT(m.id) AS response_count,
                       SUM(COALESCE(m.prompt_tokens, 0)) AS prompt_tokens,
                       SUM(COALESCE(m.completion_tokens, 0)) AS completion_tokens,
                       AVG(COALESCE(m.tokens_used, 0)) AS avg_tokens
                  FROM {local_ai_course_assistant_msgs} m
                 WHERE {$wcl} AND m.role = 'assistant' AND m.provider IS NOT NULL AND m.provider != ''
                 GROUP BY m.provider, m.model_name
                 ORDER BY response_count DESC";

        $records = $DB->get_records_sql($sql, $params);
        if (empty($records)) {
            return "(No provider data available.)\n";
        }

        $lines = ["Provider | Model | Responses | Prompt Tokens | Completion Tokens | Avg Tokens/Response"];
        foreach ($records as $r) {
            $lines[] = sprintf("%s | %s | %s | %s | %s | %s",
                $r->provider ?: '(unknown)',
                $r->model_name ?: '(default)',
                number_format((int) $r->response_count),
                number_format((int) $r->prompt_tokens),
                number_format((int) $r->completion_tokens),
                number_format((float) $r->avg_tokens, 0)
            );
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Build feedback summary.
     */
    public static function build_feedback_stats(array $courseids = [], int $since = 0): string {
        global $DB;

        $params = [];
        $where = ['1=1'];
        if (!empty($courseids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'fc');
            $where[] = "f.courseid {$insql}";
            $params = array_merge($params, $inparams);
        }
        if ($since > 0) {
            $where[] = 'f.timecreated >= :fsince';
            $params['fsince'] = $since;
        }
        $wcl = implode(' AND ', $where);

        $total = $DB->count_records_sql("SELECT COUNT(*) FROM {local_ai_course_assistant_feedback} f WHERE {$wcl}", $params);
        if ($total == 0) {
            return "(No feedback data available.)\n";
        }

        $avg = $DB->get_field_sql("SELECT AVG(f.rating) FROM {local_ai_course_assistant_feedback} f WHERE {$wcl}", $params);

        $dist = $DB->get_records_sql(
            "SELECT f.rating, COUNT(*) AS cnt FROM {local_ai_course_assistant_feedback} f WHERE {$wcl} GROUP BY f.rating ORDER BY f.rating",
            $params
        );

        $lines = ["Total feedback submissions: {$total}"];
        $lines[] = "Average rating: " . round((float) $avg, 2) . " / 5";
        $lines[] = "Distribution:";
        for ($i = 1; $i <= 5; $i++) {
            $cnt = isset($dist[$i]) ? (int) $dist[$i]->cnt : 0;
            $lines[] = "  {$i} stars: {$cnt}";
        }

        // Recent comments (anonymized).
        $comments = $DB->get_records_sql(
            "SELECT f.userid, f.rating, f.comment, f.timecreated
               FROM {local_ai_course_assistant_feedback} f
              WHERE {$wcl} AND f.comment IS NOT NULL AND f.comment != ''
              ORDER BY f.timecreated DESC",
            $params, 0, 10
        );
        if (!empty($comments)) {
            $lines[] = "\nRecent comments:";
            foreach ($comments as $c) {
                $who = anonymizer::name((int) $c->userid);
                $date = userdate($c->timecreated, '%Y-%m-%d');
                $lines[] = "  [{$date}] {$who} ({$c->rating}/5): " . substr(trim($c->comment), 0, 200);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Build anonymized student profiles summary.
     */
    public static function build_student_profiles(array $courseids = []): string {
        global $DB;

        $params = [];
        $where = ['1=1'];
        if (!empty($courseids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'sp');
            $where[] = "p.courseid {$insql}";
            $params = array_merge($params, $inparams);
        }

        try {
            $profiles = $DB->get_records_sql(
                "SELECT p.userid, p.courseid, p.profile_summary, c.fullname AS coursename
                   FROM {local_ai_course_assistant_profiles} p
                   JOIN {course} c ON c.id = p.courseid
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY p.courseid, p.userid",
                $params, 0, 50
            );
        } catch (\Throwable $e) {
            return '';
        }

        if (empty($profiles)) {
            return '';
        }

        $lines = [];
        foreach ($profiles as $p) {
            $who = anonymizer::name((int) $p->userid);
            $lines[] = "{$who} ({$p->coursename}):";
            $lines[] = "  " . str_replace("\n", "\n  ", trim($p->profile_summary));
            $lines[] = "";
        }
        return implode("\n", $lines);
    }

    /**
     * Build anonymized transcript with provider/model metadata.
     */
    public static function build_transcript(
        $courseids = [],
        int $since = 0,
        string $filterprovider = '',
        int $maxchars = 100000
    ): string {
        global $DB;

        if (is_int($courseids)) {
            $courseids = $courseids > 0 ? [$courseids] : [];
        }

        list($wcl, $params) = self::build_where($courseids, $since, $filterprovider);

        $sql = "SELECT m.id, m.userid, m.role, m.message, m.courseid, m.timecreated,
                       m.provider, m.model_name,
                       c.fullname AS coursename
                  FROM {local_ai_course_assistant_msgs} m
                  JOIN {course} c ON c.id = m.courseid
                 WHERE {$wcl}
                 ORDER BY m.courseid ASC, m.userid ASC, m.timecreated ASC";

        $messages = $DB->get_records_sql($sql, $params);

        if (empty($messages)) {
            return "(No conversation data found for the selected filters.)\n";
        }

        $lines = [];
        $currentcourse = 0;
        $totalchars = 0;
        $truncated = false;

        foreach ($messages as $msg) {
            if ($msg->courseid !== $currentcourse) {
                $currentcourse = $msg->courseid;
                $header = "\n### Course: " . $msg->coursename . "\n";
                $totalchars += strlen($header);
                if ($totalchars > $maxchars) {
                    $truncated = true;
                    break;
                }
                $lines[] = $header;
            }

            if ($msg->role === 'user') {
                $who = anonymizer::name((int) $msg->userid);
            } else {
                $provlabel = $msg->provider ? " [{$msg->provider}" . ($msg->model_name ? "/{$msg->model_name}" : "") . "]" : "";
                $who = "SOLA{$provlabel}";
            }

            $date = userdate($msg->timecreated, '%Y-%m-%d %H:%M');
            $text = trim($msg->message);
            if (strlen($text) > 2000) {
                $text = substr($text, 0, 2000) . '... [truncated]';
            }

            $line = "[{$date}] {$who}: {$text}\n";
            $totalchars += strlen($line);
            if ($totalchars > $maxchars) {
                $truncated = true;
                break;
            }
            $lines[] = $line;
        }

        $result = implode('', $lines);
        if ($truncated) {
            $result .= "\n(Data truncated to ~" . number_format($maxchars) . " characters.)\n";
        }

        $total = count($messages);
        $result = "Total messages in transcript: {$total}\n" . $result;

        return $result;
    }

    /**
     * Build a summary stats block (lightweight, for backward compat).
     */
    public static function build_stats_summary($courseids = 0, int $since = 0): string {
        if (is_int($courseids)) {
            $courseids = $courseids > 0 ? [$courseids] : [];
        }
        return self::build_aggregate_stats($courseids, $since);
    }

    /**
     * Build WHERE clause + params from filter criteria.
     */
    private static function build_where(array $courseids = [], int $since = 0, string $filterprovider = ''): array {
        global $DB;

        $where = ['m.courseid > 1'];
        $params = [];

        if (!empty($courseids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
            $where[] = "m.courseid {$insql}";
            $params = array_merge($params, $inparams);
        }
        if ($since > 0) {
            $where[] = 'm.timecreated >= :since';
            $params['since'] = $since;
        }
        if (!empty($filterprovider)) {
            $where[] = 'm.provider = :filterprov';
            $params['filterprov'] = $filterprovider;
        }

        return [implode(' AND ', $where), $params];
    }
}
