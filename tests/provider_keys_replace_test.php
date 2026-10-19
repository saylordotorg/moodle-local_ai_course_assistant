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
 * Replacing one key in the key table (and in the voice table) edits that key's column and no other byte.
 *
 * Failure messages here never print a stored line or a key: a line that changed is reported by its index only.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider_keys
 * @covers     \local_ai_course_assistant\voice_registry
 * @covers     \local_ai_course_assistant\key_inventory
 */
final class provider_keys_replace_test extends \advanced_testcase {

    /** @var string First OpenAI key. */
    private const OPENAI_ONE = 'sk-proj-REPLACE-FIRST-1111-AAAA';
    /** @var string Second OpenAI key. */
    private const OPENAI_TWO = 'sk-proj-REPLACE-SECOND-2222-BBBB';
    /** @var string Claude key. */
    private const CLAUDE = 'sk-ant-REPLACE-CLAUDE-3333-CCCC';
    /** @var string The new key a rotation writes (concealed before it reaches the method). */
    private const NEWKEY = 'sk-proj-REPLACE-NEWKEY-4444-DDDD';

    /**
     * The stored key table.
     *
     * @return string
     */
    private function stored(): string {
        return (string) get_config('local_ai_course_assistant', 'comparison_providers');
    }

    /**
     * The stored voice table.
     *
     * @return string
     */
    private function voice(): string {
        return (string) get_config('local_ai_course_assistant', 'voice_providers');
    }

    /**
     * Assert two tables differ only on the given line indexes, and say which line differs by index only.
     *
     * @param string $before
     * @param string $after
     * @param array $changed line indexes (int) expected to change
     * @return void
     */
    private function assert_only_lines_changed(string $before, string $after, array $changed): void {
        $old = explode("\n", $before);
        $new = explode("\n", $after);
        $this->assertSame(count($old), count($new), 'the line count is unchanged');
        foreach ($old as $i => $line) {
            if (in_array($i, $changed, true)) {
                $this->assertTrue($line !== $new[$i], "line $i was expected to change and did not");
            } else {
                $this->assertTrue($line === $new[$i], "line $i is not byte-identical");
            }
        }
    }

    public function test_only_the_matching_line_changes_and_every_other_line_is_byte_identical(): void {
        $this->resetAfterTest();
        $lines = [
            'openai|' . secrets::conceal(self::OPENAI_ONE) . '|gpt-4o-mini',
            'openai|' . secrets::conceal(self::OPENAI_TWO) . '|gpt-4o|0.2',
            'claude|' . secrets::conceal(self::CLAUDE) . '|claude-haiku-4-5',
        ];
        $before = implode("\n", $lines);
        set_config('comparison_providers', $before, 'local_ai_course_assistant');
        $newstored = secrets::conceal(self::NEWKEY);

        $changed = provider_keys::replace_key(key_inventory::fingerprint(self::OPENAI_ONE), $newstored);

        $this->assertSame(1, $changed);
        $after = $this->stored();
        $this->assert_only_lines_changed($before, $after, [0]);
        $this->assertTrue(explode("\n", $after)[0] === 'openai|' . $newstored . '|gpt-4o-mini',
            'line 0 is the old line with only the key column replaced');
        $this->assertTrue(self::NEWKEY === provider_keys::rows(true)[0]['key'], 'row 0 reveals the new key');
        $this->assertTrue(self::OPENAI_TWO === provider_keys::rows(true)[1]['key'], 'row 1 keeps its key');
        $this->assertTrue(self::CLAUDE === provider_keys::key_for('claude'), 'the claude row keeps its key');
    }

    public function test_every_line_holding_the_key_changes(): void {
        $this->resetAfterTest();
        $before = 'openai|' . secrets::conceal(self::OPENAI_ONE) . "|gpt-4o-mini\n"
            . 'claude|' . secrets::conceal(self::CLAUDE) . "|m\n"
            . 'openai|' . secrets::conceal(self::OPENAI_ONE) . '|gpt-4o||https://gw.example.test/v1';
        set_config('comparison_providers', $before, 'local_ai_course_assistant');

        $changed = provider_keys::replace_key(key_inventory::fingerprint(self::OPENAI_ONE),
            secrets::conceal(self::NEWKEY));

        $this->assertSame(2, $changed);
        $this->assert_only_lines_changed($before, $this->stored(), [0, 2]);
        $this->assertTrue(self::NEWKEY === provider_keys::rows(true)[2]['key'], 'row 2 reveals the new key');
        $this->assertSame('https://gw.example.test/v1', provider_keys::rows()[2]['baseurl']);
    }

