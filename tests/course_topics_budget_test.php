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
 * How the course-structure block spends its character budget.
 *
 * Backed by a production incident on learn.saylor.org (CS101, 8 units): asked
 * "What are the main topics this course covers?", gemini-2.5-flash, gpt-4o-mini
 * and claude-haiku-4-5 all listed Units 1-3 and stopped. Three models failing
 * identically means the prompt, not the model: the per-section activity lists
 * ate the cap and the tail of the unit list was cut off before it was sent.
 *
 * Also pins two neighbouring facts from the same capture: the course summary
 * ("Time: 26 hours", "CEUs: 2.6") never reached the prompt at all, and nothing
 * told the model that the (id:N) annotations are machine-readable only.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\context_builder::get_course_topics_text
 */
final class course_topics_budget_test extends \advanced_testcase {

    /** @var string CS101's real front matter, verbatim enough to carry the two facts learners ask for. */
    private const COURSE_SUMMARY =
        '<p>This course is an introduction to computer programming in Java. '
        . 'Time: 26 hours. CEUs: 2.6. '
        . 'You will write, compile and debug programs of increasing size.</p>';

    /** @var string[] CS101's eight units, in course order. */
    private const UNITS = [
        'Unit 1: Computer Programming',
        'Unit 2: Variables and Operators',
        'Unit 3: Input and Output',
        'Unit 4: Methods and Testing',
        'Unit 5: Conditionals and Logic',
        'Unit 6: Loops and Strings',
        'Unit 7: Arrays and References',
        'Unit 8: Recursive Methods',
    ];

    /**
     * @var string Section-name shape used by the at-scale fixtures.
     *
     * 46-47 chars, which is what a real Saylor unit title costs. At this length
     * 40 sections of names alone are 1990 bytes -- the number the regression
     * fixture in test_big_course_with_no_activities_is_no_worse_than_head
     * is calibrated against.
     */
    private const SCALE_NAME_TPL = 'Unit %d: Fundamentals of Applied Thermodynamics';

    /** @var \stdClass|null */
    private $course = null;

    /** @var int cmid of a page carrying enough text to anchor the prompt. */
    private $anchorcmid = 0;

    /** @var int[] Every cmid the fixture created, in creation order. */
    private $activitycmids = [];

    /**
     * Build a CS101-shaped course: eight named sections, six activities each,
     * a course summary carrying the hours/CEU facts.
     *
     * Activity names are deliberately as long as the real ones ("What a Computer
     * Is and Does"); that length is what makes the structure block outgrow its
     * cap on a real Saylor course.
     */
    private function build_course(bool $withactivities = true, string $summary = self::COURSE_SUMMARY): void {
        global $DB;

        $this->course = $this->getDataGenerator()->create_course([
            'fullname' => 'Introduction to Computer Science I',
            'shortname' => 'CS101T',
            'summary' => $summary,
            'summaryformat' => FORMAT_HTML,
            'numsections' => count(self::UNITS),
        ]);

        foreach (self::UNITS as $i => $name) {
            $DB->set_field('course_sections', 'name', $name,
                ['course' => $this->course->id, 'section' => $i + 1]);
        }

        if ($withactivities) {
            $generator = $this->getDataGenerator();
            foreach (self::UNITS as $i => $unit) {
                for ($a = 1; $a <= 6; $a++) {
                    $mod = $generator->create_module('page', [
                        'course' => $this->course->id,
                        'section' => $i + 1,
                        'name' => "Reading {$a}: What a Computer Is and Does, part {$a}",
                        'content' => 'Body text.',
                        'contentformat' => FORMAT_HTML,
                    ]);
                    $this->activitycmids[] = (int) $mod->cmid;
                }
            }
            // A page long enough (>= 500 chars of plain text) to make the caller
            // treat the prompt as page-anchored, which is the path that squeezes
            // the structure block down to 1500 chars.
            $anchor = $generator->create_module('page', [
                'course' => $this->course->id,
                'section' => 1,
                'name' => 'Anchor Reading',
                'content' => '<p>' . str_repeat('A computer is a machine that follows instructions. ', 20) . '</p>',
                'contentformat' => FORMAT_HTML,
            ]);
            $this->anchorcmid = (int) $anchor->cmid;
        }

        rebuild_course_cache($this->course->id, true);
    }


