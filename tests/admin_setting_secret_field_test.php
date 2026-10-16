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
 * A single-key field shows only a masked "ends in" hint.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\admin_setting_secret_field
 */
final class admin_setting_secret_field_test extends \advanced_testcase {

    private function field(string $name = 'embed_apikey'): admin_setting_secret_field {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        return new admin_setting_secret_field('local_ai_course_assistant/' . $name, 'A key', 'desc');
    }

    private function webhook(string $name = 'anomaly_digest_slack_webhook'): admin_setting_webhook_url {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        return new admin_setting_webhook_url('local_ai_course_assistant/' . $name, 'Slack webhook URL', 'desc');
    }

    private function stored(string $name = 'anomaly_digest_slack_webhook') {
        return get_config('local_ai_course_assistant', $name);
    }

    public function test_it_shows_the_last_four_and_never_the_key(): void {
        $this->resetAfterTest();
        $stored = secrets::conceal('sk-proj-EMBED-FIELD-0000-WXYZ');
        set_config('embed_apikey', $stored, 'local_ai_course_assistant');
        $field = $this->field();
        $html = $field->output_html($field->get_setting());
        $this->assertStringContainsString('Saved key ends in WXYZ.', $html);
        $this->assertStringNotContainsString('EMBED-FIELD', $html);
        $this->assertStringNotContainsString('sk-proj', $html);
        $this->assertStringNotContainsString($stored, $html, 'the ciphertext never reaches the page either');
        $this->assertStringNotContainsString('sodium:', $html);
        $this->assertSame('desc', $field->description, 'the description is restored after rendering');
    }

    public function test_rendering_twice_adds_the_hint_once(): void {
        $this->resetAfterTest();
        set_config('embed_apikey', secrets::conceal('sk-proj-EMBED-FIELD-0000-WXYZ'), 'local_ai_course_assistant');
        $field = $this->field();
        $field->output_html($field->get_setting());
        $html = $field->output_html($field->get_setting());
        $this->assertSame(1, substr_count($html, 'Saved key ends in'));
    }

    public function test_an_unset_field_shows_no_hint(): void {
        $this->resetAfterTest();
        $field = $this->field('rerank_apikey');
        $this->assertStringNotContainsString('ends in', $field->output_html(''));
        $this->assertStringNotContainsString('URL is saved', $field->output_html(''));
        set_config('rerank_apikey', '', 'local_ai_course_assistant');
        $this->assertStringNotContainsString('ends in', $field->output_html(''));
        $this->assertStringNotContainsString('URL is saved', $field->output_html(''));
    }

    /**
     * A saved URL (an incoming webhook, whose token is in the path) never shows its last four characters,
     * or any other part of it: the field says only that a URL is saved.
     */
    public function test_a_saved_url_shows_no_part_of_it_not_even_the_tail(): void {
        $this->resetAfterTest();
        $field = $this->field('anomaly_digest_slack_webhook');
        $urls = [
            'https://hooks.example/T000/B000/SENTINELWEBHOOK',
            'http://hooks.example/T000/B000/SENTINELWEBHOOK',
            'HTTPS://Hooks.Example/T000/B000/SENTINELWEBHOOK',
            'hTtP://hooks.example/T000/B000/SENTINELWEBHOOK',
        ];
        foreach ($urls as $url) {
            set_config('anomaly_digest_slack_webhook', secrets::conceal($url), 'local_ai_course_assistant');
            $html = $field->output_html($field->get_setting());
            $this->assertStringContainsString('A URL is saved.', $html, $url);
            $this->assertStringNotContainsString('ends in', $html, $url);
            foreach (['HOOK', 'BHOOK', 'EBHOOK', 'WEBHOOK', 'SENTINEL', 'B000', 'T000', 'ooks.example', 'sodium:'] as $part) {
                $this->assertStringNotContainsString($part, $html, 'no part of the URL reaches the page: ' . $part);
            }
            $this->assertSame('desc', $field->description);
        }
        // A value saved before the upgrade is still plain text; the same rule applies to it.
        set_config('anomaly_digest_slack_webhook', 'https://hooks.example/T000/B000/SENTINELWEBHOOK',
            'local_ai_course_assistant');
        $html = $field->output_html($field->get_setting());
        $this->assertStringContainsString('A URL is saved.', $html);
        $this->assertStringNotContainsString('HOOK', $html);
        // So is one forced in config.php with stray leading whitespace.
        set_config('anomaly_digest_slack_webhook', "  \nhttps://hooks.example/T000/B000/SENTINELWEBHOOK",
            'local_ai_course_assistant');
        $html = $field->output_html($field->get_setting());
        $this->assertStringContainsString('A URL is saved.', $html);
        $this->assertStringNotContainsString('HOOK', $html);
    }

