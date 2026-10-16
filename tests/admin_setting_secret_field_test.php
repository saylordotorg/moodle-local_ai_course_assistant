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
            $this->assertInstanceOf(admin_setting_secret_field::class, $setting);
            $html = $setting->output_html($setting->get_setting());
            $this->assertStringContainsString('A URL is saved.', $html);
            $this->assertStringNotContainsString('ends in', $html);
            foreach (['HOOK', 'EAMS', 'SENTINEL', 'hooks.example', 'T000', 'sodium:'] as $part) {
                $this->assertStringNotContainsString($part, $html, $name . ' shows no part of the URL: ' . $part);
            }
        }
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
