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
 * Flags AI output containing API-key or private-credential shapes.
 *
 * Unlike pii_echo_validator, this is unconditional — credentials must
 * never appear in learner-facing output, whether the model fabricated
 * them, hallucinated them from training data, or echoed them from
 * mis-configured context. Each pattern is high-confidence (provider-
 * specific prefix + length floor) to keep false positives rare.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class credential_leak_validator implements validator_interface {

    /** @var array<string,string> kind => regex */
    // Note: no `stripe_secret` / `stripe_publish` corpus fixture lives in
    // tests/security/credential_leak/. GitHub push protection rejects any
    // string matching the canonical Stripe key shape regardless of entropy
    // (literal-EXAMPLE bodies still trip the scanner), so we can't ship a
    // fixture file with a matching token. Coverage is implied by the
    // pattern's symmetry with the OpenAI / Anthropic / GitHub fixtures
    // which all match the same `{prefix}{alphanumeric}{minlength}` shape.
    private const PATTERNS = [
        'openai_key'      => '/\bsk-(?!ant-)[A-Za-z0-9_-]{32,}\b/',
        'anthropic_key'   => '/\bsk-ant-[A-Za-z0-9_-]{32,}\b/',
        'github_token'    => '/\b(?:ghp|gho|ghs|ghu|ghr)_[A-Za-z0-9]{36,}\b/',
        'github_pat'      => '/\bgithub_pat_[A-Za-z0-9_]{40,}\b/',
        'slack_token'     => '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/',
        'stripe_secret'   => '/\bsk_live_[A-Za-z0-9]{24,}\b/',
        'stripe_publish'  => '/\bpk_live_[A-Za-z0-9]{24,}\b/',
        'aws_access_key'  => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        'google_api_key'  => '/\bAIza[0-9A-Za-z_-]{35}\b/',
        'jwt'             => '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
        'private_key'     => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY-----/',
    ];

    public function name(): string {
        return 'credential_leak';
    }

    public function validate(string $output, array $context = []): result {
        $hits = [];
        foreach (self::PATTERNS as $kind => $pattern) {
            if (preg_match_all($pattern, $output, $matches)) {
                foreach (array_unique($matches[0]) as $token) {
                    $hits[] = ['kind' => $kind, 'token' => $this->mask($token)];
                }
            }
        }

        if (empty($hits)) {
            return result::pass($this->name());
        }

        $messages = [];
        foreach ($hits as $h) {
            $messages[] = "Output contains a {$h['kind']}: {$h['token']}";
        }

        return result::fail($this->name(), $messages, ['hits' => $hits]);
    }

    private function mask(string $token): string {
        $len = strlen($token);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($token, 0, 4) . str_repeat('*', $len - 8) . substr($token, -4);
    }
}
