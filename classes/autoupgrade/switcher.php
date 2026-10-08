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

use local_ai_course_assistant\audit_logger;
use local_ai_course_assistant\emergency_control;
use local_ai_course_assistant\model_capabilities;
use local_ai_course_assistant\policy_bundle;

/**
 * Switches a role to a candidate that passed the gate, and rolls it back (v7.8.0).
 *
 * The mode setting decides what happens when a candidate becomes eligible:
 *   off        nothing automatic at all (no discovery, no evaluation);
 *   recommend  evaluate, and email a recommendation when one is eligible;
 *   auto       evaluate, and switch when one is eligible (the shipped default).
 *
 * A switch changes the role's SITE setting and nothing else: a course with its
 * own model override keeps it, and the premium tier's trigger rules are never
 * written. It is refused while any emergency control is engaged, for a role
 * whose setting is not on the signed policy bundle's allowlist (the failover
 * model lives in a setting that holds an API key), for a setting the last
 * verified policy bundle manages (the fleet operator owns it, and the next
 * bundle would undo the switch), while another switch of the same role is
 * still being watched, and when the role's model changed after the
 * evaluation that justified the switch.
 *
 * Every switch records the previous values of exactly the settings it wrote,
 * a baseline of the old model's live traffic for the watcher, an audit row,
 * an event, and an email to the spend-alert recipients.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class switcher {
    /** @var string Table. */
    public const TABLE = 'local_ai_course_assistant_model_switch';

    /** @var string Mode: nothing automatic. */
    public const MODE_OFF = 'off';

    /** @var string Mode: evaluate and recommend. */
    public const MODE_RECOMMEND = 'recommend';

    /** @var string Mode: evaluate and switch. */
    public const MODE_AUTO = 'auto';

    /** @var string Switch status: inside the watch window. */
    public const WATCHING = 'watching';

    /** @var string Switch status: watch window passed, kept. */
    public const KEPT = 'kept';

    /** @var string Switch status: rolled back. */
    public const ROLLEDBACK = 'rolledback';

    /** @var string Switch status: someone changed the settings afterwards. */
    public const SUPERSEDED = 'superseded';

    /**
     * The configured mode.
     *
     * @return string
     */
    public static function mode(): string {
        $mode = (string) get_config('local_ai_course_assistant', 'autoupgrade_mode');
        return in_array($mode, [self::MODE_OFF, self::MODE_RECOMMEND, self::MODE_AUTO], true) ? $mode : self::MODE_AUTO;
    }

    /**
     * Act on a finished evaluation.
     *
     * @param \stdClass $cand Candidate row, already updated with the verdict.
     * @param \stdClass $eval Evaluation row.
     * @return void
     */
    public static function after_evaluation(\stdClass $cand, \stdClass $eval): void {
        global $DB;
        if ($cand->status !== candidates::ELIGIBLE) {
            return;
        }
        $ranked = self::ranked_eligible((string) $cand->role);
        if (!$ranked) {
            return;
        }
        $best = reset($ranked);
        $besteval = $DB->get_record(evaluator::TABLE, ['id' => (int) $best->lastevalid]);
        $spec = roles::spec((string) $best->role);
        if (self::mode() === self::MODE_AUTO && !empty($spec['auto'])) {
            // Best first; one blocked for a reason of its own (the reasoning
            // check on a thinking-off variant, say) doesn't hold back the next.
            $first = null;
            foreach ($ranked as $row) {
                $roweval = $DB->get_record(evaluator::TABLE, ['id' => (int) $row->lastevalid]);
                $result = self::switch_to($row, $roweval ?: null, 'auto', 0);
                if ($result['ok']) {
                    return;
                }
                $first = $first ?? $result['message'];
            }
            if ($besteval) {
                notifier::recommend($best, $besteval, (string) $first);
            }
            return;
        }
        if ($besteval) {
            notifier::recommend($best, $besteval, empty($spec['auto'])
                ? get_string('autoupgrade:why_manual_role', 'local_ai_course_assistant')
                : get_string('autoupgrade:why_recommend_mode', 'local_ai_course_assistant'));
        }
    }

    /**
     * The best eligible candidate of a role: highest measured quality, then cheapest.
     *
     * An eligible candidate whose evaluations measured a model the role no
     * longer runs is stale: it goes back to being a plain candidate, to be
     * evaluated again against the model it would now replace, instead of
     * outranking a fresh one it can never be switched in over.
     *
     * @param string $role
     * @return \stdClass|null
     */
    public static function best_eligible(string $role): ?\stdClass {
        $ranked = self::ranked_eligible($role);
        return $ranked ? reset($ranked) : null;
    }

    /**
     * Eligible candidates of a role, best first (quality, then cost). One whose
     * evaluation measured a model the role no longer runs goes back to
     * CANDIDATE with its passes reset, and isn't returned.
     *
     * @param string $role
     * @return \stdClass[]
     */
    public static function ranked_eligible(string $role): array {
        global $DB;
        $current = roles::current($role);
        $ranked = [];
        foreach ($DB->get_records(candidates::TABLE, ['role' => $role, 'status' => candidates::ELIGIBLE]) as $row) {
            $eval = $row->lastevalid ? $DB->get_record(evaluator::TABLE, ['id' => $row->lastevalid]) : null;
            $stale = !$eval || (string) $eval->inc_model !== $current['model']
                || (string) $eval->inc_provider !== $current['provider'];
            if ($stale) {
                $DB->update_record(candidates::TABLE, (object) ['id' => $row->id, 'status' => candidates::CANDIDATE,
                    'passes' => 0, 'timestatus' => time(), 'timemodified' => time()]);
                continue;
            }
            $metrics = json_decode((string) $eval->metrics, true);
            $q = (float) ($metrics['candidate']['quality'] ?? 0);
            $c = (float) ($metrics['candidate']['cost_cents'] ?? INF);
            $ranked[] = ['key' => [$q, -$c], 'row' => $row];
        }
        usort($ranked, function ($a, $b) {
            return $b['key'] <=> $a['key'];
        });
        return array_column($ranked, 'row');
    }

    /**
     * Why a role may not be switched now, or null when it may.
     *
     * @param string $role
     * @param array $writes Settings the switch would write.
     * @return string|null
     */
    public static function blocker(string $role, array $writes): ?string {
        global $DB;
        $spec = roles::spec($role);
        if (empty($writes) || $spec['modelkey'] === null) {
            return get_string('autoupgrade:block_role', 'local_ai_course_assistant');
        }
        if (emergency_control::any_active()) {
            return get_string('autoupgrade:block_emergency', 'local_ai_course_assistant');
        }
        foreach (array_keys($writes) as $key) {
            if (!in_array($key, policy_bundle::ALLOWED_KEYS, true)) {
                return get_string('autoupgrade:block_allowlist', 'local_ai_course_assistant', $key);
            }
        }
        $managed = self::managed_keys();
        if ((bool) get_config('local_ai_course_assistant', 'policy_bundle_enabled')) {
            foreach (array_keys($writes) as $key) {
                if (in_array($key, $managed, true)) {
                    return get_string('autoupgrade:block_bundle', 'local_ai_course_assistant', $key);
                }
            }
        }
        // The reasoning setting is site-wide. A switch that changes it changes
        // how every other role's thinking model thinks too, and none of them
        // was evaluated at the new level, so it waits for a person.
        $spec = roles::spec($role);
        if ($spec['variantkey'] !== null && array_key_exists($spec['variantkey'], $writes)) {
            foreach (roles::other_models($role) as $other) {
                $profile = model_capabilities::profile($other['provider'], $other['model']);
                $controlled = in_array(
                    $profile['reasoning'],
                    [model_capabilities::REASONING_OPENAI, model_capabilities::REASONING_GEMINI],
                    true
                );
                if (!empty($profile['thinks']) && $controlled) {
                    return $other['kind'] === 'course'
                        ? get_string('autoupgrade:block_reasoning_course', 'local_ai_course_assistant', $other['label'])
                        : get_string(
                            'autoupgrade:block_reasoning',
                            'local_ai_course_assistant',
                            get_string('autoupgrade:role_' . $other['label'], 'local_ai_course_assistant')
                        );
                }
            }
        }
        if ($DB->record_exists(self::TABLE, ['role' => $role, 'status' => self::WATCHING])) {
            return get_string('autoupgrade:block_watching', 'local_ai_course_assistant');
        }
        return null;
    }

    /**
     * Switch a role to a candidate.
     *
     * @param \stdClass $cand Candidate row.
     * @param \stdClass|null $eval The evaluation that justifies it.
     * @param string $how 'auto' or 'manual'.
     * @param int $userid Admin who asked, 0 for automatic.
     * @return array{ok: bool, message: string, switchid?: int}
     */
    public static function switch_to(\stdClass $cand, ?\stdClass $eval, string $how, int $userid): array {
        global $DB;
        $role = (string) $cand->role;
        $current = roles::current($role);
        if ($eval === null || $eval->status !== evaluator::COMPLETE || (int) $eval->candidateid !== (int) $cand->id) {
            return ['ok' => false, 'message' => get_string('autoupgrade:block_noeval', 'local_ai_course_assistant')];
        }
        if (!$eval->gate_passed) {
            return ['ok' => false, 'message' => get_string('autoupgrade:block_gate', 'local_ai_course_assistant')];
        }
        if ($current['provider'] !== (string) $cand->provider || $current['model'] !== (string) $eval->inc_model) {
            return ['ok' => false, 'message' => get_string('autoupgrade:block_changed', 'local_ai_course_assistant')];
        }
        $writes = roles::writes($role, (string) $cand->model, (string) $cand->variant);
        $blocked = self::blocker($role, $writes);
        if ($blocked !== null) {
            return ['ok' => false, 'message' => $blocked];
        }

        $previous = [];
        foreach ($writes as $key => $value) {
            $old = get_config('local_ai_course_assistant', $key);
            $previous[$key] = $old === false ? null : (string) $old;
        }
        $now = time();
        // From the release that started recording failed turns' models at the
        // latest: before it, every failure had a blank model and the old model
        // would look error-free.
        $since = max(
            $now - watcher::BASELINE_DAYS * DAYSECS,
            (int) get_config('local_ai_course_assistant', 'autoupgrade_failed_turns_since')
        );
        $baseline = watcher::metrics(
            $current['provider'],
            $current['model'],
            $since,
            $now
        );
        foreach ($writes as $key => $value) {
            set_config($key, $value, 'local_ai_course_assistant');
        }
        $id = (int) $DB->insert_record(self::TABLE, (object) [
            'role' => $role,
            'from_provider' => $current['provider'],
            'from_model' => $current['model'],
            'from_variant' => $current['variant'],
            'to_provider' => (string) $cand->provider,
            'to_model' => (string) $cand->model,
            'to_variant' => (string) $cand->variant,
            'candidateid' => (int) $cand->id,
            'evalid' => (int) $eval->id,
            'mode' => $how,
            'status' => self::WATCHING,
            'prevconfig' => json_encode($previous),
            'newconfig' => json_encode($writes),
            'baseline' => json_encode($baseline),
            'livemetrics' => null,
            'watchuntil' => $now + watcher::WATCH_HOURS * HOURSECS,
            'reason' => null,
            'createdby' => $userid > 0 ? $userid : null,
            'rolledbackby' => null,
            'timecreated' => $now,
            'timeresolved' => null,
        ]);
        candidates::set_status((int) $cand->id, candidates::SWITCHED);
        $details = ['switch' => $id, 'role' => $role, 'from' => $current['provider'] . '/' . $current['model']
            . self::variant_suffix($current['variant']), 'to' => $cand->provider . '/' . $cand->model
            . self::variant_suffix((string) $cand->variant), 'mode' => $how, 'settings' => array_keys($writes)];
        audit_logger::log('model_switched', $userid, 0, $details);
        \local_ai_course_assistant\event\model_switched::create([
            'context' => \context_system::instance(),
            'objectid' => $id,
            'other' => ['role' => $role, 'from' => $details['from'], 'to' => $details['to'], 'mode' => $how],
        ])->trigger();
        notifier::switched($DB->get_record(self::TABLE, ['id' => $id]), $eval);
        return ['ok' => true, 'message' => get_string('autoupgrade:switched', 'local_ai_course_assistant', (object) [
            'role' => $role, 'model' => $details['to']]), 'switchid' => $id];
    }

    /**
     * Roll a switch back to the settings it replaced.
     *
     * @param int $switchid
     * @param string $reason
     * @param int $userid Admin who asked, 0 for the watcher.
     * @param array|null $live Live metrics at the time.
     * @return array{ok: bool, message: string}
     */
    public static function rollback(int $switchid, string $reason, int $userid, ?array $live = null): array {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['id' => $switchid]);
        if (!$row || !in_array($row->status, [self::WATCHING, self::KEPT], true)) {
            return ['ok' => false, 'message' => get_string('autoupgrade:rollback_missing', 'local_ai_course_assistant')];
        }
        $new = json_decode((string) $row->newconfig, true) ?: [];
        if (!self::config_matches($new)) {
            $DB->update_record(self::TABLE, (object) ['id' => $row->id, 'status' => self::SUPERSEDED,
                'timeresolved' => time()]);
            return ['ok' => false, 'message' => get_string('autoupgrade:rollback_superseded', 'local_ai_course_assistant')];
        }
        foreach ((json_decode((string) $row->prevconfig, true) ?: []) as $key => $value) {
            if (!in_array($key, policy_bundle::ALLOWED_KEYS, true)) {
                continue;
            }
            if ($value === null) {
                unset_config($key, 'local_ai_course_assistant');
            } else {
                set_config($key, (string) $value, 'local_ai_course_assistant');
            }
        }
        $DB->update_record(self::TABLE, (object) [
            'id' => $row->id,
            'status' => self::ROLLEDBACK,
            'reason' => \core_text::substr($reason, 0, 1000),
            'rolledbackby' => $userid > 0 ? $userid : null,
            'livemetrics' => $live !== null ? json_encode($live) : $row->livemetrics,
            'timeresolved' => time(),
        ]);
        if (!empty($row->candidateid)) {
            candidates::set_status((int) $row->candidateid, candidates::ROLLEDBACK);
        }
        audit_logger::log('model_switch_rolled_back', $userid, 0, ['switch' => (int) $row->id, 'role' => $row->role,
            'to' => $row->from_provider . '/' . $row->from_model, 'reason' => $reason]);
        \local_ai_course_assistant\event\model_switch_rolled_back::create([
            'context' => \context_system::instance(),
            'objectid' => (int) $row->id,
            'other' => ['role' => $row->role, 'reason' => \core_text::substr($reason, 0, 255)],
        ])->trigger();
        notifier::rolledback($DB->get_record(self::TABLE, ['id' => $row->id]), $reason);
        return ['ok' => true, 'message' => get_string('autoupgrade:rolledback', 'local_ai_course_assistant', $row->role)];
    }

    /**
     * Settings the signed policy bundle manages.
     *
     * The keys the last verified bundle carried. A site that has not verified
     * one since upgrading to 7.8.0 falls back to the settings the last applied
     * bundle changed, from its audit row: a subset, but never a wrong one.
     *
     * @return string[]
     */
    public static function managed_keys(): array {
        global $DB;
        $keys = policy_bundle::managed_keys();
        if ($keys) {
            return $keys;
        }
        try {
            $rows = $DB->get_records(
                'local_ai_course_assistant_audit',
                ['action' => 'policy_bundle_applied'],
                'timecreated DESC, id DESC',
                'id, details',
                0,
                1
            );
            foreach ($rows as $row) {
                $details = json_decode((string) $row->details, true);
                return array_keys((array) ($details['changed'] ?? []));
            }
        } catch (\Throwable $e) {
            unset($e);
        }
        return [];
    }

    /**
     * Do the current settings still hold the values a switch wrote?
     *
     * @param array $values setting => value
     * @return bool
     */
    public static function config_matches(array $values): bool {
        foreach ($values as $key => $value) {
            if ((string) get_config('local_ai_course_assistant', $key) !== (string) $value) {
                return false;
            }
        }
        return true;
    }

    /**
     * " [off]" for a variant, '' otherwise.
     *
     * @param string $variant
     * @return string
     */
    public static function variant_suffix(string $variant): string {
        return $variant === '' ? '' : ' [' . $variant . ']';
    }
}
