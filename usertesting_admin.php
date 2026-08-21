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
 * Admin page for managing user testing tasks.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', \context_system::instance());

use local_ai_course_assistant\usertesting_manager;

$courseid = optional_param('courseid', 0, PARAM_INT);

$PAGE->set_context(\context_system::instance());
$PAGE->set_url(new moodle_url('/local/ai_course_assistant/usertesting_admin.php', ['courseid' => $courseid]));
$PAGE->set_pagelayout('admin');

$coursename = '';
if ($courseid > 0) {
    $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname', MUST_EXIST);
    $coursename = $course->fullname;
    $pagetitle = get_string('usertesting_admin:title_course', 'local_ai_course_assistant', $coursename);
} else {
    $pagetitle = get_string('usertesting_admin:title_global', 'local_ai_course_assistant');
}
$PAGE->set_title($pagetitle);
$PAGE->set_heading($pagetitle);

// Handle POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);

    if ($action === 'save') {
        $title = required_param('taskset_title', PARAM_TEXT);
        // PARAM_RAW is required to receive the JSON envelope intact (it is a
        // json_encode'd array of task objects, not a scalar). Every decoded field
        // is cleaned field-by-field below: the type slug with PARAM_ALPHANUMEXT,
        // prose with PARAM_TEXT, bounds int-cast.
        $tasksraw = required_param('tasks_json', PARAM_RAW);
        $tasks = json_decode($tasksraw, true);
        $externalurl = optional_param('external_url', '', PARAM_URL);

        if (!is_array($tasks) || empty($tasks)) {
            \core\notification::error(get_string('usertesting_admin:err_invalid_tasks', 'local_ai_course_assistant'));
            redirect($PAGE->url);
        }

        // Clean tasks.
        $clean = [];
        foreach ($tasks as $t) {
            if (empty($t['instruction']) || empty($t['type'])) {
                continue;
            }
            $ttype = clean_param((string) $t['type'], PARAM_ALPHANUMEXT);
            $item = [
                'type' => $ttype,
                'instruction' => clean_param((string) $t['instruction'], PARAM_TEXT),
            ];
            if ($ttype === 'action_then_rate') {
                $item['rating_label'] = clean_param(
                    (string) ($t['rating_label'] ?? 'Rate this task'),
                    PARAM_TEXT
                );
                $item['min'] = (int) ($t['min'] ?? 1);
                $item['max'] = (int) ($t['max'] ?? 5);
                $item['min_label'] = clean_param((string) ($t['min_label'] ?? ''), PARAM_TEXT);
                $item['max_label'] = clean_param((string) ($t['max_label'] ?? ''), PARAM_TEXT);
                $item['follow_up'] = clean_param((string) ($t['follow_up'] ?? ''), PARAM_TEXT);
            }
            if ($ttype === 'free_response') {
                $item['follow_up'] = clean_param((string) ($t['follow_up'] ?? ''), PARAM_TEXT);
            }
            if ($ttype === 'multiple_choice') {
                $opts = [];
                foreach (($t['options'] ?? []) as $opt) {
                    $opt = clean_param(trim((string) $opt), PARAM_TEXT);
                    if ($opt !== '') {
                        $opts[] = $opt;
                    }
                }
                if (empty($opts)) {
                    continue;
                }
                $item['options'] = $opts;
            }
            $clean[] = $item;
        }

        if (empty($clean)) {
            \core\notification::error(get_string('usertesting_admin:err_no_tasks', 'local_ai_course_assistant'));
            redirect($PAGE->url);
        }

        $existing = usertesting_manager::get_active_taskset($courseid);
        if ($existing && (int) $existing->courseid === $courseid) {
            usertesting_manager::update_taskset((int) $existing->id, $title, $clean, $externalurl, true);
            \core\notification::success(get_string('usertesting_admin:saved_updated', 'local_ai_course_assistant'));
        } else {
            usertesting_manager::create_taskset($courseid, $title, $clean, $externalurl);
            \core\notification::success(get_string('usertesting_admin:saved_created', 'local_ai_course_assistant'));
        }
        redirect($PAGE->url);
    }

    if ($action === 'reset') {
        if ($courseid > 0) {
            $existing = $DB->get_records('local_ai_course_assistant_ut_tasks', ['courseid' => $courseid]);
            foreach ($existing as $s) {
                $DB->delete_records('local_ai_course_assistant_ut_tasks', ['id' => $s->id]);
            }
            \core\notification::success(get_string('usertesting_admin:reset_course_done', 'local_ai_course_assistant'));
        } else {
            $existing = $DB->get_records('local_ai_course_assistant_ut_tasks', ['courseid' => 0]);
            foreach ($existing as $s) {
                $DB->delete_records('local_ai_course_assistant_ut_tasks', ['id' => $s->id]);
            }
            usertesting_manager::ensure_default_taskset();
            \core\notification::success(get_string('usertesting_admin:reset_global_done', 'local_ai_course_assistant'));
        }
        redirect($PAGE->url);
    }
}

