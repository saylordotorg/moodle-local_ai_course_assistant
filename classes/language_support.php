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
 * The languages SOLA answers in, and how a reply language is chosen.
 *
 * SOLA answers in 46 languages. The language of a reply follows, in priority
 * order:
 *   1. the language the learner's question is written in -- but when that
 *      differs from a language the learner SAVED in SOLA, SOLA asks first and
 *      only then changes the saved setting;
 *   2. the language saved in SOLA's language picker;
 *   3. the browser's language.
 *
 * The browser sends the effective code plus where it came from ('saved' or
 * 'default'); this class turns that into the prompt text. It is a plain value
 * class so the rule can be unit tested without a model.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class language_support {

    /** The learner chose this language in SOLA's language picker. */
    public const SOURCE_SAVED = 'saved';

    /**
     * The learner saved this language, and this surface cannot ask a question
     * (voice, or a question already asked): answer in it, whatever they say.
     */
    public const SOURCE_PINNED = 'pinned';

    /** Nothing saved: the code came from the browser (or Moodle) as a default. */
    public const SOURCE_DEFAULT = 'default';

    /** The course locks SOLA to English. Set by the server from course config, never taken from the browser. */
    public const SOURCE_LOCKED = 'locked';

    /**
     * Supported languages, ISO 639-1 code => English name.
     *
     * Keep in step with SUPPORTED_LANGS in amd/src/speech.js, which also carries
     * each language's own name for the confirmation buttons.
     *
     * @return array<string,string>
     */
    public static function names(): array {
        return [
            'en' => 'English', 'ar' => 'Arabic', 'zh' => 'Chinese', 'cs' => 'Czech',
            'da' => 'Danish', 'nl' => 'Dutch', 'fi' => 'Finnish', 'fr' => 'French',
            'de' => 'German', 'bg' => 'Bulgarian', 'el' => 'Greek', 'he' => 'Hebrew',
            'hi' => 'Hindi', 'hu' => 'Hungarian', 'id' => 'Indonesian', 'it' => 'Italian',
            'ja' => 'Japanese', 'ko' => 'Korean', 'nb' => 'Norwegian', 'pl' => 'Polish',
            'pt' => 'Portuguese', 'ro' => 'Romanian', 'ru' => 'Russian', 'sk' => 'Slovak',
            'es' => 'Spanish', 'sv' => 'Swedish', 'ta' => 'Tamil', 'th' => 'Thai',
            'tr' => 'Turkish', 'uk' => 'Ukrainian', 'vi' => 'Vietnamese',
            'bn' => 'Bengali', 'tl' => 'Filipino', 'ms' => 'Malay', 'pa' => 'Punjabi',
            'am' => 'Amharic', 'ne' => 'Nepali', 'sw' => 'Swahili', 'zu' => 'Zulu',
            'bm' => 'Bambara', 'ha' => 'Hausa', 'ig' => 'Igbo', 'om' => 'Oromo',
            'so' => 'Somali', 'wo' => 'Wolof', 'yo' => 'Yoruba',
        ];
    }

    /**
     * Whether a code is one of the supported languages.
     *
     * @param string $code
     * @return bool
     */
    public static function is_supported(string $code): bool {
        return isset(self::names()[strtolower($code)]);
    }

    /**
     * English name for a supported code, or '' when unsupported.
     *
     * @param string $code
     * @return string
     */
    public static function name(string $code): string {
        return self::names()[strtolower($code)] ?? '';
    }

    /**
     * Reduce a client-supplied value to a supported two-letter code, or ''.
     *
     * @param string $code Raw request value (PARAM_ALPHA already applied).
     * @return string
     */
    public static function normalise(string $code): string {
        $code = strtolower(trim($code));
        return self::is_supported($code) ? $code : '';
    }

    /**
     * Reduce a comma-separated list of codes to the supported ones, de-duplicated.
     *
     * @param string $codes e.g. "es,fr"
     * @return string Comma-separated, possibly empty.
     */
    public static function normalise_list(string $codes): string {
        $out = [];
        foreach (explode(',', $codes) as $code) {
            $code = self::normalise($code);
            if ($code !== '' && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }
        return implode(',', array_slice($out, 0, 10));
    }

    /**
     * Reduce the client's "where did this language come from" flag to a known value.
     *
     * Anything unrecognised is treated as a default, the weaker claim: a client
     * that cannot say it was a saved choice never makes SOLA ask before answering.
     * 'locked' is deliberately not accepted here: only the server, reading the
     * course's English-lock setting, may claim it.
     *
     * @param string $source
     * @return string One of the SOURCE_* constants.
     */
    public static function normalise_source(string $source): string {
        $source = strtolower(trim($source));
        return in_array($source, [self::SOURCE_SAVED, self::SOURCE_PINNED], true) ? $source : self::SOURCE_DEFAULT;
    }

    /**
     * The "Multilingual Support" section of the system prompt.
     *
     * @param string $lang Effective language code ('' when the client sent none).
     * @param string $source One of the SOURCE_* constants.
     * @param string $hold Comma-separated languages the learner already declined this session ('' for none).
     * @return string
     */
    public static function prompt_section(string $lang, string $source, string $hold = ''): string {
        $lang = self::normalise($lang);
        $hold = self::normalise_list($hold);
        $heldnames = array_map(static function (string $code): string {
            return self::name($code) . " ({$code})";
        }, $hold === '' ? [] : explode(',', $hold));
        // 'locked' is set by the server from course config and passes straight through;
        // anything else a caller supplies is reduced to a value the browser may claim.
        $source = $source === self::SOURCE_LOCKED ? $source : self::normalise_source($source);
        $langname = self::name($lang);

        $out = "\n\n## Multilingual Support\n"
            . "You are fluent in the top 100 world languages. SOLA officially supports these "
            . count(self::names()) . ": "
            . implode(', ', array_map(static function (string $code, string $name): string {
                return "{$name} ({$code})";
            }, array_keys(self::names()), self::names())) . ".\n"
            . "Keep technical terms and course-specific vocabulary in the original language when that helps the student.\n";

        if ($source === self::SOURCE_LOCKED && $lang !== '') {
            // English-language-learner courses: the lock wins over everything.
            return $out . "\n**IMPORTANT: This course is locked to {$langname} ({$lang}). "
                . "Respond in {$langname} for every message, whatever language the student writes in. "
                . "Never offer to switch language.**";
        }

        if ($source === self::SOURCE_PINNED && $lang !== '') {
            return $out . "\n**IMPORTANT: The student saved {$langname} ({$lang}) as their SOLA language. "
                . "Respond in {$langname} for every message, whatever language the student writes in and whatever "
                . "language earlier turns were in. Do not ask about switching language.**";
        }

        if ($source === self::SOURCE_SAVED && $lang !== '') {
            $out .= "\n**Reply language. The student saved {$langname} ({$lang}) as their SOLA language.** "
                . "Before answering, work out what language the student's LATEST message is written in, "
                . "and ignore the language of earlier turns.\n"
                . "1. If it is {$langname}, or you cannot tell (a very short message, a name, a number, code, "
                . "or text mixing languages), answer in {$langname}.\n";
            if ($hold !== '') {
                $heldlist = implode(' or ', $heldnames);
                $out .= "2. The student already chose to stay in {$langname} when they wrote in {$heldlist} earlier in "
                    . "this session. Do NOT ask about {$heldlist} again: answer in {$langname}.\n"
                    . "3. If it is clearly written in some OTHER supported language, ask before switching, as below.\n";
            } else {
                $out .= "2. If it is clearly written in a different supported language, ask before switching, as below.\n";
            }
            $out .= "\n**Asking before switching.** When the student's latest message is a full sentence or question "
                . "clearly written in a supported language other than {$langname}, do NOT answer it yet. "
                . "Do not change language on your own. Reply with exactly this and nothing else:\n"
                . "[SOLA_LANG_SWITCH]xx[/SOLA_LANG_SWITCH]\n"
                . "followed by one or two short sentences written in THAT language (the one the student wrote in), "
                . "saying you noticed they wrote in it, that their SOLA language is currently {$langname}, and asking "
                . "whether they would like to switch SOLA to it. Replace xx with its two-letter code from the list "
                . "above. Add no suggestion block, no source marker and no answer to the question: "
                . "the student will confirm, and the question will be sent again. This is the one reply that does not "
                . "end with the suggestion block, whatever other instructions say.\n"
                . "Do NOT ask when the student is translating, quoting, practicing a language, asking what a word "
                . "means, or asking you to write in a language for an assignment: those are about the language, "
                . "not a request to change it. Do the same when the student explicitly asks you to switch language "
                . "(ask once to confirm, using the same reply).\n"
                . "\n**When the student answers that question in their own words** (your previous reply was the "
                . "switch question and their latest message is a short yes, no, or the name of a language, in any language): "
                . "answer the question they asked BEFORE it, then add on the last line "
                . "[SOLA_LANG_SET]xx[/SOLA_LANG_SET] if they agreed (answer in that language, xx its code) or "
                . "[SOLA_LANG_KEEP]xx[/SOLA_LANG_KEEP] if they did not (answer in {$langname}, xx the code you asked about). "
                . "Add these tags only in that situation, never otherwise.\n";
            return $out;
        }

        // Nothing saved: follow the question, then the browser default.
        $out .= "\n**Reply language.** The student has not chosen a SOLA language. "
            . "Answer in the language their LATEST message is written in, whichever of the supported languages it is, "
            . "and ignore the language of earlier turns. "
            . "If you cannot tell (a very short message, a name, a number or code), "
            . ($lang !== ''
                ? "use {$langname} ({$lang}), their browser's language"
                : "use English")
            . ". Do not ask before switching; there is no saved setting to protect.\n"
            . "If a student explicitly asks you to switch to a particular language, do so and keep using it.";
        return $out;
    }
}
