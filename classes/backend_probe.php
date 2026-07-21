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

use local_ai_course_assistant\provider\base_provider;

/**
 * On-demand live backend capability probe (v5.10.0).
 *
 * Lets a self-hosted admin confirm their backend works and is sized correctly
 * without the author's help: round-trips a tiny completion, attempts to detect
 * the backend context window, checks it against the configured
 * backend_context_tokens, and (if RAG is on) checks embeddings.
 *
 * NEVER called from {@see health_check::run_all()} so the post-upgrade
 * structural checks stay network-free. Invoked only from backend_selftest.php.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backend_probe {

    public const STATUS_PASS = 'pass';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';

    /**
     * Compare the configured window to a detected one. detected 0 = unknown.
     *
     * @param int $configured backend_context_tokens setting (0 = clamping off)
     * @param int $detected window reported by the backend (0 = could not detect)
     */
    public static function compare_window(int $configured, int $detected): array {
        if ($detected === 0) {
            return self::row(self::STATUS_WARN,
                'Could not detect max_model_len from the backend. Set the Backend context window setting manually.');
        }
        if ($configured === 0) {
            return self::row(self::STATUS_WARN,
                "The backend reports a {$detected}-token window but SOLA clamping is off (Backend context window = 0). "
                . "Consider setting it to {$detected} so prompts cannot overflow.");
        }
        if ($configured > $detected) {
            return self::row(self::STATUS_WARN,
                "Configured window {$configured} exceeds the backend's {$detected}; prompts may overflow. "
                . "Lower the Backend context window setting to {$detected} or less.");
        }
        return self::row(self::STATUS_PASS, "Window OK (configured {$configured} is within the backend's {$detected}).");
    }

    /**
     * Does the system-prompt floor still fit once output is reserved?
     *
     * @param int $windowtokens backend window
     * @param int $outputtokens reserved output (max_tokens)
     * @param string $lang learner language code
     */
    public static function check_floor_fits(int $windowtokens, int $outputtokens, string $lang): array {
        $chars = token_estimator::budget_chars_for_window($windowtokens, $outputtokens > 0 ? $outputtokens : 512, 0, $lang);
        if ($chars < context_builder::MIN_BUDGET_FLOOR) {
            return self::row(self::STATUS_FAIL,
                "Window too small: only {$chars} chars remain for the system prompt, below the "
                . context_builder::MIN_BUDGET_FLOOR . '-char safety floor. Raise the window or lower Max Response Length (max_tokens).');
        }
        return self::row(self::STATUS_PASS, "System-prompt budget of {$chars} chars clears the safety floor.");
    }

    /**
     * Live: round-trip a tiny non-streaming completion against the configured provider.
     */
    public static function probe_chat(): array {
        try {
            $provider = base_provider::create_from_config(0);
            $started = microtime(true);
            $reply = $provider->chat_completion(
                'You are a connectivity probe. Answer in one word.',
                [['role' => 'user', 'content' => 'Reply with the single word OK.']],
                ['max_tokens' => 8]
            );
            $ms = (int) round((microtime(true) - $started) * 1000);
            if (trim((string) $reply) === '') {
                return self::row(self::STATUS_WARN, "Chat returned an empty reply in {$ms} ms (connection works, but no content).");
            }
            return self::row(self::STATUS_PASS, "Chat round-trip OK in {$ms} ms.");
        } catch (\Throwable $e) {
            return self::row(self::STATUS_FAIL, 'Chat probe failed: ' . $e->getMessage());
        }
    }

    /**
     * Structural: report Moodle core_ai availability and how the 'auto' chat
     * provider default would resolve. Network-free.
     */
    public static function probe_coreai(): array {
        $available = \local_ai_course_assistant\provider\coreai_provider::is_available();
        $configured = get_config('local_ai_course_assistant', 'provider') ?: 'auto';
        if ($available) {
            $note = ($configured === 'auto')
                ? 'Auto routes chat through it when no SOLA key is set.'
                : "Chat provider is set to '{$configured}', so core_ai is available but not in use.";
            return self::row(self::STATUS_PASS,
                "Moodle core_ai is available with a configured provider. {$note}");
        }
        if (!class_exists('\\core_ai\\manager')) {
            return self::row(self::STATUS_PASS,
                'Moodle core_ai subsystem not present (needs Moodle 4.5+); SOLA uses its own providers.');
        }
        return self::row(self::STATUS_PASS,
            'Moodle core_ai is present but has no configured AI provider; SOLA uses its own providers. '
            . 'Configure one under Site admin > AI to route chat through it.');
    }

    /**
     * Live: best-effort detection of the backend context window. 0 if unknown.
     */
    public static function detect_window(): int {
        try {
            $provider = base_provider::create_from_config(0);
            return $provider->detect_context_window();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Live: embed a short string when RAG is enabled, reporting dimension and latency.
     */
    public static function probe_embedding(): array {
        if (!get_config('local_ai_course_assistant', 'rag_enabled')) {
            return self::row(self::STATUS_PASS, 'RAG is disabled; embedding probe skipped.');
        }
        try {
            $provider = \local_ai_course_assistant\embedding_provider\base_embedding_provider::create_from_config();
            $started = microtime(true);
            $vec = $provider->embed('connectivity probe');
            $ms = (int) round((microtime(true) - $started) * 1000);
            $dim = is_array($vec) ? count($vec) : 0;
            if ($dim === 0) {
                return self::row(self::STATUS_FAIL, "Embedding returned no vector in {$ms} ms.");
            }
            return self::row(self::STATUS_PASS, "Embedding OK ({$dim} dimensions) in {$ms} ms.");
        } catch (\Throwable $e) {
            return self::row(self::STATUS_FAIL, 'Embedding probe failed: ' . $e->getMessage());
        }
    }

    /**
     * Run the full probe and return labelled rows for the self-test page.
     *
     * @return array<int,array{label:string,status:string,message:string}>
     */
    public static function run_all(): array {
        $rows = [];

        $rows[] = ['label' => 'Moodle core_ai'] + self::probe_coreai();

        $chat = self::probe_chat();
        $rows[] = ['label' => 'Chat round-trip'] + $chat;

        $detected = self::detect_window();
        $configured = (int) get_config('local_ai_course_assistant', 'backend_context_tokens');
        $win = self::compare_window($configured, $detected);
        $rows[] = ['label' => 'Context window'] + $win;

        if ($configured > 0) {
            $rawmax = get_config('local_ai_course_assistant', 'max_tokens');
            $outputtokens = ($rawmax === false || $rawmax === '') ? 1024 : (int) $rawmax;
            $floor = self::check_floor_fits($configured, $outputtokens, 'en');
            $rows[] = ['label' => 'Prompt budget floor'] + $floor;
        }

        $embed = self::probe_embedding();
        $rows[] = ['label' => 'Embeddings (RAG)'] + $embed;

        return $rows;
    }

    private static function row(string $status, string $message): array {
        return ['status' => $status, 'message' => $message];
    }
}
