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

use local_ai_course_assistant\tests\settings_tree;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/settings_tree.php');

/**
 * The settings menu layout (7.10.0): five task pages and an Advanced group.
 *
 * settings.php builds every setting onto staging pages, and settings_layout
 * moves each one onto the page settings_layout_map names. These tests pin
 * that nothing is lost or duplicated on the way, that every show/hide rule
 * survives the move, that no default changes, and that the day-to-day pages
 * cannot quietly grow.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\settings_layout
 */
final class settings_layout_test extends \advanced_testcase {
    /** @var string[] The five task pages, in menu order. */
    private const TASK_PAGES = ['essentials', 'models', 'features', 'safety', 'cost'];

    /** @var string[] The five Advanced pages, in menu order. */
    private const ADVANCED_PAGES = ['adv_retrieval', 'adv_documents', 'adv_prompt', 'adv_integrations', 'adv_legacy'];

    /**
     * Rebuild the real admin tree as an administrator and return the plugin category.
     *
     * @return \admin_category
     */
    private function category(): \admin_category {
        $this->resetAfterTest();
        $this->setAdminUser();
        return settings_tree::category();
    }

    /**
     * Whether a setting stores a value (headings and descriptions do not).
     *
     * @param \admin_setting $setting
     * @return bool
     */
    private function is_value_setting(\admin_setting $setting): bool {
        return !$setting instanceof \admin_setting_heading && !$setting instanceof \admin_setting_description;
    }

    public function test_every_staged_setting_has_a_home(): void {
        $this->category();
        $report = settings_layout::last_report();
        $this->assertNotNull($report, 'settings.php did not run the layout engine');
        $this->assertSame([], $report['unplaced'], 'settings with no home in settings_layout_map (appended to adv_legacy)');
        $this->assertSame([], $report['missing'], 'names settings_layout_map lists that settings.php never builds');
    }

    public function test_no_dependency_rule_is_skipped(): void {
        $this->category();
        $report = settings_layout::last_report();
        $this->assertNotNull($report, 'settings.php did not run the layout engine');
        $this->assertSame([], $report['skipped'],
            'show/hide rules whose two ends ended up on different pages, so they would never fire');
    }

    public function test_pages_exist_in_the_expected_places(): void {
        $category = $this->category();

        $direct = [];
        $advanced = null;
        foreach ($category->children as $child) {
            if ($child instanceof settings_redirect_page) {
                continue; // The hidden pages old bookmarks land on; the redirect tests own them.
            }
            if ($child instanceof \admin_settingpage) {
                $direct[] = $child->name;
            } else if ($child instanceof \admin_category) {
                $this->assertSame(settings_layout::page_id('advanced'), $child->name, 'the only nested category');
                $advanced = $child;
            }
        }
        $this->assertSame(array_map([settings_layout::class, 'page_id'], self::TASK_PAGES), $direct,
            'the five task pages sit directly under the plugin category, in order');
        $this->assertSame('local_ai_course_assistant_advanced', settings_layout::page_id('advanced'));

        $this->assertNotNull($advanced, 'the Advanced category is a child of the plugin category');
        $inner = [];
        foreach ($advanced->children as $child) {
            $this->assertInstanceOf(\admin_settingpage::class, $child);
            $inner[] = $child->name;
        }
        $this->assertSame(array_map([settings_layout::class, 'page_id'], self::ADVANCED_PAGES), $inner);
    }

