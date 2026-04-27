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
 * Contract for SOLA output validators.
 *
 * Validators inspect an AI response (and optionally the learner input
 * that produced it) and report whether the response is safe to deliver.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface validator_interface {

    /**
     * Validate an AI response.
     *
     * @param string $output The AI-generated response under inspection.
     * @param array $context Optional context. Recognized keys:
     *                       'input' (string) — the learner message;
     *                       'userid' (int), 'courseid' (int).
     * @return result
     */
    public function validate(string $output, array $context = []): result;

    /**
     * Stable machine name, used in CLI output and audit logs.
     *
     * @return string
     */
    public function name(): string;
}
