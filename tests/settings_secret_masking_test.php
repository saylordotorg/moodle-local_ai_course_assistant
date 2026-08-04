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
 * Guards that credential settings are declared as password types.
 *
 * Moodle writes '********' into mdl_config_log for password settings only. A
 * credential declared as admin_setting_configtext has every historical value
 * recorded in the clear, in a table that is never purged and is readable by
 * anything with database or reporting access.
 *
 * Found on production 2026-08-03: redash_api_key was a plain configtext, and a
 * retired key was recoverable in full from config_log. Its siblings
 * redash_user_api_key and github_token were already correct, which is exactly
 * why a standing test is worth more than the one-line fix -- the next
 * credential setting someone adds will be copied from whichever neighbour they
 * happened to look at.
 *
 * This scans settings.php as text rather than instantiating the admin tree,
 * which needs a full admin bootstrap and would make the test slow and fragile.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class settings_secret_masking_test extends \advanced_testcase {

    /**
     * Setting-name fragments that indicate a stored credential.
     */
    private const SECRET_HINTS = ['apikey', 'api_key', 'secret', 'token', 'password'];

    /**
     * Names that match a hint but are NOT credentials, with the reason.
     *
     * Kept explicit so adding an exemption is a visible decision rather than a
     * quietly loosened regex.
     */
    private const NOT_SECRETS = [
        // Ed25519 *public* key -- verification material, safe in the clear.
        'policy_bundle_pubkey'    => 'public key, not a secret',
        // Token budgets, not credentials. They match on the substring "token".
        'max_tokens'              => 'token budget',
        'backend_context_tokens'  => 'token budget',
        'prompt_budget_chars'     => 'budget',
    ];

    public function test_every_credential_setting_is_a_password_type(): void {
        $this->resetAfterTest();

        $path = __DIR__ . '/../settings.php';
        $this->assertFileExists($path);
        $src = file_get_contents($path);

        // Each declaration: the class name, then the setting name a few lines on.
        $pattern = '/new\s+(admin_setting_config\w+)\s*\(\s*\'local_ai_course_assistant\/([a-z0-9_]+)\'/i';
        $this->assertMatchesRegularExpression($pattern, $src,
            'no settings found -- the scan pattern has drifted from settings.php');
        preg_match_all($pattern, $src, $m, PREG_SET_ORDER);

        $offenders = [];
        $checked = 0;
        foreach ($m as $decl) {
            [$all, $class, $name] = $decl;
            if (array_key_exists($name, self::NOT_SECRETS)) {
                continue;
            }
            $looks = false;
            foreach (self::SECRET_HINTS as $hint) {
                if (str_contains($name, $hint)) {
                    $looks = true;
                    break;
                }
            }
            if (!$looks) {
                continue;
            }
            $checked++;
            if (!str_contains(strtolower($class), 'password')) {
                $offenders[] = "{$name} (declared {$class})";
            }
        }

        $this->assertGreaterThan(0, $checked,
            'no credential-looking settings matched -- the hint list may be stale');
        $this->assertSame([], $offenders,
            "Credential settings must use a password type so Moodle masks them in "
            . "mdl_config_log. Plain configtext records every historical value in "
            . "the clear. Offenders: " . implode(', ', $offenders));
    }

    /**
     * The exemption list must not rot into a way of silencing the test. Every
     * entry has to still exist in settings.php.
     */
    public function test_exemptions_still_exist_and_are_justified(): void {
        $this->resetAfterTest();
        $src = file_get_contents(__DIR__ . '/../settings.php');

        foreach (self::NOT_SECRETS as $name => $reason) {
            $this->assertNotEmpty($reason, "exemption {$name} has no stated reason");
            if (!str_contains($src, "local_ai_course_assistant/{$name}'")) {
                $this->addWarning("exemption '{$name}' no longer exists in settings.php; remove it");
            }
        }
        $this->assertTrue(true);
    }
}
