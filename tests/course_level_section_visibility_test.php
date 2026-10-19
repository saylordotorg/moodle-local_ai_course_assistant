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

use local_ai_course_assistant\embedding_provider\base_embedding_provider;

/**
 * Hidden and restricted sections stay out of the course-level document (#341).
 *
 * The course-level document carries no cmid, so rag_visibility can't filter it per learner by
 * section. The indexer runs as an administrator, so a hidden section's summary used to reach every
 * learner through it. These tests pin that such sections are left out at extraction, and that an
 * ordinary (not forced) reindex replaces a stale course-level chunk on an existing site.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\content_extractor::extract_course_level
 * @covers     \local_ai_course_assistant\content_indexer::index_course
 */
final class course_level_section_visibility_test extends \advanced_testcase {
    /** @var string Padding so each summary is substantial on its own. */
    private const PAD = ' This summary is long enough to be indexable text and to stand out in a chunk.';

    /**
     * Fixed-vector embedding provider so index_course() runs without the network.
     *
     * @return base_embedding_provider
     */
    private function fake_provider(): base_embedding_provider {
        return new class extends base_embedding_provider {
            /**
             * The model recorded on every chunk.
             *
             * @return string
             */
            public function get_model(): string {
                return 'fake-section-model';
            }

            /**
             * Default model name.
             *
             * @return string
             */
            protected function get_default_model(): string {
                return 'fake-section-model';
            }

            /**
             * Default base URL (never contacted).
             *
             * @return string
             */
            protected function get_default_base_url(): string {
                return 'https://example.invalid';
            }

            /**
             * One fixed vector.
             *
             * @param string $text
             * @return array
             */
            public function embed(string $text): array {
                return [0.5, 0.25, 0.125, 0.0625];
            }

            /**
             * One fixed vector per text.
             *
             * @param array $texts
             * @return array
             */
            public function embed_batch(array $texts): array {
                return array_map(fn($t) => $this->embed((string) $t), $texts);
            }
        };
    }

    /**
     * A course with a summary and three sections, each with a distinctive summary and name.
     *
     * @return \stdClass
     */
    private function course(): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course([
            'summary' => 'Course overview for the visibility test course.' . self::PAD,
            'summaryformat' => FORMAT_HTML,
            'numsections' => 3,
        ]);
        foreach ([1 => 'VISIBLESECTION', 2 => 'HIDDENSECTION', 3 => 'RESTRICTEDSECTION'] as $num => $word) {
            $DB->set_field(
                'course_sections',
                'summary',
                "{$word}-SUMMARY text." . self::PAD,
                ['course' => $course->id, 'section' => $num]
            );
            $DB->set_field('course_sections', 'name', "{$word}-TITLE", ['course' => $course->id, 'section' => $num]);
        }
        rebuild_course_cache($course->id, true);
        return $course;
    }

    /**
     * Hide section 2.
     *
     * @param \stdClass $course
     */
    private function hide_section(\stdClass $course): void {
        set_section_visible((int) $course->id, 2, 0);
    }

    /**
     * Put a date restriction on section 3.
     *
     * @param \stdClass $course
     */
    private function restrict_section(\stdClass $course): void {
        global $DB;
        $json = json_encode([
            'op' => '&',
            'c' => [['type' => 'date', 'd' => '>=', 't' => time() + WEEKSECS]],
            'showc' => [true],
        ]);
        $DB->set_field('course_sections', 'availability', $json, ['course' => $course->id, 'section' => 3]);
        rebuild_course_cache($course->id, true);
    }

    /**
     * A hidden section's summary and title are absent; a visible section's are present.
     */
    public function test_hidden_section_is_left_out_of_the_course_level_document(): void {
        $this->resetAfterTest();
        $course = $this->course();
        $this->hide_section($course);
        $this->setAdminUser();

        $text = content_extractor::extract_course_level((int) $course->id)['text'];

        $this->assertStringNotContainsString('HIDDENSECTION', $text);
        $this->assertStringContainsString('VISIBLESECTION-SUMMARY', $text);
        $this->assertStringContainsString('VISIBLESECTION-TITLE', $text);
        $this->assertStringContainsString('Course overview for the visibility test course', $text);
    }

    /**
     * A section behind an access restriction is left out too; one with an empty tree is not.
     */
    public function test_restricted_section_is_left_out_of_the_course_level_document(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('enableavailability', 1);
        $course = $this->course();
        $this->restrict_section($course);
        // An empty tree is no restriction, so section 1 stays in.
        $DB->set_field(
            'course_sections',
            'availability',
            json_encode(['op' => '&', 'c' => [], 'showc' => []]),
            ['course' => $course->id, 'section' => 1]
        );
        rebuild_course_cache($course->id, true);
        $this->setAdminUser();

        $text = content_extractor::extract_course_level((int) $course->id)['text'];

        $this->assertStringNotContainsString('RESTRICTEDSECTION', $text);
        $this->assertStringContainsString('VISIBLESECTION-SUMMARY', $text);
        $this->assertStringContainsString('HIDDENSECTION-SUMMARY', $text, 'section 2 is visible in this test');
    }

    /**
     * An ordinary reindex replaces the stale course-level chunk once a section is hidden.
     *
     * The indexer skips a chunk only when a row with the same course and content hash exists. The
     * course-level text changes, so its hash changes, the new chunk is embedded, and the old row is
     * deleted (same cmid and chunkindex, then the stale-hash prune).
     */
    public function test_ordinary_reindex_replaces_the_stale_course_level_chunk(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('rag_enabled', 1, 'local_ai_course_assistant');
        set_config('enableavailability', 1);
        $this->setAdminUser();
        $course = $this->course();
        $provider = $this->fake_provider();

        content_indexer::index_course((int) $course->id, false, ['provider' => $provider]);
        $before = $DB->get_fieldset_select(
            'local_ai_course_assistant_chunks',
            'content',
            'courseid = :c',
            ['c' => $course->id]
        );
        $this->assertStringContainsString(
            'HIDDENSECTION-SUMMARY',
            implode("\n", $before),
            'precondition: the summary is indexed while the section is visible'
        );

        $this->hide_section($course);
        $this->restrict_section($course);
        content_indexer::index_course((int) $course->id, false, ['provider' => $provider]);

        $after = implode("\n", $DB->get_fieldset_select(
            'local_ai_course_assistant_chunks',
            'content',
            'courseid = :c',
            ['c' => $course->id]
        ));
        $this->assertStringNotContainsString('HIDDENSECTION', $after);
        $this->assertStringNotContainsString('RESTRICTEDSECTION', $after);
        $this->assertStringContainsString('VISIBLESECTION-SUMMARY', $after);
    }
}
