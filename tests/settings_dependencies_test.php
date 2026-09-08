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
 * Guards for the settings page's hide_if dependency map (v7.0.0).
 *
 * hide_if() fails silently: naming a setting that does not exist registers a
 * dependency that can never match, and the control simply keeps rendering. The
 * only way that surfaces is an admin noticing the page never collapses, which
 * is how the page reached 200+ always-visible controls in the first place.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \admin_settingpage
 */
final class settings_dependencies_test extends \advanced_testcase {
    /**
     * Build the plugin's settings page from the real admin tree.
     *
     * @return \admin_settingpage
     */
    private function settings_page(): \admin_settingpage {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->setAdminUser();
        $admin = admin_get_root(true, true);
        $page = $admin->locate('local_ai_course_assistant_general');
        $this->assertInstanceOf(
            \admin_settingpage::class,
            $page,
            'The plugin settings page must be reachable, or every assertion below is vacuous.'
        );
        return $page;
    }

    /**
     * Read the private dependency list off a settings page.
     *
     * @param \admin_settingpage $page
     * @return array [settingname, dependenton] pairs.
     */
    private function dependency_pairs(\admin_settingpage $page): array {
        $prop = (new \ReflectionClass($page))->getProperty('dependencies');
        $prop->setAccessible(true);
        $pairs = [];
        foreach ($prop->getValue($page) as $dep) {
            $rc = new \ReflectionClass($dep);
            $get = function (string $name) use ($rc, $dep) {
                $p = $rc->getProperty($name);
                $p->setAccessible(true);
                return $p->getValue($dep);
            };
            $pairs[] = [$get('settingname'), $get('dependenton')];
        }
        return $pairs;
    }

    public function test_every_dependency_names_settings_that_exist(): void {
        $this->resetAfterTest();
        $page = $this->settings_page();

        // admin_settingdependency::parse_name() normalises 'plugin/name' into
        // the form-element name ('s_plugin_name'), so compare against each
        // setting's own get_full_name() rather than re-deriving the transform.
        $onpage = [];
        foreach ((array) $page->settings as $setting) {
            $onpage[$setting->get_full_name()] = true;
        }
        $exists = function (string $fullname) use ($onpage): bool {
            return isset($onpage[$fullname]);
        };

        $pairs = $this->dependency_pairs($page);
        $this->assertNotEmpty($pairs, 'No dependencies registered — the hide_if map has been lost.');

        $broken = [];
        foreach ($pairs as [$setting, $dependenton]) {
            if (!$exists($setting)) {
                $broken[] = "hidden setting missing: {$setting}";
            }
            if (!$exists($dependenton)) {
                $broken[] = "toggle missing: {$dependenton}";
            }
        }
        $this->assertSame([], $broken, implode("\n", $broken));
    }

    public function test_no_setting_carries_more_than_one_dependency(): void {
        $this->resetAfterTest();
        $pairs = $this->dependency_pairs($this->settings_page());

        // The map is deliberately a tree: each setting hangs off its nearest
        // owning toggle only. Two dependencies on one setting would make its
        // visibility depend on how Moodle combines them, which is not something
        // the map should be relying on.
        $counts = array_count_values(array_column($pairs, 0));
        $multiple = array_keys(array_filter($counts, fn($n) => $n > 1));
        $this->assertSame(
            [],
            $multiple,
            'These settings have more than one dependency: ' . implode(', ', $multiple)
        );
    }

    public function test_removed_settings_are_no_longer_registered(): void {
        $this->resetAfterTest();
        $onpage = [];
        foreach ((array) $this->settings_page()->settings as $setting) {
            $onpage[] = $setting->get_full_name();
        }
        $this->assertNotEmpty($onpage, 'Parsed no settings — this guard would pass vacuously.');

        // Removed in v7.0.0 because nothing read them. Re-adding any of these
        // should be a deliberate act that also wires up a consumer.
        foreach (
            [
                'failover_timeout_voice',
                'talking_avatar_provider_url',
                'talking_avatar_provider_api_key',
            ] as $removed
        ) {
            $this->assertNotContains(
                's_local_ai_course_assistant_' . $removed,
                $onpage,
                "{$removed} was removed as unread; it must not come back without a consumer."
            );
        }
    }
}
