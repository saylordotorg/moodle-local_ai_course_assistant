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
 * The per-course page keeps base URL, system prompt, temperature and monthly spend cap behind a native
 * "More options" details element, open only when one of them already holds a value.
 *
 * The page is a script, so it can't be run from a test. What is tested instead is each half of it:
 * the "is anything set?" decision (a pure method, plus the real database round trip it reads), the
 * template as Moodle renders it for both answers, and the one line of the script that joins the two.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\course_config_manager::has_more_options_values
 */
final class course_settings_more_options_test extends \advanced_testcase {

    /** @var string[] The four fields the wrapper holds, in the order they sat on the page before it. */
    private const FOUR = ['spend_cap_monthly', 'apibaseurl', 'temperature', 'systemprompt'];

    /** @var string[] Every control of the provider card that posts, in the order the browser sends them. */
    private const CARD_ORDER = [
        'enabled', 'provider', 'apikey', 'apikey_clear', 'model',
        'spend_cap_monthly', 'apibaseurl', 'temperature', 'systemprompt',
    ];

    /**
     * A course_cfg row as the database hands it back (numbers arrive as strings), with nothing in the four.
     *
     * @param array $override Fields to replace.
     * @return \stdClass
     */
    private function row(array $override = []): \stdClass {
        return (object) ($override + [
            'enabled' => '1',
            'provider' => 'claude',
            'apikey' => 'stored-ciphertext',
            'model' => 'claude-sonnet-5-5',
            'apibaseurl' => '',
            'systemprompt' => '',
            'temperature' => null,
            'spend_cap_monthly' => null,
        ]);
    }

    /**
     * The template's context for the provider card, with every value filled in.
     *
     * @param bool $open Whether the page decided More options starts open.
     * @return array
     */
    private function context(bool $open): array {
        return [
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
            'apikeyplaceholder' => 'Using global setting',
            'haskey' => true,
            'apikeyclearlabel' => 'Clear the stored key for this course',
            'modellabel' => 'Model',
            'apibaseurllabel' => 'API base URL',
            'temperaturelabel' => 'Temperature',
            'systempromptlabel' => 'System prompt',
            'resetpromptlabel' => 'Reset to default',
            'moreoptionslabel' => 'More options',
            'moreoptionsopen' => $open,
        ];
    }

    /**
     * Render the real template.
     *
     * @param bool $open The page's open/closed decision.
     * @return \DOMDocument The rendered page as a document.
     */
    private function render(bool $open): \DOMDocument {
        global $OUTPUT, $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/local/ai_course_assistant/course_settings.php');
        $html = $OUTPUT->render_from_template('local_ai_course_assistant/course_settings', $this->context($open));
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $doc;
    }

    /**
     * The details element the four fields sit in, or null when there isn't one.
     *
     * @param \DOMDocument $doc
     * @return \DOMElement|null
     */
    private function wrapper(\DOMDocument $doc): ?\DOMElement {
        $found = (new \DOMXPath($doc))->query(
            "//details[contains(concat(' ', normalize-space(@class), ' '), ' sola-more-options ')]"
        );
        return $found->length === 1 ? $found->item(0) : null;
    }

    /**
     * The names of the controls under a node, in document order.
     *
     * @param \DOMDocument $doc
     * @param \DOMNode|null $under Limit to this node's descendants, or the whole form when null.
     * @return string[]
     */
    private function names(\DOMDocument $doc, ?\DOMNode $under = null): array {
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('.//input[@name] | .//select[@name] | .//textarea[@name]', $under ?? $doc->documentElement);
        $names = [];
        foreach ($nodes as $node) {
            $names[] = $node->getAttribute('name');
        }
        return $names;
    }

    /**
     * A course with nothing in the four has nothing to show, whatever else it overrides.
     */
    public function test_a_row_with_none_of_the_four_set_stays_closed(): void {
        $this->assertFalse(course_config_manager::has_more_options_values(null), 'no row at all');
        $this->assertFalse(course_config_manager::has_more_options_values($this->row()),
            'a row that overrides the provider, key and model but none of the four');
        $this->assertFalse(course_config_manager::has_more_options_values($this->row(['spend_cap_monthly' => '0.00'])),
            'a zero cap is no cap, which is how the page shows it');
        $this->assertFalse(course_config_manager::has_more_options_values($this->row(['temperature' => ''])),
            'an empty temperature is not a temperature');
    }

    /**
     * Any one of the four is enough to open it, so a stored override is never hidden.
     */
    public function test_any_one_of_the_four_opens_it(): void {
        $cases = [
            'base URL' => ['apibaseurl' => 'https://gateway.example.org/v1'],
            'system prompt' => ['systemprompt' => 'You are a patient tutor.'],
            'temperature' => ['temperature' => '0.40'],
            'temperature of exactly zero' => ['temperature' => '0.00'],
            'spend cap' => ['spend_cap_monthly' => '25.00'],
            'a prompt that is only spaces' => ['systemprompt' => '  '],
        ];
        foreach ($cases as $what => $fields) {
            $this->assertTrue(course_config_manager::has_more_options_values($this->row($fields)), $what);
        }
    }