// Load current task set.
usertesting_manager::ensure_default_taskset();
$taskset = usertesting_manager::get_active_taskset($courseid);
$is_inherited = ($taskset && (int) $taskset->courseid !== $courseid && $courseid > 0);
$tasks = $taskset ? $taskset->tasks : usertesting_manager::DEFAULT_TASKS;
$title = $taskset ? $taskset->title : 'SOLA Usability Test';
$externalurl = ($taskset && isset($taskset->external_url)) ? $taskset->external_url : '';

// Get list of courses.
$courses = $DB->get_records_sql(
    "SELECT c.id, c.fullname, c.shortname FROM {course} c WHERE c.id > 1 AND c.visible = 1 ORDER BY c.fullname ASC"
);

// Labels for the task-card editor the browser builds below. Same shape as the
// rubric editor: one bundle handed to the inline script as JSON. The
// multiple-choice type label is shared with the survey editor rather than
// duplicated, since it is the same word for the same concept.
$jsstrings = [
    'typeactionrate'      => get_string('usertesting_admin:type_action_then_rate', 'local_ai_course_assistant'),
    'typemultiplechoice'  => get_string('survey_admin:type_multiple_choice', 'local_ai_course_assistant'),
    'typefreeresponse'    => get_string('usertesting_admin:type_free_response', 'local_ai_course_assistant'),
    'moveup'              => get_string('rubric_admin:move_up', 'local_ai_course_assistant'),
    'movedown'            => get_string('rubric_admin:move_down', 'local_ai_course_assistant'),
    'deletetask'          => get_string('usertesting_admin:delete_task', 'local_ai_course_assistant'),
    'confirmdelete'       => get_string('usertesting_admin:confirm_delete_task', 'local_ai_course_assistant'),
    'tasktype'            => get_string('usertesting_admin:task_type', 'local_ai_course_assistant'),
    'instruction'         => get_string('usertesting_admin:task_instruction', 'local_ai_course_assistant'),
    'ratinglabel'         => get_string('usertesting_admin:rating_label', 'local_ai_course_assistant'),
    'minvalue'            => get_string('usertesting_admin:min_value', 'local_ai_course_assistant'),
    'minvaluearia'        => get_string('usertesting_admin:min_value_aria', 'local_ai_course_assistant'),
    'maxvalue'            => get_string('usertesting_admin:max_value', 'local_ai_course_assistant'),
    'maxvaluearia'        => get_string('usertesting_admin:max_value_aria', 'local_ai_course_assistant'),
    'minlabel'            => get_string('usertesting_admin:min_label', 'local_ai_course_assistant'),
    'minlabelplaceholder' => get_string('usertesting_admin:min_label_placeholder', 'local_ai_course_assistant'),
    'maxlabel'            => get_string('usertesting_admin:max_label', 'local_ai_course_assistant'),
    'maxlabelplaceholder' => get_string('usertesting_admin:max_label_placeholder', 'local_ai_course_assistant'),
    'followup'            => get_string('usertesting_admin:follow_up', 'local_ai_course_assistant'),
    'followuparia'        => get_string('usertesting_admin:follow_up_aria', 'local_ai_course_assistant'),
    'options'             => get_string('survey_admin:options', 'local_ai_course_assistant'),
    'optionn'             => get_string('survey_admin:option_n', 'local_ai_course_assistant', '{n}'),
    'addoption'           => get_string('survey_admin:add_option', 'local_ai_course_assistant'),
    'addprompt'           => get_string('usertesting_admin:additional_prompt', 'local_ai_course_assistant'),
    'addpromptaria'       => get_string('usertesting_admin:additional_prompt_aria', 'local_ai_course_assistant'),
    'confirmreset'        => $courseid > 0
        ? get_string('usertesting_admin:confirm_reset_course', 'local_ai_course_assistant')
        : get_string('usertesting_admin:confirm_reset_global', 'local_ai_course_assistant'),
    'previewtitle'        => get_string('usertesting_admin:preview_title', 'local_ai_course_assistant'),
    'previewtasklabel'    => get_string('usertesting_admin:preview_task_label', 'local_ai_course_assistant', [
        'num' => '{n}',
        'type' => '{t}',
    ]),
    'previewnoinstruction' => get_string('usertesting_admin:preview_no_instruction', 'local_ai_course_assistant'),
    'previewratefallback'  => get_string('usertesting_admin:preview_rate_fallback', 'local_ai_course_assistant'),
    'previewraterange'     => get_string('usertesting_admin:preview_rate_range', 'local_ai_course_assistant', [
        'label' => '{l}',
        'min' => '{min}',
        'max' => '{max}',
    ]),
    'previewfollowup'      => get_string('usertesting_admin:preview_follow_up', 'local_ai_course_assistant', '{t}'),
    'previewclose'         => get_string('rubric_admin:preview_close', 'local_ai_course_assistant'),
];

