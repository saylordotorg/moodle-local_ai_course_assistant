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
 * Learner-facing privacy notice for the AI Course Assistant.
 *
 * Renders the default branded notice via a Mustache template (Output API), or
 * the admin-configured override HTML when privacy_notice_override is set.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\branding;

require_login();

$PAGE->set_url('/local/ai_course_assistant/privacy.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title(branding::display_name() . ' Privacy Notice');
$PAGE->set_heading(branding::display_name() . ' Privacy Notice');

echo $OUTPUT->header();

// Admin override: render the configured HTML in place of the default branded
// notice (passed through format_text for sanitization and filter support).
$override = get_config('local_ai_course_assistant', 'privacy_notice_override');
if (is_string($override) && trim($override) !== '') {
    echo \html_writer::start_div('sola-privacy-notice');
    echo format_text($override, FORMAT_HTML, ['context' => $PAGE->context, 'noclean' => false]);
    echo \html_writer::end_div();
    echo $OUTPUT->footer();
    return;
}

// Default branded notice. Raw branding values are passed; the template
// auto-escapes them.
echo $OUTPUT->render_from_template('local_ai_course_assistant/privacy_notice', [
    'productfull'  => branding::display_name(),
    'product'      => branding::short_name(),
    'inst'         => branding::institution_name(),
    'today'        => date('j F Y'),
    'contactemail' => branding::contact_email(),
    'privacyurl'   => branding::privacy_external_url(),
]);

echo $OUTPUT->footer();
