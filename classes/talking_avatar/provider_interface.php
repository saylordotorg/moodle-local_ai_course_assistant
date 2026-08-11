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
 * Common contract for SOLA talking-avatar provider drivers.
 *
 * v4.9.0 — supports four cost-effective vendors selectable from the
 * plugin's Talking Avatar settings: D-ID, HeyGen, Tavus, and Synthesia
 * Agents. Each driver hides the vendor-specific session-creation handshake
 * behind a single {@see start_session()} call that returns the surface the
 * widget renders in an iframe.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface provider_interface {
    /**
     * Provider key as stored in plugin config.
     *
     * @return string One of 'did', 'heygen', 'tavus', 'synthesia'.
     */
    public function get_key(): string;

    /**
     * Whether the provider has the minimum config required to start a session.
     * Drivers should at minimum require an API key and a persona/avatar id.
     *
     * @return bool
     */
    public function is_configured(): bool;

    /**
     * Open a real-time avatar session for the given user/course context.
     *
     * @param array $context {
     * @type int    courseid Optional Moodle course id.
     * @type int    userid   Moodle user id (always set by the endpoint).
     * @type string lang     Two-letter ISO 639-1 language code.
     * @type string greeting Optional initial line for the avatar to speak.
     * }
     * @return array {
     * @type string embed_url     URL the widget loads inside an iframe.
     * @type string session_token Short-lived token if the URL needs one.
     * @type string provider      Provider key (echoed for the frontend).
     * @type int    expires_in    Lifetime hint in seconds (0 if unknown).
     * }
     * @throws \moodle_exception When the upstream API rejects the request,
     *                           returns an unsafe URL, or the driver is not
     *                           configured.
     */
    public function start_session(array $context): array;
}