    public function test_a_legacy_plain_text_line_gets_the_new_value_in_its_key_column_and_nothing_else_moves(): void {
        $this->resetAfterTest();
        $legacy = 'OpenAI | ' . self::OPENAI_ONE . ' |gpt-4o-mini,gpt-4o|0.3|https://gw.example.test/v1|extra';
        $before = 'claude|' . secrets::conceal(self::CLAUDE) . "|m\n" . $legacy;
        set_config('comparison_providers', $before, 'local_ai_course_assistant');
        $newstored = secrets::conceal(self::NEWKEY);

        $this->assertSame(1, provider_keys::replace_key(key_inventory::fingerprint(self::OPENAI_ONE), $newstored));

        $after = explode("\n", $this->stored());
        $this->assertTrue($after[1] === str_replace(self::OPENAI_ONE, $newstored, $legacy),
            'the legacy line keeps its label case, its spacing, its models, temperature, base URL and extra column');
        $this->assert_only_lines_changed($before, $this->stored(), [1]);
        $this->assertTrue(self::NEWKEY === provider_keys::key_for('openai'), 'the legacy row reveals the new key');
    }

    public function test_comment_blank_and_crlf_lines_survive_and_a_commented_key_is_left_alone(): void {
        $this->resetAfterTest();
        $comment = '# openai|' . self::OPENAI_ONE . '|parked';
        $before = $comment . "\n\n" . 'claude|' . secrets::conceal(self::CLAUDE) . "|m\r\n"
            . 'openai|' . secrets::conceal(self::OPENAI_ONE) . "|gpt-4o-mini\r\n"
            . '|' . secrets::conceal(self::OPENAI_ONE) . "|providerless\n";
        set_config('comparison_providers', $before, 'local_ai_course_assistant');

        $this->assertSame(1, provider_keys::replace_key(key_inventory::fingerprint(self::OPENAI_ONE),
            secrets::conceal(self::NEWKEY)));

        $after = $this->stored();
        $this->assert_only_lines_changed($before, $after, [3]);
        $this->assertTrue(str_ends_with(explode("\n", $after)[3], "|gpt-4o-mini\r"), 'the changed line keeps its CR');
        $this->assertTrue(str_ends_with($after, "\n"), 'the trailing newline is kept');
    }

    public function test_no_match_returns_zero_and_writes_nothing(): void {
        $this->resetAfterTest();
        $before = 'openai|' . secrets::conceal(self::OPENAI_ONE) . "|gpt-4o-mini\n# comment\n";
        set_config('comparison_providers', $before, 'local_ai_course_assistant');

        $this->assertSame(0, provider_keys::replace_key(key_inventory::fingerprint(self::OPENAI_TWO),
            secrets::conceal(self::NEWKEY)));
        $this->assertSame(0, provider_keys::replace_key('not-a-fingerprint', secrets::conceal(self::NEWKEY)));
        $this->assertTrue($before === $this->stored(), 'the stored text is the identical string');
    }

    public function test_an_undecryptable_line_is_skipped_quietly_and_kept(): void {
        $this->resetAfterTest();
        $foreign = 'sodium:' . base64_encode(random_bytes(80));
        $before = "openai|$foreign|gpt-4o-mini\nopenai|" . secrets::conceal(self::OPENAI_ONE) . '|gpt-4o';
        set_config('comparison_providers', $before, 'local_ai_course_assistant');

        $this->assertSame(1, provider_keys::replace_key(key_inventory::fingerprint(self::OPENAI_ONE),
            secrets::conceal(self::NEWKEY)));
        $this->assertDebuggingNotCalled();
        $this->assert_only_lines_changed($before, $this->stored(), [1]);
    }