    /**
     * Build an N-section course with a controllable name shape.
     *
     * The CS101 fixture above is eight sections, which is far too small to
     * exercise the budget: at eight sections the name list costs ~250 chars of
     * a 1500-char cap, so nothing ever has to yield and every ordering of the
     * pricing steps looks identical. The defects this file exists to pin only
     * appear once the names alone are a serious fraction of the cap.
     *
     * @param int $sections How many named, visible sections to create.
     * @param int $actspersection Activities per section (0 for a bare course).
     * @param string $summary Raw HTML course summary.
     * @param string $nametpl sprintf template taking the 1-based section number.
     * @return string[] The section names, in course order.
     */
    private function build_scaled_course(
        int $sections,
        int $actspersection = 0,
        string $summary = '',
        string $nametpl = self::SCALE_NAME_TPL
    ): array {
        global $DB;

        $this->course = $this->getDataGenerator()->create_course([
            'fullname' => 'Scaled Fixture',
            'summary' => $summary,
            'summaryformat' => FORMAT_HTML,
            'numsections' => $sections,
        ]);

        $names = [];
        for ($i = 1; $i <= $sections; $i++) {
            $names[$i - 1] = sprintf($nametpl, $i);
            $DB->set_field('course_sections', 'name', $names[$i - 1],
                ['course' => $this->course->id, 'section' => $i]);
        }

        if ($actspersection > 0) {
            $generator = $this->getDataGenerator();
            for ($i = 1; $i <= $sections; $i++) {
                for ($a = 1; $a <= $actspersection; $a++) {
                    $mod = $generator->create_module('page', [
                        'course' => $this->course->id,
                        'section' => $i,
                        'name' => "Reading {$a}: What a Computer Is and Does, part {$a}",
                        'content' => 'Body text.',
                        'contentformat' => FORMAT_HTML,
                    ]);
                    $this->activitycmids[] = (int) $mod->cmid;
                }
            }
            $anchor = $generator->create_module('page', [
                'course' => $this->course->id,
                'section' => 1,
                'name' => 'Anchor Reading',
                'content' => '<p>' . str_repeat('A computer is a machine that follows instructions. ', 20) . '</p>',
                'contentformat' => FORMAT_HTML,
            ]);
            $this->anchorcmid = (int) $anchor->cmid;
        }

        rebuild_course_cache($this->course->id, true);
        return $names;
    }

    /**
     * Count how many of $names are absent from $haystack, and name the first
     * one that is -- a bare count makes a failure unreadable at n=60.
     *
     * @param string[] $names
     * @param string $haystack
     * @return array{0:int,1:string}
     */
    private function missing_names(array $names, string $haystack): array {
        $missing = 0;
        $first = '';
        foreach ($names as $name) {
            if (strpos($haystack, $name) === false) {
                $missing++;
                if ($first === '') {
                    $first = $name;
                }
            }
        }
        return [$missing, $first];
    }

    /**
     * D5, tight cap: every unit name survives when the caller halves the budget.
     *
     * This is the exact production shape -- learner reading a page, so the
     * structure block is thinned to 1500 chars -- and the assertion that fails
     * before the fix.
     */
    public function test_every_section_name_survives_the_page_anchored_cap(): void {
        $this->resetAfterTest();
        $this->build_course();
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $prompt = context_builder::build_system_prompt(
            (int) $this->course->id,
            (int) $student->id,
            '',
            [],
            $this->anchorcmid,
            'Anchor Reading'
        );

        foreach (self::UNITS as $unit) {
            $this->assertStringContainsString($unit, $prompt,
                "'{$unit}' was cut out of the course structure block -- this is the "
                . 'CS101 "lists Units 1-3 and stops" defect');
        }
    }

