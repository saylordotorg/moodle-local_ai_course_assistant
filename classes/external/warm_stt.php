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
use local_ai_course_assistant\voice_registry;

/**
 * Best-effort "warm up" ping for the self-hosted transcription server.
 *
 * Called from the Soapbox recorder the moment a recording starts, so a
 * scale-to-zero STT host (e.g. Cloud Run) begins booting while the student is
 * still talking. By the time they submit and the real transcription request
 * fires, the container is already warm — hiding the cold start.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class warm_stt extends external_api {

    /**
     * Parameters: none.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Send a best-effort spin-up ping to the configured self-hosted STT server.
     *
     * @return array ['warmed' => bool]
     */
    public static function execute(): array {
        global $USER, $CFG;

        // Gate to a genuine logged-in (non-guest) user, mirroring dismiss_intro:
        // moodle/user:editownprofile is held by authenticated users on their own
        // context but not by guests. No data is read or written.
        $context = \context_user::instance($USER->id);
        self::validate_context($context);
        require_capability('moodle/user:editownprofile', $context);

        // Off by default: only ping when the admin has enabled pre-warm.
        if ((string) get_config('local_ai_course_assistant', 'stt_selfhosted_warm') !== '1') {
            return ['warmed' => false];
        }

        $cfg = voice_registry::selfhosted_stt_config();
        if ($cfg === null) {
            // Self-hosted transcription not configured/enabled — nothing to warm.
            return ['warmed' => false];
        }

        // Derive the server base from the transcription endpoint and hit it with
        // a short timeout — long enough to make a scaled-to-zero host start
        // booting, short enough not to hold a PHP worker. We use Moodle's curl
        // helper so the same SSRF/security rules as the real transcription call
        // apply, and we swallow all errors: warming is strictly best-effort.
        $base = preg_replace('~/v\d+/audio/transcriptions/?$~', '', $cfg['endpoint']);
        require_once($CFG->libdir . '/filelib.php');
        try {
            $curl = new \curl();
            $curl->setopt([
                'CURLOPT_CONNECTTIMEOUT' => 3,
                'CURLOPT_TIMEOUT' => 4,
            ]);
            $curl->get($base);
        } catch (\Throwable $e) {
            debugging('warm_stt ping failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return ['warmed' => true];
    }

    /**
     * Returns description of the result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'warmed' => new external_value(PARAM_BOOL, 'Whether a warm-up ping was sent'),
        ]);
    }
}
