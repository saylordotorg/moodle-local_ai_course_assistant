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
 * One authority for removing SOLA's protocol markers from model output.
 *
 * The system prompt asks the model to emit machine-readable markers -- the
 * SOLA_NEXT suggestion block, SOLA_SCORE, SOURCE citations, OFF_TOPIC and
 * NEEDS_ESCALATION. None of them may ever be shown to a person.
 *
 * Before v7.4.7 that guarantee was maintained by five separate regex sets that
 * had drifted apart: amd/src/chat.js, amd/src/learning_radar.js,
 * classes/radar_delivery.php, sse.php and a copy inlined in the mobile template.
 * The consequences were not hypothetical. A production comparison run on
 * 2026-09-12 found a literal "[SOLA_NEXT]Tell me more" in a learner's answer and
 * raw "[[SOURCE:activity:86467]]" markers inline in two models' replies, because
 * every one of those strippers required a CLOSING tag and the model had emitted
 * an unterminated one. v7.4.7 fixed the web drawer. It did not fix the Moodle
 * mobile app, whose only strip is client-side and still closed-form, nor the
 * teacher-facing transcript CSV, which reads the stored message verbatim.
 *
 * So the authority moves here, server-side, and runs before the text is stored.
 * The client-side strippers stay as belt and braces for text that predates this
 * class, but they are no longer the only thing standing between a protocol token
 * and a learner.
 *
 * Two rules the old regexes each got wrong somewhere:
 *   1. An opening marker with no closing tag must still be removed. A truncated
 *      response is exactly when this happens, and it was the reported defect.
 *   2. Both the single-bracket form the older prompt used ([SOURCE:page]) and the
 *      double-bracket cmid form the current prompt asks for
 *      ([[SOURCE:activity:86467]]) must match, with or without an id.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_course_assistant;

defined('MOODLE_INTERNAL') || die();

/**
 * Removal of protocol markers from text that is about to be shown or stored.
 */
final class protocol_markers {

    /** Paired block markers: everything from the opener to its closer. */
    private const PAIRED = ['SOLA_NEXT', 'SOLA_SCORE'];

    /**
     * Longest tail an unterminated opener may carry and still be treated as a
     * truncated chip list rather than the learner's answer.
     */
    private const MAX_TERMINAL_PAYLOAD = 200;

    /** Standalone single-line markers. */
    private const STANDALONE = ['OFF_TOPIC', 'NEEDS_ESCALATION'];

    /**
     * Remove every protocol marker from a block of model output.
     *
     * Order matters: paired blocks are removed closed-form first, so that a
     * well-formed block is consumed as a unit before the unterminated-opener
     * sweep can eat the rest of the message from a stray opener onwards.
     *
     * @param string $text Raw model output.
     * @return string Text safe to display or store.
     */
    public static function strip(string $text): string {
        if ($text === '') {
            return '';
        }

        foreach (self::PAIRED as $marker) {
            $m = preg_quote($marker, '/');
            // Closed form. Whitespace is tolerated inside both tags because
            // models emit "[/SOLA_NEXT ]" and "[/sola_next]" often enough to
            // matter, and a mangled closer used to survive into a chip label.
            // The payload is "tempered": it may not itself contain another
            // opening marker. Without that, a lazy match starting at a STRAY
            // marker runs all the way to the terminal block's closer and deletes
            // the answer in between -- which is exactly the regression the
            // client-side fix hit first, and which this class's own test caught
            // here before it shipped.
            $text = self::replace(
                '/\n*\[\s*' . $m . '\s*\]((?:(?!\[\s*' . $m . '\s*\])[\s\S])*?)'
                    . '\[\s*\/\s*' . $m . '\s*\]\s*/i',
                '',
                $text
            );
            // Unterminated opener. Deleting to end-of-string is right ONLY when
            // the tail is a truncated chip list -- one short line. When a stray
            // marker sits mid-answer the tail is the learner's actual answer, and
            // taking it would destroy more than the leak did. So bound it the
            // same way the client does: single line, short. Otherwise remove the
            // marker token alone and keep every character of the prose.
            $text = preg_replace_callback(
                '/\n*\[\s*' . $m . '\s*\]([\s\S]*)$/i',
                static function (array $mt): string {
                    $tail = $mt[1] ?? '';
                    $looksterminal = strpos($tail, "\n") === false
                        && strlen($tail) <= self::MAX_TERMINAL_PAYLOAD;
                    // Non-terminal: drop the marker token only, keep the tail.
                    return $looksterminal ? '' : "\n" . $tail;
                },
                $text
            ) ?? $text;
            // Any opener the callback left behind (it replaced with a newline)
            // is gone; sweep any that remain from a second pass.
            $text = self::replace('/\n*\[\s*' . $m . '\s*\]/i', "\n", $text);
            // A closer with no opener, left behind by an upstream cut.
            $text = self::replace('/\n*\[\s*\/\s*' . $m . '\s*\]\s*/i', '', $text);
        }

        // SOURCE citations: [SOURCE:page], [[SOURCE:activity:86467]], any depth
        // of brackets, with or without an id.
        $text = self::replace('/\n*\[{1,3}\s*SOURCE\s*:[^\[\]]*\]{1,3}\s*/i', '', $text);

        // Internal chunk reference, e.g. [[c:12]].
        $text = self::replace('/\n*\[\[c:\d+\]\]\s*/i', '', $text);

        foreach (self::STANDALONE as $marker) {
            $m = preg_quote($marker, '/');
            $text = self::replace('/\n*\[\s*' . $m . '\s*\]\s*/i', '', $text);
        }

        $text = self::strip_activity_ids($text);

        return rtrim($text);
    }

