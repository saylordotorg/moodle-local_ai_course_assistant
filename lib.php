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
 * Library functions for local_ai_course_assistant.
 *
 * This file is kept minimal. The plugin uses hooks and external functions
 * for its primary functionality.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Serves admin-uploaded avatar images from the customavatars filearea.
 *
 * Registered under the system context so any authenticated user can load an
 * avatar to render in the widget. Returns 404 for missing files and 403 when
 * the user is not logged in.
 *
 * @param stdClass $course  Unused (system-context plugin).
 * @param stdClass $cm      Unused.
 * @param \context $context Context the file lives in (system).
 * @param string $filearea  Expected: customavatars.
 * @param array  $args      URL components after the filearea.
 * @param bool   $forcedownload
 * @param array  $options
 * @return bool False on failure; sends file on success.
 */
function local_ai_course_assistant_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $USER, $DB;

    if ($filearea === 'customavatars') {
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return false;
        }
        if (empty($USER->id)) {
            send_file_not_found();
        }
        $itemid = 0;
        $filename = array_pop($args);
        $filepath = '/' . (implode('/', $args) ?: '');
        if (!str_ends_with($filepath, '/')) {
            $filepath .= '/';
        }
        $fs = get_file_storage();
        $file = $fs->get_file($context->id, 'local_ai_course_assistant', 'customavatars',
            $itemid, $filepath, $filename);
        if (!$file || $file->is_directory()) {
            send_file_not_found();
        }
        send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
        return true;
    }

    if ($filearea === \local_ai_course_assistant\attachment_manager::FILEAREA) {
        // Student message attachments live under the course context. Access
        // rules: the owner of the message can always view their own upload;
        // course managers (capability manage) can view any message in the
        // course to support troubleshooting.
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return false;
        }
        if (empty($USER->id)) {
            send_file_not_found();
        }
        require_login(null, false);

        $itemid = (int) array_shift($args);
        $filename = array_pop($args);
        $filepath = '/' . (implode('/', $args) ?: '');
        if (!str_ends_with($filepath, '/')) {
            $filepath .= '/';
        }

        $msg = $DB->get_record('local_ai_course_assistant_msgs',
            ['id' => $itemid], 'id, userid, courseid', IGNORE_MISSING);
        if (!$msg || (int) $msg->courseid !== (int) $context->instanceid) {
            send_file_not_found();
        }

        $isowner = ((int) $msg->userid === (int) $USER->id);
        $canmanage = has_capability('local/ai_course_assistant:manage', $context);
        if (!$isowner && !$canmanage) {
            send_file_not_found();
        }

        $fs = get_file_storage();
        $file = $fs->get_file(
            $context->id,
            \local_ai_course_assistant\attachment_manager::COMPONENT,
            \local_ai_course_assistant\attachment_manager::FILEAREA,
            $itemid,
            $filepath,
            $filename
        );
        if (!$file || $file->is_directory()) {
            send_file_not_found();
        }
        send_stored_file($file, HOURSECS, 0, $forcedownload, $options);
        return true;
    }

    return false;
}

/**
 * Return the list of admin-uploaded custom avatars.
 *
 * @return array List of ['key' => 'custom:<hash>', 'label' => display name, 'url' => moodle_url]
 */
function local_ai_course_assistant_get_custom_avatars(): array {
    $fs = get_file_storage();
    $context = context_system::instance();
    $files = $fs->get_area_files($context->id, 'local_ai_course_assistant',
        'customavatars', 0, 'filename', false);
    $out = [];
    foreach ($files as $file) {
        $name = $file->get_filename();
        $key = 'custom:' . $file->get_contenthash();
        $url = moodle_url::make_pluginfile_url($context->id, 'local_ai_course_assistant',
            'customavatars', 0, $file->get_filepath(), $name, false);
        $out[] = ['key' => $key, 'label' => $name, 'url' => $url->out(false)];
    }
    return $out;
}

/**
 * Add AI Course Assistant links to the course navigation ("More" menu).
 *
 * Admin feedback: the per-course settings and analytics pages were hard to
 * find, buried under Site administration. This surfaces them where staff
 * already are — on the course page — gated by the same capabilities the
 * target pages themselves enforce. Learners (capability :use only) see
 * nothing. Note Moodle never shows a "Settings" link for local plugins on
 * the Plugins overview page (core hardcodes get_settings_section_name() to
 * null for the local type), so course navigation is the supported way to
 * make these pages findable outside the Site administration tree.
 *
 * @param navigation_node $navigation The course navigation node to extend.
 * @param stdClass $course The course record.
 * @param context_course $context The course context.
 */
function local_ai_course_assistant_extend_navigation_course(navigation_node $navigation, stdClass $course,
        context_course $context): void {
    if (has_capability('local/ai_course_assistant:manage', $context)) {
        $navigation->add(
            get_string('coursesettings:title', 'local_ai_course_assistant'),
            new moodle_url('/local/ai_course_assistant/course_settings.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'aicacoursesettings',
            new pix_icon('i/settings', '')
        );
    }
    if (has_capability('local/ai_course_assistant:viewanalytics', $context)) {
        $navigation->add(
            get_string('analytics:title', 'local_ai_course_assistant'),
            new moodle_url('/local/ai_course_assistant/instructor_dashboard.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'aicaanalytics',
            new pix_icon('i/report', '')
        );
    }
}
