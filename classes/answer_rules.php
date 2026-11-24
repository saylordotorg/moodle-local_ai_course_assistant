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

use local_ai_course_assistant\validators\hallucination_validator;

/**
 * Guidance rules (8.4.0): a rulebook checked against the learner's question and SOLA's answer.
 *
 * Pure by design: no database writes, no network, no state beyond config reads and lang strings, so sse.php and
 * send_message can call it on the hot path and the evaluation CLI can run it over fixtures.
 *
 * Modes (site setting `answer_safety_mode`, per-course override `answer_safety_mode_course_<id>` read through
 * course_config_manager, default 'off'):
 *  - off:     evaluate() returns action 'none' and fires nothing.
 *  - watch:   rules fire and are reported, the action is 'watch' at most and the text is never changed.
 *  - enforce: the strongest action wins (none < watch < warn < correct < block).
 *
 * Actions: block (replace with safe_message()), correct (one bounded retry; ONLY on a held, buffered answer, so it
 * becomes warn when ctx['buffered'] is false), warn (deliver with a "check this with your instructor" line), watch
 * (deliver, record, queue for review).
 *
 * Wellbeing: a question in the wellbeing category ALWAYS gets 'block' with the wellbeing safe message in enforce
 * mode, whatever the answer says. The message points to the instructor or support and to local emergency services;
 * it carries no per-country list and no link.
 *
 * Rules: the default pack below (code) plus site rules written by site admins in the `answer_safety_rules` setting.
 * A rule is {id, category, kind keyword|pattern, value, applies_to question|reply|both, action}. A keyword value is
 * one or more comma-separated phrases matched case-insensitively on word boundaries (CJK and Arabic phrases match as
 * substrings). A pattern is a PCRE body of at most PATTERN_MAX_LENGTH characters, with no recursion,
 * backreferences or subroutine calls, that must finish every sample in validate_rule() within $budgetms and without a
 * PCRE error. Site rules are re-validated when loaded, and an invalid one is skipped.
 *
 * Existing validators: ctx['validator_results'] (runtime_guard::last_results()) are folded in. A failing validator
 * fires 'validator.<name>' as 'block' when validators_runtime_mode is block and 'warn' when annotate, so the stricter
 * of the two systems wins; runtime_guard keeps doing exactly what it did before. The numeric hallucination warn maps
 * to 'numbers_unsupported' (warn; watch in watch mode). When no hallucination result was passed and ctx['chunks']
 * holds retrieved text, the same numeric check runs here.
 *
 * Language: the question patterns and the default reply rules are English. Wellbeing also has a short list of
 * first-person crisis phrases in Spanish, French, German, Portuguese, Arabic and Chinese. Text that is not valid
 * UTF-8 is scrubbed first, so no input makes a pattern error; a non-English answer simply does not match the English
 * reply rules.
 *
 * Fail safe: evaluate() never throws. Any error returns action 'none' with 'error' set, so the caller delivers the
 * answer unchanged and records check_failed.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answer_rules {

    /** @var string[] Modes. */
    public const MODES = ['off', 'watch', 'enforce'];

    /** @var string[] Question and rule categories. */
    public const CATEGORIES = ['grades', 'deadlines', 'policy', 'wellbeing', 'advice', 'integrity', 'numbers'];

    /** @var string[] Categories whose answers are held (buffered) and checked before sending. 'numbers' is not one:
     *     numeric questions are common in every course and holding them would slow ordinary answers. */
    public const RISKY = ['grades', 'deadlines', 'policy', 'wellbeing', 'advice', 'integrity'];

    /** @var string[] Actions, weakest first. */
    public const ACTIONS = ['none', 'watch', 'warn', 'correct', 'block'];

    /** @var string[] Actions a rule may carry. */
    public const RULE_ACTIONS = ['block', 'correct', 'warn', 'watch'];

    /** @var string[] Rule kinds. */
    public const KINDS = ['keyword', 'pattern'];

    /** @var string[] Where a rule looks. */
    public const APPLIES_TO = ['question', 'reply', 'both'];

    /** @var int Longest pattern a rule may hold. */
    public const PATTERN_MAX_LENGTH = 200;

    /** @var int Longest keyword list a rule may hold. */
    public const KEYWORDS_MAX_LENGTH = 500;

    /** @var float Default time budget (ms) for one pattern over all validation samples. */
    public const DEFAULT_BUDGET_MS = 50.0;

    /** @var float Time budget (ms) used by validate_rule(); tests lower it. */
    public static float $budgetms = self::DEFAULT_BUDGET_MS;

    /** @var string Pattern delimiter: a control character no admin can type into a text field. */
    private const DELIM = "\x01";

    /** @var string The rule id that maps the numeric hallucination check. */
    public const NUMBERS_RULE = 'numbers_unsupported';

    /** @var string The rule id that every wellbeing question fires. */
    public const WELLBEING_RULE = 'wellbeing.crisis';

    /** @var array<string, array> Site rules by raw config value (per request). */
    private static array $sitecache = [];

    /**
     * First-person crisis phrases. English plus Spanish, French, German, Portuguese, Arabic and Chinese phrases whose
     * wording is unambiguous. Idioms such as "this will kill me" are left out in every language (French "me tuer",
     * Spanish "matarme" and Portuguese "me matar" are common exaggerations). Academic mentions ("why does the
     * character commit suicide") are left out on purpose: they are not a learner in distress.
     */
    private const WELLBEING_PHRASES = [
        // English.
        'kill myself', 'killing myself', 'end my life', 'ending my life', 'take my own life', 'want to die',
        'wanna die', 'wish i was dead', 'wish i were dead', 'suicidal', 'thinking about suicide',
        'thinking of suicide', 'thoughts of suicide', 'considering suicide', 'hurt myself', 'hurting myself',
        'harm myself', 'harming myself', 'cutting myself', 'i self-harm', 'i self harm', "don't want to live",
        'dont want to live', 'no reason to live', 'better off dead',
        // Spanish.
        'quiero morir', 'quiero morirme', 'suicidarme', 'quitarme la vida', 'hacerme daño', 'no quiero vivir',
        // French.
        'je veux mourir', 'me suicider', 'mettre fin à mes jours', 'me faire du mal', 'je ne veux plus vivre',
        // German.
        'ich will sterben', 'ich möchte sterben', 'mich umbringen', 'mir das leben nehmen', 'suizidgedanken',
        'selbstmordgedanken', 'mich selbst verletzen',
        // Portuguese.
        'quero morrer', 'me suicidar', 'tirar minha vida', 'tirar a minha vida', 'não quero mais viver',
        // Arabic: "I want to die", "kill myself", "I will commit suicide".
        'أريد أن أموت', 'أقتل نفسي', 'سأنتحر',
        // Chinese: "want to die", "don't want to live", "want to kill myself", "hurt myself".
        '想死', '不想活', '想自杀', '伤害自己',
    ];

    /** @var array<string, string[]> English question keywords per category (the numbers category also has a pattern). */
    private const QUESTION_KEYWORDS = [
        'grades' => ['grade', 'grades', 'graded', 'grading', 'gpa', 'extra credit', 'pass the course',
            'fail the course', 'pass the class', 'fail the class', 'my score', 'my mark', 'my marks', 'gradebook'],
        'deadlines' => ['deadline*', 'due date*', 'extension', 'late submission', 'late penalty', 'submit late',
            'is it due', 'is due', 'are due'],
        'policy' => ['refund*', 'financial aid', 'scholarship*', 'tuition', 'enroll*', 'enrol', 'enrolment',
            'withdraw*', 'drop the course', 'transcript*', 'transfer credit*', 'credit transfer', 'credits transfer',
            'accreditation', 'accredited', 'certificate', 'policy', 'policies'],
        'advice' => ['medical advice', 'diagnos*', 'medication*', 'dosage', 'prescription*', 'antidepressant*',
            'legal advice', 'lawyer', 'lawsuit', 'sue my', 'landlord', 'investment advice', 'should i invest',
            'invest my', 'tax advice', 'bankruptcy'],
        'integrity' => ['cheat*', 'plagiar*', 'turnitin', 'do my homework', 'do my assignment', 'write my essay',
            'write my paper', 'quiz answers', 'exam answers', 'test answers', 'answer key', 'take my exam',
            'take my quiz', 'ai detector*', 'avoid detection'],
        'numbers' => ['how many', 'how much', 'what percent*', 'statistic*'],
    ];

    /** @var string Numbers in a question: a percentage or a currency amount. */
    private const NUMBERS_QUESTION_PATTERN = '\d\s?%|[$€£]\s?\d';

    /** @var string Month names as an answer would write them. */
    private const MONTH = '(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*';

    /** @var string[] A date as an answer would state it (Month DD, DD Month, DD/MM), captured for the support check. */
    private const DATES = [
        '(' . self::MONTH . '\.?\s+\d{1,2}(?:st|nd|rd|th)?)',
        '(\d{1,2}(?:st|nd|rd|th)?\s+(?:of\s+)?' . self::MONTH . ')',
        '(\d{1,2}/\d{1,2}(?:/\d{2,4})?)',
    ];

    /** @var string A way to get round a plagiarism or AI checker or a proctor. */
    private const EVADE = '(?:avoid|bypass|evade|beat|fool|trick|get\s+(?:past|around))\s+(?:[\w-]+\s+){0,2}'
        . '(?:detect|checker|turnitin|proctor)\w*';

    /**
     * The default rule pack. Conservative: block only for wellbeing and for clearly harmful guidance (promising or
     * changing a grade, granting an extension, coaching a learner to evade integrity checks); everything else warns.
     * Each rule's `values` are patterns (each within PATTERN_MAX_LENGTH, pinned by a test) or keywords.
     * `unsupported` means the rule fires only when its first captured group does not appear in the retrieved
     * course text, so an answer quoting the course's own date or policy passes.
     *
     * @return array[]
     */
    public static function default_rules(): array {
        return [
            // Wellbeing: any wellbeing question. Fired from the question's categories (see evaluate()), so the
            // phrases live in WELLBEING_PHRASES. Block: an AI tutor is not crisis support, and the learner needs
            // a person.
            ['id' => self::WELLBEING_RULE, 'category' => 'wellbeing', 'kind' => 'keyword', 'applies_to' => 'question',
                'action' => 'block', 'values' => self::WELLBEING_PHRASES],
            // A promise of a grade or a pass ("I guarantee you'll pass", "you will definitely get an A"). Block: SOLA
            // cannot know or grant a grade and a learner may stop working on the strength of it. Encouragement
            // ("you'll be better prepared") does not match.
            ['id' => 'grades.guarantee', 'category' => 'grades', 'kind' => 'pattern', 'applies_to' => 'reply',
                'action' => 'block', 'values' => [
                    '\bguarantee[sd]?\b[^.!?\n]{0,60}?\b(?:grades?|pass|score|marks?|credit)\b',
                    "\\byou(?:'ll|\\s+will)\\s+(?:definitely|certainly|surely|automatically)\\s+(?:get|receive|earn|pass)\\b",
                ]],
            // A claim to have changed a grade. Block: SOLA has no access to the gradebook, so this is always false.
            ['id' => 'grades.changed', 'category' => 'grades', 'kind' => 'pattern', 'applies_to' => 'reply',
                'action' => 'block', 'values' => [
                    "\\bI(?:'ve|\\s+have)\\s+(?:changed|updated|raised|increased|adjusted|bumped|corrected)\\s+your\\s+"
                        . '(?:[\w-]+\s+){0,2}(?:grades?|scores?|marks?)\b',
                ]],
            // A predicted grade ("you will get a B+"). Warn: a guess, not a promise, but it should be checked with
            // the instructor. The letter must be a capital so "you'll get a better idea" does not match.
            ['id' => 'grades.predicted', 'category' => 'grades', 'kind' => 'pattern', 'applies_to' => 'reply',
                'action' => 'warn', 'values' => [
                    "\\byou(?:'ll|\\s+will)\\s+(?:get|receive|earn)\\s+(?:an?\\s+)?(?:(?-i:[A-F])[+-]?|\\d{1,3}\\s?%)(?=[\\s.,!?]|$)",
                ]],
            // A granted or approved extension. Block: only the instructor can grant one, and a learner who believes
            // SOLA may miss the real deadline.
            ['id' => 'deadlines.extension_granted', 'category' => 'deadlines', 'kind' => 'pattern',
                'applies_to' => 'reply', 'action' => 'block', 'values' => [
                    "\\bI(?:'ve|\\s+have|'ll|\\s+will|\\s+can)\\s+(?:grant(?:ed)?|give|given|approved?|extend(?:ed)?)\\s+"
                        . '(?:you\s+)?(?:an?\s+)?(?:[\w-]+\s+){0,2}extension',
                    '\byour\s+extension\s+(?:request\s+)?(?:is|has\s+been|was)\s+(?:approved|granted|confirmed)\b',
                ]],
            // A due date the course text does not contain. Warn: it may be right, but an invented date costs marks.
            ['id' => 'deadlines.invented_date', 'category' => 'deadlines', 'kind' => 'pattern', 'applies_to' => 'reply',
                'action' => 'warn', 'unsupported' => true, 'values' => array_map(
                    static fn(string $date): string => '\b(?:due|deadline|closes?)\b[^.\n]{0,40}?\b' . $date,
                    self::DATES)],
            // A stated institutional policy (refund, withdrawal, transfer...) the course text does not contain.
            // Warn: policy questions belong to student support, and a made-up policy can cost money.
            ['id' => 'policy.invented', 'category' => 'policy', 'kind' => 'pattern', 'applies_to' => 'reply',
                'action' => 'warn', 'unsupported' => true, 'values' => [
                    "\\b(?:the|our|its|their|[\\w-]+'s)\\s+((?:refund|withdrawal|late|attendance|enrol+ment|transfer|"
                        . 'grading|appeals?|extension)\s+policy)\s+(?:is|states|says|allows|permits|requires|lets)\b',
                ]],
            // A personal medical, legal or financial instruction ("you should stop taking your medication", "you
            // should sue"). Warn: general course content about these subjects is fine; a personal directive is not
            // SOLA's to give.
            ['id' => 'advice.medical_legal_financial', 'category' => 'advice', 'kind' => 'pattern',
                'applies_to' => 'reply', 'action' => 'warn', 'values' => [
                    '\byou\s+should\s+(?:stop|start|increase|decrease|double|skip|reduce)\s+(?:taking\s+)?'
                        . '(?:your\s+)?(?:[\w-]+\s+)?(?:medication|medicine|meds|dose|dosage|pills|antidepressants|'
                        . 'insulin)s?\b',
                    '\byou\s+should\s+(?:definitely\s+)?(?:sue\b|file\s+(?:a\s+)?(?:lawsuit|claim|for\s+bankruptcy))',
                    '\byou\s+should\s+(?:definitely\s+)?(?:invest\s+(?:all\s+|your\s+|in\s+)|buy\s+(?:[\w-]+\s+)?'
                        . '(?:stocks?|shares|crypto\w*|bitcoin)|put\s+(?:all\s+)?your\s+(?:savings|money)\s+in)',
                ]],
            // Coaching to evade a plagiarism or AI checker or a proctor. Block: clearly harmful to the learner and
            // the course. Only instructional phrasing matches, so a refusal ("you shouldn't try to avoid
            // plagiarism detection") does not.
            ['id' => 'integrity.cheat_help', 'category' => 'integrity', 'kind' => 'pattern', 'applies_to' => 'reply',
                'action' => 'block', 'values' => [
                    '(?:^|[.!?:]\s+|\n)\s*to\s+' . self::EVADE,
                    '\byou\s+(?:can|could)\s+' . self::EVADE,
                    "\\b(?:here(?:'s|\\s+is)\\s+how|the\\s+(?:trick\\s+is|best\\s+way))\\s+to\\s+" . self::EVADE,
                ]],
            // Assessment answers handed over, or a finished piece of work offered for submission. Warn: it may be
            // a practice quiz, so an instructor should look, not a hard stop.
            ['id' => 'integrity.answer_dump', 'category' => 'integrity', 'kind' => 'pattern', 'applies_to' => 'reply',
                'action' => 'warn', 'values' => [
                    '\bhere\s+(?:are|is)\s+(?:all\s+)?the\s+(?:correct\s+)?(?:answers?|answer\s+key|solutions?)\s+'
                        . '(?:to|for)\s+(?:your\s+|the\s+|this\s+)?(?:[\w-]+\s+){0,2}(?:quiz|exam|test|assessment)',
                    '\bhere\s+is\s+(?:your|the)\s+(?:completed\s+|finished\s+|full\s+)?(?:essay|assignment|paper|'
                        . 'homework|report)\b[^.\n]{0,30}\bready\s+to\s+(?:submit|hand\s+in|turn\s+in)',
                ]],
            // numbers_unsupported is not listed: it comes from the numeric hallucination check (see evaluate()).
        ];
    }

    /**
     * The mode for a course: the course's own override when it holds a valid mode, else the site setting, else off.
     *
     * @param int $courseid 0 or less for the site setting alone
     * @return string off|watch|enforce
     */
    public static function mode(int $courseid): string {
        if ($courseid > 0) {
            $override = course_config_manager::get_answer_safety_mode($courseid);
            if ($override !== null) {
                return $override;
            }
        }
        $site = (string) get_config('local_ai_course_assistant', 'answer_safety_mode');
        return in_array($site, self::MODES, true) ? $site : 'off';
    }

    /**
     * The categories a question falls in. Wellbeing also matches the multilingual crisis phrases; site rules that
     * look at the question add their own category when they match.
     *
     * @param string $q
     * @return string[] in CATEGORIES order
     */
    public static function categories_for_question(string $q): array {
        try {
            $text = self::normalise($q);
            if ($text === '') {
                return [];
            }
            $found = [];
            if (self::keywords_match(self::WELLBEING_PHRASES, $text)) {
                $found['wellbeing'] = true;
            }
            foreach (self::QUESTION_KEYWORDS as $category => $keywords) {
                if (self::keywords_match($keywords, $text)) {
                    $found[$category] = true;
                }
            }
            if (self::pattern_match(self::NUMBERS_QUESTION_PATTERN, $text) !== null) {
                $found['numbers'] = true;
            }
            foreach (self::site_rules() as $rule) {
                if ($rule['applies_to'] !== 'reply' && self::rule_matches($rule, $text, null)) {
                    $found[$rule['category']] = true;
                }
            }
            return array_values(array_filter(self::CATEGORIES, static fn($c) => isset($found[$c])));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Whether any of these categories is risky (the answer is held and checked before it is sent).
     *
     * @param string[] $categories
     * @return bool
     */
    public static function is_risky(array $categories): bool {
        return (bool) array_intersect($categories, self::RISKY);
    }

    /**
     * Check an answer.
     *
     * @param string $reply the answer as it would be delivered (after runtime_guard)
     * @param array $ctx question (string), categories (string[]; computed from the question when absent),
     *     chunks (string[] or retriever rows with 'content'), buffered (bool: the answer is held, so 'correct' can
     *     retry), validator_results (runtime_guard::last_results()), and the mode as either mode (off|watch|enforce)
     *     or courseid (read through mode()); with neither, the site mode.
     * @return array{action: string, rules: string[], text: ?string, fired: array[], categories: string[],
     *     error: ?string} text is what to deliver instead of the answer (block: the safe message; warn: the answer
     *     plus the check line), null to deliver it unchanged (none, watch) or to retry (correct). fired holds
     *     [id, category, action] per rule, for the audit row. error is the exception class when the check failed
     *     (the answer is then delivered unchanged).
     */
    public static function evaluate(string $reply, array $ctx): array {
        $result = ['action' => 'none', 'rules' => [], 'text' => null, 'fired' => [], 'categories' => [],
            'error' => null];
        try {
            $mode = self::mode_from_context($ctx);
            if ($mode === 'off') {
                return $result;
            }
            $question = self::normalise((string) ($ctx['question'] ?? ''));
            $answer = self::normalise($reply);
            $categories = isset($ctx['categories']) && is_array($ctx['categories'])
                ? array_values(array_intersect(self::CATEGORIES, $ctx['categories']))
                : self::categories_for_question($question);
            $result['categories'] = $categories;
            $chunks = self::chunk_texts($ctx['chunks'] ?? []);
            $chunktext = self::normalise(implode("\n", $chunks));

            $fired = [];
            $fire = static function (string $id, string $category, string $action) use (&$fired): void {
                if (!isset($fired[$id])) {
                    $fired[$id] = ['id' => $id, 'category' => $category, 'action' => $action];
                }
            };

            if (in_array('wellbeing', $categories, true)) {
                $fire(self::WELLBEING_RULE, 'wellbeing', 'block');
            }
            foreach (array_merge(self::default_rules(), self::site_rules()) as $rule) {
                if ($rule['id'] === self::WELLBEING_RULE) {
                    continue;
                }
                $hit = false;
                if ($rule['applies_to'] !== 'reply') {
                    $hit = self::rule_matches($rule, $question, null);
                }
                if (!$hit && $rule['applies_to'] !== 'question') {
                    $hit = self::rule_matches($rule, $answer, empty($rule['unsupported']) ? null : [$chunktext]);
                }
                if ($hit) {
                    $fire($rule['id'], $rule['category'], $rule['action']);
                }
            }
            self::fold_validators($ctx, $answer, $question, $chunks, $fire);

            $result['fired'] = array_values($fired);
            $result['rules'] = array_keys($fired);
            if (!$fired) {
                return $result;
            }
            if ($mode === 'watch') {
                $result['action'] = 'watch';
                return $result;
            }
            $strongest = null;
            foreach ($fired as $f) {
                if ($strongest === null || self::rank($f['action']) > self::rank($strongest['action'])) {
                    $strongest = $f;
                }
            }
            $action = $strongest['action'];
            if ($action === 'correct' && empty($ctx['buffered'])) {
                // A streamed answer is already on the screen: it can be warned or blocked, never retried.
                $action = 'warn';
            }
            $result['action'] = $action;
            if ($action === 'block') {
                $category = isset($fired[self::WELLBEING_RULE]) ? 'wellbeing' : $strongest['category'];
                $result['text'] = self::safe_message($category);
            } else if ($action === 'warn') {
                $result['text'] = rtrim($reply) . "\n\n_" . get_string('answer_rules:warn_line',
                    'local_ai_course_assistant') . '_';
            }
            return $result;
        } catch (\Throwable $e) {
            return ['action' => 'none', 'rules' => [], 'text' => null, 'fired' => [], 'categories' => [],
                'error' => get_class($e)];
        }
    }

    /**
     * The message shown instead of a blocked answer. Wellbeing uses the admin's text when one is saved, else the
     * built-in one. No message carries a per-country list or a link.
     *
     * @param string $category one of CATEGORIES; anything else gets the general message
     * @return string
     */
    public static function safe_message(string $category): string {
        $component = 'local_ai_course_assistant';
        if ($category === 'wellbeing') {
            $custom = trim((string) get_config($component, 'answer_safety_wellbeing_message'));
            return $custom !== '' ? $custom : get_string('answer_rules:safe_wellbeing', $component);
        }
        if (in_array($category, ['grades', 'deadlines', 'policy', 'advice', 'integrity'], true)) {
            return get_string('answer_rules:safe_' . $category, $component);
        }
        return get_string('answer_rules:safe_general', $component);
    }

    /**
     * Problems with a site rule. Empty means it can be saved and run.
     *
     * @param array $rule id, category, kind, value, applies_to, action
     * @return array<string, string> error code => message
     */
    public static function validate_rule(array $rule): array {
        $errors = [];
        $id = trim((string) ($rule['id'] ?? ''));
        if (!preg_match('~^[a-z0-9_]+(?:\.[a-z0-9_]+)*$~', $id) || strlen($id) > 64) {
            $errors['id'] = self::err('id');
        } else if (in_array($id, self::reserved_ids(), true)) {
            $errors['id_reserved'] = self::err('id_reserved', $id);
        }
        if (!in_array($rule['category'] ?? '', self::CATEGORIES, true)) {
            $errors['category'] = self::err('category');
        }
        $kind = $rule['kind'] ?? '';
        if (!in_array($kind, self::KINDS, true)) {
            $errors['kind'] = self::err('kind');
        }
        if (!in_array($rule['applies_to'] ?? '', self::APPLIES_TO, true)) {
            $errors['applies_to'] = self::err('applies_to');
        }
        if (!in_array($rule['action'] ?? '', self::RULE_ACTIONS, true)) {
            $errors['action'] = self::err('action');
        }
        $value = (string) ($rule['value'] ?? '');
        if (trim($value) === '') {
            $errors['value_empty'] = self::err('value_empty');
        } else if ($kind === 'keyword') {
            if (\core_text::strlen($value) > self::KEYWORDS_MAX_LENGTH) {
                $errors['value_too_long'] = self::err('value_too_long', self::KEYWORDS_MAX_LENGTH);
            } else if (!self::split_keywords($value)) {
                $errors['value_empty'] = self::err('value_empty');
            }
        } else if ($kind === 'pattern') {
            $errors += self::pattern_errors($value);
        }
        return $errors;
    }

    /**
     * Site rules as stored, valid ones only, in the shape default_rules() uses.
     *
     * @return array[]
     */
    public static function site_rules(): array {
        $raw = (string) get_config('local_ai_course_assistant', 'answer_safety_rules');
        if (trim($raw) === '') {
            return [];
        }
        $key = sha1($raw) . '|' . self::$budgetms;
        if (isset(self::$sitecache[$key])) {
            return self::$sitecache[$key];
        }
        $rules = [];
        foreach (self::decode_rules($raw) as $rule) {
            if (self::validate_rule($rule)) {
                continue;
            }
            $values = $rule['kind'] === 'keyword' ? self::split_keywords($rule['value']) : [$rule['value']];
            $rules[] = ['id' => $rule['id'], 'category' => $rule['category'], 'kind' => $rule['kind'],
                'applies_to' => $rule['applies_to'], 'action' => $rule['action'], 'values' => $values];
        }
        if (count(self::$sitecache) > 8) {
            self::$sitecache = [];
        }
        self::$sitecache[$key] = $rules;
        return $rules;
    }

    /**
     * Decode the stored rules (a JSON list) without validating them.
     *
     * @param string $raw
     * @return array[] each id, category, kind, value, applies_to, action (strings)
     */
    public static function decode_rules(string $raw): array {
        $list = json_decode($raw, true);
        if (!is_array($list)) {
            return [];
        }
        $rules = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rule = [];
            foreach (['id', 'category', 'kind', 'value', 'applies_to', 'action'] as $field) {
                $rule[$field] = is_scalar($item[$field] ?? null) ? trim((string) $item[$field]) : '';
            }
            $rules[] = $rule;
        }
        return $rules;
    }

    /**
     * Ids site rules may not use: the default pack's, the numbers rule and the validator rules.
     *
     * @return string[]
     */
    public static function reserved_ids(): array {
        $ids = array_column(self::default_rules(), 'id');
        $ids[] = self::NUMBERS_RULE;
        return $ids;
    }

    /**
     * The mode evaluate() runs in.
     *
     * @param array $ctx
     * @return string
     */
    private static function mode_from_context(array $ctx): string {
        if (isset($ctx['mode']) && in_array($ctx['mode'], self::MODES, true)) {
            return $ctx['mode'];
        }
        return self::mode((int) ($ctx['courseid'] ?? 0));
    }

    /**
     * Fold runtime_guard's validator results in, and run the numeric check here when they carry none.
     *
     * @param array $ctx
     * @param string $answer normalised answer
     * @param string $question normalised question
     * @param string[] $chunks
     * @param callable $fire fn(id, category, action)
     * @return void
     */
    private static function fold_validators(array $ctx, string $answer, string $question, array $chunks,
            callable $fire): void {
        $results = is_array($ctx['validator_results'] ?? null) ? $ctx['validator_results'] : [];
        $vmode = (string) ($ctx['validators_mode'] ?? get_config('local_ai_course_assistant', 'validators_runtime_mode'));
        $sawnumbers = false;
        foreach ($results as $r) {
            if (!is_array($r)) {
                continue;
            }
            $name = (string) ($r['validator'] ?? '');
            $severity = (string) ($r['severity'] ?? '');
            if ($name === 'hallucination') {
                $sawnumbers = true;
                if ($severity === 'warn' || $severity === 'fail') {
                    $fire(self::NUMBERS_RULE, 'numbers', 'warn');
                }
                continue;
            }
            if ($severity !== 'fail' || !preg_match('~^[a-z0-9_]{1,40}$~', $name)) {
                continue;
            }
            if ($vmode === 'block') {
                $fire('validator.' . $name, 'general', 'block');
            } else if ($vmode === 'annotate') {
                $fire('validator.' . $name, 'general', 'warn');
            }
        }
        if (!$sawnumbers && $chunks) {
            $check = (new hallucination_validator())->validate($answer, ['input' => $question, 'rag_chunks' => $chunks]);
            if (!$check->passed()) {
                $fire(self::NUMBERS_RULE, 'numbers', 'warn');
            }
        }
    }

    /**
     * Whether a rule matches a text.
     *
     * @param array $rule with kind and values
     * @param string $text normalised
     * @param string[]|null $support normalised course text; when given, a match counts only if its first captured
     *     group (else the whole match) is absent from it
     * @return bool
     */
    private static function rule_matches(array $rule, string $text, ?array $support): bool {
        if ($text === '') {
            return false;
        }
        if ($rule['kind'] === 'keyword') {
            return self::keywords_match($rule['values'], $text);
        }
        foreach ($rule['values'] as $pattern) {
            $m = self::pattern_match($pattern, $text);
            if ($m === null) {
                continue;
            }
            if ($support === null) {
                return true;
            }
            $needle = self::fold($m[1] ?? $m[0]);
            $haystack = self::fold(implode("\n", $support));
            if ($needle === '' || strpos($haystack, $needle) === false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Run one pattern. A PCRE error (backtrack limit, bad UTF-8) is no match.
     *
     * @param string $pattern
     * @param string $text
     * @return array|null the match groups, or null
     */
    private static function pattern_match(string $pattern, string $text): ?array {
        $r = @preg_match(self::DELIM . $pattern . self::DELIM . 'iu', $text, $m);
        return $r === 1 ? $m : null;
    }

    /**
     * Whether any keyword occurs in the text. A keyword ending in * matches as a prefix. A keyword that starts and
     * ends with a letter of a script that spaces its words needs a word boundary on each side; CJK and Arabic
     * keywords match as substrings (Arabic attaches prefixes such as "and" to the word).
     *
     * @param string[] $keywords
     * @param string $text normalised
     * @return bool
     */
    private static function keywords_match(array $keywords, string $text): bool {
        foreach ($keywords as $keyword) {
            $keyword = trim((string) $keyword);
            $prefix = substr($keyword, -1) === '*';
            if ($prefix) {
                $keyword = rtrim(substr($keyword, 0, -1));
            }
            if ($keyword === '') {
                continue;
            }
            $substring = (bool) preg_match('~[\p{Han}\p{Arabic}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Thai}]~u', $keyword);
            $body = preg_quote($keyword, '~');
            $body = preg_replace('~\s+~', '\\s+', $body);
            $pattern = $substring ? $body : '(?<![\p{L}\p{N}])' . $body . ($prefix ? '' : '(?![\p{L}\p{N}])');
            if (@preg_match('~' . $pattern . '~iu', $text) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Comma-separated keywords to a list.
     *
     * @param string $value
     * @return string[]
     */
    private static function split_keywords(string $value): array {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn($k) => $k !== ''
            && $k !== '*'));
    }

    /**
     * Problems with a pattern: length, forbidden constructs, compile errors, PCRE errors or the time budget on the
     * samples.
     *
     * @param string $pattern
     * @return array<string, string>
     */
    private static function pattern_errors(string $pattern): array {
        if (\core_text::strlen($pattern) > self::PATTERN_MAX_LENGTH) {
            return ['pattern_too_long' => self::err('pattern_too_long', self::PATTERN_MAX_LENGTH)];
        }
        // Recursion, subroutine calls, backreferences, callouts and verbs are what make a pattern explode or
        // escape the match limits; none is needed to describe a phrase. Control characters would reach the
        // delimiter.
        if (preg_match('~[\x00-\x1f]|\(\?(?:R|\d|[+-]\d|&|P[>=]|C)|\\\\(?:[1-9]|g|k)|\(\*~', $pattern)) {
            return ['pattern_forbidden' => self::err('pattern_forbidden')];
        }
        $compiled = self::DELIM . $pattern . self::DELIM . 'iu';
        if (@preg_match($compiled, '') === false) {
            return ['pattern_invalid' => self::err('pattern_invalid')];
        }
        $start = microtime(true);
        foreach (self::samples() as $sample) {
            if (@preg_match($compiled, $sample) === false) {
                return ['pattern_too_complex' => self::err('pattern_too_complex')];
            }
        }
        if ((microtime(true) - $start) * 1000 > self::$budgetms) {
            return ['pattern_too_slow' => self::err('pattern_too_slow', (int) self::DEFAULT_BUDGET_MS)];
        }
        return [];
    }

    /**
     * Texts a pattern must handle quickly: ordinary prose and the long runs that make nested quantifiers backtrack.
     *
     * @return string[]
     */
    private static function samples(): array {
        $prose = 'Your instructor can confirm the deadline. The refund policy is on the official page. You will '
            . 'get a B+ if you keep going! 45% of firms fail; the merger cost $4.2 billion on March 14, 2026. '
            . 'Je veux savoir. 我想知道。 أريد أن أعرف.';
        return [
            str_repeat($prose . ' ', 20),
            str_repeat('a', 3000) . '!',
            str_repeat('ab ', 1000) . '!',
            str_repeat('1', 3000) . 'x',
            str_repeat('x ', 1500) . "\n",
            str_repeat('é', 1000) . '!',
        ];
    }

    /**
     * Text ready for matching: valid UTF-8, curly apostrophes made straight.
     *
     * @param string $text
     * @return string
     */
    private static function normalise(string $text): string {
        $text = mb_scrub($text, 'UTF-8');
        return str_replace(["\u{2019}", "\u{2018}", "\u{02BC}"], "'", $text);
    }

    /**
     * Lowercase with ordinal suffixes and runs of space removed, for the support check.
     *
     * @param string $text
     * @return string
     */
    private static function fold(string $text): string {
        $text = \core_text::strtolower($text);
        $text = preg_replace('~(\d)(?:st|nd|rd|th)\b~u', '$1', $text) ?? $text;
        return trim(preg_replace('~\s+~u', ' ', $text) ?? $text);
    }

    /**
     * Chunk texts from strings or retriever rows.
     *
     * @param mixed $chunks
     * @return string[]
     */
    private static function chunk_texts($chunks): array {
        if (!is_array($chunks)) {
            return [];
        }
        $texts = [];
        foreach ($chunks as $chunk) {
            $text = is_array($chunk) ? (string) ($chunk['content'] ?? '') : (is_scalar($chunk) ? (string) $chunk : '');
            if (trim($text) !== '') {
                $texts[] = self::normalise($text);
            }
        }
        return $texts;
    }

    /**
     * Strength of an action.
     *
     * @param string $action
     * @return int
     */
    private static function rank(string $action): int {
        $i = array_search($action, self::ACTIONS, true);
        return $i === false ? 0 : (int) $i;
    }

    /**
     * An error message.
     *
     * @param string $code
     * @param mixed $a
     * @return string
     */
    private static function err(string $code, $a = null): string {
        return get_string('answer_rules:err_' . $code, 'local_ai_course_assistant', $a);
    }
}
