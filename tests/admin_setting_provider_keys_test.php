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

    /**
     * Names of query-string parameters that carry a credential (ruling R22).
     *
     * @return array
     */
    public static function secret_query_provider(): array {
        $u = 'https://gw.example/v1?';
        return [
            'api_key' => [$u . 'api_key=QS-SENTINEL'],
            'api-key' => [$u . 'api-key=QS-SENTINEL'],
            'apikey' => [$u . 'apikey=QS-SENTINEL'],
            'apiKey' => [$u . 'apiKey=QS-SENTINEL'],
            'key' => [$u . 'key=QS-SENTINEL'],
            'upper case' => [$u . 'KEY=QS-SENTINEL'],
            'token' => [$u . 'token=QS-SENTINEL'],
            'access_token after a harmless one' => [$u . 'model=x&access_token=QS-SENTINEL'],
            'secret' => [$u . 'client_secret=QS-SENTINEL'],
            'sig' => [$u . 'sig=QS-SENTINEL'],
            'signature' => [$u . 'X-Amz-Signature=QS-SENTINEL'],
            'password' => [$u . 'password=QS-SENTINEL'],
            'auth' => [$u . 'auth=QS-SENTINEL'],
            'percent-encoded name' => [$u . '%6Bey=QS-SENTINEL'],
            'before a fragment' => [$u . 'key=QS-SENTINEL#top'],
        ];
    }

    /**
     * @dataProvider secret_query_provider
     * @param string $url
     */
    public function test_a_base_url_with_a_secret_in_its_query_string_is_refused(string $url): void {
        $this->resetAfterTest();
        $err = $this->setting()->write_setting(['rows' => [
            $this->row('custom', 'sk-custom-QS-URL-1616-RRRR', '', 'm', '', $url),
        ]]);
        $this->assertStringContainsString(
            get_string('providerkeys:err_baseurl_query', 'local_ai_course_assistant', 'custom'), $err);
        $this->assertStringNotContainsString('QS-SENTINEL', $err, 'the error never repeats the value');
        $this->assertSame('', $this->stored(), 'nothing saved');
    }

    /**
     * Query parameters that are not credentials, including names that only contain a risky word.
     *
     * @return array
     */
    public static function harmless_query_provider(): array {
        $u = 'https://gw.example/v1?';
        return [
            'model' => [$u . 'model=x'],
            'api-version' => [$u . 'api-version=2024-02-01'],
            'two parameters' => [$u . 'deployment=gpt4&api-version=2024-02-01'],
            'monkey is not key' => [$u . 'monkey=1'],
            'keyboard is not key' => [$u . 'keyboard=1'],
            'a value that says key' => [$u . 'mode=key'],
        ];
    }

    /**
     * @dataProvider harmless_query_provider
     * @param string $url
     */
    public function test_a_harmless_query_string_is_accepted(string $url): void {
        $this->resetAfterTest();
        $this->assertSame('', $this->setting()->write_setting(['rows' => [
            $this->row('custom', 'sk-custom-QS-URL-1616-RRRR', '', 'm', '', $url),
        ]]));
        $this->assertStringContainsString($url, $this->stored());
    }

    public function test_a_stored_query_secret_is_masked_on_the_page_and_refused_on_the_way_back(): void {
        $this->resetAfterTest();
        $enc = secrets::conceal('sk-custom-LEGACY-QS-1818-SSSS');
        set_config('comparison_providers',
            "custom|{$enc}|m||https://gw.example/v1?api-version=2024-02-01&key=QS-LEGACY-SECRET#top",
            'local_ai_course_assistant');
        $setting = $this->setting();
        $html = $setting->output_html('');
        $this->assertStringNotContainsString('QS-LEGACY-SECRET', $html);
        $this->assertStringContainsString('api-version=2024-02-01', $html, 'harmless parameters stay visible');
        $this->assertStringContainsString('key=***', $html);
        $before = $this->stored();
        $id = provider_keys::rows()[0]['id'];
        $err = $setting->write_setting(['rows' => [
            $this->row('custom', '', $id, 'm', '', 'https://gw.example/v1?api-version=2024-02-01&key=***#top'),
        ]]);
        $this->assertStringContainsString(
            get_string('providerkeys:err_baseurl_query', 'local_ai_course_assistant', 'custom'), $err);
        $this->assertSame($before, $this->stored());
    }

    public function test_the_config_log_and_the_notice_never_carry_a_url_credential_or_ciphertext(): void {
        global $DB;
        $this->resetAfterTest();
        $this->assertSame(
            "custom|********|m||https://***@gw.example/v1?key=***&model=x",
            admin_setting_provider_keys::redact_keys(
                "custom|sodium:abcdef|m||https://u:PW-SENTINEL@gw.example/v1?key=QS-SENTINEL&model=x"));
        // A real save over a legacy table: the old value is logged too.
        $enc = secrets::conceal('sk-custom-LEGACY-LOG-1919-TTTT');
        set_config('comparison_providers',
            "custom|{$enc}|m||https://u:PW-LEGACY@gw.example/v1?key=QS-LEGACY", 'local_ai_course_assistant');
        $this->assertSame('', $this->setting()->write_setting(['rows' => [
            $this->row('custom', 'sk-custom-NEW-LOG-2020-UUUU', '', 'm', '', 'https://gw.example/v1'),
        ]]));
        $logged = $DB->get_records('config_log', ['plugin' => 'local_ai_course_assistant', 'name' => 'comparison_providers']);
        $this->assertNotEmpty($logged);
        foreach ($logged as $entry) {
            foreach ([(string) $entry->value, (string) $entry->oldvalue] as $text) {
                $this->assertStringNotContainsString('PW-LEGACY', $text);
                $this->assertStringNotContainsString('QS-LEGACY', $text);
                $this->assertDoesNotMatchRegularExpression('~(sodium|openssl-aes-256-ctr):~', $text);
            }
        }
    }

    public function test_changing_a_rows_provider_never_carries_its_key_to_the_new_provider(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-MOVES-NOT-2121-VVVV', '', 'gpt-4o-mini')]]);
        $before = $this->stored();
        $id = provider_keys::rows()[0]['id'];
        // The admin picked another provider in the dropdown and typed no key.
        $err = $setting->write_setting(['rows' => [$this->row('claude', '', $id, 'claude-haiku-4-5')]]);
        $this->assertStringContainsString(
            get_string('providerkeys:err_needs_key', 'local_ai_course_assistant', 'claude'), $err);
        $this->assertSame($before, $this->stored(), 'nothing saved');
        // A keyless provider doesn't need one, and the old key isn't copied across either.
        $this->assertSame('', $setting->write_setting(['rows' => [$this->row('ollama', '', $id, 'llama3')]]));
        $this->assertSame('ollama||llama3', $this->stored());
        // The same provider with a blank key still keeps its key (see the byte-identical test).
    }

    public function test_the_unknown_provider_error_uses_the_configured_product_name(): void {
        $this->resetAfterTest();
        set_config('short_name', 'ACMEBOT', 'local_ai_course_assistant');
        $err = $this->setting()->write_setting(['rows' => [$this->row('notaprovider', 'sk-x-1234567890-abcd')]]);
        $this->assertStringContainsString('ACMEBOT', $err);
        $this->assertStringNotContainsString('SOLA', $err);
        $this->assertStringNotContainsString('[[', $err);
    }

    public function test_every_control_in_a_row_has_an_accessible_name(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-ARIA-2222-WWWW', '', 'gpt-4o-mini')]]);
        $html = $setting->output_html('');
        $this->assertGreaterThan(8, preg_match_all('~<(select|input|button)\b[^>]*>~', $html, $m));
        $unnamed = [];
        foreach ($m[0] as $i => $tag) {
            $isbutton = $m[1][$i] === 'button';
            $ishidden = preg_match('~type="hidden"~', $tag);
            $isremove = str_contains($tag, 'sola-kr-remove');
            // Hidden inputs aren't controls, and the Change and Add buttons carry their own text.
            if ($ishidden || ($isbutton && !$isremove)) {
                continue;
            }
            if (!preg_match('~aria-label="[^"]+"~', $tag)) {
                $unnamed[] = $tag;
            }
        }
        $this->assertSame([], $unnamed, 'controls with no aria-label');
        foreach (['Provider', 'API key', 'Models (comma-separated)', 'Temp.', 'Base URL', 'Remove row'] as $label) {
            $this->assertStringContainsString('aria-label="' . $label . '"', $html);
        }
    }
}
