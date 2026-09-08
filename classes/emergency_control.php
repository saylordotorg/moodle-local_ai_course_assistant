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

/**
 * Emergency-disable mechanism for SOLA (v5.4.5).
 *
 * Single source of truth for the "kill switch" surface. Used by:
 *   - admin/cli/emergency_disable.php (one-shot CLI for ops)
 *   - PHPUnit tests pinning the disable / restore round-trip
 *   - Future admin UI panic button (v5.5+)
 *
 * Design choices the runbook depends on:
 *   - Each disable() call writes an audit row keyed `emergency_disable`
 *     with the invoker, flags, and reason — incident review starts there.
 *   - Voice's `voice_active_realtime` is stashed into a backup config row
 *     before being cleared, so restore() can put it back exactly.
 *   - The chat-only kill leaves the widget rendering and uses
 *     spend_cap_site=0 so learners get the friendly "budget paused" path.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class emergency_control {
    /** Master kill (full chat widget + scheduled tasks + SSE). */
    public const FLAG_ALL = 'all';
    /** Voice realtime + TTS only. */
    public const FLAG_VOICE = 'voice';
    /** RAG retrieval and indexing only. */
    public const FLAG_RAG = 'rag';
    /** Outreach / digest / milestone emails only. */
    public const FLAG_OUTREACH = 'outreach';
    /** Chat-only kill via spend_cap_site=0 (widget keeps rendering). */
    public const FLAG_CHAT = 'chat';

    /**
     * Disable the named subsystems and write an audit row.
     *
     * @param array $flags Subset of FLAG_* constants.
     * @param string $reason Free-text reason recorded in the audit row.
     * @param string $invoker 'cli' | 'admin_ui' | 'test' — recorded only.
     * @return array Human-readable list of touched config keys.
     */
    public static function disable(array $flags, string $reason = '', string $invoker = 'cli'): array {
        $set = self::flag_set($flags);
        $touched = [];

        if ($set[self::FLAG_ALL]) {
            self::stash('enabled');
            set_config('enabled', '0', 'local_ai_course_assistant');
            $touched[] = 'enabled (master)';
        }
        if ($set[self::FLAG_VOICE] || $set[self::FLAG_ALL]) {
            // Stash before clear so restore() can put it back exactly.
            self::stash('voice_active_realtime');
            set_config('voice_active_realtime', '', 'local_ai_course_assistant');
            // v7.0.5: a dedicated flag, because blanking the active label was
            // not actually a kill switch. voice_registry::resolve() treats a
            // blank label as "admin never picked a row" and falls back to the
            // first configured row, so on any site with voice_providers set up
            // this moved voice onto row 0 rather than stopping it. And when the
            // label was already blank, the guard above wrote no backup at all,
            // leaving restore() nothing to undo. Same failure the `--chat`
            // switch had until v5.13, fixed the same way it was.
            set_config('emergency_voice_disabled', '1', 'local_ai_course_assistant');
            $touched[] = 'voice_active_realtime, emergency_voice_disabled (set to 1)';
        }
        if ($set[self::FLAG_RAG] || $set[self::FLAG_ALL]) {
            self::stash('rag_enabled');
            self::stash('rag_auto_reindex_drifted');
            set_config('rag_enabled', '0', 'local_ai_course_assistant');
            set_config('rag_auto_reindex_drifted', '0', 'local_ai_course_assistant');
            $touched[] = 'rag_enabled, rag_auto_reindex_drifted';
        }
        if ($set[self::FLAG_OUTREACH] || $set[self::FLAG_ALL]) {
            self::stash('outreach_master_enabled');
            set_config('outreach_master_enabled', '0', 'local_ai_course_assistant');
            $touched[] = 'outreach_master_enabled';
        }
        if ($set[self::FLAG_CHAT] && !$set[self::FLAG_ALL]) {
            // Chat-only: leave widget rendering, but set a dedicated kill
            // flag that spend_guard::check() consults to return CAP_BLOCKED
            // so the friendly "SOLA paused" path runs.
            //
            // v5.13.0 fix: prior versions of this branch set spend_cap_site=0
            // thinking "0 = paused", but get_cap() treats 0 as unlimited.
            // That made --chat a silent no-op on every release from v5.4.5
            // through v5.12.x. The dedicated flag below is unambiguous.
            // Backward-compatible: the spend_cap_site backup-and-clear is
            // kept for sites that may still rely on the legacy behavior
            // alongside the new flag.
            self::stash('spend_cap_site');
            set_config('spend_cap_site', '0', 'local_ai_course_assistant');
            set_config('emergency_chat_disabled', '1', 'local_ai_course_assistant');
            $touched[] = 'spend_cap_site (set to 0)';
            $touched[] = 'emergency_chat_disabled (set to 1)';
        }

        self::write_audit('disable', $flags, $reason, $invoker, $touched);
        return $touched;
    }

    /**
     * Restore the named subsystems. Call with the same flags you used
     * to disable; pass FLAG_ALL to restore everything that was touched.
     *
     * @param array $flags Subset of FLAG_* constants.
     * @param string $reason Free-text reason recorded in the audit row.
     * @param string $invoker
     * @return array
     */
    public static function restore(array $flags, string $reason = '', string $invoker = 'cli'): array {
        $set = self::flag_set($flags);
        $touched = [];

        if ($set[self::FLAG_ALL]) {
            self::unstash('enabled', '1');
            $touched[] = 'enabled (master)';
        }
        if ($set[self::FLAG_VOICE] || $set[self::FLAG_ALL]) {
            self::unstash('voice_active_realtime', '');
            // Legacy backup key from releases before v7.3.2; drain it so an
            // upgrade mid-incident still restores rather than stranding it.
            $legacy = get_config('local_ai_course_assistant', 'voice_active_realtime_backup');
            if ($legacy !== false && $legacy !== '') {
                set_config('voice_active_realtime', (string) $legacy, 'local_ai_course_assistant');
                unset_config('voice_active_realtime_backup', 'local_ai_course_assistant');
            }
            unset_config('emergency_voice_disabled', 'local_ai_course_assistant');
            $touched[] = 'voice_active_realtime (restored from backup), emergency_voice_disabled (cleared)';
        }
        if ($set[self::FLAG_RAG] || $set[self::FLAG_ALL]) {
            self::unstash('rag_enabled', '1');
            self::unstash('rag_auto_reindex_drifted', '1');
            $touched[] = 'rag_enabled, rag_auto_reindex_drifted';
        }
        if ($set[self::FLAG_OUTREACH] || $set[self::FLAG_ALL]) {
            // Shipped default is '0' (settings.php). Restoring to '1' turned on
            // a switch the operator never enabled -- the bug this release fixes.
            self::unstash('outreach_master_enabled', '0');
            $touched[] = 'outreach_master_enabled';
        }
        if ($set[self::FLAG_CHAT] && !$set[self::FLAG_ALL]) {
            self::unstash('spend_cap_site', '');
            $legacycap = get_config('local_ai_course_assistant', 'spend_cap_site_backup');
            if ($legacycap !== false && $legacycap !== '') {
                set_config('spend_cap_site', (string) $legacycap, 'local_ai_course_assistant');
                unset_config('spend_cap_site_backup', 'local_ai_course_assistant');
            }
            unset_config('emergency_chat_disabled', 'local_ai_course_assistant');
            $touched[] = 'spend_cap_site (restored from backup)';
            $touched[] = 'emergency_chat_disabled (cleared)';
        }

        self::write_audit('restore', $flags, $reason, $invoker, $touched);
        return $touched;
    }

    /**
     * Stash a setting's current value so restore() can put it back exactly.
     *
     * Presence of the backup key is the "was stashed" signal, NOT whether the
     * value is non-empty. The voice branch used to guard on `$current !== ''`,
     * so blanking an already-blank label wrote no backup and restore() had
     * nothing to undo. An empty string is a real value and must round-trip.
     *
     * Never overwrites an existing backup: disabling twice must not capture the
     * already-zeroed value as the original. The backup is cleared by unstash().
     *
     * @param string $key
     * @return void
     */
    private static function stash(string $key): void {
        if (get_config('local_ai_course_assistant', $key . '_emergency_backup') !== false) {
            return;
        }
        set_config(
            $key . '_emergency_backup',
            (string) get_config('local_ai_course_assistant', $key),
            'local_ai_course_assistant'
        );
    }

    /**
     * Put a stashed value back, or fall back when nothing was stashed.
     *
     * The fallback exists for a site restored after upgrading from a release
     * whose disable() never stashed. It is the shipped default, not a
     * hard-coded '1': restore() used to write '1' to outreach_master_enabled
     * unconditionally, and that setting ships '0', so MASTER KILL followed by
     * Restore turned ON a safety switch the operator had never enabled.
     *
     * @param string $key
     * @param string $fallback
     * @return void
     */
    private static function unstash(string $key, string $fallback): void {
        $backup = get_config('local_ai_course_assistant', $key . '_emergency_backup');
        if ($backup !== false) {
            set_config($key, (string) $backup, 'local_ai_course_assistant');
            unset_config($key . '_emergency_backup', 'local_ai_course_assistant');
            return;
        }
        set_config($key, $fallback, 'local_ai_course_assistant');
    }

    /**
     * Normalise an array of flag strings into a boolean-keyed lookup.
     * Unknown flags are ignored — defensive against partner-tooling drift.
     *
     * @param array $flags
     * @return array
     */
    private static function flag_set(array $flags): array {
        $valid = [self::FLAG_ALL, self::FLAG_VOICE, self::FLAG_RAG,
                  self::FLAG_OUTREACH, self::FLAG_CHAT];
        $set = array_fill_keys($valid, false);
        foreach ($flags as $f) {
            if (in_array($f, $valid, true)) {
                $set[$f] = true;
            }
        }
        return $set;
    }

    /**
     * Write the audit row. Failures are mtraced (CLI) or debugging()'d
     * (web) and never re-thrown — losing the audit row must not block
     * the actual disable from taking effect.
     *
     * @param string $action 'disable' | 'restore'
     * @param array $flags
     * @param string $reason
     * @param string $invoker
     * @param array $touched
     * @return void
     */
    private static function write_audit(
        string $action,
        array $flags,
        string $reason,
        string $invoker,
        array $touched
    ): void {
        try {
            // Attribute the row to whoever threw the switch. It was hardcoded to
            // userid 0, so every emergency action in the audit trail was
            // unattributed and the identity survived only in the free-text
            // invoked_by field. CLI runs legitimately have no user, hence the ?? 0.
            global $USER;
            audit_logger::log('emergency_' . $action, (int) ($USER->id ?? 0), 0, [
                'flags' => array_values($flags),
                'touched' => $touched,
                'reason' => $reason,
                'invoked_by' => $invoker,
            ]);
        } catch (\Throwable $e) {
            debugging(
                'emergency_control: audit logging failed: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }
}
