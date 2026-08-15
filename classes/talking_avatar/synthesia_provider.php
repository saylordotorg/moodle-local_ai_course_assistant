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
 * Synthesia Agents driver (real-time, 2026 release).
 *
 * Synthesia's Agents product replaces the older video-rendering API for
 * the live use case: an agent-id is created in the Synthesia dashboard,
 * then POST /v2/agents/{id}/sessions returns an iframable embed_url that
 * hosts the avatar + STT/TTS + LLM. The agent itself is configured in
 * Synthesia's UI (knowledge base, persona, allowed origins), so SOLA only
 * needs the agent id and an API key.
 *
 * Reference: https://docs.synthesia.io/reference/agents-overview
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class synthesia_provider extends base_provider {
    public function get_key(): string {
        return 'synthesia';
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
                'Synthesia: API key or agent id (persona_id) not configured.'
            );
        }
        $base = rtrim($this->cfg('base_url', 'https://api.synthesia.io'), '/');
        $agentid = $this->cfg('persona_id');

        $resp = $this->http_post_json(
            $base . '/v2/agents/' . rawurlencode($agentid) . '/sessions',
            ['Authorization: ' . $this->cfg('api_key')],
            [
                'language' => $context['lang'] ?? 'en',
                'metadata' => [
                    'sola_course' => (int) ($context['courseid'] ?? 0),
                    'sola_user' => (int) ($context['userid'] ?? 0),
                ],
                'opening_line' => $context['greeting'] ?? '',
            ]
        );

        $embedurl = (string) ($resp['embed_url'] ?? $resp['session_url'] ?? '');
        $sessionid = (string) ($resp['session_id'] ?? $resp['id'] ?? '');
        if ($embedurl === '' || $sessionid === '') {
            throw new \moodle_exception(
                'chat:error',
                'local_ai_course_assistant',
                '',
                null,
                'Synthesia: response missing embed_url or session_id.'
            );
        }
        return [
            'embed_url' => $embedurl,
            'session_token' => $sessionid,
            'provider' => 'synthesia',
            'expires_in' => (int) ($resp['expires_in'] ?? 1800),
        ];
    }
}
