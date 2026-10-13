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

/**
 * Cuts a long conversation history down to something a provider will accept.
 *
 * Used for ONE retry after a provider rejects a request outright (HTTP 400,
 * 413 or 422): the newest exchange is almost always enough to answer the
 * question, and older turns are the only part of the request that grows with
 * the conversation. Nothing here runs on a request that succeeded.
 *
 * The result is shaped so the strictest provider takes it: no empty turns,
 * no two turns in a row from the same role (they are joined), it starts with
 * a user turn, and the final turn (the question being answered) is never
 * dropped. When an unanswered question sits right before it, the two are
 * joined, oldest first.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class history_trimmer {
    /** @var int Messages kept on the retry: the newest two exchanges. */
    public const KEEP_MESSAGES = 4;

    /**
     * Trim a history for the retry.
     *
     * @param array $messages Chronological ['role' => ..., 'content' => ...] entries.
     * @param int $keep Most recent messages to keep, after tidying.
     * @return array The tidied, shortened history, or [] when nothing usable is left.
     */
    public static function trim(array $messages, int $keep = self::KEEP_MESSAGES): array {
        $tidy = [];
        foreach ($messages as $message) {
            if (!is_array($message) || !in_array($message['role'] ?? '', ['user', 'assistant'], true)) {
                continue;
            }
            $content = $message['content'] ?? '';
            if (is_string($content) && trim($content) === '') {
                continue;
            }
            $last = count($tidy) - 1;
            if (
                $last >= 0 && $tidy[$last]['role'] === $message['role']
                && is_string($content) && is_string($tidy[$last]['content'])
            ) {
                $tidy[$last]['content'] .= "\n\n" . $content;
                continue;
            }
            $tidy[] = $message;
        }

        $tidy = array_slice($tidy, -max(1, $keep));
        while ($tidy && $tidy[0]['role'] !== 'user') {
            array_shift($tidy);
        }
        return array_values($tidy);
    }

    /**
     * Whether retrying with a trimmed history is worth a call: the history is
     * longer than the retry would send, and trimming leaves something to send.
     *
     * @param array $messages The history that was rejected.
     * @param int $keep Messages the retry keeps.
     * @return array The trimmed history to retry with, or [] when a retry would not help.
     */
    public static function retry_candidate(array $messages, int $keep = self::KEEP_MESSAGES): array {
        if (count($messages) <= $keep) {
            return [];
        }
        $trimmed = self::trim($messages, $keep);
        return (count($trimmed) < count($messages)) ? $trimmed : [];
    }
}
