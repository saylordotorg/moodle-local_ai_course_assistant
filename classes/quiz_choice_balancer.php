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
 * Evens out quiz answer keys after a model has written them (v7.8.2).
 *
 * The October 2026 benchmark found two biases that no model avoided. Three of
 * the four models never keyed D (Sonnet 5 keyed A on 52 of 75 questions), and
 * Haiku 4.5 and Sonnet 5 made the key the longest option on about 70% of
 * questions, so a learner who always picks the longest choice scores 72%.
 * The prompt already asks for lengths within 25% and the models ignore it.
 *
 * Two different tools fix the two biases:
 *  - shuffle() puts the key at a uniformly random letter, so letters carry no
 *    information. It cannot touch length, which moves with the option, and it
 *    leaves a question alone when text in it names a letter.
 *  - flag_long_keys() finds questions whose key stands out by length, so the
 *    caller can have the distractors rewritten (replaced, never the key
 *    shortened, which would change what the question tests) before shuffling.
 *
 * Pure functions with no database or provider calls, so every rule is testable.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_choice_balancer {

    /** @var float A key longer than the longest distractor by more than this share is flagged. */
    public const LONG_KEY_RATIO = 1.2;

    /** @var int Keys this much longer in characters are flagged even when the ratio is small. Below this, ignore. */
    public const MIN_GAP = 6;

    /** @var string Letters, in order. */
    private const LETTERS = 'ABCD';

    /**
     * The text of a choice without its "A) " style label.
     *
     * @param string $choice
     * @return string
     */
    public static function strip_label(string $choice): string {
        return trim((string) preg_replace('/^\s*\(?[A-Da-d][\)\.\:]\s+/u', '', $choice));
    }

    /**
     * Index of the correct choice, or null when the question is not a clean A-D item.
     *
     * @param array $q A question with 'choices' and 'correct'.
     * @return int|null
     */
    private static function key_index(array $q): ?int {
        $n = count($q['choices'] ?? []);
        if ($n < 2 || $n > 4) {
            return null;
        }
        $pos = strpos(self::LETTERS, strtoupper(substr(trim((string) ($q['correct'] ?? '')), 0, 1)));
        return ($pos === false || $pos >= $n) ? null : $pos;
    }

    /**
     * Indexes of questions whose key is noticeably the longest choice.
     *
     * @param array $questions
     * @return int[] Keys into $questions.
     */
    public static function flag_long_keys(array $questions): array {
        $flagged = [];
        foreach ($questions as $i => $q) {
            $k = self::key_index($q);
            if ($k === null) {
                continue;
            }
            $lens = array_map(static fn($c) => \core_text::strlen(self::strip_label((string) $c)), $q['choices']);
            $keylen = $lens[$k];
            unset($lens[$k]);
            $longest = max($lens);
            if ($keylen > $longest * self::LONG_KEY_RATIO && $keylen - $longest >= self::MIN_GAP) {
                $flagged[] = $i;
            }
        }
        return $flagged;
    }

    /**
     * Whether a replacement distractor set leaves the key unremarkable by length.
     *
     * @param string $key Correct choice text, unlabelled.
     * @param string[] $distractors Replacement distractors, unlabelled.
     * @return bool
     */
    public static function distractors_acceptable(string $key, array $distractors): bool {
        $distractors = array_values(array_map(static fn($d) => trim((string) $d), $distractors));
        if (count($distractors) < 1 || count($distractors) > 3) {
            return false;
        }
        $norm = static fn(string $s) => \core_text::strtolower(preg_replace('/\s+/', ' ', $s));
        $seen = [$norm($key) => true];
        foreach ($distractors as $d) {
            if ($d === '' || isset($seen[$norm($d)])) {
                return false;
            }
            $seen[$norm($d)] = true;
        }
        $keylen = \core_text::strlen($key);
        $longest = max(array_map(static fn($d) => \core_text::strlen($d), $distractors));
        return !($keylen > $longest * self::LONG_KEY_RATIO && $keylen - $longest >= self::MIN_GAP);
    }

    /**
     * Replace the distractors of one question, keeping the key where it is.
     *
     * @param array $q A question.
     * @param string[] $distractors New distractor texts (unlabelled), one per old distractor.
     * @return array The question with its choices rewritten and relabelled.
     */
    public static function with_distractors(array $q, array $distractors): array {
        $k = self::key_index($q);
        if ($k === null) {
            return $q;
        }
        $distractors = array_values($distractors);
        $choices = [];
        $d = 0;
        foreach (array_keys($q['choices']) as $i) {
            $text = $i === $k ? self::strip_label((string) $q['choices'][$i]) : trim((string) ($distractors[$d++] ?? ''));
            $choices[] = $text;
        }
        $q['choices'] = self::relabel($choices);
        return $q;
    }

    /**
     * Whether text refers to a choice by letter ("option B", "(C)", "A is correct").
     *
     * Deliberately broad: a false positive only leaves one question in the order
     * the model wrote it, while rewriting such text would also rewrite subject
     * matter ("Vitamin C is the correct answer", "Plan B", "Hepatitis (B)").
     *
     * @param string $text
     * @return bool
     */
    public static function references_letters(string $text): bool {
        return preg_match(
            '/\b(?:options?|choices?|answers?|letters?)\s+(?-i:[A-D])\b|\((?-i:[A-D])\)'
            . '|\b(?-i:[A-D])\s+(?:is|was)\s+(?:the\s+)?(?:correct|incorrect|wrong|right)\b/iu',
            $text
        ) === 1;
    }

    /**
     * Whether a choice's own text depends on the other choices or their order.
     *
     * "All of the above", "Both A and B", "A and C only": moving these makes
     * the correct answer say something else, so such a question is never shuffled.
     *
     * @param array $choices
     * @return bool
     */
    public static function choices_refer_to_each_other(array $choices): bool {
        foreach ($choices as $c) {
            $text = self::strip_label((string) $c);
            if (preg_match(
                '/\b(?:above|below)\b'
                . '|(?-i:\b[A-D]\b)\s*(?:,|and|or|&)\s*(?-i:\b[A-D]\b)'
                . '|\b(?:both|neither|either|options?|choices?|answers?)\s+(?-i:[A-D])\b/iu',
                $text
            ) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Put the key at a random letter and relabel the choices.
     *
     * A question is left exactly as written when it is not a clean A-D item,
     * when a choice depends on the other choices, or when its explanation names
     * a letter, because the shuffle would then make that text point at the wrong
     * option. The prompt asks models not to name letters, so this is the exception.
     *
     * @param array $q A question.
     * @param callable|null $rand fn(int $max): int returning 0..$max, for tests. Defaults to random_int.
     * @return array The question.
     */
    public static function shuffle(array $q, ?callable $rand = null): array {
        $k = self::key_index($q);
        if (
            $k === null
            || self::choices_refer_to_each_other($q['choices'])
            || self::references_letters((string) ($q['explanation'] ?? ''))
        ) {
            return $q;
        }
        $rand = $rand ?? static fn(int $max): int => random_int(0, $max);
        $items = [];
        foreach (array_values($q['choices']) as $i => $c) {
            $items[] = ['text' => self::strip_label((string) $c), 'old' => $i];
        }
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $rand($i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        $choices = [];
        foreach ($items as $new => $item) {
            $choices[] = $item['text'];
            if ($item['old'] === $k) {
                $q['correct'] = self::LETTERS[$new];
            }
        }
        $q['choices'] = self::relabel($choices);
        return $q;
    }

    /**
     * Add "A) " style labels.
     *
     * @param string[] $texts
     * @return string[]
     */
    private static function relabel(array $texts): array {
        $out = [];
        foreach (array_values($texts) as $i => $t) {
            $out[] = self::LETTERS[$i] . ') ' . $t;
        }
        return $out;
    }
}
