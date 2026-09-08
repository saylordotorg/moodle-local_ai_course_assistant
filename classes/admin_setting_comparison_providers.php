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
 * Custom admin setting for managing LLM comparison providers.
 *
 * Renders a structured table with provider dropdown, masked API key,
 * model list, and add/remove buttons. Stores data in the same
 * pipe-delimited format the parsers already consume.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_comparison_providers extends \admin_setting {
    private static array $provider_options = [
        'openai'    => 'OpenAI',
        'claude'    => 'Claude (Anthropic)',
        'deepseek'  => 'DeepSeek',
        'gemini'    => 'Google Gemini',
        'ollama'    => 'Ollama (Local)',
        'minimax'   => 'MiniMax',
        'mistral'   => 'Mistral AI',
        'openrouter' => 'OpenRouter',
        'together'  => 'Together AI',
        'xai'       => 'xAI (Grok)',
        'coreai'    => 'Moodle AI (core_ai)',
        'custom'    => 'Custom (OpenAI-compatible)',
    ];

    public function __construct(string $name, string $visiblename, string $description) {
        parent::__construct($name, $visiblename, $description, '');
    }

    public function get_setting() {
        return $this->config_read($this->name);
    }

    public function write_setting($data) {
        if (!is_string($data)) {
            return '';
        }
        $this->config_write($this->name, $data);
        return '';
    }

    /**
     * Parse the stored pipe-delimited config into structured rows.
     *
     * v5.5.2: added optional `apibaseurl` field as the 5th column. Blank means
     * "use the provider class's hardcoded default base URL." Backward compatible
     * with existing 4-field rows.
     *
     * @return array Array of ['provider' => string, 'apikey' => string, 'models' => string, 'temperature' => string, 'apibaseurl' => string]
     */
    private function parse_rows(): array {
        $raw = $this->get_setting() ?: '';
        $rows = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $rows[] = [
                'provider'    => strtolower($parts[0] ?? ''),
                'apikey'      => $parts[1] ?? '',
                'models'      => $parts[2] ?? '',
                'temperature' => $parts[3] ?? '',
                'apibaseurl'  => $parts[4] ?? '',
            ];
        }
        return $rows;
    }

    public function output_html($data, $query = ''): string {
        $rows = $this->parse_rows();
        $id = $this->get_id();
        $fullname = $this->get_full_name();

        $provideroptions = '';
        foreach (self::$provider_options as $val => $label) {
            $provideroptions .= '<option value="' . s($val) . '">' . s($label) . '</option>';
        }

        $html = '<div id="' . $id . '-wrap">';
        $html .= '<table class="table table-sm table-bordered" id="' . $id . '-table" style="max-width:900px">';
        $html .= '<thead><tr>'
            . '<th style="width:140px">Provider</th>'
            . '<th style="width:200px">API Key</th>'
            . '<th>Models (comma-separated)</th>'
            . '<th style="width:80px" title="Leave blank to use global default">Temp.</th>'
            . '<th style="width:200px" title="Leave blank to use the provider class default">Base URL</th>'
            . '<th style="width:50px"></th>'
            . '</tr></thead>';
        $html .= '<tbody>';

        foreach ($rows as $i => $row) {
            $html .= $this->render_row($i, $row, $provideroptions);
        }

        $html .= '</tbody></table>';
        $html .= '<button type="button" class="btn btn-sm btn-outline-primary" id="' . $id . '-add">+ Add Provider</button>';
        $html .= '<input type="hidden" name="' . s($fullname) . '" id="' . $id . '-value" value="' . s($data) . '">';
        $html .= '</div>';

        $html .= $this->render_js($id, $provideroptions);

        return format_admin_setting($this, $this->visiblename, $html, $this->description, true, '', null, $query);
    }

    private function render_row(int $index, array $row, string $provideroptions): string {
        $selected = s($row['provider']);
        $opts = str_replace(
            'value="' . $selected . '"',
            'value="' . $selected . '" selected',
            $provideroptions
        );
        $masked = !empty($row['apikey']) ? str_repeat('*', min(8, strlen($row['apikey']))) . substr($row['apikey'], -4) : '';

        return '<tr data-row="' . $index . '">'
            . '<td><select class="form-control form-control-sm sola-cp-provider">' . $opts . '</select></td>'
            . '<td>'
            . '<input type="password" class="form-control form-control-sm sola-cp-key" '
            . 'value="' . s($row['apikey']) . '" placeholder="Paste API key" autocomplete="off">'
            . '<small class="text-muted sola-cp-key-hint" style="font-size:11px">' . s($masked) . '</small>'
            . '</td>'
            . '<td><input type="text" class="form-control form-control-sm sola-cp-models" '
            . 'value="' . s($row['models']) . '" placeholder="model-name-1, model-name-2"></td>'
            . '<td><input type="number" step="0.1" min="0" max="2" class="form-control form-control-sm sola-cp-temp" '
            . 'value="' . s($row['temperature']) . '" placeholder="0.7"></td>'
            . '<td><input type="text" class="form-control form-control-sm sola-cp-baseurl" '
            . 'value="' . s($row['apibaseurl']) . '" placeholder="https://... (optional)"></td>'
            . '<td><button type="button" class="btn btn-sm btn-outline-danger sola-cp-remove" title="Remove">&times;</button></td>'
            . '</tr>';
    }

    private function render_js(string $id, string $provideroptions): string {
        $optsjs = json_encode($provideroptions);
        return <<<JS
<script>
(function() {
    var wrap = document.getElementById('{$id}-wrap');
    var tbody = document.querySelector('#{$id}-table tbody');
    var addBtn = document.getElementById('{$id}-add');
    var hiddenInput = document.getElementById('{$id}-value');
    var optsHtml = {$optsjs};

    function serialize() {
        var lines = [];
        tbody.querySelectorAll('tr').forEach(function(tr) {
            var p = tr.querySelector('.sola-cp-provider').value;
            var k = tr.querySelector('.sola-cp-key').value;
            var m = tr.querySelector('.sola-cp-models').value;
            var t = tr.querySelector('.sola-cp-temp').value.trim();
            var b = tr.querySelector('.sola-cp-baseurl').value.trim();
            if (p && k) {
                // Emit the minimum number of fields needed. The base URL is
                // 5th; if it is set but temperature is not, write an empty
                // 4th slot so positions line up for the parser.
                var line = p + '|' + k + '|' + m;
                if (t !== '' || b !== '') {
                    line += '|' + t;
                }
                if (b !== '') {
                    line += '|' + b;
                }
                lines.push(line);
            }
        });
        hiddenInput.value = lines.join('\\n');
    }

    function addRow() {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><select class="form-control form-control-sm sola-cp-provider">' + optsHtml + '</select></td>'
            + '<td><input type="password" class="form-control form-control-sm sola-cp-key" placeholder="Paste API key" autocomplete="off">'
            + '<small class="text-muted sola-cp-key-hint" style="font-size:11px"></small></td>'
            + '<td><input type="text" class="form-control form-control-sm sola-cp-models" placeholder="model-name-1, model-name-2"></td>'
            + '<td><input type="number" step="0.1" min="0" max="2" class="form-control form-control-sm sola-cp-temp" placeholder="0.7"></td>'
            + '<td><input type="text" class="form-control form-control-sm sola-cp-baseurl" placeholder="https://... (optional)"></td>'
            + '<td><button type="button" class="btn btn-sm btn-outline-danger sola-cp-remove" title="Remove">&times;</button></td>';
        tbody.appendChild(tr);
        bindRow(tr);
        serialize();
    }

    function bindRow(tr) {
        tr.querySelector('.sola-cp-remove').addEventListener('click', function() {
            tr.remove();
            serialize();
        });
        tr.querySelector('.sola-cp-provider').addEventListener('change', serialize);
        tr.querySelector('.sola-cp-key').addEventListener('input', serialize);
        tr.querySelector('.sola-cp-models').addEventListener('input', serialize);
        tr.querySelector('.sola-cp-temp').addEventListener('input', serialize);
        tr.querySelector('.sola-cp-baseurl').addEventListener('input', serialize);
    }

    tbody.querySelectorAll('tr').forEach(bindRow);
    addBtn.addEventListener('click', addRow);

    // Serialize on form submit to capture any last-second edits.
    var form = wrap.closest('form');
    if (form) {
        form.addEventListener('submit', serialize);
    }
})();
</script>
JS;
    }

    /**
     * Keep the API keys out of the configuration change log.
     *
     * config_log records the full old and new value of every setting change.
     * This field is pipe-delimited with the API key in the second position, so
     * every edit was writing provider credentials to mdl_config_log in plain
     * text, where they persist independently of the setting itself and are
     * visible to anyone who can read that table. The UI already masks the key
     * on redisplay; the log did not.
     *
     * Only the key is redacted. Masking the whole value the way core's password
     * setting does would also hide which provider, model or base URL changed,
     * which is the part of the row an audit trail is for.
     *
     * @param string $name
     * @param mixed $oldvalue
     * @param mixed $value
     * @return void
     */
    protected function add_to_config_log($name, $oldvalue, $value) {
        parent::add_to_config_log($name, self::redact_keys($oldvalue), self::redact_keys($value));
    }

    /**
     * Replace the API key in each pipe-delimited row with a fixed placeholder.
     *
     * @param mixed $raw
     * @return mixed The redacted value, or the input unchanged when not a string.
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