echo $OUTPUT->header();
?>


<div class="aica-ut-admin">

    <div class="mb-3 d-flex flex-wrap" style="gap:8px">
        <a href="<?php echo (new moodle_url('/admin/category.php', ['category' => 'local_ai_course_assistant']))->out(); ?>"
           class="btn btn-sm btn-outline-secondary">&larr; <?php echo get_string('courses_admin:plugin_settings', 'local_ai_course_assistant'); ?></a>
        <a href="<?php echo (new moodle_url('/local/ai_course_assistant/analytics.php'))->out(); ?>"
           class="btn btn-sm btn-outline-secondary"><?php echo get_string('rubric_admin:analytics_link', 'local_ai_course_assistant'); ?></a>
    </div>

    <!-- Scope selector -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="get" action="<?php echo $PAGE->url->out_omit_querystring(); ?>" class="form-inline" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <label for="aica-ut-scope" style="font-weight:600;white-space:nowrap"><?php echo get_string('usertesting_admin:scope_label', 'local_ai_course_assistant'); ?></label>
                <select id="aica-ut-scope" name="courseid" class="form-control form-control-sm" style="max-width:350px"
                        onchange="this.form.submit()">
                    <option value="0" <?php echo $courseid === 0 ? 'selected' : ''; ?>><?php echo get_string('rubric_admin:scope_global', 'local_ai_course_assistant'); ?></option>
                    <?php foreach ($courses as $c) : ?>
                    <option value="<?php echo $c->id; ?>" <?php echo (int) $c->id === $courseid ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c->fullname); ?> (<?php echo htmlspecialchars($c->shortname); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <?php if ($is_inherited) : ?>
    <div class="aica-ut-inherited-badge">
        <?php echo get_string('usertesting_admin:inherited_notice', 'local_ai_course_assistant'); ?>
    </div>
    <?php endif; ?>

    <form method="post" action="<?php echo $PAGE->url->out(false); ?>" id="aica-ut-form">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="tasks_json" id="aica-tasks-json" value="">

        <div class="aica-ut-field mb-3">
            <label for="aica-ut-title"><?php echo get_string('usertesting_admin:taskset_title', 'local_ai_course_assistant'); ?></label>
            <input type="text" id="aica-ut-title" name="taskset_title"
                   value="<?php echo htmlspecialchars($title); ?>"
                   placeholder="<?php echo htmlspecialchars(
                       \local_ai_course_assistant\branding::str('usertesting_admin:taskset_title_placeholder')
                   ); ?>">
        </div>

        <div class="aica-ut-field mb-3">
            <label for="aica-ut-exturl"><?php echo get_string('usertesting_admin:external_url', 'local_ai_course_assistant'); ?></label>
            <input type="url" id="aica-ut-exturl" name="external_url"
                   value="<?php echo htmlspecialchars($externalurl); ?>"
                   placeholder="e.g. https://forms.google.com/d/e/xxx/viewform?entry.1={{userid}}&entry.2={{courseid}}">
            <small style="color:#94a3b8;font-size:11px">
                <?php echo get_string('usertesting_admin:external_url_help', 'local_ai_course_assistant'); ?>
            </small>
        </div>

        <h5 style="margin-bottom:12px;color:#334155"><?php echo get_string('usertesting_admin:tasks_heading', 'local_ai_course_assistant'); ?></h5>
        <div id="aica-tasks-container"></div>
        <div class="aica-ut-add-task" id="aica-add-task-btn"><?php echo get_string('usertesting_admin:add_task', 'local_ai_course_assistant'); ?></div>

        <div class="d-flex flex-wrap" style="gap:8px;margin-top:16px">
            <button type="submit" class="btn btn-primary"><?php echo get_string('usertesting_admin:save', 'local_ai_course_assistant'); ?></button>
            <button type="button" class="btn btn-outline-secondary" id="aica-ut-preview-btn"><?php echo get_string('rubric_admin:preview', 'local_ai_course_assistant'); ?></button>
            <?php if ($courseid > 0 && !$is_inherited) : ?>
            <button type="button" class="btn btn-outline-danger" id="aica-ut-reset-btn"><?php echo get_string('rubric_admin:remove_override', 'local_ai_course_assistant'); ?></button>
            <?php elseif ($courseid === 0): ?>
            <button type="button" class="btn btn-outline-danger" id="aica-ut-reset-btn"><?php echo get_string('rubric_admin:reset_defaults', 'local_ai_course_assistant'); ?></button>
            <?php endif; ?>
        </div>
    </form>

    <form method="post" action="<?php echo $PAGE->url->out(false); ?>" id="aica-ut-reset-form" style="display:none">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="reset">
    </form>
