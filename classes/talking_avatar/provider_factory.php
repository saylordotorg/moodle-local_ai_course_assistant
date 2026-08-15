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

namespace local_ai_course_assistant\talking_avatar;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolve the configured talking-avatar driver.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_factory {
    /** @var array<string, string> Provider key → driver class. */
    private const DRIVERS = [
        'did'       => did_provider::class,
        'heygen'    => heygen_provider::class,
        'tavus'     => tavus_provider::class,
        'synthesia' => synthesia_provider::class,
    ];

    /**
     * Return the configured provider key (or '' if not selected).
     *
     * @return string
     */
    public static function configured_key(): string {
        return (string) (get_config('local_ai_course_assistant', 'talking_avatar_provider') ?: '');
    }

    /**
     * Build the driver for the given key, or for the configured key when null.
     *
     * @param string|null $key
     * @return provider_interface|null Null when no driver matches.
     */
    public static function make(?string $key = null): ?provider_interface {
        $key = $key ?? self::configured_key();
        if (!isset(self::DRIVERS[$key])) {
            return null;
        }
        $class = self::DRIVERS[$key];
        return new $class();
    }

    /**
     * Provider keys, for use in admin selectors.
     *
     * @return string[]
     */
    public static function known_keys(): array {
        return array_keys(self::DRIVERS);
    }
}
