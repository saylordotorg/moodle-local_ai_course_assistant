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
 * Admin page for managing conversation starter chips.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', \context_system::instance());

use local_ai_course_assistant\starter_manager;

$PAGE->set_context(\context_system::instance());
$PAGE->set_url(new moodle_url('/local/ai_course_assistant/starter_settings.php'));
$PAGE->set_title(get_string('starters:admin_title', 'local_ai_course_assistant'));
$PAGE->set_heading(get_string('starters:admin_title', 'local_ai_course_assistant'));
$PAGE->set_pagelayout('admin');

// Handle POST actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $action = required_param('action', PARAM_ALPHA);

    if ($action === 'save') {
        // PARAM_RAW is required to receive the JSON envelope intact; every
        // decoded field is then strictly cleaned (clean_param per key, icon and
        // type allowlisted) inside starter_manager::save_global_starters().
        $raw = required_param('starters_json', PARAM_RAW);
        $starters = json_decode($raw, true);
        if (is_array($starters)) {
            starter_manager::save_global_starters($starters);
            \core\notification::success(get_string('starters:saved', 'local_ai_course_assistant'));
        }
    } else if ($action === 'reset') {
        starter_manager::reset_to_defaults();
        \core\notification::success(get_string('starters:reset_done', 'local_ai_course_assistant'));
    }

    redirect($PAGE->url);
}

// Load current starters.
$starters = starter_manager::get_global_starters();
$iconkeys = starter_manager::get_icon_keys();
$icons = [];
$iconlabels = [];
$sm = get_string_manager();
foreach ($iconkeys as $k) {
    $icons[$k] = starter_manager::get_icon_svg($k);
    // One tooltip per icon key. A key with no label yet falls back to the raw
    // key in the browser, which is what the page did before the extraction.
    if ($sm->string_exists('starters:icon_' . $k, 'local_ai_course_assistant')) {
        $iconlabels[$k] = get_string('starters:icon_' . $k, 'local_ai_course_assistant');
    }
}

// Labels for the starter-card editor that amd/src/starter_admin.js builds in
// the browser. Resolved here so they are translatable (I18N001); the script
// reads STR.<key>.
$jsstrkeys = [
    'drag_handle', 'on', 'name', 'name_aria', 'name_placeholder',
    'description', 'desc_aria', 'desc_placeholder', 'desc_help',
    'prompt', 'prompt_aria', 'prompt_placeholder', 'prompt_help',
    'icon', 'conditional', 'cond_always', 'cond_tts', 'cond_realtime',
    'delete', 'builtin_note', 'confirm_delete', 'new_name',
];
$jsstrings = [];
foreach ($jsstrkeys as $jsk) {
    $jsstrings[$jsk] = get_string('starters:js_' . $jsk, 'local_ai_course_assistant');
}

// CONTRIB-10574 #201: page body moved from PHP/HTML alternation to
// templates/starter_settings.mustache. Every string is resolved here — the
// template carries no str helpers. The howto.* strings ship inline markup
// (strong/code/em) in the lang pack and the old page echoed them raw, so the
// template renders them unescaped; sesskey, URLs, and labels stay escaped.
$templatedata = [
    'intro' => get_string('starters:admin_desc', 'local_ai_course_assistant'),
    'sesskey' => sesskey(),
    'backurl' => (new moodle_url('/admin/category.php', ['category' => 'local_ai_course_assistant']))->out(false),
    'howto' => [
        'heading' => get_string('starters:howto_heading', 'local_ai_course_assistant'),
        'builtin' => get_string('starters:howto_builtin', 'local_ai_course_assistant'),
        'custom' => get_string(
            'starters:howto_custom',
            'local_ai_course_assistant',
            get_string('starters:add_new', 'local_ai_course_assistant')
        ),
        'types' => get_string('starters:howto_types', 'local_ai_course_assistant'),
        'typeprompt' => get_string('starters:howto_type_prompt', 'local_ai_course_assistant'),
        'typequiz' => get_string('starters:howto_type_quiz', 'local_ai_course_assistant'),
        'typevoice' => get_string('starters:howto_type_voice', 'local_ai_course_assistant'),
        'typepronunciation' => get_string('starters:howto_type_pronunciation', 'local_ai_course_assistant'),
        'badgequiz' => get_string('starters:badge_quiz', 'local_ai_course_assistant'),
        'badgevoice' => get_string('starters:badge_voice', 'local_ai_course_assistant'),
        'badgepronunciation' => get_string('starters:badge_pronunciation', 'local_ai_course_assistant'),
        'conditional' => get_string('starters:howto_conditional', 'local_ai_course_assistant'),
        'placeholders' => get_string('starters:howto_placeholders', 'local_ai_course_assistant'),
        'reorder' => get_string('starters:howto_reorder', 'local_ai_course_assistant'),
        'overrides' => get_string('starters:howto_overrides', 'local_ai_course_assistant'),
    ],
    'labels' => [
        'save' => get_string('starters:save', 'local_ai_course_assistant'),
        'resetdefaults' => get_string('starters:reset_defaults', 'local_ai_course_assistant'),
        'back' => get_string('starters:back_settings', 'local_ai_course_assistant'),
        'addnew' => get_string('starters:add_new', 'local_ai_course_assistant'),
    ],
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/starter_settings', $templatedata);

// v7.2.0 (CONTRIB-10574 #201): the 229-line inline <script> that used to sit
// here is now amd/src/starter_admin.js, initialised via js_call_amd with the
// data that was previously echoed into the page as JSON literals. The
// resetconfirm string replaces the inline onsubmit confirm the reset forms
// carried before the template conversion; starter_admin.js binds it.
$PAGE->requires->js_call_amd('local_ai_course_assistant/starter_admin', 'init', [[
    'icons' => $icons,
    'iconlabels' => $iconlabels,
    'strings' => $jsstrings,
    'starters' => $starters,
    'resetconfirm' => get_string('starters:reset_confirm', 'local_ai_course_assistant'),
]]);

echo $OUTPUT->footer();