    public function test_each_map_entry_lands_on_its_page(): void {
        $category = $this->category();
        $pages = settings_tree::pages($category);

        foreach (settings_layout_map::PAGES as $short => $page) {
            $id = settings_layout::page_id($short);
            $this->assertArrayHasKey($id, $pages, "page {$short} is registered");

            // The page holds exactly what the map lists, in the listed order:
            // the navigation notice, then each section's heading and settings.
            $expected = ['nav_' . $short];
            foreach ($page['sections'] as [$kind, $heading, $names]) {
                if ($kind === 'existing') {
                    $expected[] = $heading;
                } else if ($kind === 'new') {
                    $expected[] = 'layout_' . $heading . '_heading';
                }
                foreach ($names as $name) {
                    $this->assertTrue(isset($pages[$id]->settings->{'local_ai_course_assistant' . $name}),
                        "{$name} is on {$short}");
                    $expected[] = $name;
                }
            }
            $actual = array_map(fn($s) => $s->name, array_values((array) $pages[$id]->settings));
            $this->assertSame($expected, $actual, "{$short} holds what the map lists, in order");
        }

        // A dropped entry renders nowhere.
        foreach (settings_tree::settings($category) as $setting) {
            $this->assertNotContains($setting->name, settings_layout_map::DROPPED, 'dropped entries no longer render');
        }
    }

    public function test_new_headings_have_strings(): void {
        $sm = get_string_manager();
        $component = 'local_ai_course_assistant';
        foreach (settings_layout_map::PAGES as $short => $page) {
            $this->assertTrue($sm->string_exists('settingspage:' . $short, $component), "settingspage:{$short}");
            $this->assertTrue($sm->string_exists('settingspage:' . $short . '_intro', $component),
                "settingspage:{$short}_intro");
            foreach ($page['sections'] as [$kind, $key]) {
                if ($kind === 'new') {
                    $this->assertTrue($sm->string_exists('layout:' . $key, $component), "layout:{$key}");
                }
            }
        }
        $this->assertTrue($sm->string_exists('settingspage:advanced', $component));
    }

    public function test_day_to_day_pages_stay_small(): void {
        $pages = settings_tree::pages($this->category());

        $total = 0;
        foreach (self::TASK_PAGES as $short) {
            $count = count(array_filter((array) $pages[settings_layout::page_id($short)]->settings,
                fn($s) => $this->is_value_setting($s)));
            $this->assertLessThanOrEqual(50, $count, "{$short} carries {$count} settings");
            $total += $count;
        }
        $this->assertGreaterThan(0, $total, 'counted nothing, so this guard proves nothing');
        $this->assertLessThanOrEqual(135, $total,
            "the five task pages carry {$total} settings; put new ones on an Advanced page");

        // Essentials is the first page every administrator opens: no key, token or webhook on it.
        foreach ((array) $pages[settings_layout::page_id('essentials')]->settings as $setting) {
            $this->assertNotInstanceOf(\admin_setting_encryptedpassword::class, $setting,
                "{$setting->name} is a secret field on Essentials");
            $this->assertFalse(secrets::is_secret($setting->name), "{$setting->name} is a listed secret on Essentials");
        }
    }

    public function test_defaults_are_unchanged(): void {
        $snapshot = json_decode(file_get_contents(__DIR__ . '/fixtures/settings_defaults_snapshot.json'), true);
        $this->assertIsArray($snapshot);
        $this->assertCount(289, $snapshot);

        $live = [];
        foreach (settings_tree::settings($this->category()) as $setting) {
            if (!$this->is_value_setting($setting)) {
                continue;
            }
            $this->assertArrayNotHasKey($setting->name, $live, "{$setting->name} is registered twice");
            $live[$setting->name] = $setting instanceof \admin_setting_encryptedpassword
                ? '<secret>' : $setting->get_defaultsetting();
        }
        // Through JSON, so an int default compares to the snapshot as it was written.
        $live = json_decode(json_encode($live), true);
        ksort($live);

        $this->assertSame(array_keys($snapshot), array_keys($live), 'the same settings exist as before the regroup');
        foreach ($snapshot as $name => $default) {
            $this->assertSame($default, $live[$name], "default of {$name}");
        }
    }

    public function test_navigation_rows_are_not_search_results(): void {
        $pages = settings_tree::pages($this->category());
        foreach (array_keys(settings_layout_map::PAGES) as $short) {
            $nav = $pages[settings_layout::page_id($short)]->settings->{'local_ai_course_assistant' . 'nav_' . $short};
            $this->assertInstanceOf(admin_setting_page_nav::class, $nav, "nav_{$short}");
            $this->assertStringContainsString('Features', $nav->description, 'the row does name the pages');
            $this->assertFalse($nav->is_related('features'), "nav_{$short} is not a search hit");
        }
        // A real setting on the same page is still found.
        $topk = $pages[settings_layout::page_id('adv_retrieval')]->settings->{'local_ai_course_assistantrag_topk'};
        $this->assertTrue($topk->is_related('rag_topk'));
    }

