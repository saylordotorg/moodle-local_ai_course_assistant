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
use local_ai_course_assistant\soapbox_config;
use local_ai_course_assistant\soapbox_storage;
use local_ai_course_assistant\soapbox_assignment_manager;
use local_ai_course_assistant\feature_flags;

defined('MOODLE_INTERNAL') || die();

/**
 * Issue a presigned upload URL for a Soapbox recording (v6.8.13).
 *
 * Validates the learner may record for this assignment and is under the
 * recording cap, generates an object key under the learner's own path, and
 * returns a short-lived presigned PUT URL for a direct browser-to-S3 upload.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class soapbox_get_upload_url extends external_api {

    /** @var string[] Extensions we accept for a recording. */
    const ALLOWED_EXT = ['mp4', 'webm', 'ogg', 'm4a', 'oga', 'mov'];

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignid' => new external_value(PARAM_INT, 'Soapbox assignment id'),
            'ext' => new external_value(PARAM_ALPHANUM, 'Recording file extension', VALUE_DEFAULT, 'mp4'),
        ]);
    }

    /**
     * @param int $assignid
     * @param string $ext
     * @return array
     */
    public static function execute(int $assignid, string $ext = 'mp4'): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(),
            ['assignid' => $assignid, 'ext' => $ext]);

        $assign = soapbox_assignment_manager::get_assignment((int) $params['assignid']);
        if (!$assign || !$assign->visible) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $context = \context_course::instance((int) $assign->courseid);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        if (!feature_flags::resolve('soapbox', (int) $assign->courseid)) {
            throw new \moodle_exception('soapbox:disabled', 'local_ai_course_assistant');
        }
        if (!soapbox_storage::is_configured()) {
            throw new \moodle_exception('soapbox:storage_unconfigured', 'local_ai_course_assistant');
        }

        // Enforce the recording cap (per-assignment attempts, bounded by the admin max).
        $made = $DB->count_records_select(
            'local_ai_course_assistant_sbx_rec',
            'assignid = :a AND userid = :u AND status <> :d',
            ['a' => $assign->id, 'u' => $USER->id, 'd' => 'deleted']);
        if ($made >= soapbox_config::effective_recording_cap((int) $assign->max_attempts)) {
            throw new \moodle_exception('soapbox:cap_reached', 'local_ai_course_assistant');
        }

        $ext = strtolower((string) $params['ext']);
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            $ext = ($assign->mode === 'audio') ? 'm4a' : 'mp4';
        }
        $key = soapbox_storage::make_object_key((int) $assign->courseid, (int) $USER->id, $ext);
        $storage = new soapbox_storage();

        return [
            'uploadurl' => $storage->presign_put($key),
            'objectkey' => $key,
            'method'    => 'PUT',
            'expiresin' => soapbox_storage::DEFAULT_EXPIRY,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'uploadurl' => new external_value(PARAM_RAW, 'Presigned PUT URL for a direct browser upload'),
            'objectkey' => new external_value(PARAM_RAW, 'Object key to pass back to finalize'),
            'method'    => new external_value(PARAM_ALPHA, 'HTTP method to use for the upload'),
            'expiresin' => new external_value(PARAM_INT, 'Seconds until the presigned URL expires'),
        ]);
    }
}
