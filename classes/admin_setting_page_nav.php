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
 * The navigation row at the top of each settings page (settings_layout::nav_html()).
 *
 * A plain admin_setting_description matches an admin search on its HTML, and
 * every page's row names every page, so a search for "Features", "Advanced"
 * or "settings" listed one navigation row per page. This notice stores
 * nothing and is never a search result.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class admin_setting_page_nav extends \admin_setting_description {
    /**
     * Never match an admin search: the row only repeats the page titles.
     *
     * @param string $query The search text.
     * @return bool Always false.
     */
    public function is_related($query) {
        return false;
    }
}
