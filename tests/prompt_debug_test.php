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
 * Tests for the prompt debug log: format -> parse round trip, rotation, and
 * the playground's simulated-chunk parsing.
 *
 * The log format is the contract between sse.php (writer) and
 * prompt_debug_view.php (parser); these tests pin it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\prompt_debug
 */
final class prompt_debug_test extends \advanced_testcase {

    /**
     * Standard entry parts used across tests; override per test as needed.
     *
     * @param array $overrides
     * @return array
     */
    private function parts(array $overrides = []): array {
        return array_merge([
            'timestamp'         => '2026-06-12 10:00:00',
            'courseid'          => 42,
            'userid'            => 7,
            'provider'          => 'gemini',
            'systemprompt'      => "You are SOLA.\n## Current Page Content\nPage body here.\n## Current focus\nTopic.",
            'budgetchars'       => 8000,
            'breakdowntext'     => "  1880  current_page_content\n   120  topic_focus",
            'retrievedchunks'   => [],
            'history'           => [
                ['role' => 'user', 'content' => 'What is supply?'],
                ['role' => 'assistant', 'content' => 'Supply is...'],
            ],
            'message'           => 'And what about demand?',
            'attachmentmeta'    => null,
            'pdfextractedchars' => 0,
        ], $overrides);
    }

    public function test_round_trip_basic_entry(): void {
        $entry = prompt_debug::format_entry($this->parts());
        $parsed = prompt_debug::parse_entries($entry, 10);

        $this->assertCount(1, $parsed);
        $e = $parsed[0];
        $this->assertSame('2026-06-12 10:00:00', $e['timestamp']);
        $this->assertSame(42, $e['courseid']);
        $this->assertSame(7, $e['userid']);
        $this->assertSame('gemini', $e['provider']);
        $this->assertStringContainsString('chars', $e['total']);
        $this->assertStringContainsString('8000', $e['budget']);
        $this->assertStringContainsString('You are SOLA.', $e['system_prompt']);
        $this->assertStringContainsString('What is supply?', $e['history']);
        $this->assertSame('And what about demand?', $e['message']);
        $this->assertFalse($e['has_attachment']);
        $this->assertFalse($e['has_chunks']);
        $this->assertSame(0, $e['chunk_count']);
    }

    public function test_round_trip_retrieved_chunks(): void {
        $entry = prompt_debug::format_entry($this->parts([
            'retrievedchunks' => [
                ['content' => 'Chunk one body', 'score' => 0.91234, 'cmid' => 12, 'modtype' => 'page'],
                ['content' => 'Chunk two body', 'score' => 0.7, 'cmid' => 13, 'modtype' => 'book'],
            ],
        ]));
        $parsed = prompt_debug::parse_entries($entry, 10);

        $this->assertCount(1, $parsed);
        $e = $parsed[0];
        $this->assertTrue($e['has_chunks']);
        $this->assertSame(2, $e['chunk_count']);
        $this->assertStringContainsString('score=0.9123', $e['chunks']);
        $this->assertStringContainsString('cmid=12 modtype=page', $e['chunks']);
        $this->assertStringContainsString('Chunk two body', $e['chunks']);
        // The chunks block must not bleed into the system prompt block.
        $this->assertStringNotContainsString('Chunk one body', $e['system_prompt']);
    }

    public function test_round_trip_attachment(): void {
        $entry = prompt_debug::format_entry($this->parts([
            'attachmentmeta'    => ['filename' => 'notes.pdf', 'mime' => 'application/pdf', 'size' => 1234],
            'pdfextractedchars' => 987,
        ]));
        $parsed = prompt_debug::parse_entries($entry, 10);

        $e = $parsed[0];
        $this->assertTrue($e['has_attachment']);
        $this->assertStringContainsString('filename=notes.pdf', $e['attachment']);
        $this->assertStringContainsString('pdf_extracted_chars=987', $e['attachment']);
    }

    public function test_multiple_entries_newest_first_and_limit(): void {
        $log = '';
        foreach ([1, 2, 3] as $n) {
            $log .= prompt_debug::format_entry($this->parts([
                'timestamp' => "2026-06-12 10:0{$n}:00",
                'courseid'  => $n,
            ]));
        }
        $all = prompt_debug::parse_entries($log, 10);
        $this->assertCount(3, $all);
        $this->assertSame(3, $all[0]['courseid']); // Newest first.
        $this->assertSame(1, $all[2]['courseid']);

        $limited = prompt_debug::parse_entries($log, 2);
        $this->assertCount(2, $limited);
        $this->assertSame(3, $limited[0]['courseid']);
    }

