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
 * Admin page — apply-once deployment presets (v5.10.0).
 *
 * Writes a recommended bundle of settings for either a hosted large-context
 * deployment or a self-hosted small-context backend. The written values remain
 * individually editable in the normal plugin settings.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);

$apply = optional_param('apply', '', PARAM_ALPHANUMEXT);

$pageurl = new moodle_url('/local/ai_course_assistant/deployment_profile.php');
$PAGE->set_url($pageurl);
$PAGE->set_context($syscontext);
$PAGE->set_title(get_string('profile:title', 'local_ai_course_assistant'));
$PAGE->set_heading(get_string('profile:title', 'local_ai_course_assistant'));
$PAGE->set_pagelayout('admin');

if ($apply !== '' && confirm_sesskey()) {
    if (!in_array($apply, \local_ai_course_assistant\deployment_profile::profiles(), true)) {
        redirect($pageurl, get_string('profile:unknown', 'local_ai_course_assistant'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
    \local_ai_course_assistant\deployment_profile::apply($apply);
    redirect($pageurl, get_string('profile:applied', 'local_ai_course_assistant', $apply), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('profile:title', 'local_ai_course_assistant'));
echo html_writer::tag('p', get_string('profile:intro', 'local_ai_course_assistant'));

$current = (string) get_config('local_ai_course_assistant', 'deployment_profile');
if ($current !== '') {
    echo html_writer::tag('p',
        get_string('profile:current', 'local_ai_course_assistant', s($current)),
        ['class' => 'alert alert-info']);
}

foreach (\local_ai_course_assistant\deployment_profile::profiles() as $profile) {
    echo $OUTPUT->heading(get_string('profile:' . $profile, 'local_ai_course_assistant'), 4);

    // Show the values the preset would write so there is no hidden behaviour.
    $table = new html_table();
    $table->head = [
        get_string('profile:setting', 'local_ai_course_assistant'),
        get_string('profile:value', 'local_ai_course_assistant'),
    ];
    foreach (\local_ai_course_assistant\deployment_profile::values($profile) as $key => $value) {
        $table->data[] = [s($key), s($value)];
    }
    echo html_writer::table($table);

    echo $OUTPUT->single_button(
        new moodle_url($pageurl, ['apply' => $profile, 'sesskey' => sesskey()]),
        get_string('profile:apply_' . $profile, 'local_ai_course_assistant'),
        'post'
    );
}

echo $OUTPUT->footer();
