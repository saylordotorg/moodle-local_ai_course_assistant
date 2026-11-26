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
 * Saylor's values are not other sites' defaults (8.4.1, Marketplace review).
 *
 * A site that is not Saylor's gets neutral defaults and never shows "Saylor"; Saylor's own sites keep their
 * values through the 8.4.1 upgrade step; and no shipped code carries a Saylor value as a default.
 *
 * @package    local_ai_course_assistant
 * @category   test
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\site_defaults
 * @covers     \local_ai_course_assistant\branding
 */
final class site_defaults_test extends \advanced_testcase {

    /** @var string */
    private const C = 'local_ai_course_assistant';

    /**
     * Store every setting the way an 8.4.0 site carried it: the old Saylor default.
     */
    private function store_old_defaults(): void {
        foreach (site_defaults::SAYLOR_VALUES as $name => $value) {
            set_config($name, $value, self::C);
        }
        // contact, DPO, privacy URL and transcript pattern shipped blank; blank meant Saylor at runtime.
        foreach (['contact_email', 'dpo_email', 'privacy_external_url', 'rag_transcript_url_pattern'] as $name) {
            set_config($name, '', self::C);
        }
    }

    /**
     * The branding values a learner or teacher can see.
     *
     * @return string[]
     */
    private function visible_branding(): array {
        return [branding::display_name(), branding::short_name(), branding::institution_name(),
            branding::institution_short_name(), branding::contact_email(), branding::dpo_email(),
            branding::privacy_external_url()];
    }

    public function test_the_saylor_hosts(): void {
        foreach (['https://learn.saylor.org', 'https://degrees.saylor.org', 'https://dev.sylr.org',
                'https://alpha.sylr.org/moodle', 'http://saylor.org'] as $url) {
            $this->assertTrue(site_defaults::is_saylor_host($url), $url);
        }
        foreach (['https://www.example.com/moodle', 'https://notsaylor.org', 'https://saylor.org.example.com',
                'https://sylr.org.evil.test', 'https://mysylr.org'] as $url) {
            $this->assertFalse(site_defaults::is_saylor_host($url), $url);
        }
    }

    public function test_a_site_that_is_not_saylors_gets_neutral_values_and_no_saylor_string(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->supportemail = 'help@example.edu';
        $this->store_old_defaults();
        set_config('display_name', 'My own name', self::C);

        $moved = site_defaults::migrate('https://www.example.com/moodle');

        // Only the old defaults moved; the name an admin chose stayed.
        $this->assertArrayNotHasKey('display_name', $moved);
        $this->assertSame('My own name', get_config(self::C, 'display_name'));
        foreach (['institution_name', 'institution_short_name', 'footer_courses_text', 'footer_courses_url',
                'external_resources_allowlist'] as $name) {
            $this->assertSame('neutral', $moved[$name] ?? null, $name);
        }
        foreach (array_keys(site_defaults::SAYLOR_VALUES) as $name) {
            $this->assertStringNotContainsStringIgnoringCase('saylor', (string) get_config(self::C, $name), $name);
        }
        set_config('display_name', '', self::C);
        foreach ($this->visible_branding() as $value) {
            $this->assertStringNotContainsStringIgnoringCase('saylor', $value);
        }
        $this->assertSame('help@example.edu', branding::contact_email());
        $this->assertSame(format_string(get_site()->fullname), branding::institution_name());

        // A second run changes nothing.
        $this->assertSame([], site_defaults::migrate('https://www.example.com/moodle'));
    }