    public function test_malformed_block_is_skipped(): void {
        $good = prompt_debug::format_entry($this->parts());
        $garbage = "this is not an entry\n================================================================\n\n";
        $parsed = prompt_debug::parse_entries($garbage . $good, 10);
        $this->assertCount(1, $parsed);
        $this->assertSame(42, $parsed[0]['courseid']);
    }

    public function test_section_states(): void {
        $sections = "  1880  current_page_content\n"
            . "   500  topic_focus [TRUNCATED]\n"
            . "     0  course_structure [DROPPED]";
        $this->assertSame('kept', prompt_debug::section_state($sections, 'current_page_content', '', ''));
        $this->assertSame('truncated', prompt_debug::section_state($sections, 'topic_focus', '', ''));
        $this->assertSame('dropped', prompt_debug::section_state($sections, 'course_structure', '', ''));
        $this->assertSame('absent', prompt_debug::section_state($sections, 'never_added', '', ''));
        // Zero chars counts as dropped even without the flag.
        $this->assertSame('dropped', prompt_debug::section_state("     0  current_page_content", 'current_page_content', '', ''));
        // Fallback: heading present in the body when the breakdown is missing.
        $this->assertSame('kept', prompt_debug::section_state('', 'current_page_content',
            "intro\n## Current Page Content\nbody", '## Current Page Content'));
    }

    public function test_parsed_states_flow_to_entry(): void {
        $entry = prompt_debug::format_entry($this->parts([
            'breakdowntext' => "  1880  current_page_content\n     0  topic_focus [DROPPED]",
        ]));
        $e = prompt_debug::parse_entries($entry, 10)[0];
        $this->assertTrue($e['page_kept']);
        $this->assertTrue($e['topic_dropped']);
    }

    public function test_extract_named_block_edges(): void {
        $block = "=== h ===\n--- HISTORY (2 messages) ---\nline a\nline b\n\n--- CURRENT USER MESSAGE (3 chars) ---\nhey";
        // Name with a parenthesised suffix still matches; body extends to the next marker.
        $this->assertSame("line a\nline b", prompt_debug::extract_named_block($block, 'HISTORY'));
        // Final block runs to end of string.
        $this->assertSame('hey', prompt_debug::extract_named_block($block, 'CURRENT USER MESSAGE'));
        $this->assertNull(prompt_debug::extract_named_block($block, 'ATTACHMENT'));
    }

    public function test_fallback_section_split_when_no_breakdown(): void {
        $entry = prompt_debug::format_entry($this->parts(['breakdowntext' => null]));
        $this->assertStringContainsString("Sections:\n", $entry);
        $this->assertStringContainsString('PREAMBLE', $entry);
    }

    public function test_write_entry_appends_and_rotates(): void {
        $this->resetAfterTest();
        $logpath = prompt_debug::log_path();
        @mkdir(dirname($logpath), 0777, true);
        @unlink($logpath);

        prompt_debug::write_entry("first\n");
        prompt_debug::write_entry("second\n");
        $this->assertSame("first\nsecond\n", file_get_contents($logpath));

        // Exceed the rotation threshold: the next write truncates first.
        file_put_contents($logpath, str_repeat('x', prompt_debug::MAX_LOG_BYTES + 1));
        prompt_debug::write_entry("fresh\n");
        $this->assertSame("fresh\n", file_get_contents($logpath));
        @unlink($logpath);
    }

    public function test_sim_chunks_from_text(): void {
        $raw = "First chunk body\n---\n\nSecond chunk body\n---\n   \n---\nThird";
        $chunks = prompt_debug::sim_chunks_from_text($raw);
        $this->assertCount(3, $chunks);
        $this->assertSame('First chunk body', $chunks[0]['content']);
        $this->assertSame('sim', $chunks[0]['modtype']);
        $this->assertSame(1.0, $chunks[0]['score']);
        $this->assertSame('Third', $chunks[2]['content']);
        $this->assertSame([], prompt_debug::sim_chunks_from_text("  \n---\n  "));
    }

    public function test_round_trip_history_count_header(): void {
        $entry = prompt_debug::format_entry($this->parts());
        $this->assertStringContainsString('--- HISTORY (2 messages) ---', $entry);
        $e = prompt_debug::parse_entries($entry, 10)[0];
        $this->assertStringContainsString('[0] user', $e['history']);
        $this->assertStringContainsString('[1] assistant', $e['history']);
    }
}
