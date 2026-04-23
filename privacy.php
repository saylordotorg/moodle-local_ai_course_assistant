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
 * Learner-facing SOLA privacy notice.
 *
 * Renders the canonical privacy notice referenced by the Saylor University
 * Responsible Use of AI Policy, section 6.7. Linked from the widget footer,
 * from the first-run consent banner, and from the learner settings page.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url('/local/ai_course_assistant/privacy.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('privacy:title', 'local_ai_course_assistant'));
$PAGE->set_heading(get_string('privacy:title', 'local_ai_course_assistant'));

echo $OUTPUT->header();

// Content is authored in .drafts/sola-privacy-notice-learner-facing.md and
// mirrored here as sanitized HTML. When the Legal review lands, the canonical
// text will move into lang strings so the 46 language files can localize it.
?>
<div class="sola-privacy-notice" style="max-width:820px;margin:0 auto;line-height:1.6;padding:0 12px">

<h2>SOLA Privacy Notice</h2>
<p><em>Last updated: <?php echo date('j F Y'); ?>. Version 1.0.</em></p>

<h3>What SOLA Is</h3>
<p>SOLA is the Saylor Online Learning Assistant. It is an AI powered learning coach built into some Saylor courses. When SOLA is available on a course, you will see a chat widget on the course pages. You can ask SOLA questions about the material, get practice questions, plan your study schedule, and use voice features if your course has them enabled.</p>
<p>SOLA is not available on every Saylor course. The decision to enable SOLA on a course is made by the course owner.</p>

<h3>What Information SOLA Collects</h3>
<p>When you use SOLA on a course, Saylor records your messages, SOLA's responses, the course and time of each exchange, ratings and feedback you give, your study plan and reminder preferences if you create them, your chosen avatar, and a short profile summary that SOLA generates from your conversations to personalize future sessions.</p>
<p>SOLA also collects standard technical data needed to operate the service: your Moodle user id, IP address, browser type, and a timestamp. SOLA does not collect your full name, email address, home address, phone number (unless you give one for reminders), payment information, or government issued identifiers.</p>

<h3>How Your Information Is Used</h3>
<ol>
    <li>Answer your questions in the moment.</li>
    <li>Personalize SOLA's responses to you.</li>
    <li>Improve SOLA itself, using anonymized and aggregated data.</li>
    <li>Detect and prevent abuse.</li>
    <li>Generate analytics that help course authors improve course materials. Analytics are anonymized before they reach a human reviewer.</li>
</ol>
<p>Saylor does not sell your information. Saylor does not use your SOLA conversations to market unrelated products to you.</p>

<h3>Who Receives Your Information</h3>
<p>To answer your questions, SOLA sends your first name, a summary of your chosen course material, the last 10 turns of your current SOLA conversation, your study plan context (if any), and your profile summary (if any) to the AI model provider configured for the course. SOLA does not send your last name, email, Moodle user id, address, or other personally identifying information to the AI model provider.</p>
<p>Saylor uses AI model providers from Saylor's Approved AI Vendor List. Each approved provider has a contract with Saylor that limits how that provider may use your data and requires them not to train their models on your SOLA messages.</p>

<h3>How Long SOLA Keeps Your Information</h3>
<ul>
    <li>Your current conversation is stored for the duration of your course enrollment. Only the most recent 10 turns are ever sent to the AI model.</li>
    <li>Ratings, study plans, and reminders are retained until you remove them or end your course enrollment.</li>
    <li>Anonymized analytics are retained under Saylor's Records Retention Policy and cannot be linked back to you.</li>
    <li>Audit and operational logs are retained up to 365 days.</li>
</ul>
<p>When your Saylor user account is deleted, all SOLA data tied to your user id is deleted within the same operation.</p>

<h3>Your Rights</h3>
<ol>
    <li><strong>Access.</strong> View your current conversation in the widget; download a complete copy of all SOLA data from the SOLA user settings page.</li>
    <li><strong>Download.</strong> The user settings page offers a "Download my SOLA data" button that produces a JSON file.</li>
    <li><strong>Delete.</strong> The user settings page offers course level and global delete options. Deletion is immediate.</li>
    <li><strong>Correction.</strong> SOLA conversations are raw transcripts and are not normally amended. If a derived record looks wrong, continue using SOLA or contact the Saylor Data Protection Officer.</li>
    <li><strong>Object or restrict.</strong> You do not have to use SOLA. You can remove your data at any time.</li>
    <li><strong>Portability.</strong> The download is in a standard JSON format and can be imported into other systems.</li>
    <li><strong>Complaint.</strong> Contact the Saylor Data Protection Officer. Learners in the EU, UK, Switzerland, Brazil, or Canada may also complain to their national data protection authority.</li>
</ol>

<h3>International Learners</h3>
<p>Saylor serves learners globally. If you are based in a region with specific data protection rules (GDPR, UK GDPR, LGPD, PIPEDA, Swiss FADP, CCPA), those rules apply to your SOLA data. Saylor's lawful basis for processing is the performance of the education contract you have with Saylor, combined with Saylor's legitimate interest in improving its education services.</p>

<h3>Security</h3>
<p>SOLA runs inside Saylor's Moodle platform, behind your Saylor login. Data in transit is encrypted with TLS. Data at rest lives in Saylor's Moodle database under Saylor's standard security controls. If Saylor detects a security incident that affects your SOLA data, Saylor will notify you in accordance with the applicable law.</p>

<h3>Children</h3>
<p>SOLA is available only to learners who meet the age requirements of the Saylor course they are enrolled in. Saylor does not knowingly collect SOLA data from children under the age of 13.</p>

<h3>Contact</h3>
<ul>
    <li>Saylor Data Protection Officer: dpo@saylor.org</li>
    <li>Saylor Privacy Page: https://www.saylor.org/privacy</li>
    <li>Within SOLA: open the widget, click the gear icon, open the Privacy and data section.</li>
</ul>

</div>
<?php
echo $OUTPUT->footer();
