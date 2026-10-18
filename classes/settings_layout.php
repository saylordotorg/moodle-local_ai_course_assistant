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
 * Builds the settings menu from settings_layout_map (7.10.0).
 *
 * settings.php builds every setting onto staging pages that are never
 * registered. build() moves each setting object onto the page the map names
 * (admin_settingpage::add() keeps no record of a setting's previous page, it
 * only stores the object under plugin . name), adds a navigation notice to
 * every page, attaches the show/hide rules, and reports anything it could
 * not place. register() puts the pages in the admin tree.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings_layout {
    /** @var string Plugin component, which is also the settings plugin name and the page id prefix. */
    private const COMPONENT = 'local_ai_course_assistant';

    /** @var string Page every unplaced setting is appended to, so none is ever lost. */
    private const FALLBACK_PAGE = 'adv_legacy';

    /** @var string[] The five task pages shown in the navigation row, in order. */
    private const NAV_PAGES = ['essentials', 'models', 'features', 'safety', 'cost'];

    /** @var array|null unplaced, missing and skipped from the most recent build(). */
    private static ?array $lastreport = null;

    /**
     * Full admin tree id of a page or category.
     *
     * @param string $short Id without the plugin prefix, e.g. 'essentials' or 'advanced'.
     * @return string
     */
    public static function page_id(string $short): string {
        return self::COMPONENT . '_' . $short;
    }

    /**
     * Move every staged setting onto its page and attach the show/hide rules.
     *
     * @param \admin_settingpage[] $staged The staging pages settings.php built, in order.
     * @param array $dependencies Toggle name => list of dependent setting names.
     * @return array{pages: array<string, \admin_settingpage>, unplaced: string[], missing: string[],
     *               skipped: array<int, array{0: string, 1: string}>}
     */
    public static function build(array $staged, array $dependencies): array {
        $index = self::index($staged);
        $pages = [];
        $missing = [];
        foreach (settings_layout_map::PAGES as $short => $layout) {
            $page = new \admin_settingpage(self::page_id($short),
                get_string('settingspage:' . $short, self::COMPONENT), 'moodle/site:config');
            $page->add(new admin_setting_page_nav(self::COMPONENT . '/nav_' . $short, '', self::nav_html($short)));
            foreach ($layout['sections'] as $section) {
                self::place_section($page, $section, $index, $missing);
            }
            $pages[$short] = $page;
        }

        $unplaced = [];
        foreach ($index as $name => $setting) {
            if (in_array($name, settings_layout_map::DROPPED, true)) {
                continue;
            }
            debugging("SOLA: the setting '{$name}' has no page in settings_layout_map, so it was put on "
                . self::FALLBACK_PAGE . '. Give it a home in classes/settings_layout_map.php.', DEBUG_DEVELOPER);
            $pages[self::FALLBACK_PAGE]->add($setting);
            $unplaced[] = $name;
        }

        $skipped = self::attach_dependencies($pages, $dependencies);
        self::$lastreport = ['unplaced' => $unplaced, 'missing' => $missing, 'skipped' => $skipped];
        return ['pages' => $pages] + self::$lastreport;
    }

    /**
     * Register the built pages: task pages first, then the Advanced category and its pages.
     *
     * The tool pages and the redirect pages are registered by the caller.
     *
     * @param \admin_root $admin The admin tree.
     * @param array $built The return value of build().
     * @return void
     */
    public static function register(\admin_root $admin, array $built): void {
        $advanced = [];
        foreach ($built['pages'] as $short => $page) {
            if (settings_layout_map::PAGES[$short]['parent'] === null) {
                $admin->add(self::COMPONENT, $page);
            } else {
                $advanced[] = $page;
            }
        }
        $admin->add(self::COMPONENT, new \admin_category(self::page_id('advanced'),
            get_string('settingspage:advanced', self::COMPONENT)));
        foreach ($advanced as $page) {
            $admin->add(self::page_id('advanced'), $page);
        }
    }

    /**
     * What the most recent build() could not place, could not find, or skipped.
     *
     * @return array{unplaced: string[], missing: string[], skipped: array}|null Null before any build().
     */
    public static function last_report(): ?array {
        return self::$lastreport;
    }

    /**
     * The navigation row for a page, followed by that page's one-sentence intro.
     *
     * @param string $currentshort Short id of the page being rendered.
     * @return string HTML.
     */
    public static function nav_html(string $currentshort): string {
        $isadvanced = (settings_layout_map::PAGES[$currentshort]['parent'] ?? null) === 'advanced';
        $firstadvanced = self::advanced_pages()[0];

        $items = [];
        foreach (self::NAV_PAGES as $short) {
            $items[] = self::nav_link($short, get_string('settingspage:' . $short, self::COMPONENT),
                $short === $currentshort ? 'page' : null);
        }
        $items[] = self::nav_link($firstadvanced, get_string('settingspage:advanced', self::COMPONENT),
            $isadvanced ? ($currentshort === $firstadvanced ? 'page' : 'true') : null);

        $html = '<nav class="sola-pagenav" aria-label="' . s(get_string('settingspage:nav_label', self::COMPONENT)) . '">'
            . '<ul class="sola-pagenav-links">' . implode('', $items) . '</ul>';
        if ($isadvanced) {
            $sub = [];
            foreach (self::advanced_pages() as $short) {
                $sub[] = self::nav_link($short, get_string('settingspage:' . $short, self::COMPONENT),
                    $short === $currentshort ? 'page' : null);
            }
            $html .= '<ul class="sola-pagenav-links sola-pagenav-sub">' . implode('', $sub) . '</ul>';
        }
        return $html . '</nav>'
            . '<p class="sola-pagenav-intro">' . s(branding::str('settingspage:' . $currentshort . '_intro')) . '</p>';
    }

    /**
     * One link of the navigation row.
     *
     * @param string $short Short id of the page linked to.
     * @param string $label Visible label (unescaped).
     * @param string|null $current aria-current value ('page' or 'true'), or null when not current.
     * @return string HTML list item.
     */
    private static function nav_link(string $short, string $label, ?string $current): string {
        $url = new \moodle_url('/admin/settings.php', ['section' => self::page_id($short)]);
        $attrs = $current === null ? '' : ' class="sola-pagenav-current" aria-current="' . $current . '"';
        return '<li><a href="' . s($url->out(false)) . '"' . $attrs . '>' . s($label) . '</a></li>';
    }

    /**
     * Short ids of the pages inside the Advanced category, in order.
     *
     * @return string[]
     */
    private static function advanced_pages(): array {
        return array_keys(array_filter(settings_layout_map::PAGES, fn($p) => $p['parent'] === 'advanced'));
    }

    /**
     * Index every staged setting object by its bare name, in staging order.
     *
     * @param \admin_settingpage[] $staged
     * @return array<string, \admin_setting>
     */
    private static function index(array $staged): array {
        $index = [];
        foreach ($staged as $page) {
            foreach ((array) $page->settings as $setting) {
                $index[$setting->name] = $setting;
            }
        }
        return $index;
    }

    /**
     * Add one section of the map to a page: its heading, then its settings in order.
     *
     * A placed object is removed from the index, so whatever is left at the
     * end is unplaced, and a name listed twice is reported missing the second time.
     *
     * @param \admin_settingpage $page Page being built.
     * @param array $section [kind, heading name or key, setting names].
     * @param array $index Bare name => staged setting, consumed in place.
     * @param string[] $missing Names the map lists that no staging page holds, appended in place.
     * @return void
     */
    private static function place_section(\admin_settingpage $page, array $section, array &$index,
            array &$missing): void {
        [$kind, $heading, $names] = $section;
        if ($kind === 'new') {
            $desckey = 'layout:' . $heading . '_desc';
            $page->add(new \admin_setting_heading(self::COMPONENT . '/layout_' . $heading . '_heading',
                get_string('layout:' . $heading, self::COMPONENT),
                get_string_manager()->string_exists($desckey, self::COMPONENT) ? get_string($desckey, self::COMPONENT) : ''));
        } else if ($kind === 'existing') {
            $names = array_merge([$heading], $names);
        }
        foreach ($names as $name) {
            if (!isset($index[$name])) {
                $missing[] = $name;
                continue;
            }
            $page->add($index[$name]);
            unset($index[$name]);
        }
    }

    /**
     * Attach each show/hide rule to the page that holds both of its ends.
     *
     * Core's show/hide only works within one page, so a rule whose ends are on
     * different pages (or missing) is skipped and reported rather than emitted
     * as a rule that would never fire.
     *
     * @param array<string, \admin_settingpage> $pages Built pages.
     * @param array $dependencies Toggle name => list of dependent setting names.
     * @return array<int, array{0: string, 1: string}> Skipped [dependent, toggle] pairs.
     */
    private static function attach_dependencies(array $pages, array $dependencies): array {
        $owner = [];
        foreach ($pages as $short => $page) {
            foreach ((array) $page->settings as $setting) {
                $owner[$setting->name] = $short;
            }
        }
        $skipped = [];
        foreach ($dependencies as $toggle => $dependents) {
            foreach ($dependents as $dependent) {
                $page = $owner[$toggle] ?? null;
                if ($page === null || ($owner[$dependent] ?? null) !== $page) {
                    debugging("SOLA: hide_if skipped, '{$dependent}' and its toggle '{$toggle}' are not on the "
                        . 'same settings page, so the rule could never fire. Move them together in '
                        . 'classes/settings_layout_map.php. See issue #292.', DEBUG_DEVELOPER);
                    $skipped[] = [$dependent, $toggle];
                    continue;
                }
                $pages[$page]->hide_if(self::COMPONENT . '/' . $dependent, self::COMPONENT . '/' . $toggle, 'notchecked');
            }
        }
        return $skipped;
    }
}
