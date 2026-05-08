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
 * Page-grounding regression coverage (v5.3.9).
 *
 * The Non-serviam regression broke the page-grounding pipeline twice in
 * four days (Tomi @ Debrecen, May 4 and May 7). Neither time was caught by
 * automated tests. This file plants real Page activities with fingerprint
 * text and asserts the assembled system prompt picks them up — both via
 * the get_module_content() filtered-text path and via the v5.3.6 raw
 * fallback path that catches Moodle filter collapse.
 *
 * Each test uses a unique fingerprint string that has no chance of
 * appearing anywhere else in the prompt by accident, so a positive
 * assertion proves the page content actually flowed through.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\context_builder
 */
final class page_grounding_test extends \advanced_testcase {

    /**
     * Helper: plant a Page activity with the given body text and return
     * its course_modules id.
     *
     * @param int $courseid
     * @param string $name
     * @param string $body
     * @return int
     */
    private function make_page(int $courseid, string $name, string $body): int {
        $generator = $this->getDataGenerator();
        $pagegen = $generator->get_plugin_generator('mod_page');
        $page = $pagegen->create_instance([
            'course' => $courseid,
            'name' => $name,
            'content' => $body,
            'contentformat' => FORMAT_HTML,
        ]);
        $cm = get_coursemodule_from_instance('page', $page->id);
        return (int)$cm->id;
    }

    /**
     * Build a system prompt for the given user/course/page and return it.
     * Helper isolates the call so the assertions read clearly.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $pageid
     * @param string $pagetitle
     * @return string
     */
    private function build_prompt(int $courseid, int $userid, int $pageid, string $pagetitle = ''): string {
        return context_builder::build_system_prompt($courseid, $userid, '', [], $pageid, $pagetitle, '');
    }

    /**
     * The core regression. A learner is on a Page activity with
     * substantial body text. The assembled prompt MUST contain a
     * Current Page Content section AND the fingerprint text. If this
     * test ever fails, the page-grounding pipeline is broken — same
     * class of bug Tomi reported twice.
     */
    public function test_page_with_fingerprint_text_appears_in_prompt(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        // v5.3.12: setUser so get_fast_modinfo uses the enrolled student,
        // not the default test guest. Without this, modinfo iteration
        // shows zero uservisible modules and the wide dump is empty.
        $this->setUser($user);

        $fingerprint = 'XYZ123-FINGERPRINT-' . uniqid()
            . ' Professor Dobb book personetics Eino Kaikki cruellest science.';
        $body = '<p>' . $fingerprint . ' '
            . 'This page is about Stanislaw Lem story Non Serviam, in which the philosopher discusses '
            . 'the ethics of personetics, a science of creating sentient beings inside computer simulations. '
            . 'The story raises questions about creator responsibility, free will, and the moral status of '
            . 'simulated minds. Dobb is the protagonist who runs the simulations.</p>';
        $cmid = $this->make_page((int)$course->id, 'Non Serviam', $body);

        $prompt = $this->build_prompt((int)$course->id, $user->id, $cmid, 'Non Serviam');

        $this->assertStringContainsString('## Current Page Content', $prompt,
            'Current Page Content section heading must appear when a Page cmid is supplied.');
        $this->assertStringContainsString($fingerprint, $prompt,
            'Page body fingerprint must appear in the prompt — if missing, '
            . 'get_module_content() returned empty for this Page activity.');
        $this->assertStringContainsString('Non Serviam', $prompt,
            'Page title should appear in the Current Page Content section.');
        $this->assertStringContainsString('Page-grounded answer required', $prompt,
            'The page-grounded directive must accompany the page content.');
    }

    /**
     * v5.3.6 changed the get_module_content threshold from 80 to 30 and
     * added a raw-content fallback for filter collapse. This test
     * exercises a page whose body text after format_text+strip_tags is
     * still substantial — the filtered path should win, not the fallback.
     */
    public function test_page_just_above_30_char_floor_appears_in_prompt(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        // v5.3.12: setUser so get_fast_modinfo uses the enrolled student,
        // not the default test guest. Without this, modinfo iteration
        // shows zero uservisible modules and the wide dump is empty.
        $this->setUser($user);

        // 35 chars after stripping tags — well above the 30 floor.
        $fingerprint = 'tinyfp-' . uniqid() . '-page';
        $body = '<p>' . $fingerprint . ' content</p>';
        $cmid = $this->make_page((int)$course->id, 'Tiny Page', $body);

        $prompt = $this->build_prompt((int)$course->id, $user->id, $cmid, 'Tiny Page');

        $this->assertStringContainsString($fingerprint, $prompt);
    }

