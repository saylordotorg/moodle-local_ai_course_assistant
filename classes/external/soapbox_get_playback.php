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
use core_external\external_multiple_structure;
use core_external\external_value;
use local_ai_course_assistant\soapbox_storage;
use local_ai_course_assistant\soapbox_deck_renderer;
use local_ai_course_assistant\soapbox_config;
use local_ai_course_assistant\soapbox_assignment_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Return everything needed to play a Soapbox recording with synced slides
 * (v6.8.26): a presigned video/audio URL, the deck rendered to page images, and
 * the slide-advance timeline. Available to the recording's owner and to course
 * managers. The deck is rendered server-side from the stored key, so no client
 * key handling (or per-viewer ownership check) is needed.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class soapbox_get_playback extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'recordingid' => new external_value(PARAM_INT, 'Recording id'),
        ]);
    }

    /**
     * @param int $recordingid
     * @return array
     */
    public static function execute(int $recordingid): array {
        global $USER, $DB, $CFG;

        $params = self::validate_parameters(self::execute_parameters(),
            ['recordingid' => $recordingid]);

        $rec = $DB->get_record('local_ai_course_assistant_sbx_rec',
            ['id' => (int) $params['recordingid']]);
        if (!$rec || $rec->status === 'deleted' || empty($rec->storage_key)) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $assign = soapbox_assignment_manager::get_assignment((int) $rec->assignid);
        if (!$assign) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $context = \context_course::instance((int) $assign->courseid);
        self::validate_context($context);
        // Owner or a course manager may play it back.
        if ((int) $rec->userid !== (int) $USER->id) {
            require_capability('local/ai_course_assistant:manage', $context);
        } else {
            require_capability('local/ai_course_assistant:use', $context);
        }

        $storage = new soapbox_storage();
        $videourl = $storage->presign_get($rec->storage_key, 3600);

        // Render the deck (if any) server-side from the stored key.
        $pages = [];
        if (!empty($rec->deck_key) && soapbox_deck_renderer::is_available()) {
            require_once($CFG->libdir . '/filelib.php');
            $tmp = make_request_directory() . '/deck.pdf';
            $curl = new \curl();
            $curl->download_one($storage->presign_get($rec->deck_key, 900), null,
                ['filepath' => $tmp, 'timeout' => 120, 'followlocation' => true]);
            if (is_file($tmp) && filesize($tmp) > 0) {
                $pages = soapbox_deck_renderer::render_to_datauris($tmp);
            }
        }

        $timeline = soapbox_config::normalize_slide_timeline((string) ($rec->slide_timeline ?? ''));

        return [
            'videourl' => $videourl,
            'mode'     => $rec->mode,
            'pages'    => $pages,
            'timeline' => json_encode($timeline),
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'videourl' => new external_value(PARAM_RAW, 'Presigned URL of the recording'),
            'mode'     => new external_value(PARAM_ALPHA, 'video | audio'),
            'pages'    => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Page image as a data URI'), 'Slide page images'),
            'timeline' => new external_value(PARAM_RAW, 'JSON slide-advance timeline'),
        ]);
    }
}
