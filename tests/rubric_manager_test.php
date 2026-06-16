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
 * Tests for rubric_manager: practice rubrics + scores, with focus on the
 * v6.7.0 Soapbox speech rubric type and v6.7.1 ESL level presets.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\rubric_manager
 */
final class rubric_manager_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_type_speech_constant(): void {
        $this->assertEquals('speech', rubric_manager::TYPE_SPEECH);
    }

    public function test_default_speech_criteria_shape(): void {
        $criteria = rubric_manager::DEFAULT_SPEECH_CRITERIA;
        $this->assertCount(5, $criteria);
        foreach ($criteria as $c) {
            $this->assertArrayHasKey('name', $c);
            $this->assertArrayHasKey('description', $c);
            $this->assertArrayHasKey('max_score', $c);
            $this->assertSame(5, $c['max_score']);
        }
    }

    public function test_get_default_criteria_by_type(): void {
        $this->assertSame(rubric_manager::DEFAULT_SPEECH_CRITERIA,
            rubric_manager::get_default_criteria('speech'));
        $this->assertSame(rubric_manager::DEFAULT_PRONUNCIATION_CRITERIA,
            rubric_manager::get_default_criteria('pronunciation'));
        $this->assertSame(rubric_manager::DEFAULT_CONVERSATION_CRITERIA,
            rubric_manager::get_default_criteria('conversation'));
        // Unknown type falls back to conversation.
        $this->assertSame(rubric_manager::DEFAULT_CONVERSATION_CRITERIA,
            rubric_manager::get_default_criteria('nonsense'));
    }

    public function test_speech_presets_has_four_levels(): void {
        $presets = rubric_manager::speech_presets();
        $this->assertEqualsCanonicalizing(
            ['general', 'esl_beginner', 'esl_intermediate', 'esl_advanced'],
            array_keys($presets));
        foreach ($presets as $level => $preset) {
            $this->assertArrayHasKey('label_key', $preset, "$level needs a label_key");
            $this->assertArrayHasKey('hint', $preset, "$level needs a coaching hint");
            $this->assertNotEmpty($preset['hint']);
            $this->assertCount(5, $preset['criteria'], "$level rubric should have 5 criteria");
        }
    }

    public function test_speech_preset_general_matches_default_criteria(): void {
        $this->assertSame(rubric_manager::DEFAULT_SPEECH_CRITERIA,
            rubric_manager::speech_preset('general')['criteria']);
    }

    public function test_speech_preset_resolves_levels_and_falls_back(): void {
        $beginner = rubric_manager::speech_preset('esl_beginner');
        $this->assertStringContainsStringIgnoringCase('beginner', $beginner['hint']);
        $advanced = rubric_manager::speech_preset('esl_advanced');
        $this->assertStringContainsStringIgnoringCase('advanced', $advanced['hint']);
        // Unknown level falls back to the General preset.
        $this->assertSame(rubric_manager::speech_preset('general'),
            rubric_manager::speech_preset('does-not-exist'));
    }

    public function test_ensure_default_rubrics_seeds_a_global_speech_rubric(): void {
        rubric_manager::ensure_default_rubrics();
        $rubric = rubric_manager::get_active_rubric(0, rubric_manager::TYPE_SPEECH);
        $this->assertNotNull($rubric);
        $this->assertSame('speech', $rubric->type);
        $this->assertSame(0, (int) $rubric->courseid);
        // get_active_rubric returns criteria already decoded to an array.
        $this->assertIsArray($rubric->criteria);
        $this->assertCount(5, $rubric->criteria);
    }

    public function test_get_active_rubric_falls_back_to_global_default(): void {
        $course = $this->getDataGenerator()->create_course();
        rubric_manager::ensure_default_rubrics();
        // No course-specific speech rubric -> falls back to the global one.
        $rubric = rubric_manager::get_active_rubric((int) $course->id, rubric_manager::TYPE_SPEECH);
        $this->assertNotNull($rubric);
        $this->assertSame(0, (int) $rubric->courseid);
    }

    public function test_save_score_persists_meta_blob(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $rid = rubric_manager::create_rubric(0, rubric_manager::TYPE_SPEECH, 'Speech',
            rubric_manager::DEFAULT_SPEECH_CRITERIA);

        $scores = [['name' => 'Delivery & Fluency', 'score' => 4, 'feedback' => 'Clear pace.']];
        $meta = ['name' => 'My speech', 'topic' => 'Climate', 'target' => 180, 'tips' => ['Slow down']];
        $sid = rubric_manager::save_score($rid, (int) $user->id, (int) $course->id,
            rubric_manager::TYPE_SPEECH, $scores, 4, 'Nice work.', 95, $meta);

        $this->assertGreaterThan(0, $sid);
        $row = $DB->get_record('local_ai_course_assistant_practice_scores', ['id' => $sid]);
        $this->assertSame('speech', $row->session_type);
        $this->assertSame(95, (int) $row->session_duration);
        $this->assertSame($meta, json_decode($row->session_meta, true));
    }

    public function test_save_score_meta_is_null_when_omitted(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $rid = rubric_manager::create_rubric(0, 'conversation', 'Conv',
            rubric_manager::DEFAULT_CONVERSATION_CRITERIA);

        $sid = rubric_manager::save_score($rid, (int) $user->id, (int) $course->id,
            'conversation', [], 3, 'ok', 10);

        $row = $DB->get_record('local_ai_course_assistant_practice_scores', ['id' => $sid]);
        $this->assertNull($row->session_meta);
    }

    public function test_get_user_scores_returns_decoded_scores_for_speech(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $rid = rubric_manager::create_rubric(0, rubric_manager::TYPE_SPEECH, 'Speech',
            rubric_manager::DEFAULT_SPEECH_CRITERIA);
        $scores = [['name' => 'Delivery & Fluency', 'score' => 5, 'feedback' => 'Great.']];
        rubric_manager::save_score($rid, (int) $user->id, (int) $course->id,
            rubric_manager::TYPE_SPEECH, $scores, 5, 'Excellent.', 120,
            ['name' => 'Speech 1']);

        $rows = rubric_manager::get_user_scores((int) $user->id, (int) $course->id,
            rubric_manager::TYPE_SPEECH, 5);

        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertIsArray($row->scores);
        $this->assertSame('Delivery & Fluency', $row->scores[0]['name']);
        // session_meta is left as raw JSON for the caller to decode.
        $this->assertSame('Speech 1', json_decode($row->session_meta, true)['name']);
    }
}
