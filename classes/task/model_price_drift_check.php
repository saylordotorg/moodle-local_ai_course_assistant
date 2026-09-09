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

namespace local_ai_course_assistant\task;

use local_ai_course_assistant\email_optout;
use local_ai_course_assistant\model_registry;
use local_ai_course_assistant\price_source;

defined('MOODLE_INTERNAL') || die();

/**
 * v7.4.0 daily price-drift check — the self-running half of the model registry.
 *
 * A price is a fact about the outside world that goes stale on someone else's
 * schedule, and SOLA had no way to notice. Two facts from this release make the
 * cost concrete: gemini-2.5-flash, the production chat model, matched no
 * rate-card prefix at all, so 100% of production chat spend computed as $0.00;
 * and the committed Anthropic prices were a full generation stale (opus listed
 * at 15/75 against an actual 5/25). Neither was detected by anything. Every
 * existing monitor watches spend going UP — an unpriced or under-priced model
 * makes spend look SMALL, so the spend caps, the anomaly detector and the
 * dashboards all reported low numbers and all agreed with each other.
 *
 * This task closes that loop. Daily, for every enabled pricing source, it
 * fetches, parses, and compares against {@see model_registry::effective_rates()},
 * producing three finding classes:
 *
 *  - MISSING  — a model observed in real billable traffic has no rate at all.
 *               HIGHEST severity: this is the class that reports spend as
 *               $0.00 rather than as an error, because estimate_cost() returns
 *               null and every consumer treats null as "skip".
 *  - MISMATCH — the registry price differs from the source beyond the
 *               tolerance (default 1%, admin-settable). Catches the stale
 *               committed table.
 *  - NEW      — the source knows a model the registry does not. Informational;
 *               capped, because a full aggregator knows a thousand models the
 *               site will never run.
 *
 * Findings are PROPOSED, NEVER APPLIED. Nothing here calls
 * model_registry::upsert(). A price is a number an admin is accountable for,
 * and an automated feed silently rewriting it is precisely the defect v7.4.0
 * fixed in the weekly refresher (which wholesale-overwrote every hand-entered
 * correction). Findings land in a compact JSON summary in the
 * `model_price_drift_last` config key, plus a human-readable line on each
 * source row, for the admin page to render with a one-click apply.
 *
 * Off by default (`price_drift_check_enabled`). Emails the existing
 * `spend_notify_emails` recipients on any MISSING or MISMATCH, honouring the
 * email_optout table exactly as {@see \local_ai_course_assistant\cost_anomaly_detector}
 * does. Nothing is written to the filesystem.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_price_drift_check extends \core\task\scheduled_task {
    /** @var string Config key holding the compact JSON summary of the last run. */
    public const SUMMARY_KEY = 'model_price_drift_last';

    /** @var float Percent difference tolerated before a MISMATCH is reported. */
    public const DEFAULT_TOLERANCE_PCT = 1.0;

    /** @var int Hard cap on stored findings, so the config value stays bounded. */
    public const MAX_FINDINGS = 200;

    /** @var int Cap on informational NEW findings; an aggregator knows thousands. */
    public const MAX_NEW_FINDINGS = 25;

    /** @var int Cap on MISSING findings. Distinct unpriced models in traffic are few. */
    public const MAX_MISSING_FINDINGS = 50;

    /**
     * Byte ceiling for the stored summary.
     *
     * config_plugins.value is TEXT, which MySQL caps at 65,535 bytes and
     * truncates silently past it — and a silently truncated findings list would
     * decode to null, leaving the admin page blank with no explanation. Trim to
     * fit instead, and record how many findings were dropped.
     */
    public const MAX_SUMMARY_BYTES = 48000;

    /**
     * Task name.
     *
     * Falls back to English when the lang string is not present yet: this wave
     * may not edit lang/, so the string is specified in the manifest and wired
     * by a later stage. get_string() on a missing key emits a debugging notice
     * and returns [[key]], which would surface in the scheduled-task UI.
     *
     * @return string
     */
    public function get_name(): string {
        $manager = get_string_manager();
        if ($manager->string_exists('task:model_price_drift_check', 'local_ai_course_assistant')) {
            return \local_ai_course_assistant\branding::apply(
                get_string('task:model_price_drift_check', 'local_ai_course_assistant')
            );
        }
        return 'Model price drift check';
    }

    /**
     * Cron entry point. Gated on `price_drift_check_enabled`.
     *
     * @return void
     */
    public function execute(): void {
        if (!get_config('local_ai_course_assistant', 'price_drift_check_enabled')) {
            mtrace('  model_price_drift_check: disabled (price_drift_check_enabled off), skipping.');
            return;
        }

        $summary = self::run();
        mtrace(sprintf(
            '  model_price_drift_check: status=%s sources=%d ok=%d failed=%d '
                . 'missing=%d mismatch=%d new=%d tolerance=%.2f%%',
            $summary['status'],
            count($summary['sources']),
            $summary['counts']['sources_ok'],
            $summary['counts']['sources_failed'],
            $summary['counts']['missing'],
            $summary['counts']['mismatch'],
            $summary['counts']['new'],
            $summary['tolerance_pct']
        ));
        foreach ($summary['sources'] as $row) {
            mtrace('    [' . $row['status'] . '] ' . $row['name'] . ': ' . $row['message']);
        }
        if ($summary['counts']['missing'] > 0 || $summary['counts']['mismatch'] > 0) {
            $sent = self::maybe_send_alert($summary);
            mtrace('  model_price_drift_check: alert email ' . ($sent ? 'sent' : 'skipped'));
        }
    }

    /**
     * Do the whole check and persist the result. Callable directly by an admin
     * "run now" button and by the tests; the enabled flag is checked by the
     * cron entry point, not here.
     *
     * @return array{status: string, timerun: int, tolerance_pct: float, sources: array[],
     *               counts: array<string, int>, findings: array[], truncated: array<string, int>,
     *               unpriced_total: int}
     */
    public static function run(): array {
        $tolerance = self::tolerance_pct();
        $rates = model_registry::effective_rates();

        // Merge every enabled source into one key => price map. First source to
        // supply a key wins (sources are read in name order, so this is
        // deterministic), and we remember which source it came from so the
        // admin can see who is proposing the change.
        $merged = [];
        $owner = [];
        $sources = [];
        $sourcerows = price_source::enabled_sources();
        $okcount = 0;
        $failcount = 0;

        foreach ($sourcerows as $source) {
            $result = price_source::refresh_source($source);
            $entry = [
                'id'       => (int) $source->id,
                'name'     => (string) $source->name,
                'format'   => (string) $source->format,
                'status'   => $result['ok'] ? 'ok' : 'error',
                'prices'   => (int) $result['count'],
                'mismatch' => 0,
                'new'      => 0,
                'message'  => $result['message'],
            ];
            if (!$result['ok']) {
                $failcount++;
                $sources[] = $entry;
                continue;
            }
            $okcount++;
            foreach ($result['prices'] as $key => $row) {
                if (!isset($merged[$key])) {
                    $merged[$key] = $row;
                    $owner[$key] = ['id' => (int) $source->id, 'name' => (string) $source->name];
                }
            }
            $sources[] = $entry;
        }

        // Per-source comparison, so each row can report its own finding counts.
        $mismatch = [];
        $newmodels = [];
        $seenmismatch = [];
        $seennew = [];
        foreach ($sources as $index => $entry) {
            if ($entry['status'] !== 'ok') {
                continue;
            }
            $owned = [];
            foreach ($merged as $key => $row) {
                if (($owner[$key]['id'] ?? 0) === $entry['id']) {
                    $owned[$key] = $row;
                }
            }
            $cmp = price_source::compare($owned, $rates, $tolerance);
            foreach ($cmp['mismatch'] as $finding) {
                if (isset($seenmismatch[$finding['modelkey']])) {
                    continue;
                }
                $seenmismatch[$finding['modelkey']] = true;
                $mismatch[] = self::stamp_source($finding, $entry);
            }
            foreach ($cmp['new'] as $finding) {
                if (isset($seennew[$finding['modelkey']])) {
                    continue;
                }
                $seennew[$finding['modelkey']] = true;
                $newmodels[] = self::stamp_source($finding, $entry);
            }
            $sources[$index]['mismatch'] = count($cmp['mismatch']);
            $sources[$index]['new'] = count($cmp['new']);
        }

        $missing = self::missing_findings($merged, $owner);

        // Sort the two severity-bearing classes so the worst is first: busiest
        // unpriced model, then largest price delta.
        usort($missing, fn($a, $b) => ($b['calls'] ?? 0) <=> ($a['calls'] ?? 0));
        usort($mismatch, fn($a, $b) => self::worst_delta($b) <=> self::worst_delta($a));

        $counts = [
            'missing'        => count($missing),
            'mismatch'       => count($mismatch),
            'new'            => count($newmodels),
            'sources_ok'     => $okcount,
            'sources_failed' => $failcount,
        ];

        $keptmissing = array_slice($missing, 0, self::MAX_MISSING_FINDINGS);
        $keptnew = array_slice($newmodels, 0, self::MAX_NEW_FINDINGS);
        $findings = array_slice(
            array_merge($keptmissing, $mismatch, $keptnew),
            0,
            self::MAX_FINDINGS
        );

        $status = 'ok';
        if ($counts['missing'] > 0 || $counts['mismatch'] > 0) {
            $status = 'findings';
        } else if (empty($sourcerows)) {
            $status = 'no_sources';
        } else if ($okcount === 0) {
            $status = 'all_sources_failed';
        }

        $summary = [
            'status'         => $status,
            'timerun'        => time(),
            'tolerance_pct'  => $tolerance,
            'sources'        => $sources,
            'counts'         => $counts,
            'findings'       => $findings,
            'truncated'      => [
                'missing' => max(0, $counts['missing'] - count($keptmissing)),
                'new'     => max(0, $counts['new'] - count($keptnew)),
            ],
            'unpriced_total' => $counts['missing'],
        ];

        // Findings go where the admin page can render them: a compact JSON
        // summary in config, plus a human-readable line on each source row.
        // No file is written — after the next release there is no filesystem.
        set_config(self::SUMMARY_KEY, self::encode_summary($summary), 'local_ai_course_assistant');
        foreach ($sourcerows as $source) {
            foreach ($sources as $entry) {
                if ($entry['id'] === (int) $source->id && $entry['status'] === 'ok') {
                    price_source::append_message($source, 'Findings: ' . $entry['mismatch']
                        . ' price mismatch, ' . $entry['new'] . ' unknown to the registry.');
                }
            }
        }

        return $summary;
    }

    /**
     * JSON-encode the summary, dropping findings until it fits the value column.
     *
     * The returned summary (and therefore the alert email) keeps the full list;
     * only what is persisted for the admin page is trimmed, since that page can
     * re-run the check.
     *
     * @param array $summary
     * @return string
     */
    private static function encode_summary(array $summary): string {
        $json = json_encode($summary);
        $total = count($summary['findings']);
        while (strlen($json) > self::MAX_SUMMARY_BYTES && count($summary['findings']) > 5) {
            $keep = max(5, (int) floor(count($summary['findings']) / 2));
            $summary['findings'] = array_slice($summary['findings'], 0, $keep);
            $summary['truncated']['stored'] = $total - $keep;
            $json = json_encode($summary);
        }
        return (string) $json;
    }

    /**
     * The last stored summary, for the admin page.
     *
     * @return array|null
     */
    public static function last_summary(): ?array {
        $raw = (string) (get_config('local_ai_course_assistant', self::SUMMARY_KEY) ?: '');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Configured tolerance, clamped to something meaningful.
     *
     * @return float Percent.
     */
    public static function tolerance_pct(): float {
        $raw = get_config('local_ai_course_assistant', 'price_drift_tolerance_pct');
        if ($raw === false || $raw === '' || !is_numeric($raw)) {
            return self::DEFAULT_TOLERANCE_PCT;
        }
        return max(0.0, min(100.0, (float) $raw));
    }

    /**
     * MISSING findings: models in real billable traffic that resolve to no rate.
     *
     * The traffic side comes from {@see model_registry::unpriced_models()},
     * which is the shared query (it selects billable rows via
     * analytics::spend_rows_predicate(), never a hand-rolled role condition).
     * When a source knows a price for the model — exactly, or via a shorter
     * prefix — the finding carries it as a proposal so the admin page's apply
     * button has something to write. When no source knows it, the finding is
     * still recorded with null rates: "we are billing for this and nobody can
     * tell you what it costs" is the single most important thing on the page.
     *
     * Public because the admin page renders the same list live (it is the
     * severity headline of the registry page), and because it is the one
     * finding class that needs no network at all to test.
     *
     * @param array $merged Merged source prices, keyed by model prefix; may be empty.
     * @param array $owner Owning source per key, as id and name.
     * @return array[]
     */
    public static function missing_findings(array $merged = [], array $owner = []): array {
        $out = [];
        foreach (model_registry::unpriced_models() as $observed) {
            $model = (string) $observed['model_name'];
            $prefix = price_source::longest_prefix($model, array_keys($merged));
            $proposal = $prefix !== null ? $merged[$prefix] : null;
            $src = $prefix !== null ? ($owner[$prefix] ?? null) : null;
            $out[] = [
                'type'             => 'missing',
                'modelkey'         => strtolower($model),
                'provider'         => $proposal['provider'] ?? $observed['provider'],
                'capability'       => $proposal['capability'] ?? null,
                'input'            => $proposal !== null ? (float) $proposal['input'] : null,
                'output'           => $proposal !== null ? (float) $proposal['output'] : null,
                'context'          => $proposal['context'] ?? null,
                'registry_input'   => null,
                'registry_output'  => null,
                'delta_pct_input'  => null,
                'delta_pct_output' => null,
                'calls'            => (int) $observed['calls'],
                'tokens'           => (int) $observed['tokens'],
                'sourceid'         => $src['id'] ?? null,
                'sourcename'       => $src['name'] ?? null,
                'matchedkey'       => $prefix,
            ];
        }
        return $out;
    }

    /**
     * Attach the reporting source to a finding.
     *
     * @param array $finding
     * @param array $entry Source summary entry.
     * @return array
     */
    private static function stamp_source(array $finding, array $entry): array {
        $finding['sourceid'] = $entry['id'];
        $finding['sourcename'] = $entry['name'];
        return $finding;
    }

    /**
     * Larger of a mismatch finding's two deltas, for ranking.
     *
     * @param array $finding
     * @return float
     */
    private static function worst_delta(array $finding): float {
        return max((float) ($finding['delta_pct_input'] ?? 0), (float) ($finding['delta_pct_output'] ?? 0));
    }

    /**
     * Email the spend-notify recipients about MISSING / MISMATCH findings.
     *
     * Idempotent per day AND per finding set: the stored flag is a hash of the
     * findings, so a second run on the same day is silent, but a NEW finding
     * appearing later the same day still alerts. Honours the email_optout table
     * the way cost_anomaly_detector does, and falls back to site admins when
     * `spend_notify_emails` is empty (the same fallback spend_guard uses).
     *
     * @param array $summary Output of {@see run()}.
     * @return bool True when at least one email was sent.
     */
    public static function maybe_send_alert(array $summary): bool {
        $counts = $summary['counts'] ?? [];
        if (($counts['missing'] ?? 0) <= 0 && ($counts['mismatch'] ?? 0) <= 0) {
            return false;
        }

        $flagkey = 'price_drift_notified_' . gmdate('Y-m-d', time());
        $fingerprint = self::fingerprint($summary);
        if ((string) (get_config('local_ai_course_assistant', $flagkey) ?: '') === $fingerprint) {
            return false;
        }

        $recipients = trim((string) (get_config('local_ai_course_assistant', 'spend_notify_emails') ?: ''));
        if ($recipients === '') {
            $recipients = implode(',', array_map(fn($a) => $a->email, get_admins()));
        }
        if ($recipients === '') {
            return false;
        }

        $subject = '[SOLA price drift] ' . (int) ($counts['missing'] ?? 0) . ' unpriced model(s) in traffic, '
            . (int) ($counts['mismatch'] ?? 0) . ' price mismatch(es)';
        $body = self::alert_body($summary);

        $sent = false;
        foreach (array_filter(array_map('trim', explode(',', $recipients))) as $email) {
            try {
                if (email_optout::is_opted_out($email, email_optout::TYPE_SPEND_ALERT)) {
                    continue;
                }
            } catch (\Throwable $e) {
                // Opt-out table unavailable: send anyway rather than swallow a
                // spend alert, matching cost_anomaly_detector.
                unset($e);
            }
            $to = new \stdClass();
            $to->id = -10;
            $to->email = $email;
            $to->firstname = '';
            $to->lastname = '';
            $to->maildisplay = true;
            $to->mailformat = 1;
            $to->firstnamephonetic = '';
            $to->lastnamephonetic = '';
            $to->middlename = '';
            $to->alternatename = '';
            try {
                if (\email_to_user($to, \core_user::get_noreply_user(), $subject, $body)) {
                    $sent = true;
                }
            } catch (\Throwable $e) {
                debugging('model_price_drift_check email send failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        if ($sent) {
            set_config($flagkey, $fingerprint, 'local_ai_course_assistant');
            try {
                global $DB;
                $DB->insert_record('local_ai_course_assistant_audit', (object) [
                    'action'      => 'model_price_drift_alert',
                    'userid'      => 0,
                    'courseid'    => 0,
                    'ipaddress'   => '',
                    'useragent'   => 'cli/scheduled_task',
                    'details'     => json_encode($summary['counts'] ?? []),
                    'timecreated' => time(),
                ]);
            } catch (\Throwable $e) {
                unset($e);
            }
        }
        return $sent;
    }

    /**
     * Stable hash of the alert-worthy findings.
     *
     * @param array $summary
     * @return string
     */
    private static function fingerprint(array $summary): string {
        $parts = [];
        foreach ($summary['findings'] ?? [] as $finding) {
            if (($finding['type'] ?? '') === 'new') {
                continue;
            }
            $parts[] = ($finding['type'] ?? '') . '|' . ($finding['modelkey'] ?? '')
                . '|' . round((float) ($finding['input'] ?? 0), 6)
                . '|' . round((float) ($finding['output'] ?? 0), 6);
        }
        sort($parts);
        return sha1(implode("\n", $parts));
    }

    /**
     * Plain-text alert body.
     *
     * @param array $summary
     * @return string
     */
    private static function alert_body(array $summary): string {
        $counts = $summary['counts'] ?? [];
        $body = "SOLA model price drift check\n\n";
        $body .= sprintf("Tolerance:            %.2f%%\n", (float) ($summary['tolerance_pct'] ?? 0));
        $body .= sprintf("Sources OK / failed:  %d / %d\n",
            (int) ($counts['sources_ok'] ?? 0), (int) ($counts['sources_failed'] ?? 0));
        $body .= sprintf("Unpriced in traffic:  %d\n", (int) ($counts['missing'] ?? 0));
        $body .= sprintf("Price mismatches:     %d\n", (int) ($counts['mismatch'] ?? 0));
        $body .= sprintf("Unknown to registry:  %d (informational)\n\n", (int) ($counts['new'] ?? 0));

        $missing = array_filter($summary['findings'] ?? [], fn($f) => ($f['type'] ?? '') === 'missing');
        if ($missing) {
            $body .= "UNPRICED MODELS IN BILLABLE TRAFFIC — highest severity.\n";
            $body .= "These models have no rate, so estimate_cost() returns null and every\n";
            $body .= "consumer treats that as nothing to attribute: their spend is reported as\n";
            $body .= "\$0.00 rather than as an error. The spend caps and the anomaly detector\n";
            $body .= "are blind to them.\n";
            foreach ($missing as $finding) {
                $body .= sprintf("  %-42s %7d calls", substr((string) $finding['modelkey'], 0, 42),
                    (int) ($finding['calls'] ?? 0));
                if ($finding['input'] !== null) {
                    $body .= sprintf("  proposed \$%.4f in / \$%.4f out per 1M (from %s)",
                        (float) $finding['input'], (float) $finding['output'],
                        (string) ($finding['sourcename'] ?? 'a source'));
                } else {
                    $body .= '  NO SOURCE KNOWS THIS MODEL — enter the price by hand';
                }
                $body .= "\n";
            }
            $body .= "\n";
        }

        $mismatches = array_filter($summary['findings'] ?? [], fn($f) => ($f['type'] ?? '') === 'mismatch');
        if ($mismatches) {
            $body .= "PRICE MISMATCHES (registry vs source, USD per 1M tokens).\n";
            foreach ($mismatches as $finding) {
                $body .= sprintf(
                    "  %-32s registry %.4f/%.4f  source %.4f/%.4f  (%.1f%% / %.1f%%)  [%s]\n",
                    substr((string) $finding['modelkey'], 0, 32),
                    (float) ($finding['registry_input'] ?? 0), (float) ($finding['registry_output'] ?? 0),
                    (float) ($finding['input'] ?? 0), (float) ($finding['output'] ?? 0),
                    (float) ($finding['delta_pct_input'] ?? 0), (float) ($finding['delta_pct_output'] ?? 0),
                    (string) ($finding['sourcename'] ?? '')
                );
            }
            $body .= "\n";
        }

        foreach ($summary['sources'] ?? [] as $source) {
            if (($source['status'] ?? '') !== 'ok') {
                $body .= 'SOURCE FAILED — ' . ($source['name'] ?? '') . ': ' . ($source['message'] ?? '') . "\n";
            }
        }

        $body .= "\nNothing has been changed. These are PROPOSALS: review and apply them at\n";
        $body .= "  Site administration > Plugins > Local plugins > AI Course Assistant > Model registry.\n";
        $body .= "Tolerance and the daily schedule are configurable in the same place.\n";
        return $body;
    }
}