    /**
     * D5, function cap: the same guarantee at the 2500-char backstop.
     */
    public function test_every_section_name_survives_the_function_cap(): void {
        $this->resetAfterTest();
        $this->build_course();

        $topics = context_builder::get_course_topics_text((int) $this->course->id);

        foreach (self::UNITS as $unit) {
            $this->assertStringContainsString($unit, $topics,
                "'{$unit}' is missing from the course topics text at the 2500-char cap");
        }
    }

    /**
     * D5, extreme cap: names still all present when the budget is far too small
     * for any activity detail at all. Activity detail is what must yield.
     */
    public function test_section_names_outrank_activity_detail_at_a_tight_cap(): void {
        $this->resetAfterTest();
        $this->build_course();

        $topics = context_builder::get_course_topics_text((int) $this->course->id, 420);

        foreach (self::UNITS as $unit) {
            $this->assertStringContainsString($unit, $topics,
                "'{$unit}' lost its place to activity detail at a 420-char budget");
        }
        $this->assertLessThanOrEqual(420, strlen($topics),
            'the tight cap was not respected');
    }

    /**
     * D4: the course summary reaches the prompt, so "how many hours is this
     * course?" is answerable. Gemini honestly said it did not know; Claude
     * invented "around 40-50 hours" against a real answer of 26.
     */
    public function test_course_summary_facts_reach_the_structure_block(): void {
        $this->resetAfterTest();
        $this->build_course();

        $topics = context_builder::get_course_topics_text((int) $this->course->id);

        $this->assertStringContainsString('Time: 26 hours', $topics,
            'the course duration is absent -- this is the gap a fabricating model fills');
        $this->assertStringContainsString('CEUs: 2.6', $topics);
        $this->assertStringNotContainsString('<p>', $topics,
            'the course summary must be stripped of HTML before it enters the prompt');
    }

    /**
     * D4 in the assembled prompt, on the page-anchored (tight-cap) path.
     */
    public function test_course_summary_survives_the_page_anchored_cap(): void {
        $this->resetAfterTest();
        $this->build_course();
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $prompt = context_builder::build_system_prompt(
            (int) $this->course->id,
            (int) $student->id,
            '',
            [],
            $this->anchorcmid,
            'Anchor Reading'
        );

        $this->assertStringContainsString('Time: 26 hours', $prompt);
    }

    /**
     * The overall cap still holds -- the fix is to spend the budget correctly,
     * not to remove it.
     */
    public function test_output_respects_the_cap(): void {
        $this->resetAfterTest();
        $this->build_course();

        $this->assertLessThanOrEqual(2500,
            strlen(context_builder::get_course_topics_text((int) $this->course->id)),
            'the 2500-char backstop was exceeded');
        $this->assertLessThanOrEqual(1500,
            strlen(context_builder::get_course_topics_text((int) $this->course->id, 1500)),
            'the 1500-char page-anchored cap was exceeded');
    }

    /**
     * Degradation: sections but no activities at all.
     */
    public function test_course_with_no_activities_still_lists_every_section(): void {
        $this->resetAfterTest();
        $this->build_course(false);

        $topics = context_builder::get_course_topics_text((int) $this->course->id);

        foreach (self::UNITS as $unit) {
            $this->assertStringContainsString($unit, $topics);
        }
        $this->assertStringNotContainsString('Activities:', $topics,
            'an empty activity list must not emit an empty "Activities:" label');
        $this->assertStringContainsString('Time: 26 hours', $topics);
    }

