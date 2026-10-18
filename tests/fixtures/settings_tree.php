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

namespace local_ai_course_assistant\tests;

/**
 * The one way tests walk the plugin's settings pages.
 *
 * 7.10.0 put five of the ten settings pages inside a nested Advanced category,
 * so a walk that reads only $category->children and keeps the
 * admin_settingpage instances silently skips half the settings, including
 * secret fields. Every test that needs the plugin's pages goes through here.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings_tree {
    /**
     * Every settings page below a category, nested categories included, in tree order.
     *
     * @param \admin_category $category The plugin category (or any category).
     * @return \admin_settingpage[] Keyed by page name.
     */
    public static function pages(\admin_category $category): array {
        $pages = [];
        foreach ($category->children as $child) {
            if ($child instanceof \admin_category) {
                $pages += self::pages($child);
            } else if ($child instanceof \admin_settingpage) {
                $pages[$child->name] = $child;
            }
        }
        return $pages;
    }

    /**
     * Every setting object on every page below a category, in tree order.
     *
     * @param \admin_category $category The plugin category (or any category).
     * @return \admin_setting[] A list; a name appears once per page that holds it.
     */
    public static function settings(\admin_category $category): array {
        $all = [];
        foreach (self::pages($category) as $page) {
            foreach ((array) $page->settings as $setting) {
                $all[] = $setting;
            }
        }
        return $all;
    }

    /**
     * Rebuild the admin tree and return the plugin category.
     *
     * The caller must be signed in as an administrator, or settings.php
     * registers nothing.
     *
     * @return \admin_category
     */
    public static function category(): \admin_category {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $category = \admin_get_root(true, true)->locate('local_ai_course_assistant');
        if (!$category instanceof \admin_category) {
            throw new \coding_exception('The local_ai_course_assistant admin category is not in the tree.');
        }
        return $category;
    }
}
