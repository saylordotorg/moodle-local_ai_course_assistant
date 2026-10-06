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
 * Keys are encrypted at rest and reach their callers as plain text (#302).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\secrets
 */
final class secrets_test extends \advanced_testcase {

    /**
     * Every secret setting in settings.php is an encrypted one, and every
     * encrypted one is listed in secrets::names(). Either drifting means a key
     * stored as plain text, or one read back as ciphertext.
     */
    public function test_the_secret_list_matches_the_settings_tree(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $category = \admin_get_root(true, true)->locate('local_ai_course_assistant');
        $encrypted = [];
        $masked = [];
        foreach ($category->children as $page) {
            if (!$page instanceof \admin_settingpage) {
                continue;
            }
            foreach ($page->settings as $setting) {
                if ($setting instanceof \admin_setting_encryptedpassword) {
                    $encrypted[] = $setting->name;
                } else if ($setting instanceof \admin_setting_configpasswordunmask) {
                    $masked[] = $setting->name;
                }
            }
        }
        sort($encrypted);
        $names = secrets::names();
        sort($names);
        $this->assertSame([], $masked, 'masked-but-plain-text key settings remain');
        $this->assertSame($names, $encrypted);
    }

    /**
     * Saving through the admin setting stores ciphertext, and the provider
     * still receives the plain key.
     */
    public function test_a_saved_key_is_ciphertext_in_the_database_and_plain_to_the_provider(): void {
        global $DB;
        $this->resetAfterTest();

        $setting = new \admin_setting_encryptedpassword('local_ai_course_assistant/apikey', 'k', 'd');
        $this->assertSame('', $setting->write_setting('sk-ant-plain-123'));

        $stored = $DB->get_field('config_plugins', 'value',
            ['plugin' => 'local_ai_course_assistant', 'name' => 'apikey']);
        $this->assertStringNotContainsString('sk-ant-plain-123', $stored);
        $this->assertTrue(secrets::is_encrypted($stored));

        $this->assertSame('sk-ant-plain-123', secrets::get('apikey'));
        $this->assertSame('sk-ant-plain-123', course_config_manager::get_effective_config(0)['apikey']);
    }

    /**
     * A per-course key is stored encrypted and resolved to plain text.
     */
    public function test_a_per_course_key_round_trips(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        set_config('apikey', secrets::conceal('site-key'), 'local_ai_course_assistant');

        course_config_manager::save($course->id, ['enabled' => 1, 'apikey' => 'course-key-456']);

        $stored = $DB->get_field('local_ai_course_assistant_course_cfg', 'apikey', ['courseid' => $course->id]);
        $this->assertStringNotContainsString('course-key-456', $stored);
        $this->assertSame('course-key-456', course_config_manager::get_effective_config($course->id)['apikey']);

        // Saving again with the stored ciphertext (what course_settings.php does
        // when the field is left blank) must not double-encrypt it.
        course_config_manager::save($course->id, ['enabled' => 1, 'apikey' => $stored]);
        $this->assertSame('course-key-456', course_config_manager::get_effective_config($course->id)['apikey']);
    }

    /**
     * The upgrade encrypts stored plain text once, leaves config.php alone, and
     * is safe to run twice.
     */
    public function test_the_upgrade_encrypts_existing_keys_once(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        set_config('apikey', 'legacy-plain', 'local_ai_course_assistant');
        set_config('zendesk_token', 'legacy-zd', 'local_ai_course_assistant');
        set_config('model', 'not-a-secret', 'local_ai_course_assistant');
        $DB->insert_record('local_ai_course_assistant_course_cfg', (object) [
            'courseid' => $course->id, 'enabled' => 1, 'apikey' => 'legacy-course',
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        set_config('redash_api_key', 'forced-plain', 'local_ai_course_assistant');
        $CFG->forced_plugin_settings['local_ai_course_assistant']['redash_api_key'] = 'forced-plain';

        $this->assertSame(2, secrets::encrypt_stored_settings());
        $this->assertSame(1, secrets::encrypt_course_keys());

        $raw = fn(string $n) => $DB->get_field('config_plugins', 'value',
            ['plugin' => 'local_ai_course_assistant', 'name' => $n]);
        $this->assertTrue(secrets::is_encrypted($raw('apikey')));
        $this->assertTrue(secrets::is_encrypted($raw('zendesk_token')));
        $this->assertSame('not-a-secret', $raw('model'));
        $this->assertSame('forced-plain', $raw('redash_api_key'));
        $this->assertSame('legacy-plain', secrets::get('apikey'));
        $this->assertSame('legacy-course', course_config_manager::get_effective_config($course->id)['apikey']);

        $this->assertSame(0, secrets::encrypt_stored_settings());
        $this->assertSame(0, secrets::encrypt_course_keys());
        $this->assertSame('legacy-plain', secrets::get('apikey'));

        unset($CFG->forced_plugin_settings['local_ai_course_assistant']);
    }

    /**
     * A value encrypted under another site's key reads as unset, never as the
     * ciphertext, which a provider would reject as a bad key.
     */
    public function test_a_key_from_another_site_reads_as_unset(): void {
        $this->resetAfterTest();
        $foreign = 'sodium:' . base64_encode(random_bytes(80));
        set_config('apikey', $foreign, 'local_ai_course_assistant');

        $this->assertSame('', secrets::get('apikey'));
        $this->assertDebuggingCalled();
    }

    /**
     * Nothing reads a secret with get_config() except through secrets::get().
     *
     * Catches a literal name, a class constant holding a secret name, and a
     * name built from a secret suffix. A read that deliberately keeps the
     * stored form (save-and-restore) carries "Raw on purpose" on its line.
     */
    public function test_no_secret_is_read_around_the_helper(): void {
        global $CFG;
        $root = $CFG->dirroot . '/local/ai_course_assistant';

        $secrets = secrets::names();
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $problems = [];
        $constants = [];
        $files = [];
        foreach ($iter as $file) {
            $path = $file->getPathname();
            $rel = substr($path, strlen($root) + 1);
            if (substr($path, -4) !== '.php' || preg_match('~^(tests|vendor|node_modules|cdn|services)/~', $rel)) {
                continue;
            }
            $src = file_get_contents($path);
            $files[$rel] = $src;
            // Collect constants whose value is a secret name.
            if (preg_match_all("~const\\s+([A-Z_]+)\\s*=\\s*'([a-z_]+)'~", $src, $m, PREG_SET_ORDER)) {
                foreach ($m as $c) {
                    if (in_array($c[2], $secrets, true)) {
                        $constants[$c[1]] = $c[2];
                    }
                }
            }
        }
        $this->assertArrayHasKey('SETTING_KEY', $constants, 'the constant scan must see spend_export::SETTING_KEY');
        $this->assertArrayHasKey('SETTING_APIKEY', $constants, 'the constant scan must see embedding_migration::SETTING_APIKEY');

        foreach ($files as $rel => $src) {
            // Over the whole file, not line by line: a call written across
            // several lines (arguments on their own lines) is the common shape
            // for exactly the long constant-name reads this exists to catch.
            if (!preg_match_all("~get_config\\(\\s*'local_ai_course_assistant'\\s*,\\s*([^)]*?)\\s*\\)~s",
                    $src, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($calls as $call) {
                [$text, $offset] = $call[0];
                $lineno = substr_count($src, "\n", 0, $offset) + 1;
                $eol = strpos($src, "\n", $offset + strlen($text));
                $span = substr($src, $offset, ($eol === false ? strlen($src) : $eol) - $offset);
                if (strpos($span, 'Raw on purpose') !== false) {
                    continue;
                }
                $arg = trim($call[1][0]);
                $hit = null;
                if (preg_match("~^'([a-z_]+)'$~", $arg, $lit) && in_array($lit[1], $secrets, true)) {
                    $hit = $lit[1];
                } else if (preg_match('~::([A-Z_]+)$~', $arg, $c) && isset($constants[$c[1]])) {
                    $hit = $constants[$c[1]];
                } else if (preg_match("~'(_api_key|_webhook_secret|_apikey|_token|_secret)'$~", $arg, $suffix)) {
                    $hit = '*' . $suffix[1];
                }
                if ($hit !== null) {
                    $problems[] = "{$rel}:{$lineno} reads {$hit} with get_config()";
                }
            }
        }
        $this->assertSame([], $problems, "Secrets read around secrets::get():\n  " . implode("\n  ", $problems));
    }
}
