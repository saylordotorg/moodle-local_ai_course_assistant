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
 * A thumbs-down outlives the conversation history cap (issue #283).
 *
 * add_message() caps a conversation at 100 messages and deletes the oldest
 * beyond that. It never touched msg_ratings, so a rating on a trimmed message
 * kept a messageid that no longer resolved, and every consumer joined through
 * that column with an INNER JOIN. A learner's thumbs-down therefore left the
 * instructor review queue the moment their message aged out, which is backwards:
 * the longer and more engaged the conversation, the more likely the feedback
 * vanished.
 *
 * The row now keeps a copy of the text it was reacting to, the review queue
 * LEFT JOINs and falls back to that copy, and the course filter uses the
 * rating's own courseid rather than the message's.
 *
 * Two consumers keep an INNER JOIN deliberately: instructor_analytics groups by
 * cmid and llm_optimizer filters on provider and model, and an orphaned rating
 * belongs to neither. Those are asserted here so a later change does not
 * "fix" them into counting ratings they cannot attribute.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\review_queue
 */
final class rating_survives_history_cap_test extends \advanced_testcase {

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Rate a reply, lose the message, keep the feedback.
     *
     * The message is deleted directly rather than by driving 100 messages
     * through add_message(), because the defect is about a rating outliving its
     * message, not about the cap's arithmetic. Any future delete path produces
     * the same state.
     */
    public function test_a_rating_survives_its_message_being_trimmed(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $conv = conversation_manager::get_or_create_conversation((int) $user->id, (int) $course->id);
        $messageid = conversation_manager::add_message(
            (int) $conv->id, (int) $user->id, (int) $course->id,
            'assistant', 'Photosynthesis happens in the mitochondria.');

        $DB->insert_record('local_ai_course_assistant_msg_ratings', (object) [
            'messageid' => $messageid,
            'userid' => $user->id,
            'courseid' => $course->id,
            'rating' => -1,
            'is_hallucination' => 1,
            'comment' => 'That is wrong.',
            'rated_excerpt' => 'Photosynthesis happens in the mitochondria.',
            'timecreated' => 1759276800,
        ]);

        $before = review_queue::pending_for_course((int) $course->id);
        $this->assertNotEmpty($before, 'sanity: the thumbs-down should be in the queue to begin with');

        // The history cap trims the oldest messages and leaves the ratings.
        $DB->delete_records('local_ai_course_assistant_msgs', ['id' => $messageid]);

        $after = review_queue::pending_for_course((int) $course->id);
        $this->assertNotEmpty($after,
            'The learner flagged this reply as wrong and the instructor review queue '
                . 'dropped it as soon as the message aged out of the conversation.');

        // pending_for_course() returns arrays with the text under 'summary'.
        $texts = array_map(static function ($row) {
            return (string) ($row['summary'] ?? '');
        }, array_values($after));
        $this->assertStringContainsString('mitochondria', implode(' ', $texts),
            'the queue kept the rating but lost the text it was about, so there is '
                . 'nothing for an instructor to act on');
    }

    /**
     * The queue is still scoped to one course.
     *
     * Moving the filter from the message to the rating must not widen it.
     */
    public function test_the_queue_is_still_scoped_to_its_course(): void {
        global $DB;

        $here = $this->getDataGenerator()->create_course();
        $elsewhere = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        foreach ([$here, $elsewhere] as $course) {
            $conv = conversation_manager::get_or_create_conversation(
                (int) $user->id, (int) $course->id);
            $mid = conversation_manager::add_message(
                (int) $conv->id, (int) $user->id, (int) $course->id, 'assistant', 'A reply.');
            $DB->insert_record('local_ai_course_assistant_msg_ratings', (object) [
                'messageid' => $mid,
                'userid' => $user->id,
                'courseid' => $course->id,
                'rating' => -1,
                'is_hallucination' => 0,
                'rated_excerpt' => 'A reply in course ' . $course->id,
                'timecreated' => 1759276800,
            ]);
        }

        $rows = array_values(array_filter(
            review_queue::pending_for_course((int) $here->id),
            static function ($row) {
                return ($row['source'] ?? '') === 'rating';
            }
        ));
        // One rating exists in each course. Exactly one must come back.
        $this->assertCount(1, $rows,
            'The course filter moved from the message to the rating. If it widened, this '
                . 'queue now shows an instructor feedback from a course they may not teach.');
    }

    /**
     * Rating a message stores the text alongside it.
     */
    public function test_rating_a_message_keeps_a_copy_of_its_text(): void {
        $src = file_get_contents(__DIR__ . '/../classes/external/rate_message.php');
        $this->assertNotFalse($src);
        $this->assertStringContainsString('rated_excerpt', $src,
            'rate_message does not store the rated text, so every new rating is one '
                . 'conversation-trim away from being unreadable');
    }

    /**
     * The two deliberate INNER JOINs stay INNER.
     */
    public function test_module_and_model_attribution_still_require_the_message(): void {
        foreach ([
            '../classes/instructor_analytics.php' => 'cmid',
            '../classes/llm_optimizer.php' => 'provider',
        ] as $file => $why) {
            $src = file_get_contents(__DIR__ . '/' . $file);
            $this->assertNotFalse($src, $file);
            $this->assertStringNotContainsString(
                'LEFT JOIN {local_ai_course_assistant_msgs}', $src,
                basename($file) . ' attributes ratings by ' . $why . ', which an orphaned '
                    . 'rating does not have. A LEFT JOIN here counts ratings that belong '
                    . 'to no ' . $why . '. See issue #283.');
        }
    }
}
