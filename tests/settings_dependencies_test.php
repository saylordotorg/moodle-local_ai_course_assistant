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
        $pages = $this->settings_pages();
        return reset($pages);
    }

    /**
     * Every settings page the plugin registers.
     *
     * Issue #292 split one page into eleven, because core serialises a page's
     * whole show/hide dependency map into a single js_call_amd argument and
     * ours reached 5,290 characters against a 1,024 limit. Anything that
     * reasons about dependencies has to look at all of them now: before the
     * split this helper returned the only page, and a test that kept doing that
     * would quietly assert nothing, since the general page now carries no
     * dependencies at all.
     *
     * @return \admin_settingpage[] keyed by page name.
     */
    private function settings_pages(): array {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->setAdminUser();
        $admin = admin_get_root(true, true);
        $category = $admin->locate('local_ai_course_assistant');
        $this->assertNotEmpty($category,
            'The plugin settings category must be reachable, or every assertion below is vacuous.');

        $pages = [];
        foreach ($category->children as $child) {
            if ($child instanceof \admin_settingpage) {
                $pages[$child->name] = $child;
            }
        }
        $this->assertNotEmpty($pages, 'no settings pages found');
        return $pages;
    }

    /**
     * No page may exceed core's js_call_amd argument budget.
     *
     * This is the regression guard for issue #292. core/showhidesettings is
     * handed one page's entire dependency map as a single argument, and
     * page_requirements_manager warns above 1,024 characters. At DEVELOPER
     * debug that warning is an exception on a strict error handler, so the
     * settings category page returned HTTP 500, and Moodle's own plugin
     * reviewers run at that debug level.
     *
     * The ceiling here is deliberately below core's, because the fix left the
     * worst page at 987 of 1,024 and a single new dependent would have put it
     * back over with nothing to catch it until someone opened the page.
     */
    public function test_no_settings_page_exceeds_the_dependency_budget(): void {
        // settings_pages() calls setAdminUser(), and phpunit fails the test with
        // "unexpected change of $USER" unless the change is declared resettable.
        $this->resetAfterTest();

        $ceiling = 950;
        $over = [];

        foreach ($this->settings_pages() as $name => $page) {
            if (!$page->has_dependencies()) {
                continue;
            }
            $payload = json_encode(['dependencies' => $page->get_dependencies_for_javascript()]);
            $len = strlen($payload);
            if ($len > $ceiling) {
                $over[] = "{$name}: {$len} chars";
            }
        }

        $this->assertSame([], $over,
            "These settings pages send more than {$ceiling} characters of show/hide data "
                . "to core/showhidesettings in one js_call_amd argument:\n  "
                . implode("\n  ", $over)
                . "\nCore warns above 1024, and at DEVELOPER debug that warning becomes an "
                . "exception, so the settings category page returns HTTP 500. Split the page, "
                . "or move a toggle and its dependents onto a page of their own. See #292.");
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
        $pages = $this->settings_pages();

        // admin_settingdependency::parse_name() normalises 'plugin/name' into
        // the form-element name ('s_plugin_name'), so compare against each
        // setting's own get_full_name() rather than re-deriving the transform.
        //
        // Issue #292 spread the settings over eleven pages, so this has to look
        // at all of them. Reading one page would have found zero dependencies
        // and reported the map lost, which is how the split first surfaced here.
        $onpage = [];
        $pairs = [];
        foreach ($pages as $page) {
            foreach ((array) $page->settings as $setting) {
                $onpage[$setting->get_full_name()] = true;
            }
            foreach ($this->dependency_pairs($page) as $pair) {
                $pairs[] = $pair;
            }
        }
        $exists = function (string $fullname) use ($onpage): bool {
            return isset($onpage[$fullname]);
        };

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
