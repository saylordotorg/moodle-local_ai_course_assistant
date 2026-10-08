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

/**
 * Candidate models per role, and their lifecycle (v7.8.0).
 *
 * A candidate is (role, provider, model, variant). Its status moves:
 *
 *   candidate -> passed (one passing evaluation) -> eligible (two in a row)
 *             -> switched -> kept | rolledback
 *   any evaluation that fails the gate sends it to failed, which resets the
 *   count: two passes means two CONSECUTIVE passes, each against a fresh
 *   measurement of the model it would replace.
 *
 * A failed or rolled-back candidate is left alone for RETRY_AFTER_DAYS, then
 * becomes eligible for evaluation again, because providers change models
 * behind a stable name and a verdict from a month ago is stale.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class candidates {
    /** @var string Table. */
    public const TABLE = 'local_ai_course_assistant_model_cand';

    /** @var string Discovered, never evaluated (or due again). */
    public const CANDIDATE = 'candidate';

    /** @var string Passed the gate once; needs another pass. */
    public const PASSED = 'passed';

    /** @var string Passed the gate REQUIRED_PASSES times in a row. */
    public const ELIGIBLE = 'eligible';

    /** @var string The last evaluation failed the gate. */
    public const FAILED = 'failed';

    /** @var string Now serving the role. */
    public const SWITCHED = 'switched';

    /** @var string Switched to and rolled back by the watcher or an admin. */
    public const ROLLEDBACK = 'rolledback';

    /** @var string Listed but not a candidate any more (provider dropped it). */
    public const RETIRED = 'retired';

    /** @var int Days before a failed or rolled-back candidate may be evaluated again. */
    public const RETRY_AFTER_DAYS = 30;

    /**
     * Insert or refresh one candidate. Never resets an evaluated status.
     *
     * @param string $role
     * @param string $provider
     * @param string $model
     * @param string $variant
     * @param string $reason Why it is a candidate (price band, discovery date).
     * @return int Row id.
     */
    public static function upsert(string $role, string $provider, string $model, string $variant, string $reason): int {
        global $DB;
        $now = time();
        $key = ['role' => $role, 'provider' => $provider, 'model' => $model, 'variant' => $variant];
        $row = $DB->get_record(self::TABLE, $key);
        if ($row) {
            // Seeing a candidate again must not touch timestatus: due() times
            // the second pass and the 30-day retry from it, and discovery runs
            // every night.
            $update = (object) ['id' => $row->id, 'reason' => $reason, 'timemodified' => $now];
            if ($row->status === self::RETIRED) {
                $update->status = self::CANDIDATE;
                $update->timestatus = $now;
            }
            $DB->update_record(self::TABLE, $update);
            return (int) $row->id;
        }
        return (int) $DB->insert_record(self::TABLE, (object) ($key + [
            'status' => self::CANDIDATE,
            'passes' => 0,
            'lastevalid' => null,
            'reason' => $reason,
            'timestatus' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ]));
    }

    /**
     * One candidate by id.
     *
     * @param int $id
     * @return \stdClass|null
     */
    public static function get(int $id): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $id]) ?: null;
    }

    /**
     * Candidates for a role, best first for evaluation.
     *
     * @param string $role
     * @return \stdClass[]
     */
    public static function for_role(string $role): array {
        global $DB;
        return array_values($DB->get_records(self::TABLE, ['role' => $role], 'timecreated ASC, id ASC'));
    }

    /**
     * Mark candidates of a role that the provider no longer lists.
     *
     * @param string $role
     * @param string $provider
     * @param string[] $keep Model ids still listed.
     * @return void
     */
    public static function retire_missing(string $role, string $provider, array $keep): void {
        global $DB;
        foreach ($DB->get_records(self::TABLE, ['role' => $role, 'provider' => $provider]) as $row) {
            if (
                !in_array($row->model, $keep, true)
                    && in_array($row->status, [self::CANDIDATE, self::PASSED, self::FAILED], true)
            ) {
                $DB->update_record(self::TABLE, (object) ['id' => $row->id, 'status' => self::RETIRED,
                    'timestatus' => time(), 'timemodified' => time()]);
            }
        }
    }

    /**
     * Record an evaluation's gate verdict on its candidate.
     *
     * @param int $id
     * @param bool $passed
     * @param int $evalid
     * @param int $required Passes in a row needed to become eligible.
     * @return \stdClass The updated row.
     */
    public static function record_verdict(int $id, bool $passed, int $evalid, int $required): \stdClass {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
        $passes = $passed ? (int) $row->passes + 1 : 0;
        $status = !$passed ? self::FAILED : ($passes >= $required ? self::ELIGIBLE : self::PASSED);
        $DB->update_record(self::TABLE, (object) ['id' => $id, 'passes' => $passes, 'status' => $status,
            'lastevalid' => $evalid, 'timestatus' => time(), 'timemodified' => time()]);
        return $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Set a status directly (switched, rolled back).
     *
     * @param int $id
     * @param string $status
     * @return void
     */
    public static function set_status(int $id, string $status): void {
        global $DB;
        $DB->update_record(self::TABLE, (object) ['id' => $id, 'status' => $status, 'timestatus' => time(),
            'timemodified' => time()]);
    }

    /**
     * Is this candidate due for an evaluation now?
     *
     * @param \stdClass $row
     * @param int $now
     * @return bool
     */
    public static function due(\stdClass $row, int $now): bool {
        switch ($row->status) {
            case self::CANDIDATE:
                return true;
            case self::PASSED:
                // The second pass is taken on another day, so the two
                // measurements see different provider conditions.
                return $now - (int) $row->timestatus >= 20 * HOURSECS;
            case self::FAILED:
            case self::ROLLEDBACK:
                return $now - (int) $row->timestatus >= self::RETRY_AFTER_DAYS * DAYSECS;
            default:
                return false;
        }
    }
}