    /**
     * Pages under the 30-char floor produce no Current Page Content
     * section — but should still appear in the wide course-content dump
     * because v5.3.6 also preempts the supplied cmid in modinfo
     * iteration. Ensures the wide dump still surfaces the page even when
     * extraction drops it.
     */
    public function test_short_page_falls_back_to_wide_dump_with_cmid_preempt(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        // v5.3.11: enrol the user so build_course_content's $cm->uservisible
        // returns true. Without enrolment the wide dump iterates zero
        // modules even though the rows exist in the DB.
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        // v5.3.12: setUser so get_fast_modinfo uses the enrolled student,
        // not the default test guest. Without this, modinfo iteration
        // shows zero uservisible modules and the wide dump is empty.
        $this->setUser($user);

        // Add several other pages first so the cmid we test against is
        // NOT first in modinfo natural order.
        for ($i = 0; $i < 3; $i++) {
            $this->make_page((int)$course->id, "Filler page {$i}",
                '<p>Filler content for page ' . $i . ' to push the test target back in modinfo order.</p>');
        }
        // Now plant the target — short content (under 30 chars stripped),
        // but with a unique fingerprint we can recognise.
        $fingerprint = 'shortfp-' . uniqid();
        $shortbody = '<p>' . $fingerprint . '</p>';
        $cmid = $this->make_page((int)$course->id, 'Target Page', $shortbody);

        $prompt = $this->build_prompt((int)$course->id, $user->id, $cmid, 'Target Page');

        // Even though the page itself was too short for the page-content
        // section, the cmid preempt should put it at the top of the wide
        // dump, so the fingerprint still appears somewhere in the prompt.
        $this->assertStringContainsString($fingerprint, $prompt,
            'cmid preempt must surface even short pages in the wide dump.');
    }

    /**
     * When the current page IS firing with substantial content, the
     * wide dump's full course-content stuffing is replaced with a short
     * pointer so the page text wins the prompt budget. This was the
     * v5.3.6 fix that prevented the "first 3 pages of the course" symptom.
     */
    public function test_wide_dump_replaced_with_pointer_when_page_content_fires(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        // v5.3.12: setUser so get_fast_modinfo uses the enrolled student,
        // not the default test guest. Without this, modinfo iteration
        // shows zero uservisible modules and the wide dump is empty.
        $this->setUser($user);

        // Plant a few other pages we DO NOT want to see in the prompt.
        $unwanted1 = 'UNWANTED-' . uniqid();
        $unwanted2 = 'ALSO-UNWANTED-' . uniqid();
        $this->make_page((int)$course->id, 'Other 1', '<p>' . $unwanted1
            . ' content of an unrelated page that should NOT appear in the prompt because the '
            . 'learner is reading a different page right now.</p>');
        $this->make_page((int)$course->id, 'Other 2', '<p>' . $unwanted2
            . ' more unrelated content that should NOT appear when the current page anchor is firing.</p>');

        // Plant the target with substantial content (>500 chars to trigger skip).
        $targetfp = 'TARGET-' . uniqid();
        $targetbody = '<p>' . $targetfp . ' '
            . str_repeat('This is the page the learner is reading. ', 30)
            . '</p>';
        $cmid = $this->make_page((int)$course->id, 'Target', $targetbody);

        $prompt = $this->build_prompt((int)$course->id, $user->id, $cmid, 'Target');

        $this->assertStringContainsString($targetfp, $prompt,
            'Target page fingerprint must appear (Current Page Content section).');
        $this->assertStringNotContainsString($unwanted1, $prompt,
            'Wide dump must be SKIPPED when current page content is firing — '
            . 'unrelated page 1 must not appear in the prompt.');
        $this->assertStringNotContainsString($unwanted2, $prompt,
            'Wide dump must be SKIPPED — unrelated page 2 must not appear.');
        // The pointer text replacing the wide dump should be present.
        $this->assertStringContainsString('the **Current Page Content** section above', $prompt,
            'When wide dump is skipped, the pointer note must replace it.');
    }

    /**
     * Defensive: when no pageid is supplied (e.g. learner is on a course
     * landing or dashboard), the wide course-content dump still runs.
     */
    public function test_no_page_anchor_keeps_wide_dump_active(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        // v5.3.11: enrol so wide dump iterates uservisible cms.
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        // v5.3.12: setUser so get_fast_modinfo uses the enrolled student,
        // not the default test guest. Without this, modinfo iteration
        // shows zero uservisible modules and the wide dump is empty.
        $this->setUser($user);

        $fp = 'COURSE-CONTENT-' . uniqid();
        $this->make_page((int)$course->id, 'Page 1', '<p>' . $fp
            . ' content from page 1 of the course.</p>');

        // Build prompt without a pageid (course-landing scenario).
        $prompt = $this->build_prompt((int)$course->id, $user->id, 0, '');

        $this->assertStringContainsString($fp, $prompt,
            'Without a page anchor, the wide course-content dump must still run.');
        $this->assertStringNotContainsString('## Current Page Content', $prompt,
            'No pageid means no Current Page Content section.');
    }

    /**
     * Regression guard: get_module_content with a cmid that does not
     * exist must not throw — it must return empty string. The old code
     * used MUST_EXIST and a missing cmid would throw dml_missing_record.
     */
    public function test_get_module_content_for_unknown_cmid_returns_empty(): void {
        $this->resetAfterTest();
        $this->assertSame('', context_builder::get_module_content(999999, 1000));
    }

    /**
     * The fingerprint section heading text must NEVER appear without an
     * actual page section under it — a learner reading a model reply
     * should never see a Current Page Content stub with no body.
     */
    public function test_no_orphan_current_page_heading_when_extraction_fails(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        // No page exists at this cmid. Build prompt with a dangling pageid.
        $prompt = $this->build_prompt((int)$course->id, $user->id, 999999, 'Ghost');

        $this->assertStringNotContainsString('## Current Page Content', $prompt,
            'When no page content can be extracted, the Current Page Content '
            . 'heading must NOT appear (no orphan heading with empty body).');
    }
}
