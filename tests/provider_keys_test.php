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
 * The provider table is read through one accessor, with keys revealed only on request.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider_keys
 */
final class provider_keys_test extends \advanced_testcase {

    private function seed(string $rows): void {
        set_config('comparison_providers', $rows, 'local_ai_course_assistant');
    }

    public function test_plain_and_encrypted_rows_read_the_same(): void {
        $this->resetAfterTest();
        $enc = secrets::conceal('sk-ant-CLAUDE-KEY-0001-ABCD');
        $this->seed("openai|sk-proj-OPENAI-KEY-0002-WXYZ|gpt-4o-mini\nclaude|{$enc}|claude-haiku-4-5|0.5|https://x.example");
        $rows = provider_keys::rows(true);
        $this->assertSame('sk-proj-OPENAI-KEY-0002-WXYZ', $rows[0]['key']);
        $this->assertSame('sk-ant-CLAUDE-KEY-0001-ABCD', $rows[1]['key']);
        $this->assertSame('claude-haiku-4-5', $rows[1]['model']);
        $this->assertSame('0.5', $rows[1]['temperature']);
        $this->assertSame('https://x.example', $rows[1]['baseurl']);
        $this->assertSame(5, $rows[1]['columns']);
    }

    public function test_rows_without_reveal_never_carry_a_key_or_the_stored_value(): void {
        $this->resetAfterTest();
        $enc = secrets::conceal('sk-proj-SENTINEL-KEY-9999-QRST');
        $this->seed("openai|{$enc}|gpt-4o-mini");
        // Unescaped slashes: base64 ciphertext holds '/', which json_encode would otherwise write as '\/'
        // and so hide a leak from a plain substring check.
        $json = json_encode(provider_keys::rows(), JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('SENTINEL', $json);
        $this->assertStringNotContainsString($enc, $json);
        $this->assertStringNotContainsString(substr($enc, strpos($enc, ':') + 1), $json);
        $this->assertSame(
            ['id', 'provider', 'label', 'haskey', 'last4', 'key', 'models', 'model', 'temperature', 'baseurl', 'columns'],
            array_keys(provider_keys::rows()[0])
        );
        $this->assertSame('', provider_keys::rows()[0]['key']);
        $this->assertTrue(provider_keys::rows()[0]['haskey']);
        $this->assertSame('QRST', provider_keys::rows()[0]['last4']);
    }

    public function test_last4_is_shown_only_for_a_long_key(): void {
        $this->assertSame('', provider_keys::last4('short-key'));
        $this->assertSame('6789', provider_keys::last4('sk-0123456789'));
        $this->assertSame('', provider_keys::last4(''));
    }

    public function test_for_provider_takes_the_first_row_that_has_a_key(): void {
        $this->resetAfterTest();
        $this->seed("openai||gpt-4o-mini\nopenai|sk-proj-SECOND-ROW-KEY-1234|gpt-4o\nopenai|sk-proj-THIRD-ROW-KEY-5678|x");
        $row = provider_keys::for_provider('OpenAI');
        $this->assertSame('sk-proj-SECOND-ROW-KEY-1234', $row['key']);
        $this->assertSame('gpt-4o', $row['model']);
        $this->assertSame('sk-proj-SECOND-ROW-KEY-1234', provider_keys::key_for('openai'));
        $this->assertNull(provider_keys::for_provider('gemini'));
        $this->assertSame('', provider_keys::key_for('gemini'));
    }

    public function test_by_label_prefers_a_keyed_row_over_an_earlier_keyless_one(): void {
        $this->resetAfterTest();
        $this->seed("openai||a\nopenai|sk-proj-KEYED-ROW-0000-ZZZZ|b");
        $this->assertTrue(provider_keys::by_label()['openai']['haskey']);
        $this->assertSame('b', provider_keys::by_label()['openai']['model']);
    }

    public function test_row_for_needs_two_columns_like_the_old_lookup(): void {
        $this->resetAfterTest();
        $this->seed("ollama\nopenai|sk-proj-AAAA-BBBB-CCCC-DDDD|m");
        $this->assertNull(provider_keys::row_for('ollama'));
        $this->assertSame('m', provider_keys::row_for('openai')['models']);
        $this->assertSame('sk-proj-AAAA-BBBB-CCCC-DDDD', provider_keys::row_for('openai', true)['key']);
    }

    public function test_the_row_id_is_stable_and_does_not_contain_the_key(): void {
        $this->resetAfterTest();
        $enc = secrets::conceal('sk-proj-ID-TEST-KEY-1234-5678');
        $id = provider_keys::rowid('openai', $enc);
        $this->assertSame($id, provider_keys::rowid('openai', $enc));
        $this->assertNotSame($id, provider_keys::rowid('claude', $enc));
        $this->assertSame(12, strlen($id));
        $this->assertStringNotContainsString('ID-TEST', $id);
    }

    public function test_serialize_round_trips_and_writes_the_fewest_columns(): void {
        $rows = [
            ['provider' => 'openai', 'stored_key' => 'K1', 'models' => 'm1', 'temperature' => '', 'baseurl' => ''],
            ['provider' => 'claude', 'stored_key' => 'K2', 'models' => '', 'temperature' => '0.3', 'baseurl' => ''],
            ['provider' => 'custom', 'stored_key' => 'K3', 'models' => 'x', 'temperature' => '', 'baseurl' => 'https://g.example'],
            ['provider' => 'ollama', 'stored_key' => '', 'models' => 'llama3', 'temperature' => '', 'baseurl' => ''],
        ];
        $this->assertSame("openai|K1|m1\nclaude|K2||0.3\ncustom|K3|x||https://g.example\nollama||llama3", provider_keys::serialize($rows));
    }

    /** Review focus 2: a key that cannot be decrypted reads as "has a key" but yields nothing. */
    public function test_an_undecryptable_key_is_present_but_empty(): void {
        $this->resetAfterTest();
        $this->seed('openai|sodium:AAAAnotarealciphertextAAAAAAAAAAAAAAAAAAAAAAAA|gpt-4o-mini');
        $row = provider_keys::rows(true)[0];
        $this->assertTrue($row['haskey']);
        $this->assertSame('', $row['key']);
        $this->assertSame('', $row['last4']);
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
    }

    /** An undecryptable key counts as no key for a failover entry: no entry with an empty key is built. */
    public function test_the_failover_lookup_skips_a_row_whose_key_cannot_be_decrypted(): void {
        $this->resetAfterTest();
        set_config('spend_failover_chain', 'chat:openai', 'local_ai_course_assistant');
        $this->seed('openai|sodium:AAAAnotarealciphertextAAAAAAAAAAAAAAAAAAAAAAAA|gpt-4o-mini');
        $this->assertSame([], \local_ai_course_assistant\spend_guard::resolve_failover_chain('chat'));
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
    }

    /** Review focus 5: keyless providers resolve with no key. */
    public function test_a_keyless_row_is_listed_and_has_no_key(): void {
        $this->resetAfterTest();
        $this->seed('ollama||llama3||http://localhost:11434');
        $row = provider_keys::rows(true)[0];
        $this->assertSame('ollama', $row['provider']);
        $this->assertFalse($row['haskey']);
        $this->assertNull(provider_keys::for_provider('ollama'));
        $this->assertSame('http://localhost:11434', $row['baseurl']);
    }
}
