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

use local_ai_course_assistant\provider\base_provider;
use local_ai_course_assistant\provider\failover_chain;
use local_ai_course_assistant\provider\provider_http_exception;

/**
 * Checks that the failover chain's backup providers actually work (v7.8.8).
 *
 * A backup nobody exercises fails silently until the day it is needed. On
 * Learn the OpenAI fallback row held the model as "GPT-4o-mini", a display
 * name OpenAI answers with HTTP 404; the admin LLM picker still worked, so the
 * row looked fine, and for days 4% to 23% of student messages failed whenever
 * the primary did. Degrees carried the same row unnoticed.
 *
 * Two kinds of check:
 *  - static_problems(): free, no network. A chain line with no row or no key
 *    (silently skipped at runtime), no backup at all while per-call failover is
 *    on, and a model written with capitals or spaces on a hosted provider.
 *    Shown as a notice after the settings are saved and on Reports > Status.
 *  - probe_all(): one tiny real request per backup, built by the SAME code a
 *    failover uses (base_provider::build_fallbacks), so it sends the row's model
 *    string exactly as typed. Run daily by a scheduled task or on demand by
 *    admin/cli/verify_failover.php, and recorded as `failover_probe` audit rows.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class failover_verifier {
    /** @var string Audit action written once per backup per probe run. */
    public const AUDIT_EVENT_PROBE = 'failover_probe';

    /** @var int How far back the Status check looks for a probe result, in seconds. */
    public const FRESH_WITHIN = 3 * DAYSECS;

    /** @var string[] Providers whose model ids are lowercase when no custom base URL is set. */
    private const HOSTED_LOWERCASE = ['openai', 'claude', 'gemini', 'mistral', 'deepseek', 'xai'];

    /**
     * Labels on the chat failover chain, in order.
     *
     * @return string[]
     */
    public static function chat_labels(): array {
        $raw = trim((string) (get_config('local_ai_course_assistant', 'spend_failover_chain') ?: ''));
        $labels = [];
        foreach (preg_split("/\r?\n/", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            [$capability, $label] = array_pad(array_map('trim', explode(':', $line, 2)), 2, '');
            if ($capability === 'chat' && $label !== '') {
                $labels[] = $label;
            }
        }
        return $labels;
    }

    /**
     * The comparison_providers rows, keyed by lowercase label (first row wins).
     *
     * @return array<string, array{label: string, haskey: bool, model: string, baseurl: string}>
     */
    public static function rows(): array {
        $rows = [];
        foreach (provider_keys::by_label() as $key => $row) {
            $rows[$key] = [
                'label'   => $row['label'],
                'haskey'  => $row['haskey'],
                'model'   => $row['model'],
                'baseurl' => $row['baseurl'],
            ];
        }
        return $rows;
    }

    /**
     * Problems visible from the settings alone. No network.
     *
     * @return string[] Plain-text lines, one per problem.
     */
    public static function static_problems(): array {
        $problems = [];
        $rows = self::rows();
        $labels = self::chat_labels();

        foreach ($labels as $label) {
            $row = $rows[strtolower($label)] ?? null;
            if ($row === null) {
                $problems[] = get_string('verify:missing_row', 'local_ai_course_assistant', $label);
            } else if (!$row['haskey']) {
                $problems[] = get_string('verify:missing_key', 'local_ai_course_assistant', $label);
            }
        }

        $resolvable = array_filter($labels, function ($label) use ($rows) {
            $row = $rows[strtolower($label)] ?? null;
            return $row !== null && $row['haskey'];
        });
        if ((bool) get_config('local_ai_course_assistant', 'failover_per_call_enabled') && !$resolvable) {
            $problems[] = get_string('verify:no_backup', 'local_ai_course_assistant');
        }

        foreach ($rows as $key => $row) {
            if ($row['model'] === '' || $row['baseurl'] !== '' || !in_array($key, self::HOSTED_LOWERCASE, true)) {
                continue;
            }
            if (preg_match('/[A-Z\s]/', $row['model'])) {
                $problems[] = get_string('verify:model_case', 'local_ai_course_assistant', (object) [
                    'label' => $row['label'],
                    'model' => $row['model'],
                    'suggest' => strtolower((string) preg_replace('/\s+/', '-', $row['model'])),
                ]);
            }
        }
        return $problems;
    }

    /**
     * Send one tiny real request to every chat backup and record the outcome.
     *
     * Costs a few tokens a backup, more for a thinking model (the provider adds its reasoning headroom to the
     * 16 requested). The call goes straight to the provider, so it is not in token analytics and does not count
     * toward a spend cap.
     *
     * @param callable|null $send fn(provider_interface): void, for tests; null sends a real request.
     * @return array[] One entry per backup: label, model, ok, status, detail, ms.
     */
    public static function probe_all(?callable $send = null): array {
        $results = [];
        $chain = \local_ai_course_assistant\spend_guard::resolve_failover_chain('chat');
        if (!$chain) {
            return $results;
        }
        $effective = \local_ai_course_assistant\course_config_manager::get_effective_config(0);
        // The primary exactly as create_from_config() resolves it: build_fallbacks() compares each backup's
        // provider with it to decide whether a backup with no model of its own uses its provider's default,
        // and 'auto' must have become a concrete provider by then.
        $primary = !empty($effective['provider'])
            ? (string) $effective['provider']
            : (string) (get_config('local_ai_course_assistant', 'provider') ?: '');
        if ($primary === 'auto' || $primary === '') {
            $primary = base_provider::resolve_auto_provider($effective);
        }
        $fallbacks = base_provider::build_fallbacks($primary, $effective, $chain);
        foreach ($fallbacks as $fallback) {
            $started = microtime(true);
            $result = ['label' => $fallback['label'], 'model' => '', 'ok' => true, 'status' => 0, 'detail' => ''];
            $member = $fallback['provider'];
            if (method_exists($member, 'model_id')) {
                $result['model'] = (string) $member->model_id();
            }
            try {
                if ($send !== null) {
                    $send($member);
                } else {
                    $member->chat_completion(
                        'Reply with the single word OK.',
                        [['role' => 'user', 'content' => 'ok']],
                        ['max_tokens' => 16]
                    );
                }
            } catch (\Throwable $e) {
                $result['ok'] = false;
                $result['status'] = ($e instanceof provider_http_exception) ? $e->status : 0;
                $result['detail'] = failover_chain::describe_failure($e);
            }
            $result['ms'] = (int) round((microtime(true) - $started) * 1000);
            $results[] = $result;
            try {
                audit_logger::log(self::AUDIT_EVENT_PROBE, 0, 0, $result);
            } catch (\Throwable $ignore) {
                // The probe's answer matters more than its record.
                unset($ignore);
            }
        }
        return $results;
    }

    /**
     * The latest recorded probe per backup, with how many probes in a row failed.
     *
     * @param int|null $now Time to measure freshness from; null for the present.
     * @param bool $currentonly Keep only results that still describe the settings: for a label that is on the
     *        chain now, with the model its row names now. A backup that was removed, or whose model was
     *        corrected after a failed probe, is not reported until the next probe has run.
     * @return array<string, array{label: string, model: string, ok: bool, detail: string, failures: int, time: int}>
     */
    public static function latest_results(?int $now = null, bool $currentonly = false): array {
        global $DB;
        $since = ($now ?? time()) - self::FRESH_WITHIN;
        $rows = $DB->get_records_select(
            'local_ai_course_assistant_audit',
            'action = :a AND timecreated >= :t',
            ['a' => self::AUDIT_EVENT_PROBE, 't' => $since],
            'timecreated DESC, id DESC',
            'id, details, timecreated',
            0,
            200
        );
        $latest = [];
        $streakopen = [];
        foreach ($rows as $row) {
            $d = json_decode((string) $row->details, true);
            if (!is_array($d) || empty($d['label'])) {
                continue;
            }
            $label = (string) $d['label'];
            $ok = !empty($d['ok']);
            if (!isset($latest[$label])) {
                $latest[$label] = [
                    'label' => $label,
                    'model' => (string) ($d['model'] ?? ''),
                    'ok' => $ok,
                    'detail' => (string) ($d['detail'] ?? ''),
                    'failures' => $ok ? 0 : 1,
                    'time' => (int) $row->timecreated,
                ];
                $streakopen[$label] = !$ok;
            } else if ($streakopen[$label]) {
                if ($ok) {
                    $streakopen[$label] = false;
                } else {
                    $latest[$label]['failures']++;
                }
            }
        }
        if ($currentonly) {
            $onchain = array_map('strtolower', self::chat_labels());
            $rows = self::rows();
            $latest = array_filter($latest, function ($result) use ($onchain, $rows) {
                $key = strtolower($result['label']);
                if (!in_array($key, $onchain, true)) {
                    return false;
                }
                $model = $rows[$key]['model'] ?? '';
                return $model === '' || $result['model'] === '' || $model === $result['model'];
            });
        }
        return $latest;
    }
}
