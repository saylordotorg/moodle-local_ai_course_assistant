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
 * Composes a sequence of validators into a single guard.
 *
 * Use {@see check()} for a full report (every validator runs); use
 * {@see check_strict()} for short-circuit behavior on the first hard fail.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guard {

    /** @var validator_interface[] */
    private array $validators = [];

    public function add(validator_interface $validator): self {
        $this->validators[] = $validator;
        return $this;
    }

    /**
     * Run every registered validator and return all results.
     *
     * @param string $output AI-generated text to validate.
     * @param array $context Optional context (input, userid, courseid, rag_chunks).
     * @return result[]
     */
    public function check(string $output, array $context = []): array {
        $results = [];
        foreach ($this->validators as $validator) {
            $results[] = $validator->validate($output, $context);
        }
        return $results;
    }

    /**
     * Run validators in order, returning the first hard fail. Returns
     * null when every validator passes (warns are not blocking).
     *
     * @param string $output AI-generated text to validate.
     * @param array $context Optional context (input, userid, courseid, rag_chunks).
     * @return result|null
     */
    public function check_strict(string $output, array $context = []): ?result {
        foreach ($this->validators as $validator) {
            $r = $validator->validate($output, $context);
            if ($r->blocked()) {
                return $r;
            }
        }
        return null;
    }
}
