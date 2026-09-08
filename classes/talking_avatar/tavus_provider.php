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
 * Tavus Conversational Video Interface driver.
 *
 * Tavus is the easiest of the four to embed: POST /v2/conversations
 * returns an iframable conversation_url that hosts the avatar + LLM end
 * to end on Tavus's side. We pass it through to the widget directly,
 * skipping the local viewer page.
 *
 * Reference: https://docs.tavus.io/sections/conversational-video-interface/cvi-overview
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tavus_provider extends base_provider {
    public function get_key(): string {
        return 'tavus';
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
                'Tavus: API key or replica_id (persona_id) not configured.'
            );
        }
        $base = rtrim($this->cfg('base_url', 'https://tavusapi.com'), '/');

        $payload = [
            'replica_id' => $this->cfg('persona_id'),
            'conversation_name' => 'SOLA course ' . (int) ($context['courseid'] ?? 0),
            'conversational_context' => $context['greeting'] ?? '',
            'properties' => [
                'language' => $context['lang'] ?? 'en',
                'max_call_duration' => 1800,
                'participant_left_timeout' => 30,
            ],
        ];
        if ($this->cfg('persona_secondary_id') !== '') {
            $payload['persona_id'] = $this->cfg('persona_secondary_id');
        }

        $resp = $this->http_post_json(
            $base . '/v2/conversations',
            ['x-api-key: ' . $this->cfg('api_key')],
            $payload
        );

        $convurl = (string) ($resp['conversation_url'] ?? '');
        $convid = (string) ($resp['conversation_id'] ?? '');
        if ($convurl === '' || $convid === '') {
            throw new \moodle_exception(
                'chat:error',
                'local_ai_course_assistant',
                '',
                null,
                'Tavus: response missing conversation_url or conversation_id.'
            );
        }
        // Tavus's conversation_url is itself iframable, so we don't need a
        // local viewer page — point the widget directly at it.
        return [
            'embed_url' => $convurl,
            'session_token' => $convid,
            'provider' => 'tavus',
            'expires_in' => 1800,
        ];
    }
}
