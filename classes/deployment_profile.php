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
 * Apply-once deployment presets (v5.10.0).
 *
 * Bundles the existing knobs into two recommended baselines: a hosted
 * large-context default, and a self-hosted small-context profile sized for a
 * single-GPU backend (for example a 24GB card running vLLM with an 8K window).
 *
 * Apply-once semantics: selecting a preset writes the recommended values into
 * the normal individual settings, which remain editable afterward. There is no
 * runtime override layer, so the individual settings stay the single source of
 * truth.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class deployment_profile {

    /** Recommended values per profile. Each maps a config key to its value. */
    private const PRESETS = [
        'self_hosted_small' => [
            'backend_context_tokens' => '8192',
            'max_tokens'             => '768',
            'prompt_verbosity'       => 'standard',
            'socratic_verbose'       => '0',
            'maxhistory'             => '6',
            'rag_enabled'            => '1',
            'rag_topk'               => '4',
            'rag_chunksize'          => '300',
            'backend_retry_attempts' => '2',
        ],
        'hosted_large' => [
            'backend_context_tokens' => '0',
            'max_tokens'             => '1024',
            'prompt_verbosity'       => 'concise',
            'maxhistory'             => '20',
            'rag_topk'               => '5',
            'rag_chunksize'          => '400',
        ],
    ];

    /**
     * Apply a preset by writing its values into the plugin settings.
     *
     * @param string $profile one of {@see self::profiles()}
     * @throws \coding_exception on an unknown profile name
     */
    public static function apply(string $profile): void {
        if (!isset(self::PRESETS[$profile])) {
            throw new \coding_exception('Unknown deployment profile: ' . $profile);
        }
        foreach (self::PRESETS[$profile] as $key => $value) {
            set_config($key, $value, 'local_ai_course_assistant');
        }
        set_config('deployment_profile', $profile, 'local_ai_course_assistant');
    }

    /**
     * The values a preset would write, for display/preview.
     *
     * @param string $profile
     * @return array<string,string>
     */
    public static function values(string $profile): array {
        return self::PRESETS[$profile] ?? [];
    }

    /** @return string[] the known profile names */
    public static function profiles(): array {
        return array_keys(self::PRESETS);
    }
}