    /**
     * The plugin's redirect pages, by name, as registered in the tree.
     *
     * @param \admin_category $category The plugin category.
     * @return settings_redirect_page[] Keyed by page name.
     */
    private function redirect_pages(\admin_category $category): array {
        $found = [];
        foreach ($category->children as $child) {
            if ($child instanceof settings_redirect_page) {
                $found[$child->name] = $child;
            }
        }
        return $found;
    }

    public function test_every_old_page_name_redirects(): void {
        $category = $this->category();
        foreach (settings_layout_map::REDIRECTS as $old => $new) {
            $oldid = settings_layout::page_id($old);
            $newid = settings_layout::page_id($new);

            $redirect = $category->locate($oldid);
            $this->assertInstanceOf(settings_redirect_page::class, $redirect, "{$oldid} is registered as a redirect page");
            $this->assertSame('/admin/settings.php?section=' . $newid, $redirect->url->out_as_local_url(false),
                "{$oldid} sends the browser to {$newid}");

            $target = $category->locate($newid);
            $this->assertInstanceOf(\admin_settingpage::class, $target, "{$newid} is a settings page");
            $this->assertNotInstanceOf(settings_redirect_page::class, $target, "{$newid} is a real page, not another redirect");
        }

        // Exactly the old names in the map, nothing extra. The old safety id is also a new page id,
        // so it is a real page and has no redirect.
        $expected = array_map([settings_layout::class, 'page_id'], array_keys(settings_layout_map::REDIRECTS));
        $actual = array_keys($this->redirect_pages($category));
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->assertNotInstanceOf(settings_redirect_page::class, $category->locate(settings_layout::page_id('safety')));
    }

    public function test_a_request_for_an_old_page_is_redirected_and_no_other_request_is(): void {
        $category = $this->category();
        $page = $category->locate(settings_layout::page_id('ai'));
        $this->assertInstanceOf(settings_redirect_page::class, $page);

        try {
            // Core asks every page of a save request whether the user may write it: that must not redirect.
            unset($_GET['section'], $_POST['section']);
            $this->assertTrue($page->check_access(), 'no section requested');
            $_GET['section'] = settings_layout::page_id('models');
            $this->assertTrue($page->check_access(), 'a different section requested');

            // admin/settings.php asks the located page first. Redirecting is the whole point.
            $_GET['section'] = settings_layout::page_id('ai');
            try {
                $page->check_access();
                $this->fail('a request for the old page did not redirect');
            } catch (\moodle_exception $e) {
                // In a CLI test core's redirect() throws instead of sending a header.
                $this->assertSame('redirecterrordetected', $e->errorcode);
            }

            // Someone who may not open settings is never redirected into the page.
            $this->setUser($this->getDataGenerator()->create_user());
            $this->assertFalse($page->check_access());
        } finally {
            unset($_GET['section']);
        }
    }

    public function test_redirect_pages_are_hidden(): void {
        $category = $this->category();
        $redirects = $this->redirect_pages($category);
        $this->assertNotEmpty($redirects);

        // What core's category page lists is every child that is not hidden.
        $listed = [];
        foreach ($category->children as $child) {
            if (!$child->is_hidden()) {
                $listed[] = $child->name;
            }
        }
        $root = \admin_get_root();
        foreach ($redirects as $name => $page) {
            $this->assertTrue($page->hidden, "{$name} is hidden");
            $this->assertTrue($page->is_hidden(), "{$name} reports hidden");
            $this->assertNotContains($name, $listed, "{$name} is not listed on the plugin's page");
            $this->assertSame([], $page->search(strtolower($name)), "{$name} is not an admin search hit");
        }
        $found = $root->search('moved');
        foreach ($redirects as $name => $page) {
            $this->assertArrayNotHasKey($name, $found, "searching for its title does not find {$name}");
        }
    }

