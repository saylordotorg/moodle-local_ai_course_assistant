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
 * A turn refused by the quiz lock must not write anything first (S11).
 *
 * Found on staging, 2026-09-02. base_provider is the enforcement chokepoint and
 * it does refuse, but both entry points reached it only AFTER persisting the
 * learner's message and writing a `message_sent` audit row. So a learner blocked
 * mid-quiz got a question in their transcript with no answer under it, and the
 * audit log -- the single row an academic-integrity review would query --
 * recorded `message_sent` for a turn that was refused.
 *
 * This pins the ordering in the source rather than the runtime, because both
 * entry points are top-level scripts (sse.php streams and exits; the external
 * function needs a live provider) and neither is reachable from PHPUnit without
 * standing up far more than the property being protected.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\quiz_lock
 */
final class quiz_lock_refusal_ordering_test extends \advanced_testcase {

    /**
     * Offset of the first match, or -1.
     *
     * Named offset_of, not at(): PHPUnit\Framework\TestCase already declares a
     * public static at(), and redeclaring it private is a fatal error.
     *
     * @param string $haystack
     * @param string $needle
     * @return int
     */
    private static function offset_of(string $haystack, string $needle): int {
        $pos = strpos($haystack, $needle);
        return $pos === false ? -1 : (int) $pos;
    }

    /**
     * sse.php must evaluate the lock before it persists or audits.
     */
    public function test_sse_checks_lock_before_persisting_and_auditing(): void {
        global $CFG;
        $src = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/sse.php');

        $lock    = self::offset_of($src, 'quiz_lock::active_attempt');
        $persist = self::offset_of($src, '// Save user message with interaction context.');
        $audit   = self::offset_of($src, "audit_logger::log('message_sent'");

        $this->assertGreaterThan(-1, $lock, 'sse.php no longer checks the quiz lock at all');
        $this->assertGreaterThan(-1, $persist, 'user-message persist anchor moved');
        $this->assertGreaterThan(-1, $audit, 'message_sent audit anchor moved');

        $this->assertLessThan($persist, $lock,
            'sse.php persists the learner message before checking the quiz lock');
        $this->assertLessThan($audit, $lock,
            'sse.php writes a message_sent audit row before checking the quiz lock');
    }

    /**
     * The external function must do the same. It previously had no check at
     * all and relied entirely on base_provider, which runs after both writes.
     */
    public function test_web_service_checks_lock_before_persisting_and_auditing(): void {
        global $CFG;
        $src = file_get_contents(
            $CFG->dirroot . '/local/ai_course_assistant/classes/external/send_message.php'
        );

        $lock    = self::offset_of($src, 'quiz_lock::active_attempt');
        $persist = self::offset_of($src, '// Save user message.');
        $audit   = self::offset_of($src, "'message_sent'");

        $this->assertGreaterThan(-1, $lock,
            'send_message.php does not check the quiz lock; it relies on base_provider, '
            . 'which runs after the message and the audit row are already written');
        $this->assertLessThan($persist, $lock,
            'send_message.php persists the learner message before checking the quiz lock');
        $this->assertLessThan($audit, $lock,
            'send_message.php writes a message_sent audit row before checking the quiz lock');
    }

    /**
     * Both refusals must be recorded, and with a surface that identifies them.
     */
    public function test_both_paths_record_the_refusal(): void {
        global $CFG;
        $base = $CFG->dirroot . '/local/ai_course_assistant/';
        $sse = file_get_contents($base . 'sse.php');
        $ws  = file_get_contents($base . 'classes/external/send_message.php');

        $this->assertStringContainsString('quiz_lock::record_refusal', $sse);
        $this->assertStringContainsString('quiz_lock::record_refusal', $ws);
        $this->assertStringContainsString("'webservice'", $ws,
            'the web-service refusal should be distinguishable from the chat one');
    }
}
