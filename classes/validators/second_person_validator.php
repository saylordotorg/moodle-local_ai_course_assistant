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

namespace local_ai_course_assistant\validators;

/**
 * Flags assistant turns that refer to the learner in the third person.
 *
 * Surfaced by v3.4.7 production usability testing: SOLA occasionally drifts
 * into third-person prose ("the student should review chapter 3") instead
 * of addressing the learner directly. This validator catches the most
 * common third-person patterns so we can gate them out of releases.
 *
 * Detection is intentionally conservative: only multi-token phrases that
 * are unambiguously third-person ABOUT the learner trigger a fail. Phrases
 * like "students typically struggle here" (talking ABOUT the cohort, not
 * THIS learner) and structural mentions ("learner profile") are allowed.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class second_person_validator implements validator_interface {

    /**
     * Phrases that almost always mean "talking about the learner I'm
     * supposed to be addressing directly." Each is matched as a
     * case-insensitive whole-word phrase.
     */
    private const THIRD_PERSON_PATTERNS = [
        // Direct references to "the X" where X is the learner.
        '/\bthe student\b/i',
        '/\bthe learner\b/i',
        '/\bthe user\b/i',
        // "X should/must/will/can" style imperatives, third-person framed.
        '/\bthe student (should|must|will|can|may|needs to|has to|might want to)\b/i',
        '/\bthe learner (should|must|will|can|may|needs to|has to|might want to)\b/i',
        // Recommended construction.
        '/\bit is recommended that the (student|learner|user)\b/i',
        '/\bthe (student|learner|user) is encouraged to\b/i',
    ];

    public function name(): string {
        return 'second_person';
    }

    public function validate(string $output, array $context = []): result {
        $matches = [];
        foreach (self::THIRD_PERSON_PATTERNS as $pattern) {
            if (preg_match($pattern, $output, $m)) {
                $matches[] = $m[0];
            }
        }
        // Dedupe; first match per pattern is enough.
        $matches = array_values(array_unique($matches));

        if (empty($matches)) {
            return result::pass($this->name());
        }

        $messages = [];
        foreach ($matches as $phrase) {
            $messages[] = "Third-person reference to learner: \"{$phrase}\". Address the learner directly with \"you\".";
        }
        return result::fail($this->name(), $messages, ['phrases' => $matches]);
    }
}
