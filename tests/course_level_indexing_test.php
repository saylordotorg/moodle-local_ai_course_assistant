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
 * Course-level front matter must be indexed and retrievable (S14).
 *
 * Found on staging 2026-09-02. The extractor walked $modinfo->get_cms() only,
 * so the course summary and every section summary were invisible to SOLA on
 * every course; the prompt path appended a section summary only when it was
 * under 200 characters. ARTH101 states its five-step analysis system in a
 * 2,037-character section-0 summary, and the assistant could not see it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\content_extractor::extract_course_level
 */
final class course_level_indexing_test extends \advanced_testcase {

    /** @var string Stands in for ARTH101's section-0 summary: long enough that the old 200-char rule dropped it. */
    private const LONG_SECTION_SUMMARY =
        'The five-step system for analyzing a work of art. Description: what you literally see. '
        . 'Analysis: how the formal elements work together. Context: when and why it was made. '
        . 'Meaning: what it communicates to a viewer. Judgment: A critical assessment of how well '
        . 'the work achieves what it sets out to do, argued from the previous four steps rather '
        . 'than from taste alone.';

    /**
     * The course summary and a long section summary both reach the extractor.
     */
    public function test_course_and_section_summaries_are_extracted(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course([
            'summary' => '<p>An introduction to art appreciation and visual analysis.</p>',
            'summaryformat' => FORMAT_HTML,
            'numsections' => 2,
        ]);

        // Give section 0 the long summary the old code path dropped.
        global $DB;
        $DB->set_field('course_sections', 'summary', self::LONG_SECTION_SUMMARY,
            ['course' => $course->id, 'section' => 0]);
        rebuild_course_cache($course->id, true);

        $item = content_extractor::extract_course_level((int) $course->id);

        $this->assertNotNull($item, 'course-level front matter was not extracted at all');
        $this->assertNull($item['cmid'], 'course-level rows must carry cmid = null');
        $this->assertSame('course', $item['modtype']);
        $this->assertStringContainsString('art appreciation', $item['text'],
            'the course summary is missing from the extracted text');
        $this->assertStringContainsString('Judgment: A critical', $item['text'],
            'the long section summary is missing -- this is the ARTH101 failure');
        $this->assertGreaterThan(200, strlen($item['text']),
            'the old prompt path only kept section summaries under 200 chars');
    }

    /**
     * A course with no summaries yields nothing rather than an empty chunk.
     */
    public function test_course_without_front_matter_yields_null(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '', 'numsections' => 1]);
        $this->assertNull(content_extractor::extract_course_level((int) $course->id));
    }

    /**
     * Course-level content is emitted as ONE document.
     *
     * Course-level rows share the indexer's upsert key (courseid + cmid +
     * chunkindex) because cmid is null for all of them. Two documents would
     * make the second one's chunk 0 delete the first one's chunk 0 on every
     * run. One document means one continuous index range and no collision.
     */
    public function test_course_level_is_a_single_document_so_chunkindex_cannot_collide(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course([
            'summary' => 'Overview text for the course, long enough to be indexable on its own.',
            'summaryformat' => FORMAT_HTML,
            'numsections' => 3,
        ]);
        global $DB;
        foreach ([0, 1, 2] as $s) {
            $DB->set_field('course_sections', 'summary',
                "Section {$s} summary. " . self::LONG_SECTION_SUMMARY,
                ['course' => $course->id, 'section' => $s]);
        }
        rebuild_course_cache($course->id, true);

        $modules = content_extractor::extract_course_modules((int) $course->id);
        $courselevel = array_values(array_filter($modules, static function ($m) {
            return ($m['modtype'] ?? '') === 'course';
        }));

        $this->assertCount(1, $courselevel,
            'course-level content must be one document; several would collide on chunkindex');
        foreach ([0, 1, 2] as $s) {
            $this->assertStringContainsString("Section {$s} summary.", $courselevel[0]['text'],
                "section {$s}'s summary is missing from the single course-level document");
        }
    }

    /**
     * Retrieval must not discard course-level rows on a module-page turn.
     *
     * They read back with cmid 0, so the document filter would drop them on
     * exactly the turns where a learner asks about the course as a whole.
     */
    public function test_course_level_rows_survive_document_scoping(): void {
        $this->resetAfterTest();
        $courserow = ['cmid' => 0, 'modtype' => 'course', 'rank' => 0.9];
        $onpage    = ['cmid' => 42, 'modtype' => 'page', 'rank' => 0.8];
        $offpage   = ['cmid' => 99, 'modtype' => 'page', 'rank' => 0.7];
        $ranked = [$courserow, $onpage, $offpage];

        $this->assertSame([$courserow, $onpage],
            rag_retriever::scope_to_document($ranked, 42, 'document_first'));
        $this->assertSame([$courserow],
            rag_retriever::scope_to_document($ranked, 7, 'document_only'));
    }
}
