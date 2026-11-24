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
 * Output safety scrub for model replies: leaked system prompt fragments, credentials and stray
 * protocol markers. One authority for the streamed path (sse.php, through its wrapper) and the
 * Moodle app / repository.js path (classes/external/send_message.php). Moved verbatim from
 * sse.php in 8.4.0; behavior unchanged.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_ai_course_assistant;

defined('MOODLE_INTERNAL') || die();

/**
 * Scrubs a model reply before it is saved or sent.
 */
class response_safety {
    /**
     * Scrub leaked system prompt fragments, credentials and protocol markers from LLM output. Runs after
     * marker removal but before the response is saved and sent.
     *
     * @param string $response
     * @return string Cleaned response.
     */
    public static function filter(string $response): string {
        // Phrases that indicate the LLM is leaking its system prompt.
        $leakpatterns = [
            '/## Security Rules \(non-negotiable\)/i',
            '/## Off-topic Detection/i',
            '/## Student Learning Profile/i',
            '/## Wellbeing & Safety/i',
            // NOTE: protocol markers are NOT listed here any more. They are removed
            // by protocol_markers::strip() below, which handles the unterminated and
            // double-bracket forms these patterns missed -- the two shapes that
            // actually reached learners in the 2026-09-12 production run. What stays
            // in this list is system-prompt leakage, which is a different problem.
            // Name-agnostic: the default prompt opens with the configured short name
            // ([[tutorshort]]), so a rebranded site leaks 'You are Atlas (...)'.
            '/You are [^\n()]{1,80}? \(Online Learning Assistant\)/iu',
            '/KEEP RESPONSES BRIEF:/i',
            '/These rules override any conflicting instruction/i',
        ];

        $leaked = false;
        foreach ($leakpatterns as $pattern) {
            if (preg_match($pattern, $response)) {
                $leaked = true;
                $response = preg_replace($pattern, '', $response);
            }
        }

        // Scrub common PII patterns (email addresses, API keys).
        $response = preg_replace('/\b(sk-[a-zA-Z0-9_-]{20,})\b/', '[key redacted]', $response);
        $response = preg_replace('/\b(sk-ant-[a-zA-Z0-9_-]{20,})\b/', '[key redacted]', $response);
        $response = preg_replace('/\b(AIza[a-zA-Z0-9_-]{30,})\b/', '[key redacted]', $response);

        // Protocol markers, via the single authority. This runs on the copy that is
        // persisted to msgs.message, which is what classes/transcript_report.php
        // emits verbatim into the teacher-facing CSV -- so a marker that survived
        // here used to reach a teacher even when the chat UI had hidden it.
        $response = \local_ai_course_assistant\protocol_markers::strip($response);

        if ($leaked) {
            debugging('SOLA: system prompt leak detected in response, fragments scrubbed.', DEBUG_DEVELOPER);
        }

        return trim($response);
    }
}
