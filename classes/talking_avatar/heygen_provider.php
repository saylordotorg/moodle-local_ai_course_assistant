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
 * HeyGen Interactive Avatar (LiveKit-backed) driver.
 *
 * The streaming.new flow returns a LiveKit room URL + access token; we
 * forward both to the SOLA viewer page, which loads HeyGen's
 * @heygen/streaming-avatar SDK (via the operator-supplied CDN bundle if
 * any, otherwise falls back to a static "configure CDN" notice).
 *
 * Reference: https://docs.heygen.com/reference/new-session
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class heygen_provider extends base_provider {
    public function get_key(): string {
        return 'heygen';
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
                'HeyGen: API key or avatar id (persona_id) not configured.'
            );
        }
        $base = rtrim($this->cfg('base_url', 'https://api.heygen.com'), '/');

        $tokenresp = $this->http_post_json(
            $base . '/v1/streaming.create_token',
            ['X-Api-Key: ' . $this->cfg('api_key')],
            []
        );
        $accesstoken = (string) ($tokenresp['data']['token'] ?? '');
        if ($accesstoken === '') {
            throw new \moodle_exception(
                'chat:error',
                'local_ai_course_assistant',
                '',
                null,
                'HeyGen: streaming.create_token response missing data.token.'
            );
        }

        $newresp = $this->http_post_json(
            $base . '/v1/streaming.new',
            ['Authorization: Bearer ' . $accesstoken],
            [
                'avatar_id' => $this->cfg('persona_id'),
                'quality' => 'medium',
                'voice' => ['language' => $context['lang'] ?? 'en'],
                'version' => 'v2',
            ]
        );

        $roomurl = (string) ($newresp['data']['url'] ?? '');
        $sessionid = (string) ($newresp['data']['session_id'] ?? '');
        if ($roomurl === '' || $sessionid === '') {
            throw new \moodle_exception(
                'chat:error',
                'local_ai_course_assistant',
                '',
                null,
                'HeyGen: streaming.new response missing url or session_id.'
            );
        }

        $viewer = new \moodle_url('/local/ai_course_assistant/talking_avatar_viewer.php', [
            'provider' => 'heygen',
            'sid' => $sessionid,
            'lang' => $context['lang'] ?? 'en',
        ]);

        return [
            'embed_url' => $viewer->out(false),
            'session_token' => $accesstoken,
            // The id the viewer URL carries as `sid`; ownership checks match on
            // THIS, not on the LiveKit access token above -- storing the token
            // made the legitimate owner fail the viewer's ownership check after
            // the billable vendor session had already been created.
            'upstream_session_id' => $sessionid,
            'provider' => 'heygen',
            'expires_in' => 1800,
            'extras' => [
                'room_url' => $roomurl,
                'access_token' => $newresp['data']['access_token'] ?? '',
            ],
        ];
    }
}
