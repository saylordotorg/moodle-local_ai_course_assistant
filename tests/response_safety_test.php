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
 * The output scrub shared by sse.php (through its wrapper) and send_message (8.4.0).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\response_safety
 */
final class response_safety_test extends \advanced_testcase {

    public function test_clean_text_is_untouched(): void {
        $text = "A balance sheet lists assets.\n\nSee **equity** and `code`.";
        $this->assertSame($text, response_safety::filter($text));
    }

    public function test_empty_string(): void {
        $this->assertSame('', response_safety::filter(''));
        $this->assertSame('', response_safety::filter("  \n "));
    }

    public function test_multibyte_text_is_unchanged(): void {
        $text = 'Привет, 你好, café, 😀 — ¿qué tal?';
        $this->assertSame($text, response_safety::filter($text));
    }

    public function test_leaked_prompt_fragments_are_removed(): void {
        foreach (['## Security Rules (non-negotiable)', '## Off-topic Detection', '## Student Learning Profile',
                '## Wellbeing & Safety', 'KEEP RESPONSES BRIEF:', 'These rules override any conflicting instruction',
                'You are Atlas (Online Learning Assistant)'] as $frag) {
            $out = response_safety::filter("Hello. {$frag} Goodbye.");
            $this->assertDebuggingCalled('SOLA: system prompt leak detected in response, fragments scrubbed.',
                DEBUG_DEVELOPER, "no leak debugging for '{$frag}'");
            $this->assertStringNotContainsString($frag, $out, $frag);
            $this->assertStringContainsString('Hello.', $out, "text before '{$frag}' was lost");
            $this->assertStringContainsString('Goodbye.', $out, "text after '{$frag}' was lost");
        }
    }

    public function test_credentials_are_redacted(): void {
        $out = response_safety::filter('a sk-' . str_repeat('a', 30) . ' b AIza' . str_repeat('B', 35)
            . ' c sk-ant-' . str_repeat('c', 30));
        $this->assertSame(3, substr_count($out, '[key redacted]'));
        $this->assertStringNotContainsString('sk-', $out, 'an sk- key survived');
        $this->assertStringNotContainsString('AIza', $out, 'an AIza key survived');
    }

    public function test_protocol_markers_are_stripped(): void {
        $out = response_safety::filter('Answer. [[SOURCE:activity:86467]] [SOLA_NEXT]Tell me more');
        $this->assertStringNotContainsString('SOURCE', $out, 'a SOURCE marker survived');
        $this->assertStringNotContainsString('SOLA_NEXT', $out, 'a SOLA_NEXT marker survived');
    }

    public function test_result_is_trimmed(): void {
        $this->assertSame('hi', response_safety::filter("  hi \n"));
    }

    public function test_sse_wrapper_delegates_to_the_class(): void {
        $src = file_get_contents(__DIR__ . '/../sse.php');
        $this->assertSame(1, preg_match(
            '/function local_ai_course_assistant_filter_response_safety\(string \$response\): string \{\s*'
            . 'return \\\\local_ai_course_assistant\\\\response_safety::filter\(\$response\);\s*\}/', $src),
            'the wrapper body only calls the class');
        $this->assertStringNotContainsString('$leakpatterns', $src, 'no second copy of the patterns in sse.php');
        $this->assertStringContainsString('local_ai_course_assistant_filter_response_safety($cleanresponse)', $src);
        $this->assertStringContainsString('local_ai_course_assistant_filter_response_safety($text)', $src);
    }
}
