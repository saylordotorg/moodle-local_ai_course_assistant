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

// Labels for the starter-card editor that the inline JS builds in the browser.
// Resolved here so they are translatable (I18N001); the script reads STR.<key>.
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

echo $OUTPUT->header();
?>


<div class="aica-starters-admin">
    <p><?php echo get_string('starters:admin_desc', 'local_ai_course_assistant'); ?></p>

    <div class="card mb-3 sola-starters-howto">
        <div class="card-body">
            <details>
                <summary class="sola-starters-howto-summary">
                    <?php echo get_string('starters:howto_heading', 'local_ai_course_assistant'); ?>
                </summary>
                <div class="sola-starters-howto-body">
                <p><?php echo get_string('starters:howto_builtin', 'local_ai_course_assistant'); ?></p>
                <p><?php echo get_string(
                    'starters:howto_custom',
                    'local_ai_course_assistant',
                    get_string('starters:add_new', 'local_ai_course_assistant')
                ); ?></p>
                <p><?php echo get_string('starters:howto_types', 'local_ai_course_assistant'); ?></p>
                <ul class="sola-starters-howto-list">
                    <li><?php echo get_string('starters:howto_type_prompt', 'local_ai_course_assistant'); ?></li>
                    <li><span class="aica-starter-type-badge type-quiz" >QUIZ</span> <?php
                        echo get_string('starters:howto_type_quiz', 'local_ai_course_assistant'); ?></li>
                    <li><span class="aica-starter-type-badge type-voice" >VOICE</span> <?php
                        echo get_string('starters:howto_type_voice', 'local_ai_course_assistant'); ?></li>
                    <li><span class="aica-starter-type-badge type-pronunciation" >PRONUNCIATION</span> <?php
                        echo get_string('starters:howto_type_pronunciation', 'local_ai_course_assistant'); ?></li>
                </ul>
                <p><?php echo get_string('starters:howto_conditional', 'local_ai_course_assistant'); ?></p>
                <p><?php echo get_string('starters:howto_placeholders', 'local_ai_course_assistant'); ?></p>
                <p><?php echo get_string('starters:howto_reorder', 'local_ai_course_assistant'); ?></p>
                <p><?php echo get_string('starters:howto_overrides', 'local_ai_course_assistant'); ?></p>
            </div>
            </details>
        </div>
    </div>

    <div class="aica-admin-actions mb-3">
        <form method="post" class="sola-inline-form">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="starters_json" class="aica-starters-json" value="">
            <button type="submit" class="btn btn-primary aica-save-btn">
                <?php echo get_string('starters:save', 'local_ai_course_assistant'); ?>
            </button>
        </form>
        <form method="post" class="sola-inline-form" onsubmit="return confirm('<?php echo get_string('starters:reset_confirm', 'local_ai_course_assistant'); ?>');">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="reset">
            <button type="submit" class="btn btn-outline-secondary">
                <?php echo get_string('starters:reset_defaults', 'local_ai_course_assistant'); ?>
            </button>
        </form>
        <a href="<?php echo (new moodle_url('/admin/category.php', ['category' => 'local_ai_course_assistant']))->out(); ?>"
           class="btn btn-outline-secondary">
            <?php echo get_string('starters:back_settings', 'local_ai_course_assistant'); ?>
        </a>
    </div>

    <div id="aica-starters-list"></div>

    <button type="button" class="aica-btn-add" id="aica-add-starter">
        <span class="sola-starters-plus">+</span>
        <?php echo get_string('starters:add_new', 'local_ai_course_assistant'); ?>
    </button>

    <div class="aica-admin-actions">
        <form method="post" class="sola-inline-form">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="starters_json" class="aica-starters-json" value="">
            <button type="submit" class="btn btn-primary aica-save-btn">
                <?php echo get_string('starters:save', 'local_ai_course_assistant'); ?>
            </button>
        </form>
        <form method="post" class="sola-inline-form" onsubmit="return confirm('<?php echo get_string('starters:reset_confirm', 'local_ai_course_assistant'); ?>');">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="reset">
            <button type="submit" class="btn btn-outline-secondary">
                <?php echo get_string('starters:reset_defaults', 'local_ai_course_assistant'); ?>
            </button>
        </form>
        <a href="<?php echo (new moodle_url('/admin/category.php', ['category' => 'local_ai_course_assistant']))->out(); ?>"
           class="btn btn-outline-secondary">
            <?php echo get_string('starters:back_settings', 'local_ai_course_assistant'); ?>
        </a>
    </div>
</div>

<?php
// v7.2.0 (CONTRIB-10574 #201): the 229-line inline <script> that used to sit
// here is now amd/src/starter_admin.js, initialised via js_call_amd with the
// data that was previously echoed into the page as JSON literals.
$PAGE->requires->js_call_amd('local_ai_course_assistant/starter_admin', 'init', [[
    'icons' => $icons,
    'iconlabels' => $iconlabels,
    'strings' => $jsstrings,
    'starters' => $starters,
]]);

echo $OUTPUT->footer();
