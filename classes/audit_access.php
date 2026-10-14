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
 * Who may read the audit log page (v7.8.8).
 *
 * Until 7.8.8 the page required moodle/site:config, which is also the right to
 * change every setting on the site, so a monitoring account that only needed to
 * READ the log had to be as powerful as an administrator. The dedicated
 * capability lets an administrator grant read access alone. Holding
 * moodle/site:config still opens the page, so nobody who could read it before
 * loses access.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_access {
    /** @var string The capability that grants read-only access to the audit log. */
    public const CAPABILITY = 'local/ai_course_assistant:viewaudit';

    /**
     * Whether a user may read the audit log.
     *
     * @param \stdClass|int|null $user User object or id; null for the current user.
     * @return bool
     */
    public static function can_view($user = null): bool {
        $context = \context_system::instance();
        return has_capability(self::CAPABILITY, $context, $user)
            || has_capability('moodle/site:config', $context, $user);
    }

    /**
     * Stop the request unless the current user may read the audit log.
     *
     * @return void
     * @throws \required_capability_exception
     */
    public static function require_view(): void {
        if (!self::can_view()) {
            // Name the new capability: it is the one an administrator can grant.
            require_capability(self::CAPABILITY, \context_system::instance());
        }
    }
}
