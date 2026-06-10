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
 * v6.0.0 spend-alert self-test CLI.
 *
 * Sends a test email to every address in `spend_notify_emails` (or to
 * site admins if that setting is blank) so admins can verify the alert
 * delivery path BEFORE relying on it. The same recipient resolution and
 * email format used by `spend_guard::check()` (80% / 95% / 100%
 * threshold notifications) and `cost_anomaly_detector::maybe_send_alert()`
 * (v6.0 daily anomaly task).
 *
 * Usage:
 *   php admin/cli/send_spend_alert_test_email.php
 *   php admin/cli/send_spend_alert_test_email.php --dry-run
 *
 * The CLI prints the recipient list, the email body, and the per-address
 * delivery result. `--dry-run` resolves the recipient list and prints the
 * email body without actually sending.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
global $CFG;
require_once($CFG->dirroot . '/lib/clilib.php');

$dryrun = in_array('--dry-run', $argv, true);

$recipients = trim((string) (get_config('local_ai_course_assistant', 'spend_notify_emails') ?: ''));
$source = 'spend_notify_emails';
if ($recipients === '') {
    $admins = get_admins();
    $recipients = implode(',', array_map(fn($a) => $a->email, $admins));
    $source = 'site admins (spend_notify_emails was blank)';
}

$list = array_values(array_filter(array_map('trim', explode(',', $recipients))));
echo "Recipient source: $source\n";
echo "Resolved " . count($list) . " recipient(s):\n";
foreach ($list as $email) {
    echo "  - $email\n";
}
if (empty($list)) {
    fwrite(STDERR, "ERROR: no recipients resolved. Set spend_notify_emails or add a site admin with a valid email.\n");
    exit(1);
}

$subject = '[SOLA spend guard] TEST email — verify alert delivery path';
$body  = "This is a TEST email from SOLA's spend-alert delivery path.\n\n";
$body .= "Sent by:    admin/cli/send_spend_alert_test_email.php\n";
$body .= "Time:       " . userdate(time()) . "\n";
$body .= "Source:     " . $source . "\n";
$body .= "Recipients: " . implode(', ', $list) . "\n\n";
$body .= "If you received this, the spend-alert email path is live. The same\n";
$body .= "recipient resolution is used by:\n";
$body .= "  - spend_guard 80% / 95% / 100% threshold notifications (existing).\n";
$body .= "  - cost_anomaly_detector daily anomaly alerts (v6.0).\n\n";
$body .= "If you did NOT expect to be on this list, the admin's recipient list\n";
$body .= "is set via Site administration > Plugins > Local plugins > AI Course\n";
$body .= "Assistant > Spend caps > Spend notification emails.\n";

if ($dryrun) {
    echo "\n--- DRY RUN, no email sent ---\nSubject: $subject\n\n$body";
    exit(0);
}

$sent = 0;
$failed = [];
foreach ($list as $email) {
    $to = new \stdClass();
    $to->email = $email;
    $to->firstname = '';
    $to->lastname = '';
    $to->id = -10;
    $to->maildisplay = true;
    $to->mailformat = 1;
    $to->firstnamephonetic = '';
    $to->lastnamephonetic = '';
    $to->middlename = '';
    $to->alternatename = '';
    try {
        if (\email_to_user($to, \core_user::get_noreply_user(), $subject, $body)) {
            $sent++;
            echo "  ✓ sent to $email\n";
        } else {
            $failed[] = $email;
            echo "  ✗ FAIL: $email (email_to_user returned false)\n";
        }
    } catch (\Throwable $e) {
        $failed[] = $email;
        echo "  ✗ FAIL: $email (" . $e->getMessage() . ")\n";
    }
}

echo "\nDelivered $sent of " . count($list) . " test emails.\n";
if (!empty($failed)) {
    fwrite(STDERR, "Failures: " . implode(', ', $failed) . "\n");
    exit(2);
}
exit(0);
