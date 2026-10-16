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
 * The Providers and keys table: saves keys encrypted, never renders one, and never echoes one back.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\admin_setting_provider_keys
 * @covers     \local_ai_course_assistant\admin_setting_keyed_rows
 */
final class admin_setting_provider_keys_test extends \advanced_testcase {

    private function setting(): admin_setting_provider_keys {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        return new admin_setting_provider_keys('local_ai_course_assistant/comparison_providers', 'Provider keys', 'desc');
    }

    private function stored(): string {
        return (string) get_config('local_ai_course_assistant', 'comparison_providers');
    }

    private function row(string $provider, string $newkey = '', string $keepid = '', string $models = '',
            string $temp = '', string $baseurl = ''): array {
        return ['provider' => $provider, 'newkey' => $newkey, 'keepid' => $keepid, 'models' => $models,
            'temperature' => $temp, 'baseurl' => $baseurl];
    }

    public function test_a_typed_key_is_saved_encrypted_and_reads_back_plain(): void {
        $this->resetAfterTest();
        $err = $this->setting()->write_setting(['rows' => [
            $this->row('openai', 'sk-proj-TYPED-KEY-1234-ABCD', '', 'gpt-4o-mini'),
        ]]);
        $this->assertSame('', $err);
        $this->assertStringNotContainsString('TYPED-KEY', $this->stored());
        $this->assertTrue(secrets::is_encrypted(provider_keys::stored_rows()[0]['stored_key']));
        $this->assertSame('sk-proj-TYPED-KEY-1234-ABCD', provider_keys::key_for('openai'));
    }

