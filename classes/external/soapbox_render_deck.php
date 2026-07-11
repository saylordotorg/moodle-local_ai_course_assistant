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
use local_ai_course_assistant\soapbox_assignment_manager;
use local_ai_course_assistant\feature_flags;

defined('MOODLE_INTERNAL') || die();

/**
 * Render an uploaded slide deck (PDF) to page images for the recorder's slide
 * viewer (v6.8.23). Fetches the deck from storage, rasterizes it server-side
 * with Ghostscript, and returns the pages as data URIs (not persisted), so the
 * browser can display and advance slides while recording.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class soapbox_render_deck extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignid' => new external_value(PARAM_INT, 'Soapbox assignment id'),
            'deckkey' => new external_value(PARAM_RAW, 'Deck object key from get_upload_url(kind=deck)'),
        ]);
    }

    /**
     * @param int $assignid
     * @param string $deckkey
     * @return array
     */
    public static function execute(int $assignid, string $deckkey): array {
        global $USER, $CFG;

        $params = self::validate_parameters(self::execute_parameters(),
            ['assignid' => $assignid, 'deckkey' => $deckkey]);

        $assign = soapbox_assignment_manager::get_assignment((int) $params['assignid']);
        if (!$assign || !$assign->visible) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $context = \context_course::instance((int) $assign->courseid);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        if (!feature_flags::resolve('soapbox', (int) $assign->courseid)
                || empty($assign->slides_enabled)) {
            throw new \moodle_exception('soapbox:slides_disabled', 'local_ai_course_assistant');
        }
        if (!soapbox_deck_renderer::is_available()) {
            return ['pagecount' => 0, 'pages' => []];
        }

        // The deck key must be under this learner's own path for this course.
        $key = (string) $params['deckkey'];
        $expectedprefix = soapbox_storage::prefix() . (int) $assign->courseid . '/' . (int) $USER->id . '/';
        if (strpos($key, $expectedprefix) !== 0) {
            throw new \moodle_exception('soapbox:bad_key', 'local_ai_course_assistant');
        }

        // Download the deck to a per-request temp file.
        require_once($CFG->libdir . '/filelib.php');
        $storage = new soapbox_storage();
        $tmp = make_request_directory() . '/deck.pdf';
        $curl = new \curl();
        $curl->download_one($storage->presign_get($key, 900), null,
            ['filepath' => $tmp, 'timeout' => 120, 'followlocation' => true]);
        if (!is_file($tmp) || filesize($tmp) === 0) {
            throw new \moodle_exception('soapbox:upload_missing', 'local_ai_course_assistant');
        }

        $pages = soapbox_deck_renderer::render_to_datauris($tmp);
        return ['pagecount' => count($pages), 'pages' => $pages];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'pagecount' => new external_value(PARAM_INT, 'Number of pages rendered'),
            'pages' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Page image as a data URI'),
                'Ordered page images'),
        ]);
    }
}