    public function test_internal_links_point_at_real_pages(): void {
        $category = $this->category();
        $files = ['audit_log.php', 'emergency_admin.php', 'rag_admin.php', 'classes/autoupgrade/page.php'];
        foreach ($files as $file) {
            $source = file_get_contents(__DIR__ . '/../' . $file);
            $this->assertNotFalse($source, $file);

            $total = preg_match_all('~\'section\'\s*=>~', $source);
            $this->assertGreaterThan(0, $total, "{$file} links to no settings page (update this test if that is on purpose)");
            $count = preg_match_all(
                '~\'section\'\s*=>\s*(?:\'([a-z0-9_]+)\'|[\\\\\w]*settings_layout::page_id\(\'(\w+)\'\))~',
                $source, $links, PREG_SET_ORDER);
            $this->assertSame($total, $count, "{$file}: a 'section' value is neither a literal id nor settings_layout::page_id()");

            foreach ($links as $link) {
                $id = ($link[1] ?? '') !== '' ? $link[1] : settings_layout::page_id($link[2]);
                $page = $category->locate($id);
                $this->assertInstanceOf(\admin_settingpage::class, $page, "{$file} links to {$id}, which is not in the tree");
                $this->assertNotInstanceOf(settings_redirect_page::class, $page,
                    "{$file} links to {$id}, an old name that only redirects");
            }
        }
    }

    public function test_placement_logic_with_a_fake_staging_set(): void {
        $this->resetAfterTest();
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');

        $one = new \admin_settingpage('stage_one', 'Stage one');
        $one->add(new \admin_setting_configtext('local_ai_course_assistant/rag_topk', 'Top k', '', '5'));
        $one->add(new \admin_setting_configtext('local_ai_course_assistant/fake_unmapped', 'Unmapped', '', 'x'));
        $one->add(new \admin_setting_description('local_ai_course_assistant/sec_ai_anchor', '', 'anchor'));
        $two = new \admin_settingpage('stage_two', 'Stage two');
        $two->add(new \admin_setting_configcheckbox('local_ai_course_assistant/rag_enabled', 'RAG', '', 1));
        $two->add(new \admin_setting_configtext('local_ai_course_assistant/rerank_model', 'Model', '', 'm'));

        $built = settings_layout::build([$one, $two], ['rag_enabled' => ['rag_topk', 'rerank_model']]);
        // One notice for the unplaced setting, one for the skipped rule.
        $this->assertDebuggingCalledCount(2);

        $this->assertSame(array_keys(settings_layout_map::PAGES), array_keys($built['pages']));
        $this->assertSame(['fake_unmapped'], $built['unplaced']);
        $this->assertSame([['rerank_model', 'rag_enabled']], $built['skipped']);
        $this->assertContains('enabled', $built['missing'], 'a mapped name with no staged object is reported');
        $this->assertNotContains('rag_topk', $built['missing']);
        $this->assertSame($built['unplaced'], settings_layout::last_report()['unplaced']);

        $where = [];
        foreach ($built['pages'] as $short => $page) {
            foreach ((array) $page->settings as $setting) {
                $where[$setting->name][] = $short;
            }
        }
        $this->assertSame(['adv_retrieval'], $where['rag_topk']);
        $this->assertSame(['adv_retrieval'], $where['rag_enabled']);
        $this->assertSame(['adv_documents'], $where['rerank_model']);
        $this->assertSame(['adv_legacy'], $where['fake_unmapped'], 'an unplaced setting is never lost');
        $this->assertArrayNotHasKey('sec_ai_anchor', $where, 'a dropped entry is placed nowhere');

        // The rule whose ends share a page is attached there, and only there.
        $this->assertTrue($built['pages']['adv_retrieval']->has_dependencies());
        $this->assertFalse($built['pages']['adv_documents']->has_dependencies());
    }
}
