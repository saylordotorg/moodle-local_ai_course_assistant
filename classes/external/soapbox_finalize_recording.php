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

defined('MOODLE_INTERNAL') || die();

/**
 * Finalize a Soapbox recording after the browser has uploaded it (v6.8.13).
 *
 * Confirms the object exists in storage under the learner's own key path, then
 * records a recording row with an expiry set by the retention window. Scoring
 * is enqueued by a later Phase 1 PR; this just registers the upload.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class soapbox_finalize_recording extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignid' => new external_value(PARAM_INT, 'Soapbox assignment id'),
            'objectkey' => new external_value(PARAM_RAW, 'Object key returned by get_upload_url'),
            'topicid' => new external_value(PARAM_INT, 'Chosen topic id (0 = none)', VALUE_DEFAULT, 0),
            'durationseconds' => new external_value(PARAM_INT, 'Recorded duration in seconds', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * @param int $assignid
     * @param string $objectkey
     * @param int $topicid
     * @param int $durationseconds
     * @return array
     */
    public static function execute(int $assignid, string $objectkey, int $topicid = 0,
            int $durationseconds = 0): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'assignid' => $assignid, 'objectkey' => $objectkey,
            'topicid' => $topicid, 'durationseconds' => $durationseconds,
        ]);

        $assign = soapbox_assignment_manager::get_assignment((int) $params['assignid']);
        if (!$assign || !$assign->visible) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $context = \context_course::instance((int) $assign->courseid);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        // The key must live under this learner's own path for this course, so a
        // learner cannot register another learner's or an arbitrary object.
        $key = (string) $params['objectkey'];
        $expectedprefix = soapbox_storage::prefix() . (int) $assign->courseid . '/' . (int) $USER->id . '/';
        if (strpos($key, $expectedprefix) !== 0) {
            throw new \moodle_exception('soapbox:bad_key', 'local_ai_course_assistant');
        }

        // Confirm the upload actually landed.
        $storage = new soapbox_storage();
        $size = $storage->object_size($key);
        if ($size === null) {
            throw new \moodle_exception('soapbox:upload_missing', 'local_ai_course_assistant');
        }

        // Validate the topic belongs to this assignment (if any).
        $topicid = (int) $params['topicid'];
        if ($topicid > 0 && !$DB->record_exists('local_ai_course_assistant_sbx_topic',
                ['id' => $topicid, 'assignid' => $assign->id])) {
            $topicid = 0;
        }

        $now = time();
        $rec = (object) [
            'assignid'         => (int) $assign->id,
            'userid'           => (int) $USER->id,
            'topicid'          => $topicid ?: null,
            'mode'             => $assign->mode,
            'storage_key'      => $key,
            'duration_seconds' => max(0, (int) $params['durationseconds']),
            'size_bytes'       => $size,
            'status'           => 'uploaded',
            'transcript'       => null,
            'scoreid'          => null,
            'expires_at'       => $now + (soapbox_config::retention_days() * DAYSECS),
            'timecreated'      => $now,
        ];
        $recid = (int) $DB->insert_record('local_ai_course_assistant_sbx_rec', $rec);

        // Transcribe + score off the request.
        $task = new \local_ai_course_assistant\task\score_recording();
        $task->set_custom_data(['recid' => $recid]);
        \core\task\manager::queue_adhoc_task($task);

        return [
            'recordingid' => $recid,
            'status'      => 'uploaded',
            'expires_at'  => $rec->expires_at,
            'viewurl'    => $storage->presign_get($key, 3600),
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'recordingid' => new external_value(PARAM_INT, 'New recording id'),
            'status'      => new external_value(PARAM_ALPHA, 'Recording status'),
            'expires_at'  => new external_value(PARAM_INT, 'Unix time the recording is deleted'),
            'viewurl'    => new external_value(PARAM_RAW, 'Presigned URL to view the recording'),
        ]);
    }
}