    /**
     * Degradation: empty course summary. No header, no stray separator, and the
     * section list is unaffected.
     */
    public function test_empty_course_summary_degrades_to_sections_only(): void {
        $this->resetAfterTest();
        $this->build_course(true, '');

        $topics = context_builder::get_course_topics_text((int) $this->course->id);

        $this->assertStringStartsWith('- ' . self::UNITS[0], $topics,
            'with no course summary the block must start straight at the first section');
        foreach (self::UNITS as $unit) {
            $this->assertStringContainsString($unit, $topics);
        }
    }

    /**
     * Degradation: a course with neither sections nor summary.
     */
    public function test_bare_course_degrades_gracefully(): void {
        $this->resetAfterTest();
        $bare = $this->getDataGenerator()->create_course(['summary' => '', 'numsections' => 0]);

        $topics = context_builder::get_course_topics_text((int) $bare->id);

        $this->assertSame('No topics available.', trim($topics));
    }

    /**
     * D3: the (id:N) annotations must carry an explicit "never print these"
     * instruction. Observed verbatim in production: 'Review "What Is a
     * Computer?" (id:86464)' and 'take the Unit 1 Assessment (Activity ID:
     * 89206)'.
     *
     * Asserted against the real assembled prompt, and only after proving the
     * leak channel actually exists in that same prompt -- an instruction with
     * no ids to govern would be a vacuous pass.
     */
    public function test_id_annotations_carry_a_suppression_instruction(): void {
        $this->resetAfterTest();
        $this->build_course();
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $prompt = context_builder::build_system_prompt(
            (int) $this->course->id,
            (int) $student->id
        );

        // The channel must genuinely be present, or this test proves nothing.
        //
        // This keys on a cmid THIS FIXTURE created, not on the shape /\(id:\d+\)/.
        // The shape was satisfied by the instruction's own worked example
        // ("(id:89206)"), so the guard matched the very text it was meant to be
        // independent of and could never fail while the instruction existed --
        // a vacuous anti-vacuity guard.
        $realids = array_values(array_filter(
            $this->activitycmids,
            static fn(int $cmid): bool => strpos($prompt, '(id:' . $cmid . ')') !== false
        ));
        $this->assertNotEmpty(
            $realids,
            'no annotation for any cmid this fixture created reached the prompt -- '
            . 'the leak channel is absent and the rest of this test would be vacuous'
        );

        $structureat = strpos($prompt, '## Course Structure');
        $this->assertNotFalse($structureat, 'no course structure block in the prompt');

        // Scope every remaining assertion to the head of the structure block,
        // so a similarly-worded sentence somewhere else in the prompt (the
        // source-attribution or security block) cannot satisfy them.
        $block = substr($prompt, $structureat, 900);

        $this->assertMatchesRegularExpression(
            '/\(id:N\)/', $block,
            'the structure block does not name the (id:N) annotation form at all'
        );
        $this->assertMatchesRegularExpression(
            '/\(id:N\).{0,400}?(?:NEVER|[Nn]ever)\s+(?:write|include|show|print|output)/s',
            $block,
            'the course structure block does not tell the model that (id:N) is '
            . 'machine-readable only and must never be printed to the learner'
        );
        $this->assertMatchesRegularExpression(
            '/[Aa]ctivity ID/', $block,
            'the instruction should also name the "Activity ID:" phrasing the model actually emitted'
        );

        // And it must come BEFORE the listing, because prompt\builder truncates
        // sections from the tail: an instruction appended after the ids is the
        // first thing a tight budget drops.
        // This used to look for $this->anchorcmid, which round-robin activity
        // selection never reaches on an un-anchored prompt, so strpos() was
        // always false and the guarded assertion never executed at all. It now
        // uses the earliest annotation that is actually present ($realids is
        // non-empty by the assertion above), so the ordering really is checked.
        $offsets = array_map(
            static fn(int $cmid): int => (int) strpos($prompt, '(id:' . $cmid . ')'),
            $realids
        );
        $this->assertLessThan(
            min($offsets),
            strpos($prompt, '(id:N)'),
            'the suppression rule trails the id listing, so tail truncation can remove it'
        );
    }

