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

use core\check\result;
use local_ai_course_assistant\check\course_keys;

/**
 * A course must not hold a key that belongs to another provider (v7.8.8).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\course_key_guard
 * @covers     \local_ai_course_assistant\check\course_keys
 */
final class course_key_guard_test extends \advanced_testcase {
    private const CLAUDE_KEY = 'sk-ant-api03-TESTKEYTESTKEYTESTKEY';
    private const GEMINI_KEY = 'AIzaSyTESTKEYTESTKEYTESTKEYTESTKEY';

    public function test_a_key_is_attributed_by_an_unambiguous_prefix_only(): void {
        $this->assertSame('claude', course_key_guard::owner_of_key(self::CLAUDE_KEY));
        $this->assertSame('gemini', course_key_guard::owner_of_key(self::GEMINI_KEY));
        $this->assertSame('gemini', course_key_guard::owner_of_key('AQ.Ab8RNtestkey'));
        $this->assertSame('openai', course_key_guard::owner_of_key('sk-proj-TESTKEY123'));
        $this->assertSame('openrouter', course_key_guard::owner_of_key('sk-or-v1-TESTKEY'));
        $this->assertSame('xai', course_key_guard::owner_of_key('xai-TESTKEY123'));
        $this->assertSame('', course_key_guard::owner_of_key('sk-TESTKEY123'), 'a bare sk- is shared by many vendors');
        $this->assertSame('', course_key_guard::owner_of_key('some-proxy-token'));
        $this->assertSame('', course_key_guard::owner_of_key(''));
    }

    public function test_the_effective_provider_follows_the_course_then_the_site_then_the_model(): void {
        $this->assertSame('gemini', course_key_guard::effective_provider('gemini', '', 'claude'));
        $this->assertSame('claude', course_key_guard::effective_provider('', '', 'claude'));
        $this->assertSame('gemini', course_key_guard::effective_provider('', 'gemini-3.1-flash-lite', 'auto'));
        $this->assertSame('claude', course_key_guard::effective_provider('auto', 'claude-haiku-4-5', 'gemini'));
        $this->assertSame('', course_key_guard::effective_provider('', '', 'auto'), 'cannot be told');
        $this->assertSame('gemini', course_key_guard::effective_provider('', '', 'auto', 'gemini-2.5-flash'), 'the site model decides');
        $this->assertSame('claude', course_key_guard::effective_provider('', 'claude-haiku-4-5', 'auto', 'gemini-2.5-flash'), 'the course model wins');
    }

    public function test_only_a_clear_mismatch_is_a_conflict(): void {
        $this->assertSame('claude', course_key_guard::conflict('gemini', self::CLAUDE_KEY));
        $this->assertSame('', course_key_guard::conflict('claude', self::CLAUDE_KEY));
        $this->assertSame('', course_key_guard::conflict('gemini', 'sk-TESTKEY123'), 'ambiguous key: no verdict');
        $this->assertSame('', course_key_guard::conflict('deepseek', 'sk-TESTKEY123'));
        $this->assertSame('', course_key_guard::conflict('', self::CLAUDE_KEY), 'provider unknown: no verdict');
        $this->assertSame('', course_key_guard::conflict('auto', self::CLAUDE_KEY));
        $this->assertSame('', course_key_guard::conflict('custom', self::CLAUDE_KEY));
        $this->assertSame('', course_key_guard::conflict('gemini', self::CLAUDE_KEY, 'https://proxy.example.org/v1'), 'a proxy takes any key');
    }

    /**
     * The BUS101 case: the provider changes to Gemini, the key box stays blank,
     * and the stored Claude key must go instead of being sent to Google.
     */
    public function test_switching_provider_with_the_key_box_blank_removes_the_stale_key(): void {
        $r = course_key_guard::resolve('gemini', 'gemini-3.1-flash-lite', 'claude', '', '', self::CLAUDE_KEY);
        $this->assertSame('', $r['apikey']);
        $this->assertCount(1, $r['notices']);
        $this->assertSame('warning', $r['notices'][0][0]);
        $this->assertSame('coursesettings:key_removed', $r['notices'][0][1]);
        $this->assertSame('claude', $r['notices'][0][2]->owner);
        $this->assertSame('gemini', $r['notices'][0][2]->provider);
    }

