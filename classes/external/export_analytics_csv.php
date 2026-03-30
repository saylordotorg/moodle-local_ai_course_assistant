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
use local_ai_course_assistant\analytics;

/**
 * Export analytics data as CSV.
 *
 * Admin-only endpoint that returns CSV-formatted analytics data
 * for the specified tab and filters.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export_analytics_csv extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'tab' => new external_value(PARAM_ALPHA, 'Analytics tab to export (overall, courses, comparison, units, usage_types, themes, feedback)'),
            'courseid' => new external_value(PARAM_INT, 'Course ID (0 = all courses)', VALUE_DEFAULT, 0),
            'since' => new external_value(PARAM_INT, 'Unix timestamp filter (0 = all time)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(string $tab, int $courseid = 0, int $since = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'tab' => $tab,
            'courseid' => $courseid,
            'since' => $since,
        ]);
        $tab = $params['tab'];
        $courseid = $params['courseid'];
        $since = $params['since'];

        // Admin-only.
        $syscontext = \context_system::instance();
        self::validate_context($syscontext);
        require_capability('moodle/site:config', $syscontext);

        $validtabs = ['overall', 'courses', 'comparison', 'units', 'usage_types', 'themes', 'feedback'];
        // Moodle's PARAM_ALPHA strips underscores, so also accept without them.
        $tabalias = [
            'usagetypes' => 'usage_types',
        ];
        if (isset($tabalias[$tab])) {
            $tab = $tabalias[$tab];
        }
        if (!in_array($tab, $validtabs, true)) {
            throw new \invalid_parameter_exception('Invalid tab: ' . $tab);
        }

        $csv = '';
        $filename = "sola_analytics_{$tab}";
        if ($courseid > 0) {
            $filename .= "_course{$courseid}";
        }
        $filename .= '_' . date('Ymd') . '.csv';

        switch ($tab) {
            case 'overall':
                $data = analytics::get_overview($courseid, $since);
                $csv = self::array_to_csv(['Metric', 'Value'], self::flatten_kv($data));
                break;

            case 'courses':
                $data = analytics::get_overview($courseid, $since);
                $csv = self::array_to_csv(['Metric', 'Value'], self::flatten_kv($data));
                break;

            case 'comparison':
                if ($courseid > 0) {
                    $data = analytics::get_provider_comparison($courseid, $since);
                    if (!empty($data)) {
                        $headers = array_keys($data[0]);
                        $rows = array_map('array_values', $data);
                        $csv = self::array_to_csv($headers, $rows);
                    }
                }
                break;

            case 'units':
                if ($courseid > 0) {
                    $data = analytics::get_hotspots($courseid, $since);
                    if (!empty($data)) {
                        $headers = array_keys($data[0]);
                        $rows = array_map('array_values', $data);
                        $csv = self::array_to_csv($headers, $rows);
                    }
                }
                break;

            case 'usage_types':
                if ($courseid > 0) {
                    $data = analytics::get_common_prompts($courseid, $since);
                    if (!empty($data)) {
                        $headers = array_keys($data[0]);
                        $rows = array_map('array_values', $data);
                        $csv = self::array_to_csv($headers, $rows);
                    }
                }
                break;

            case 'themes':
                if ($courseid > 0) {
                    $data = analytics::get_common_prompts($courseid, $since);
                    if (!empty($data)) {
                        $headers = array_keys($data[0]);
                        $rows = array_map('array_values', $data);
                        $csv = self::array_to_csv($headers, $rows);
                    }
                }
                break;

            case 'feedback':
                if (method_exists(analytics::class, 'get_message_rating_summary')) {
                    $data = analytics::get_message_rating_summary($courseid, $since);
                    $csv = self::array_to_csv(['Metric', 'Value'], self::flatten_kv($data));
                }
                break;
        }

        if (empty($csv)) {
            $csv = "No data available for the selected filters.\n";
        }

        return [
            'csv' => $csv,
            'filename' => $filename,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'csv' => new external_value(PARAM_RAW, 'CSV-formatted analytics data'),
            'filename' => new external_value(PARAM_RAW, 'Suggested filename for download'),
        ]);
    }

    /**
     * Convert headers and rows to a CSV string.
     *
     * @param array $headers Column headers.
     * @param array $rows Array of row arrays.
     * @return string CSV content.
     */
    private static function array_to_csv(array $headers, array $rows): string {
        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /**
     * Flatten an associative array into [key, value] rows.
     *
     * @param array $data Associative array.
     * @return array Array of [key, value] pairs.
     */
    private static function flatten_kv(array $data): array {
        $rows = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $rows[] = [$key, $value];
        }
        return $rows;
    }
}