    /**
     * B1: the course summary must yield to section names, not the reverse.
     *
     * Round 1 charged the "Course overview:" header to the budget BEFORE the
     * names, so on a real-sized course the header simply pushed the tail of the
     * unit list past the cap and the backstop cut it off. Measured on this exact
     * fixture (30 sections, 4 activities each, ~800-char summary, page-anchored
     * 1500-char path): 10 of 30 names absent, units 21-30 gone. The reported
     * defect had moved from Unit 3 to Unit 20, not been eliminated.
     *
     * The names cost 1490 of the 1500 bytes here, so the correct outcome is all
     * 30 names and no summary at all. That is the trade this block is for: the
     * map of the course is the thing a learner asks for, and a half-map is
     * worse than a map with no annotations.
     */
    public function test_the_course_summary_yields_to_section_names(): void {
        $this->resetAfterTest();
        $names = $this->build_scaled_course(30, 4, '<p>' . str_repeat('Course front matter. ', 40) . '</p>');
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $prompt = context_builder::build_system_prompt(
            (int) $this->course->id,
            (int) $student->id,
            '',
            [],
            $this->anchorcmid,
            'Anchor Reading'
        );

        [$missing, $first] = $this->missing_names($names, $prompt);
        $this->assertSame(0, $missing,
            "{$missing}/30 section names are missing from the assembled prompt, first '{$first}' -- "
            . 'the course-summary header was priced ahead of the section names and crowded them out');
    }

    /**
     * B1/(c): the "every name survives" invariant at 24 sections on the real
     * page-anchored path. Eight sections proves nothing; the names there are a
     * sixth of the cap.
     */
    public function test_every_section_name_survives_at_24_sections(): void {
        $this->resetAfterTest();
        $names = $this->build_scaled_course(24, 4, '<p>' . str_repeat('Course front matter. ', 40) . '</p>');
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $prompt = context_builder::build_system_prompt(
            (int) $this->course->id,
            (int) $student->id,
            '',
            [],
            $this->anchorcmid,
            'Anchor Reading'
        );

        [$missing, $first] = $this->missing_names($names, $prompt);
        $this->assertSame(0, $missing,
            "{$missing}/24 section names missing from the page-anchored prompt, first '{$first}'");
    }

    /**
     * B1/(c): and at 60 sections against the 2500-char backstop, with the
     * shorter unit titles that let 60 names fit at all.
     */
    public function test_every_section_name_survives_at_60_sections(): void {
        $this->resetAfterTest();
        $names = $this->build_scaled_course(
            60, 3, '<p>' . str_repeat('Course front matter. ', 40) . '</p>', 'Unit %d: Thermodynamics'
        );

        $topics = context_builder::get_course_topics_text((int) $this->course->id);

        [$missing, $first] = $this->missing_names($names, $topics);
        $this->assertSame(0, $missing,
            "{$missing}/60 section names missing at the 2500-char backstop, first '{$first}'");
        $this->assertLessThanOrEqual(2500, strlen($topics));
    }

    /**
     * B2: no shape may be worse than the code this replaces.
     *
     * A big course with NO activities is the shape the old code was already
     * good at -- it spent the whole budget on names and fit 40 of them into
     * 1990 bytes. Round 1 handed up to 622 of those bytes to an unconditional
     * "Course overview:" header and lost 3 names, which is a straight
     * regression on a shape that previously worked. Measured on this exact
     * fixture at a 2500-char budget: HEAD len=1990 missing=0/40, round 1
     * len=2500 missing=3/40.
     */
    public function test_big_course_with_no_activities_is_no_worse_than_head(): void {
        $this->resetAfterTest();
        $names = $this->build_scaled_course(40, 0, '<p>' . str_repeat('Course front matter. ', 55) . '</p>');

        $topics = context_builder::get_course_topics_text((int) $this->course->id, 2500);

        [$missing, $first] = $this->missing_names($names, $topics);
        $this->assertSame(0, $missing,
            "{$missing}/40 section names missing, first '{$first}' -- this shape lost nothing "
            . 'before the structure block grew a course-summary header');
        $this->assertLessThanOrEqual(2500, strlen($topics));
    }