    /**
     * Only a value that starts with http:// or https:// is a URL. A key that merely begins with those letters
     * is still a key and keeps its hint.
     */
    public function test_a_key_that_only_looks_a_little_like_a_url_keeps_the_key_hint(): void {
        $this->resetAfterTest();
        set_config('embed_apikey', secrets::conceal('https-key-0123456789-ABCD'), 'local_ai_course_assistant');
        $field = $this->field();
        $html = $field->output_html($field->get_setting());
        $this->assertStringContainsString('Saved key ends in ABCD.', $html);
        $this->assertStringNotContainsString('URL is saved', $html);
    }

    public function test_a_short_key_shows_no_hint(): void {
        $this->resetAfterTest();
        // Under 12 characters the last four would be a third of the key.
        set_config('embed_apikey', secrets::conceal('short-key-1'), 'local_ai_course_assistant');
        $field = $this->field();
        $html = $field->output_html($field->get_setting());
        $this->assertStringNotContainsString('ends in', $html);
        $this->assertStringNotContainsString('short-key-1', $html);
    }

    public function test_a_key_that_will_not_decrypt_shows_no_hint_and_no_ciphertext(): void {
        $this->resetAfterTest();
        $foreign = 'sodium:' . base64_encode(random_bytes(80));
        set_config('embed_apikey', $foreign, 'local_ai_course_assistant');
        $field = $this->field();
        $html = $field->output_html($field->get_setting());
        $this->assertDebuggingCalled();
        $this->assertStringNotContainsString('ends in', $html);
        $this->assertStringNotContainsString($foreign, $html);
    }

    public function test_a_valid_webhook_url_is_saved_encrypted(): void {
        $this->resetAfterTest();
        $field = $this->webhook();
        $this->assertSame('', $field->write_setting('https://hooks.example/T000/B000/SENTINELWEBHOOK'));
        $stored = (string) $this->stored();
        $this->assertTrue(secrets::is_encrypted($stored));
        $this->assertStringNotContainsString('SENTINEL', $stored);
        $this->assertStringNotContainsString('hooks.example', $stored);
        $this->assertSame('https://hooks.example/T000/B000/SENTINELWEBHOOK', secrets::get('anomaly_digest_slack_webhook'));

        // http is accepted, any case, and a pasted line break or space at either end is trimmed away. The
        // scheme is stored in lower case (the send-time address check compares it as written); the rest of the
        // URL is stored exactly as typed.
        $cases = [
            "  HTTP://hooks.example/T000/B000/SENTINELTWO\n" => 'http://hooks.example/T000/B000/SENTINELTWO',
            'http://hooks.example/x' => 'http://hooks.example/x',
            'HTTPS://Hooks.Example/T000/B000/SENTINELTHREE' => 'https://Hooks.Example/T000/B000/SENTINELTHREE',
            'hTtPs://hooks.example/UPPER/Lower' => 'https://hooks.example/UPPER/Lower',
            'https://hooks.example:8443/T000/B000/SENTINELFOUR' => 'https://hooks.example:8443/T000/B000/SENTINELFOUR',
            'https://hooks.example?token=SENTINELFIVE' => 'https://hooks.example?token=SENTINELFIVE',
            'https://hooks.example/a@b/HTTPS://x' => 'https://hooks.example/a@b/HTTPS://x',
            'https://[::1]:8443/x' => 'https://[::1]:8443/x',
        ];
        foreach ($cases as $typed => $expected) {
            $this->assertSame('', $field->write_setting($typed), $typed);
            $this->assertSame($expected, secrets::get('anomaly_digest_slack_webhook'), $typed);
        }
    }

