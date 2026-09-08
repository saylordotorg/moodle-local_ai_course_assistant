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
$privacytitle = get_string('privacy:title', 'local_ai_course_assistant', branding::display_name());
$PAGE->set_title($privacytitle);
$PAGE->set_heading($privacytitle);

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

// Default branded notice. All prose lives in privacynotice:* lang strings;
// branding::str() resolves the [[tutorname]]/[[tutorshort]]/[[uniname]]
// tokens they contain. The template auto-escapes every variable except the
// two pre-built contact link lines.
$notice = [
    'title'                 => 'privacynotice:title',
    'whatisheading'         => 'privacynotice:whatis',
    'whatisp1'              => 'privacynotice:whatis_p1',
    'whatisp2'              => 'privacynotice:whatis_p2',
    'collectsheading'       => 'privacynotice:collects',
    'collectsp1'            => 'privacynotice:collects_p1',
    'collectsp2'            => 'privacynotice:collects_p2',
    'usesheading'           => 'privacynotice:uses',
    'usesitem1'             => 'privacynotice:uses_item1',
    'usesitem2'             => 'privacynotice:uses_item2',
    'usesitem3'             => 'privacynotice:uses_item3',
    'usesitem4'             => 'privacynotice:uses_item4',
    'usesitem5'             => 'privacynotice:uses_item5',
    'usesnosell'            => 'privacynotice:uses_nosell',
    'whoseesheading'        => 'privacynotice:whosees',
    'whoseesintro'          => 'privacynotice:whosees_intro',
    'shareditem1'           => 'privacynotice:shared_item1',
    'shareditem2'           => 'privacynotice:shared_item2',
    'shareditem3'           => 'privacynotice:shared_item3',
    'shareditem4'           => 'privacynotice:shared_item4',
    'shareditem5'           => 'privacynotice:shared_item5',
    'whoseesnever'          => 'privacynotice:whosees_never',
    'notshareditem1'        => 'privacynotice:notshared_item1',
    'notshareditem2'        => 'privacynotice:notshared_item2',
    'notshareditem3'        => 'privacynotice:notshared_item3',
    'notshareditem4'        => 'privacynotice:notshared_item4',
    'notshareditem5'        => 'privacynotice:notshared_item5',
    'whoseescontract'       => 'privacynotice:whosees_contract',
    'retentionheading'      => 'privacynotice:retention',
    'retentionitem1'        => 'privacynotice:retention_item1',
    'retentionitem2'        => 'privacynotice:retention_item2',
    'retentionitem3'        => 'privacynotice:retention_item3',
    'retentionitem4'        => 'privacynotice:retention_item4',
    'retentiondeletion'     => 'privacynotice:retention_deletion',
    'rightsheading'         => 'privacynotice:rights',
    'rightsaccess'          => 'privacynotice:rights_access',
    'rightsaccessdesc'      => 'privacynotice:rights_access_desc',
    'rightsdownload'        => 'privacynotice:rights_download',
    'rightsdownloaddesc'    => 'privacynotice:rights_download_desc',
    'rightsdelete'          => 'privacynotice:rights_delete',
    'rightsdeletedesc'      => 'privacynotice:rights_delete_desc',
    'rightscorrection'      => 'privacynotice:rights_correction',
    'rightscorrectiondesc'  => 'privacynotice:rights_correction_desc',
    'rightsobject'          => 'privacynotice:rights_object',
    'rightsobjectdesc'      => 'privacynotice:rights_object_desc',
    'rightsportability'     => 'privacynotice:rights_portability',
    'rightsportabilitydesc' => 'privacynotice:rights_portability_desc',
    'rightscomplaint'       => 'privacynotice:rights_complaint',
    'rightscomplaintdesc'   => 'privacynotice:rights_complaint_desc',
    'internationalheading'  => 'privacynotice:international',
    'internationalbody'     => 'privacynotice:international_body',
    'securityheading'       => 'privacynotice:security',
    'securityintro'         => 'privacynotice:security_intro',
    'securitytransit'       => 'privacynotice:security_transit',
    'securitytransitdesc'   => 'privacynotice:security_transit_desc',
    'securityrest'          => 'privacynotice:security_rest',
    'securityrestdesc'      => 'privacynotice:security_rest_desc',
    'securityincident'      => 'privacynotice:security_incident',
    'securityincidentdesc'  => 'privacynotice:security_incident_desc',
    'childrenheading'       => 'privacynotice:children',
    'childrenbody'          => 'privacynotice:children_body',
    'contactheading'        => 'privacynotice:contact',
    'contactwidget'         => 'privacynotice:contact_widget',
];
$data = [];
foreach ($notice as $var => $identifier) {
    $data[$var] = branding::str($identifier);
}
$data['lastupdated'] = branding::str('privacynotice:lastupdated', date('j F Y'));

// Contact lines carry inline links, so the anchors are built here (with the
// email/URL escaped) and rendered unescaped by the template; the raw values
// gate the corresponding list items.
$contactemail = branding::contact_email();
$privacyurl = branding::privacy_external_url();
$data['contactemail'] = $contactemail;
$data['privacyurl'] = $privacyurl;
$data['contactemailline'] = branding::str('privacynotice:contact_email',
    html_writer::link('mailto:' . $contactemail, s($contactemail)));
$data['privacypageline'] = branding::str('privacynotice:contact_privacypage',
    html_writer::link($privacyurl, s($privacyurl), ['target' => '_blank', 'rel' => 'noopener']));

echo $OUTPUT->render_from_template('local_ai_course_assistant/privacy_notice', $data);

echo $OUTPUT->footer();
