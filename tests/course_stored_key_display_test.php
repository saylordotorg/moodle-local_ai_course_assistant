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
 * The per-course page shows "a key is stored" only for a course that really holds a key.
 *
 * A course row made by a restore has a NULL apikey column, not an empty one. The page used to compare the
 * column with '' (and NULL !== ''), so it showed the stored-key placeholder and the "clear the stored key"
 * checkbox for a course with no key at all. The page is a script and can't be run from a test, so the decision
 * is a pure method on course_config_manager (tested here against rows as the database returns them), the real
 * template is rendered for both answers, and the one line of the script that joins them is pinned at the source.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\course_config_manager::has_stored_key
 */
final class course_stored_key_display_test extends \advanced_testcase {

    /** @var string A key planted in a course row. */
    private const KEY = 'sk-proj-COURSEKEY-SENTINEL-0001-ABCD';

    /**
     * Insert a course_cfg row straight into the table, the way a restore does (no key column handling at all).
     *
     * @param string|null $apikey the column value, null for a restored row with no key
     * @return \stdClass the row as course_config_manager::get() returns it
     */
    private function row_with(?string $apikey): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('local_ai_course_assistant_course_cfg', (object) [
            'courseid' => $course->id, 'enabled' => 1, 'provider' => 'openai', 'apikey' => $apikey,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $record = course_config_manager::get((int) $course->id);
        $this->assertNotNull($record);
        return $record;
    }

    /**
     * Render the real template with the key decision the page would make for a row.
     *
     * The placeholder is built exactly as course_settings.php builds it (pinned by
     * settings_no_secret_in_page_test), from the decision under test.
     *
     * @param \stdClass|null $record the course's row
     * @return \DOMDocument the rendered page
     */
    private function render_for(?\stdClass $record): \DOMDocument {
        global $OUTPUT, $PAGE;
        $PAGE->set_url('/local/ai_course_assistant/course_settings.php');
        $haskey = course_config_manager::has_stored_key($record);
        $html = $OUTPUT->render_from_template('local_ai_course_assistant/course_settings', [
            'formaction' => '/local/ai_course_assistant/course_settings.php?courseid=2',
            'sesskey' => 'abc123',
            'enabledlabel' => 'Enable course overrides',
            'providerlabel' => 'AI provider',
            'providerselect' => [
                'hasid' => true, 'id' => 'provider', 'name' => 'provider', 'selectclass' => 'form-control',
                'options' => [['value' => '', 'label' => 'Using global setting', 'selected' => true]],
            ],
            'spendcaplabel' => 'Monthly spend cap (USD)',
            'spendcapvalue' => '',
            'apikeylabel' => 'API key',
            'apikeyplaceholder' => $haskey
                ? get_string('coursesettings:apikey_stored', 'local_ai_course_assistant')
                : get_string('coursesettings:using_global', 'local_ai_course_assistant'),
            'haskey' => (bool) $haskey,
            'apikeyclearlabel' => 'Clear the stored key for this course',
            'modellabel' => 'Model',
            'apibaseurllabel' => 'API base URL',
            'temperaturelabel' => 'Temperature',
            'systempromptlabel' => 'System prompt',
            'resetpromptlabel' => 'Reset to default',
            'moreoptionslabel' => 'More options',
            'moreoptionsopen' => false,
        ]);
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $doc;
    }

    public function test_a_course_with_no_row_a_null_key_or_an_empty_key_holds_no_key(): void {
        $this->resetAfterTest();
        $this->assertFalse(course_config_manager::has_stored_key(null), 'no row at all');
        $this->assertFalse(course_config_manager::has_stored_key($this->row_with(null)),
            'a row made by a restore has a NULL key column');
        $this->assertFalse(course_config_manager::has_stored_key($this->row_with('')), 'an empty key column');
        $this->assertFalse(course_config_manager::has_stored_key((object) ['provider' => 'openai']),
            'a record with no key field at all');
        $this->assertFalse(course_config_manager::has_stored_key($this->row_with(" \t\n ")),
            'whitespace only is not a key, as the key inventory reads it');
    }

    public function test_a_course_with_a_stored_value_holds_a_key(): void {
        $this->resetAfterTest();
        $this->assertTrue(course_config_manager::has_stored_key($this->row_with(secrets::conceal(self::KEY))),
            'a key saved by the page (ciphertext)');
        $this->assertTrue(course_config_manager::has_stored_key($this->row_with(self::KEY)),
            'a legacy plain key');
        $this->assertTrue(course_config_manager::has_stored_key(
            $this->row_with('sodium:' . base64_encode(random_bytes(60)))),
            'a value this site can not decrypt is still stored, so the page lets the admin clear it');
    }

    public function test_the_decision_agrees_with_what_save_stores(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        course_config_manager::save((int) $course->id, ['enabled' => 1, 'provider' => 'openai', 'apikey' => self::KEY]);
        course_config_manager::save((int) $other->id, ['enabled' => 1, 'provider' => 'openai', 'apikey' => '']);
        $this->assertTrue(course_config_manager::has_stored_key(course_config_manager::get((int) $course->id)));
        $this->assertFalse(course_config_manager::has_stored_key(course_config_manager::get((int) $other->id)));
    }

    public function test_a_null_key_renders_no_stored_key_placeholder_and_no_clear_checkbox(): void {
        $this->resetAfterTest();
        $stored = get_string('coursesettings:apikey_stored', 'local_ai_course_assistant');
        $global = get_string('coursesettings:using_global', 'local_ai_course_assistant');
        $xpath = fn(\DOMDocument $doc) => new \DOMXPath($doc);

        $doc = $this->render_for($this->row_with(null));
        $input = $xpath($doc)->query('//input[@name="apikey"]');
        $this->assertSame(1, $input->length);
        $this->assertSame($global, $input->item(0)->getAttribute('placeholder'), 'a NULL key uses the site key');
        $this->assertNotSame($stored, $input->item(0)->getAttribute('placeholder'));
        $this->assertSame(0, $xpath($doc)->query('//input[@name="apikey_clear"]')->length,
            'there is no stored key to clear');

        $doc = $this->render_for($this->row_with(secrets::conceal(self::KEY)));
        $input = $xpath($doc)->query('//input[@name="apikey"]');
        $this->assertSame($stored, $input->item(0)->getAttribute('placeholder'), 'a saved key is announced');
        $this->assertSame('', $input->item(0)->getAttribute('value'), 'and still never printed');
        $this->assertSame(1, $xpath($doc)->query('//input[@name="apikey_clear"]')->length, 'and can be cleared');
        // Not assertStringNotContainsString: a failure would print the key.
        $this->assertFalse(str_contains($doc->saveHTML(), self::KEY), 'the page holds the planted course key');
    }

    /**
     * The page joins the decision to the template with one line. Pin it at the source: it calls the method, and
     * it no longer compares the raw column itself, which is what let a NULL through.
     */
    public function test_the_page_asks_the_method_and_does_not_compare_the_column_itself(): void {
        $script = (string) file_get_contents(__DIR__ . '/../course_settings.php');
        $this->assertStringContainsString('$haskey = course_config_manager::has_stored_key($current);', $script);
        $this->assertStringNotContainsString('$current->apikey', $script, 'the page never reads the stored column');
    }
}