    /**
     * The send-time check (security::is_safe_provider_url) compares the scheme as written, so a URL saved as
     * HTTPS:// would be refused at send time. Saving it with the scheme in lower case is what makes the digest
     * post to it.
     */
    public function test_an_upper_case_scheme_is_saved_in_a_form_the_send_time_check_accepts(): void {
        $this->resetAfterTest();
        set_config('ssrf_trusted_endpoints', 'https://hooks.example', 'local_ai_course_assistant');
        $this->assertSame('', $this->webhook()->write_setting('HTTPS://hooks.example/T000/B000/SENTINELWEBHOOK'));
        $this->assertTrue(security::is_safe_provider_url(secrets::get('anomaly_digest_slack_webhook')));
    }

    /**
     * @return array[] typed values that aren't an http(s) URL without whitespace
     */
    public static function not_a_webhook_url(): array {
        return [
            'words' => ['not a url NOT-A-URL-SENTINEL'],
            'one word' => ['NOT-A-URL-SENTINEL'],
            'no scheme' => ['hooks.example/T000/NOT-A-URL-SENTINEL'],
            'other scheme' => ['ftp://hooks.example/NOT-A-URL-SENTINEL'],
            'script scheme' => ['javascript:NOT-A-URL-SENTINEL'],
            'scheme only' => ['https://'],
            'no host' => ['https:///NOT-A-URL-SENTINEL'],
            'space in the path' => ['https://hooks.example/NOT-A-URL SENTINEL'],
            'tab in the path' => ["https://hooks.example/NOT-A-URL\tSENTINEL"],
            'line break in the path' => ["https://hooks.example/NOT-A-URL\nSENTINEL"],
            'two urls' => ['https://hooks.example/one https://hooks.example/NOT-A-URL-SENTINEL'],
            'a key pasted by mistake' => ['sk-proj-NOT-A-URL-SENTINEL-0000-ZZZZ'],
            'an at sign and nothing else' => ['https://@'],
            'a port and no host' => ['https://:80'],
            'a port and no host, then a path' => ['https://:80/NOT-A-URL-SENTINEL'],
            'no host before a user' => ['https://@hooks.example/NOT-A-URL-SENTINEL'],
            'a user name' => ['https://NOT-A-URL-SENTINEL@hooks.example/x'],
            'a user name and password' => ['https://NOT-A-URL-SENTINEL:pw@hooks.example/x'],
            'a host that is really a user' => ['https://hooks.example@evil.example/NOT-A-URL-SENTINEL'],
            'a host and port that are really a user' => ['https://hooks.example:80@evil.example/NOT-A-URL-SENTINEL'],
            'a port that is not a number' => ['https://hooks.example:NOT-A-URL-SENTINEL/x'],
            'an empty port' => ['https://hooks.example:/NOT-A-URL-SENTINEL'],
            'a back slash in the host' => ['https://hooks.example\\evil.example/NOT-A-URL-SENTINEL'],
            'an unclosed address literal' => ['https://[::1/NOT-A-URL-SENTINEL'],
            'a control character' => ["https://hooks.example/NOT-A-URL\x00SENTINEL"],
            'a delete character in the host' => ["https://hooks\x7f.example/NOT-A-URL-SENTINEL"],
        ];
    }

