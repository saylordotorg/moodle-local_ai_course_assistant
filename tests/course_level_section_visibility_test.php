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

    /**
     * Add a subsection (a delegated section) to a section and give it a distinctive summary.
     *
     * @param \stdClass $course
     * @param int $parentsection Section number the subsection module sits in.
     * @param string $word Distinctive word for the summary and title.
     * @return \stdClass The subsection module record (with cmid).
     */
    private function subsection(\stdClass $course, int $parentsection, string $word): \stdClass {
        global $DB;
        $mod = $this->getDataGenerator()->create_module(
            'subsection',
            ['course' => $course->id, 'section' => $parentsection, 'name' => "{$word}-TITLE"]
        );
        $DB->set_field(
            'course_sections',
            'summary',
            "{$word}-SUMMARY text." . self::PAD,
            ['course' => $course->id, 'component' => 'mod_subsection', 'itemid' => $mod->id]
        );
        rebuild_course_cache($course->id, true);
        return $mod;
    }

    /**
     * A subsection inherits hiding and restrictions from its module and the section that holds it.
     */
    public function test_delegated_section_inherits_hiding_and_restrictions(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        if (!file_exists($CFG->dirroot . '/mod/subsection/version.php')) {
            $this->markTestSkipped('mod_subsection is not installed on this Moodle, so there are no delegated sections.');
        }
        set_config('enableavailability', 1);
        // Moodle 4.5 installs mod_subsection disabled; a site that uses subsections has enabled it.
        \core\plugininfo\mod::enable_plugin('subsection', 1);
        $this->setAdminUser();
        $course = $this->course();
        $this->subsection($course, 1, 'OPENSUB');
        $this->subsection($course, 3, 'SUBINRESTRICTED');
        $this->subsection($course, 2, 'SUBINHIDDEN');
        $restrictedcm = $this->subsection($course, 1, 'SUBCMRESTRICTED');

        // Section 3 restricted; section 2 hidden directly in the table, so the subsection's own row stays
        // visible and only inheritance can hide it; one subsection module restricted on the module itself.
        $date = json_encode(['op' => '&', 'c' => [['type' => 'date', 'd' => '>=', 't' => time() + WEEKSECS]],
            'showc' => [true]]);
        $DB->set_field('course_sections', 'availability', $date, ['course' => $course->id, 'section' => 3]);
        $DB->set_field('course_sections', 'visible', 0, ['course' => $course->id, 'section' => 2]);
        $DB->set_field('course_modules', 'availability', $date, ['id' => $restrictedcm->cmid]);
        rebuild_course_cache($course->id, true);
        $this->assertSame(
            1,
            (int) $DB->get_field(
                'course_sections',
                'visible',
                ['course' => $course->id, 'component' => 'mod_subsection', 'itemid' => $this->subsection_id($course, 'SUBINHIDDEN')]
            ),
            'precondition: the delegated section itself is still visible'
        );

        $text = content_extractor::extract_course_level((int) $course->id)['text'];

        $this->assertStringContainsString('OPENSUB-SUMMARY', $text);
        $this->assertStringNotContainsString('SUBINRESTRICTED', $text);
        $this->assertStringNotContainsString('SUBINHIDDEN', $text);
        $this->assertStringNotContainsString('SUBCMRESTRICTED', $text);

        // With the subsection module disabled the delegate can't be inspected, so every subsection is left out.
        \core\plugininfo\mod::enable_plugin('subsection', 0);
        rebuild_course_cache($course->id, true);
        $text = content_extractor::extract_course_level((int) $course->id)['text'];
        $this->assertStringNotContainsString('OPENSUB', $text);
        $this->assertStringContainsString('VISIBLESECTION-SUMMARY', $text);
    }

    /**
     * The subsection instance id whose module name starts with a word.
     *
     * @param \stdClass $course
     * @param string $word
     * @return int
     */
    private function subsection_id(\stdClass $course, string $word): int {
        global $DB;
        return (int) $DB->get_field('subsection', 'id', ['course' => $course->id, 'name' => "{$word}-TITLE"]);
    }

    /**
     * An assignment whose description is shown only from its submission date isn't indexed before it.
     */
    public function test_assignment_description_hidden_until_the_submission_date_is_not_indexed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $intro = ' brief: write an essay comparing two economic systems, citing at least three sources each.';
        $make = function (string $word, int $always, int $from) use ($course, $intro) {
            $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => $word,
                'intro' => "{$word}{$intro}", 'introformat' => FORMAT_HTML,
                'alwaysshowdescription' => $always, 'allowsubmissionsfromdate' => $from]);
        };
        $make('LATERONLY', 0, time() + WEEKSECS);
        $make('ALWAYSSHOWN', 1, time() + WEEKSECS);
        $make('DATEPASSED', 0, time() - DAYSECS);

        $texts = implode("\n", array_column(content_extractor::extract_course_modules((int) $course->id), 'text'));

        $this->assertStringNotContainsString('LATERONLY', $texts);
        $this->assertStringContainsString('ALWAYSSHOWN', $texts);
        $this->assertStringContainsString('DATEPASSED', $texts);
    }
}
