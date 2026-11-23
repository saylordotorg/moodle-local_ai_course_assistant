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

namespace local_ai_course_assistant\voice;

defined('MOODLE_INTERNAL') || die();

/**
 * How long a voice mode keeps listening when nobody speaks (8.3.1).
 *
 * Every voice mode stops listening after a stretch of silence so an open microphone does not run up the bill
 * (the live modes are billed for as long as a session is open) and the learner taps Resume to carry on:
 *   - Gemini Live: gemini_live::idle_seconds() (default 5 seconds);
 *   - every other voice mode, meaning OpenAI and xAI realtime and the regular turn-based mic: this class
 *     (default 10 seconds).
 *
 * The browser enforces both. A live session talks to the provider directly, so the server cannot cut one off for
 * silence; what it holds regardless of the browser is the session cap and the daily allowance (gemini_live).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class voice_idle {
    /** @var int Default silence limit in seconds for every voice mode except Gemini Live. */
    public const DEFAULT_SECONDS = 10;

    /** @var int[] Bounds of the setting in seconds, also applied when reading it. */
    public const RANGE = [5, 600];

    /** @var int The Gemini Live default before 8.3.1. A site that still has exactly this value never chose it. */
    public const OLD_GEMINI_LIVE_DEFAULT = 90;

    /**
     * The silence limit in seconds for the regular and OpenAI/xAI realtime voice modes.
     *
     * @return int
     */
    public static function seconds(): int {
        $raw = get_config('local_ai_course_assistant', 'voice_idle_seconds');
        $value = ($raw === false || trim((string) $raw) === '' || !is_numeric($raw)) ? self::DEFAULT_SECONDS : (int) $raw;
        return max(self::RANGE[0], min(self::RANGE[1], $value));
    }

    /**
     * Upgrade to 8.3.1: a site whose Gemini Live silence limit is exactly the old default of 90 seconds moves to
     * the new default. Moodle writes a setting's default into the database when the settings page is first
     * saved, so 90 almost always means "never changed" rather than "chosen"; any other value is a choice and is
     * kept. An admin who really wants 90 sets it again.
     *
     * @return bool True when the value was changed.
     */
    public static function migrate_old_gemini_live_default(): bool {
        $current = get_config('local_ai_course_assistant', 'gemini_live_idle_seconds');
        if ($current === false || trim((string) $current) !== (string) self::OLD_GEMINI_LIVE_DEFAULT) {
            return false;
        }
        set_config('gemini_live_idle_seconds', gemini_live::DEFAULT_IDLE_SECONDS, 'local_ai_course_assistant');
        return true;
    }
}
