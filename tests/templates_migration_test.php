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
 * CONTRIB-10574 #273: the pages it names stay on Templates, Output API and AMD.
 *
 * The plugin-directory review listed eight places that echoed markup with
 * inline script, inline style attributes or an inline event handler. Six were
 * migrated in v7.6.3; sandbox.php and the settings.php reset button were the
 * last two. This pins all eight, so a later edit cannot quietly reintroduce
 * the pattern the review flagged.
 *
 * The reset button also carried a real defect: it restored the default prompt
 * with atob(), which decodes to Latin-1 rather than UTF-8, so in 37 of the 46
 * locales every non-ASCII character came back as mojibake.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\admin_setting_systemprompt
 */
final class templates_migration_test extends \advanced_testcase {

    /** @var string[] The pages #273 named. */
    private const PAGES = [
        'course_settings.php', 'essay_feedback.php', 'rubric_admin.php', 'survey_admin.php',
        'usertesting_admin.php', 'soapbox.php', 'sandbox.php', 'settings.php',
    ];

    /**
     * None of the named pages echoes inline script, style or an event handler.
     */
    public function test_no_named_page_carries_inline_script_style_or_handlers(): void {
        $problems = [];
        foreach (self::PAGES as $page) {
            $src = file_get_contents(__DIR__ . '/../' . $page);
            $this->assertNotFalse($src, "{$page} must be readable");
            foreach (['<script' => 'inline <script>', 'style="' => 'inline style attribute',
                    'onclick=' => 'inline onclick handler'] as $needle => $what) {
                if (stripos($src, $needle) !== false) {
                    $problems[] = "{$page}: {$what}";
                }
            }
        }
        $this->assertSame([], $problems,
            "CONTRIB-10574 #273 regressed:\n  " . implode("\n  ", $problems));
    }

    /**
     * Rendering the prompt field asks for the reset button's module.
     *
     * It is requested from output_html() so that it loads only where the field
     * is shown, rather than on every admin page that builds the tree.
     */
    public function test_rendering_the_prompt_field_loads_its_module(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/admin/settings.php');
        $PAGE->set_context(\context_system::instance());

        $setting = new admin_setting_systemprompt('local_ai_course_assistant/systemprompt',
            'label', 'desc', '', 'the template', 'Reset');
        $setting->output_html('');

        $this->assertStringContainsString('local_ai_course_assistant/reset_prompt',
            $PAGE->requires->get_end_code());
    }

    /**
     * With nothing to reset to there is no button, and so no module either.
     */
    public function test_no_reset_value_means_no_button_and_no_module(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/admin/settings.php');
        $PAGE->set_context(\context_system::instance());

        $setting = new admin_setting_systemprompt('local_ai_course_assistant/systemprompt',
            'label', 'desc', '');
        $html = $setting->output_html('');

        $this->assertStringNotContainsString('sola-reset-prompt', $html);
        $this->assertStringNotContainsString('local_ai_course_assistant/reset_prompt',
            $PAGE->requires->get_end_code());
    }

    /**
     * The reset button, as Moodle actually renders it, carries the default exactly.
     *
     * The first version of this change put the button in the setting's
     * description and this test read $setting->description directly. It passed,
     * and the page was broken: format_admin_setting() runs every description
     * through markdown_to_html(), which rewrote the default prompt's "## "
     * headings, "- " bullets and blank lines into <h2>, <ul> and <p> INSIDE the
     * data-default attribute, so Reset saved HTML as the prompt. The PR review
     * caught it. So this renders through output_html(), the real path, and
     * decodes what the browser would read.
     */
    public function test_the_rendered_reset_button_carries_the_default_exactly(): void {
        global $CFG, $PAGE;
        // admin_get_root() lives in adminlib, which phpunit does not load. Load it
        // here rather than relying on an earlier test having pulled it in.
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/admin/settings.php');
        $PAGE->set_context(\context_system::instance());

        // locate() finds pages, not settings, and the settings are split across
        // several pages (#292), so search every page this plugin registers
        // rather than hard-coding which one holds the prompt today.
        $root = \admin_get_root(true, true);
        preg_match_all("/new admin_settingpage\\(\\s*'(local_ai_course_assistant_[a-z_]+)'/",
            file_get_contents(__DIR__ . '/../settings.php'), $pages);
        $this->assertNotEmpty($pages[1], 'sanity: settings.php should register pages');
        $setting = null;
        foreach ($pages[1] as $name) {
            $page = $root->locate($name);
            // A page keys its settings by plugin and name run together.
            if ($page && isset($page->settings->local_ai_course_assistantsystemprompt)) {
                $setting = $page->settings->local_ai_course_assistantsystemprompt;
                break;
            }
        }
        $this->assertInstanceOf(admin_setting_systemprompt::class, $setting);
        $this->assertStringNotContainsString('data-default', $setting->description,
            'the button must not be in the description, which Moodle runs through Markdown');

        // Every data-default on the rendered field, not just the first: a second
        // button smuggled in through the Markdown-processed description would
        // otherwise hide behind a correct first one. (A mutation doing exactly
        // that survived the first version of this assertion.)
        $html = $setting->output_html('');
        $this->assertSame(1, preg_match_all('/data-default="([^"]*)"/', $html, $m),
            'the rendered field should carry exactly one reset button');
        $default = get_string('settings:systemprompt_default', 'local_ai_course_assistant');
        $this->assertSame($default, html_entity_decode($m[1][0], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'the default prompt was altered on its way into the page');
        $this->assertStringNotContainsString('onclick', $html);

        // The template stays tokenised so a reset prompt follows a rebrand
        // (branding::apply() resolves it when the prompt is built); a literal
        // brand here would freeze the name into whatever an admin saves.
        $this->assertStringNotContainsString('SOLA', $default);
        $this->assertStringNotContainsString('Saylor', $default);
    }

    /**
     * Every locale's default prompt round-trips exactly through the attribute.
     *
     * The attribute path is s() on the way out and the browser's HTML decoding
     * on the way in, so this checks that pair for every shipped translation,
     * and confirms the old atob() path really did corrupt them.
     */
    public function test_every_locale_round_trips_where_atob_did_not(): void {
        $root = __DIR__ . '/../lang';
        $checked = 0;
        $garbledbyatob = 0;
        foreach (scandir($root) as $locale) {
            $file = "{$root}/{$locale}/local_ai_course_assistant.php";
            if ($locale[0] === '.' || !is_file($file)) {
                continue;
            }
            $string = [];
            include($file);
            if (!isset($string['settings:systemprompt_default'])) {
                continue;
            }
            $value = $string['settings:systemprompt_default'];
            $this->assertSame($value, html_entity_decode(s($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                "{$locale}: the default prompt did not survive the data attribute");
            // What atob() produced: each byte of the UTF-8 string read as Latin-1.
            if (mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1') !== $value) {
                $garbledbyatob++;
            }
            $checked++;
        }
        $this->assertGreaterThanOrEqual(46, $checked, 'expected to check every shipped locale');
        $this->assertGreaterThan(0, $garbledbyatob, 'sanity: the old path did corrupt some locales');
    }
}
