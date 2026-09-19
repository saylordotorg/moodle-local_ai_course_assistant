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

/**
 * Body-language observation for a Soapbox video presentation (v7.5.1).
 *
 * One vision pass reads the still frames the browser sampled from the learner's
 * own recording and describes what is visible: gestures, posture, where the eyes
 * are directed, framing. It returns a DESCRIPTION, never a score. The scoring
 * model is then given that description as text, which is what lets the visual
 * criteria be judged by a provider with no vision capability at all.
 *
 * Two design points are load-bearing and should not be casually changed.
 *
 * It takes a RECORDING ID, not the observation, and derives everything server
 * side. An earlier design passed the visual evidence in as a web-service
 * parameter; score_speech is an ajax-enabled external function any learner with
 * local/ai_course_assistant:use can call, so that would have let a learner POST
 * "open, purposeful gestures throughout" and award themselves the visual
 * criteria. These courses are fully online and self-paced: there is no
 * instructor, no moderation and no appeal, so nothing downstream would have
 * caught it. observe() refuses unless the recording belongs to the calling user
 * and its assignment belongs to the course being scored.
 *
 * The prompt is descriptive rather than evaluative on purpose. Asking a vision
 * model to score body language directly invites it to reason about the person;
 * asking it to report what the hands and eyes are doing keeps the judgement in
 * the rubric, where the criteria are visible to the learner and editable by an
 * administrator.
 *
 * The observation is stored on the attempt and is the only evidence those two
 * criteria are scored from. It is NOT rendered to the learner in v7.5.1, so the
 * transparency argument above is about where the judgement lives, not about the
 * learner being able to audit it. Do not cite this comment as evidence that the
 * guarantee already exists. Rendering it is a deliberate decision, not a patch:
 * it is raw unreviewed model prose about a named learner's body, and nothing
 * between the prompt and the store enforces the appearance bar the prompt asks
 * for.
 *
 * Best-effort throughout: every failure path returns an empty note and the
 * rubric feedback is unaffected, exactly as soapbox_slide_vision behaves.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_course_assistant;

use local_ai_course_assistant\provider\base_provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Reads sampled still frames and reports what is visible.
 */
class soapbox_gesture_vision {

    /** @var int Max characters kept from the model's observation. */
    const MAX_NOTE_CHARS = 900;

    /** @var int Vision passes allowed per user per window. */
    const RATE_MAX = 10;

    /** @var int Rate-limit window in seconds. */
    const RATE_WINDOW = 600;

    /**
     * Whether body-language feedback should run for this assignment.
     *
     * No per-assignment opt-in, unlike slide vision. That gate exists there
     * because a deck can be many images; this is one bounded sheet per
     * recording, and requiring every assignment to opt in would mean most
     * learners never receive the feedback this release exists to give.
     *
     * @param object $assign Assignment record.
     * @return bool
     */
    public static function is_enabled(object $assign): bool {
        if (!get_config('local_ai_course_assistant', 'soapbox_gesture_vision')) {
            return false;
        }
        return ($assign->mode ?? '') !== 'audio';
    }

    /**
     * Describe what is visible in a recording's sampled frames.
     *
     * @param int $recid    Recording id.
     * @param int $courseid Course the scoring call is for.
     * @param int $userid   The learner the scoring call is for.
     * @return array{note: string, couldhavevideo: bool}
     */
    public static function observe(int $recid, int $courseid, int $userid): array {
        global $DB;

        $empty = ['note' => '', 'couldhavevideo' => false];

        try {
            $rec = $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $recid]);
            if (!$rec) {
                return $empty;
            }
            // The ownership check that makes this safe to derive rather than
            // accept. A learner may only ever supply their own recording id.
            if ((int) $rec->userid !== $userid) {
                return $empty;
            }
            $assign = $DB->get_record(
                'local_ai_course_assistant_sbx_assign',
                ['id' => (int) $rec->assignid]
            );
            if (!$assign || (int) $assign->courseid !== $courseid) {
                return $empty;
            }

            // Off is the DEFAULT, and score_recording() calls this for every
            // scored recording with no enablement guard of its own. Computing
            // couldhavevideo before this check meant the disabled path returned
            // true, and score_speech turned that into a saved paragraph telling
            // the learner their camera "could not be read" -- on a site where
            // the recorder never sampled a frame in the first place. Both stated
            // causes were false, the remedy was unactionable, and it contradicted
            // the "5 of 5 criteria assessed (100%)" line rendered right above it.
            if (!self::is_enabled($assign)) {
                return $empty;
            }

