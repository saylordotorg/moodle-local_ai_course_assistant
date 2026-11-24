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
 * The guidance rule engine (8.4.0): categories, modes, actions, the default pack and site rules.
 *
 * The per-category tests read tests/fixtures/answer_safety/<category>.json, the same files the evaluation CLI reads:
 * [{id, category, question, reply, expect none|watch|warn|block, chunks?}], judged in enforce mode on a held answer.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\answer_rules
 */
final class answer_rules_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        answer_rules::$budgetms = answer_rules::DEFAULT_BUDGET_MS;
    }

    protected function tearDown(): void {
        answer_rules::$budgetms = answer_rules::DEFAULT_BUDGET_MS;
        parent::tearDown();
    }

    /**
     * The fixtures of one category.
     *
     * @param string $category
     * @return array[]
     */
    private function fixtures(string $category): array {
        $path = __DIR__ . '/fixtures/answer_safety/' . $category . '.json';
        $this->assertFileExists($path);
        $items = json_decode(file_get_contents($path), true);
        $this->assertIsArray($items, "$category.json is a JSON list");
        return $items;
    }

    /**
     * Every fixture, all categories.
     *
     * @return array[]
     */
    private function all_fixtures(): array {
        $all = [];
        foreach (answer_rules::CATEGORIES as $category) {
            $all = array_merge($all, $this->fixtures($category));
        }
        return $all;
    }

    /**
     * Evaluate one fixture.
     *
     * @param array $f
     * @param string $mode
     * @param bool $buffered
     * @return array
     */
    private function run_fixture(array $f, string $mode = 'enforce', bool $buffered = true): array {
        return answer_rules::evaluate($f['reply'], [
            'question' => $f['question'],
            'categories' => answer_rules::categories_for_question($f['question']),
            'chunks' => $f['chunks'] ?? [],
            'buffered' => $buffered,
            'mode' => $mode,
            'validators_mode' => 'off',
        ]);
    }

    /**
     * Check a category's fixtures: shape, at least four, two non-English, a bad and a good one, each as expected.
     *
     * @param string $category
     * @return void
     */
    private function assert_category(string $category): void {
        $items = $this->fixtures($category);
        $this->assertGreaterThanOrEqual(4, count($items), "$category has at least 4 fixtures");
        $nonenglish = 0;
        $expects = [];
        foreach ($items as $f) {
            foreach (['id', 'category', 'question', 'reply', 'expect'] as $field) {
                $this->assertArrayHasKey($field, $f, "$category fixture has $field");
            }
            $this->assertSame($category, $f['category'], $f['id']);
            $this->assertContains($f['expect'], ['none', 'watch', 'warn', 'block'], $f['id']);
            if (preg_match('~[^\x00-\x7F]~', $f['question'] . $f['reply'])
                    || preg_match('~_(es|fr|de|pt|ar|zh)(_|$)~', $f['id'])) {
                $nonenglish++;
            }
            $expects[$f['expect']] = true;
            $result = $this->run_fixture($f);
            $this->assertNull($result['error'], $f['id']);
            $this->assertSame($f['expect'], $result['action'], $f['id'] . ' rules: ' . implode(',', $result['rules']));
        }
        $this->assertGreaterThanOrEqual(2, $nonenglish, "$category has 2 non-English fixtures");
        $this->assertArrayHasKey('none', $expects, "$category has a good sample");
        $this->assertTrue(count($expects) > 1, "$category has a bad sample");
    }

    public function test_grades_fixtures(): void {
        $this->assert_category('grades');
    }

    public function test_deadlines_fixtures(): void {
        $this->assert_category('deadlines');
    }

    public function test_policy_fixtures(): void {
        $this->assert_category('policy');
    }

    public function test_wellbeing_fixtures(): void {
        $this->assert_category('wellbeing');
    }

    public function test_advice_fixtures(): void {
        $this->assert_category('advice');
    }

    public function test_integrity_fixtures(): void {
        $this->assert_category('integrity');
    }

    public function test_numbers_fixtures(): void {
        $this->assert_category('numbers');
    }

    public function test_bad_samples_fire_the_named_rule(): void {
        $cases = [
            'grades_bad_guarantee' => 'grades.guarantee',
            'grades_bad_changed' => 'grades.changed',
            'grades_bad_predicted' => 'grades.predicted',
            'deadlines_bad_extension' => 'deadlines.extension_granted',
            'deadlines_bad_invented_date' => 'deadlines.invented_date',
            'policy_bad_refund' => 'policy.invented',
            'wellbeing_bad_zh' => 'wellbeing.crisis',
            'advice_bad_legal' => 'advice.medical_legal_financial',
            'integrity_bad_evasion' => 'integrity.cheat_help',
            'integrity_bad_answer_dump' => 'integrity.answer_dump',
            'numbers_bad_es' => 'numbers_unsupported',
        ];
        $byid = array_column($this->all_fixtures(), null, 'id');
        foreach ($cases as $id => $rule) {
            $this->assertContains($rule, $this->run_fixture($byid[$id])['rules'], $id);
        }
    }

    public function test_wellbeing_question_always_blocks_with_safe_text_in_enforce(): void {
        $safe = answer_rules::safe_message('wellbeing');
        foreach ($this->fixtures('wellbeing') as $f) {
            if ($f['expect'] !== 'block') {
                continue;
            }
            $categories = answer_rules::categories_for_question($f['question']);
            $this->assertContains('wellbeing', $categories, $f['id']);
            $this->assertTrue(answer_rules::is_risky($categories), $f['id']);
            // Whatever the answer, held or streamed.
            foreach (['Here is a helpful study plan.', $f['reply'], ''] as $reply) {
                foreach ([true, false] as $buffered) {
                    $r = answer_rules::evaluate($reply, ['question' => $f['question'], 'buffered' => $buffered,
                        'mode' => 'enforce']);
                    $this->assertSame('block', $r['action'], $f['id']);
                    $this->assertSame($safe, $r['text'], $f['id']);
                    $this->assertContains('wellbeing.crisis', $r['rules'], $f['id']);
                }
            }
        }
        // Even with other rules firing: the wellbeing text wins over a grades block.
        $r = answer_rules::evaluate('I guarantee you will pass the course.', ['question' => 'I want to die',
            'mode' => 'enforce', 'buffered' => true]);
        $this->assertSame('block', $r['action']);
        $this->assertSame($safe, $r['text']);
        // Callers that pass categories are trusted: wellbeing in the list blocks.
        $r = answer_rules::evaluate('Fine.', ['question' => '', 'categories' => ['wellbeing'], 'mode' => 'enforce']);
        $this->assertSame('block', $r['action']);
    }

    public function test_wellbeing_message_is_admin_editable_and_falls_back(): void {
        $default = get_string('answer_rules:safe_wellbeing', 'local_ai_course_assistant');
        $this->assertSame($default, answer_rules::safe_message('wellbeing'));
        set_config('answer_safety_wellbeing_message', 'Talk to your tutor or call emergency services.',
            'local_ai_course_assistant');
        $this->assertSame('Talk to your tutor or call emergency services.', answer_rules::safe_message('wellbeing'));
        set_config('answer_safety_wellbeing_message', "  \n ", 'local_ai_course_assistant');
        $this->assertSame($default, answer_rules::safe_message('wellbeing'), 'blank falls back to the built-in text');
    }

    public function test_safe_message_has_no_country_list_or_url(): void {
        $this->assertStringContainsStringIgnoringCase('emergency', answer_rules::safe_message('wellbeing'));
        $this->assertMatchesRegularExpression('~instructor|support~i', answer_rules::safe_message('wellbeing'));
        foreach (array_merge(answer_rules::CATEGORIES, ['general', 'nonsense']) as $category) {
            $text = answer_rules::safe_message($category);
            $this->assertNotSame('', trim($text), $category);
            $this->assertDoesNotMatchRegularExpression('~https?://|www\.|\.(?:com|org|net)\b~i', $text, $category);
            $this->assertDoesNotMatchRegularExpression(
                '~\b(?:911|988|999|112|000|helpline|hotline|lifeline|samaritans|befrienders|crisis text line|'
                . 'USA|United States|UK|United Kingdom|Canada|Australia|India)\b~i', $text, $category);
            $this->assertStringNotContainsString('[[', $text, "$category has no unresolved token");
        }
    }

    public function test_watch_mode_never_changes_text(): void {
        $fired = 0;
        foreach ($this->all_fixtures() as $f) {
            foreach ([true, false] as $buffered) {
                $r = $this->run_fixture($f, 'watch', $buffered);
                $this->assertNull($r['text'], $f['id']);
                $this->assertContains($r['action'], ['none', 'watch'], $f['id']);
                if ($f['expect'] !== 'none') {
                    $this->assertSame('watch', $r['action'], $f['id']);
                    $this->assertNotEmpty($r['rules'], $f['id']);
                    $fired++;
                }
            }
        }
        $this->assertGreaterThan(10, $fired);
        // A validator in block mode is watched, not enforced, by the rulebook.
        $r = answer_rules::evaluate('x', ['question' => 'q', 'mode' => 'watch', 'validators_mode' => 'block',
            'validator_results' => [['validator' => 'pii_echo', 'severity' => 'fail', 'messages' => []]]]);
        $this->assertSame('watch', $r['action']);
        $this->assertNull($r['text']);
    }

    public function test_off_mode_fires_nothing(): void {
        foreach ($this->all_fixtures() as $f) {
            $r = $this->run_fixture($f, 'off');
            $this->assertSame(['none', [], null], [$r['action'], $r['rules'], $r['text']], $f['id']);
        }
    }

    public function test_correct_downgrades_to_warn_when_not_buffered(): void {
        $this->set_site_rules([['id' => 'site.gradebook_claim', 'category' => 'grades', 'kind' => 'keyword',
            'value' => 'gradebook, grade book', 'applies_to' => 'reply', 'action' => 'correct']]);
        $reply = 'Your gradebook shows a pass.';
        $held = answer_rules::evaluate($reply, ['question' => 'q', 'mode' => 'enforce', 'buffered' => true]);
        $this->assertSame('correct', $held['action']);
        $this->assertNull($held['text'], 'correct means retry, not replace');
        $this->assertContains('site.gradebook_claim', $held['rules']);

        $streamed = answer_rules::evaluate($reply, ['question' => 'q', 'mode' => 'enforce', 'buffered' => false]);
        $this->assertSame('warn', $streamed['action']);
        $this->assertStringStartsWith($reply, $streamed['text']);
        $this->assertStringContainsString(get_string('answer_rules:warn_line', 'local_ai_course_assistant'),
            $streamed['text']);
        // A missing buffered flag means streamed.
        $this->assertSame('warn', answer_rules::evaluate($reply, ['question' => 'q', 'mode' => 'enforce'])['action']);
    }

    public function test_non_english_input_does_not_error_or_block_on_english_patterns(): void {
        $texts = [
            'Te garantizo que vas a aprobar con una A. Tu extensión ha sido aprobada.',
            'Je te garantis que tu vas réussir. Pour éviter la détection du plagiat, change les mots.',
            'Ich garantiere dir, dass du bestehst. Die Frist ist am 14. März.',
            'Garanto que você vai passar com A. A política de reembolso permite tudo.',
            '我保证你会通过考试并得到A。截止日期是3月14日。',
            'أضمن لك أنك ستنجح بدرجة A. الموعد النهائي هو ١٤ مارس.',
            "\xC3\x28 invalid \xFF\xFE bytes \xE2\x82",
            str_repeat('ñ', 5000),
        ];
        foreach ($texts as $text) {
            $categories = answer_rules::categories_for_question($text);
            $this->assertIsArray($categories);
            $this->assertNotContains('wellbeing', $categories);
            foreach ([true, false] as $buffered) {
                $r = answer_rules::evaluate($text, ['question' => $text, 'mode' => 'enforce', 'buffered' => $buffered,
                    'chunks' => [$text]]);
                $this->assertNull($r['error'], $text);
                $this->assertNotSame('block', $r['action'], $text . ': ' . implode(',', $r['rules']));
                $this->assertSame('none', $r['action'], $text . ': ' . implode(',', $r['rules']));
            }
        }
    }

    public function test_pattern_over_length_or_time_limit_is_rejected(): void {
        $base = ['id' => 'site.x', 'category' => 'grades', 'kind' => 'pattern', 'applies_to' => 'reply',
            'action' => 'warn'];
        $this->assertSame([], answer_rules::validate_rule($base + ['value' => 'you\s+will\s+pass']));

        $long = $base + ['value' => str_repeat('a', answer_rules::PATTERN_MAX_LENGTH + 1)];
        $this->assertArrayHasKey('pattern_too_long', answer_rules::validate_rule($long));
        $this->assertSame([], answer_rules::validate_rule($base + ['value' =>
            str_repeat('a', answer_rules::PATTERN_MAX_LENGTH)]), 'exactly the limit is fine');

        answer_rules::$budgetms = 0.0;
        $this->assertArrayHasKey('pattern_too_slow', answer_rules::validate_rule($base + ['value' => 'you\s+will']));
        // A keyword rule has no pattern and no time budget.
        $this->assertSame([], answer_rules::validate_rule(array_merge($base, ['kind' => 'keyword', 'value' => 'a, b'])));
    }

    public function test_catastrophic_backtracking_pattern_is_rejected_and_neutralised(): void {
        $base = ['id' => 'site.boom', 'category' => 'grades', 'kind' => 'pattern', 'applies_to' => 'reply',
            'action' => 'block'];
        foreach (['(a+)+$', '^(\w+\s?)*$', '(a|aa)+$', '(\d+)+$'] as $pattern) {
            $errors = answer_rules::validate_rule($base + ['value' => $pattern]);
            $this->assertNotEmpty(array_intersect(array_keys($errors), ['pattern_too_complex', 'pattern_too_slow']),
                $pattern . ': ' . implode(',', array_keys($errors)));
        }
        // Stored by hand anyway: skipped when loaded, and evaluation still finishes quickly with no error.
        set_config('answer_safety_rules', json_encode([$base + ['value' => '(a+)+$']]), 'local_ai_course_assistant');
        $this->assertSame([], answer_rules::site_rules());
        $start = microtime(true);
        $r = answer_rules::evaluate(str_repeat('a', 5000) . '!', ['question' => str_repeat('a', 40) . '!',
            'mode' => 'enforce', 'buffered' => true]);
        $this->assertLessThan(1.0, microtime(true) - $start);
        $this->assertNull($r['error']);
        $this->assertNotContains('site.boom', $r['rules']);

        // A pattern that explodes only on input the samples lack passes validation, but PCRE's backtrack limit
        // still stops it at run time: no match, no error, no stall.
        $this->set_site_rules([$base + ['value' => '(x+x+)+y']]);
        $this->assertCount(1, answer_rules::site_rules());
        $start = microtime(true);
        $r = answer_rules::evaluate(str_repeat('x', 5000) . '!y', ['question' => 'q', 'mode' => 'enforce']);
        $this->assertLessThan(1.0, microtime(true) - $start);
        $this->assertNull($r['error']);
        $this->assertSame('none', $r['action']);
    }

    public function test_validate_rule_reports_each_problem(): void {
        $errors = answer_rules::validate_rule(['id' => 'Bad Id!', 'category' => 'weather', 'kind' => 'regex',
            'applies_to' => 'everything', 'action' => 'delete', 'value' => '']);
        foreach (['id', 'category', 'kind', 'applies_to', 'action', 'value_empty'] as $code) {
            $this->assertArrayHasKey($code, $errors);
            $this->assertNotSame('', $errors[$code]);
        }
        $ok = ['id' => 'site.ok', 'category' => 'policy', 'kind' => 'pattern', 'applies_to' => 'both',
            'action' => 'watch'];
        $this->assertArrayHasKey('id_reserved', answer_rules::validate_rule(['id' => 'grades.guarantee'] + $ok
            + ['value' => 'x']));
        $this->assertArrayHasKey('id_reserved', answer_rules::validate_rule(['id' => 'numbers_unsupported'] + $ok
            + ['value' => 'x']));
        $this->assertArrayHasKey('pattern_invalid', answer_rules::validate_rule($ok + ['value' => '(unclosed']));
        foreach (['(a)\1', '(?R)', '(?1)', '(?&n)', '(?P>n)', '(*SKIP)x', "a\x01b", '\g1', '\k<n>'] as $bad) {
            $this->assertArrayHasKey('pattern_forbidden', answer_rules::validate_rule($ok + ['value' => $bad]), $bad);
        }
        $this->assertArrayHasKey('value_too_long', answer_rules::validate_rule(array_merge($ok, ['kind' => 'keyword',
            'value' => str_repeat('word, ', 100)])));
        $this->assertSame([], answer_rules::validate_rule($ok + ['value' => '(?i)refund\s+within\s+\d+\s+days']));
    }

    public function test_default_rules_are_valid_patterns_with_unique_ids(): void {
        $ids = [];
        foreach (answer_rules::default_rules() as $rule) {
            $this->assertNotContains($rule['id'], $ids, 'unique id');
            $ids[] = $rule['id'];
            $this->assertContains($rule['category'], answer_rules::CATEGORIES);
            $this->assertContains($rule['action'], answer_rules::RULE_ACTIONS);
            if ($rule['kind'] !== 'pattern') {
                continue;
            }
            foreach ($rule['values'] as $pattern) {
                $errors = answer_rules::validate_rule(['id' => 'site.check', 'category' => $rule['category'],
                    'kind' => 'pattern', 'applies_to' => 'reply', 'action' => 'warn', 'value' => $pattern]);
                $this->assertSame([], $errors, $rule['id'] . ': ' . $pattern);
            }
        }
        // Conservative: only wellbeing and clearly harmful guidance block.
        $blocking = array_column(array_filter(answer_rules::default_rules(), fn($r) => $r['action'] === 'block'), 'id');
        sort($blocking);
        $this->assertSame(['deadlines.extension_granted', 'grades.changed', 'grades.guarantee', 'integrity.cheat_help',
            'wellbeing.crisis'], $blocking);
    }

    public function test_existing_block_mode_still_wins_when_stricter(): void {
        $failing = [['validator' => 'pii_echo', 'severity' => 'fail', 'messages' => ['x']],
            ['validator' => 'second_person', 'severity' => 'pass', 'messages' => []]];
        // The rulebook alone would only warn (a predicted grade); the validator in block mode makes it a block.
        $reply = 'You will get a B+ on the final.';
        $r = answer_rules::evaluate($reply, ['question' => 'q', 'mode' => 'enforce', 'buffered' => true,
            'validator_results' => $failing, 'validators_mode' => 'block']);
        $this->assertSame('block', $r['action']);
        $this->assertContains('validator.pii_echo', $r['rules']);
        $this->assertContains('grades.predicted', $r['rules']);
        $this->assertNotContains('validator.second_person', $r['rules'], 'a passing validator fires nothing');
        $this->assertSame(answer_rules::safe_message('general'), $r['text']);

        // Annotate mode maps to warn.
        $r = answer_rules::evaluate('Plain answer.', ['question' => 'q', 'mode' => 'enforce',
            'validator_results' => $failing, 'validators_mode' => 'annotate']);
        $this->assertSame('warn', $r['action']);
        $this->assertContains('validator.pii_echo', $r['rules']);

        // The site setting is read when the caller does not say.
        set_config('validators_runtime_mode', 'block', 'local_ai_course_assistant');
        $r = answer_rules::evaluate('Plain answer.', ['question' => 'q', 'mode' => 'enforce',
            'validator_results' => $failing]);
        $this->assertSame('block', $r['action']);

        // And the stricter rule wins the other way: a wellbeing block beats an annotate warn.
        $r = answer_rules::evaluate('Plain answer.', ['question' => 'I want to die', 'mode' => 'enforce',
            'validator_results' => $failing, 'validators_mode' => 'annotate']);
        $this->assertSame('block', $r['action']);
        $this->assertSame(answer_rules::safe_message('wellbeing'), $r['text']);
    }

    public function test_numeric_hallucination_maps_to_numbers_unsupported(): void {
        $warn = [['validator' => 'hallucination', 'severity' => 'warn', 'messages' => ['45%']]];
        $r = answer_rules::evaluate('About 45% fail.', ['question' => 'q', 'mode' => 'enforce',
            'validator_results' => $warn, 'validators_mode' => 'annotate']);
        $this->assertSame(['numbers_unsupported'], $r['rules']);
        $this->assertSame('warn', $r['action']);
        $this->assertSame([['id' => 'numbers_unsupported', 'category' => 'numbers', 'action' => 'warn']], $r['fired']);

        $r = answer_rules::evaluate('About 45% fail.', ['question' => 'q', 'mode' => 'watch',
            'validator_results' => $warn]);
        $this->assertSame('watch', $r['action']);

        // A passing hallucination result means the check already ran: it is not run again on the chunks.
        $r = answer_rules::evaluate('About 45% fail.', ['question' => 'q', 'mode' => 'enforce', 'chunks' => ['None.'],
            'validator_results' => [['validator' => 'hallucination', 'severity' => 'pass', 'messages' => []]]]);
        $this->assertSame('none', $r['action']);
        // Retriever rows are accepted as chunks.
        $r = answer_rules::evaluate('About 45% fail.', ['question' => 'q', 'mode' => 'enforce',
            'chunks' => [['content' => 'Roughly 45% fail.', 'cmid' => 3]]]);
        $this->assertSame('none', $r['action']);
    }

    public function test_site_rules_fire_on_question_reply_or_both(): void {
        $this->set_site_rules([
            ['id' => 'site.q', 'category' => 'policy', 'kind' => 'keyword', 'value' => 'housing office',
                'applies_to' => 'question', 'action' => 'watch'],
            ['id' => 'site.r', 'category' => 'advice', 'kind' => 'pattern', 'value' => '\bvisa\s+status\b',
                'applies_to' => 'reply', 'action' => 'warn'],
            ['id' => 'site.bad', 'category' => 'advice', 'kind' => 'pattern', 'value' => '(a+)+$',
                'applies_to' => 'reply', 'action' => 'block'],
        ]);
        $this->assertCount(2, answer_rules::site_rules(), 'the invalid row is skipped');
        $this->assertContains('policy', answer_rules::categories_for_question('Where is the Housing  Office?'));
        $r = answer_rules::evaluate('Your visa status is fine.', ['question' => 'Where is the housing office?',
            'mode' => 'enforce']);
        $this->assertEqualsCanonicalizing(['site.q', 'site.r'], $r['rules']);
        $this->assertSame('warn', $r['action']);
        $this->assertSame('none', answer_rules::evaluate('Your visas are fine.', ['question' => 'q',
            'mode' => 'enforce'])['action'], 'word boundaries');
        set_config('answer_safety_rules', 'not json', 'local_ai_course_assistant');
        $this->assertSame([], answer_rules::site_rules());
    }

    public function test_categories_and_risk(): void {
        $this->assertSame(['grades'], answer_rules::categories_for_question('What grade will I get?'));
        $this->assertContains('deadlines', answer_rules::categories_for_question('When is the deadline?'));
        $this->assertContains('integrity', answer_rules::categories_for_question('Can you help me cheat?'));
        $this->assertSame([], answer_rules::categories_for_question('Explain photosynthesis'));
        $this->assertSame([], answer_rules::categories_for_question(''));
        $this->assertSame(['numbers'], answer_rules::categories_for_question('Is 45% of $200 more than 80?'));
        $this->assertFalse(answer_rules::is_risky(['numbers']));
        $this->assertFalse(answer_rules::is_risky([]));
        foreach (answer_rules::RISKY as $category) {
            $this->assertTrue(answer_rules::is_risky(['numbers', $category]), $category);
        }
    }

    public function test_mode_reads_course_override_then_site_then_off(): void {
        unset_config('answer_safety_mode', 'local_ai_course_assistant');
        $this->assertSame('off', answer_rules::mode(7), 'off by default');
        set_config('answer_safety_mode', 'watch', 'local_ai_course_assistant');
        $this->assertSame('watch', answer_rules::mode(7));
        $this->assertSame('watch', answer_rules::mode(0));
        course_config_manager::set_answer_safety_mode(7, 'enforce');
        $this->assertSame('enforce', answer_rules::mode(7));
        $this->assertSame('watch', answer_rules::mode(8), 'only that course');
        course_config_manager::set_answer_safety_mode(7, 'off');
        $this->assertSame('off', answer_rules::mode(7), 'a course can turn it off');
        set_config('answer_safety_mode_course_7', 'nonsense', 'local_ai_course_assistant');
        $this->assertSame('watch', answer_rules::mode(7), 'an unknown override is ignored');
        course_config_manager::set_answer_safety_mode(7, null);
        $this->assertNull(course_config_manager::get_answer_safety_mode(7));
        set_config('answer_safety_mode', 'bogus', 'local_ai_course_assistant');
        $this->assertSame('off', answer_rules::mode(7));
        // evaluate() reads the mode through the course id when it is not given.
        course_config_manager::set_answer_safety_mode(9, 'enforce');
        $this->assertSame('block', answer_rules::evaluate('x', ['question' => 'I want to die', 'courseid' => 9])['action']);
        $this->assertSame('none', answer_rules::evaluate('x', ['question' => 'I want to die', 'courseid' => 10])['action']);
        $this->expectException(\coding_exception::class);
        course_config_manager::set_answer_safety_mode(7, 'loud');
    }

    public function test_answer_audit_is_enabled_delegates_to_mode(): void {
        set_config('answer_safety_mode', 'off', 'local_ai_course_assistant');
        $this->assertFalse(answer_audit::is_enabled(7));
        course_config_manager::set_answer_safety_mode(7, 'watch');
        $this->assertTrue(answer_audit::is_enabled(7), 'a course override turns recording on');
        $this->assertFalse(answer_audit::is_enabled(8));
        set_config('answer_safety_mode', 'enforce', 'local_ai_course_assistant');
        course_config_manager::set_answer_safety_mode(8, 'off');
        $this->assertFalse(answer_audit::is_enabled(8), 'and off for one course');
    }

    public function test_evaluate_never_throws(): void {
        $r = answer_rules::evaluate('x', ['question' => ['not', 'a', 'string'], 'mode' => 'enforce',
            'categories' => 'grades', 'chunks' => 'nope', 'validator_results' => ['junk', 5]]);
        $this->assertNull($r['text']);
        $this->assertContains($r['action'], answer_rules::ACTIONS);
    }

    /**
     * Store site rules as the admin setting does.
     *
     * @param array[] $rules
     * @return void
     */
    private function set_site_rules(array $rules): void {
        set_config('answer_safety_rules', json_encode($rules), 'local_ai_course_assistant');
    }
}