    /** Review focus 1. */
    public function test_saving_with_no_key_typed_leaves_the_stored_table_byte_identical(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [
            $this->row('openai', 'sk-proj-ONE-KEY-0000-AAAA', '', 'gpt-4o-mini'),
            $this->row('claude', 'sk-ant-TWO-KEY-1111-BBBB', '', 'claude-haiku-4-5', '0.4', 'https://c.example'),
        ]]);
        $before = $this->stored();
        $posted = [];
        foreach (provider_keys::rows() as $r) {
            $posted[] = $this->row($r['provider'], '', $r['id'], $r['models'], $r['temperature'], $r['baseurl']);
        }
        $this->assertSame('', $setting->write_setting(['rows' => $posted]));
        $this->assertSame($before, $this->stored());
    }

    public function test_a_typed_key_replaces_only_that_row(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [
            $this->row('openai', 'sk-proj-OLD-OLD-OLD-0000-AAAA'),
            $this->row('claude', 'sk-ant-KEEP-KEEP-1111-BBBB'),
        ]]);
        $rows = provider_keys::rows();
        $setting->write_setting(['rows' => [
            $this->row('openai', 'sk-proj-NEW-NEW-NEW-2222-CCCC', $rows[0]['id']),
            $this->row('claude', '', $rows[1]['id']),
        ]]);
        $this->assertSame('sk-proj-NEW-NEW-NEW-2222-CCCC', provider_keys::key_for('openai'));
        $this->assertSame('sk-ant-KEEP-KEEP-1111-BBBB', provider_keys::key_for('claude'));
    }

    /** Review focus 3. */
    public function test_an_unknown_keepid_never_attaches_another_rows_key(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('claude', 'sk-ant-ONLY-ROW-3333-DDDD')]]);
        $before = $this->stored();
        $err = $setting->write_setting(['rows' => [$this->row('openai', '', 'deadbeef0000')]]);
        $this->assertStringContainsString('openai', $err);
        $this->assertSame($before, $this->stored(), 'nothing saved');
    }

    /** Review focus 5. */
    public function test_a_keyless_provider_saves_without_a_key(): void {
        $this->resetAfterTest();
        $this->assertSame('', $this->setting()->write_setting(['rows' => [
            $this->row('ollama', '', '', 'llama3', '', 'http://localhost:11434'),
        ]]));
        $this->assertSame("ollama||llama3||http://localhost:11434", $this->stored());
    }

    public function test_a_keyed_provider_with_no_key_is_refused(): void {
        $this->resetAfterTest();
        $err = $this->setting()->write_setting(['rows' => [$this->row('openai')]]);
        $this->assertStringContainsString('openai', $err);
        $this->assertSame('', $this->stored());
    }

    public function test_validation_names_the_problem(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $this->assertStringContainsString('notaprovider',
            $setting->write_setting(['rows' => [$this->row('notaprovider', 'sk-x-1234567890-abcd')]]));
        $this->assertStringContainsString('9',
            $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-T-1234-5678-ABCD', '', '', '9')]]));
        $this->assertStringContainsString('http',
            $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-T-1234-5678-ABCD', '', '', '', 'ftp://x')]]));
    }

    public function test_two_rows_for_one_provider_are_allowed(): void {
        $this->resetAfterTest();
        $this->assertSame('', $this->setting()->write_setting(['rows' => [
            $this->row('claude', 'sk-ant-FIRST-0000-1111-AAAA', '', 'claude-haiku-4-5'),
            $this->row('claude', 'sk-ant-SECOND-2222-3333-BBBB', '', 'claude-sonnet-4-6'),
        ]]));
        $this->assertCount(2, provider_keys::rows());
        $this->assertSame('sk-ant-FIRST-0000-1111-AAAA', provider_keys::key_for('claude'));
    }

    public function test_a_blank_provider_row_is_a_removed_row(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-GONE-0000-1111-AAAA')]]);
        $setting->write_setting(['rows' => [$this->row('')]]);
        $this->assertSame('', $this->stored());
    }

    public function test_the_page_never_contains_a_key_or_its_ciphertext(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-HIDDEN-KEY-4444-EEEE', '', 'gpt-4o-mini')]]);
        $stored = provider_keys::stored_rows()[0]['stored_key'];
        $html = $setting->output_html($this->stored());
        $this->assertStringNotContainsString('HIDDEN-KEY', $html);
        $this->assertStringNotContainsString(substr($stored, strpos($stored, ':') + 1, 24), $html);
        $this->assertStringContainsString('EEEE', $html, 'the last four are shown');
        $this->assertStringContainsString('gpt-4o-mini', $html);
        $this->assertDoesNotMatchRegularExpression('~<input[^>]*type="password"[^>]*value="[^"]+"~', $html);
    }

    /** Review focus 6. */
    public function test_a_failed_save_echoes_nothing_back(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $submitted = ['rows' => [$this->row('openai', 'sk-proj-TYPED-AND-REFUSED-5555-FFFF', '', '', '9')]];
        $this->assertNotSame('', $setting->write_setting($submitted));
        $html = $setting->output_html($submitted);
        $this->assertStringNotContainsString('TYPED-AND-REFUSED', $html);
        $this->assertSame('', $this->stored());
    }

    public function test_the_config_log_never_sees_a_key_or_ciphertext(): void {
        $this->assertSame("openai|********|m\nollama||x", admin_setting_provider_keys::redact_keys("openai|sodium:abcdef|m\nollama||x"));
        $this->assertSame('', admin_setting_provider_keys::redact_keys(''));
    }

    public function test_an_undecryptable_row_shows_saved_without_digits_and_survives_a_save(): void {
        $this->resetAfterTest();
        set_config('comparison_providers', 'openai|sodium:AAAAnotarealciphertextAAAAAAAAAAAAAAAAAAAAAAAA|m', 'local_ai_course_assistant');
        $setting = $this->setting();
        $html = $setting->output_html('');
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $this->assertStringContainsString('Saved', $html);
        $before = $this->stored();
        $id = provider_keys::rows()[0]['id'];
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $this->assertSame('', $setting->write_setting(['rows' => [$this->row('openai', '', $id, 'm')]]));
        $this->assertSame($before, $this->stored(), 'a save does not wipe a key it cannot read');
    }

    /**
     * Value hygiene: models, temperature and base URL are written between pipes on one line, so a typed
     * pipe or line break would corrupt every row after it.
     *
     * @return array
     */
    public static function bad_character_provider(): array {
        return [
            'pipe in models' => ['models', 'gpt-4o|evil'],
            'carriage return in models' => ['models', "gpt-4o\rgpt-4o-mini"],
            'line feed in models' => ['models', "gpt-4o\nollama||x"],
            'pipe in temperature' => ['temperature', '0.5|1'],
            'line feed in temperature' => ['temperature', "0.5\n0.6"],
            'carriage return in temperature' => ['temperature', "0.5\r0.6"],
            'pipe in base url' => ['baseurl', 'https://x.example/a|b'],
            'line feed in base url' => ['baseurl', "https://x.example\nhttps://y.example"],
            'carriage return in base url' => ['baseurl', "https://x.example\rhttps://y.example"],
        ];
    }

    /**
     * @dataProvider bad_character_provider
     * @param string $field
     * @param string $value
     */
    public function test_a_pipe_or_line_break_in_a_value_is_refused(string $field, string $value): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('claude', 'sk-ant-KEEP-ME-6666-GGGG', '', 'claude-haiku-4-5')]]);
        $before = $this->stored();
        $row = $this->row('openai', 'sk-proj-BAD-VALUE-7777-HHHH');
        $row[$field] = $value;
        $err = $setting->write_setting(['rows' => [$row]]);
        $label = get_string('providerkeys:col_' . ['models' => 'models', 'temperature' => 'temp', 'baseurl' => 'baseurl'][$field],
            'local_ai_course_assistant');
        $this->assertStringContainsString($label, $err);
        $this->assertStringContainsString('openai', $err);
        $this->assertStringContainsString(get_string('providerkeys:err_resubmit', 'local_ai_course_assistant'), $err);
        $this->assertStringNotContainsString('BAD-VALUE', $err);
        $this->assertSame($before, $this->stored(), 'nothing saved');
    }

    public function test_a_base_url_with_a_user_name_or_password_is_refused(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        foreach (['https://user:pass-SENTINEL@host.example/v1', 'http://user@host.example', 'HTTPS://u:p@host.example',
                'https://u:p@host.example:8443'] as $url) {
            $err = $setting->write_setting(['rows' => [
                $this->row('custom', 'sk-custom-CRED-URL-8888-IIII', '', 'm', '', $url),
            ]]);
            $this->assertStringContainsString('user name or password', $err, $url);
            $this->assertStringContainsString('custom', $err, $url);
            $this->assertStringNotContainsString('SENTINEL', $err, 'the error never repeats the URL');
            $this->assertSame('', $this->stored(), 'nothing saved');
        }
        // An at sign in the path or query is not a credential.
        $this->assertSame('', $setting->write_setting(['rows' => [
            $this->row('custom', 'sk-custom-CRED-URL-8888-IIII', '', 'm', '', 'https://host.example/v1/@me?x=a@b'),
        ]]));
    }

    public function test_a_stored_base_url_with_a_password_never_reaches_the_page(): void {
        $this->resetAfterTest();
        $enc = secrets::conceal('sk-custom-LEGACY-URL-9999-JJJJ');
        set_config('comparison_providers', "custom|{$enc}|m||https://admin:LEGACY-PASSWORD@gw.example/v1", 'local_ai_course_assistant');
        $setting = $this->setting();
        $html = $setting->output_html('');
        $this->assertStringNotContainsString('LEGACY-PASSWORD', $html);
        $this->assertStringNotContainsString('admin:', $html);
        $this->assertStringContainsString('gw.example/v1', $html, 'the host stays visible');
        // Posting back what the page showed is refused until the admin fixes it, so the mask is never saved.
        $before = $this->stored();
        $id = provider_keys::rows()[0]['id'];
        $err = $setting->write_setting(['rows' => [
            $this->row('custom', '', $id, 'm', '', 'https://***@gw.example/v1'),
        ]]);
        $this->assertStringContainsString('user name or password', $err);
        $this->assertSame($before, $this->stored());
    }

    public function test_an_error_never_repeats_input_that_is_not_a_plain_number_or_provider_name(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $err = $setting->write_setting(['rows' => [
            $this->row('openai', 'sk-proj-REAL-ONE-1010-KKKK', '', '', 'sk-proj-PASTED-IN-THE-WRONG-FIELD'),
        ]]);
        $this->assertNotSame('', $err);
        $this->assertStringNotContainsString('PASTED', $err);
        $err = $setting->write_setting(['rows' => [
            $this->row('sk-proj-PASTED-INTO-THE-PROVIDER-FIELD-1234567890', 'sk-proj-REAL-ONE-1010-KKKK'),
        ]]);
        $this->assertNotSame('', $err);
        $this->assertStringNotContainsString('PASTED', $err);
        $this->assertSame('', $this->stored());
    }

    public function test_removing_the_last_row_is_possible_because_the_form_always_posts(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-LAST-ONE-1111-LLLL')]]);
        $this->assertStringContainsString('[present]', $setting->output_html(''));
        // With every row removed in the browser nothing but the marker is posted.
        $this->assertSame('', $setting->write_setting(['present' => '1']));
        $this->assertSame('', $this->stored());
    }

    public function test_a_value_that_did_not_come_from_the_form_never_clears_a_saved_table(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-STAYS-1212-MMMM')]]);
        $before = $this->stored();
        $this->assertSame('', $setting->write_setting(''));
        $this->assertSame('', $setting->write_setting('openai|sk-proj-OLD-STRING-FORMAT-1313-NNNN|m'));
        $this->assertSame($before, $this->stored());
        // Installing the default for a setting that was never saved still stores the empty value.
        unset_config('comparison_providers', 'local_ai_course_assistant');
        $this->assertNull($setting->get_setting());
        $this->assertSame('', $setting->write_setting(''));
        $this->assertSame('', $setting->get_setting());
    }

    public function test_a_provider_the_form_does_not_list_keeps_its_own_value_on_the_page(): void {
        $this->resetAfterTest();
        $enc = secrets::conceal('sk-cohere-LEGACY-1414-OOOO');
        set_config('comparison_providers', "cohere|{$enc}|command", 'local_ai_course_assistant');
        $setting = $this->setting();
        $html = $setting->output_html('');
        // Without this the select would fall back to its first option and a save would hand this key to openai.
        $this->assertMatchesRegularExpression('~<option value="cohere" selected~', $html);
        $before = $this->stored();
        $id = provider_keys::rows()[0]['id'];
        $err = $setting->write_setting(['rows' => [$this->row('cohere', '', $id, 'command')]]);
        $this->assertStringContainsString('cohere', $err);
        $this->assertSame($before, $this->stored());
    }

    public function test_a_stored_provider_name_that_looks_like_a_key_never_reaches_the_page(): void {
        $this->resetAfterTest();
        set_config('comparison_providers', "sk-proj-SENTINEL-IN-THE-PROVIDER-COLUMN-1515-PPPP||m", 'local_ai_course_assistant');
        $html = $this->setting()->output_html('');
        $this->assertStringNotContainsStringIgnoringCase('SENTINEL', $html);
        $this->assertStringNotContainsStringIgnoringCase('IN-THE-PROVIDER-COLUMN', $html);
    }
}