    /**
     * @dataProvider not_a_webhook_url
     * @param string $typed
     */
    public function test_a_value_that_is_not_a_url_is_refused_and_never_echoed(string $typed): void {
        $this->resetAfterTest();
        $field = $this->webhook();
        $before = secrets::conceal('https://hooks.example/T000/B000/SENTINELWEBHOOK');
        set_config('anomaly_digest_slack_webhook', $before, 'local_ai_course_assistant');

        $error = $field->write_setting($typed);

        $this->assertNotSame('', $error, 'a non-empty error string means nothing was saved');
        $this->assertStringContainsString('Slack webhook URL', $error, 'it names the field');
        foreach (['SENTINEL', 'hooks.example', 'evil.example', 'sk-proj', 'ZZZZ', ':pw@'] as $part) {
            $this->assertStringNotContainsString($part, $error, 'the error never repeats what was typed');
        }
        $this->assertSame($before, $this->stored(), 'the saved URL is untouched, byte for byte');

        // Moodle hands the failed value back to output_html(): it never reaches the page.
        $html = $field->output_html($typed);
        $this->assertStringNotContainsString('SENTINEL', $html);
        $this->assertStringNotContainsString('NOT-A-URL', $html);
        $this->assertStringNotContainsString('ZZZZ', $html);
    }

    public function test_a_refused_value_saves_nothing_on_a_field_that_was_never_set(): void {
        $this->resetAfterTest();
        $before = $this->stored('anomaly_digest_teams_webhook');
        $this->assertNotSame('', $this->webhook('anomaly_digest_teams_webhook')->write_setting('not a url'));
        $this->assertSame($before, $this->stored('anomaly_digest_teams_webhook'));
        $this->assertSame('', secrets::get('anomaly_digest_teams_webhook'), 'nothing is saved');
    }

    /**
     * An empty value is the parent's business, exactly as before: it clears the setting.
     */
    public function test_an_empty_value_behaves_as_before(): void {
        $this->resetAfterTest();
        $field = $this->webhook();
        foreach (['', '   ', "\n"] as $empty) {
            set_config('anomaly_digest_slack_webhook', secrets::conceal('https://hooks.example/T000/B000/SENTINELWEBHOOK'),
                'local_ai_course_assistant');
            $this->assertSame('', $field->write_setting($empty));
            $this->assertSame('', $this->stored(), 'it clears the saved URL, as the encrypted password field does');
        }
        // The same call on the parent class gives the same result, so nothing changed for an empty value.
        set_config('anomaly_digest_slack_webhook', secrets::conceal('https://hooks.example/x'), 'local_ai_course_assistant');
        $parent = new \admin_setting_encryptedpassword('local_ai_course_assistant/anomaly_digest_slack_webhook', 'x', 'x');
        $this->assertSame('', $parent->write_setting(''));
        $this->assertSame('', $this->stored());
    }

    public function test_only_the_webhook_fields_insist_on_a_url(): void {
        $this->resetAfterTest();
        $this->assertTrue(is_subclass_of(admin_setting_webhook_url::class, admin_setting_secret_field::class));
        // A key field takes whatever is typed, as before.
        $this->assertSame('', $this->field()->write_setting('not a url, it is a key'));
        $this->assertSame('not a url, it is a key', secrets::get('embed_apikey'));
    }

