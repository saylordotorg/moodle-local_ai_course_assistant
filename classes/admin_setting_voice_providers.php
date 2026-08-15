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
 * Admin setting widget for the voice_providers table.
 *
 * Renders a structured table so admins can define one or more voice API
 * providers (OpenAI, xAI, etc.) with an API key, a friendly label, and
 * default voices for Realtime and TTS. Stored in the same pipe-delimited
 * format voice_registry consumes:
 *   provider|apikey|label|realtime_voice|tts_voice
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_voice_providers extends \admin_setting {
    private static array $provider_options = [
        'openai' => 'OpenAI',
        'xai'    => 'xAI (Grok)',
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
     * Parse the stored config into structured rows for rendering.
     *
     * @return array
     */
    private function parse_rows(): array {
        return voice_registry::parse_rows();
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
        $html .= '<table class="table table-sm table-bordered" id="' . $id . '-table" style="max-width:960px">';
        $html .= '<thead><tr>'
            . '<th style="width:140px">Provider</th>'
            . '<th style="width:220px">API Key</th>'
            . '<th style="width:160px">Label</th>'
            . '<th style="width:140px">Realtime voice</th>'
            . '<th style="width:140px">TTS voice</th>'
            . '<th style="width:50px"></th>'
            . '</tr></thead>';
        $html .= '<tbody>';

        foreach ($rows as $i => $row) {
            $html .= $this->render_row($i, $row, $provideroptions);
        }

        $html .= '</tbody></table>';
        $html .= '<button type="button" class="btn btn-sm btn-outline-primary" id="' . $id . '-add">+ Add Voice Provider</button>';
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
        return '<tr data-row="' . $index . '">'
            . '<td><select class="form-control form-control-sm sola-vp-provider">' . $opts . '</select></td>'
            . '<td><input type="password" class="form-control form-control-sm sola-vp-key" '
            . 'value="' . s($row['apikey']) . '" placeholder="Paste API key" autocomplete="off"></td>'
            . '<td><input type="text" class="form-control form-control-sm sola-vp-label" '
            . 'value="' . s($row['label']) . '" placeholder="e.g. openai-prod"></td>'
            . '<td><input type="text" class="form-control form-control-sm sola-vp-rtvoice" '
            . 'value="' . s($row['realtime_voice']) . '" placeholder="e.g. shimmer, eve"></td>'
            . '<td><input type="text" class="form-control form-control-sm sola-vp-ttsvoice" '
            . 'value="' . s($row['tts_voice']) . '" placeholder="e.g. shimmer, eve"></td>'
            . '<td><button type="button" class="btn btn-sm btn-outline-danger sola-vp-remove" title="Remove">&times;</button></td>'
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
            var p = tr.querySelector('.sola-vp-provider').value;
            var k = tr.querySelector('.sola-vp-key').value;
            var lbl = tr.querySelector('.sola-vp-label').value;
            var rv = tr.querySelector('.sola-vp-rtvoice').value;
            var tv = tr.querySelector('.sola-vp-ttsvoice').value;
            if (p && k) {
                lines.push([p, k, lbl, rv, tv].join('|').replace(/\\|+\$/,''));
            }
        });
        hiddenInput.value = lines.join('\\n');
    }

    function addRow() {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><select class="form-control form-control-sm sola-vp-provider">' + optsHtml + '</select></td>'
            + '<td><input type="password" class="form-control form-control-sm sola-vp-key" placeholder="Paste API key" autocomplete="off"></td>'
            + '<td><input type="text" class="form-control form-control-sm sola-vp-label" placeholder="e.g. openai-prod"></td>'
            + '<td><input type="text" class="form-control form-control-sm sola-vp-rtvoice" placeholder="e.g. shimmer, eve"></td>'
            + '<td><input type="text" class="form-control form-control-sm sola-vp-ttsvoice" placeholder="e.g. shimmer, eve"></td>'
            + '<td><button type="button" class="btn btn-sm btn-outline-danger sola-vp-remove" title="Remove">&times;</button></td>';
        tbody.appendChild(tr);
        bindRow(tr);
        serialize();
    }

    function bindRow(tr) {
        tr.querySelector('.sola-vp-remove').addEventListener('click', function() { tr.remove(); serialize(); });
        ['.sola-vp-provider', '.sola-vp-key', '.sola-vp-label', '.sola-vp-rtvoice', '.sola-vp-ttsvoice'].forEach(function(sel) {
            var el = tr.querySelector(sel);
            if (el) { el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', serialize); }
        });
    }

    tbody.querySelectorAll('tr').forEach(bindRow);
    addBtn.addEventListener('click', addRow);
    var form = wrap.closest('form');
    if (form) { form.addEventListener('submit', serialize); }
})();
</script>
JS;
    }
}