    public function test_the_widget_on_a_site_that_is_not_saylors_never_says_saylor(): void {
        global $OUTPUT, $PAGE;
        $this->resetAfterTest();
        $this->store_old_defaults();
        site_defaults::migrate('https://www.example.com/moodle');
        set_config('enabled', '1', self::C);
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Neutral course']);
        set_config('sola_enabled_course_' . $course->id, '1', self::C);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        $PAGE = new \moodle_page();
        $PAGE->set_course($course);
        $PAGE->set_context(\context_course::instance($course->id));
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));
        $PAGE->set_pagetype('course-view-topics');
        $PAGE->set_state(\moodle_page::STATE_PRINTING_HEADER);
        $PAGE->set_state(\moodle_page::STATE_IN_BODY);
        $OUTPUT = new \core_renderer($PAGE, RENDERER_TARGET_GENERAL);
        $hook = new \core\hook\output\before_footer_html_generation($OUTPUT);
        hook_callbacks::inject_chat_widget($hook);
        $html = $hook->get_output();

        $this->assertStringContainsString('local-ai-course-assistant', $html);
        $this->assertStringNotContainsStringIgnoringCase('saylor', $html);
    }

    public function test_a_saylor_site_keeps_its_values_after_the_upgrade(): void {
        $this->resetAfterTest();
        foreach (array_keys(site_defaults::SAYLOR_VALUES) as $name) {
            unset_config($name, self::C);
        }
        // Blank where blank used to mean the Saylor value; a chosen value stays.
        set_config('contact_email', '', self::C);
        set_config('footer_courses_text', '', self::C);
        set_config('institution_short_name', 'SU', self::C);

        $moved = site_defaults::migrate('https://learn.saylor.org');

        $this->assertSame('saylor', $moved['contact_email']);
        $this->assertArrayNotHasKey('footer_courses_text', $moved, 'a cleared footer link stays hidden');
        $this->assertArrayNotHasKey('institution_short_name', $moved);
        $this->assertSame('SU', branding::institution_short_name());
        $this->assertSame('Saylor University', branding::institution_name());
        $this->assertSame('Saylor Online Learning Assistant', branding::display_name());
        $this->assertSame('contact@saylor.org', branding::contact_email());
        $this->assertSame('dpo@saylor.org', branding::dpo_email());
        $this->assertSame('https://www.saylor.org/privacypolicy/', branding::privacy_external_url());
        $this->assertSame(site_defaults::SAYLOR_VALUES['rag_transcript_url_pattern'],
            get_config(self::C, 'rag_transcript_url_pattern'));
        $this->assertSame(site_defaults::SAYLOR_VALUES['footer_courses_url'], get_config(self::C, 'footer_courses_url'));
        $this->assertSame(site_defaults::SAYLOR_VALUES['external_resources_allowlist'],
            get_config(self::C, 'external_resources_allowlist'));

        // An 8.4.0 Saylor site that stored the old defaults is left exactly as it is.
        $this->store_old_defaults();
        set_config('contact_email', 'contact@saylor.org', self::C);
        $this->assertSame(['dpo_email' => 'saylor', 'privacy_external_url' => 'saylor',
            'rag_transcript_url_pattern' => 'saylor'], site_defaults::migrate('https://dev.sylr.org'));
        $this->assertSame([], site_defaults::migrate('https://dev.sylr.org'));
    }

    public function test_a_blank_transcript_pattern_pairs_nothing(): void {
        $this->resetAfterTest();
        set_config('rag_transcript_url_pattern', '', self::C);
        $method = new \ReflectionMethod(content_extractor::class, 'get_transcript_pattern');
        $this->assertSame('', $method->invoke(null));
    }

    /**
     * No shipped code applies a Saylor value as a default: the only PHP string literals naming Saylor's sites or
     * names are site_defaults' own table, the historic remote-config URL the 7.6.0 upgrade step recognises, the
     * upgrade's own report and one lang string id, and the browser code has none outside comments.
     */
    public function test_no_code_carries_a_saylor_default(): void {
        global $CFG;
        $root = $CFG->dirroot . '/local/ai_course_assistant';
        // site_defaults holds the old values on purpose; remote_config_manager keeps the historic URL its upgrade
        // step recognises; db/upgrade.php only reports what the 8.4.1 step did.
        $allowed = ['classes/site_defaults.php', 'classes/remote_config_manager.php', 'db/upgrade.php'];
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel = substr($file->getPathname(), strlen($root) + 1);
            if (preg_match('#^(tests|lang|docs|\.drafts|\.claude|\.wiki|cdn|services|scripts|vendor|amd/build|node_modules|\.git)/#', $rel)
                    || in_array($rel, $allowed, true)) {
                continue;
            }
            $ext = $file->getExtension();
            if ($ext === 'php') {
                foreach (token_get_all(file_get_contents($file->getPathname())) as $tok) {
                    if (is_array($tok) && in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                            && stripos($tok[1], 'saylor') !== false
                            && $tok[1] !== "'settings:avatar_saylor'") {
                        $hits[] = $rel . ':' . $tok[2] . ' ' . $tok[1];
                    }
                }
            } else if ($ext === 'js' || $ext === 'mustache') {
                $code = file_get_contents($file->getPathname());
                $code = preg_replace('#/\*.*?\*/|\{\{!.*?\}\}#s', '', $code);
                foreach (explode("\n", $code) as $n => $line) {
                    $line = preg_replace('#(^|\s)//.*$#', '', $line);
                    if (stripos($line, 'saylor') !== false) {
                        $hits[] = $rel . ':' . ($n + 1) . ' ' . trim($line);
                    }
                }
            }
        }
        $this->assertSame([], $hits, "Saylor values in shipped code:\n" . implode("\n", $hits));
    }
}
