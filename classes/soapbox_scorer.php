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

use local_ai_course_assistant\external\score_speech;

defined('MOODLE_INTERNAL') || die();

/**
 * Scores a finished Soapbox video/audio recording (v6.8.16): pulls the object
 * from storage, transcribes it via the configured Whisper path (voice_registry,
 * self-hosted preferred), and scores the transcript against the course speech
 * rubric with the assignment's presentation type. Audio and transcript bytes
 * are handled transiently; the transcript is stored on the recording row and
 * the score in the practice-scores history (as the existing Soapbox flow does).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class soapbox_scorer {

    /**
     * Download a stored recording and transcribe it. Returns the transcript
     * text, or null if the object is missing or transcription is unavailable.
     *
     * @param string $key Object key.
     * @return string|null
     */
    public static function transcribe_object(string $key): ?string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $cfg = voice_registry::resolve(voice_registry::CAPABILITY_STT);
        if (empty($cfg) || empty($cfg['endpoint'])) {
            return null;
        }
        if (!security::is_safe_provider_url($cfg['endpoint'])) {
            return null;
        }

        // Fetch the object to a per-request temp file (File API temp dir).
        $storage = new soapbox_storage();
        $ext = pathinfo(parse_url($key, PHP_URL_PATH) ?? $key, PATHINFO_EXTENSION) ?: 'bin';
        $tmpfile = make_request_directory() . '/recording.' . preg_replace('/[^a-z0-9]/', '', strtolower($ext));
        $dl = new \curl();
        $dl->download_one($storage->presign_get($key, 900), null,
            ['filepath' => $tmpfile, 'timeout' => 180, 'followlocation' => true]);
        if (!file_exists($tmpfile) || filesize($tmpfile) === 0) {
            return null;
        }

        $mimetypes = ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4', 'oga' => 'audio/ogg', 'mov' => 'video/quicktime'];
        $mime = $mimetypes[strtolower($ext)] ?? 'application/octet-stream';
        $model = !empty($cfg['model']) ? $cfg['model'] : 'whisper-1';
        $post = ['file' => new \CURLFile($tmpfile, $mime, basename($tmpfile)), 'model' => $model];

        $curl = new \curl();
        if (!empty($cfg['apikey'])) {
            $curl->setHeader('Authorization: Bearer ' . $cfg['apikey']);
        }
        $options = array_merge(['CURLOPT_TIMEOUT' => 300],
            security::resolve_pin_options($cfg['endpoint']));
        $response = $curl->post($cfg['endpoint'], $post, $options);
        if ((int) ($curl->get_info()['http_code'] ?? 0) !== 200) {
            return null;
        }
        $data = json_decode($response, true);
        $text = isset($data['text']) ? trim((string) $data['text']) : '';
        return ($text !== '') ? $text : null;
    }

    /**
     * Transcribe and score one recording, updating its row. Impersonates the
     * recording owner so the existing score_speech flow saves the score to the
     * right learner's history. Idempotent-ish: only acts on 'uploaded' rows.
     *
     * @param int $recid
     */
    public static function score_recording(int $recid): void {
        global $DB;

        $rec = $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $recid]);
        if (!$rec || $rec->status !== 'uploaded' || empty($rec->storage_key)) {
            return;
        }
        $assign = soapbox_assignment_manager::get_assignment((int) $rec->assignid);
        if (!$assign) {
            return;
        }

        $transcript = self::transcribe_object($rec->storage_key);
        if ($transcript === null) {
            $DB->set_field('local_ai_course_assistant_sbx_rec', 'status', 'failed', ['id' => $recid]);
            return;
        }

        $topictitle = '';
        if (!empty($rec->topicid)) {
            $topictitle = (string) $DB->get_field('local_ai_course_assistant_sbx_topic', 'title',
                ['id' => $rec->topicid]);
        }

        // Score as the recording owner (score_speech saves to $USER's history and
        // checks the course :use capability, which the learner holds).
        $user = $DB->get_record('user', ['id' => (int) $rec->userid]);
        if (!$user) {
            return;
        }
        \core\session\manager::set_user($user);

        $result = score_speech::execute(
            (int) $assign->courseid, $transcript, '', $topictitle,
            (int) $assign->max_seconds, (int) $rec->duration_seconds, (string) $assign->ptype);

        $update = (object) [
            'id'         => $recid,
            'transcript' => $transcript,
            'scoreid'    => !empty($result['scoreid']) ? (int) $result['scoreid'] : null,
            'status'     => !empty($result['success']) ? 'scored' : 'failed',
        ];
        $DB->update_record('local_ai_course_assistant_sbx_rec', $update);
    }
}
