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
 * The Voice providers table keeps its stored format but never renders a key or ciphertext.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\admin_setting_voice_providers
 * @covers     \local_ai_course_assistant\admin_setting_keyed_rows
 */
final class admin_setting_voice_providers_test extends \advanced_testcase {

    private function setting(): admin_setting_voice_providers {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        return new admin_setting_voice_providers('local_ai_course_assistant/voice_providers', 'Voice', 'desc');
    }

    private function stored(): string {
        return (string) get_config('local_ai_course_assistant', 'voice_providers');
    }

    private function row(string $p, string $new = '', string $keep = '', string $label = '', string $rt = '',
            string $tts = ''): array {
        return ['provider' => $p, 'newkey' => $new, 'keepid' => $keep, 'label' => $label,
            'realtime_voice' => $rt, 'tts_voice' => $tts];
    }

    private function id_of_first_row(): string {
        $stored = voice_registry::parse_rows(false)[0];
        return provider_keys::rowid($stored['provider'] . '|' . $stored['label'], $stored['apikey']);
    }

    public function test_a_row_is_saved_in_the_old_format_with_an_encrypted_key(): void {
        $this->resetAfterTest();
        $this->assertSame('', $this->setting()->write_setting(['rows' => [
            $this->row('openai', 'sk-proj-VOICE-KEY-1111-AAAA', '', 'main', 'shimmer', 'nova'),
        ]]));
        $rows = voice_registry::parse_rows(true);
        $this->assertSame('sk-proj-VOICE-KEY-1111-AAAA', $rows[0]['apikey']);
        $this->assertSame(['openai', 'main', 'shimmer', 'nova'],
            [$rows[0]['provider'], $rows[0]['label'], $rows[0]['realtime_voice'], $rows[0]['tts_voice']]);
        $this->assertStringNotContainsString('VOICE-KEY', $this->stored());
        $this->assertTrue(secrets::is_encrypted(voice_registry::parse_rows(false)[0]['apikey']));
        // The stored line is still provider|key|label|realtime_voice|tts_voice.
        $parts = explode('|', $this->stored());
        $this->assertCount(5, $parts);
        $this->assertSame(['openai', 'main', 'shimmer', 'nova'], [$parts[0], $parts[2], $parts[3], $parts[4]]);
    }

    public function test_the_page_never_contains_the_key_or_its_ciphertext(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-VOICE-HIDDEN-2222-BBBB', '', 'main')]]);
        $stored = voice_registry::parse_rows(false)[0]['apikey'];
        $html = $setting->output_html('');
        $this->assertStringNotContainsString('VOICE-HIDDEN', $html);
        $this->assertStringNotContainsString(substr($stored, strpos($stored, ':') + 1, 24), $html);
        $this->assertStringContainsString('BBBB', $html);
        $this->assertDoesNotMatchRegularExpression('~<input[^>]*type="password"[^>]*value="[^"]+"~', $html);
    }

    /** A key saved in plain text before 7.7.3 (or by hand) must not reach the page either. */
    public function test_a_legacy_plain_key_never_reaches_the_page(): void {
        $this->resetAfterTest();
        set_config('voice_providers', "# old note\nxai|xai-LEGACY-PLAIN-VOICE-5555-EEEE|grok|eve|eve",
            'local_ai_course_assistant');
        $html = $this->setting()->output_html('');
        $this->assertStringNotContainsString('LEGACY-PLAIN', $html);
        $this->assertStringContainsString('EEEE', $html);
        $this->assertStringContainsString('grok', $html);
    }

