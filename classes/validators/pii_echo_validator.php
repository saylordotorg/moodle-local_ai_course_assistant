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
 * Flags AI responses that echo PII present in the learner's input.
 *
 * Detects email addresses, US-style phone numbers, SSN-shaped digit
 * runs, and credit-card-shaped digit runs (Luhn-validated). When the
 * same PII token appears in both input and output, the response is
 * blocked — even harmless echoes show up in screenshots and shared
 * chat logs.
 *
 * If no input is supplied in $context, the validator falls back to
 * scanning the output alone for credit-card-shaped tokens (the highest
 * blast-radius case).
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pii_echo_validator implements validator_interface {

    private const RE_EMAIL = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';
    private const RE_PHONE = '/(?<!\d)(?:\+?1[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}(?!\d)/';
    private const RE_SSN   = '/(?<!\d)\d{3}-\d{2}-\d{4}(?!\d)/';
    private const RE_CC    = '/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/';

    public function name(): string {
        return 'pii_echo';
    }

    public function validate(string $output, array $context = []): result {
        $input = (string) ($context['input'] ?? '');

        $echoes = [];

        foreach ($this->extract(self::RE_EMAIL, $input) as $token) {
            if (stripos($output, $token) !== false) {
                $echoes[] = ['kind' => 'email', 'token' => $this->mask($token)];
            }
        }

        foreach ($this->extract(self::RE_PHONE, $input) as $token) {
            $normin = $this->normalize_digits($token);
            foreach ($this->extract(self::RE_PHONE, $output) as $out) {
                if ($this->normalize_digits($out) === $normin) {
                    $echoes[] = ['kind' => 'phone', 'token' => $this->mask($token)];
                    break;
                }
            }
        }

        foreach ($this->extract(self::RE_SSN, $input) as $token) {
            if (strpos($output, $token) !== false) {
                $echoes[] = ['kind' => 'ssn', 'token' => $this->mask($token)];
            }
        }

        foreach ($this->extract(self::RE_CC, $output) as $candidate) {
            $digits = preg_replace('/\D/', '', $candidate);
            if ($digits === null || strlen($digits) < 13 || strlen($digits) > 19) {
                continue;
            }
            if (!$this->luhn($digits)) {
                continue;
            }
            $echoes[] = ['kind' => 'credit_card', 'token' => $this->mask($digits)];
        }

        if (empty($echoes)) {
            return result::pass($this->name());
        }

        $messages = [];
        foreach ($echoes as $e) {
            $messages[] = "Output echoes {$e['kind']} from input: {$e['token']}";
        }

        return result::fail($this->name(), $messages, ['echoes' => $echoes]);
    }

    private function extract(string $pattern, string $haystack): array {
        if ($haystack === '') {
            return [];
        }
        if (!preg_match_all($pattern, $haystack, $m)) {
            return [];
        }
        return array_values(array_unique($m[0]));
    }

    private function normalize_digits(string $s): string {
        return preg_replace('/\D/', '', $s) ?? '';
    }

    private function luhn(string $digits): bool {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }
        return $sum % 10 === 0;
    }

    private function mask(string $token): string {
        $len = strlen($token);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return substr($token, 0, 2) . str_repeat('*', $len - 4) . substr($token, -2);
    }
}
