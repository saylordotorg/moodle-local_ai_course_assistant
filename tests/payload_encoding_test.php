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

defined('MOODLE_INTERNAL') || die();

/**
 * An outbound request body is never empty, whatever is in it.
 *
 * This pins the fix for issue #219. json_encode() returns FALSE on invalid
 * UTF-8, and returned unchecked from a method declared `: string` PHP coerces
 * that to '', so the provider receives an EMPTY BODY and answers with a bare
 * HTTP 400 naming nothing. On staging that presented as "chat is broken on one
 * restored course and fine on another", which is about as unattributable as a
 * failure gets: same code, same provider, same session, different course.
 *
 * The root producer was a byte-wise prompt cut that could slice a multibyte
 * character in half. That is fixed in the builder. This is the boundary guard
 * behind it, and it had no test, which on a defect that reached production and
 * cost a debugging session is the wrong thing to leave untested.
 *
 * @package    local_ai_course_assistant
 * @covers     \local_ai_course_assistant\provider\base_provider::encode_payload
 */
final class payload_encoding_test extends \advanced_testcase {

    /**
     * Reach the protected encoder the providers use.
     *
     * @param array $body The payload.
     * @return string
     */
    private function encode(array $body): string {
        $this->resetDebugging();
        $m = new \ReflectionMethod(provider\base_provider::class, 'encode_payload');
        $m->setAccessible(true);
        return $m->invoke(null, $body);
    }

    /**
     * A clean payload encodes normally.
     *
     * @return void
     */
    public function test_a_valid_payload_encodes_unchanged(): void {
        $json = $this->encode(['model' => 'gemini-2.5-flash', 'contents' => 'hello']);
        $this->assertSame('{"model":"gemini-2.5-flash","contents":"hello"}', $json);
    }

    /**
     * Invalid UTF-8 is repaired, never turned into an empty body.
     *
     * A lone continuation byte is exactly what a byte-wise cut through a
     * multibyte character leaves behind.
     *
     * @return void
     */
    public function test_invalid_utf8_is_repaired_rather_than_dropped(): void {
        $broken = "course content ending mid-character \xE2\x82";

        $this->assertFalse(
            json_encode(['contents' => $broken]),
            'precondition: this payload really is unencodable, otherwise the test proves nothing'
        );

        $json = $this->encode(['model' => 'gemini-2.5-flash', 'contents' => $broken]);

        $this->assertNotSame('', $json, 'An empty body is what produced the unattributable HTTP 400.');
        $this->assertStringContainsString('gemini-2.5-flash', $json, 'the rest of the payload survives');
        $this->assertNotFalse(
            json_decode($json, true),
            'The repaired body must be parseable JSON. A learner mid-conversation should not lose '
                . 'their turn over one bad byte, and the vendor cannot tell us which byte it was.'
        );

        // The log line is not incidental, it is the point. Substituting quietly
        // would have hidden the builder bug that produced the bad bytes, so the
        // repair must always be announced, and this asserts it was.
        $this->assertDebuggingCalled(
            null,
            DEBUG_DEVELOPER,
            'Repairing a payload without naming it in the log would hide the upstream producer '
                . 'of the invalid bytes, which is how this defect survived to production.'
        );
    }

    /**
     * A structurally impossible payload throws rather than sending nothing.
     *
     * Substitution cannot save a resource or a recursive structure, and sending
     * an empty body would reproduce the exact failure this method prevents. So
     * it fails locally, where the message names the cause.
     *
     * @return void
     */
    public function test_a_structurally_unencodable_payload_throws(): void {
        $handle = fopen('php://memory', 'r');

        try {
            $this->encode(['model' => 'x', 'stream' => $handle]);
            $this->fail('An unencodable payload must throw rather than send an empty body.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('payload_encode_failed', (string) $e->debuginfo);
        } finally {
            fclose($handle);
            // The first attempt logs before it tries substitution.
            $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        }
    }
}
