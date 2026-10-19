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
use local_ai_course_assistant\check\key_places;

/**
 * The Reports > Status check that says which API keys are stored in more than one place (7.11.0).
 *
 * It is advice, so it is INFO whatever it finds. It names places and last-four hints, and never a key: every
 * test that plants a key searches the whole result for it, for its ciphertext and for each 8-character window
 * of it. Failure messages never print a key.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\check\key_places
 */
final class key_places_check_test extends \advanced_testcase {

    /** @var string One OpenAI key stored in three places. */
    private const SHARED = 'sk-proj-KPSHARED-SENTINEL-0001-AB12';
    /** @var string A Claude key stored once. */
    private const SINGLE = 'sk-ant-KPSINGLE-SENTINEL-0002-ZZ99';
    /** @var string A second key stored twice. */
    private const PAIR = 'pa-KPPAIR-SENTINEL-0003-QQ77';

    /** @var string[] Every value stored for a sentinel (ciphertext and plain), to search the output for. */
    private array $stored = [];

    /**
     * Set a plugin setting and remember the stored value.
     *
     * @param string $name
     * @param string $value
     * @return void
     */
    private function cfg(string $name, string $value): void {
        set_config($name, $value, 'local_ai_course_assistant');
        $this->stored[] = $value;
    }

