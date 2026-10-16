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

namespace local_ai_course_assistant;

defined('MOODLE_INTERNAL') || die();

/**
 * The anomaly digest's Slack and Teams webhook URLs are stored encrypted and posted to decrypted.
 *
 * The URL carries its token in the path, so it is a secret like a key. These tests run the real digest
 * task and watch where it delivers: a decrypted URL on a trusted host is posted to (the response is mocked,
 * nothing leaves the machine) and nothing falls back to the admin email, while a URL that reaches the
 * delivery still as ciphertext fails the address check and the digest falls back to the email.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\task\run_anomaly_digest
 * @covers     \local_ai_course_assistant\secrets
 */
final class anomaly_digest_webhook_test extends \advanced_testcase {

    private const SLACK = 'https://hooks.example/T000/B000/SENTINELWEBHOOK';
    private const TEAMS = 'https://hooks.example/teams/T000/SENTINELTEAMS';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Turn the digest on and give it something to report: negative ratings up from 5 to 6 week over week,
     * with the threshold at 0 so any rise is an alert. The webhook host is trusted so the address check
     * accepts it without a DNS lookup.
     */
    private function make_the_digest_alert(): void {
        global $DB;
        set_config('anomaly_digest_enabled', 1, 'local_ai_course_assistant');
        set_config('anomaly_digest_threshold_pct', 0, 'local_ai_course_assistant');
        set_config('ssrf_trusted_endpoints', 'https://hooks.example', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $now = time();
        for ($i = 0; $i < 5; $i++) {
            $DB->insert_record('local_ai_course_assistant_msg_ratings', (object) [
                'messageid' => 100 + $i, 'userid' => $user->id, 'courseid' => $course->id,
                'rating' => -1, 'timecreated' => $now - (10 * 86400),
            ]);
        }
        for ($i = 0; $i < 6; $i++) {
            $DB->insert_record('local_ai_course_assistant_msg_ratings', (object) [
                'messageid' => 200 + $i, 'userid' => $user->id, 'courseid' => $course->id,
                'rating' => -1, 'timecreated' => $now - (3 * 86400),
            ]);
        }
    }

    /**
     * Run the digest and say how many admin emails it sent.
     *
     * @return int
     */
    private function run_digest(): int {
        $sink = $this->redirectEmails();
        ob_start();
        try {
            (new \local_ai_course_assistant\task\run_anomaly_digest())->execute();
        } finally {
            ob_end_clean();
        }
        $count = $sink->count();
        $sink->close();
        return $count;
    }

    /**
     * How many mocked responses the digest has not used yet (one is used per webhook it posts to).
     *
     * @return int
     */
    private function unused_posts(): int {
        $prop = new \ReflectionProperty(\curl::class, 'mockresponses');
        $prop->setAccessible(true);
        return count($prop->getValue());
    }

    private function drain_posts(): void {
        $prop = new \ReflectionProperty(\curl::class, 'mockresponses');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    protected function tearDown(): void {
        $this->drain_posts();
        parent::tearDown();
    }

    public function test_with_no_destination_the_digest_falls_back_to_the_admin_email(): void {
        $this->make_the_digest_alert();
        $this->assertSame(1, $this->run_digest(), 'the control: this harness sees the fallback email');
    }

    public function test_the_slack_url_is_posted_to_decrypted(): void {
        $this->make_the_digest_alert();
        set_config('anomaly_digest_slack_webhook', secrets::conceal(self::SLACK), 'local_ai_course_assistant');
        \curl::mock_response('ok');

        $emails = $this->run_digest();

        $this->assertSame(0, $this->unused_posts(), 'the digest posted to the Slack URL');
        $this->assertSame(0, $emails, 'it was delivered, so nothing fell back to the admin email');
    }

    public function test_the_teams_url_is_posted_to_decrypted(): void {
        $this->make_the_digest_alert();
        set_config('anomaly_digest_teams_webhook', secrets::conceal(self::TEAMS), 'local_ai_course_assistant');
        \curl::mock_response('ok');

        $emails = $this->run_digest();

        $this->assertSame(0, $this->unused_posts(), 'the digest posted to the Teams URL');
        $this->assertSame(0, $emails);
    }

    public function test_both_urls_are_used_when_both_are_saved(): void {
        $this->make_the_digest_alert();
        set_config('anomaly_digest_slack_webhook', secrets::conceal(self::SLACK), 'local_ai_course_assistant');
        set_config('anomaly_digest_teams_webhook', secrets::conceal(self::TEAMS), 'local_ai_course_assistant');
        \curl::mock_response('ok');
        \curl::mock_response('ok');

        $emails = $this->run_digest();

        $this->assertSame(0, $this->unused_posts(), 'one post each to Slack and Teams');
        $this->assertSame(0, $emails);
    }

    public function test_a_url_that_will_not_decrypt_is_never_posted_to(): void {
        $this->make_the_digest_alert();
        // A database restored on another site: the stored value is ciphertext this site can't open.
        set_config('anomaly_digest_slack_webhook', 'sodium:' . base64_encode(random_bytes(80)), 'local_ai_course_assistant');
        \curl::mock_response('ok');

        $emails = $this->run_digest();

        // The notice is the decrypt one from secrets::get(), not the address check rejecting ciphertext.
        $messages = $this->getDebuggingMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('could not be decrypted', $messages[0]->message);
        $this->resetDebugging();
        $this->assertSame(1, $this->unused_posts(), 'nothing was posted: the value is treated as unset');
        $this->assertSame(1, $emails, 'so the digest fell back to the admin email');
    }

    /**
     * A URL saved before this release is plain text. It still works before the upgrade has run, the upgrade
     * encrypts it, and the digest then posts to the decrypted URL.
     */
    public function test_a_plain_url_saved_before_the_upgrade_is_encrypted_and_still_delivered(): void {
        global $DB;
        $this->make_the_digest_alert();
        set_config('anomaly_digest_slack_webhook', self::SLACK, 'local_ai_course_assistant');
        set_config('anomaly_digest_teams_webhook', self::TEAMS, 'local_ai_course_assistant');
        $raw = fn(string $n) => (string) $DB->get_field('config_plugins', 'value',
            ['plugin' => 'local_ai_course_assistant', 'name' => $n]);

        // Between the deploy and the upgrade step the plain value is still read as it is.
        \curl::mock_response('ok');
        \curl::mock_response('ok');
        $this->assertSame(0, $this->run_digest());
        $this->assertSame(0, $this->unused_posts());

        $this->assertSame(2, secrets::encrypt_stored_settings(), 'both URLs were encrypted');
        foreach (['anomaly_digest_slack_webhook', 'anomaly_digest_teams_webhook'] as $name) {
            $this->assertTrue(secrets::is_encrypted($raw($name)), $name . ' is ciphertext now');
            $this->assertStringNotContainsString('SENTINEL', $raw($name));
        }
        $this->assertSame(self::SLACK, secrets::get('anomaly_digest_slack_webhook'));
        $this->assertSame(self::TEAMS, secrets::get('anomaly_digest_teams_webhook'));

        // After the upgrade the digest posts to the decrypted URLs, not to the ciphertext.
        \curl::mock_response('ok');
        \curl::mock_response('ok');
        $this->assertSame(0, $this->run_digest());
        $this->assertSame(0, $this->unused_posts());

        $this->assertSame(0, secrets::encrypt_stored_settings(), 'a second upgrade run changes nothing');
    }
}
