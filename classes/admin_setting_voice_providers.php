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
 * Admin setting for the voice_providers table: openai or xai rows with a key, a label and default voices.
 *
 * Stored format (unchanged, read by voice_registry): provider|key|label|realtime_voice|tts_voice, with the
 * key encrypted. It shares the row editor with the Providers and keys table, so the page never carries a
 * key or ciphertext, a blank key keeps the stored one, and the config log shows the key column masked.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_voice_providers extends admin_setting_keyed_rows {

    /** @var string[] The only providers with WebSocket Realtime, TTS and STT endpoints today. */
    private const PROVIDERS = ['openai', 'xai'];

    /**
     * The columns after the key.
     *
     * @return array[]
     */
    protected function columns(): array {
        $options = [];
        foreach (self::PROVIDERS as $id) {
            $options[$id] = get_string('providerkeys:name_' . $id, 'local_ai_course_assistant');
        }
        return [
            ['name' => 'provider', 'label' => 'providerkeys:col_provider', 'type' => 'select', 'options' => $options],
            ['name' => 'label', 'label' => 'providerkeys:col_label', 'type' => 'text',
                'placeholder' => 'e.g. openai-prod'],
            ['name' => 'realtime_voice', 'label' => 'providerkeys:col_rtvoice', 'type' => 'text',
                'placeholder' => 'e.g. shimmer, eve'],
            ['name' => 'tts_voice', 'label' => 'providerkeys:col_ttsvoice', 'type' => 'text',
                'placeholder' => 'e.g. shimmer, eve'],
        ];
    }

    /**
     * Rows as stored (server side only). The key stays in its stored, encrypted form.
     *
     * @return array[]
     */
    protected function load_rows(): array {
        $rows = [];
        foreach (voice_registry::parse_rows(false) as $r) {
            $rows[] = [
                'id'             => provider_keys::rowid($r['provider'] . '|' . $r['label'], $r['apikey']),
                'stored_key'     => $r['apikey'],
                'provider'       => $r['provider'],
                'label'          => $r['label'],
                'realtime_voice' => $r['realtime_voice'],
                'tts_voice'      => $r['tts_voice'],
            ];
        }
        return $rows;
    }

    /**
     * Rows to the stored string. Trailing empty columns are left off, as the old form did, so a line it wrote
     * comes back unchanged from a save that touched nothing.
     *
     * @param array[] $rows
     * @return string
     */
    protected function store(array $rows): string {
        $lines = [];
        foreach ($rows as $r) {
            $lines[] = preg_replace('~\|+$~', '',
                $r['provider'] . '|' . $r['stored_key'] . '|' . $r['label'] . '|' . $r['realtime_voice'] . '|' . $r['tts_voice']);
        }
        return implode("\n", $lines);
    }

    /**
     * Lang key of the add button.
     *
     * @return string
     */
    protected function add_label_key(): string {
        return 'providerkeys:add_voice';
    }

    /**
     * Problems that stop a save. Messages repeat only a short provider id, never what was typed, because a
     * key pasted into the wrong field must not come back in the page.
     *
     * @param array[] $rows
     * @return string[]
     */
    protected function validate(array $rows): array {
        $errors = [];
        $plugin = 'local_ai_course_assistant';
        foreach ($rows as $r) {
            $p = $r['provider'];
            if (!in_array($p, self::PROVIDERS, true)) {
                $errors[] = get_string('providerkeys:err_voice_provider', $plugin, s(self::safe_name($p)));
                continue;
            }
            if ($r['stored_key'] === '') {
                $errors[] = get_string('providerkeys:err_needs_key', $plugin, $p);
            }
            // These go between pipes on one line, so a pipe or a line break would shift a column, and the key
            // column is one of them.
            foreach (['label' => 'providerkeys:col_label', 'realtime_voice' => 'providerkeys:col_rtvoice',
                    'tts_voice' => 'providerkeys:col_ttsvoice'] as $field => $label) {
                if (preg_match('~[|\r\n]~', $r[$field])) {
                    $errors[] = get_string('providerkeys:err_badchar', $plugin,
                        (object) ['field' => get_string($label, $plugin), 'provider' => $p]);
                }
            }
        }
        return $errors;
    }
}