    /**
     * B3: the block must be valid UTF-8. It is assembled into a JSON request
     * body, and json_encode() returns FALSE on a split multibyte character --
     * which empties the outbound body and produces a vendor 400 that names
     * nothing. This is issue #219, already fixed once in
     * prompt\builder::truncate_content(); the two byte-wise substr() cuts
     * round 1 added to this file reopened it.
     *
     * A long CJK course summary is the summary-header cut; 60 CJK section names
     * against a tight cap is the backstop cut. In a 46-language product neither
     * is an edge case.
     */
    public function test_multibyte_course_summary_survives_the_summary_cap(): void {
        $this->resetAfterTest();
        // ~1500 bytes of Japanese: comfortably past the 600-byte summary cap,
        // and every character is 3 bytes so a byte-wise cut lands mid-character.
        $this->build_scaled_course(8, 2, '<p>' . str_repeat('このコースはプログラミングの入門です。', 30) . '</p>');

        $topics = context_builder::get_course_topics_text((int) $this->course->id);

        $this->assertTrue(mb_check_encoding($topics, 'UTF-8'),
            'the course-summary cut split a multibyte character -- invalid UTF-8 in the prompt');
        $this->assertNotFalse(json_encode($topics),
            'json_encode() failed on the topics block: ' . json_last_error_msg()
            . ' -- the outbound provider body would be empty (issue #219)');
    }

    /**
     * B3, backstop cut: CJK section names, budget far too small for all of them.
     */
    public function test_multibyte_section_names_survive_the_backstop_cut(): void {
        $this->resetAfterTest();
        $this->build_scaled_course(60, 0, '', '第%d単元：応用熱力学の基礎');

        $topics = context_builder::get_course_topics_text((int) $this->course->id, 420);

        $this->assertTrue(mb_check_encoding($topics, 'UTF-8'),
            'the backstop cut left a dangling lead byte in a CJK section name');
        $this->assertNotFalse(json_encode($topics),
            'json_encode() failed on the truncated topics block: ' . json_last_error_msg());
        $this->assertLessThanOrEqual(420, strlen($topics));
    }

    /**
     * B4: truncation is line-atomic.
     *
     * A byte-wise cut of the joined list produced two distinct failures, both
     * measured: a bare "-" list item, and -- worse -- "- Unit 17: A Reasonably
     * Long Descriptive Section Name Her", a unit title that does not exist and
     * that the model will happily quote back to the learner as real. Dropping
     * a whole line loses information; half a line invents it.
     */
    public function test_backstop_truncation_never_emits_a_partial_section_name(): void {
        $this->resetAfterTest();
        // 60 long names against a 420-char budget: the names alone cannot fit,
        // so the backstop is guaranteed to bite.
        $names = $this->build_scaled_course(60, 0, '', 'Unit %d: A Reasonably Long Descriptive Section Name Here');

        $topics = context_builder::get_course_topics_text((int) $this->course->id, 420);

        $this->assertStringContainsString('[..additional sections truncated..]', $topics,
            'the fixture did not actually trigger the backstop, so this test proves nothing');

        foreach (explode("\n", $topics) as $line) {
            if (strpos($line, '- ') !== 0 && $line !== '-') {
                continue;
            }
            $this->assertNotSame('-', rtrim($line),
                'the cut emitted a bare "-" list item');
            $this->assertContains(substr($line, 2), $names,
                "the cut fabricated a section name: '" . substr($line, 2) . "' is not a real section "
                . 'in this course, and the model may quote it to the learner as one');
        }
    }

