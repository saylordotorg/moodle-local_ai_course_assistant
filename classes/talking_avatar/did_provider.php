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

namespace local_ai_course_assistant\talking_avatar;

defined('MOODLE_INTERNAL') || die();

/**
 * D-ID Streaming Talks driver.
 *
 * Cheapest entry point of the four — D-ID's "talks/streams" endpoint
 * returns a session_id + WebRTC ICE bundle, which we forward to a small
 * static viewer page (`talking_avatar_viewer.php`) that opens the WebRTC
 * peer connection client-side. The viewer page is what gets iframed into
 * the SOLA widget.
 *
 * Reference: https://docs.d-id.com/reference/talks-streams-overview
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class did_provider extends base_provider {
    public function get_key(): string {
        return 'did';
    }

    public function is_configured(): bool {
        return $this->cfg('api_key') !== '' && $this->cfg('persona_id') !== '';
    }

    public function start_session(array $context): array {
        if (!$this->is_configured()) {
            throw new \moodle_exception(
                'chat:error',
                'local_ai_course_assistant',
                '',
                null,
                'D-ID: API key or source URL (persona_id) not configured.'
            );
        }
        $base = rtrim($this->cfg('base_url', 'https://api.d-id.com'), '/');
        $sourceurl = $this->cfg('persona_id');

        $resp = $this->http_post_json(
            $base . '/talks/streams',
            ['Authorization: Basic ' . $this->cfg('api_key')],
            [
                'source_url' => $sourceurl,
                'stream_warmup' => true,
            ]
        );

        $sessionid = (string) ($resp['id'] ?? '');
        $sessiontoken = (string) ($resp['session_id'] ?? '');
        if ($sessionid === '' || $sessiontoken === '') {
            throw new \moodle_exception(
                'chat:error',
                'local_ai_course_assistant',
                '',
                null,
                'D-ID: response missing stream id or session id.'
            );
        }

        $viewer = new \moodle_url('/local/ai_course_assistant/talking_avatar_viewer.php', [
            'provider' => 'did',
            'sid' => $sessionid,
            'tok' => $sessiontoken,
            'lang' => $context['lang'] ?? 'en',
        ]);

        return [
            'embed_url' => $viewer->out(false),
            'session_token' => $sessiontoken,
            'provider' => 'did',
            'expires_in' => 600,
            'extras' => [
                'ice_servers' => $resp['ice_servers'] ?? [],
                'offer' => $resp['offer'] ?? null,
            ],
        ];
    }
}