    public function test_on_an_auto_site_the_site_model_decides_which_key_fits(): void {
        $r = course_key_guard::resolve('', '', 'auto', '', '', self::CLAUDE_KEY, 'gemini-2.5-flash');
        $this->assertSame('', $r['apikey'], 'a Claude key under a Gemini site model is removed');
        $r = course_key_guard::resolve('', '', 'auto', '', '', self::CLAUDE_KEY, 'claude-haiku-4-5');
        $this->assertSame(self::CLAUDE_KEY, $r['apikey']);
    }

    public function test_a_blank_key_box_keeps_a_stored_key_that_still_fits(): void {
        $r = course_key_guard::resolve('claude', 'claude-haiku-4-5', 'gemini', '', '', self::CLAUDE_KEY);
        $this->assertSame(self::CLAUDE_KEY, $r['apikey']);
        $this->assertSame([], $r['notices']);
    }

    public function test_a_typed_key_for_another_provider_is_refused_and_the_stored_one_kept(): void {
        $r = course_key_guard::resolve('gemini', 'gemini-2.5-flash', 'claude', '', self::CLAUDE_KEY, self::GEMINI_KEY);
        $this->assertSame(self::GEMINI_KEY, $r['apikey']);
        $this->assertSame(['error'], array_column($r['notices'], 0));
        $this->assertSame('coursesettings:key_rejected', $r['notices'][0][1]);
    }

    public function test_a_refused_typed_key_over_a_stale_stored_one_removes_both(): void {
        $r = course_key_guard::resolve('gemini', 'gemini-2.5-flash', 'claude', '', self::CLAUDE_KEY, self::CLAUDE_KEY);
        $this->assertSame('', $r['apikey']);
        $this->assertSame(['error', 'warning'], array_column($r['notices'], 0));
    }

    public function test_a_typed_key_that_fits_replaces_the_stored_one(): void {
        $r = course_key_guard::resolve('gemini', 'gemini-2.5-flash', 'claude', '', self::GEMINI_KEY, self::CLAUDE_KEY);
        $this->assertSame(self::GEMINI_KEY, $r['apikey']);
        $this->assertSame([], $r['notices']);
    }

    public function test_a_course_with_its_own_base_url_is_never_checked(): void {
        $r = course_key_guard::resolve('gemini', '', 'claude', 'https://proxy.example.org/v1', '', self::CLAUDE_KEY);
        $this->assertSame(self::CLAUDE_KEY, $r['apikey']);
        $this->assertSame([], $r['notices']);
    }

    /**
     * Write a course's AI config row.
     *
     * @param string $shortname
     * @param string $provider
     * @param string $key Plain text; stored encrypted.
     * @param string $model
     * @param int $enabled
     */
    private function course_row(string $shortname, string $provider, string $key, string $model = '', int $enabled = 1): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['shortname' => $shortname]);
        $DB->insert_record('local_ai_course_assistant_course_cfg', (object) [
            'courseid' => $course->id, 'enabled' => $enabled, 'provider' => $provider,
            'apikey' => secrets::conceal($key), 'model' => $model, 'apibaseurl' => '',
            'systemprompt' => '', 'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    public function test_the_status_check_lists_existing_mismatches_and_otherwise_is_ok(): void {
        $this->resetAfterTest();
        set_config('provider', 'claude', 'local_ai_course_assistant');
        $check = new course_keys();
        $this->assertSame(result::OK, $check->get_result()->get_status());

        $this->course_row('GOOD101', 'claude', self::CLAUDE_KEY);
        $this->course_row('STALE101', 'gemini', self::CLAUDE_KEY, 'gemini-3.1-flash-lite');
        $this->course_row('OFF101', 'gemini', self::CLAUDE_KEY, '', 0);
        $this->course_row('SITE101', '', self::GEMINI_KEY);

        $r = $check->get_result();
        $this->assertSame(result::WARNING, $r->get_status());
        $this->assertStringContainsString('STALE101', $r->get_summary());
        $this->assertStringContainsString('SITE101', $r->get_summary(), 'inherits the site provider, claude');
        $this->assertStringNotContainsString('GOOD101', $r->get_summary());
        $this->assertStringNotContainsString('OFF101', $r->get_summary(), 'a disabled override is not used');
        $this->assertStringContainsString('2 course(s)', $r->get_summary());
        $this->assertStringContainsString('STALE101 (gemini &larr; claude)', $r->get_summary());
    }

    public function test_the_course_settings_page_asks_the_guard_before_it_saves_a_key(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/course_settings.php');
        $this->assertStringContainsString('course_key_guard::resolve(', $source);
        $this->assertGreaterThan(
            strpos($source, 'course_key_guard::resolve('),
            strpos($source, 'course_config_manager::save('),
            'the guard must run before the save'
        );
    }
}
