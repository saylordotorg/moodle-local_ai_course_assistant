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
 * A hidden settings page that tells the browser where the page it asked for moved to (7.10.0, reworked in 7.12.2).
 *
 * Before 7.10.0 the plugin had eleven settings pages, and bookmarks, emails
 * and old release notes name them (admin/settings.php?section=
 * local_ai_course_assistant_ai). Those ids no longer hold settings. Moodle's
 * admin/settings.php refuses anything that is not an admin_settingpage
 * ("Section error!"), so an admin_externalpage cannot catch the old name; a
 * page of this class can.
 *
 * It must NOT redirect from check_access(). Until 7.12.1 it did: settings.php
 * calls check_access() on the located page before it prints anything, so that
 * worked on 4.5, but core also calls check_access() on every page of the admin
 * tree whenever it builds the settings navigation, and on Moodle 5.x a block
 * can ask for that navigation while the page header is printing, where
 * redirect() throws "You cannot redirect while printing the page header" (#342).
 * So this class does not override check_access(): it only answers the access
 * question, reads no request data and never redirects. Do not add a redirect()
 * there, or anywhere the navigation can reach. The page does its work where
 * settings.php prints a settings page, after the header, in output_html(): a
 * notice naming the new page with a link to it (all a browser without script
 * needs) and a script that asks the browser to go there at once.
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

    /** @var string Title of the settings page that replaced this one; the link text. */
    public string $targettitle;

    /**
     * Create a redirect page.
     *
     * @param string $name Admin tree id of the old page, e.g. local_ai_course_assistant_ai.
     * @param string $visiblename Heading shown above the notice.
     * @param \moodle_url $url The settings page the notice links to and the browser is sent to.
     * @param string $targettitle Title of that page, shown as the link text (plain text); the address when empty.
     */
    public function __construct(string $name, string $visiblename, \moodle_url $url, string $targettitle = '') {
        parent::__construct($name, $visiblename, 'moodle/site:config', true);
        $this->url = $url;
        $this->targettitle = $targettitle;
    }

    /**
     * What admin/settings.php prints for this page, after the header: where the page moved, and a jump there.
     *
     * The link is the whole fallback for a browser without script. The script
     * is Moodle's own inline AMD block in the page footer, and it replaces the
     * history entry so Back does not return to this page.
     *
     * @return string HTML.
     */
    public function output_html() {
        global $OUTPUT, $PAGE;

        $target = $this->url->out(false);
        $name = $this->targettitle !== '' ? $this->targettitle : $target;
        $link = \html_writer::link($this->url, s($name));

        // The hex flags keep a quote, an ampersand or "</script>" in the address from ending the string or the element.
        $address = json_encode($target, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $PAGE->requires->js_amd_inline('window.location.replace(' . $address . ');');

        $notice = get_string('settingspage:redirect_notice', 'local_ai_course_assistant', $link);
        return $OUTPUT->notification($notice, \core\output\notification::NOTIFY_INFO, false);
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
