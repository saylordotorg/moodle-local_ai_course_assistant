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
 * Learner-facing in-browser Python code sandbox (v3.9.26).
 *
 * Runs Python entirely in the learner's browser via Pyodide
 * (Python-compiled-to-WebAssembly). Code never leaves the device; no
 * server-side execution. Per-course toggle so courses without code
 * work do not see it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

// v5.1.7: was required_param + hard 404 when accessed bare. Sandbox is
// learner-facing and almost always opened from inside a course context,
// but a direct hit on the URL should give a friendly "pick a course"
// landing rather than a 404. Direct links with ?courseid=N unchanged.
$courseid = optional_param('courseid', 0, PARAM_INT);
if ($courseid <= 0) {
    \local_ai_course_assistant\page_helpers::render_course_picker_landing(
        '/local/ai_course_assistant/sandbox.php',
        get_string(
            'coursepicker:title',
            'local_ai_course_assistant',
            get_string('sandbox:title', 'local_ai_course_assistant')
        ),
        'local/ai_course_assistant:use'
    );
    exit;
}
$course = get_course($courseid);
$context = context_course::instance($courseid);
require_capability('local/ai_course_assistant:use', $context);

if (!\local_ai_course_assistant\feature_flags::resolve('code_sandbox', $courseid)) {
    throw new \moodle_exception('sandbox:disabled', 'local_ai_course_assistant');
}

// CONTRIB-10574 #271: the Python runtime used to be fetched from a hard-coded
// jsDelivr URL, so opening this page sent every learner's IP address and user
// agent to a third party nobody had agreed to. The location is now an admin
// setting with no default, and empty means off, exactly as with
// remoteconfigurl in 7.6.0. No fallback: an admin who leaves it blank has said
// no to the outbound request, and the page must not quietly find another way.
$pyodidebase = \local_ai_course_assistant\code_sandbox::pyodide_base_url();
if ($pyodidebase === '') {
    throw new \moodle_exception('sandbox:noruntimeurl', 'local_ai_course_assistant');
}

$pageurl = new moodle_url('/local/ai_course_assistant/sandbox.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_course($course);
$PAGE->set_title(get_string('sandbox:title', 'local_ai_course_assistant'));
$PAGE->set_heading($course->fullname);

// Note: NOT calling security::send_security_headers() here — the bundled
// Moodle CSP from the theme is permissive enough for Pyodide's WASM
// fetches. The default CSP we ship in classes/security.php restricts
// script-src to 'self', which would block a runtime hosted anywhere else.
// The sandbox page is the one place we accept the looser CSP, because
// Pyodide loads scripts and WASM from the configured runtime location.
// An operator who wants 'self' back can host the runtime on this site and
// point the setting at it.

// Page markup lives in templates/sandbox.mustache and the behaviour in
// amd/src/sandbox.js (CONTRIB-10574 #273). The runtime location reaches the
// module on data attributes rather than being echoed into a script, so no PHP
// value is ever interpolated into JavaScript on this page. That retires the
// class of bug fixed in v7.6.1, where a French apostrophe in a lang string
// closed a hand-quoted literal and stopped every script from running.
//
// The Pyodide loader is injected by the module, not listed as an AMD
// dependency: it comes from the admin-configured location, outside Moodle's
// module loader. Subresource-integrity is intentionally omitted: the bundle
// pulls hash-stamped sub-files of its own, so SRI on the loader alone would
// protect little and would break every self-hosted copy.
$PAGE->requires->js_call_amd('local_ai_course_assistant/sandbox', 'init');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/sandbox', [
    'pyodidebase' => $pyodidebase,
    'loaderurl' => \local_ai_course_assistant\code_sandbox::pyodide_asset_url('pyodide.js'),
]);
echo $OUTPUT->footer();