    /**
     * Every single-key field on the settings pages is this class, so none is left without the hint, and a
     * field added later with Moodle's plain encrypted password type fails here instead of going without it.
     */
    public function test_every_encrypted_setting_on_the_settings_pages_is_the_hinted_field(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $category = \admin_get_root(true, true)->locate('local_ai_course_assistant');
        $seen = 0;
        $plain = [];
        $hinted = null;
        foreach ($category->children as $page) {
            if (!$page instanceof \admin_settingpage) {
                continue;
            }
            foreach ($page->settings as $setting) {
                if (!$setting instanceof \admin_setting_encryptedpassword) {
                    continue;
                }
                $seen++;
                if (!$setting instanceof admin_setting_secret_field) {
                    $plain[] = $setting->name;
                }
                if ($setting->name === 'embed_apikey') {
                    $hinted = $setting;
                }
            }
        }
        $this->assertGreaterThanOrEqual(20, $seen, 'the tree walk must see the credential settings');
        $this->assertSame([], $plain, 'these use admin_setting_encryptedpassword, so they show no "ends in" hint');

        // And the real declaration renders it.
        $this->assertNotNull($hinted);
        set_config('embed_apikey', secrets::conceal('sk-proj-TREE-FIELD-2222-QRST'), 'local_ai_course_assistant');
        $html = $hinted->output_html($hinted->get_setting());
        $this->assertStringContainsString('Saved key ends in QRST.', $html);
        $this->assertStringNotContainsString('TREE-FIELD', $html);
    }

    /**
     * The anomaly digest's webhook URLs carry a token in the path. On the settings page they say only that a
     * URL is saved: no last four, no part of the URL, no ciphertext.
     */
    public function test_the_anomaly_webhook_fields_say_a_url_is_saved_and_show_none_of_it(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $urls = [
            'anomaly_digest_slack_webhook' => 'https://hooks.example/T000/B000/SENTINELWEBHOOK',
            'anomaly_digest_teams_webhook' => 'https://hooks.example/teams/T000/SENTINELTEAMS',
        ];
        foreach ($urls as $name => $url) {
            set_config($name, secrets::conceal($url), 'local_ai_course_assistant');
        }
        $category = \admin_get_root(true, true)->locate('local_ai_course_assistant');
        $found = [];
        foreach ($category->children as $page) {
            if (!$page instanceof \admin_settingpage) {
                continue;
            }
            foreach ($page->settings as $setting) {
                if (isset($urls[$setting->name])) {
                    $found[$setting->name] = $setting;
                }
            }
        }
        $this->assertSame(array_keys($urls), array_keys($found), 'both webhook fields are on the settings page');
        foreach ($found as $name => $setting) {
            $this->assertInstanceOf(admin_setting_webhook_url::class, $setting);
            $html = $setting->output_html($setting->get_setting());
            $this->assertStringContainsString('A URL is saved.', $html);
            $this->assertStringNotContainsString('ends in', $html);
            foreach (['HOOK', 'EAMS', 'SENTINEL', 'hooks.example', 'T000', 'sodium:'] as $part) {
                $this->assertStringNotContainsString($part, $html, $name . ' shows no part of the URL: ' . $part);
            }
        }
    }

    /**
     * The newest config_log row id, to read only what a test writes after it.
     *
     * @return int
     */
    private function log_mark(): int {
        global $DB;
        return (int) $DB->get_field_sql('SELECT COALESCE(MAX(id), 0) FROM {config_log}');
    }

    /**
     * The rows config_log holds for a setting after a mark, oldest first.
     *
     * @param string $name
     * @param int $after a log_mark()
     * @return \stdClass[]
     */
    private function log_rows(string $name, int $after): array {
        global $DB;
        return array_values($DB->get_records_select('config_log', 'plugin = ? AND name = ? AND id > ?',
            ['local_ai_course_assistant', $name, $after], 'id ASC'));
    }

