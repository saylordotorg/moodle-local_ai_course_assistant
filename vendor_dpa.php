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
 * Vendor DPA status admin view.
 *
 * Surfaces the `vendor_registry::DPA_STATUS` table so admins can see, at a
 * glance, which AI provider drivers are cleared for Tier 2 or higher use
 * and which require the Approved AI Vendor review before being routed
 * learner traffic.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);

$PAGE->set_url('/local/ai_course_assistant/vendor_dpa.php');
$PAGE->set_context($syscontext);
$PAGE->set_title(get_string('admin:vendor_dpa:title', 'local_ai_course_assistant',
    \local_ai_course_assistant\branding::short_name()));
$PAGE->set_heading(get_string('admin:vendor_dpa:title', 'local_ai_course_assistant',
    \local_ai_course_assistant\branding::short_name()));

\local_ai_course_assistant\security::send_security_headers(true);

// Map each registry status to a language-string key and a CSS tone class, so
// the template carries no hard-coded English or data-driven inline colours.
$labels = [
    'contractual' => ['key' => 'admin:vendor_dpa:too_contractual', 'class' => 'sola-dpa-good'],
    'default_on'  => ['key' => 'admin:vendor_dpa:too_default_on',  'class' => 'sola-dpa-bad'],
    'none'        => ['key' => 'admin:vendor_dpa:too_none',        'class' => 'sola-dpa-bad'],
    'local'       => ['key' => 'admin:vendor_dpa:too_local',       'class' => 'sola-dpa-good'],
    'unknown'     => ['key' => 'admin:vendor_dpa:too_unknown',     'class' => 'sola-dpa-warn'],
];
$dpalabels = [
    'signed'         => ['key' => 'admin:vendor_dpa:dpa_signed',         'class' => 'sola-dpa-good'],
    'available'      => ['key' => 'admin:vendor_dpa:dpa_available',      'class' => 'sola-dpa-good'],
    'negotiating'    => ['key' => 'admin:vendor_dpa:dpa_negotiating',    'class' => 'sola-dpa-warn'],
    'not_offered'    => ['key' => 'admin:vendor_dpa:dpa_not_offered',    'class' => 'sola-dpa-bad'],
    'not_applicable' => ['key' => 'admin:vendor_dpa:dpa_not_applicable', 'class' => 'sola-dpa-neutral'],
    'unknown'        => ['key' => 'admin:vendor_dpa:dpa_unknown',        'class' => 'sola-dpa-neutral'],
];

$rows = [];
foreach (\local_ai_course_assistant\vendor_registry::all() as $row) {
    $too = $labels[$row['training_opt_out']] ?? $labels['unknown'];
    $dpa = $dpalabels[$row['dpa_status']] ?? $dpalabels['unknown'];
    $rows[] = [
        'label'     => $row['label'],
        'provider'  => $row['provider'],
        'too_text'  => get_string($too['key'], 'local_ai_course_assistant'),
        'too_class' => $too['class'],
        'dpa_text'  => get_string($dpa['key'], 'local_ai_course_assistant'),
        'dpa_class' => $dpa['class'],
        'retention' => $row['retention'],
        'tier'      => get_string('admin:vendor_dpa:tier', 'local_ai_course_assistant', (int) $row['tier_ok']),
        'link'      => !empty($row['dpa_link']) ? $row['dpa_link'] : '',
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/vendor_dpa', [
    'intro'            => get_string('admin:vendor_dpa:intro', 'local_ai_course_assistant'),
    'maintenance_note' => get_string('admin:vendor_dpa:maintenance_note', 'local_ai_course_assistant'),
    'rows'             => $rows,
]);
echo $OUTPUT->footer();
