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
 * Base for settings that are a table of rows, one of whose columns is a secret key.
 *
 * The rule this class exists to keep: no key and no ciphertext is ever written into the page. A saved
 * key shows as "Saved, ends in ab12" with a Change button. The form posts plain arrays (never one
 * string carrying every key), a blank key keeps the stored one through an opaque row id, and a typed
 * key is encrypted on the server.
 *
 * Posted shape: {fullname}[rows][<n>][provider|newkey|keepid|<other columns>], plus {fullname}[present]
 * so the setting is still posted after the last row is removed in the browser.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class admin_setting_keyed_rows extends \admin_setting {

    /**
     * Columns other than the key. The FIRST column identifies the row (the provider).
     *
     * @return array[] each: name, label (lang key), type text|number|select, options (value => label), placeholder
     */
    abstract protected function columns(): array;

    /**
     * Rows as stored, for server-side use only.
     *
     * @return array[] each: id, stored_key, and one value per column name
     */
    abstract protected function load_rows(): array;

    /**
     * Turn rows (each with stored_key and one value per column) into the stored string.
     *
     * @param array[] $rows
     * @return string
     */
    abstract protected function store(array $rows): string;

    /**
     * Problems that stop a save.
     *
     * @param array[] $rows
     * @return string[] messages; empty means fine
     */
    abstract protected function validate(array $rows): array;

    /**
     * Lang key for the add button.
     *
     * @return string
     */
    abstract protected function add_label_key(): string;

    /**
     * Create the setting.
     *
     * @param string $name
     * @param string $visiblename
     * @param string $description
     */
    public function __construct(string $name, string $visiblename, string $description) {
        parent::__construct($name, $visiblename, $description, '');
    }

    /**
     * The stored value, for Moodle's own bookkeeping (change detection, search). Never rendered.
     *
     * @return mixed
     */
    public function get_setting() {
        return $this->config_read($this->name);
    }

    /**
     * Save the posted rows.
     *
     * @param mixed $data array with 'rows' (and the 'present' marker) from the form
     * @return string '' when saved, otherwise the problems
     */
    public function write_setting($data) {
        if (!is_array($data)) {
            // Not from this form. Moodle passes the empty default when it installs a setting that was never
            // saved; anything else (an old string format) must not wipe a saved table.
            if ($this->get_setting() === null) {
                $this->config_write($this->name, '');
            }
            return '';
        }
        $posted = (isset($data['rows']) && is_array($data['rows'])) ? $data['rows'] : [];
        $existing = [];
        foreach ($this->load_rows() as $row) {
            $existing[$row['id']] = $row;
        }
        $columns = $this->columns();
        $first = $columns[0]['name'];
        $rows = [];
        $keyerrors = [];
        foreach ($posted as $p) {
            if (!is_array($p)) {
                continue;
            }
            $row = [];
            foreach ($columns as $column) {
                $row[$column['name']] = trim((string) ($p[$column['name']] ?? ''));
            }
            $row[$first] = strtolower($row[$first]);
            if ($row[$first] === '') {
                continue;
            }
            $newkey = trim((string) ($p['newkey'] ?? ''));
            $keepid = (string) ($p['keepid'] ?? '');
            if ($newkey !== '' && preg_match('~[|\r\n]~', $newkey)) {
                // The stored line is pipe-delimited, and secrets::conceal() passes a value that already starts
                // with a ciphertext prefix straight through, so a typed "sodium:x|y" would skip both the
                // encryption and the column check. A real key never holds these characters. The message names
                // the column and a short provider id, never what was typed.
                $keyerrors[] = get_string('providerkeys:err_badchar', 'local_ai_course_assistant', (object) [
                    'field' => get_string('providerkeys:col_key', 'local_ai_course_assistant'),
                    'provider' => self::safe_name($row[$first]),
                ]);
                continue;
            }
            if ($newkey !== '') {
                $row['stored_key'] = secrets::conceal($newkey);
            } else if ($keepid !== '' && isset($existing[$keepid]) && $existing[$keepid][$first] === $row[$first]) {
                // Only the same row (same identifying column) keeps its key. If the admin switched the row to
                // another provider and typed no key, the old key doesn't follow it: the row needs a new one.
                $row['stored_key'] = $existing[$keepid]['stored_key'];
            } else {
                $row['stored_key'] = '';
            }
            $rows[] = $row;
        }
        $errors = array_merge($keyerrors, $this->validate($rows));
        if ($errors) {
            return implode(' ', $errors) . ' ' . get_string('providerkeys:err_resubmit', 'local_ai_course_assistant');
        }
        $this->config_write($this->name, $this->store($rows));
        return '';
    }

    /**
     * Render the table from what is stored.
     *
     * @param mixed $data IGNORED ON PURPOSE. After a failed save Moodle passes the submitted values here;
     *                    a key typed into the form must never be echoed back, so this renders from storage only.
     * @param string $query
     * @return string
     */
    public function output_html($data, $query = ''): string {
        global $OUTPUT, $PAGE;
        $id = $this->get_id();
        $columns = $this->columns();
        $rows = [];
        foreach ($this->load_rows() as $i => $row) {
            $rows[] = $this->row_context((string) $i, $row, $columns);
        }
        $blank = ['id' => '', 'stored_key' => ''];
        foreach ($columns as $column) {
            $blank[$column['name']] = '';
        }
        $heads = [];
        foreach ($columns as $i => $column) {
            $heads[] = ['label' => get_string($column['label'], 'local_ai_course_assistant'), 'iskey' => false];
            if ($i === 0) {
                $heads[] = ['label' => get_string('providerkeys:col_key', 'local_ai_course_assistant'), 'iskey' => true];
            }
        }
        $html = $OUTPUT->render_from_template('local_ai_course_assistant/admin_keyed_rows', [
            'id' => $id,
            'presentname' => $this->get_full_name() . '[present]',
            'heads' => $heads,
            'rows' => $rows,
            'blankrow' => $this->row_context('__IDX__', $blank, $columns),
            'addlabel' => get_string($this->add_label_key(), 'local_ai_course_assistant'),
        ]);
        $PAGE->requires->js_call_amd('local_ai_course_assistant/keyed_rows', 'init', [$id]);
        return format_admin_setting($this, $this->visiblename, $html, $this->description, true, '', null, $query);
    }

    /**
     * How a stored value is shown in its input. Override to keep part of a value off the page.
     *
     * @param string $column column name
     * @param string $value the stored value
     * @return string
     */
    protected function display_value(string $column, string $value): string {
        return $value;
    }

    /**
     * A value that is safe to repeat on the page or in an error: a short identifier or nothing of it.
     *
     * A row's first column is meant to be a provider id. If it holds anything else (a key pasted in the
     * wrong place, a hand-edited line) the page says "unrecognized" instead of printing it.
     *
     * @param string $value
     * @return string
     */
    protected static function safe_name(string $value): string {
        return preg_match('~^[a-z0-9][a-z0-9._-]{0,31}$~i', $value) ? $value : 'unrecognized';
    }

    /**
     * Template context for one row. Contains no key and no ciphertext, only the saved flag and last four.
     *
     * @param string $idx row index in the posted array
     * @param array $row
     * @param array[] $columns
     * @return array
     */
    private function row_context(string $idx, array $row, array $columns): array {
        $fullname = $this->get_full_name();
        $haskey = $row['stored_key'] !== '';
        $last4 = $haskey ? provider_keys::last4(secrets::reveal($row['stored_key'])) : '';
        $cells = [];
        foreach ($columns as $i => $column) {
            $current = (string) $row[$column['name']];
            $cell = [
                'name' => $column['name'],
                'inputname' => $fullname . '[rows][' . $idx . '][' . $column['name'] . ']',
                'label' => get_string($column['label'], 'local_ai_course_assistant'),
                'value' => $this->display_value($column['name'], $current),
                'isselect' => $column['type'] === 'select',
                'isnumber' => $column['type'] === 'number',
                'istext' => $column['type'] === 'text',
                'placeholder' => $column['placeholder'] ?? '',
                'options' => [],
                'iskeynext' => $i === 0,
            ];
            if ($cell['isselect']) {
                $found = false;
                foreach ($column['options'] as $value => $label) {
                    $selected = (string) $value === $current;
                    $found = $found || $selected;
                    $cell['options'][] = ['value' => $value, 'label' => $label, 'selected' => $selected];
                }
                if (!$found && $current !== '') {
                    // A saved value the list doesn't offer. Without its own option the select would show its
                    // first entry, and a save would quietly move this row (and its key) to that provider.
                    $shown = self::safe_name($current);
                    $cell['options'][] = ['value' => $shown, 'label' => $shown, 'selected' => true];
                }
            }
            $cells[] = $cell;
        }
        return [
            'idx' => $idx,
            'rowid' => $row['id'],
            'haskey' => $haskey,
            'savedlabel' => $last4 !== ''
                ? get_string('providerkeys:saved_ends_in', 'local_ai_course_assistant', $last4)
                : get_string('providerkeys:saved', 'local_ai_course_assistant'),
            'keyinput' => $fullname . '[rows][' . $idx . '][newkey]',
            'keylabel' => get_string('providerkeys:col_key', 'local_ai_course_assistant'),
            'keepinput' => $fullname . '[rows][' . $idx . '][keepid]',
            'cells' => $cells,
            'changelabel' => get_string('providerkeys:change', 'local_ai_course_assistant'),
            'newkeyplaceholder' => get_string('providerkeys:newkey_placeholder', 'local_ai_course_assistant'),
            'removelabel' => get_string('providerkeys:remove', 'local_ai_course_assistant'),
        ];
    }

    /**
     * Keep keys out of the configuration change log: the key column becomes a fixed placeholder.
     *
     * @param string $name
     * @param mixed $oldvalue
     * @param mixed $value
     * @return void
     */
    protected function add_to_config_log($name, $oldvalue, $value) {
        parent::add_to_config_log($name, static::redact_keys($oldvalue), static::redact_keys($value));
    }

    /**
     * Replace the key column of every row with a placeholder. Which provider or model changed stays visible.
     *
     * @param mixed $raw
     * @return mixed
     */
    public static function redact_keys($raw) {
        if (!is_string($raw) || $raw === '') {
            return $raw;
        }
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            if (trim($line) === '') {
                $out[] = $line;
                continue;
            }
            $parts = explode('|', $line);
            if (isset($parts[1]) && trim($parts[1]) !== '') {
                $parts[1] = '********';
            }
            $out[] = implode('|', $parts);
        }
        return implode("\n", $out);
    }
}