    /**
     * Moodle's encrypted password field adds nothing to the config log, so the log got the full ciphertext of
     * every key. This field logs '********' for a value that is set, as Moodle's own masked password type does,
     * and leaves an empty one empty.
     */
    public function test_a_key_reaches_config_log_only_as_asterisks(): void {
        $this->resetAfterTest();
        $field = $this->field();
        $mark = $this->log_mark();

        $this->assertSame('', $field->write_setting('sk-proj-LOGGED-ONE-1111-AAAA'));
        $this->assertSame('', $field->write_setting('sk-proj-LOGGED-TWO-2222-BBBB'));
        $this->assertSame('', $field->write_setting(''));

        $rows = $this->log_rows('embed_apikey', $mark);
        $this->assertCount(3, $rows, 'a first save, a replacement and a clear are each logged');
        foreach ($rows as $row) {
            foreach ([(string) $row->value, (string) $row->oldvalue] as $logged) {
                $this->assertStringNotContainsString('LOGGED', $logged);
                $this->assertStringNotContainsString('sodium:', $logged);
                $this->assertStringNotContainsString('openssl', $logged);
                $this->assertContains($logged, ['', '********'], 'only a mask or nothing is ever logged');
            }
        }
        $this->assertSame('********', $rows[0]->value, 'a saved key is logged as a mask');
        $this->assertSame('********', $rows[1]->oldvalue);
        $this->assertSame('********', $rows[1]->value, 'a replaced key is masked both sides');
        $this->assertSame('', (string) $rows[2]->value, 'a cleared key stays empty, so a clear still reads as one');
        $this->assertSame('********', $rows[2]->oldvalue);
        // What was stored is untouched by the masking.
        $this->assertSame('', secrets::get('embed_apikey'));
    }

    public function test_a_webhook_url_reaches_config_log_only_as_asterisks(): void {
        $this->resetAfterTest();
        $field = $this->webhook();
        $mark = $this->log_mark();
        $this->assertSame('', $field->write_setting('https://hooks.example/T000/B000/SENTINELWEBHOOK'));
        $this->assertSame('', $field->write_setting('https://hooks.example/T000/B000/SENTINELTWO'));

        $rows = $this->log_rows('anomaly_digest_slack_webhook', $mark);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('********', $row->value);
            $this->assertContains((string) $row->oldvalue, ['', '********']);
        }
        $this->assertSame('https://hooks.example/T000/B000/SENTINELTWO', secrets::get('anomaly_digest_slack_webhook'));
    }

    /**
     * With old and new both logged as the same mask, a key rotation must still tell the next admin page that
     * something changed.
     */
    public function test_a_changed_key_still_produces_a_changed_notice(): void {
        global $SESSION;
        $this->resetAfterTest();
        $this->setAdminUser();
        settings_save_report::assume_web(true);
        $field = $this->field();
        $field->write_setting('sk-proj-NOTICE-ONE-1111-AAAA');
        unset($SESSION->local_ai_course_assistant_savedchanges);

        $field->write_setting('sk-proj-NOTICE-TWO-2222-BBBB');
        $this->assertArrayHasKey('embed_apikey', $SESSION->local_ai_course_assistant_savedchanges);
        settings_save_report::flush();
        $text = '';
        foreach (\core\notification::fetch() as $notice) {
            $text .= $notice->get_message();
        }
        $this->assertStringContainsString('embed_apikey', $text);
        $this->assertStringContainsString(get_string('savereport:secret', 'local_ai_course_assistant'), $text);
        $this->assertStringNotContainsString('NOTICE', $text);
        $this->assertStringNotContainsString('sodium:', $text);
        settings_save_report::assume_web(null);
    }

    public function test_it_is_still_an_encrypted_password_setting(): void {
        $this->resetAfterTest();
        $this->assertTrue(is_subclass_of(admin_setting_secret_field::class, \admin_setting_encryptedpassword::class));
        // The save path is the parent's: the typed key is stored as ciphertext and reads back plain.
        $field = $this->field();
        $this->assertSame('', $field->write_setting('sk-proj-TYPED-FIELD-1111-ABCD'));
        $stored = (string) get_config('local_ai_course_assistant', 'embed_apikey');
        $this->assertTrue(secrets::is_encrypted($stored));
        $this->assertStringNotContainsString('TYPED-FIELD', $stored);
        $this->assertSame('sk-proj-TYPED-FIELD-1111-ABCD', secrets::get('embed_apikey'));
    }
}
