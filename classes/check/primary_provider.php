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

namespace local_ai_course_assistant\check;

use core\check\check;
use core\check\result;
use local_ai_course_assistant\provider\failover_chain;

/**
 * Status check: is the main chat provider answering, or is the backup doing its work?
 *
 * When the provider SOLA is set to use fails and a failover provider is
 * configured, learners still get answers, so nothing looks wrong. On dev in
 * October 2026 the provider setting slipped to "auto", every call to the main
 * provider was rejected, and chat ran on the backup model for about a day
 * before anyone knew. This check reads the failover audit rows and says so on
 * Site administration > Reports > Status.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class primary_provider extends check {

    /** @var int Look-back window in seconds. */
    public const WINDOW = DAYSECS;

    /** @var int Primary failures in the window that raise a warning. */
    public const WARN_AT = 3;

    /** @var int Primary failures in the window that raise an error. */
    public const ERROR_AT = 10;

    /**
     * Name shown in the status list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('check:primary_name', 'local_ai_course_assistant');
    }

    /**
     * Summarise primary-provider failures in the window as a status result.
     *
     * @return result
     */
    public function get_result(): result {
        $s = self::summarise(time() - self::WINDOW);
        if ($s['failures'] === 0) {
            return new result(result::OK, get_string('check:primary_ok', 'local_ai_course_assistant'));
        }
        $a = (object) [
            'failures' => $s['failures'],
            'rescued' => $s['rescued'],
            // Error text can be a gateway's HTML page, and a check result is shown as HTML.
            'model' => s($s['model'] !== '' ? $s['model'] : $s['primary']),
            'reason' => s($s['reason']),
        ];
        $status = $s['failures'] >= self::ERROR_AT ? result::ERROR
            : ($s['failures'] >= self::WARN_AT ? result::WARNING : result::INFO);
        return new result($status, get_string('check:primary_failing', 'local_ai_course_assistant', $a));
    }

    /**
     * Count failures of the primary provider, and the turns the backup answered.
     *
     * @param int $since Only rows at or after this time.
     * @return array{failures: int, rescued: int, primary: string, model: string, reason: string}
     */
    public static function summarise(int $since): array {
        global $DB;

        $out = ['failures' => 0, 'rescued' => 0, 'primary' => '', 'model' => '', 'reason' => ''];
        $rows = $DB->get_records_select(
            'local_ai_course_assistant_audit',
            'action = :a AND timecreated >= :t',
            ['a' => failover_chain::AUDIT_EVENT_FALLTHROUGH, 't' => $since],
            'timecreated DESC',
            'id, details',
            0,
            500
        );
        foreach ($rows as $row) {
            $d = json_decode((string) $row->details, true);
            if (!is_array($d) || ($d['failed_label'] ?? null) !== ($d['primary'] ?? '')) {
                continue;
            }
            $out['failures']++;
            if ($out['primary'] === '') {
                $out['primary'] = (string) $d['primary'];
                $out['model'] = (string) ($d['failed_model'] ?? '');
                $out['reason'] = self::clean((string) ($d['reason'] ?? ''));
            }
        }
        $out['rescued'] = $DB->count_records_select(
            'local_ai_course_assistant_audit',
            'action = :a AND timecreated >= :t',
            ['a' => failover_chain::AUDIT_EVENT_RESCUED, 't' => $since]
        );
        return $out;
    }

    /**
     * Shorten an error and blank anything shaped like a credential.
     *
     * @param string $reason
     * @return string
     */
    private static function clean(string $reason): string {
        $reason = preg_replace('/[A-Za-z0-9_\-\.]{24,}/', '[hidden]', $reason);
        return \core_text::substr(trim($reason), 0, 160);
    }
}
