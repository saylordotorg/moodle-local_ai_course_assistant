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

use local_ai_course_assistant\external\get_realtime_token;

/**
 * The three paid voice entry points, driven as the learner they were written for.
 *
 * WHY THIS FILE EXISTS. Three capability defects reached a release in one day.
 * Each of them made a learner-facing feature read its data through a gate no
 * student holds, so the feature was invisible to learners and perfect for
 * everybody who checked it. None of the ~200 test files noticed, because every
 * one of them would have passed unchanged running as a teacher or an
 * administrator: they asserted refusals, or declared shapes, and never once
 * drove the feature with real data AS a plain enrolled student.
 *
 * Voice carries that blind spot with money attached. `get_realtime_token` mints
 * a live OpenAI Realtime credential worth roughly $18/hour of held session, and
 * `transcribe.php` and `tts.php` proxy per-minute and per-character spend. The
 * existing voice suite is substantial and still leaves the two things that
 * matter most unpinned:
 *
 *  - The mint's `require_capability('local/ai_course_assistant:use', ...)` is
 *    pinned by nothing. The test that looks like it does -- an unenrolled user
 *    being rejected -- passes because `validate_context()` calls
 *    `require_login($course)` and throws BEFORE the capability line is reached.
 *    Delete that line, or swap it for a staff-only capability, and the whole
 *    suite stays green while voice goes invisible to every learner on the site.
 *  - `realtime_mint` (6 per 300s) appears exactly once in the tree: its own call
 *    site. Nothing pins that the one paid endpoint with no spend accounting is
 *    throttled at all, and nothing pins that the bucket is keyed on the learner
 *    rather than shared across the site.
 *
 * So every test here asserts FIRST that the learner holds none of the staff
 * capabilities that would make the run meaningless -- viewanalytics, manage,
 * course:update, course:viewhiddenactivities, site:config -- and DOES hold
 * local/ai_course_assistant:use. A red test then reads as "the learner was
 * refused", not as "the learner was never entitled in the first place".
 *
 * Beyond the gates it pins `mode_block()`, which was entirely untested: no
 * existing test passes a mode, topic or phrase, so ELL pronunciation coaching
 * and the current-page topic fallback both shipped able to collapse silently
 * into an ordinary icebreaker for every learner who selected them.
 *
 * NO NETWORK, NO KEY. Every mint here runs the xAI branch, which builds its
 * HS256 JWT in process and never leaves the server; `proxy.example.com` is
 * allowlisted via `ssrf_trusted_endpoints` so nothing has to resolve.
 *
 * ONE HONEST LIMIT, stated rather than worked around. `transcribe.php` and
 * `tts.php` cannot be entered from PHPUnit: each declares AJAX_SCRIPT,
 * re-requires config.php, terminates every refusal with a bare `exit`, and
 * gates on `is_uploaded_file()`, which is false under CLI for any file a test
 * could create. So their guarantees are split in two. The half that IS a unit
 * -- per-learner throttling at the exact bucket and limit the endpoint passes,
 * and the audio size constant -- is asserted for real against `rate_limiter`
 * and `security`. The half that is "the endpoint actually applies it, in the
 * right order, before any provider call" is asserted as ordered `strpos()`
 * offsets over the endpoint source read through `$CFG->dirroot`, which is the
 * deployed tree a mutation lands in. That is the pattern
 * tests/spend_attribution_test.php already established for transcribe.php.
 *
 * Those source needles are whole guard BLOCKS, `exit` included, and that is
 * deliberate. Mutation proof showed a needle that stopped at the condition is
 * worse than useless here: deleting just the `exit;` from the oversize guard
 * and from the non-audio-200 guard left every asserted fragment in place and
 * the file stayed green, while a throttled learner's clip went to the provider
 * and got billed and the provider's JSON refusal reached the learner
 * base64-encoded as "audio". A bare `exit;` needle looked up by offset was no
 * better: with the guard's own exit gone it simply matched the NEXT guard's
 * exit further down the file and reported the dead guard as intact. Only the
 * whole block distinguishes a guard that refuses from one that merely writes a
 * refusal and carries on.
 *
 * It still proves the guard is present, complete and correctly ordered, not
 * that it fires on a real request. The durable fix is to lift both guard
 * blocks into a testable helper, which is a change to non-test files and out
 * of scope here. The cost of the block needles is that an innocuous reword of
 * an error string turns this file red; that is the intended trade, since the
 * alternative failed to notice a guard being switched off.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\get_realtime_token
 * @covers     \local_ai_course_assistant\rate_limiter
 */
