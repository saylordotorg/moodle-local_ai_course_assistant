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

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_course_assistant\radar_schedule_manager;

/**
 * Create or update a Learning Radar schedule.
 *
 * Replaces the `action=save` branch of the former radar_schedule.php
 * AJAX_SCRIPT endpoint. Site-admin only, at system context, exactly as that
 * endpoint was.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_radar_schedule extends external_api {
    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Schedule id, 0 to create', VALUE_DEFAULT, 0),
            // PARAM_RAW here, cleaned to PARAM_TEXT inside execute(). Declaring
            // it PARAM_TEXT makes external_value::validate() THROW when the
            // cleaned value differs from the submitted one, and strip_tags()
            // eats everything from the first '<'. The modal pre-fills the name
            // from the first 60 characters of the analyst query, and analyst
            // queries routinely say things like "grade <70 in week 3", so a
            // perfectly ordinary name was rejected with "Invalid parameter
            // value detected" and no indication of which field was wrong. The
            // old AJAX endpoint cleaned it silently and saved. Cleaning inside
            // execute() keeps the stored value safe and the field forgiving.
            'name' => new external_value(PARAM_RAW, 'Schedule name', VALUE_DEFAULT, ''),
            // PARAM_RAW is required: the scheduled Learning Radar query is
            // free-form analyst prose that routinely carries newlines, quotes,
            // LaTeX and fenced code blocks. It is stored verbatim, sent to the
            // LLM by the cron task, and returned to the admin UI as JSON where
            // the browser writes it into an input .value - it is never
            // interpolated into HTML.
            'query' => new external_value(PARAM_RAW, 'Analyst prose query to run', VALUE_DEFAULT, ''),
            'provider' => new external_value(PARAM_ALPHANUMEXT, 'AI provider key', VALUE_DEFAULT, ''),
            // Vendor model slugs contain dots and slashes ("gemini-2.5-flash",
            // "meta-llama/Llama-3.1-8B-Instruct"), which PARAM_ALPHANUMEXT
            // would strip. PARAM_TEXT is lossless for those while stripping
            // markup.
            // Same reasoning as name: PARAM_RAW in, cleaned to PARAM_TEXT in
            // execute(), so a slug the cleaner would alter cannot throw.
            'model' => new external_value(PARAM_RAW, 'Model slug, empty for the provider default',
                VALUE_DEFAULT, ''),
            'frequency' => new external_value(PARAM_ALPHA, 'daily | weekly | monthly', VALUE_DEFAULT, 'weekly'),
            'recipient_email' => new external_value(PARAM_EMAIL, 'Report recipient', VALUE_DEFAULT, ''),
            'slack_webhook' => new external_value(PARAM_URL, 'Slack incoming webhook', VALUE_DEFAULT, ''),
            'teams_webhook' => new external_value(PARAM_URL, 'Teams incoming webhook', VALUE_DEFAULT, ''),
            'format' => new external_value(PARAM_ALPHA, 'text | csv | json | markdown', VALUE_DEFAULT, 'text'),
            'courseids' => new external_value(PARAM_SEQUENCE, 'Comma-separated course ids, empty for all',
                VALUE_DEFAULT, ''),
            // null distinguishes ABSENT from empty: the schedule modal has no
            // filterprovider input, so every edit posts without the key and a
            // '' default used to overwrite a provider filter migrated from the
            // legacy metaai_cron_filterprovider setting. Absent preserves the
            // stored value; an explicit empty string still clears it.
            'filterprovider' => new external_value(PARAM_ALPHANUMEXT, 'Provider filter, omit to keep the stored one',
                VALUE_DEFAULT, null, NULL_ALLOWED),
            // Blank means "no explicit range" (stored as NULL = site default),
            // so this cannot be PARAM_INT, which would coerce '' to 0. It is
            // read raw to keep the blank/non-blank distinction and cleaned to
            // an integer explicitly below.
            'range_days' => new external_value(PARAM_RAW_TRIMMED, 'Reporting window in days, blank for the site default',
                VALUE_DEFAULT, ''),
            'enabled' => new external_value(PARAM_BOOL, 'Whether the schedule runs', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Save a schedule and return its id.
     *
     * @param int $id
     * @param string $name
     * @param string $query
     * @param string $provider
     * @param string $model
     * @param string $frequency
     * @param string $recipientemail
     * @param string $slackwebhook
     * @param string $teamswebhook
     * @param string $format
     * @param string $courseids
     * @param string|null $filterprovider
     * @param string $rangedays
     * @param bool $enabled
     * @return array
     * @throws \moodle_exception When the name or the query is empty.
     */
    public static function execute(
        int $id = 0,
        string $name = '',
        string $query = '',
        string $provider = '',
        string $model = '',
        string $frequency = 'weekly',
        string $recipientemail = '',
        string $slackwebhook = '',
        string $teamswebhook = '',
        string $format = 'text',
        string $courseids = '',
        ?string $filterprovider = null,
        string $rangedays = '',
        bool $enabled = false
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'id' => $id,
            'name' => $name,
            'query' => $query,
            'provider' => $provider,
            'model' => $model,
            'frequency' => $frequency,
            'recipient_email' => $recipientemail,
            'slack_webhook' => $slackwebhook,
            'teams_webhook' => $teamswebhook,
            'format' => $format,
            'courseids' => $courseids,
            'filterprovider' => $filterprovider,
            'range_days' => $rangedays,
            'enabled' => $enabled,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $data = $params;
        $data['model'] = trim($params['model']);
        // Clean what the declarations no longer clean for us.
        $data['name'] = clean_param($params['name'], PARAM_TEXT);
        $data['model'] = trim(clean_param($params['model'], PARAM_TEXT));

        $data['range_days'] = $params['range_days'] === ''
            ? '' : (string) clean_param($params['range_days'], PARAM_INT);
        $data['enabled'] = !empty($params['enabled']) ? 1 : 0;

        if ($data['filterprovider'] === null) {
            $editid = (int) $data['id'];
            $existingrow = $editid > 0 ? radar_schedule_manager::get($editid) : null;
            $data['filterprovider'] = $existingrow ? (string) $existingrow->filterprovider : '';
        }

        if ($data['name'] === '' || $data['query'] === '') {
            throw new \moodle_exception('radar:err_name_query_required', 'local_ai_course_assistant');
        }

        return ['id' => radar_schedule_manager::save($data, (int) $USER->id)];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Id of the saved schedule'),
        ]);
    }
}
