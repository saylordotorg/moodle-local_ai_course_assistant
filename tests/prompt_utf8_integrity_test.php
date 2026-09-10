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
 * Prompt truncation must not split a multibyte character.
 *
 * A byte-wise cut in the middle of a UTF-8 sequence had two consequences that
 * both presented as something else. json_encode() returns false on invalid
 * UTF-8, and returned unchecked from a `: string` method PHP coerces that to
 * '' -- so the provider got an EMPTY body and replied with a bare 400 naming
 * nothing. And the fence bookkeeping uses a /u regex, which also returns false
 * on invalid UTF-8, so the closing [[/UNTRUSTED]] marker was never re-added and
 * the rest of the prompt read as if it sat inside the untrusted region.
 *
 * These are provable with real bytes on any database, unlike the ordering
 * defect fixed alongside them.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class prompt_utf8_integrity_test extends \advanced_testcase {

    /**
     * Call the private truncator.
     *
     * @param string $content Text to cut.
     * @param int $newlen Byte budget.
     * @return string
     */
    private function truncate(string $content, int $newlen): string {
        $m = new \ReflectionMethod(\local_ai_course_assistant\prompt\builder::class, 'truncate_content');
        $m->setAccessible(true);
        return $m->invoke(null, $content, $newlen);
    }

    /**
     * Cutting at every byte offset through multibyte text must always leave
     * valid UTF-8 -- not merely at the offsets a hand-picked example uses.
     */
    public function test_truncation_never_produces_invalid_utf8(): void {
        $this->resetAfterTest();

        // Two-byte (é), three-byte (中), and four-byte (emoji) sequences, so every
        // continuation-byte length is exercised.
        $text = 'cafés and 中文 and ' . "\u{1F600}" . ' tail padding to give the cut room';

        for ($len = 1; $len <= strlen($text); $len++) {
            $cut = $this->truncate($text, $len);
            $this->assertTrue(
                mb_check_encoding($cut, 'UTF-8'),
                "Truncating to {$len} bytes produced invalid UTF-8. json_encode() returns "
                . 'false on this, which becomes an empty provider request body and an '
                . 'unattributable HTTP 400.'
            );
            $this->assertNotFalse(
                json_encode(['content' => $cut]),
                "Truncating to {$len} bytes produced a payload json_encode() cannot encode."
            );
        }
    }

    /**
     * The cut must still respect the byte budget it was given.
     */
    public function test_truncation_still_respects_the_byte_budget(): void {
        $this->resetAfterTest();

        // truncate_content() deliberately appends a truncation notice (and any
        // closing fence), so the RETURNED string is legitimately longer than the
        // budget. What must respect the budget is the content kept from the
        // original -- assert on that, not on the whole return value.
        $notice = "\n[…truncated by prompt budget…]";
        $text = str_repeat('中', 200);

        foreach ([10, 47, 100, 301] as $len) {
            $out = $this->truncate($text, $len);
            $this->assertStringEndsWith($notice, $out, 'the truncation notice is missing');

            $kept = substr($out, 0, -strlen($notice));
            $this->assertLessThanOrEqual(
                $len,
                strlen($kept),
                "Budget {$len}: the retained content is denominated in bytes and must not "
                . 'exceed it.'
            );
            // A boundary-safe cut may drop up to 3 bytes of a 4-byte sequence, never more.
            $this->assertGreaterThan(
                $len - 4,
                strlen($kept),
                "Budget {$len}: the cut discarded far more than one character boundary, "
                . 'so the budget is being wasted.'
            );
        }
    }

    /**
     * The untrusted fence must be re-closed even when the cut lands inside
     * multibyte content -- the /u regex that does the bookkeeping silently
     * fails on invalid UTF-8, so this only works if the cut is clean.
     */
    public function test_untrusted_fence_is_reclosed_across_multibyte_content(): void {
        $this->resetAfterTest();

        $label = 'Course材料';
        $body = 'Some reference text that runs on for a while 中';
        $content = "[[UNTRUSTED {$label} \u{2014}]]{$body}";

        // Choose a budget that lands INSIDE the trailing 3-byte character, so a
        // raw byte cut is guaranteed to produce invalid UTF-8 here. Picking an
        // arbitrary offset does not reliably split anything, which would make
        // this test pass against the very defect it exists to catch.
        $budget = strlen($content) - 1;
        $this->assertFalse(
            mb_check_encoding(substr($content, 0, $budget), 'UTF-8'),
            'test setup is wrong: this budget does not split a character, so the test '
            . 'would pass against the unfixed byte cut'
        );

        $cut = $this->truncate($content, $budget);

        $this->assertTrue(mb_check_encoding($cut, 'UTF-8'), 'cut is not valid UTF-8');
        $this->assertStringContainsString(
            '[[/UNTRUSTED',
            $cut,
            'The truncated section left its untrusted fence open. Everything after it in '
            . 'the assembled prompt -- persona, house style, the safety block -- then reads '
            . 'as if it were inside the untrusted region.'
        );
    }

    /**
     * The outbound encoder must never hand a provider an empty body.
     */
    public function test_encode_payload_never_returns_empty_on_bad_utf8(): void {
        $this->resetAfterTest();

        $m = new \ReflectionMethod(
            \local_ai_course_assistant\provider\base_provider::class,
            'encode_payload'
        );
        $m->setAccessible(true);

        // A deliberately malformed byte sequence, as if something upstream slipped.
        $json = $m->invoke(null, ['messages' => [['role' => 'user', 'content' => "bad \xC3 byte"]]]);

        // The log line IS the point -- substituting silently would have hidden the
        // builder bug permanently, so assert the developer notice actually fired.
        $this->assertDebuggingCalled();

        $this->assertIsString($json);
        $this->assertNotSame('', $json, 'an empty body is the failure this guard exists to prevent');
        $this->assertNotFalse(json_decode($json, true), 'the repaired payload must be decodable');
    }
}