final class voice_learner_journey_test extends \advanced_testcase {
    /**
     * The refusal body both throttles share, exit included.
     *
     * Asserted as a whole block rather than as a bare 'exit;' needle, because
     * an offset lookup for 'exit;' after the condition matches the NEXT guard's
     * exit once this one is deleted, and so reports a throttle that no longer
     * stops anything as intact.
     */
    private const RATE_LIMIT_REFUSAL = "    http_response_code(429);\n"
        . "    header('Retry-After: 60');\n"
        . "    echo json_encode(['error' => get_string('chat:error_ratelimit', 'local_ai_course_assistant')]);\n"
        . "    exit;\n"
        . '}';

    /**
     * Clear the test-only HTTP seam so a stray stub cannot leak between files.
     *
     * @return void
     */
    protected function tearDown(): void {
        // phpcs:ignore moodle.NamingConventions.ValidVariableName.VariableNameUnderscore
        get_realtime_token::$test_http_response = null;
        parent::tearDown();
    }

    /**
     * Configure the xAI realtime path, which mints entirely in process.
     *
     * No upstream call, no key, and the proxy host is allowlisted so
     * security::is_safe_provider_url() never needs a DNS lookup. Same shape as
     * configure_voice() in realtime_token_gating_test.php.
     *
     * @return void
     */
    private function configure_voice(): void {
        set_config('voice_providers', 'xai|sk-xai-test|MyXai|alloy|alloy', 'local_ai_course_assistant');
        set_config('voice_active_realtime', 'MyXai', 'local_ai_course_assistant');
        set_config('realtime_enabled', 1, 'local_ai_course_assistant');
        set_config('xai_proxy_url', 'wss://proxy.example.com/realtime', 'local_ai_course_assistant');
        set_config('xai_proxy_jwt_secret', str_repeat('a', 64), 'local_ai_course_assistant');
        set_config('ssrf_trusted_endpoints', 'https://proxy.example.com', 'local_ai_course_assistant');
    }

