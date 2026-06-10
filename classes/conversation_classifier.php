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

use local_ai_course_assistant\provider\base_provider;

/**
 * LLM-based classifier that reads a single user+assistant turn and tags
 * it with the best-fit learning objective plus a mastery signal. Runs
 * in a background adhoc task so it never blocks the chat response.
 *
 * Design notes:
 *  - Uses the course's configured provider by default. An admin can
 *    override with mastery_classifier_* settings when cost is a concern.
 *  - Low-confidence or "unclear" signals are dropped so stray turns
 *    don't move the mastery needle.
 *  - Attempts recorded with weight 0.3 by default (conversations are a
 *    noisier signal than quiz answers, which carry weight 1.0).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conversation_classifier {

    /** @var float Default minimum confidence before an attempt is recorded. */
    private const MIN_CONFIDENCE = 0.70;

    /** @var float Default weight for conversation-derived attempts. */
    private const DEFAULT_WEIGHT = 0.30;

    /**
     * Classify one turn and, when signal quality permits, record a mastery attempt.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $usermsgid
     * @param int $assistantmsgid
     * @return array{recorded:bool, objectiveid:int, signal:string, confidence:float}
     */
    public static function classify_and_record(
        int $userid,
        int $courseid,
        int $usermsgid,
        int $assistantmsgid
    ): array {
        if (!objective_manager::is_enabled_for_course($courseid)) {
            return self::nullresult();
        }

        $objectives = objective_manager::list_for_course($courseid);
        if (empty($objectives)) {
            return self::nullresult();
        }

        $usermsg = self::fetch_message($usermsgid);
        $assistantmsg = self::fetch_message($assistantmsgid);
        if ($usermsg === '' || $assistantmsg === '') {
            return self::nullresult();
        }

        $objectivelines = [];
        $validids = [];
        foreach ($objectives as $obj) {
            $label = $obj->code ? "[{$obj->code}] " : '';
            $objectivelines[] = (int) $obj->id . ': ' . $label . $obj->title;
            $validids[(int) $obj->id] = true;
        }

        $schema = [
            'name' => 'mastery_signal',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'objectiveid' => [
                        'type' => 'integer',
                        'description' => 'Matching objective id from the list, or 0 if none fits',
                    ],
                    'signal' => [
                        'type' => 'string',
                        'enum' => ['mastered', 'partial', 'unclear'],
                        'description' => 'Whether the turn demonstrates mastery, partial, or no signal',
                    ],
                    'confidence' => [
                        'type' => 'number',
                        'description' => '0..1, how confident you are in this tag',
                    ],
                ],
                'required' => ['objectiveid', 'signal', 'confidence'],
                'additionalProperties' => false,
            ],
        ];

        $system = "You are a short, precise classifier. Given one turn of a tutoring "
            . "conversation (a student message and the tutor's reply), decide: which learning "
            . "objective was being practised, and how the student performed.\n\n"
            . "Rules:\n"
            . "- Return objectiveid 0 when the turn is off-topic, meta (UI, settings), or "
            . "doesn't clearly engage any listed objective.\n"
            . "- Return signal 'mastered' only when the student demonstrated accurate, "
            . "confident understanding — a direct question with a vague answer doesn't count.\n"
            . "- Return signal 'partial' when the student engaged the concept but made a "
            . "mistake, showed confusion, or asked a clarifying question.\n"
            . "- Return signal 'unclear' when you can't tell from one turn.\n"
            . "- Confidence must honestly reflect uncertainty. A short exchange rarely "
            . "merits confidence above 0.8.\n\n"
            . "## Learning objectives (only these ids are valid)\n"
            . implode("\n", $objectivelines);

        $user = "## Student turn\n" . $usermsg . "\n\n"
            . "## Tutor turn\n" . $assistantmsg . "\n\n"
            . "Return your classification now.";

        try {
            $provider = self::resolve_classifier_provider($courseid);
            $response = $provider->chat_completion(
                $system,
                [['role' => 'user', 'content' => $user]],
                ['response_schema' => $schema, 'max_tokens' => 200]
            );
        } catch (\Throwable $e) {
            debugging('conversation_classifier failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return self::nullresult();
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return self::nullresult();
        }

        $objid = (int) ($decoded['objectiveid'] ?? 0);
        $signal = (string) ($decoded['signal'] ?? 'unclear');
        $confidence = (float) ($decoded['confidence'] ?? 0);

        $threshold = (float) (get_config('local_ai_course_assistant', 'mastery_classifier_threshold')
            ?: self::MIN_CONFIDENCE);
        $weight = (float) (get_config('local_ai_course_assistant', 'mastery_classifier_weight')
            ?: self::DEFAULT_WEIGHT);

        if ($objid <= 0 || !isset($validids[$objid])
                || $signal === 'unclear' || $confidence < $threshold) {
            return [
                'recorded' => false,
                'objectiveid' => $objid,
                'signal' => $signal,
                'confidence' => $confidence,
            ];
        }

        objective_manager::record_attempt(
            $userid,
            $courseid,
            $objid,
            $signal === 'mastered',
            'conversation',
            $weight,
            $usermsgid,
            $confidence
        );

        return [
            'recorded' => true,
            'objectiveid' => $objid,
            'signal' => $signal,
            'confidence' => $confidence,
        ];
    }

    /**
     * @param int $msgid
     * @return string
     */
    private static function fetch_message(int $msgid): string {
        global $DB;
        $rec = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $msgid], 'message', IGNORE_MISSING);
        return $rec ? trim((string) $rec->message) : '';
    }

    /**
     * @return array
     */
    private static function nullresult(): array {
        return ['recorded' => false, 'objectiveid' => 0, 'signal' => 'unclear', 'confidence' => 0.0];
    }

    /**
     * Resolve which provider runs the classifier turn. If both
     * mastery_classifier_provider and mastery_classifier_model are set,
     * route to that dedicated provider via comparison_providers (so the
     * API key, base URL, and temperature come from the same row admins
     * already manage). Otherwise fall back to the course's primary chat
     * provider — preserving pre-v5.11 behavior on sites that never
     * touched the classifier settings.
     *
     * v5.11.0 wires the previously-orphaned mastery_classifier_model
     * setting. Default 'openai' + 'gpt-4o-mini' saves ~$220/month at
     * 100k Saylor MAU vs running the classifier on the chat-tier model.
     *
     * @param int $courseid
     * @return \local_ai_course_assistant\provider\provider_interface
     */
    private static function resolve_classifier_provider(int $courseid) {
        $providerid = trim((string) get_config('local_ai_course_assistant', 'mastery_classifier_provider'));
        $model = trim((string) get_config('local_ai_course_assistant', 'mastery_classifier_model'));
        if ($providerid !== '' && $model !== '') {
            try {
                return base_provider::create_for_comparison($providerid, $model, $courseid);
            } catch (\Throwable $e) {
                debugging('mastery classifier provider unavailable, falling back to chat tier: '
                    . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return base_provider::create_from_config($courseid);
    }
}