    /**
     * Remove course-module ids the model copied out of the structure block.
     *
     * The structure block annotates every activity as "Name (id:20057)" so the
     * model can cite it as [SOURCE:activity:20057]. context_builder tells it in
     * so many words never to write an id into prose -- and a measured 8-12% of
     * production replies do it anyway (Learn 2,112/17,265; Degrees 151/1,878).
     * A prompt rule is a request; this is the enforcement. Learners were reading
     * 'Watch the Unit 1 Introduction Video (id:20057)'.
     *
     * Only complete, digit-bearing forms are matched, so "(idea 2)" and a bare
     * "(id)" are untouched, and a fragment split across a stream chunk is held
     * in the caller's carry buffer until it completes.
     *
     * @param string $text
     * @return string
     */
    public static function strip_activity_ids(string $text): string {
        if ($text === '' || strpos($text, 'id') === false && strpos($text, 'ID') === false) {
            return $text;
        }

        // Parenthesised form: "(id:20057)", "(cmid: 3)", "(Activity ID: 89206)".
        $text = self::replace(
            '/[ \t]*\((?:[ \t]*(?:activity|module|course[ \t]+module))?[ \t]*c?mid[ \t]*'
                . '[:#=]?[ \t]*\d+[ \t]*\)/iu',
            '',
            $text
        );
        $text = self::replace(
            '/[ \t]*\((?:[ \t]*(?:activity|module|course[ \t]+module))?[ \t]*id[ \t]*'
                . '[:#=]?[ \t]*\d+[ \t]*\)/iu',
            '',
            $text
        );

        // Bare form: "the Unit 1 Assessment, Activity ID: 89206". Any separator
        // the model used to attach it is taken with it, so no orphaned comma or
        // dash is left behind mid-sentence.
        $text = self::replace(
            '/[ \t]*[,;:\x{2013}\x{2014}-]?[ \t]*\b(?:activity|module)[ \t]+id[ \t]*[:#=][ \t]*\d+/iu',
            '',
            $text
        );

        return $text;
    }

    /**
     * Whether any protocol marker is present.
     *
     * Used by callers that want to log a leak rather than silently clean it.
     *
     * @param string $text
     * @return bool
     */
    public static function has_marker(string $text): bool {
        return $text !== self::strip($text);
    }

    /**
     * preg_replace that never destroys the subject.
     *
     * preg_replace() returns null on a backtrack limit or on invalid UTF-8 in
     * the subject, and a naive (string) cast turns that null into an empty
     * string -- silently deleting the learner's whole answer. Keep the original
     * text when the engine fails.
     *
     * @param string $pattern
     * @param string $replacement
     * @param string $subject
     * @return string
     */
    private static function replace(string $pattern, string $replacement, string $subject): string {
        $out = preg_replace($pattern, $replacement, $subject);
        return $out === null ? $subject : $out;
    }
}