    /**
     * B5: entity-encoded markup must not come back to life.
     *
     * flatten_text() decoded entities AFTER strip_tags(), so a summary written
     * as "&lt;script&gt;" arrived in the prompt as a literal "<script>". Not
     * exploitable today -- every render path escapes correctly -- but it is
     * prompt noise the model has to reason past, and a latent hazard the moment
     * some future consumer does not escape.
     */
    public function test_entity_encoded_markup_cannot_resurrect_in_the_prompt(): void {
        $this->resetAfterTest();
        $this->build_course(true,
            '<p>Time: 26 hours. &lt;script&gt;alert(2)&lt;/script&gt; CEUs: 2.6.</p>');

        $topics = context_builder::get_course_topics_text((int) $this->course->id);

        $this->assertStringNotContainsString('<script', $topics,
            'entity-encoded markup was decoded back into a live-looking tag after strip_tags() had run');
        $this->assertStringNotContainsString('</script', $topics);
        $this->assertStringContainsString('Time: 26 hours', $topics,
            'the surrounding summary text must survive the markup sweep');
        $this->assertStringContainsString('CEUs: 2.6', $topics);
    }

    /**
     * B5, the other direction: a comparison written as an entity is prose, not
     * markup, and must not be swallowed. "if a &lt; b" is ordinary maths in a
     * course summary; a naive decode-then-strip_tags() eats everything from the
     * "<" to the next ">".
     */
    public function test_an_entity_encoded_comparison_is_not_swallowed(): void {
        $this->resetAfterTest();
        $this->build_course(true,
            '<p>Time: 26 hours. Loop while a &lt; b and b &gt; c holds. CEUs: 2.6.</p>');

        $topics = context_builder::get_course_topics_text((int) $this->course->id);

        $this->assertStringContainsString('a < b and b > c', $topics,
            'a maths comparison written with entities was stripped as if it were a tag');
    }
    /**
     * B3 (issue #219 regression): the cuts must be multi-byte safe.
     *
     * Round 1 reintroduced raw substr() at two sites -- the summary cap and
     * clamp_topics(). A cut landing inside a multi-byte character put invalid
     * UTF-8 into the assembled prompt, which json_encode() then refused, so the
     * API payload silently degraded to U+FFFD via JSON_INVALID_UTF8_SUBSTITUTE.
     * classes/prompt/builder.php names substr() as the root producer of exactly
     * this and uses \core_text::str_max_bytes() instead.
     *
     * The leading 'X' deliberately misaligns the cap so it falls mid-character.
     */
    public function test_multibyte_summary_is_not_cut_mid_character(): void {
        $this->resetAfterTest();
        $this->build_course(true, 'X' . str_repeat('このコースはプログラミングの入門です', 40));

        $topics = context_builder::get_course_topics_text((int) $this->course->id, 2500);

        $this->assertTrue(mb_check_encoding($topics, 'UTF-8'),
            'the course summary cut produced invalid UTF-8');
        $this->assertNotFalse(json_encode(['p' => $topics]),
            'the block cannot be json_encoded: ' . json_last_error_msg());
    }

    /**
     * B3, second cut site: clamp_topics() itself, reached via CJK section names
     * with no course summary in play at all.
     */
    public function test_multibyte_section_names_are_not_cut_mid_character(): void {
        $this->resetAfterTest();
        $this->build_scaled_course(60, 0, '', '第%d章：応用熱力学の基礎と伝熱工学');

        $topics = context_builder::get_course_topics_text((int) $this->course->id, 1500);

        $this->assertTrue(mb_check_encoding($topics, 'UTF-8'),
            'clamp_topics() produced invalid UTF-8');
        $this->assertNotFalse(json_encode(['p' => $topics]),
            'the block cannot be json_encoded: ' . json_last_error_msg());
    }

