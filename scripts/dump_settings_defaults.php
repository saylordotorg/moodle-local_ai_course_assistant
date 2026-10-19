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

/**
 * Print every plugin setting's registered default as JSON.
 *
 * Walks every settings page under the local_ai_course_assistant admin
 * category (nested categories included) and prints {name: default}. Headings
 * and descriptions are skipped, and a secret field prints "<secret>" instead
 * of any default. Used to generate tests/fixtures/settings_defaults_snapshot.json,
 * which settings_layout_test compares the live tree against.
 *
 * Usage, from the Moodle tree:
 *   php local/ai_course_assistant/scripts/dump_settings_defaults.php > snapshot.json
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

\core\session\manager::set_user(get_admin());

$root = admin_get_root(true, true);
$category = $root->locate('local_ai_course_assistant');
if (!$category instanceof admin_category) {
    fwrite(STDERR, "The local_ai_course_assistant admin category was not found.\n");
    exit(1);
}

/**
 * Collect the default of every value setting below a category.
 *
 * @param admin_category $category Category to walk, recursively.
 * @param array $out Name => default, filled in place.
 * @return void
 */
function local_ai_course_assistant_dump_defaults(admin_category $category, array &$out): void {
    foreach ($category->children as $child) {
        if ($child instanceof admin_category) {
            local_ai_course_assistant_dump_defaults($child, $out);
            continue;
        }
        if (!$child instanceof admin_settingpage) {
            continue;
        }
        foreach ($child->settings as $setting) {
            if ($setting instanceof admin_setting_heading || $setting instanceof admin_setting_description) {
                continue;
            }
            $out[$setting->name] = $setting instanceof admin_setting_encryptedpassword
                ? '<secret>' : $setting->get_defaultsetting();
        }
    }
}

$defaults = [];
local_ai_course_assistant_dump_defaults($category, $defaults);
ksort($defaults);
echo json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
fwrite(STDERR, count($defaults) . " settings\n");