    /**
     * Enrol a user as a student on a course and become them.
     *
     * @param \stdClass $course Course to enrol into.
     * @return \stdClass The learner.
     */
    private function become_a_learner_on(\stdClass $course): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        return $user;
    }

    /**
     * The learner running this test must not be able to see everybody's work.
     *
     * Without these five assertions the whole file would pass while running as
     * somebody who can administer the site, edit the course and read hidden
     * activities -- which is exactly how three defects hid on release day.
     *
     * @param int $courseid Course whose context the capabilities are read in.
     * @return void
     */
    private function assert_holds_no_staff_capability(int $courseid): void {
        $context = \context_course::instance($courseid);

        $this->assertFalse(
            has_capability('local/ai_course_assistant:viewanalytics', $context),
            'A learner must not hold the analytics capability, or this test proves nothing.'
        );
        $this->assertFalse(
            has_capability('local/ai_course_assistant:manage', $context),
            'A learner must not hold the plugin manage capability, or this test proves nothing.'
        );
        $this->assertFalse(
            has_capability('moodle/course:update', $context),
            'A learner must not be able to edit the course, or a staff-only voice gate '
                . 'would still look open from here.'
        );
        $this->assertFalse(
            has_capability('moodle/course:viewhiddenactivities', $context),
            'A learner must not be able to read hidden activities, or the grounded-prompt '
                . 'guarantees are being checked as somebody allowed to read them.'
        );
        $this->assertFalse(
            has_capability('moodle/site:config', \context_system::instance()),
            'A learner must not be a site administrator, or every gate in this file is open '
                . 'for reasons that have nothing to do with the gate.'
        );
    }

    /**
     * The person driving this test is an entitled learner, and only that.
     *
     * @param int $courseid Course whose context the capabilities are read in.
     * @return void
     */
    private function assert_this_is_an_entitled_learner(int $courseid): void {
        $this->assert_holds_no_staff_capability($courseid);
        $this->assertTrue(
            has_capability('local/ai_course_assistant:use', \context_course::instance($courseid)),
            'The learner must hold the assistant capability, or a red test below would only '
                . 'mean they were never entitled to voice in the first place.'
        );
    }

    /**
     * Prohibit the assistant capability for one user in one course.
     *
     * A dedicated role rather than an edit to 'student', so a second learner in
     * the same course keeps working and the cases stay independent.
     *
     * @param \stdClass $user User to prohibit.
     * @param \stdClass $course Course to prohibit them in.
     * @return void
     */
    private function prohibit_the_assistant_for(\stdClass $user, \stdClass $course): void {
        $context = \context_course::instance($course->id);
        $roleid = $this->getDataGenerator()->create_role();
        role_assign($roleid, $user->id, $context);
        assign_capability('local/ai_course_assistant:use', CAP_PROHIBIT, $roleid, $context, true);
    }

    /**
     * Read one of the direct voice endpoints from the DEPLOYED plugin tree.
     *
     * Via $CFG->dirroot deliberately: that is the copy a mutation lands in, so
     * a source assertion read from anywhere else would be unfalsifiable.
     *
     * @param string $file Endpoint filename, e.g. 'tts.php'.
     * @return string The endpoint source.
     */
    private function endpoint_source(string $file): string {
        global $CFG;

        $src = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/' . $file);
        $this->assertNotFalse($src, $file . ' could not be read from the deployed plugin tree.');
        return (string) $src;
    }

    /**
     * Assert each needle appears in the source strictly after the one before it.
     *
     * @param string $src Endpoint source.
     * @param array $needles Literal fragments, in the order they must appear.
     * @param string $file Filename, for the failure message.
     * @return void
     */
    private function assert_appears_in_order(string $src, array $needles, string $file): void {
        $offset = 0;
        $previous = '';
        foreach ($needles as $needle) {
            $at = strpos($src, $needle, $offset);
            $this->assertNotFalse(
                $at,
                $file . ' is missing "' . $needle . '"'
                    . ($previous === '' ? '' : ' after "' . $previous . '"')
                    . ', so the guard is either gone or runs too late to stop the spend.'
            );
            $offset = (int) $at + strlen($needle);
            $previous = $needle;
        }
    }

    /**
     * A plain enrolled student can actually start voice mode.
     *
     * The release-day defect class, applied to the most expensive endpoint in
     * the plugin: gate the mint behind a capability no student holds and voice
     * is dead for every learner on the site while working perfectly for the
     * teacher and the admin who tested it. Every currently passing mint test
     * runs as an administrator, or as a student whose entitlement is never
     * asserted, so nothing would notice. If this breaks, a learner presses the
     * Voice tab and gets an authorisation error instead of a conversation.
     *
     * @return void
     */
    public function test_a_plain_learner_can_mint_a_voice_credential(): void {
        $this->resetAfterTest();
        $this->configure_voice();

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->become_a_learner_on($course);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $result = get_realtime_token::execute((int) $course->id);

        $this->assertSame('xai', $result['provider']);
        $this->assertNotSame('', $result['endpoint'], 'The learner was handed no voice endpoint at all.');
        $this->assertStringContainsString(
            'token=',
            $result['endpoint'],
            'The endpoint must carry the minted credential, or the browser has nothing to connect with.'
        );

        // The credential is this learner's, in this course -- not a shared one.
        parse_str((string) parse_url($result['endpoint'], PHP_URL_QUERY), $qs);
        $parts = explode('.', (string) ($qs['token'] ?? ''));
        $this->assertCount(3, $parts, 'The endpoint must carry a three-part JWT.');
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $this->assertSame((int) $learner->id, (int) $payload['sub']);
        $this->assertSame((int) $course->id, (int) $payload['courseid']);
    }

    /**
     * A learner the site has switched the assistant off for mints nothing.
     *
     * They are enrolled, so this isolates the capability gate from the login
     * gate -- which is the whole point: the existing "unenrolled user is
     * rejected" test throws inside validate_context()'s require_login() before
     * the capability line is ever reached, so that line can be deleted with the
     * suite still green. If this breaks, a learner an administrator
     * deliberately excluded from the assistant can still POST to the endpoint
     * and hold an $18/hour session.
     *
     * @return void
     */
    public function test_a_learner_without_the_use_capability_is_refused_the_mint(): void {
        $this->resetAfterTest();
        $this->configure_voice();

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->become_a_learner_on($course);
        $this->prohibit_the_assistant_for($learner, $course);

        $this->assert_holds_no_staff_capability((int) $course->id);
        $this->assertFalse(
            has_capability('local/ai_course_assistant:use', \context_course::instance($course->id)),
            'The prohibition did not take effect, so this test would prove nothing.'
        );

        try {
            $result = get_realtime_token::execute((int) $course->id);
            $this->fail('A learner without the assistant capability was handed a voice credential: '
                . json_encode($result));
        } catch (\required_capability_exception $e) {
            $this->assertSame(
                get_capability_string('local/ai_course_assistant:use'),
                $e->a,
                'Refused, but for some capability other than the assistant one.'
            );
        }
    }

    /**
     * The authorisation refusal comes before the feature-state refusal.
     *
     * An unauthorised caller must not learn whether voice is switched on for
     * this site. If the order flips, someone with no entitlement probes the
     * endpoint and is told "Voice mode is disabled on this site" -- a different
     * answer from the one an entitled learner gets, which is exactly the
     * feature-state disclosure the ordering comment in the endpoint promises to
     * prevent. The existing gating tests never notice, because they only run
     * the switch case as an entitled learner.
     *
     * @return void
     */
    public function test_the_capability_refusal_comes_before_the_feature_state_refusal(): void {
        $this->resetAfterTest();
        $this->configure_voice();
        set_config('realtime_enabled', 0, 'local_ai_course_assistant');

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->become_a_learner_on($course);
        $this->prohibit_the_assistant_for($learner, $course);
        $this->assert_holds_no_staff_capability((int) $course->id);
        $this->assertFalse(has_capability('local/ai_course_assistant:use', \context_course::instance($course->id)));

        try {
            get_realtime_token::execute((int) $course->id);
            $this->fail('An unauthorised caller was served by an endpoint that is switched off.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('local/ai_course_assistant:use'), $e->a);
        } catch (\moodle_exception $e) {
            $this->fail(
                'The unauthorised caller was told the feature state instead of being refused: "'
                    . $e->getMessage() . '".'
            );
        }
    }

    /**
     * The mint is throttled, so a loop cannot open sessions without limit.
     *
     * Each mint opens a paid realtime session and this endpoint writes no usage
     * row, so it is the one paid surface with neither a request limit nor spend
     * accounting. 'realtime_mint' appears exactly once in the whole tree -- its
     * own call site -- so nothing pins the ceiling. If it breaks, one learner
     * (or one stuck reconnect loop in the browser) bills the institution for
     * unbounded concurrent voice sessions and nobody finds out until invoicing.
     *
     * @return void
     */
    public function test_the_realtime_mint_is_throttled(): void {
        $this->resetAfterTest();
        $this->configure_voice();

        $course = $this->getDataGenerator()->create_course();
        $this->become_a_learner_on($course);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        for ($i = 1; $i <= 6; $i++) {
            $allowed = get_realtime_token::execute((int) $course->id);
            $this->assertNotSame(
                '',
                $allowed['endpoint'],
                'Mint ' . $i . ' of the allowed six was refused; the ceiling is too tight to reconnect.'
            );
        }

        try {
            $seventh = get_realtime_token::execute((int) $course->id);
            $this->fail('The seventh mint inside the window was allowed: ' . json_encode($seventh));
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(
                get_string('soapbox:rate_limited', 'local_ai_course_assistant'),
                $e->getMessage(),
                'The seventh mint was refused, but not as a rate limit.'
            );
        }
    }

    /**
     * The mint ceiling is per learner, not shared across the site.
     *
     * A shared bucket reads as a working rate limit in any test that uses one
     * user, and lets a single learner deny voice mode to everybody else in the
     * institution six calls after they start. If this breaks, a learner presses
     * the Voice tab for the first time all day and is told they have made too
     * many requests.
     *
     * @return void
     */
    public function test_the_mint_ceiling_is_per_learner(): void {
        $this->resetAfterTest();
        $this->configure_voice();

        $course = $this->getDataGenerator()->create_course();

        $heavy = $this->become_a_learner_on($course);
        $this->assert_this_is_an_entitled_learner((int) $course->id);
        for ($i = 0; $i < 6; $i++) {
            get_realtime_token::execute((int) $course->id);
        }

        $second = $this->become_a_learner_on($course);
        $this->assertNotSame((int) $heavy->id, (int) $second->id);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $result = get_realtime_token::execute((int) $course->id);

        $this->assertNotSame(
            '',
            $result['endpoint'],
            'The second learner\'s first mint of the day was refused because of somebody else\'s mints.'
        );
        parse_str((string) parse_url($result['endpoint'], PHP_URL_QUERY), $qs);
        $parts = explode('.', (string) ($qs['token'] ?? ''));
        $payload = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
        $this->assertSame(
            (int) $second->id,
            (int) ($payload['sub'] ?? 0),
            'The second learner was handed a credential minted for somebody else.'
        );
    }

    /**
     * A learner who asks for pronunciation practice is actually coached.
     *
     * mode_block() is entirely untested: no test anywhere passes a mode, a
     * topic or a phrase. If ELL mode collapses into ordinary conversation, the
     * learner who picked "Practice Speaking" and typed a phrase gets a generic
     * chat about the course instead of hearing the phrase said and being asked
     * to repeat it -- and nothing fails, because the session still works.
     *
     * @return void
     */
    public function test_ell_mode_coaches_the_phrase_the_learner_chose(): void {
        $this->resetAfterTest();
        $this->configure_voice();

        $course = $this->getDataGenerator()->create_course();
        $this->become_a_learner_on($course);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $phrase = 'thorough entrepreneurial rehearsal';
        $ell = get_realtime_token::execute((int) $course->id, 0, '', '', 'ell', '', $phrase);

        $this->assertStringContainsString(
            'ELL Coaching Mode',
            $ell['instructions'],
            'A learner who chose pronunciation practice was given ordinary conversation instructions.'
        );
        $this->assertStringContainsString(
            $phrase,
            $ell['instructions'],
            'The coaching block does not name the phrase the learner asked to practise.'
        );

        // Negative control: ordinary conversation must NOT carry the coaching
        // block, or the positive assertion above would hold even if mode were
        // ignored entirely.
        $chat = get_realtime_token::execute((int) $course->id, 0, '', '', 'conversation', '', '');
        $this->assertStringNotContainsString(
            'ELL Coaching Mode',
            $chat['instructions'],
            'A learner in ordinary conversation is being corrected as a language student.'
        );
    }

    /**
     * With no topic chosen, the page the learner is reading becomes the topic.
     *
     * If the fallback goes, the model is handed "no specific topic is set" and
     * opens by asking the learner which course topic they want -- on a page the
     * learner is already looking at. The existing pagetitle test passes through
     * a different line entirely (the "currently on the page titled" sentence)
     * and stays green when this is removed, so it pins nothing here.
     *
     * @return void
     */
    public function test_the_current_page_becomes_the_topic_when_the_learner_picked_none(): void {
        $this->resetAfterTest();
        $this->configure_voice();

        $course = $this->getDataGenerator()->create_course();
        $this->become_a_learner_on($course);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        // A pageid of 0 deliberately: that suppresses the separate "currently on the
        // page titled" sentence, so only the topic fallback can satisfy this.
        $pagetitle = 'Amortisation Schedules';
        $result = get_realtime_token::execute((int) $course->id, 0, $pagetitle, '', 'conversation', '', '');

        $this->assertStringContainsString(
            'Topic for this session: "' . $pagetitle . '".',
            $result['instructions'],
            'The page the learner is reading did not become the session topic.'
        );
        $this->assertStringNotContainsString(
            'If no specific topic is set',
            $result['instructions'],
            'The session opened topicless on a page the learner is already reading.'
        );

        // Positive control: an explicit topic still wins over the page title.
        $chosen = get_realtime_token::execute((int) $course->id, 0, $pagetitle, '', 'conversation', 'Bond yields', '');
        $this->assertStringContainsString('Topic for this session: "Bond yields".', $chosen['instructions']);
    }

    /**
     * Both direct voice endpoints refuse an unentitled or cross-site caller.
     *
     * Nothing anywhere asserts that transcribe.php or tts.php has a capability
     * check at all. If either gate goes, any logged-in user on the site --
     * including one an administrator deliberately excluded from the assistant
     * -- can spend the institution's Whisper and TTS budget; if either sesskey
     * check goes, a page on another site can spend a learner's budget while
     * they are logged in, without their knowledge. Ordered source offsets,
     * because these scripts cannot be entered from PHPUnit; see the class
     * docblock for why that limit is stated rather than faked.
     *
     * @return void
     */
    public function test_both_direct_voice_endpoints_gate_before_they_spend(): void {
        $guards = [
            'require_login();',
            'require_sesskey();',
            'require_capability(\'local/ai_course_assistant:use\', $context);',
            'voice_registry::resolve(',
            'new \curl()',
        ];

        $this->assert_appears_in_order($this->endpoint_source('transcribe.php'), $guards, 'transcribe.php');
        $this->assert_appears_in_order($this->endpoint_source('tts.php'), $guards, 'tts.php');
    }

    /**
     * Transcription is throttled at twenty a minute, per learner.
     *
     * A globally keyed bucket still looks like a working rate limit in any test
     * that uses one user, while letting one learner's stuck recorder lock
     * transcription for everybody on the site. If this breaks, a learner
     * presses the microphone for the first time and is told to wait a minute.
     *
     * @return void
     */
    public function test_the_stt_bucket_is_twenty_a_minute_per_learner(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $heavy = $this->become_a_learner_on($course);
        $second = $this->become_a_learner_on($course);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        // The exact bucket name and limit pair transcribe.php passes.
        for ($i = 1; $i <= 20; $i++) {
            $this->assertFalse(
                rate_limiter::is_rate_limited((int) $heavy->id, 'stt', 20, 60),
                'Clip ' . $i . ' of the allowed twenty was refused.'
            );
        }
        $this->assertTrue(
            rate_limiter::is_rate_limited((int) $heavy->id, 'stt', 20, 60),
            'The twenty-first clip inside the window was allowed through.'
        );
        $this->assertFalse(
            rate_limiter::is_rate_limited((int) $second->id, 'stt', 20, 60),
            'A second learner\'s first clip was refused because of somebody else\'s uploads.'
        );

        // And the endpoint keys that bucket on the caller, not on a constant,
        // and the refusal actually terminates before the paid call. A guard
        // whose body no longer exits still reads as present to any assertion
        // that only looks for the condition, while every throttled clip is
        // transcribed and billed exactly as if there were no limit at all.
        $this->assert_appears_in_order(
            $this->endpoint_source('transcribe.php'),
            [
                "if (\\local_ai_course_assistant\\rate_limiter::is_rate_limited(\$USER->id, 'stt', 20, 60)) {\n"
                    . self::RATE_LIMIT_REFUSAL,
                'new \curl()',
            ],
            'transcribe.php'
        );
    }

    /**
     * Speech playback is throttled at thirty a minute, per learner.
     *
     * Same failure with a different budget: TTS is a per-character spend
     * vector. Shared, one learner replaying answers all afternoon silences the
     * assistant's voice for everybody else.
     *
     * @return void
     */
    public function test_the_tts_bucket_is_thirty_a_minute_per_learner(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $heavy = $this->become_a_learner_on($course);
        $second = $this->become_a_learner_on($course);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        for ($i = 1; $i <= 30; $i++) {
            $this->assertFalse(
                rate_limiter::is_rate_limited((int) $heavy->id, 'tts', 30, 60),
                'Playback ' . $i . ' of the allowed thirty was refused.'
            );
        }
        $this->assertTrue(
            rate_limiter::is_rate_limited((int) $heavy->id, 'tts', 30, 60),
            'The thirty-first playback inside the window was allowed through.'
        );
        $this->assertFalse(
            rate_limiter::is_rate_limited((int) $second->id, 'tts', 30, 60),
            'A second learner\'s first playback was refused because of somebody else\'s playbacks.'
        );

        $this->assert_appears_in_order(
            $this->endpoint_source('tts.php'),
            [
                "if (\\local_ai_course_assistant\\rate_limiter::is_rate_limited(\$USER->id, 'tts', 30, 60)) {\n"
                    . self::RATE_LIMIT_REFUSAL,
                'new \curl()',
            ],
            'tts.php'
        );
    }

    /**
     * An empty or oversized recording is refused before it reaches a provider.
     *
     * MAX_AUDIO_BYTES has zero test references anywhere in the tree. The
     * `$size <= 0` half is the absent-value case that matters: filesize()
     * returns 0 or false for an unreadable or empty temp file, and a zero must
     * be refused rather than treated as a valid very small clip. If this
     * breaks, a learner whose recording failed silently uploads nothing, waits
     * for a 30-second provider round trip, and gets an empty transcript -- and
     * a 25 MB clip is paid for in full before anyone looks at it.
     *
     * @return void
     */
    public function test_transcription_refuses_an_empty_or_oversized_recording(): void {
        $this->assertSame(
            25 * 1024 * 1024,
            security::MAX_AUDIO_BYTES,
            'The audio upload cap moved; 25 MB is what the endpoint documents and bills against.'
        );

        $this->assert_appears_in_order(
            $this->endpoint_source('transcribe.php'),
            [
                "if (\$size <= 0 || \$size > \\local_ai_course_assistant\\security::MAX_AUDIO_BYTES) {\n"
                    . "    http_response_code(413);\n"
                    . "    echo json_encode(['error' => 'Audio file too large.']);\n"
                    . "    exit;\n"
                    . '}',
                'new \curl()',
            ],
            'transcribe.php'
        );
    }

    /**
     * A 200 that is not audio is refused, not handed to the learner as audio.
     *
     * Providers and proxies answer a moderation refusal or an internal error
     * with HTTP 200 and a JSON body. Base64-encoding that gives the learner
     * "audio" that decodes to silence, so the browser falls back to the robotic
     * system voice with no diagnostic anywhere and the failure is invisible in
     * logs. Mp3 never starts with '{', which is what the guard keys on.
     *
     * @return void
     */
    public function test_speech_playback_refuses_a_two_hundred_that_is_not_audio(): void {
        $this->assert_appears_in_order(
            $this->endpoint_source('tts.php'),
            [
                'new \curl()',
                // The whole block, not just its condition. The exit IS the
                // guard: delete it and the condition, the 502 and the error
                // body all survive while the provider's JSON refusal is still
                // handed to the learner base64-encoded as "audio". A needle
                // that stops at the condition cannot tell those two apart,
                // and neither can a later 'exit;' looked up by offset, which
                // happily matches some other guard's exit further down.
                "if (\$response === false || \$response === '' || \$response[0] === '{') {\n"
                    . "    http_response_code(502);\n"
                    . "    echo json_encode(['error' => 'TTS provider returned no audio']);\n"
                    . "    exit;\n"
                    . '}',
                'base64_encode($response)',
            ],
            'tts.php'
        );
    }
}
