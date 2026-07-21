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

use local_ai_course_assistant\provider\coreai_provider;
use local_ai_course_assistant\provider\base_provider;

/**
 * Tests for the Moodle core_ai provider adapter and the 'auto' provider default.
 *
 * @package    local_ai_course_assistant
 * @covers     \local_ai_course_assistant\provider\coreai_provider
 */
final class coreai_provider_test extends \advanced_testcase {

    /**
     * extract_text picks the generated text across the response-data key names
     * core_ai has used across Moodle versions (the version-defensive matrix).
     */
    public function test_extract_text_version_variants(): void {
        $m = new \ReflectionMethod(coreai_provider::class, 'extract_text');
        $m->setAccessible(true);
        $this->assertSame('hi', $m->invoke(null, ['generatedcontent' => 'hi']));
        $this->assertSame('hi', $m->invoke(null, ['content' => 'hi']));
        $this->assertSame('hi', $m->invoke(null, ['response' => 'hi']));
        $this->assertSame('hi', $m->invoke(null, ['completion' => 'hi']));
        $this->assertSame('hi', $m->invoke(null, ['text' => 'hi']));
        $this->assertSame('', $m->invoke(null, []));
        $this->assertSame('', $m->invoke(null, ['generatedcontent' => '']));
        $this->assertSame('', $m->invoke(null, ['unknownkey' => 'hi']));
        // First non-empty known key wins.
        $this->assertSame('a', $m->invoke(null, ['generatedcontent' => 'a', 'content' => 'b']));
    }

    /**
     * flatten_conversation folds the system prompt and turns into one prompt,
     * labelled by role and ending with an open "Assistant:" cue.
     */
    public function test_flatten_conversation(): void {
        $m = new \ReflectionMethod(coreai_provider::class, 'flatten_conversation');
        $m->setAccessible(true);
        $out = $m->invoke(null, 'SYS', [
            ['role' => 'user', 'content' => 'q1'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user', 'content' => 'q2'],
        ]);
        $this->assertStringContainsString('[System instructions]', $out);
        $this->assertStringContainsString('SYS', $out);
        $this->assertStringContainsString('User: q1', $out);
        $this->assertStringContainsString('Assistant: a1', $out);
        $this->assertStringContainsString('User: q2', $out);
        $this->assertStringEndsWith('Assistant:', $out);
        // Empty-content turns are skipped.
        $out2 = $m->invoke(null, '', [['role' => 'user', 'content' => '']]);
        $this->assertSame('Assistant:', trim($out2));
    }

    /**
     * In the SOLA test environment the core_ai subsystem is not present, so
     * is_available must return false (and thus 'auto' will not pick coreai).
     */
    public function test_is_available_false_without_coreai(): void {
        $this->resetAfterTest();
        $this->assertFalse(coreai_provider::is_available());
    }

    /**
     * The 'auto' provider resolves to a direct provider when there is no usable
     * SOLA key and no core_ai. A configured key keeps it on the direct path.
     */
    public function test_auto_resolves_to_direct_provider(): void {
        $this->resetAfterTest();
        $m = new \ReflectionMethod(base_provider::class, 'resolve_auto_provider');
        $m->setAccessible(true);
        // No key, no core_ai available -> historical direct default.
        $this->assertSame('openai', $m->invoke(null, []));
        // A per-course/site key means the admin wants a direct provider.
        $this->assertSame('openai', $m->invoke(null, ['apikey' => 'sk-test']));
    }
}