    /**
     * The decision reads what a save really stores: the row comes back from the database with strings, and a
     * temperature of 0 is a stored value, not a missing one.
     */
    public function test_the_decision_agrees_with_what_save_stores(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $none = $generator->create_course();
        $temp = $generator->create_course();
        $zero = $generator->create_course();
        $cap = $generator->create_course();
        $base = ['enabled' => 1, 'provider' => 'claude', 'model' => 'm', 'apikey' => ''];

        course_config_manager::save($none->id, $base);
        course_config_manager::save($temp->id, $base + ['temperature' => '0.7']);
        course_config_manager::save($zero->id, $base + ['temperature' => '0']);
        course_config_manager::save($cap->id, $base + ['spend_cap_monthly' => '12.5']);

        $this->assertFalse(course_config_manager::has_more_options_values(course_config_manager::get($none->id)));
        $this->assertTrue(course_config_manager::has_more_options_values(course_config_manager::get($temp->id)));
        $this->assertTrue(course_config_manager::has_more_options_values(course_config_manager::get($zero->id)));
        $this->assertTrue(course_config_manager::has_more_options_values(course_config_manager::get($cap->id)));
        $this->assertFalse(course_config_manager::has_more_options_values(
            course_config_manager::get($generator->create_course()->id)), 'a course with no row at all');
    }

    /**
     * Closed: the four fields are inside a details element with no open attribute, and nothing else is.
     */
    public function test_the_four_fields_sit_inside_a_closed_details_element(): void {
        $doc = $this->render(false);
        $wrapper = $this->wrapper($doc);
        $this->assertNotNull($wrapper, 'exactly one details.sola-more-options is rendered');
        $this->assertFalse($wrapper->hasAttribute('open'), 'no open attribute when none of the four is set');

        $inside = $this->names($doc, $wrapper);
        $this->assertSame(self::FOUR, $inside, 'the four fields, and nothing else, in the order they had');

        $summary = (new \DOMXPath($doc))->query('./summary', $wrapper);
        $this->assertSame(1, $summary->length, 'one summary');
        $this->assertSame('More options', trim($summary->item(0)->textContent));
    }

    /**
     * Open: the same markup with the open attribute, so a value that is stored is on screen.
     */
    public function test_the_details_element_opens_when_the_page_says_so(): void {
        $doc = $this->render(true);
        $wrapper = $this->wrapper($doc);
        $this->assertNotNull($wrapper);
        $this->assertTrue($wrapper->hasAttribute('open'), 'open when one of the four is set');
        $this->assertSame(self::FOUR, $this->names($doc, $wrapper));
    }

    /**
     * Nothing posts differently: the same nine controls under the same names, each still inside the one form.
     * The only movement is the spend cap, which now follows the model with the other three.
     */
    public function test_every_field_still_posts_under_its_own_name_from_inside_the_form(): void {
        foreach ([false, true] as $open) {
            $doc = $this->render($open);
            $forms = (new \DOMXPath($doc))->query('//form');
            $this->assertSame(1, $forms->length, 'one form');
            $inform = $this->names($doc, $forms->item(0));
            $card = array_values(array_filter($inform, fn($name) => in_array($name, self::CARD_ORDER, true)));
            $this->assertSame(self::CARD_ORDER, $card, 'the provider card posts the same controls, once each');
            $this->assertSame(count($inform), count($this->names($doc)), 'no control sits outside the form');
        }
    }

    /**
     * The three controls that stay on show are not inside the wrapper, and the key input is untouched by it.
     */
    public function test_provider_key_and_model_stay_outside_it(): void {
        $doc = $this->render(false);
        $inside = $this->names($doc, $this->wrapper($doc));
        foreach (['enabled', 'provider', 'apikey', 'apikey_clear', 'model'] as $name) {
            $this->assertNotContains($name, $inside, "{$name} stays visible");
        }
        $xpath = new \DOMXPath($doc);
        $key = $xpath->query('//input[@name="apikey"]');
        $this->assertSame(1, $key->length);
        $this->assertSame('', $key->item(0)->getAttribute('value'), 'the key input still ships empty');
    }

    /**
     * The script hands the template the decision, built from the stored row, and the label, from a lang string.
     * (The page can't be run here, so this pins the join at the source.)
     */
    public function test_the_script_feeds_the_template_the_decision_and_the_label(): void {
        $script = (string) file_get_contents(__DIR__ . '/../course_settings.php');
        $this->assertStringContainsString(
            '$moreoptionsopen = course_config_manager::has_more_options_values($current);',
            $script, 'the open/closed decision comes from the stored row');
        $this->assertStringContainsString("'moreoptionsopen' => \$moreoptionsopen,", $script,
            'and is what the template is given');
        $this->assertStringContainsString(
            "'moreoptionslabel' => get_string('coursesettings:more_options', 'local_ai_course_assistant'),",
            $script, 'the summary text is a language string');
        $template = (string) file_get_contents(__DIR__ . '/../templates/course_settings.mustache');
        $this->assertSame(1, substr_count($template, '<details'), 'one details element on the page');
        $this->assertMatchesRegularExpression(
            '~<details class="sola-more-options"\s*\{\{#moreoptionsopen\}\}open\{\{/moreoptionsopen\}\}>~',
            $template, 'the open attribute is the only thing that varies');
    }

    /**
     * The summary text exists in English, and is the words the spec names.
     */
    public function test_the_summary_string_is_defined(): void {
        $this->assertSame('More options', get_string('coursesettings:more_options', 'local_ai_course_assistant'));
    }
}
