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

/**
 * Manual "Refresh now" trigger for the LLM rate card.
 *
 * Admin-only. Calls {@see rate_card_refresher::refresh()} and redirects
 * back to the plugin settings page with a success or error notification
 * so admins do not have to wait for the weekly cron to pick up a
 * pricing change.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);
require_sesskey();

$result = \local_ai_course_assistant\rate_card_refresher::refresh();

$settingsurl = new moodle_url('/admin/category.php', ['category' => 'local_ai_course_assistant']);
if ($result['ok']) {
    redirect(
        $settingsurl,
        get_string('settings:rate_card_refresh_success', 'local_ai_course_assistant', $result['count']),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
} else {
    redirect(
        $settingsurl,
        get_string('settings:rate_card_refresh_error', 'local_ai_course_assistant', s($result['error'])),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}
