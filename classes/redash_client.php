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
 * Push a Learning Radar query/response into Redash as a saved query.
 *
 * Redash's data model is pull-based: queries fetch from data sources on a
 * schedule. To make Learning Radar results visible in Redash without
 * forcing the admin to hand-build everything, this client creates a new
 * Redash query that targets the admin's pre-configured JSON data source
 * (which they pointed at our pull endpoint, redash_export.php). The query
 * body contains a YAML/JSON snippet plus the human-readable SOLA response
 * as a comment, so the admin sees the analysis context next to the chart.
 *
 * All three settings — redash_base_url, redash_user_api_key,
 * redash_data_source_id — must be configured. SSRF allowlist is enforced
 * on the base URL before any outbound POST.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class redash_client {

    /**
     * Whether all three Redash push settings are configured.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        $url = (string) (get_config('local_ai_course_assistant', 'redash_base_url') ?: '');
        $key = (string) (get_config('local_ai_course_assistant', 'redash_user_api_key') ?: '');
        $dsid = (int) (get_config('local_ai_course_assistant', 'redash_data_source_id') ?: 0);
        return $url !== '' && $key !== '' && $dsid > 0;
    }

    /**
     * Create a new Redash query from a Learning Radar query/response pair.
     *
     * @param string $name Human-readable query name.
     * @param string $query The original SOLA query.
     * @param string $response The LLM response text.
     * @param string $jsonpath Optional JSON path to pull from redash_export.php
     *                         (e.g., "learning_radar_queries"). Defaults to the
     *                         full export.
     * @return array {ok: bool, url?: string, error?: string}
     */
    public static function push_query(string $name, string $query, string $response, string $jsonpath = 'learning_radar_queries'): array {
        if (!self::is_configured()) {
            return ['ok' => false, 'error' => 'Redash push is not configured. Set redash_base_url, redash_user_api_key, and redash_data_source_id in plugin settings.'];
        }

        $baseurl = rtrim((string) get_config('local_ai_course_assistant', 'redash_base_url'), '/');
        if (!security::is_safe_provider_url($baseurl)) {
            return ['ok' => false, 'error' => 'Redash base URL rejected by SSRF allowlist.'];
        }

        $apikey = (string) get_config('local_ai_course_assistant', 'redash_user_api_key');
        $dsid = (int) get_config('local_ai_course_assistant', 'redash_data_source_id');

        // Build the Redash query body. For Redash's JSON data source, the
        // query body is YAML-ish: a `url:` line and an optional `path:` to
        // drill into the response. We prepend the SOLA query+response as a
        // YAML comment block so the admin sees the analytical context next
        // to the chart-generating SQL/URL.
        $pullurl = (new \moodle_url('/local/ai_course_assistant/redash_export.php', [
            'apikey' => get_config('local_ai_course_assistant', 'redash_api_key') ?: '',
        ]))->out(false);

        $commented = self::yaml_comment_block($query, $response);
        $body = $commented . "\nurl: " . $pullurl . "\npath: " . $jsonpath . "\n";

        $payload = [
            'data_source_id' => $dsid,
            'name' => mb_substr($name, 0, 250),
            'query' => $body,
            'description' => 'Created from SOLA Learning Radar on ' . userdate(time(), '%Y-%m-%d %H:%M'),
            'options' => new \stdClass(),
        ];

        global $CFG;
        require_once($CFG->dirroot . '/lib/filelib.php');
        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Key ' . $apikey,
            'Content-Type: application/json',
        ]);
        $endpoint = $baseurl . '/api/queries';
        $resp = $curl->post($endpoint, json_encode($payload));
        $code = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'Redash API returned HTTP ' . $code . ': ' . substr((string) $resp, 0, 400)];
        }

        $decoded = json_decode((string) $resp, true);
        if (!is_array($decoded) || empty($decoded['id'])) {
            return ['ok' => false, 'error' => 'Redash response did not include a query id.'];
        }
        $queryid = (int) $decoded['id'];
        return ['ok' => true, 'url' => $baseurl . '/queries/' . $queryid];
    }

    /**
     * Wrap the SOLA query+response as a YAML comment block.
     *
     * @param string $query
     * @param string $response
     * @return string
     */
    private static function yaml_comment_block(string $query, string $response): string {
        $cap = function (string $s, int $max): string {
            $s = trim(preg_replace('/\s+/', ' ', $s));
            return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 3) . '...' : $s;
        };
        $out = "# === SOLA Learning Radar — analyst notes ===\n";
        $out .= '# Query:    ' . $cap($query, 240) . "\n";
        $out .= '# Response: ' . $cap($response, 1200) . "\n";
        $out .= "# === end notes ===\n";
        return $out;
    }
}
