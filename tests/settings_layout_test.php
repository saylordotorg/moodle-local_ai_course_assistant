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
     * Settings that were in tests/fixtures/settings_defaults_snapshot.json and were deleted on purpose.
     *
     * The regroup (7.10.0) renamed, removed and re-defaulted nothing. A release
     * that drops a setting lists its name here in the same commit, so the
     * removal is visible in review: prompt_budget_auto_tune went in 7.12.0.
     *
     * @var string[]
     */
    private const REMOVED_ON_PURPOSE = ['prompt_budget_auto_tune'];

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
        // A normal tree build raises no developer notice (settings_usage_report --json
        // builds the same tree, so a notice here would land in its output).
        $this->assertDebuggingNotCalled();
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
                    $onpage = isset($pages[$id]->settings->{'local_ai_course_assistant' . $name});
                    if (!$onpage && in_array($name, settings_layout_map::OPTIONAL, true)) {
                        continue; // A conditional notice this site does not meet the condition for.
                    }
                    $this->assertTrue($onpage, "{$name} is on {$short}");
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

    /**
     * Pin that no existing setting's default changed since the regroup.
     *
     * One way on purpose: every setting in the snapshot must still be registered
     * with the same default, but a setting the snapshot has never heard of is
     * allowed, so adding a setting later needs no fixture change. A setting
     * removed on purpose is named in REMOVED_ON_PURPOSE, with the release that
     * dropped it, so a deletion is a reviewed edit and never a silent one.
     */
    public function test_defaults_are_unchanged(): void {
        $snapshot = json_decode(file_get_contents(__DIR__ . '/fixtures/settings_defaults_snapshot.json'), true);
        $this->assertIsArray($snapshot);
        // The fixture itself must not be trimmed to make a missing setting go unnoticed.
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

        foreach ($snapshot as $name => $default) {
            if (in_array($name, self::REMOVED_ON_PURPOSE, true)) {
                continue;
            }
            $this->assertArrayHasKey($name, $live, "{$name} was in the snapshot and is no longer registered "
                . '(list it in REMOVED_ON_PURPOSE if that is intended)');
            $this->assertSame($default, $live[$name], "default of {$name}");
        }
    }

    /**
     * A name in REMOVED_ON_PURPOSE must really be gone, or the list hides a changed default.
     *
     * test_defaults_are_unchanged skips every listed name, so a setting that was listed and then kept (or came
     * back) would escape the pin. The snapshot still holds the names, on purpose: the fixture is one-way.
     */
    public function test_a_setting_listed_as_removed_is_not_registered(): void {
        $snapshot = json_decode(file_get_contents(__DIR__ . '/fixtures/settings_defaults_snapshot.json'), true);
        $live = [];
        foreach (settings_tree::settings($this->category()) as $setting) {
            $live[$setting->name] = true;
        }
        foreach (self::REMOVED_ON_PURPOSE as $name) {
            $this->assertArrayHasKey($name, $snapshot, "{$name} is not in the snapshot, so it needs no entry here");
            $this->assertArrayNotHasKey($name, $live, "{$name} is listed as removed but is still registered");
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
            // The redirect() argument itself cannot be asserted: core's CLI redirect() throws before a target can be captured.
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

    /**
     * A section parameter that is an array is simply not a request for the page.
     *
     * Core builds the admin navigation on every admin page and calls
     * check_access() on each page it finds, so ?section[]=x reaches this class
     * on pages that never asked for it. optional_param() throws a
     * coding_exception on an array, which would turn a malformed query string
     * into an error page for the whole admin area.
     */
    public function test_an_array_section_parameter_is_not_a_request_and_does_not_throw(): void {
        $category = $this->category();
        $page = $category->locate(settings_layout::page_id('ai'));
        $this->assertInstanceOf(settings_redirect_page::class, $page);

        $cases = [
            'an array in GET' => ['get', [settings_layout::page_id('ai')]],
            'an array in POST' => ['post', [settings_layout::page_id('ai')]],
            'a nested array in GET' => ['get', ['nested' => ['x']]],
            'a nested array in POST' => ['post', ['nested' => ['x']]],
        ];
        foreach ($cases as $label => [$source, $value]) {
            try {
                unset($_GET['section'], $_POST['section']);
                if ($source === 'get') {
                    $_GET['section'] = $value;
                } else {
                    $_POST['section'] = $value;
                }
                $this->assertFalse($page->is_requested(), "{$label} is not a request");
                $this->assertTrue($page->check_access(), "{$label} neither redirects nor throws");
            } finally {
                unset($_GET['section'], $_POST['section']);
            }
        }

        // The string form still works, so the guard did not turn the check off.
        try {
            $_GET['section'] = settings_layout::page_id('ai');
            $this->assertTrue($page->is_requested());
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

    public function test_conditional_notices_are_placed_when_their_condition_is_met(): void {
        $this->resetAfterTest();
        // Both conditions met: a truncation in the last 7 days, and an automatic budget with no model window to
        // derive it from (no model name, no backend_context_tokens).
        set_config('prompt_truncation_seen', time() - HOURSECS, 'local_ai_course_assistant');
        set_config('prompt_budget_mode', 'auto', 'local_ai_course_assistant');
        set_config('model', '', 'local_ai_course_assistant');
        set_config('backend_context_tokens', 0, 'local_ai_course_assistant');
        $pages = settings_tree::pages($this->category());
        $this->assertDebuggingNotCalled();

        $report = settings_layout::last_report();
        $this->assertSame([], $report['unplaced'], 'a conditional notice was appended to adv_legacy instead of placed');
        $this->assertTrue(isset($pages[settings_layout::page_id('adv_prompt')]
            ->settings->{'local_ai_course_assistantprompt_truncation_warning'}));
        $this->assertTrue(isset($pages[settings_layout::page_id('adv_prompt')]
            ->settings->{'local_ai_course_assistantprompt_budget_nowindow_warning'}));
    }

    public function test_conditional_notices_are_left_out_quietly_when_their_condition_is_not_met(): void {
        $this->resetAfterTest();
        set_config('prompt_truncation_seen', 0, 'local_ai_course_assistant');
        set_config('prompt_budget_mode', 'fixed', 'local_ai_course_assistant');
        $pages = settings_tree::pages($this->category());
        $this->assertDebuggingNotCalled();

        $report = settings_layout::last_report();
        $this->assertSame([], $report['missing'], 'an OPTIONAL name that was not built is not reported missing');
        $this->assertSame([], $report['unplaced']);
        foreach (settings_layout_map::OPTIONAL as $name) {
            foreach ($pages as $page) {
                $this->assertFalse(isset($page->settings->{'local_ai_course_assistant' . $name}), "{$name} was not built");
            }
        }
    }

    public function test_every_literal_setting_name_in_settings_php_has_a_home(): void {
        // Reads settings.php as text, so it also sees settings built only under a
        // condition the test site does not meet. Names built in loops from a
        // variable ('local_ai_course_assistant/' . $key) are not literals and are
        // covered by the runtime tree checks above instead.
        $src = file_get_contents(__DIR__ . '/../settings.php');
        preg_match_all("~new\s+\\\\?(?:local_ai_course_assistant\\\\)?admin_setting_[a-z_]+\(\s*"
            . "'local_ai_course_assistant/([a-z0-9_]+)'~", $src, $m);
        $this->assertGreaterThan(300, count($m[1]), 'the scan pattern has drifted from settings.php');

        $homes = settings_layout_map::DROPPED;
        foreach (settings_layout_map::PAGES as $page) {
            foreach ($page['sections'] as [$kind, $heading, $names]) {
                if ($kind === 'existing') {
                    $homes[] = $heading;
                }
                $homes = array_merge($homes, $names);
            }
        }
        $homeless = array_values(array_diff($m[1], $homes));
        $this->assertSame([], $homeless, 'settings.php builds these but settings_layout_map gives them no page');
    }

    public function test_dropped_lists_only_a_heading_that_still_exists(): void {
        // The eight table-of-contents anchors were deleted in 7.12.0 together with the
        // helper that built them. provider_heading is still built by settings.php
        // and is dropped on purpose (the Models page opens on its own headings).
        $this->assertSame(['provider_heading'], settings_layout_map::DROPPED);
        $src = file_get_contents(__DIR__ . '/../settings.php');
        $this->assertStringContainsString("'local_ai_course_assistant/provider_heading'", $src);
    }

    public function test_optional_names_are_listed_in_pages(): void {
        $listed = [];
        foreach (settings_layout_map::PAGES as $page) {
            foreach ($page['sections'] as [, , $names]) {
                $listed = array_merge($listed, $names);
            }
        }
        $this->assertNotEmpty(settings_layout_map::OPTIONAL);
        foreach (settings_layout_map::OPTIONAL as $name) {
            $this->assertContains($name, $listed, "{$name} is optional, so it still needs a page");
        }
    }

    public function test_an_optional_entry_is_placed_when_built_and_not_missing_when_absent(): void {
        $this->resetAfterTest();
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $optional = 'prompt_truncation_warning';
        $this->assertContains($optional, settings_layout_map::OPTIONAL);

        $built = settings_layout::build([new \admin_settingpage('stage', 'Stage')], []);
        $this->assertNotContains($optional, $built['missing'], 'an absent optional entry is not missing');
        $this->assertContains('enabled', $built['missing'], 'an absent required entry still is');

        $stage = new \admin_settingpage('stage', 'Stage');
        $stage->add(new \admin_setting_description('local_ai_course_assistant/' . $optional, '', 'warning'));
        $built = settings_layout::build([$stage], []);
        $this->assertTrue(isset($built['pages']['adv_prompt']->settings->{'local_ai_course_assistant' . $optional}),
            'a built optional entry is placed on its page');
        $this->assertNotContains($optional, $built['unplaced']);
    }

    public function test_a_duplicate_staged_name_raises_a_developer_notice(): void {
        $this->resetAfterTest();
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $one = new \admin_settingpage('stage_one', 'Stage one');
        $one->add(new \admin_setting_configtext('local_ai_course_assistant/rag_topk', 'Top k', '', '5'));
        $two = new \admin_settingpage('stage_two', 'Stage two');
        $two->add(new \admin_setting_configtext('local_ai_course_assistant/rag_topk', 'Top k again', '', '6'));

        settings_layout::build([$one, $two], []);
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
    }

    public function test_placement_logic_with_a_fake_staging_set(): void {
        $this->resetAfterTest();
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');

        $one = new \admin_settingpage('stage_one', 'Stage one');
        $one->add(new \admin_setting_configtext('local_ai_course_assistant/rag_topk', 'Top k', '', '5'));
        $one->add(new \admin_setting_configtext('local_ai_course_assistant/fake_unmapped', 'Unmapped', '', 'x'));
        $one->add(new \admin_setting_description('local_ai_course_assistant/provider_heading', '', 'dropped'));
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
        $this->assertArrayNotHasKey('provider_heading', $where, 'a dropped entry is placed nowhere');

        // The rule whose ends share a page is attached there, and only there.
        $this->assertTrue($built['pages']['adv_retrieval']->has_dependencies());
        $this->assertFalse($built['pages']['adv_documents']->has_dependencies());
    }
}
