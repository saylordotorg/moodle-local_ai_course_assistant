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

defined('MOODLE_INTERNAL') || die();

/**
 * Admin setting for the site's own answer safety rules (8.4.0), added to the default pack in answer_rules.
 *
 * One row per rule: id, category, kind (keyword|pattern), value, applies_to (question|reply|both), action
 * (block|correct|warn|watch). Stored as a JSON list (a pattern may hold a pipe, so the pipe-delimited format of the
 * other row tables does not fit). Every row goes through answer_rules::validate_rule() before a save, so a pattern
 * over 200 characters, with a forbidden construct, or slower than the time budget is refused with its reason. The
 * rows share the keyed-rows editor without a key column; only site admins reach this page.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_answer_rules extends admin_setting_keyed_rows {

    /**
     * The columns.
     *
     * @return array[]
     */
    protected function columns(): array {
        $c = 'local_ai_course_assistant';
        $options = static function (array $values, string $prefix) use ($c): array {
            $out = [];
            foreach ($values as $v) {
                $out[$v] = get_string($prefix . $v, $c);
            }
            return $out;
        };
        return [
            ['name' => 'id', 'label' => 'answer_rules:col_id', 'type' => 'text', 'placeholder' => 'e.g. site.housing'],
            ['name' => 'category', 'label' => 'answer_rules:col_category', 'type' => 'select',
                'options' => $options(answer_rules::CATEGORIES, 'answer_rules:category_')],
            ['name' => 'kind', 'label' => 'answer_rules:col_kind', 'type' => 'select',
                'options' => $options(answer_rules::KINDS, 'answer_rules:kind_')],
            ['name' => 'value', 'label' => 'answer_rules:col_value', 'type' => 'text',
                'placeholder' => 'e.g. housing office, dorm'],
            ['name' => 'applies_to', 'label' => 'answer_rules:col_applies_to', 'type' => 'select',
                'options' => $options(answer_rules::APPLIES_TO, 'answer_rules:applies_')],
            ['name' => 'action', 'label' => 'answer_rules:col_action', 'type' => 'select',
                'options' => $options(answer_rules::RULE_ACTIONS, 'answer_rules:action_')],
        ];
    }

    /**
     * No key column.
     *
     * @return bool
     */
    protected function has_key_column(): bool {
        return false;
    }

    /**
     * The note after a failed save.
     *
     * @return string
     */
    protected function resubmit_note(): string {
        return get_string('answer_rules:err_resubmit', 'local_ai_course_assistant');
    }

    /**
     * Rows as stored, including ones that no longer validate (so the admin sees and fixes them).
     *
     * @return array[]
     */
    protected function load_rows(): array {
        $rows = [];
        foreach (answer_rules::decode_rules((string) $this->get_setting()) as $i => $rule) {
            $rows[] = ['id' => (string) $i, 'stored_key' => ''] + $rule;
        }
        return $rows;
    }

    /**
     * Rows to the stored JSON.
     *
     * @param array[] $rows
     * @return string
     */
    protected function store(array $rows): string {
        if (!$rows) {
            return '';
        }
        $list = [];
        foreach ($rows as $r) {
            $list[] = ['id' => $r['id'], 'category' => $r['category'], 'kind' => $r['kind'], 'value' => $r['value'],
                'applies_to' => $r['applies_to'], 'action' => $r['action']];
        }
        return json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Problems that stop a save: each rule's own, and a repeated id.
     *
     * @param array[] $rows
     * @return string[]
     */
    protected function validate(array $rows): array {
        $errors = [];
        $seen = [];
        foreach ($rows as $r) {
            $id = self::safe_name($r['id']);
            foreach (answer_rules::validate_rule($r) as $message) {
                $errors[] = $id . ': ' . $message;
            }
            if (isset($seen[$r['id']])) {
                $errors[] = get_string('answer_rules:err_id_duplicate', 'local_ai_course_assistant', $id);
            }
            $seen[$r['id']] = true;
        }
        return $errors;
    }

    /**
     * Lang key of the add button.
     *
     * @return string
     */
    protected function add_label_key(): string {
        return 'answer_rules:add_rule';
    }

    /**
     * The stored JSON has no key column, so the config log keeps it as written.
     *
     * @param mixed $raw
     * @return mixed
     */
    public static function redact_keys($raw) {
        return $raw;
    }
}