    public function test_saving_with_no_key_typed_keeps_the_stored_value_byte_identical(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('xai', 'xai-VOICE-KEY-3333-CCCC', '', 'grok', 'eve', 'eve')]]);
        $before = $this->stored();
        $id = $this->id_of_first_row();
        $this->assertSame('', $setting->write_setting(['rows' => [$this->row('xai', '', $id, 'grok', 'eve', 'eve')]]));
        $this->assertSame($before, $this->stored());
    }

    /** A line the old form wrote (trailing empty columns stripped) comes back unchanged from an untouched save. */
    public function test_an_untouched_save_of_a_line_the_old_form_wrote_changes_nothing(): void {
        $this->resetAfterTest();
        $enc = secrets::conceal('sk-proj-OLDFORM-VOICE-6666-FFFF');
        set_config('voice_providers', "openai|{$enc}|main", 'local_ai_course_assistant');
        $before = $this->stored();
        $this->assertSame('', $this->setting()->write_setting(['rows' => [
            $this->row('openai', '', $this->id_of_first_row(), 'main'),
        ]]));
        $this->assertSame($before, $this->stored());
    }

    public function test_a_typed_key_replaces_only_that_row(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [
            $this->row('openai', 'sk-proj-VOICE-OLD-OLD-0000-AAAA', '', 'main'),
            $this->row('xai', 'xai-VOICE-KEEP-KEEP-1111-BBBB', '', 'grok'),
        ]]);
        $stored = voice_registry::parse_rows(false);
        $ids = [];
        foreach ($stored as $s) {
            $ids[] = provider_keys::rowid($s['provider'] . '|' . $s['label'], $s['apikey']);
        }
        $setting->write_setting(['rows' => [
            $this->row('openai', 'sk-proj-VOICE-NEW-NEW-2222-CCCC', $ids[0], 'main'),
            $this->row('xai', '', $ids[1], 'grok'),
        ]]);
        $rows = voice_registry::parse_rows(true);
        $this->assertSame('sk-proj-VOICE-NEW-NEW-2222-CCCC', $rows[0]['apikey']);
        $this->assertSame('xai-VOICE-KEEP-KEEP-1111-BBBB', $rows[1]['apikey']);
    }

    public function test_changing_a_rows_provider_never_carries_its_key_to_the_other_provider(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-VOICE-STAYS-4444-DDDD', '', 'main')]]);
        $before = $this->stored();
        $err = $setting->write_setting(['rows' => [$this->row('xai', '', $this->id_of_first_row(), 'main')]]);
        $this->assertStringContainsString(
            get_string('providerkeys:err_needs_key', 'local_ai_course_assistant', 'xai'), $err);
        $this->assertSame($before, $this->stored(), 'nothing saved');
    }

    /**
     * The voice row id includes the label, so renaming a row changes its id. The keepid the form round-trips
     * (it was computed from the OLD label when the page was drawn) is what lets a rename keep the key.
     */
    public function test_renaming_a_row_with_no_key_typed_keeps_the_stored_key_and_changes_the_label(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [
            $this->row('openai', 'sk-proj-VOICE-RENAME-1313-RRRR', '', 'main', 'shimmer', 'nova'),
        ]]);
        $storedkey = explode('|', $this->stored())[1];
        $oldid = $this->id_of_first_row();

        $this->assertSame('', $setting->write_setting(['rows' => [
            $this->row('openai', '', $oldid, 'prod', 'shimmer', 'nova'),
        ]]));

        $parts = explode('|', $this->stored());
        $this->assertSame($storedkey, $parts[1], 'the stored key is kept byte for byte');
        $this->assertSame('prod', $parts[2]);
        $this->assertSame('sk-proj-VOICE-RENAME-1313-RRRR', voice_registry::parse_rows(true)[0]['apikey']);
        $this->assertNotSame($oldid, $this->id_of_first_row(), 'the id follows the label, so only the posted keepid can match');
    }

    /** Review focus 3, on the voice table. */
    public function test_a_stale_or_unknown_keepid_never_attaches_another_rows_key(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('xai', 'xai-VOICE-ONLY-ROW-1414-SSSS', '', 'grok')]]);
        $before = $this->stored();
        $xaiid = $this->id_of_first_row();

        // An id nothing stored has (the row was removed by another admin).
        $err = $setting->write_setting(['rows' => [$this->row('openai', '', 'deadbeef0000', 'main')]]);
        $this->assertStringContainsString(
            get_string('providerkeys:err_needs_key', 'local_ai_course_assistant', 'openai'), $err);
        $this->assertSame($before, $this->stored(), 'nothing saved');

        // Another row's id, with a different provider: the key doesn't follow.
        $err = $setting->write_setting(['rows' => [$this->row('openai', '', $xaiid, 'main')]]);
        $this->assertStringContainsString(
            get_string('providerkeys:err_needs_key', 'local_ai_course_assistant', 'openai'), $err);
        $this->assertSame($before, $this->stored(), 'nothing saved');
    }

    /** The two voice_active_* selects only need each row's label and provider, so they never decrypt a key. */
    public function test_the_settings_page_never_decrypts_the_voice_keys(): void {
        $src = file_get_contents(__DIR__ . '/../settings.php');
        $this->assertStringContainsString('voice_registry::parse_rows(false)', $src);
        $this->assertDoesNotMatchRegularExpression('~voice_registry::parse_rows\(\s*(true)?\s*\)~', $src);
    }

    public function test_only_openai_and_xai_are_accepted_and_a_key_is_required(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $err = $setting->write_setting(['rows' => [$this->row('gemini', 'AIza-1234567890-abcd')]]);
        $this->assertStringContainsString('gemini', $err);
        $this->assertStringContainsString('openai', $err, 'the message says which providers are valid');
        $this->assertStringContainsString('xai', $err);
        $this->assertSame('', $this->stored(), 'nothing saved');
        $this->assertStringContainsString('openai', $setting->write_setting(['rows' => [$this->row('openai')]]));
        $this->assertSame('', $this->stored(), 'nothing saved');
        // Both valid providers save.
        $this->assertSame('', $setting->write_setting(['rows' => [
            $this->row('openai', 'sk-proj-VOICE-OK-7777-GGGG'),
            $this->row('xai', 'xai-VOICE-OK-8888-HHHH'),
        ]]));
        $this->assertCount(2, voice_registry::parse_rows(false));
    }

    /** A key that landed in the provider column (a tampered post) is named "unrecognized", never repeated. */
    public function test_a_key_in_the_provider_column_is_never_repeated(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $err = $setting->write_setting(['rows' => [
            $this->row('sk-proj-VOICE-WRONGFIELD-0101-PPPP', 'xai-VOICE-OTHER-0202-QQQQ'),
        ]]);
        $this->assertNotSame('', $err);
        $this->assertStringNotContainsStringIgnoringCase('WRONGFIELD', $err);
        $this->assertStringNotContainsStringIgnoringCase('OTHER', $err);
        $this->assertStringContainsString('unrecognized', $err);
        $this->assertSame('', $this->stored(), 'nothing saved');
    }

    public function test_a_failed_save_echoes_nothing(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-VOICE-STORED-9999-IIII', '', 'stored-label')]]);
        $submitted = ['rows' => [
            $this->row('gemini', 'sk-proj-VOICE-TYPED-4444-DDDD', '', 'SUBMITTED-LABEL-ONLY', 'SUBMITTED-RT'),
        ]];
        $err = $setting->write_setting($submitted);
        $this->assertNotSame('', $err);
        $this->assertStringNotContainsString('VOICE-TYPED', $err);
        $this->assertStringNotContainsString('SUBMITTED', $err);
        $html = $setting->output_html($submitted);
        $this->assertStringNotContainsString('VOICE-TYPED', $html);
        $this->assertStringNotContainsString('DDDD', $html);
        $this->assertStringNotContainsString('SUBMITTED', $html, 'the page is drawn from what is stored');
        $this->assertStringContainsString('stored-label', $html);
        $this->assertStringContainsString('IIII', $html);
    }

    /**
     * The stored line is pipe-delimited, so a pipe or line break in a typed value would shift a column or
     * add a line.
     *
     * @return array
     */
    public static function bad_character_provider(): array {
        return [
            'pipe in label' => ['label', 'prod|evil'],
            'carriage return in label' => ['label', "prod\rxai|k|l"],
            'line feed in label' => ['label', "prod\nxai|k|l"],
            'pipe in realtime voice' => ['realtime_voice', 'eve|evil'],
            'carriage return in realtime voice' => ['realtime_voice', "eve\revil"],
            'line feed in realtime voice' => ['realtime_voice', "eve\nevil"],
            'pipe in tts voice' => ['tts_voice', 'nova|evil'],
            'carriage return in tts voice' => ['tts_voice', "nova\revil"],
            'line feed in tts voice' => ['tts_voice', "nova\nevil"],
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
        $row = $this->row('openai', 'sk-proj-VOICE-PIPE-1212-JJJJ', '', 'main', 'shimmer', 'nova');
        $row[$field] = $value;
        $err = $setting->write_setting(['rows' => [$row]]);
        $labels = ['label' => 'providerkeys:col_label', 'realtime_voice' => 'providerkeys:col_rtvoice',
            'tts_voice' => 'providerkeys:col_ttsvoice'];
        $this->assertStringContainsString(get_string($labels[$field], 'local_ai_course_assistant'), $err);
        $this->assertStringContainsString('openai', $err);
        $this->assertStringNotContainsString('evil', $err, 'the message never repeats the typed value');
        $this->assertStringNotContainsString('JJJJ', $err);
        $this->assertSame('', $this->stored(), 'nothing saved');
    }

    public function test_the_config_log_never_sees_a_key_or_ciphertext(): void {
        global $DB;
        $this->resetAfterTest();
        $this->assertSame("openai|********|main|shimmer|nova\nxai||grok",
            admin_setting_voice_providers::redact_keys("openai|sodium:abcdef|main|shimmer|nova\nxai||grok"));

        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-VOICE-LOG-OLD-3131-KKKK', '', 'main', 'shimmer', 'nova')]]);
        // Replace the key: the old and the new value are both logged.
        $setting->write_setting(['rows' => [
            $this->row('openai', 'sk-proj-VOICE-LOG-NEW-3232-LLLL', $this->id_of_first_row(), 'main', 'shimmer', 'nova'),
        ]]);
        $logged = $DB->get_records('config_log', ['plugin' => 'local_ai_course_assistant', 'name' => 'voice_providers']);
        $this->assertNotEmpty($logged);
        foreach ($logged as $entry) {
            foreach ([(string) $entry->value, (string) $entry->oldvalue] as $text) {
                $this->assertStringNotContainsString('VOICE-LOG', $text);
                $this->assertDoesNotMatchRegularExpression('~(sodium|openssl-aes-256-ctr):~', $text);
            }
        }
        $last = end($logged);
        $this->assertSame('openai|********|main|shimmer|nova', $last->value);
    }

    /** A browser tab left open on the old page posts one string; it must not wipe the saved table. */
    public function test_a_string_from_the_old_form_never_clears_a_saved_table(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-VOICE-KEEPS-5656-MMMM', '', 'main')]]);
        $before = $this->stored();
        $this->assertSame('', $setting->write_setting("openai|typed-in-the-old-form|main"));
        $this->assertSame($before, $this->stored());
    }

    public function test_removing_the_last_row_empties_the_table(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-VOICE-GONE-7878-NNNN', '', 'main')]]);
        $this->assertSame('', $setting->write_setting(['present' => '1']));
        $this->assertSame('', $this->stored());
        $this->assertSame([], voice_registry::parse_rows(false));
    }

    public function test_every_column_is_on_the_page_with_a_name(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-VOICE-ARIA-9090-OOOO', '', 'main', 'shimmer', 'nova')]]);
        $html = $setting->output_html('');
        foreach (['Provider', 'API key', 'Label', 'Realtime voice', 'TTS voice', 'Remove row'] as $label) {
            $this->assertStringContainsString('aria-label="' . $label . '"', $html);
        }
        $this->assertStringContainsString('value="shimmer"', $html);
        $this->assertStringContainsString('value="nova"', $html);
        $this->assertStringContainsString(get_string('providerkeys:add_voice', 'local_ai_course_assistant'), $html);
    }
}
