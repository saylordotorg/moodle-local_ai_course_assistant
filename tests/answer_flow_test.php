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

/**
 * Guidance-rule enforcement on the chat path (8.4.0 Task 5): what sse.php decides, and where it decides it.
 *
 * sse.php is a script a test can't load, so the decisions live in answer_flow (plan before the model call, finalize
 * after it) and answer_hold (keepalives while a risky answer is held), and the call sites are pinned from the source.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\answer_flow
 * @covers     \local_ai_course_assistant\answer_hold
 */
final class answer_flow_test extends \advanced_testcase {

    /** @var string A question in the grades category (risky). */
    private const GRADE_Q = 'What grade will I get on the final?';

    /** @var string A question in no category. */
    private const PLAIN_Q = 'Can you explain photosynthesis?';

    /** @var string A reply the default pack blocks (grades.guarantee). */
    private const BLOCKED = 'Do not worry. I guarantee you will pass the course.';

    /** @var string A reply the default pack warns on (advice.medical_legal_financial). */
    private const WARNED = 'Honestly, you should sue your landlord over this.';

    /** @var string A reply the default pack only watches (grades.predicted). */
    private const WATCHED = 'With that effort you will definitely pass.';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        answer_flow::$beforefinalize = null;
        answer_flow::$spendcheck = null;
    }

    protected function tearDown(): void {
        answer_flow::$beforefinalize = null;
        answer_flow::$spendcheck = null;
        parent::tearDown();
    }

    /**
     * Set the site mode.
     *
     * @param string $mode
     * @return void
     */
    private function mode(string $mode): void {
        set_config('answer_safety_mode', $mode, 'local_ai_course_assistant');
    }

    /**
     * A site rule whose action is correct, on the reply.
     *
     * @return void
     */
    private function correct_rule(): void {
        set_config('answer_safety_rules', json_encode([[
            'id' => 'site.no_zork', 'category' => 'grades', 'kind' => 'keyword', 'value' => 'zorkmid',
            'applies_to' => 'reply', 'action' => 'correct',
        ]]), 'local_ai_course_assistant');
    }

    // Plan: categorise the question before the model call; hold only when risky and enforcing.

    public function test_plan_off_does_nothing(): void {
        $this->mode('off');
        $plan = answer_flow::plan(self::GRADE_Q, 0);
        $this->assertSame('off', $plan['mode']);
        $this->assertSame([], $plan['categories'], 'off: nothing new happens, not even categorising');
        $this->assertFalse($plan['risky']);
        $this->assertFalse($plan['hold']);
    }

    public function test_plan_watch_categorises_and_never_holds(): void {
        $this->mode('watch');
        $plan = answer_flow::plan(self::GRADE_Q, 0);
        $this->assertSame('watch', $plan['mode']);
        $this->assertContains('grades', $plan['categories']);
        $this->assertTrue($plan['risky']);
        $this->assertFalse($plan['hold'], 'watch never changes what the learner sees, so it never holds');
    }

    public function test_plan_enforce_holds_only_a_risky_question(): void {
        $this->mode('enforce');
        $this->assertTrue(answer_flow::plan(self::GRADE_Q, 0)['hold']);
        $plain = answer_flow::plan(self::PLAIN_Q, 0);
        $this->assertSame([], $plain['categories']);
        $this->assertFalse($plain['risky']);
        $this->assertFalse($plain['hold'], 'an ordinary question streams as today');
    }

    public function test_plan_reads_the_course_override(): void {
        $this->mode('off');
        $course = $this->getDataGenerator()->create_course();
        course_config_manager::set_answer_safety_mode((int) $course->id, 'enforce');
        $this->assertTrue(answer_flow::plan(self::GRADE_Q, (int) $course->id)['hold']);
        $this->assertFalse(answer_flow::plan(self::GRADE_Q, 0)['hold']);
    }

    // Finalize: evaluate the answer, then deliver, warn, correct or block.

    public function test_finalize_off_returns_the_reply_untouched(): void {
        $this->mode('off');
        $plan = answer_flow::plan(self::GRADE_Q, 0);
        $out = answer_flow::finalize(self::BLOCKED, $plan, ['question' => self::GRADE_Q]);
        $this->assertSame(self::BLOCKED, $out['text']);
        $this->assertSame('none', $out['action']);
        $this->assertSame([], $out['rules']);
        $this->assertFalse($out['replace']);
    }

    public function test_watch_records_rules_and_never_changes_text(): void {
        $this->mode('watch');
        foreach ([self::BLOCKED, self::WARNED] as $reply) {
            $out = answer_flow::finalize($reply, answer_flow::plan(self::GRADE_Q, 0), ['question' => self::GRADE_Q]);
            $this->assertSame($reply, $out['text']);
            $this->assertSame('watch', $out['action']);
            $this->assertFalse($out['replace']);
            $this->assertNotEmpty($out['rules'], 'the rules fired are recorded for the audit row');
        }
        $out = answer_flow::finalize(self::BLOCKED, answer_flow::plan(self::GRADE_Q, 0), ['question' => self::GRADE_Q]);
        $this->assertContains(['id' => 'grades.guarantee', 'action' => 'block'], $out['rules']);
        $this->assertContains('grades', $out['categories']);
    }

    public function test_enforce_block_replaces_with_the_safe_message(): void {
        $this->mode('enforce');
        $out = answer_flow::finalize(self::BLOCKED, answer_flow::plan(self::PLAIN_Q, 0),
            ['question' => self::PLAIN_Q, 'buffered' => false]);
        $this->assertSame('block', $out['action']);
        $this->assertSame(answer_rules::safe_message('grades'), $out['text']);
        $this->assertTrue($out['replace']);
        $this->assertSame('grades.guarantee', $out['reason']);
        $this->assertSame([['id' => 'grades.guarantee', 'action' => 'block']], $out['rules']);
    }

    public function test_enforce_warn_appends_the_check_line(): void {
        $this->mode('enforce');
        $out = answer_flow::finalize(self::WARNED, answer_flow::plan(self::PLAIN_Q, 0),
            ['question' => self::PLAIN_Q, 'buffered' => false]);
        $this->assertSame('warn', $out['action']);
        $this->assertStringStartsWith(self::WARNED, $out['text']);
        $this->assertStringContainsString(get_string('answer_rules:warn_line', 'local_ai_course_assistant'), $out['text']);
        $this->assertTrue($out['replace'], 'a streamed answer gets a replace with the line added');
        $this->assertSame('advice.medical_legal_financial', $out['reason']);
    }

    public function test_a_watch_action_in_enforce_delivers_unchanged_and_records(): void {
        $this->mode('enforce');
        $out = answer_flow::finalize(self::WATCHED, answer_flow::plan(self::PLAIN_Q, 0), ['question' => self::PLAIN_Q]);
        $this->assertSame('watch', $out['action']);
        $this->assertSame(self::WATCHED, $out['text']);
        $this->assertFalse($out['replace']);
        $this->assertSame([['id' => 'grades.predicted', 'action' => 'watch']], $out['rules']);
    }

    public function test_a_clean_answer_is_delivered_unchanged(): void {
        $this->mode('enforce');
        $out = answer_flow::finalize('Photosynthesis turns light into sugar.', answer_flow::plan(self::GRADE_Q, 0),
            ['question' => self::GRADE_Q, 'buffered' => true]);
        $this->assertSame('none', $out['action']);
        $this->assertSame('Photosynthesis turns light into sugar.', $out['text']);
        $this->assertFalse($out['replace']);
        $this->assertContains('grades', $out['categories']);
    }

    public function test_wellbeing_question_is_blocked_whatever_the_answer(): void {
        $this->mode('enforce');
        $q = 'I want to kill myself';
        $plan = answer_flow::plan($q, 0);
        $this->assertTrue($plan['hold']);
        $out = answer_flow::finalize('Here is a study plan.', $plan, ['question' => $q, 'buffered' => true]);
        $this->assertSame('block', $out['action']);
        $this->assertSame(answer_rules::safe_message('wellbeing'), $out['text']);
        $this->assertSame(answer_rules::WELLBEING_RULE, $out['reason']);
    }

    public function test_correct_retries_once_and_delivers_a_passing_retry(): void {
        $this->mode('enforce');
        $this->correct_rule();
        $calls = [];
        $retry = function (string $instruction) use (&$calls): string {
            $calls[] = $instruction;
            return 'Ask your instructor about your grade.';
        };
        $reply = 'You will get 500 zorkmid points.';
        $out = answer_flow::finalize($reply, answer_flow::plan(self::GRADE_Q, 0),
            ['question' => self::GRADE_Q, 'buffered' => true], $retry);
        $this->assertCount(1, $calls, 'exactly one retry');
        $this->assertStringContainsString('site.no_zork', $calls[0], 'the failure is stated to the model');
        $this->assertStringNotContainsString('zorkmid', $calls[0], 'the rule text itself is not sent');
        $this->assertSame('correct', $out['action']);
        $this->assertSame('Ask your instructor about your grade.', $out['text']);
        $this->assertTrue($out['replace']);
        $this->assertTrue($out['retried']);
        $this->assertSame('site.no_zork', $out['reason']);
        $this->assertContains(['id' => 'site.no_zork', 'action' => 'correct'], $out['rules']);
    }

    public function test_correct_blocks_when_the_retry_still_fails(): void {
        $this->mode('enforce');
        $this->correct_rule();
        $calls = 0;
        $retry = function () use (&$calls): string {
            $calls++;
            return 'Still 500 zorkmid points.';
        };
        $out = answer_flow::finalize('You get 500 zorkmid.', answer_flow::plan(self::GRADE_Q, 0),
            ['question' => self::GRADE_Q, 'buffered' => true], $retry);
        $this->assertSame(1, $calls, 'one retry, never a second');
        $this->assertSame('block', $out['action']);
        $this->assertSame(answer_rules::safe_message('grades'), $out['text']);
        $this->assertTrue($out['replace']);
    }

    public function test_correct_blocks_when_the_retry_throws_or_is_missing(): void {
        $this->mode('enforce');
        $this->correct_rule();
        $plan = answer_flow::plan(self::GRADE_Q, 0);
        $boom = function (): string {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'provider down');
        };
        $out = answer_flow::finalize('You get 500 zorkmid.', $plan, ['question' => self::GRADE_Q, 'buffered' => true],
            $boom);
        $this->assertSame('block', $out['action']);
        $this->assertSame(answer_rules::safe_message('grades'), $out['text']);
        $this->assertStringEndsWith('moodle_exception', (string) $out['error']);

        $out = answer_flow::finalize('You get 500 zorkmid.', $plan, ['question' => self::GRADE_Q, 'buffered' => true]);
        $this->assertSame('block', $out['action'], 'a held answer with no retry path is blocked, not delivered');
    }

    public function test_correct_on_a_streamed_answer_warns_and_never_retries(): void {
        $this->mode('enforce');
        $this->correct_rule();
        $calls = 0;
        $retry = function () use (&$calls): string {
            $calls++;
            return 'x';
        };
        $out = answer_flow::finalize('You get 500 zorkmid.', answer_flow::plan(self::PLAIN_Q, 0),
            ['question' => self::PLAIN_Q, 'buffered' => false], $retry);
        $this->assertSame(0, $calls);
        $this->assertSame('warn', $out['action']);
        $this->assertStringStartsWith('You get 500 zorkmid.', $out['text']);
    }

    public function test_any_exception_delivers_the_original_reply_with_an_error(): void {
        $this->mode('enforce');
        answer_flow::$beforefinalize = function (): void {
            throw new \RuntimeException('planted');
        };
        $out = answer_flow::finalize(self::BLOCKED, answer_flow::plan(self::PLAIN_Q, 0), ['question' => self::PLAIN_Q]);
        $this->assertSame(self::BLOCKED, $out['text'], 'a failure in the new code never changes the answer');
        $this->assertSame('none', $out['action']);
        $this->assertFalse($out['replace']);
        $this->assertSame('RuntimeException', $out['error']);
    }

    public function test_a_rules_engine_error_is_reported_and_the_answer_delivered(): void {
        $this->mode('enforce');
        answer_rules::$beforerules = function (): void {
            throw new \RuntimeException('engine');
        };
        try {
            $out = answer_flow::finalize(self::BLOCKED, answer_flow::plan(self::PLAIN_Q, 0),
                ['question' => self::PLAIN_Q]);
        } finally {
            answer_rules::$beforerules = null;
        }
        $this->assertSame(self::BLOCKED, $out['text']);
        $this->assertFalse($out['replace']);
        $this->assertSame('RuntimeException', $out['error']);
    }

    public function test_validator_results_still_fold_in(): void {
        $this->mode('enforce');
        set_config('validators_runtime_mode', 'block', 'local_ai_course_assistant');
        $results = [['validator' => 'pii_echo', 'severity' => 'fail', 'message' => 'x']];
        $out = answer_flow::finalize('Fine answer.', answer_flow::plan(self::PLAIN_Q, 0),
            ['question' => self::PLAIN_Q, 'validator_results' => $results]);
        $this->assertSame('block', $out['action'], 'the stricter of the validators and the rules wins');
        $this->assertContains(['id' => 'validator.pii_echo', 'action' => 'block'], $out['rules']);
    }

    // What a held answer releases (shared by the main reply and the correct retry).

    public function test_release_keeps_the_raw_text_when_only_markers_were_removed(): void {
        $raw = "Ask your instructor.\n[SOLA_NEXT]More||Quiz me[/SOLA_NEXT]";
        $this->assertSame($raw, answer_flow::release_text($raw, 'Ask your instructor.', 'Ask your instructor.'),
            'the chips still reach the browser (the stream filter scrubs them from the text)');
    }

    public function test_release_after_runtime_guard_block_is_the_stored_fallback(): void {
        // validators_runtime_mode=block replaced the retry with its fallback: the learner sees the fallback that was
        // checked and stored, never the raw retry runtime_guard rejected.
        set_config('validators_runtime_mode', 'block', 'local_ai_course_assistant');
        $rawretry = 'Ask your instructor. My API key is sk-ant-' . str_repeat('a', 40) . '.';
        $clean = $rawretry;
        $guarded = runtime_guard::apply($clean, ['input' => 'q']);
        $this->assertNotSame($clean, $guarded, 'precondition: runtime_guard replaced the retry');

        $this->mode('enforce');
        $this->correct_rule();
        $out = answer_flow::finalize('You get 500 zorkmid.', answer_flow::plan(self::GRADE_Q, 0),
            ['question' => self::GRADE_Q, 'buffered' => true], fn() => $guarded);
        $this->assertSame('correct', $out['action'], 'the fallback itself passes the rules');
        $this->assertSame($guarded, $out['text']);
        $this->assertSame($guarded, answer_flow::release_text($rawretry, $clean, $out['text']));
    }

    public function test_release_of_a_redacted_retry_is_the_clean_text(): void {
        $raw = "Use key sk-abcdefghijklmnopqrstuvwxyz123 here.\n[SOLA_NEXT]a||b[/SOLA_NEXT][NEEDS_ESCALATION]";
        $clean = 'Use key [key redacted] here.';
        $this->assertSame($clean, answer_flow::release_text($raw, $clean, $clean),
            'a credential the safety filter redacted is never released raw');
        $this->assertSame('Blocked.', answer_flow::release_text('anything', 'x', 'Blocked.'));
    }

    public function test_correct_is_blocked_without_a_retry_when_the_course_is_at_its_cap(): void {
        global $DB;
        $this->mode('enforce');
        $this->correct_rule();
        $asked = [];
        answer_flow::$spendcheck = function (int $courseid) use (&$asked): string {
            $asked[] = $courseid;
            return spend_guard::CAP_BLOCKED;
        };
        $calls = 0;
        $retry = function () use (&$calls): string {
            $calls++;
            return 'fine';
        };
        $out = answer_flow::finalize('You get 500 zorkmid.', answer_flow::plan(self::GRADE_Q, 0),
            ['question' => self::GRADE_Q, 'buffered' => true, 'courseid' => 12], $retry);
        $this->assertSame([12], $asked);
        $this->assertSame(0, $calls, 'no second model call for a course at its cap');
        $this->assertSame('block', $out['action']);
        $this->assertSame(answer_rules::safe_message('grades'), $out['text']);
        $this->assertSame(answer_flow::RETRY_SKIPPED_SPEND, $out['error']);

        answer_audit::log_rules_error(5, 12, $out['error']);
        $row = $DB->get_record('local_ai_course_assistant_audit', ['action' => answer_audit::RULES_FAILED_ACTION],
            '*', MUST_EXIST);
        $this->assertSame(['courseid' => 12, 'reason' => 'retry_skipped_spend'], json_decode($row->details, true));

        // A warning level is still under the cap: the retry runs.
        answer_flow::$spendcheck = fn(int $courseid): string => spend_guard::CAP_WARN_95;
        $out = answer_flow::finalize('You get 500 zorkmid.', answer_flow::plan(self::GRADE_Q, 0),
            ['question' => self::GRADE_Q, 'buffered' => true, 'courseid' => 12], $retry);
        $this->assertSame(1, $calls);
        $this->assertSame('correct', $out['action']);
    }

    // The held answer's keepalives.

    public function test_buffered_path_sends_keepalive_before_text(): void {
        $now = 1000.0;
        $frames = [];
        $hold = new answer_hold(function (string $frame) use (&$frames): void {
            $frames[] = $frame;
        }, function () use (&$now): float {
            return $now;
        });
        $hold->start();
        $this->assertCount(1, $frames, 'a keepalive goes out as soon as the answer is held');
        foreach ([2, 5, 9.9] as $t) {
            $now = 1000.0 + $t;
            $hold->tick();
        }
        $this->assertCount(1, $frames, 'none again within the interval');
        $now = 1000.0 + answer_hold::KEEPALIVE_SECONDS;
        $hold->tick();
        $this->assertCount(2, $frames);
        $now += 25;
        $hold->tick();
        $hold->tick();
        $this->assertCount(3, $frames, 'one per elapsed interval, not one per chunk');
        foreach ($frames as $frame) {
            $this->assertSame(answer_hold::KEEPALIVE_FRAME, $frame);
            $this->assertStringStartsWith(':', $frame, 'an SSE comment: the browser ignores it, proxies see traffic');
            $this->assertStringEndsWith("\n\n", $frame);
            $this->assertStringNotContainsString('data:', $frame);
        }
        $this->assertSame(10, answer_hold::KEEPALIVE_SECONDS);
    }

    public function test_a_failing_keepalive_never_breaks_the_answer(): void {
        $hold = new answer_hold(function (): void {
            throw new \RuntimeException('client gone');
        });
        $hold->start();
        $this->assertDebuggingCalled('answer_hold: keepalive failed: RuntimeException');
        $hold->tick();
        $this->assertDebuggingNotCalled();
    }

    // The audit row and the failure counter.

    public function test_record_answer_stores_the_categories_and_rules(): void {
        global $DB;
        $this->mode('watch');
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $conv = conversation_manager::get_or_create_conversation((int) $user->id, (int) $course->id);
        $msgid = conversation_manager::add_message((int) $conv->id, (int) $user->id, (int) $course->id, 'assistant', 'x');
        $row = ['messageid' => $msgid, 'conversationid' => (int) $conv->id, 'userid' => (int) $user->id,
            'courseid' => (int) $course->id, 'cmid' => null, 'mode' => 'text', 'question' => self::GRADE_Q,
            'answer' => 'final text', 'model' => 'm', 'provider' => 'p'];
        $id = answer_audit::record_answer($row, [], [], [], ['categories' => ['grades'],
            'rules' => [['id' => 'grades.guarantee', 'action' => 'block']]]);
        $rec = $DB->get_record('local_ai_course_assistant_answer_audit', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(['grades'], json_decode($rec->categories, true));
        $this->assertSame([['action' => 'block', 'id' => 'grades.guarantee']], json_decode($rec->rules, true));
        $this->assertSame('final text', $rec->answer);
        $this->assertTrue(answer_audit::verify_chain((int) $course->id)['ok']);
    }

    public function test_rules_error_is_counted_without_text(): void {
        global $DB;
        answer_audit::log_rules_error(5, 7, 'RuntimeException');
        $row = $DB->get_record('local_ai_course_assistant_audit', ['action' => answer_audit::RULES_FAILED_ACTION],
            '*', MUST_EXIST);
        $this->assertEquals(7, $row->courseid);
        $this->assertSame(['courseid' => 7, 'reason' => 'rules_error', 'error_class' => 'RuntimeException'],
            json_decode($row->details, true));
    }

    // Where sse.php does each step (sse.php is a script a test can't load).

    /**
     * The sse.php source with whitespace folded.
     *
     * @return string
     */
    private function sse_source(): string {
        global $CFG;
        return preg_replace('/\s+/', ' ', file_get_contents($CFG->dirroot . '/local/ai_course_assistant/sse.php'));
    }

    public function test_sse_call_sites_and_order(): void {
        $src = $this->sse_source();
        $plan = '$answerplan = \\local_ai_course_assistant\\answer_flow::plan((string) $message, (int) $courseid);';
        $hold = '$answerhold = $answerplan[\'hold\'] ? new \\local_ai_course_assistant\\answer_hold(';
        $firstcall = '$provider->chat_completion_stream($systemprompt, $history, $streamfilter, $streamoptions);';
        $guard = '$cleanresponse = \\local_ai_course_assistant\\runtime_guard::apply($cleanresponse, [';
        $finalize = '\\local_ai_course_assistant\\answer_flow::finalize(';
        $release = '$streamemit(\\local_ai_course_assistant\\answer_flow::release_text($answerreleaseraw, '
            . '$answerreleaseclean, $cleanresponse));';
        $save = '$assistantmsgid = conversation_manager::add_message(';
        $replace = "local_ai_course_assistant_sse_send(['replace' => \$answerreplace['text'], "
            . "'messageid' => (int) \$assistantmsgid, 'reason' => \$answerreplace['reason']]);";
        $lockdone = "local_ai_course_assistant_sse_send(['done' => true, 'locked' => true, "
            . "'locked_until' => \$lockeduntil]);";
        $done = 'local_ai_course_assistant_sse_send($doneevent);';
        $this->assertSame(2, substr_count($src, $firstcall), 'the call and its model-not-found retry');
        foreach ([$plan, $hold, $guard, $finalize, $release, $save, $replace, $lockdone, $done] as $needle) {
            $this->assertSame(1, substr_count($src, $needle), 'exactly one: ' . $needle);
        }
        $p = fn(string $n): int => strpos($src, $n);
        $this->assertLessThan($p($firstcall), $p($plan), 'the question is categorised before the model call');
        $this->assertLessThan($p($firstcall), $p($hold));
        $this->assertLessThan($p($finalize), $p($guard), 'evaluated after runtime_guard');
        $this->assertLessThan($p($save), $p($finalize), 'and before the answer is saved');
        $this->assertLessThan($p($save), $p($release), 'a held answer is released before the save');
        $this->assertLessThan($p($replace), $p($save), 'the replace carries the saved message id');
        $this->assertLessThan($p($lockdone), $p($replace), 'replace before the lockout done event');
        $this->assertLessThan($p($done), $p($replace), 'replace before the done event');
        // The held stream only ticks keepalives; tokens are sent by $streamemit alone.
        $this->assertStringContainsString('if ($answerhold !== null) { $answerhold->tick(); return; }', $src);
        $this->assertStringContainsString('$answerhold->start();', $src);
        // The replace goes out whenever the rules set one, and only then.
        $this->assertStringContainsString("if (\$answerreplace !== null) { local_ai_course_assistant_sse_send(['replace' => ",
            $src);
        // A replace is only for an answer the learner already saw streamed.
        $this->assertStringContainsString('if ($answerhold === null) { $answerreplace = [', $src);
        // Both the main reply and the correct retry are released through release_text(): the retry's raw text is
        // never emitted directly, and the retry hands over its own cleaned text to compare against.
        $this->assertSame(2, substr_count($src, '$streamemit('), 'the live stream and the one held release');
        $this->assertStringNotContainsString('$streamemit($answerretryraw', $src);
        $this->assertStringContainsString('$answerretryclean = $text; return \\local_ai_course_assistant\\runtime_guard::apply($text, [',
            $src);
        $this->assertStringContainsString('$answerreleaseraw = $answerretryraw; $answerreleaseclean = $answerretryclean;',
            $src);
        $this->assertStringContainsString("'courseid' => (int) \$courseid, ], \$answerretry);", $src,
            'finalize gets the course, so the retry checks the spend cap');
        // The audit gets what the rules decided.
        $this->assertStringContainsString('\\local_ai_course_assistant\\runtime_guard::last_results(), $answercheck];', $src);
        $this->assertStringContainsString('answer_audit::log_rules_error(', $src);
        // A failure in the new code keeps today's answer.
        $this->assertMatchesRegularExpression('/\} catch \(\\\\Throwable \$e\) \{ \$answercheck = /', $src);
    }
}
