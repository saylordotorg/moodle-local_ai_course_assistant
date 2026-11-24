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
 * PCRE error (the samples and the budget are checked when the rule is SAVED). When loaded, site rules are checked
 * for structure, length and compiling only, never timed, so a saved rule cannot drop out because the server is busy;
 * an invalid one is skipped. Every rule pattern runs under pcre.backtrack_limit BACKTRACK_LIMIT; a pattern that hits
 * it fails open but visibly (listed in pcre_errors and fired as 'watch').
 *
 * Existing validators: ctx['validator_results'] (runtime_guard::last_results()) are folded in. A failing validator
 * fires 'validator.<name>' as 'block' when validators_runtime_mode is block and 'warn' when annotate, so the stricter
 * of the two systems wins; runtime_guard keeps doing exactly what it did before. The numeric hallucination warn maps
 * to 'numbers_unsupported' (warn; watch in watch mode). When no hallucination result was passed and ctx['chunks']
 * holds retrieved text, the same numeric check runs here. Validator rules carry the category VALIDATOR_CATEGORY
 * ('general'), which is not one of CATEGORIES.
 *
 * Language: the question patterns and the default reply rules are English. Wellbeing also has a short list of
 * first-person crisis phrases in Spanish, French, German, Portuguese, Arabic and Chinese. Text that is not valid
 * UTF-8 is scrubbed first, so no input makes a pattern error; a non-English answer simply does not match the English
 * reply rules.
 *
 * Fail safe: evaluate() never throws. Any error returns action 'none' with 'error' set, so the caller delivers the
 * answer unchanged and records check_failed; the one exception is a wellbeing question, which is checked first and
 * still gets its block (enforce) or watch when anything after that fails.
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

    /** @var \Closure|null Test seam: called inside evaluate() after the wellbeing check. Never set outside tests. */
    public static ?\Closure $beforerules = null;

    /** @var int pcre.backtrack_limit while a rule pattern runs (PHP's default is 1,000,000). */
    public const BACKTRACK_LIMIT = 100000;

    /** @var array<string, array> Site rules by raw config value (per request). */
    private static array $sitecache = [];

    /**
     * Crisis phrases that are first person by their own wording, matched as keywords. Spanish, French, German,
     * Portuguese, Arabic and Chinese phrases are only those whose wording is unambiguous. Left out on purpose:
     * idioms ("this will kill me", French "me tuer", Spanish "matarme", Portuguese "me matar", German "mich
     * umbringen" as in "my dad will kill me"), phrases that also ask whether something can hurt you (Spanish
     * "hacerme daño", French "me faire du mal", German "mich selbst verletzen", Chinese "伤害自己" are only listed
     * with "I want to"), and academic mentions ("why does the character commit suicide").
     */
    private const WELLBEING_PHRASES = [
        // English.
        'kill myself', 'killing myself', 'hang myself', 'end my life', 'ending my life', 'take my own life',
        'taking my own life', 'wish i was dead', 'wish i were dead', 'better off without me',
        // Spanish.
        'quiero morir', 'quiero morirme', 'suicidarme', 'quitarme la vida', 'no quiero vivir', 'quiero hacerme daño',
        // French.
        'je veux mourir', 'me suicider', 'mettre fin à mes jours', 'je veux me faire du mal', 'je ne veux plus vivre',
        // German.
        'ich will sterben', 'ich möchte sterben', 'ich will mich umbringen', 'mir das leben nehmen',
        'suizidgedanken', 'selbstmordgedanken', 'ich will mich selbst verletzen', 'ich verletze mich selbst',
        // Portuguese.
        'quero morrer', 'me suicidar', 'tirar minha vida', 'tirar a minha vida', 'não quero mais viver',
        // Arabic: "I want to die", "kill myself", "I will commit suicide".
        'أريد أن أموت', 'أقتل نفسي', 'سأنتحر',
        // Chinese: "don't want to live", "want to kill myself", "I want to hurt myself".
        '不想活', '想自杀', '我想伤害自己',
    ];

    /** @var string "I", "I'm", "I've", "I'd", "I'll" at a word boundary. */
    private const I = "\\bi(?:'m|'ve|'d|'ll)?";

    /** @var string Words that may sit between "I" and a crisis phrase without changing who it is about. */
    private const IGAP = '(?:\s+(?:am|was|will|have|been|keep|just|really|honestly|still|so|seriously|sometimes|often|'
        . 'want\s+to|wanna|going\s+to|gonna|might|could|plan(?:ning)?\s+to|feel(?:ing)?|felt|think(?:ing)?\s+(?:about|of)))*';

    /**
     * Crisis phrases that need a first-person frame, as patterns. "Suicidal", "want to die", "hurt myself" and
     * "self-harm" alone also appear in coursework ("risk factors for suicidal patients", "why does Hamlet want to
     * die", "I hurt myself playing football"), so each is tied to "I" with only the words in IGAP between, and
     * hurting oneself needs an intent or a habit ("I want to", "I keep", "I've been").
     *
     * @return string[]
     */
    private static function wellbeing_patterns(): array {
        $i = self::I . self::IGAP;
        return [
            $i . '\s+(?:feel(?:ing)?\s+|been\s+)?suicidal\b',
            $i . '\s+(?:having|have|had|get|getting)\s+(?:\w+\s+)?suicidal\s+(?:thoughts|feelings|urges)',
            '\bmy\s+suicidal\s+(?:thoughts|feelings|urges)',
            $i . '\s+(?:thinking|thought|think)\s+(?:about|of)\s+(?:suicide|killing\s+myself|ending\s+it)'
                . '(?!\s+(?:prevention|rates?|research|awareness|risk))',
            $i . '\s+commit(?:ting)?\s+suicide',
            $i . '\s+(?:want\s+to|wanna)\s+die\b',
            $i . '\s+end\s+it\s+all\b',
            self::I . '(?:\s+(?:really|just|still|sometimes|often|so))*\s+(?:want\s+to|wanna|am\s+going\s+to|going\s+to|'
                . 'gonna|keep|have\s+been|been|feel\s+like|might|will|can\'t\s+stop|need\s+to)\s+'
                . '(?:hurt|harm|cut|burn)(?:ing)?\s+myself',
            "\\bi(?:'m|\\s+am)\\s+(?:hurting|harming|cutting|burning)\\s+myself",
            '\bi\s+cut\s+myself\s+(?:again|last\s+night|tonight|today|yesterday|this\s+morning|on\s+purpose)\b',
            "\\bi(?:'ve|\\s+have)\\s+been\\s+self[\\s-]?harming|\\bi(?:'m|\\s+am)\\s+self[\\s-]?harming",
            "\\bi(?:\\s+have|'ve)?\\s+self[\\s-]?harm(?:ed)?\\b|\\bmy\\s+self[\\s-]?harm(?:ing)?\\b",
            "\\bmy\\s+life\\s+(?:is\\s+not|isn't|is\\s+no\\s+longer|feels)\\s+(?:\\w+\\s+)?worth\\s+living",
            $i . '\s+(?:(?:take|taking|took|taken)\s+an?\s+overdose|overdos(?:e|ing)\b)',
            "\\bi\\s+(?:don't|dont|do\\s+not)\\s+want\\s+to\\s+(?:live|be\\s+alive|exist)(?:\\s+any\\s*more)?(?=\\s*(?:[.!?,;]|$))",
            "\\b(?:i\\s+have|i've\\s+got|there's)\\s+no(?:thing)?\\s+(?:reason|point)\\s+(?:for\\s+me\\s+)?(?:to\\s+live|in\\s+living)",
            "\\b(?:i'd|i\\s+would)\\s+be\\s+better\\s+off\\s+dead\\b",
            // Chinese "I want to die", but not "我想死你了" ("I miss you so much").
            '我(?:真的|好|很|就|都)?想死(?!你|您|他|她|它)',
        ];
    }

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

    /**
     * @var string[] A date as an answer would state it, captured for the support check: Month DD, DD Month, or a
     *     numeric date WITH a year (a bare 3/4 is a fraction). The day ends at a word boundary, so "March 2020" is
     *     not "March 20".
     */
    private const DATES = [
        '(' . self::MONTH . '\.?\s+\d{1,2}(?:st|nd|rd|th)?)\b',
        '(\d{1,2}(?:st|nd|rd|th)?\s+(?:of\s+)?' . self::MONTH . ')\b',
        '(\d{1,2}/\d{1,2}/\d{2,4})\b',
    ];

    /** @var string What a learner might try to get round: Turnitin, a proctor, or a plagiarism, AI or similarity
     *     checker or detector. */
    private const EVADE_OBJECT = '(?:turnitin|proctor\w*|(?:plagiarism|ai|similarity)[\s-]+(?:detect|check|scan)\w*)';

    /** @var string Getting round one of them. A bare "avoid detection" (insects, malware) does not match. */
    private const EVADE = '(?:avoid|bypass|evade|beat|fool|trick|get\s+(?:past|around))\s+(?:\w+\s+)?'
        . self::EVADE_OBJECT;

    /** @var string The category the existing runtime validators' rules carry (validator.<name>). Not an admin choice,
     *     so not in CATEGORIES; consumers must accept it. safe_message() gives it the general text. */
    public const VALIDATOR_CATEGORY = 'general';

    /**
     * The default rule pack. Conservative: block only for wellbeing and for clearly harmful guidance (promising or
     * changing a grade, granting an extension, coaching a learner to evade integrity checks); the rest warns or
     * watches. Each rule's `values` are patterns (each within PATTERN_MAX_LENGTH, pinned by a test) or keywords.
     * `support` (date|policy) means the rule fires only when retrieved course text exists AND does not contain the
     * same date (day and month in either order) or a policy of the same kind; with no retrieved text it never fires,
     * so a course without retrieval is not flooded with warnings.
     *
     * @return array[]
     */
    public static function default_rules(): array {
        $gradeobject = '(?:an?\s+)?(?:(?-i:[A-F])(?:[+-]|\b)|\d{1,3}\s?%|(?:good|high|full|top)\s+(?:grade|mark|score)s?)';
        return [
            // Wellbeing: any wellbeing question. Fired from the question's categories (see evaluate()), so the
            // phrases live in WELLBEING_PHRASES and wellbeing_patterns(). Block: an AI tutor is not crisis support,
            // and the learner needs a person.
            ['id' => self::WELLBEING_RULE, 'category' => 'wellbeing', 'kind' => 'keyword', 'applies_to' => 'question',
                'action' => 'block', 'values' => self::WELLBEING_PHRASES],
            // A guaranteed grade or pass for the learner ("I guarantee you will pass", "you're guaranteed an A").
            // Block: SOLA cannot know or grant a grade and a learner may stop working on the strength of it. Needs
            // "you" and a grade or pass, so "the government guarantees the loan" and "tests guarantee your code will
            // pass" do not match.
            ['id' => 'grades.guarantee', 'category' => 'grades', 'kind' => 'pattern', 'applies_to' => 'reply',
                'action' => 'block', 'values' => [
                    "\\b(?:i|we)\\s+(?:can\\s+)?guarantee\\s+(?:that\\s+)?you(?:'ll|\\s+will)\\s+pass\\b",
                    "\\b(?:i|we)\\s+(?:can\\s+)?guarantee\\s+(?:that\\s+)?you(?:'ll|\\s+will)\\s+(?:get|receive|earn)\\s+"
                        . $gradeobject,
                    "\\byou(?:'re|\\s+are)\\s+guaranteed\\s+to\\s+pass\\b",
                    "\\byou(?:'re|\\s+are)\\s+guaranteed\\s+(?:to\\s+(?:get|receive|earn)\\s+)?" . $gradeobject,
                    '\byour\s+(?:grade|mark|score|pass)\s+is\s+guaranteed\b',
                ]],
            // A claim to have changed a grade. Block: SOLA has no access to the gradebook, so this is always false.
            ['id' => 'grades.changed', 'category' => 'grades', 'kind' => 'pattern', 'applies_to' => 'reply',
                'action' => 'block', 'values' => [
                    "\\bI(?:'ve|\\s+have)\\s+(?:changed|updated|raised|increased|adjusted|bumped|corrected)\\s+your\\s+"
                        . '(?:[\w-]+\s+){0,2}(?:grades?|scores?|marks?)\b',
                ]],
            // A certain outcome ("you will definitely get an A"). Watch: a confident prediction, recorded for review.
            // Conditional arithmetic ("if you score 85% you'll get a B") does not match.
            ['id' => 'grades.predicted', 'category' => 'grades', 'kind' => 'pattern', 'applies_to' => 'reply',
                'action' => 'watch', 'values' => [
                    "\\byou(?:'ll|\\s+will|'re\\s+going\\s+to|\\s+are\\s+going\\s+to)\\s+(?:definitely|certainly|surely|"
                        . 'automatically|easily)\s+(?:get|receive|earn|pass)\b',
                ]],
            // A granted extension on a piece of work. Block: only the instructor can grant one, and a learner who
            // believes SOLA may miss the real deadline. Needs a granting frame AND a deadline or piece of work, so
            // "a file extension list" and "a field extension" do not match.
            ['id' => 'deadlines.extension_granted', 'category' => 'deadlines', 'kind' => 'pattern',
                'applies_to' => 'reply', 'action' => 'block', 'values' => [
                    "\\b(?:i|we)\\b(?:'\\w+|\\s+\\w+)?\\s+(?:grant|giv|approv)\\w*\\s+(?:\\S+\\s+){0,3}extension\\s+(?:on|for|to)\\s+"
                        . '(?:\S+\s+){0,3}?(?:deadline|assignment|essay|exam|quiz|test|project|paper|due\s+date)',
                    '\byour\s+extension\s+(?:request\s+)?(?:is|has\s+been|was)\s+(?:approved|granted|confirmed)\b',
                    "\\b(?:i|we)(?:'ve|\\s+have)\\s+extended\\s+(?:your|the)\\s+(?:[\\w-]+\\s+){0,2}(?:deadline|due\\s+date)",
                ]],
            // A due date the retrieved course text does not contain. Warn: it may be right, but an invented date
            // costs marks. "Due to" is not a due date.
            ['id' => 'deadlines.invented_date', 'category' => 'deadlines', 'kind' => 'pattern', 'applies_to' => 'reply',
                'action' => 'warn', 'support' => 'date', 'values' => array_map(
                    static fn(string $date): string => '\b(?:due(?!\s+to\b)|deadline|closes?)\b[^.\n]{0,40}?\b' . $date,
                    self::DATES)],
            // A stated institutional policy (refund, withdrawal, transfer...) of a kind the retrieved course text
            // has no policy for. Warn: policy questions belong to student support, and a made-up policy can cost
            // money. The kind is captured, so "the late policy" matches a course's "Late Work Policy".
            ['id' => 'policy.invented', 'category' => 'policy', 'kind' => 'pattern', 'applies_to' => 'reply',
                'action' => 'warn', 'support' => 'policy', 'values' => [
                    "\\b(?:the|our|its|their|[\\w-]+'s)\\s+(refund|withdrawal|late|attendance|enrol+ment|transfer|grading|"
                        . 'appeals?|extension)(?:\s+[\w-]+)?\s+policy\s+(?:is|states|says|allows|permits|requires|lets)\b',
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
            // Coaching to get round a plagiarism or AI checker, Turnitin or a proctor. Block: clearly harmful to
            // the learner and the course. Needs one of those as the object AND an instructional frame, so a refusal
            // ("you shouldn't try to avoid plagiarism detection") and "to avoid detection by predators" do not match.
            ['id' => 'integrity.cheat_help', 'category' => 'integrity', 'kind' => 'pattern', 'applies_to' => 'reply',
                'action' => 'block', 'values' => [
                    '(?:^|[.!?:]\s+|\n)\s*to\s+' . self::EVADE,
                    '\byou\s+(?:can|could)\s+' . self::EVADE,
                    "\\bhere(?:'s|\\s+is)\\s+how\\s+to\\s+" . self::EVADE,
                    '\bthe\s+(?:trick\s+is|best\s+way)\s+to\s+' . self::EVADE,
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
            if (self::is_wellbeing_text($text)) {
                $found['wellbeing'] = true;
            }
            foreach (self::QUESTION_KEYWORDS as $category => $keywords) {
                if (self::keywords_match($keywords, $text)) {
                    $found[$category] = true;
                }
            }
            if (is_array(self::pattern_match(self::NUMBERS_QUESTION_PATTERN, $text))) {
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
     * Whether a question reads as a learner in distress or crisis (the built-in phrases and patterns only).
     *
     * @param string $text normalised
     * @return bool
     */
    private static function is_wellbeing_text(string $text): bool {
        if ($text === '' || self::keywords_match(self::WELLBEING_PHRASES, $text)) {
            return $text !== '';
        }
        foreach (self::wellbeing_patterns() as $pattern) {
            if (is_array(self::pattern_match($pattern, $text))) {
                return true;
            }
        }
        return false;
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
     *     error: ?string, pcre_errors: string[]} text is what to deliver instead of the answer (block: the safe message; warn: the answer
     *     plus the check line), null to deliver it unchanged (none, watch) or to retry (correct). fired holds
     *     [id, category, action] per rule, for the audit row; a validator rule's category is VALIDATOR_CATEGORY
     *     ('general'), which is not one of CATEGORIES. error is the exception class when the check failed (the
     *     answer is then delivered unchanged, EXCEPT that a wellbeing question still gets its block). pcre_errors
     *     lists rules whose pattern hit the PCRE backtrack limit; each is also fired as 'watch'.
     */
    public static function evaluate(string $reply, array $ctx): array {
        $result = ['action' => 'none', 'rules' => [], 'text' => null, 'fired' => [], 'categories' => [],
            'error' => null, 'pcre_errors' => []];
        // Wellbeing first, on its own: if anything after this throws, a learner in crisis still gets the safe
        // message rather than the answer.
        $mode = 'off';
        $wellbeing = false;
        try {
            $mode = self::mode_from_context($ctx);
            if ($mode === 'off') {
                return $result;
            }
            $wellbeing = isset($ctx['categories']) && is_array($ctx['categories'])
                ? in_array('wellbeing', $ctx['categories'], true)
                : self::is_wellbeing_text(self::normalise(is_string($ctx['question'] ?? null) ? $ctx['question'] : ''));
        } catch (\Throwable $e) {
            $result['error'] = get_class($e);
            return $result;
        }
        try {
            if (self::$beforerules) {
                (self::$beforerules)();
            }
            return self::evaluate_rules($reply, $ctx, $mode, $wellbeing, $result);
        } catch (\Throwable $e) {
            $result['error'] = get_class($e);
            if ($wellbeing) {
                $result['categories'] = ['wellbeing'];
                $result['fired'] = [['id' => self::WELLBEING_RULE, 'category' => 'wellbeing', 'action' => 'block']];
                $result['rules'] = [self::WELLBEING_RULE];
                $result['action'] = $mode === 'enforce' ? 'block' : 'watch';
                $result['text'] = $mode === 'enforce' ? self::safe_message('wellbeing') : null;
            }
            return $result;
        }
    }

    /**
     * The body of evaluate() after the mode and the wellbeing check.
     *
     * @param string $reply
     * @param array $ctx
     * @param string $mode watch|enforce
     * @param bool $wellbeing
     * @param array $result the empty result
     * @return array
     */
    private static function evaluate_rules(string $reply, array $ctx, string $mode, bool $wellbeing,
            array $result): array {
        $question = self::normalise(is_string($ctx['question'] ?? null) ? $ctx['question'] : '');
        $answer = self::normalise($reply);
        $categories = isset($ctx['categories']) && is_array($ctx['categories'])
            ? array_values(array_intersect(self::CATEGORIES, $ctx['categories']))
            : self::categories_for_question($question);
        if ($wellbeing && !in_array('wellbeing', $categories, true)) {
            $categories = array_values(array_intersect(self::CATEGORIES, array_merge($categories, ['wellbeing'])));
        }
        $result['categories'] = $categories;
        $chunks = self::chunk_texts($ctx['chunks'] ?? []);
        $chunktext = self::normalise(implode("\n", $chunks));

        $fired = [];
        $fire = static function (string $id, string $category, string $action) use (&$fired): void {
            if (!isset($fired[$id])) {
                $fired[$id] = ['id' => $id, 'category' => $category, 'action' => $action];
            }
        };
        $pcreerrors = [];

        if (in_array('wellbeing', $categories, true)) {
            $fire(self::WELLBEING_RULE, 'wellbeing', 'block');
        }
        foreach (array_merge(self::default_rules(), self::site_rules()) as $rule) {
            if ($rule['id'] === self::WELLBEING_RULE) {
                continue;
            }
            $hit = false;
            if ($rule['applies_to'] !== 'reply') {
                $hit = self::rule_matches($rule, $question, null, $pcreerrors);
            }
            if ($hit !== true && $rule['applies_to'] !== 'question') {
                $hit = self::rule_matches($rule, $answer, $chunktext, $pcreerrors) ?: $hit;
            }
            if ($hit === true) {
                $fire($rule['id'], $rule['category'], $rule['action']);
            }
        }
        // A rule whose pattern hit the PCRE limit could not be checked: it fails open, but visibly, as a watch
        // (recorded and queued for review) and listed in pcre_errors.
        foreach (array_unique($pcreerrors) as $id) {
            $result['pcre_errors'][] = $id;
            $fire($id, self::VALIDATOR_CATEGORY, 'watch');
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
     * @param bool $timing run the samples and the time budget (at save time); site_rules() passes false, so a rule
     *     that was saved is never dropped because the server happens to be busy when it loads
     * @return array<string, string> error code => message
     */
    public static function validate_rule(array $rule, bool $timing = true): array {
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
            $errors += self::pattern_errors($value, $timing);
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
        $key = sha1($raw);
        if (isset(self::$sitecache[$key])) {
            return self::$sitecache[$key];
        }
        $rules = [];
        foreach (self::decode_rules($raw) as $rule) {
            if (self::validate_rule($rule, false)) {
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
                $fire('validator.' . $name, self::VALIDATOR_CATEGORY, 'block');
            } else if ($vmode === 'annotate') {
                $fire('validator.' . $name, self::VALIDATOR_CATEGORY, 'warn');
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
     * @param array $rule with kind, values and optionally support (date|policy)
     * @param string $text normalised
     * @param string|null $chunktext normalised retrieved course text for a `support` rule; null on the question side
     * @param string[] $pcreerrors receives the rule id when a pattern hit the PCRE limit
     * @return bool
     */
    private static function rule_matches(array $rule, string $text, ?string $chunktext,
            array &$pcreerrors = []): bool {
        if ($text === '') {
            return false;
        }
        if ($rule['kind'] === 'keyword') {
            return self::keywords_match($rule['values'], $text);
        }
        $support = $rule['support'] ?? null;
        if ($support !== null && trim((string) $chunktext) === '') {
            // Nothing retrieved: nothing to say the answer is unsupported by.
            return false;
        }
        foreach ($rule['values'] as $pattern) {
            $m = self::pattern_match($pattern, $text);
            if ($m === false) {
                $pcreerrors[] = $rule['id'];
                continue;
            }
            if ($m === null) {
                continue;
            }
            if ($support === null) {
                return true;
            }
            $found = $m[1] ?? $m[0];
            $supported = $support === 'date' ? self::date_supported($found, (string) $chunktext)
                : self::policy_supported($found, (string) $chunktext);
            if (!$supported) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the course text holds the same date (day and month, in either order or as a numeric date).
     *
     * @param string $date as captured from the answer
     * @param string $chunktext
     * @return bool
     */
    private static function date_supported(string $date, string $chunktext): bool {
        $wanted = self::date_keys($date);
        if (!$wanted) {
            return true;
        }
        $pattern = self::DELIM . '(?:' . implode('|', array_map(static fn($d) => substr($d, 0, -2), self::DATES))
            . ')\b' . self::DELIM . 'iu';
        $have = [];
        if (preg_match_all($pattern, $chunktext, $all)) {
            foreach ($all[0] as $one) {
                $have = array_merge($have, self::date_keys($one));
            }
        }
        // Numeric dates in the course text without a year still count (a schedule may write 3/14).
        if (preg_match_all('~\b(\d{1,2})/(\d{1,2})\b~', $chunktext, $nums, PREG_SET_ORDER)) {
            foreach ($nums as $n) {
                $have[] = (int) $n[1] . '-' . (int) $n[2];
                $have[] = (int) $n[2] . '-' . (int) $n[1];
            }
        }
        return (bool) array_intersect($wanted, $have);
    }

    /**
     * Month-day keys for a date ("3-14"); a numeric date gives both readings.
     *
     * @param string $date
     * @return string[]
     */
    private static function date_keys(string $date): array {
        $date = \core_text::strtolower($date);
        $months = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
        if (preg_match('~^(\d{1,2})/(\d{1,2})~', $date, $m)) {
            return [(int) $m[1] . '-' . (int) $m[2], (int) $m[2] . '-' . (int) $m[1]];
        }
        if (preg_match('~([a-z]{3})[a-z]*\.?\s+(\d{1,2})~', $date, $m) || preg_match('~(\d{1,2})(?:st|nd|rd|th)?\s+'
                . '(?:of\s+)?([a-z]{3})~', $date, $m)) {
            [$mon, $day] = ctype_digit($m[1]) ? [$m[2], $m[1]] : [$m[1], $m[2]];
            $i = array_search($mon, $months, true);
            return $i === false ? [] : [($i + 1) . '-' . (int) $day];
        }
        return [];
    }

    /**
     * Whether the course text has a policy of this kind ("late" matches "Late Work Policy", "policy on late work").
     *
     * @param string $kind the captured kind (refund, late, transfer...)
     * @param string $chunktext
     * @return bool
     */
    private static function policy_supported(string $kind, string $chunktext): bool {
        $stem = preg_quote(substr(\core_text::strtolower($kind), 0, 4), '~');
        return (bool) preg_match('~\b' . $stem . '\w*(?:\s+[\w-]+){0,2}\s+polic(?:y|ies)\b'
            . '|\bpolic(?:y|ies)\s+(?:on|for|about|regarding)\s+(?:[\w-]+\s+){0,2}' . $stem . '~iu', $chunktext);
    }

    /**
     * Run one pattern under a lower PCRE backtrack limit, restored afterwards.
     *
     * @param string $pattern
     * @param string $text
     * @return array|null|false the match groups, null for no match, false when PCRE stopped (limit or bad input)
     */
    private static function pattern_match(string $pattern, string $text) {
        $old = ini_get('pcre.backtrack_limit');
        @ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);
        try {
            $r = @preg_match(self::DELIM . $pattern . self::DELIM . 'iu', $text, $m);
        } finally {
            if ($old !== false) {
                @ini_set('pcre.backtrack_limit', $old);
            }
        }
        if ($r === false) {
            return false;
        }
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
     * @param bool $timing run the samples and the time budget (save time only)
     * @return array<string, string>
     */
    private static function pattern_errors(string $pattern, bool $timing = true): array {
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
        if (!$timing) {
            return [];
        }
        $start = microtime(true);
        foreach (self::samples() as $sample) {
            if (self::pattern_match($pattern, $sample) === false) {
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