    /**
     * B4: when the budget genuinely cannot hold every name, what IS emitted
     * must still be true. Round 1 cut mid-line and produced
     * "- Unit 17: A Reasonably Long Descriptive Section Name Her" -- a unit
     * title that does not exist, which the model could quote to a learner.
     */
    public function test_truncation_never_emits_a_partial_or_fabricated_name(): void {
        $this->resetAfterTest();
        $tpl = 'Unit %d: A Reasonably Long Descriptive Section Name Here';
        $this->build_scaled_course(60, 0, '', $tpl);

        $topics = context_builder::get_course_topics_text((int) $this->course->id, 1500);

        $real = [];
        for ($i = 1; $i <= 60; $i++) {
            $real[] = sprintf($tpl, $i);
        }
        $emitted = [];
        foreach (explode("\n", $topics) as $line) {
            if (strpos($line, '- ') === 0) {
                $emitted[] = substr($line, 2);
            }
        }
        $this->assertNotEmpty($emitted, 'no section lines survived at all');
        $this->assertSame([], array_values(array_diff($emitted, $real)),
            'clamp_topics emitted a partial or fabricated section name');
    }

    /**
     * B1/B2 at scale. The invariant "every visible section name survives" holds
     * while the names themselves fit; past that it cannot, and the honest
     * boundary is worth pinning rather than asserting a guarantee that is false.
     *
     * Measured on the 1500-char anchored budget with these name lengths: n=24
     * fits with room to spare; n=60 cannot (60 names alone exceed the budget),
     * so it degrades to a truncated-but-truthful list. The code comment used to
     * claim this "only bites at hundreds of sections", which is wrong.
     */
    public function test_all_names_survive_while_they_physically_fit(): void {
        $this->resetAfterTest();
        $this->build_scaled_course(24, 0, self::COURSE_SUMMARY);

        $topics = context_builder::get_course_topics_text((int) $this->course->id, 1500);

        $names = [];
        for ($i = 1; $i <= 24; $i++) {
            $names[] = sprintf(self::SCALE_NAME_TPL, $i);
        }
        [$missing, $first] = $this->missing_names($names, $topics);
        $this->assertSame(0, $missing,
            "a 24-section course lost {$missing} name(s) that fit inside the budget, first: {$first}");
        $this->assertLessThanOrEqual(1500, strlen($topics), 'the cap was exceeded');
    }

    /**
     * The D4/D5 trade, pinned so it stays a decision rather than an accident.
     *
     * Names are priced before the course summary, so when a course is large
     * enough that its names alone consume the budget, the "Course overview:"
     * header is dropped and the hours/CEU facts go with it. That is the
     * intended outcome: a model missing the summary says "the materials don't
     * state a duration", which is visibly incomplete, whereas a model missing
     * the tail of the section list presents a partial course as the whole
     * course, which nothing signals. See the comment in build_course_topics().
     *
     * Measured boundary at ~47-char names on the 1500-char anchored budget:
     * 24 sections keeps both; 30 sections keeps every name and drops the header.
     */
    public function test_summary_yields_to_names_under_budget_pressure(): void {
        $this->resetAfterTest();

        // Comfortable: both the map and the facts fit.
        $this->build_scaled_course(8, 0, self::COURSE_SUMMARY);
        $roomy = context_builder::get_course_topics_text((int) $this->course->id, 1500);
        $this->assertStringContainsString('Time: 26 hours', $roomy,
            'the hours fact should survive whenever there is room for it');

        // Squeezed: names alone dominate the budget.
        $names = $this->build_scaled_course(30, 0, self::COURSE_SUMMARY);
        $tight = context_builder::get_course_topics_text((int) $this->course->id, 1500);

        [$missing, $first] = $this->missing_names($names, $tight);
        $this->assertSame(0, $missing,
            "the course map must survive intact; lost {$missing}, first: {$first}");
        $this->assertStringNotContainsString('Course overview:', $tight,
            'the header should yield to the section names, not the other way round');
        $this->assertLessThanOrEqual(1500, strlen($tight), 'the cap was exceeded');
    }

}
