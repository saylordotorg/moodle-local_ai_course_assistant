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
 * The answer safety site rules editor (8.4.0): rows saved as JSON, every row validated, no key column.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\admin_setting_answer_rules
 */
final class admin_setting_answer_rules_test extends \advanced_testcase {

    private function setting(): admin_setting_answer_rules {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        return new admin_setting_answer_rules('local_ai_course_assistant/answer_safety_rules', 'Rules', 'desc');
    }

    private function row(string $id, string $value, string $kind = 'keyword', string $action = 'warn'): array {
        return ['rule_id' => $id, 'category' => 'policy', 'kind' => $kind, 'value' => $value, 'applies_to' => 'both',
            'action' => $action];
    }

    public function test_rows_are_saved_as_json_and_used_by_the_engine(): void {
        $this->resetAfterTest();
        $this->assertSame('', $this->setting()->write_setting(['present' => 1, 'rows' => [
            $this->row('site.housing', 'housing office, dorm'),
            $this->row('site.visa', '\bvisa\s+(?:status|office)|i-20', 'pattern', 'block'),
        ]]));
        $stored = json_decode((string) get_config('local_ai_course_assistant', 'answer_safety_rules'), true);
        $this->assertSame(['site.housing', 'site.visa'], array_column($stored, 'id'));
        $this->assertSame('\bvisa\s+(?:status|office)|i-20', $stored[1]['value'], 'a pipe in a pattern survives');
        $this->assertCount(2, answer_rules::site_rules());
        $r = answer_rules::evaluate('Ask the visa office.', ['question' => 'q', 'mode' => 'enforce']);
        $this->assertSame('block', $r['action']);
        // Removing every row clears the setting.
        $this->assertSame('', $this->setting()->write_setting(['present' => 1]));
        $this->assertSame('', (string) get_config('local_ai_course_assistant', 'answer_safety_rules'));
    }

    public function test_an_invalid_row_stops_the_save_with_its_reason(): void {
        $this->resetAfterTest();
        $this->setting()->write_setting(['rows' => [$this->row('site.keep', 'kept')]]);
        $before = get_config('local_ai_course_assistant', 'answer_safety_rules');
        foreach ([
            [$this->row('site.long', str_repeat('a', 201), 'pattern')],
            [$this->row('site.boom', '(a+)+$', 'pattern')],
            [$this->row('grades.guarantee', 'x')],
            [$this->row('site.dup', 'a'), $this->row('site.dup', 'b')],
            [array_merge($this->row('site.act', 'a'), ['action' => 'explode'])],
        ] as $rows) {
            $error = $this->setting()->write_setting(['rows' => $rows]);
            $this->assertNotSame('', $error, json_encode($rows));
            $this->assertStringContainsString(get_string('answer_rules:err_resubmit', 'local_ai_course_assistant'),
                $error);
            $this->assertStringNotContainsString('Keys are never shown', $error);
            $this->assertSame($before, get_config('local_ai_course_assistant', 'answer_safety_rules'), 'nothing saved');
        }
    }

    public function test_the_table_renders_without_a_key_column(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('site.housing', 'housing office')]]);
        $html = $setting->output_html('');
        $this->assertStringContainsString('housing office', $html);
        $this->assertStringNotContainsString('sola-kr-keycell', $html);
        $this->assertStringNotContainsString('type="password"', $html);
        $this->assertStringNotContainsString(get_string('providerkeys:col_key', 'local_ai_course_assistant'), $html);
        $this->assertStringContainsString(get_string('answer_rules:col_value', 'local_ai_course_assistant'), $html);
    }

    /**
     * The form fields a browser would post back for the rendered table, as Moodle hands them to write_setting().
     *
     * @param string $html
     * @return array
     */
    private function posted_from(string $html): array {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $post = [];
        $set = static function (array &$post, string $name, string $value): void {
            if (!preg_match('~^s_local_ai_course_assistant_answer_safety_rules((?:\[[^\]]*\])+)$~', $name, $m)) {
                return;
            }
            preg_match_all('~\[([^\]]*)\]~', $m[1], $keys);
            $ref = &$post;
            foreach ($keys[1] as $key) {
                $ref = &$ref[$key];
            }
            $ref = $value;
        };
        foreach ($doc->getElementsByTagName('input') as $input) {
            // The blank row template lives inside <template>; a browser never posts it.
            if ($input->parentNode && $this->inside_template($input)) {
                continue;
            }
            $set($post, $input->getAttribute('name'), $input->getAttribute('value'));
        }
        foreach ($doc->getElementsByTagName('select') as $select) {
            if ($this->inside_template($select)) {
                continue;
            }
            foreach ($select->getElementsByTagName('option') as $option) {
                if ($option->hasAttribute('selected')) {
                    $set($post, $select->getAttribute('name'), $option->getAttribute('value'));
                }
            }
        }
        return $post;
    }

    private function inside_template(\DOMNode $node): bool {
        for ($n = $node->parentNode; $n; $n = $n->parentNode) {
            if ($n->nodeName === 'template') {
                return true;
            }
        }
        return false;
    }

    public function test_rule_ids_survive_render_and_resave(): void {
        $this->resetAfterTest();
        $this->assertSame('', $this->setting()->write_setting(['present' => 1, 'rows' => [
            $this->row('site.housing', 'housing office, dorm'),
            $this->row('site.visa', '\bvisa\s+status|i-20', 'pattern', 'block'),
        ]]));
        $before = (string) get_config('local_ai_course_assistant', 'answer_safety_rules');
        $html = $this->setting()->output_html('');
        $this->assertStringContainsString('value="site.housing"', $html);
        $this->assertStringContainsString('value="site.visa"', $html);

        // Save the page untouched: the stored rules come back exactly as they were.
        $posted = $this->posted_from($html);
        $this->assertCount(2, $posted['rows']);
        $this->assertSame('', $this->setting()->write_setting($posted));
        $after = (string) get_config('local_ai_course_assistant', 'answer_safety_rules');
        $this->assertSame(['site.housing', 'site.visa'], array_column(json_decode($after, true), 'id'));
        $this->assertSame($before, $after);
        $this->assertSame(['site.housing', 'site.visa'], array_column(answer_rules::site_rules(), 'id'));
    }

    public function test_config_log_keeps_the_rules_as_written(): void {
        $raw = json_encode([$this->row('site.a', 'x|y', 'pattern')]);
        $this->assertSame($raw, admin_setting_answer_rules::redact_keys($raw));
    }
}
