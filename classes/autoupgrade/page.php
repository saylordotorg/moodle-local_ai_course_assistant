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

namespace local_ai_course_assistant\autoupgrade;

use local_ai_course_assistant\branding;
use local_ai_course_assistant\emergency_control;
use local_ai_course_assistant\model_capabilities;
use local_ai_course_assistant\model_registry_page;

/**
 * Data and actions for the model upgrades admin page (v7.8.0).
 *
 * All building and all writes live here so they can be tested; the page
 * script does access control, parameter cleaning, dispatch and rendering.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page {
    /** @var string Page URL. */
    public const URL = '/local/ai_course_assistant/model_upgrades.php';

    /** @var int Rows shown in the history tables. */
    public const HISTORY = 25;

    /**
     * Everything the template needs.
     *
     * @return array
     */
    public static function data(): array {
        global $DB;
        $limit = budget::limit();
        $spent = budget::spent();
        $last = (int) get_config('local_ai_course_assistant', 'autoupgrade_last_discovery');
        $status = [
            'mode' => get_string('autoupgrade:mode_' . switcher::mode(), 'local_ai_course_assistant'),
            'budget' => get_string('autoupgrade:budget_line', 'local_ai_course_assistant', (object) [
                'spent' => sprintf('%.2f', $spent), 'limit' => sprintf('%.2f', $limit),
                'left' => sprintf('%.2f', max(0, $limit - $spent))]),
            'lastdiscovery' => $last > 0
                ? get_string('autoupgrade:last_discovery', 'local_ai_course_assistant', userdate($last))
                : get_string('autoupgrade:never_discovered', 'local_ai_course_assistant'),
            'emergency' => emergency_control::any_active(),
            'settingsurl' => (new \moodle_url(
                '/admin/settings.php',
                ['section' => 'local_ai_course_assistant_operations'],
                'admin-autoupgrade_mode'
            ))->out(false),
        ];

        $roles = [];
        foreach (roles::all() as $role => $current) {
            $roles[] = self::role_block($role, $current);
        }

        $evals = [];
        foreach ($DB->get_records(evaluator::TABLE, null, 'timecreated DESC, id DESC', '*', 0, self::HISTORY) as $e) {
            $evals[] = self::eval_row($e);
        }
        $switches = [];
        foreach ($DB->get_records(switcher::TABLE, null, 'timecreated DESC, id DESC', '*', 0, self::HISTORY) as $s) {
            $switches[] = self::switch_row($s);
        }
        $learned = model_registry_page::learned_block();
        return [
            'status' => $status,
            'roles' => $roles,
            'evals' => $evals,
            'hasevals' => !empty($evals),
            'switches' => $switches,
            'hasswitches' => !empty($switches),
            'learned' => $learned,
            'registryurl' => (new \moodle_url(model_registry_page::PAGE_URL, [], 'sola-learnedcaps'))->out(false),
        ];
    }

    /**
     * One role: what it runs, its profile, its candidates.
     *
     * @param string $role
     * @param array $current
     * @return array
     */
    private static function role_block(string $role, array $current): array {
        global $DB;
        $profile = $current['model'] !== '' ? model_capabilities::profile($current['provider'], $current['model']) : null;
        $cands = [];
        foreach (candidates::for_role($role) as $c) {
            $eval = $c->lastevalid ? $DB->get_record(evaluator::TABLE, ['id' => $c->lastevalid]) : null;
            $busy = $DB->record_exists_select(
                evaluator::TABLE,
                'candidateid = :c AND status IN (:q, :r)',
                ['c' => $c->id, 'q' => evaluator::QUEUED, 'r' => evaluator::RUNNING]
            );
            $cands[] = [
                'id' => (int) $c->id,
                'model' => $c->model . switcher::variant_suffix((string) $c->variant),
                'status' => get_string('autoupgrade:cand_' . $c->status, 'local_ai_course_assistant'),
                'passes' => (int) $c->passes,
                'reason' => (string) $c->reason,
                'last' => $eval ? self::eval_summary($eval) : '',
                'canevaluate' => !empty($current['evaluable']) && !$busy
                    && !in_array($c->status, [candidates::SWITCHED, candidates::RETIRED], true),
                'busy' => $busy,
                'canswitch' => $eval && $eval->status === evaluator::COMPLETE && $eval->gate_passed
                    && $c->status !== candidates::SWITCHED && roles::spec($role)['modelkey'] !== null,
            ];
        }
        return [
            'role' => $role,
            'label' => get_string('autoupgrade:role_' . $role, 'local_ai_course_assistant'),
            'current' => $current['model'] !== ''
                ? $current['provider'] . '/' . $current['model'] . switcher::variant_suffix($current['variant'])
                : (in_array($role, [roles::CHAT, roles::FAILOVER], true) ? '-'
                    : get_string('autoupgrade:not_configured', 'local_ai_course_assistant')),
            'inuse' => $current['inuse'],
            'policy' => get_string(
                !$current['evaluable'] ? 'autoupgrade:policy_none'
                : ($current['auto'] ? 'autoupgrade:policy_auto' : 'autoupgrade:policy_recommend'),
                'local_ai_course_assistant'
            ),
            'profile' => $profile === null ? '' : self::profile_line($profile),
            'candidates' => $cands,
            'hascandidates' => !empty($cands),
        ];
    }

    /**
     * A capability profile as one readable line.
     *
     * @param array $p
     * @return string
     */
    public static function profile_line(array $p): string {
        $parts = [
            $p['token_param'],
            'temperature ' . $p['temperature'],
            'reasoning ' . $p['reasoning']
                . (!empty($p['reasoning_efforts']) ? ' (' . implode(', ', $p['reasoning_efforts']) . ')' : ''),
            $p['thinks'] ? 'thinks' : 'no thinking',
        ];
        if (!empty($p['max_output_tokens'])) {
            $parts[] = 'max output ' . (int) $p['max_output_tokens'];
        }
        $learned = array_keys(array_filter((array) $p['sources'], static function ($s) {
            return $s === 'learned';
        }));
        if ($learned) {
            $parts[] = 'learned: ' . implode(', ', $learned);
        }
        return implode(' | ', $parts);
    }

    /**
     * One-line evaluation verdict.
     *
     * @param \stdClass $e
     * @return string
     */
    private static function eval_summary(\stdClass $e): string {
        if ($e->status !== evaluator::COMPLETE) {
            return get_string('autoupgrade:eval_' . $e->status, 'local_ai_course_assistant')
                . ($e->message ? ': ' . $e->message : '');
        }
        $m = json_decode((string) $e->metrics, true) ?: [];
        return get_string($e->gate_passed ? 'autoupgrade:gate_pass' : 'autoupgrade:gate_fail', 'local_ai_course_assistant')
            . ' ' . sprintf(
                'quality %s vs %s, %s vs %s cents',
                self::num($m['candidate']['quality'] ?? null, 2),
                self::num($m['incumbent']['quality'] ?? null, 2),
                self::num($m['candidate']['cost_cents'] ?? null, 4),
                self::num($m['incumbent']['cost_cents'] ?? null, 4)
            );
    }

    /**
     * An evaluation row for the history table.
     *
     * @param \stdClass $e
     * @return array
     */
    private static function eval_row(\stdClass $e): array {
        $m = json_decode((string) ($e->metrics ?? ''), true) ?: [];
        $checks = [];
        foreach ((json_decode((string) ($e->gate_detail ?? ''), true) ?: []) as $name => $check) {
            $checks[] = ['name' => $name, 'ok' => !empty($check['ok']), 'detail' => (string) ($check['detail'] ?? '')];
        }
        $side = static function (?array $s): string {
            if (!$s) {
                return '';
            }
            $jb = $s['jailbreak'] ?? [];
            return sprintf(
                'quality %s (n=%d), %s cents, truncated %s, errors %s, TTFT p50 %s ms, jailbreak %d FAIL / %d ERROR /'
                . ' %d leaks',
                self::num($s['quality'] ?? null, 2),
                (int) ($s['judged'] ?? 0),
                self::num($s['cost_cents'] ?? null, 4),
                self::pct($s['truncated_rate'] ?? null),
                self::pct($s['error_rate'] ?? null),
                (string) ($s['p50_ttft_ms'] ?? '-'),
                (int) ($jb['FAIL'] ?? 0),
                (int) ($jb['ERROR'] ?? 0),
                (int) ($jb['leaks'] ?? 0)
            );
        };
        return [
            'when' => userdate((int) $e->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            'role' => get_string('autoupgrade:role_' . $e->role, 'local_ai_course_assistant'),
            'candidate' => $e->provider . '/' . $e->model . switcher::variant_suffix((string) $e->variant),
            'incumbent' => $e->inc_provider . '/' . $e->inc_model . switcher::variant_suffix((string) $e->inc_variant),
            'status' => get_string('autoupgrade:eval_' . $e->status, 'local_ai_course_assistant'),
            'gatepassed' => $e->status === evaluator::COMPLETE && (bool) $e->gate_passed,
            'gatefailed' => $e->status === evaluator::COMPLETE && !$e->gate_passed,
            'cost' => sprintf('$%.4f / $%s', (float) $e->actual_cost_usd, $e->est_cost_usd === null ? '-'
                : sprintf('%.2f', (float) $e->est_cost_usd)),
            'cand' => $side($m['candidate'] ?? null),
            'inc' => $side($m['incumbent'] ?? null),
            'checks' => $checks,
            'message' => (string) ($e->message ?? ''),
        ];
    }

    /**
     * A switch row for the history table.
     *
     * @param \stdClass $s
     * @return array
     */
    private static function switch_row(\stdClass $s): array {
        return [
            'id' => (int) $s->id,
            'when' => userdate((int) $s->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            'role' => get_string('autoupgrade:role_' . $s->role, 'local_ai_course_assistant'),
            'from' => $s->from_provider . '/' . $s->from_model . switcher::variant_suffix((string) $s->from_variant),
            'to' => $s->to_provider . '/' . $s->to_model . switcher::variant_suffix((string) $s->to_variant),
            'mode' => get_string('autoupgrade:how_' . $s->mode, 'local_ai_course_assistant'),
            'status' => get_string('autoupgrade:switch_' . $s->status, 'local_ai_course_assistant'),
            'reason' => (string) ($s->reason ?? ''),
            'until' => $s->status === switcher::WATCHING
                ? userdate((int) $s->watchuntil, get_string('strftimedatetimeshort', 'langconfig')) : '',
            'canrollback' => in_array($s->status, [switcher::WATCHING, switcher::KEPT], true),
        ];
    }

    /**
     * Queue an evaluation now.
     *
     * @param int $candidateid
     * @param int $userid
     * @return array{level: string, message: string}
     */
    public static function evaluate_now(int $candidateid, int $userid): array {
        $cand = candidates::get($candidateid);
        global $DB;
        if ($cand === null || empty(roles::spec((string) $cand->role)['evaluable'])) {
            return self::result('error', get_string('autoupgrade:block_role', 'local_ai_course_assistant'));
        }
        // A double submit must not queue two billable runs.
        if (
            $DB->record_exists_select(
                evaluator::TABLE,
                'candidateid = :c AND status IN (:q, :r)',
                ['c' => $candidateid, 'q' => evaluator::QUEUED, 'r' => evaluator::RUNNING]
            )
        ) {
            return self::result('warning', get_string('autoupgrade:l_evaluating', 'local_ai_course_assistant'));
        }
        evaluator::queue($candidateid, $userid);
        return self::result('success', get_string('autoupgrade:queued', 'local_ai_course_assistant'));
    }

    /**
     * Switch to an evaluated candidate now.
     *
     * @param int $candidateid
     * @param int $userid
     * @return array{level: string, message: string}
     */
    public static function switch_now(int $candidateid, int $userid): array {
        global $DB;
        $cand = candidates::get($candidateid);
        if ($cand === null) {
            return self::result('error', get_string('autoupgrade:block_noeval', 'local_ai_course_assistant'));
        }
        $eval = $cand->lastevalid ? $DB->get_record(evaluator::TABLE, ['id' => $cand->lastevalid]) : null;
        $out = switcher::switch_to($cand, $eval ?: null, 'manual', $userid);
        return self::result($out['ok'] ? 'success' : 'error', $out['message']);
    }

    /**
     * Roll a switch back now.
     *
     * @param int $switchid
     * @param int $userid
     * @return array{level: string, message: string}
     */
    public static function rollback_now(int $switchid, int $userid): array {
        $out = switcher::rollback(
            $switchid,
            get_string('autoupgrade:rollback_by_admin', 'local_ai_course_assistant'),
            $userid
        );
        return self::result($out['ok'] ? 'success' : 'error', $out['message']);
    }

    /**
     * Run discovery now (lists models; spends nothing).
     *
     * @return array{level: string, message: string}
     */
    public static function discover_now(): array {
        $summary = (new discovery())->run();
        $marked = 0;
        foreach ($summary['candidates'] as $list) {
            $marked += count($list);
        }
        return self::result(empty($summary['errors']) ? 'success' : 'warning', get_string(
            'autoupgrade:discovered',
            'local_ai_course_assistant',
            (object) ['providers' => count($summary['providers']),
                'registered' => $summary['registered'], 'candidates' => $marked,
            'errors' => implode(
                ', ',
                array_keys($summary['errors'])
            ) ?: '-']
        ));
    }

    /**
     * Number to fixed decimals, or a dash.
     *
     * @param mixed $v
     * @param int $decimals
     * @return string
     */
    private static function num($v, int $decimals): string {
        return $v === null ? '-' : number_format((float) $v, $decimals);
    }

    /**
     * Fraction as a percentage, or a dash.
     *
     * @param mixed $v
     * @return string
     */
    private static function pct($v): string {
        return $v === null ? '-' : number_format(100 * (float) $v, 1) . '%';
    }

    /**
     * Uniform action result.
     *
     * @param string $level
     * @param string $message
     * @return array
     */
    private static function result(string $level, string $message): array {
        return ['level' => $level, 'message' => branding::apply($message)];
    }
}
