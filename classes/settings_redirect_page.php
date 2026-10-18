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

require_once($CFG->libdir . '/adminlib.php');

/**
 * A hidden settings page that sends the browser to the page that replaced it (7.10.0).
 *
 * Before 7.10.0 the plugin had eleven settings pages, and bookmarks, emails
 * and old release notes name them (admin/settings.php?section=
 * local_ai_course_assistant_ai). Those ids no longer hold settings. Moodle's
 * admin/settings.php refuses anything that is not an admin_settingpage
 * ("Section error!"), so an admin_externalpage cannot catch the old name; a
 * page of this class can, because settings.php calls check_access() on the
 * located page before it prints anything, and this page redirects there.
 *
 * It holds no settings, is hidden from the admin tree and the plugin's page
 * lists, and never appears in an admin search.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings_redirect_page extends \admin_settingpage {
    /** @var \moodle_url The settings page that replaced this one. */
    public \moodle_url $url;

    /**
     * Create a redirect page.
     *
     * @param string $name Admin tree id of the old page, e.g. local_ai_course_assistant_ai.
     * @param string $visiblename Title (never shown: the page is hidden and redirects).
     * @param \moodle_url $url The settings page to send the browser to.
     */
    public function __construct(string $name, string $visiblename, \moodle_url $url) {
        parent::__construct($name, $visiblename, 'moodle/site:config', true);
        $this->url = $url;
    }

    /**
     * Whether the current request is asking for this page, as admin/settings.php?section= does.
     *
     * @return bool
     */
    public function is_requested(): bool {
        return optional_param('section', '', PARAM_SAFEDIR) === $this->name;
    }

    /**
     * Redirect when this page was asked for; otherwise just report access.
     *
     * Core calls this on every settings page of the tree when a settings form
     * is saved, so it redirects only for the one page named in the request,
     * and only for a user who could open it.
     *
     * @return bool True if the user has access.
     */
    public function check_access() {
        $allowed = parent::check_access();
        if ($allowed && $this->is_requested()) {
            redirect($this->url);
        }
        return $allowed;
    }

    /**
     * Never an admin search result: the page holds nothing to find.
     *
     * @param string $query The search text.
     * @return array Always empty.
     */
    public function search($query) {
        return [];
    }
}
