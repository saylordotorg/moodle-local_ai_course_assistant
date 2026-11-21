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
            return self::row(
                self::STATUS_WARN,
                'Could not detect max_model_len from the backend. Set the Backend context window setting manually.'
            );
        }
        if ($configured === 0) {
            return self::row(
                self::STATUS_WARN,
                "The backend reports a {$detected}-token window but " . branding::short_name() . " clamping is off (Backend context window = 0). "
                . "Consider setting it to {$detected} so prompts cannot overflow."
            );
        }
        if ($configured > $detected) {
            return self::row(
                self::STATUS_WARN,
                "Configured window {$configured} exceeds the backend's {$detected}; prompts may overflow. "
                . "Lower the Backend context window setting to {$detected} or less."
            );
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
            return self::row(
                self::STATUS_FAIL,
                "Window too small: only {$chars} chars remain for the system prompt, below the "
                . context_builder::MIN_BUDGET_FLOOR . '-char safety floor. Raise the window or lower Max Response Length (max_tokens).'
            );
        }
        return self::row(self::STATUS_PASS, "System-prompt budget of {$chars} chars clears the safety floor.");
    }

    /**
     * Live: round-trip a tiny non-streaming completion against the configured provider.
     */
    public static function probe_chat(): array {
        try {
            $provider = base_provider::create_from_config(0, /* diagnostic */ true);
            $started = microtime(true);
            $reply = $provider->chat_completion(
                'You are a connectivity probe. Answer in one word.',
                [['role' => 'user', 'content' => 'Reply with the single word OK.']],
                // v7.2.8: NOT 8. Reasoning models spend the completion budget on
                // internal thinking tokens before emitting anything, so a tiny
                // ceiling returns HTTP 200 with finish_reason=length and null
                // content. Measured against live gemini-2.5-flash on 2026-09-01:
                // max_tokens 8 and 16 both yield null; 64 yields "OK". At 8 this
                // probe reported a perfectly healthy production backend as FAIL,
                // which is the worst possible behaviour for the one page an admin
                // opens to answer "is my backend up?". 256 is far above the knee
                // and still costs a fraction of a cent per run.
                ['max_tokens' => 256]
            );
            $ms = (int) round((microtime(true) - $started) * 1000);
            if (trim((string) $reply) === '') {
                return self::row(self::STATUS_WARN, "Chat returned an empty reply in {$ms} ms (connection works, but no content).");
            }
            return self::row(self::STATUS_PASS, "Chat round-trip OK in {$ms} ms.");
        } catch (\Throwable $e) {
            // v7.2.8: surface debuginfo. moodle_exception keeps the HTTP status
            // and response body there, not in getMessage(), so reporting the
            // message alone reduced every backend fault to the same generic
            // sentence -- on the page whose entire job is telling an admin what
            // is wrong. Diagnosing the 2026-09-01 staging incident took a dozen
            // steps for exactly this reason.
            $detail = trim((string) ($e->debuginfo ?? ''));
            $msg = 'Chat probe failed: ' . $e->getMessage();
            if ($detail !== '') {
                $msg .= ' [' . \core_text::substr($detail, 0, 300) . ']';
            }
            return self::row(self::STATUS_FAIL, $msg);
        }
    }

    /**
     * Structural: report Moodle core_ai availability and how the 'auto' chat
     * provider default would resolve. Network-free.
     */
    public static function probe_coreai(): array {
        return self::coreai_row(
            class_exists('\\core_ai\\manager'),
            \local_ai_course_assistant\provider\coreai_provider::is_available(),
            (string) (get_config('local_ai_course_assistant', 'provider') ?: 'auto'),
            (string) (\local_ai_course_assistant\secrets::get('apikey') ?: '') !== ''
        );
    }

    /**
     * The core_ai row for given facts. Pure, so each case can be tested.
     *
     * This row used to be PASS whatever the facts, so a site with the chat
     * provider set to Moodle AI and nothing in core_ai able to generate text was
     * told all was well while every turn failed.
     *
     * @param bool $present Whether core_ai exists (Moodle 4.5+).
     * @param bool $available Whether core_ai can run generate_text (coreai_provider::is_available()).
     * @param string $configured The site chat provider setting ('auto' when unset).
     * @param bool $haskey Whether a site chat key is saved (it keeps 'auto' on a direct provider).
     * @return array{status:string,message:string}
     */
    public static function coreai_row(bool $present, bool $available, string $configured, bool $haskey): array {
        $short = branding::short_name();
        $chosen = $configured === 'coreai';
        $auto = ($configured === 'auto' || $configured === '') && !$haskey;
        if (!$present) {
            if ($chosen) {
                return self::row(
                    self::STATUS_FAIL,
                    'Chat provider is set to Moodle AI, but this Moodle has no core_ai subsystem (needs Moodle 4.5+).'
                );
            }
            return self::row(
                self::STATUS_PASS,
                "Moodle core_ai subsystem not present (needs Moodle 4.5+); {$short} uses its own providers."
            );
        }
        if (!$available) {
            $what = 'Moodle core_ai has no enabled, configured provider with the Generate text action turned on';
            if ($chosen) {
                return self::row(
                    self::STATUS_FAIL,
                    "Chat provider is set to Moodle AI, but {$what}, so every chat turn will fail. "
                    . 'Set one up under Site admin > AI > AI providers.'
                );
            }
            return self::row(
                self::STATUS_PASS,
                "{$what}; {$short} uses its own providers. Configure one under Site admin > AI to route chat through it."
            );
        }
        if ($chosen || $auto) {
            $how = $chosen ? 'Chat provider is set to Moodle AI' : "Auto routes chat through it because no {$short} key is set";
            return self::row(
                self::STATUS_PASS,
                "Moodle core_ai can generate text. {$how}. Learners must accept Moodle's AI usage policy first, "
                . "and courses or activities with Moodle's AI tools turned off are passed to the next provider "
                . 'in the failover chain, if there is one.'
            );
        }
        return self::row(
            self::STATUS_PASS,
            "Moodle core_ai can generate text, but the chat provider is set to '{$configured}', so it is not in use."
        );
    }

    /**
     * Live: best-effort detection of the backend context window. 0 if unknown.
     */
    public static function detect_window(): int {
        try {
            $provider = base_provider::create_from_config(0, /* diagnostic */ true);
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
