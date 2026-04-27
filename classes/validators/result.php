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
 * Outcome of a single validator run.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result {

    /** Validator approved the output. */
    const SEVERITY_PASS = 'pass';
    /** Soft signal — log but do not block. */
    const SEVERITY_INFO = 'info';
    /** Soft fail — log and may downgrade response. */
    const SEVERITY_WARN = 'warn';
    /** Hard fail — block delivery. */
    const SEVERITY_FAIL = 'fail';

    public string $validator;
    public string $severity;
    /** @var string[] */
    public array $messages;
    /** @var array<string,mixed> */
    public array $details;

    public function __construct(
        string $validator,
        string $severity,
        array $messages = [],
        array $details = []
    ) {
        $this->validator = $validator;
        $this->severity = $severity;
        $this->messages = $messages;
        $this->details = $details;
    }

    public static function pass(string $validator, array $details = []): self {
        return new self($validator, self::SEVERITY_PASS, [], $details);
    }

    public static function fail(string $validator, array $messages, array $details = []): self {
        return new self($validator, self::SEVERITY_FAIL, $messages, $details);
    }

    public static function warn(string $validator, array $messages, array $details = []): self {
        return new self($validator, self::SEVERITY_WARN, $messages, $details);
    }

    public function passed(): bool {
        return $this->severity === self::SEVERITY_PASS;
    }

    public function blocked(): bool {
        return $this->severity === self::SEVERITY_FAIL;
    }

    public function to_array(): array {
        return [
            'validator' => $this->validator,
            'severity' => $this->severity,
            'messages' => $this->messages,
            'details' => $this->details,
        ];
    }
}