            // Reported even when no note can be produced: the caller uses it to
            // decide whether to tell the learner why a section of feedback is
            // missing. The surviving mode test still covers an audio row under a
            // video assignment, and the rate-limit and fetch-failure paths below
            // keep it true, which is the case the message was actually written
            // for.
            $couldhavevideo = (($rec->mode ?? $assign->mode ?? '') !== 'audio');
            $out = ['note' => '', 'couldhavevideo' => $couldhavevideo];

            if (empty($rec->frames_key)) {
                return $out;
            }
            if (rate_limiter::is_rate_limited($userid, 'soapbox_gesture_vision', self::RATE_MAX, self::RATE_WINDOW)) {
                return $out;
            }

            $uri = self::fetch_frames_datauri((string) $rec->frames_key);
            if ($uri === '') {
                return $out;
            }

            $provider = self::resolve_provider($courseid);
            $note = $provider->chat_completion(
                self::system_prompt(),
                [['role' => 'user', 'content' => 'Describe what is visible in these frames now.']],
                ['image_datauris' => [$uri], 'max_tokens' => 300]
            );
            // Passed explicitly rather than read from $USER so this class does
            // not depend on which side of soapbox_scorer's set_user() it is
            // called from. Slide vision runs before it; this runs after.
            conversation_manager::log_ancillary_usage(
                $provider,
                $userid,
                $courseid,
                'gesture_vision',
                '[Soapbox] body-language observation'
            );

            $note = trim((string) $note);
            if ($note !== '') {
                $out['note'] = \core_text::substr($note, 0, self::MAX_NOTE_CHARS);
            }
            return $out;
        } catch (\Throwable $e) {
            debugging('soapbox gesture-vision failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $empty;
        }
    }

    /**
     * The observation prompt.
     *
     * Descriptive, not evaluative, and explicitly bounded away from describing
     * the person. It is asked to admit when a frame cannot be read, because a
     * confident description of an unreadable frame is what would produce a
     * confident score of something nobody saw.
     *
     * @return string
     */
    private static function system_prompt(): string {
        return "You are shown a single image containing still frames sampled at even intervals "
            . "across one learner's recorded presentation, in order from the start of the talk to "
            . "the end.\n\n"
            . "Describe ONLY what is visible, in plain prose, in about four to six sentences:\n"
            . "- what the hands and arms are doing, and whether that changes across the frames;\n"
            . "- posture and stance, and any repeated movement such as rocking, pacing or fidgeting;\n"
            . "- where the eyes appear to be directed, for example toward the camera, downward at "
            . "notes, or off to one side;\n"
            . "- how the speaker is framed: how much of them is in shot, whether the hands are in "
            . "view, and whether the face is lit well enough to read.\n\n"
            . "Do not score, rate or evaluate anything. Do not guess at what is not visible. Do not "
            . "describe the person's appearance, ethnicity, age, clothing or setting beyond what "
            . "bears directly on how the presentation reads. Do not read or transcribe any text.\n\n"
            . "If a frame is too dark, too blurred, too far away or cropped so the hands or face "
            . "cannot be seen, say so plainly and say how many frames are affected. Reporting that "
            . "you cannot tell is more useful than a confident guess.";
    }

    /**
     * Download the stored frame sheet and return it as a data URI.
     *
     * @param string $key Object key.
     * @return string Data URI, or '' on any failure.
     */
    private static function fetch_frames_datauri(string $key): string {
        try {
            $storage = new soapbox_storage();
            $url = $storage->presign_get($key, 900);
            $tmp = make_request_directory() . '/frames.jpg';

            $curl = new \curl();
            $body = $curl->get($url);
            if ($curl->get_errno() || $body === false || $body === '') {
                return '';
            }
            file_put_contents($tmp, $body);
            $bytes = file_get_contents($tmp);
            if ($bytes === false || $bytes === '') {
                return '';
            }
            // Sniff rather than trust the extension: the object was uploaded by
            // a browser and the key is ours, but the bytes are not.
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->buffer($bytes);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                return '';
            }
            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Resolve the provider for the vision pass, mirroring slide vision so both
     * follow the same admin override and the same fallback.
     *
     * @param int $courseid
     * @return \local_ai_course_assistant\provider\provider_interface
     */
    private static function resolve_provider(int $courseid) {
        $providerid = trim((string) get_config('local_ai_course_assistant', 'soapbox_vision_provider'));
        $model = trim((string) get_config('local_ai_course_assistant', 'soapbox_vision_model'));
        if ($providerid !== '' && $model !== '') {
            try {
                return base_provider::create_for_comparison($providerid, $model, $courseid);
            } catch (\Throwable $e) {
                debugging(
                    'soapbox gesture-vision provider unavailable, falling back: ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
        return base_provider::create_from_config($courseid);
    }
}