    /**
     * Store a course's own key straight into course_cfg.
     *
     * @param string $shortname the new course's short name
     * @param string $key the plain key
     * @return int the course id
     */
    private function course_key(string $shortname, string $key): int {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['shortname' => $shortname]);
        $stored = secrets::conceal($key);
        $DB->insert_record('local_ai_course_assistant_course_cfg', (object) [
            'courseid' => $course->id, 'enabled' => 1, 'provider' => 'openai', 'apikey' => $stored,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $this->stored[] = $stored;
        return (int) $course->id;
    }

    /**
     * Plant SHARED in a table row, the embedding setting and one course; SINGLE once; PAIR twice.
     *
     * @return void
     */
    private function plant(): void {
        set_config('provider', 'openai', 'local_ai_course_assistant');
        $this->cfg('comparison_providers', 'openai|' . secrets::conceal(self::SHARED) . "|gpt-4o-mini\n"
            . 'claude|' . secrets::conceal(self::SINGLE) . '|claude-haiku-4-5');
        $this->cfg('embed_apikey', secrets::conceal(self::SHARED));
        $this->cfg('rerank_apikey', secrets::conceal(self::PAIR));
        $this->cfg('embed_migration_target_apikey', secrets::conceal(self::PAIR));
        $this->course_key('KPCOURSE1', self::SHARED);
    }

    /**
     * Everything the result can show, as one string per output form.
     *
     * @param result $result
     * @return string[]
     */
    private function outputs(result $result): array {
        return [
            'summary' => $result->get_summary(),
            'details' => $result->get_details(),
            'json' => json_encode([$result->get_summary(), $result->get_details(), $result->get_status()]),
            'print_r' => print_r($result, true),
        ];
    }

    /**
     * Assert no key, ciphertext or 8-character window of a key is in any output form of the result.
     *
     * @param result $result
     * @param string[] $keys the plain keys planted
     * @return void
     */
    private function assert_no_key_anywhere(result $result, array $keys): void {
        foreach ($this->outputs($result) as $form => $text) {
            foreach ($keys as $i => $key) {
                $this->assertFalse(str_contains($text, $key), "$form holds planted key #$i");
                for ($at = 0; $at + 8 <= strlen($key); $at++) {
                    if (str_contains($text, substr($key, $at, 8))) {
                        $this->fail("$form holds an 8-character window of planted key #$i at offset $at");
                    }
                }
            }
            foreach ($this->stored as $i => $value) {
                $this->assertFalse($value !== '' && str_contains($text, $value), "$form holds stored value #$i");
            }
            $this->assertFalse(str_contains($text, 'sodium:'), "$form holds ciphertext");
        }
    }

    public function test_a_site_with_no_keys_gets_an_info_result_that_says_none_are_stored(): void {
        $this->resetAfterTest();
        $result = (new key_places())->get_result();
        $this->assertDebuggingNotCalled();
        $this->assertSame(result::INFO, $result->get_status());
        $this->assertSame(get_string('check:key_places_none', 'local_ai_course_assistant'), $result->get_summary());
        $this->assertSame('', $result->get_details(), 'nothing to list');
    }

    public function test_the_name_is_a_language_string(): void {
        $this->assertSame(get_string('check:key_places', 'local_ai_course_assistant'), (new key_places())->get_name());
        $this->assertNotSame('', (new key_places())->get_name());
    }

    public function test_a_key_in_one_place_only_is_not_listed(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', 'claude|' . secrets::conceal(self::SINGLE) . '|claude-haiku-4-5');
        set_config('provider', 'claude', 'local_ai_course_assistant');
        $result = (new key_places())->get_result();
        $this->assertSame(result::INFO, $result->get_status());
        $this->assertSame(get_string('check:key_places_single', 'local_ai_course_assistant'), $result->get_summary());
        $this->assertSame('', $result->get_details(), 'a key kept in one place is not advice');
        $this->assertStringNotContainsString('ZZ99', $result->get_summary() . $result->get_details());
        $this->assert_no_key_anywhere($result, [self::SINGLE]);
    }

    public function test_a_key_in_three_places_names_the_places_and_the_last_four_and_no_key(): void {
        $this->resetAfterTest();
        $this->plant();
        $result = (new key_places())->get_result();
        $this->assertDebuggingNotCalled();

        $this->assertSame(result::INFO, $result->get_status(), 'advice, never a warning');
        $details = $result->get_details();
        $this->assertStringContainsString('AB12', $details, 'the last four of the shared key');
        $this->assertStringContainsString('openai', $details, 'the table row');
        $this->assertStringContainsString('embed_apikey', $details, 'the pasted embedding setting');
        $this->assertStringContainsString('KPCOURSE1', $details, 'the course short name');
        $this->assertStringContainsString('rotate_key.php', $details, 'points at the command');

        $this->assert_no_key_anywhere($result, [self::SHARED, self::SINGLE, self::PAIR]);
    }

    public function test_only_keys_in_two_or_more_places_are_listed_and_the_summary_counts_them(): void {
        $this->resetAfterTest();
        $this->plant();
        $result = (new key_places())->get_result();
        $details = $result->get_details();

        // SHARED (3 places) and PAIR (2 places) are listed; SINGLE (1 place) is not.
        $this->assertStringContainsString('AB12', $details);
        $this->assertStringContainsString('QQ77', $details);
        $this->assertStringNotContainsString('ZZ99', $details);
        $this->assertStringNotContainsString('claude', $details, 'the single-place key and its row are not listed');
        $this->assertStringContainsString('rerank_apikey', $details);
        $this->assertStringContainsString('embed_migration_target_apikey', $details);
        $this->assertSame(
            get_string('check:key_places_some', 'local_ai_course_assistant', 2),
            $result->get_summary(), 'two keys are stored in more than one place');
    }

    public function test_the_biggest_group_is_listed_first(): void {
        $this->resetAfterTest();
        $this->plant();
        $details = (new key_places())->get_result()->get_details();
        $this->assertLessThan(strpos($details, 'QQ77'), strpos($details, 'AB12'),
            'three places come before two, as key_inventory::groups() orders them');
    }

    public function test_the_default_keys_automatic_copy_is_named_as_a_copy(): void {
        $this->resetAfterTest();
        set_config('provider', 'openai', 'local_ai_course_assistant');
        $this->cfg('comparison_providers', 'openai|' . secrets::conceal(self::SHARED) . '|gpt-4o-mini');
        $this->cfg('apikey', secrets::conceal(self::SHARED));
        $details = (new key_places())->get_result()->get_details();
        $this->assertStringContainsString('openai', $details);
        $this->assertStringContainsString(
            get_string('check:key_places_mirror', 'local_ai_course_assistant'), $details,
            'the legacy apikey setting is described, not shown as a bare setting name');
    }

    public function test_voice_rows_and_course_places_are_named(): void {
        $this->resetAfterTest();
        $this->cfg('voice_providers', 'openai|' . secrets::conceal(self::SHARED) . '|tts-1');
        $this->cfg('embed_apikey', secrets::conceal(self::SHARED));
        $details = (new key_places())->get_result()->get_details();
        $this->assertStringContainsString(
            get_string('check:key_places_voice', 'local_ai_course_assistant', 'openai'), $details);
        $this->assertStringContainsString('embed_apikey', $details);
    }

    public function test_a_course_name_is_escaped(): void {
        $this->resetAfterTest();
        $this->cfg('embed_apikey', secrets::conceal(self::SHARED));
        $this->course_key('<b>BAD</b>&x', self::SHARED);
        $details = (new key_places())->get_result()->get_details();
        $this->assertStringNotContainsString('<b>BAD', $details, 'a short name is page text, not markup');
        $this->assertStringContainsString('&lt;b&gt;BAD&lt;/b&gt;&amp;x', $details);
    }

    public function test_a_long_list_of_courses_is_cut_and_says_how_many_more(): void {
        $this->resetAfterTest();
        $this->cfg('embed_apikey', secrets::conceal(self::SHARED));
        for ($i = 1; $i <= key_places::PLACE_LIMIT + 5; $i++) {
            $this->course_key('KPLONG' . $i, self::SHARED);
        }
        // One pasted setting and PLACE_LIMIT + 5 courses: the list shows PLACE_LIMIT places, 6 are left.
        $details = (new key_places())->get_result()->get_details();
        $this->assertSame(key_places::PLACE_LIMIT, substr_count($details, '<span class="sola-keyplace">'));
        $this->assertStringContainsString(get_string('check:key_places_more', 'local_ai_course_assistant', 6), $details);
        $this->assert_no_key_anywhere((new key_places())->get_result(), [self::SHARED]);
    }

    public function test_a_key_too_short_for_a_hint_is_listed_without_one_and_without_the_key(): void {
        $this->resetAfterTest();
        $short = 'abc123XY';
        $this->cfg('embed_apikey', secrets::conceal($short));
        $this->cfg('realtime_apikey', secrets::conceal($short));
        $result = (new key_places())->get_result();
        $this->assertSame(result::INFO, $result->get_status());
        $details = $result->get_details();
        $this->assertStringContainsString('embed_apikey', $details);
        $this->assertStringContainsString('realtime_apikey', $details);
        $this->assertStringContainsString(get_string('check:key_places_keyshort', 'local_ai_course_assistant'), $details);
        $this->assert_no_key_anywhere($result, [$short]);
    }

    public function test_a_value_this_site_cannot_decrypt_is_skipped_quietly(): void {
        $this->resetAfterTest();
        $foreign = 'sodium:' . base64_encode(random_bytes(80));
        $this->cfg('embed_apikey', $foreign);
        $this->cfg('realtime_apikey', $foreign);
        $result = (new key_places())->get_result();
        $this->assertDebuggingNotCalled();
        $this->assertSame(get_string('check:key_places_none', 'local_ai_course_assistant'), $result->get_summary());
        $this->assert_no_key_anywhere($result, []);
    }

    public function test_the_status_is_info_for_every_state(): void {
        $this->resetAfterTest();
        $states = [];
        $states['none'] = (new key_places())->get_result()->get_status();
        $this->cfg('embed_apikey', secrets::conceal(self::SINGLE));
        $states['single'] = (new key_places())->get_result()->get_status();
        $this->plant();
        $states['duplicated'] = (new key_places())->get_result()->get_status();
        $this->assertSame(['none' => result::INFO, 'single' => result::INFO, 'duplicated' => result::INFO], $states);
    }

    public function test_the_plugin_registers_the_check(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/ai_course_assistant/lib.php');
        $found = array_filter(local_ai_course_assistant_status_checks(), fn($c) => $c instanceof key_places);
        $this->assertCount(1, $found, 'registered exactly once');
    }

    /**
     * The check reads places through key_inventory::groups() only. It never reads a key itself, and it never
     * prints with a debug function, so there is no route for a key to reach the page that this file could open.
     */
    public function test_the_class_only_reads_the_inventory_and_never_handles_a_key(): void {
        $source = (string) file_get_contents(__DIR__ . '/../classes/check/key_places.php');
        $this->assertNotSame('', $source);
        $this->assertStringContainsString('key_inventory::groups()', $source);
        foreach (['secrets::', 'get_config', 'provider_keys::rows', 'var_dump', 'print_r', 'var_export', 'error_log'] as $bad) {
            $this->assertStringNotContainsString($bad, $source, "the check must not use $bad");
        }
        $this->assertDoesNotMatchRegularExpression("~\\['(key|stored|plain|apikey)'\\]~", $source);
    }
}
