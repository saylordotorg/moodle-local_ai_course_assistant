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
use local_ai_course_assistant\feature_flags;
use local_ai_course_assistant\rate_limiter;
use local_ai_course_assistant\talking_avatar\provider_factory;
use local_ai_course_assistant\talking_avatar_session_manager;

/**
 * Open a real-time talking-avatar session for the configured provider.
 *
 * Replaces the former talking_avatar_session.php AJAX_SCRIPT endpoint. The
 * widget calls this when the learner opens the avatar surface; the embed_url
 * is rendered inside an iframe.
 *
 * COURSE-ONLY ON PURPOSE. Every other sibling that a support-mode learner can
 * reach goes through support_mode::validation_context() and require_use(), and
 * this one deliberately does not: 'talkingavatarenabled' is in
 * support_mode::SUPPRESSED_FLAGS, so the button that calls this is forced off
 * on the out-of-course surface. Relaxing the context here would hand a
 * per-minute-billed vendor session to a population the feature is switched off
 * for, which is the opposite of what the suppression list is for.
 *
 * The session is always opened for the calling user. There is no userid
 * parameter, by construction: the row is written against $USER->id, and the
 * one-open-session guard counts only that user's rows.
 *
 * The driver's `extras` key (ICE servers, a WebRTC offer, a LiveKit room) is
 * deliberately not returned. No caller reads it, and its shape is
 * vendor-defined and nested, so declaring it would be a fiction the next
 * driver breaks.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class start_avatar_session extends external_api {
    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course the learner is in'),
            'lang' => new external_value(PARAM_ALPHA, 'Two-letter language hint, empty to let the provider decide',
                VALUE_DEFAULT, ''),
            'greeting' => new external_value(PARAM_TEXT, 'Optional opening line for the avatar', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Start a session, or report why it could not start.
     *
     * @param int $courseid
     * @param string $lang
     * @param string $greeting
     * @return array
     */
    public static function execute(int $courseid, string $lang = '', string $greeting = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'lang' => $lang,
            'greeting' => $greeting,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        if (!feature_flags::resolve('talking_avatar', $params['courseid'])) {
            return self::refusal('disabled', 'talking_avatar:disabled');
        }

        // F84: this was the one paid learner endpoint with neither a request
        // limit nor spend accounting -- every call opens a per-minute-billed
        // vendor session. Four opens per five minutes covers a flaky-connection
        // retry; a loop does not get to buy avatar minutes invisibly.
        if (rate_limiter::is_rate_limited($USER->id, 'talking_avatar_open', 4, 300)) {
            return self::refusal('ratelimited', 'soapbox:rate_limited');
        }

        // One open session per learner. A second tab (or a crafted loop) used
        // to open a second billable vendor session while the first kept
        // running; the sweeper eventually closes orphans, but minutes accrue
        // until it does. Scoped to this user's own rows: another learner's open
        // sessions must never lock this one out.
        $opensessions = $DB->count_records_select(
            'local_ai_course_assistant_avatar_sess',
            'userid = :uid AND ended_at IS NULL AND started_at > :cutoff',
            ['uid' => $USER->id, 'cutoff' => time() - 3600]
        );
        if ($opensessions >= 2) {
            return self::refusal('session_open', 'talking_avatar:session_failed');
        }

        $driver = provider_factory::make();
        if ($driver === null || !$driver->is_configured()) {
            return self::refusal('unconfigured', 'talking_avatar:unconfigured');
        }

        try {
            $session = $driver->start_session([
                'courseid' => $params['courseid'],
                'userid'   => $USER->id,
                'lang'     => $params['lang'] ?: 'en',
                'greeting' => $params['greeting'],
            ]);
            $rowid = talking_avatar_session_manager::start(
                $USER->id,
                $params['courseid'],
                (string) ($session['provider'] ?? $driver->get_key()),
                (string) (get_config('local_ai_course_assistant', $driver->get_key() . '_persona_id') ?: ''),
                // upstream_session_id when the driver distinguishes it (HeyGen
                // and D-ID carry a different id in the viewer URL than their
                // credential); session_token as the fallback for drivers where
                // they are the same.
                (string) ($session['upstream_session_id'] ?? $session['session_token'] ?? '')
            );
        } catch (\Throwable $e) {
            debugging('SOLA talking-avatar session error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return self::refusal('upstream', 'talking_avatar:session_failed');
        }

        return [
            'ok' => true,
            'reason' => '',
            'error' => '',
            'session_rowid' => $rowid,
            'embed_url' => (string) ($session['embed_url'] ?? ''),
            'session_token' => (string) ($session['session_token'] ?? ''),
            'provider' => (string) ($session['provider'] ?? $driver->get_key()),
            'expires_in' => (int) ($session['expires_in'] ?? 0),
        ];
    }

    /**
     * A refusal in the same shape as a success, so the widget has one branch.
     *
     * @param string $reason Machine-readable reason code.
     * @param string $stringid Lang string shown to the learner.
     * @return array
     */
    private static function refusal(string $reason, string $stringid): array {
        return [
            'ok' => false,
            'reason' => $reason,
            'error' => get_string($stringid, 'local_ai_course_assistant'),
            'session_rowid' => 0,
            'embed_url' => '',
            'session_token' => '',
            'provider' => '',
            'expires_in' => 0,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether a session was opened'),
            'reason' => new external_value(PARAM_ALPHANUMEXT,
                'disabled | ratelimited | session_open | unconfigured | upstream, empty on success'),
            'error' => new external_value(PARAM_TEXT, 'Message for the learner, empty on success'),
            'session_rowid' => new external_value(PARAM_INT, 'Session row id the heartbeat closes, 0 on refusal'),
            // PARAM_URL, not PARAM_RAW: this lands in an iframe src, and the
            // vendor response it comes from is not otherwise checked.
            'embed_url' => new external_value(PARAM_URL, 'URL the widget loads in an iframe, empty on refusal'),
            'session_token' => new external_value(PARAM_RAW, 'Short-lived token if the embed needs one'),
            'provider' => new external_value(PARAM_ALPHANUMEXT, 'Provider key, empty on refusal'),
            'expires_in' => new external_value(PARAM_INT, 'Lifetime hint in seconds, 0 if unknown'),
        ]);
    }
}