</div>

<script>
(function() {
    var tasks = <?php echo json_encode($tasks); ?>;
    var STR = <?php echo json_encode($jsstrings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    // One lookup for the task-type label, used by both the card badge and the
    // preview, so the two never disagree about what a stored type is called.
    function taskTypeLabel(type) {
        if (type === 'action_then_rate') { return STR.typeactionrate; }
        if (type === 'multiple_choice') { return STR.typemultiplechoice; }
        return STR.typefreeresponse;
    }
    var container = document.getElementById('aica-tasks-container');
    var addBtn = document.getElementById('aica-add-task-btn');
    var resetBtn = document.getElementById('aica-ut-reset-btn');

    function renderAll() {
        container.innerHTML = '';
        tasks.forEach(function(t, idx) { container.appendChild(buildCard(t, idx)); });
        updateNumbers();
    }

    function updateNumbers() {
        var cards = container.querySelectorAll('.aica-ut-card');
        cards.forEach(function(card, i) {
            var num = card.querySelector('.aica-ut-num');
            if (num) num.textContent = (i + 1);
        });
    }

    function buildCard(t, idx) {
        var card = document.createElement('div');
        card.className = 'aica-ut-card';
        card.draggable = true;
        card.dataset.idx = idx;

        card.addEventListener('dragstart', function(e) { card.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', idx); });
        card.addEventListener('dragend', function() { card.classList.remove('dragging'); });
        card.addEventListener('dragover', function(e) { e.preventDefault(); });
        card.addEventListener('drop', function(e) {
            e.preventDefault();
            var from = parseInt(e.dataTransfer.getData('text/plain'), 10);
            var to = parseInt(card.dataset.idx, 10);
            if (from !== to) { var moved = tasks.splice(from, 1)[0]; tasks.splice(to, 0, moved); renderAll(); }
        });

        // Header.
        var header = document.createElement('div');
        header.className = 'aica-ut-header';
        var num = document.createElement('div');
        num.className = 'aica-ut-num';
        num.textContent = (idx + 1);
        header.appendChild(num);
        var typeLabel = document.createElement('span');
        typeLabel.className = 'aica-ut-type';
        typeLabel.textContent = taskTypeLabel(t.type);
        header.appendChild(typeLabel);

        var actions = document.createElement('div');
        actions.className = 'aica-ut-actions';
        var upBtn = document.createElement('button'); upBtn.type = 'button'; upBtn.innerHTML = '&#8593;'; upBtn.title = STR.moveup;
        upBtn.addEventListener('click', function() { if (idx > 0) { var tmp = tasks[idx]; tasks[idx] = tasks[idx-1]; tasks[idx-1] = tmp; renderAll(); } });
        actions.appendChild(upBtn);
        var downBtn = document.createElement('button'); downBtn.type = 'button'; downBtn.innerHTML = '&#8595;'; downBtn.title = STR.movedown;
        downBtn.addEventListener('click', function() { if (idx < tasks.length-1) { var tmp = tasks[idx]; tasks[idx] = tasks[idx+1]; tasks[idx+1] = tmp; renderAll(); } });
        actions.appendChild(downBtn);
        var delBtn = document.createElement('button'); delBtn.type = 'button'; delBtn.className = 'aica-ut-delete';
        delBtn.innerHTML = '&#10005;'; delBtn.title = STR.deletetask;
        delBtn.addEventListener('click', function() { if (confirm(STR.confirmdelete)) { tasks.splice(idx, 1); renderAll(); } });
        actions.appendChild(delBtn);
        header.appendChild(actions);
        card.appendChild(header);

        // Type selector.
        var typeField = document.createElement('div'); typeField.className = 'aica-ut-field';
        var typeLbl = document.createElement('label'); typeLbl.textContent = STR.tasktype; typeField.appendChild(typeLbl);
        var typeSelect = document.createElement('select'); typeSelect.setAttribute('aria-label', STR.tasktype);
        [['action_then_rate', STR.typeactionrate], ['free_response', STR.typefreeresponse], ['multiple_choice', STR.typemultiplechoice]].forEach(function(opt) {
            var o = document.createElement('option'); o.value = opt[0]; o.textContent = opt[1];
            if (t.type === opt[0]) o.selected = true;
            typeSelect.appendChild(o);
        });
        typeSelect.addEventListener('change', function() {
            t.type = typeSelect.value;
            if (t.type === 'action_then_rate') { t.rating_label = t.rating_label || 'Rate this task'; t.min = t.min || 1; t.max = t.max || 5; }
            if (t.type === 'multiple_choice' && !t.options) { t.options = ['Option 1', 'Option 2']; }
            renderAll();
        });
        typeField.appendChild(typeSelect);
        card.appendChild(typeField);

        // Instruction text.
        var instrField = document.createElement('div'); instrField.className = 'aica-ut-field';
        var instrLbl = document.createElement('label'); instrLbl.textContent = STR.instruction; instrField.appendChild(instrLbl);
        var instrInput = document.createElement('textarea'); instrInput.setAttribute('aria-label', STR.instruction); instrInput.value = t.instruction || ''; instrInput.rows = 2;
        instrInput.addEventListener('input', function() { t.instruction = instrInput.value; });
        instrField.appendChild(instrInput);
        card.appendChild(instrField);

        // Type-specific fields.
        if (t.type === 'action_then_rate') {
            // Rating label.
            var rlField = document.createElement('div'); rlField.className = 'aica-ut-field';
            var rlLbl = document.createElement('label'); rlLbl.textContent = STR.ratinglabel; rlField.appendChild(rlLbl);
            var rlInp = document.createElement('input'); rlInp.type = 'text'; rlInp.setAttribute('aria-label', STR.ratinglabel); rlInp.value = t.rating_label || '';
            rlInp.addEventListener('input', function() { t.rating_label = rlInp.value; });
            rlField.appendChild(rlInp);
            card.appendChild(rlField);

            // Rating config.
            var ratingFields = document.createElement('div'); ratingFields.className = 'aica-ut-rating-fields';
            [[STR.minvalue, 'min', 1, STR.minvaluearia], [STR.maxvalue, 'max', 5, STR.maxvaluearia]].forEach(function(cfg) {
                var f = document.createElement('div'); f.className = 'aica-ut-field';
                var l = document.createElement('label'); l.textContent = cfg[0]; f.appendChild(l);
                var inp = document.createElement('input'); inp.type = 'number'; inp.setAttribute('aria-label', cfg[3]); inp.value = t[cfg[1]] || cfg[2]; inp.min = 0; inp.max = 10;
                inp.addEventListener('input', function() { t[cfg[1]] = parseInt(inp.value, 10) || cfg[2]; });
                f.appendChild(inp); ratingFields.appendChild(f);
            });
            [[STR.minlabel, 'min_label', STR.minlabelplaceholder], [STR.maxlabel, 'max_label', STR.maxlabelplaceholder]].forEach(function(cfg) {
                var f = document.createElement('div'); f.className = 'aica-ut-field';
                var l = document.createElement('label'); l.textContent = cfg[0]; f.appendChild(l);
                var inp = document.createElement('input'); inp.type = 'text'; inp.setAttribute('aria-label', cfg[0]); inp.value = t[cfg[1]] || ''; inp.placeholder = cfg[2];
                inp.addEventListener('input', function() { t[cfg[1]] = inp.value; });
                f.appendChild(inp); ratingFields.appendChild(f);
            });
            card.appendChild(ratingFields);

            // Follow-up.
            var fuField = document.createElement('div'); fuField.className = 'aica-ut-field';
            var fuLbl = document.createElement('label'); fuLbl.textContent = STR.followup; fuField.appendChild(fuLbl);
            var fuInp = document.createElement('input'); fuInp.type = 'text'; fuInp.setAttribute('aria-label', STR.followuparia); fuInp.value = t.follow_up || '';
            fuInp.addEventListener('input', function() { t.follow_up = fuInp.value; });
            fuField.appendChild(fuInp);
            card.appendChild(fuField);
        }

        if (t.type === 'multiple_choice') {
            var optLabel = document.createElement('label');
            optLabel.textContent = STR.options;
            optLabel.style.cssText = 'font-size:12px;font-weight:600;color:#64748b;margin-bottom:4px;display:block';
            card.appendChild(optLabel);
            var optList = document.createElement('div');
            (t.options || []).forEach(function(opt, oi) {
                var row = document.createElement('div'); row.className = 'aica-ut-opt-row';
                var inp = document.createElement('input'); inp.type = 'text'; inp.setAttribute('aria-label', STR.optionn.replace('{n}', oi + 1)); inp.value = opt;
                inp.addEventListener('input', function() { t.options[oi] = inp.value; });
                row.appendChild(inp);
                var rmBtn = document.createElement('button'); rmBtn.type = 'button'; rmBtn.innerHTML = '&times;';
                rmBtn.addEventListener('click', function() { t.options.splice(oi, 1); renderAll(); });
                row.appendChild(rmBtn);
                optList.appendChild(row);
            });
            card.appendChild(optList);
            var addOpt = document.createElement('div'); addOpt.className = 'aica-ut-add-opt';
            addOpt.textContent = STR.addoption;
            addOpt.addEventListener('click', function() { if (!t.options) t.options = []; t.options.push('New option'); renderAll(); });
            card.appendChild(addOpt);
        }

        if (t.type === 'free_response') {
            var fuField2 = document.createElement('div'); fuField2.className = 'aica-ut-field';
            var fuLbl2 = document.createElement('label'); fuLbl2.textContent = STR.addprompt; fuField2.appendChild(fuLbl2);
            var fuInp2 = document.createElement('input'); fuInp2.type = 'text'; fuInp2.setAttribute('aria-label', STR.addpromptaria); fuInp2.value = t.follow_up || '';
            fuInp2.addEventListener('input', function() { t.follow_up = fuInp2.value; });
            fuField2.appendChild(fuInp2);
            card.appendChild(fuField2);
        }

        return card;
    }

    addBtn.addEventListener('click', function() {
        tasks.push({type: 'action_then_rate', instruction: '', rating_label: 'Rate this task', min: 1, max: 5, min_label: '', max_label: '', follow_up: ''});
        renderAll();
        var cards = container.querySelectorAll('.aica-ut-card');
        if (cards.length) cards[cards.length-1].scrollIntoView({behavior:'smooth',block:'center'});
    });

    document.getElementById('aica-ut-form').addEventListener('submit', function() {
        document.getElementById('aica-tasks-json').value = JSON.stringify(tasks);
    });

    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            if (confirm(STR.confirmreset)) { document.getElementById('aica-ut-reset-form').submit(); }
        });
    }

    // Preview button.
    var previewBtn = document.getElementById('aica-ut-preview-btn');
    if (previewBtn) {
        previewBtn.addEventListener('click', function() {
            var overlay = document.createElement('div');
            overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center';
            overlay.addEventListener('click', function(e) { if (e.target === overlay) document.body.removeChild(overlay); });
            var panel = document.createElement('div');
            panel.style.cssText = 'background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%;max-height:80vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.2)';
            var h = document.createElement('h4');
            h.textContent = document.getElementById('aica-ut-title').value || STR.previewtitle;
            h.style.marginBottom = '16px';
            panel.appendChild(h);

            tasks.forEach(function(t, idx) {
                var tDiv = document.createElement('div');
                tDiv.style.cssText = 'margin-bottom:16px;padding:14px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc';
                var tNum = document.createElement('div');
                tNum.style.cssText = 'font-size:11px;font-weight:700;color:#0369a1;text-transform:uppercase;margin-bottom:6px';
                tNum.textContent = STR.previewtasklabel.replace('{n}', idx + 1).replace('{t}', taskTypeLabel(t.type));
                tDiv.appendChild(tNum);
                var tText = document.createElement('div');
                tText.style.cssText = 'font-size:14px;color:#1e293b;margin-bottom:8px';
                tText.textContent = t.instruction || STR.previewnoinstruction;
                tDiv.appendChild(tText);

                if (t.type === 'action_then_rate') {
                    var rl = document.createElement('div');
                    rl.style.cssText = 'font-size:12px;color:#64748b;font-style:italic';
                    rl.textContent = STR.previewraterange
                        .replace('{l}', t.rating_label || STR.previewratefallback)
                        .replace('{min}', (t.min||1))
                        .replace('{max}', (t.max||5));
                    tDiv.appendChild(rl);
                    if (t.follow_up) {
                        var fu = document.createElement('div');
                        fu.style.cssText = 'font-size:12px;color:#94a3b8;margin-top:4px';
                        fu.textContent = STR.previewfollowup.replace('{t}', t.follow_up);
                        tDiv.appendChild(fu);
                    }
                }
                if (t.type === 'multiple_choice') {
                    (t.options || []).forEach(function(opt) {
                        var row = document.createElement('div');
                        row.style.cssText = 'font-size:13px;padding:4px 10px;margin-top:4px;background:#fff;border-radius:6px;border:1px solid #e2e8f0';
                        row.textContent = opt;
                        tDiv.appendChild(row);
                    });
                }
                panel.appendChild(tDiv);
            });

            var closeBtn = document.createElement('button');
            closeBtn.textContent = STR.previewclose;
            closeBtn.className = 'btn btn-sm btn-outline-secondary';
            closeBtn.addEventListener('click', function() { document.body.removeChild(overlay); });
            panel.appendChild(closeBtn);
            overlay.appendChild(panel);
            document.body.appendChild(overlay);
        });
    }

    renderAll();
})();
</script>

<?php
echo $OUTPUT->footer();