    public function test_an_empty_plain_or_undecryptable_new_value_is_refused_without_naming_either_key(): void {
        $this->resetAfterTest();
        $before = 'openai|' . secrets::conceal(self::OPENAI_ONE) . '|gpt-4o-mini';
        set_config('comparison_providers', $before, 'local_ai_course_assistant');
        $undecryptable = ['sodium:xyz', 'sodium:' . base64_encode(random_bytes(80))];
        foreach (array_merge(['', self::NEWKEY, secrets::conceal(self::NEWKEY) . "|x"], $undecryptable) as $i => $bad) {
            try {
                provider_keys::replace_key(key_inventory::fingerprint(self::OPENAI_ONE), $bad);
                $this->fail("bad new value $i was accepted");
            } catch (\coding_exception $e) {
                $text = $e->getMessage() . ' ' . $e->debuginfo;
                $this->assertFalse(str_contains($text, self::OPENAI_ONE), "message $i names the old key");
                $this->assertFalse(str_contains($text, 'NEWKEY'), "message $i names the new key");
                $this->assertFalse(str_contains($text, 'sodium:'), "message $i carries ciphertext");
            }
        }
        $this->assertDebuggingNotCalled();
        $this->assertTrue($before === $this->stored(), 'nothing was written');
    }

    public function test_a_table_forced_in_config_php_is_not_written(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->forced_plugin_settings['local_ai_course_assistant']['comparison_providers'] =
            'openai|' . self::OPENAI_ONE . '|gpt-4o-mini';
        $this->assertSame(0, provider_keys::replace_key(key_inventory::fingerprint(self::OPENAI_ONE),
            secrets::conceal(self::NEWKEY)));
        unset($CFG->forced_plugin_settings['local_ai_course_assistant']['comparison_providers']);
        $this->assertTrue('' === $this->stored(), 'nothing reached the database');
    }

    public function test_a_voice_row_key_is_replaced_as_text_and_every_other_line_is_byte_identical(): void {
        $this->resetAfterTest();
        $before = "# voice rows\n"
            . 'openai|' . secrets::conceal(self::OPENAI_ONE) . "|openai-prod|shimmer|alloy\n"
            . 'xai|' . secrets::conceal('xai-VOICE-OTHER-KEY-5555-EEEE') . "|grok\n"
            . 'openai | ' . self::OPENAI_ONE . ' |legacy-plain';
        set_config('voice_providers', $before, 'local_ai_course_assistant');
        $newstored = secrets::conceal(self::NEWKEY);

        $this->assertSame(2, voice_registry::replace_row_key(key_inventory::fingerprint(self::OPENAI_ONE), $newstored));

        $after = $this->voice();
        $this->assert_only_lines_changed($before, $after, [1, 3]);
        $this->assertTrue(explode("\n", $after)[3] === 'openai | ' . $newstored . ' |legacy-plain',
            'the plain line keeps its spacing and label');
        $rows = voice_registry::parse_rows();
        $this->assertTrue(self::NEWKEY === $rows[0]['apikey'], 'voice row 0 reveals the new key');
        $this->assertSame('openai-prod', $rows[0]['label']);
        $this->assertTrue(self::NEWKEY === $rows[2]['apikey'], 'voice row 2 reveals the new key');

        $again = $this->voice();
        $this->assertSame(0, voice_registry::replace_row_key(key_inventory::fingerprint(self::OPENAI_ONE), $newstored));
        $this->assertTrue($again === $this->voice(), 'no match writes nothing');
    }

    public function test_a_voice_replacement_refuses_a_plain_value_and_skips_a_forced_table(): void {
        global $CFG;
        $this->resetAfterTest();
        set_config('voice_providers', 'openai|' . secrets::conceal(self::OPENAI_ONE) . '|p', 'local_ai_course_assistant');
        try {
            voice_registry::replace_row_key(key_inventory::fingerprint(self::OPENAI_ONE), self::NEWKEY);
            $this->fail('a plain new value was accepted');
        } catch (\coding_exception $e) {
            $this->assertFalse(str_contains($e->getMessage() . ' ' . $e->debuginfo, 'NEWKEY'));
        }
        $CFG->forced_plugin_settings['local_ai_course_assistant']['voice_providers'] = 'openai|' . self::OPENAI_ONE . '|p';
        $this->assertSame(0, voice_registry::replace_row_key(key_inventory::fingerprint(self::OPENAI_ONE),
            secrets::conceal(self::NEWKEY)));
    }
}
