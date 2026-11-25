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
 * The LLM provider and model pickers in the chat composer (8.4.0).
 *
 * Hidden unless the site setting chat_show_model_picker is on, and then shown only to holders of the manage
 * capability in the course, as before. The same two conditions decide whether sse.php honors a provider or model
 * named in a chat request, so a crafted request cannot pick a model while the pickers are hidden.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class model_picker {

    /** @var string The site setting (checkbox, default off). */
    public const SETTING = 'chat_show_model_picker';

    /** @var string The capability a user needs in the course, besides the setting. */
    public const CAPABILITY = 'local/ai_course_assistant:manage';

    /**
     * Whether the site setting is on.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (bool) get_config('local_ai_course_assistant', self::SETTING);
    }

    /**
     * Whether the current user may use the pickers in this course: the setting is on and they hold the capability.
     *
     * @param int $courseid
     * @return bool
     */
    public static function allowed(int $courseid): bool {
        return self::enabled() && has_capability(self::CAPABILITY, \context_course::instance($courseid));
    }

    /**
     * The provider and model a chat request may override with: what the request named when the current user may
     * use the pickers in this course, otherwise two empty strings (the configured model answers).
     *
     * @param string $provider the request's provider, already cleaned
     * @param string $model the request's model, already cleaned
     * @param int $courseid
     * @return string[] [provider, model]
     */
    public static function request_override(string $provider, string $model, int $courseid): array {
        if ($provider === '' || !self::allowed($courseid)) {
            return ['', ''];
        }
        return [$provider, $model];
    }

    /**
     * The picker's options for the browser: the primary provider first, then the other chat rows of Providers and
     * keys. Call only when the pickers are shown.
     *
     * @return array{enabled: bool, providers: array[]}
     */
    public static function options(): array {
        $configprovider = get_config('local_ai_course_assistant', 'provider') ?: 'openai';
        $configmodel = get_config('local_ai_course_assistant', 'model') ?: '';
        $providers = [
            [
                'id' => $configprovider,
                'label' => ucfirst($configprovider) . ' (primary)',
                'models' => $configmodel
                    ? [['id' => $configmodel, 'label' => $configmodel, 'status' => 'active']]
                    : [],
            ],
        ];
        $seen = [$configprovider => true];
        foreach (provider_keys::rows() as $row) {
            if ($row['columns'] < 2 || in_array($row['provider'], provider_keys::KEY_ONLY_PROVIDERS, true)) {
                continue;
            }
            $pid = $row['provider'];
            if (!empty($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            $models = [];
            foreach (array_filter(array_map('trim', explode(',', $row['models']))) as $m) {
                $models[] = ['id' => $m, 'label' => $m, 'status' => 'active'];
            }
            $providers[] = [
                'id' => $pid,
                'label' => ucfirst($pid),
                'models' => $models,
            ];
        }
        return [
            'enabled' => true,
            'providers' => $providers,
        ];
    }
}
