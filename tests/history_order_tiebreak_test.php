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
 * Message ordering must be deterministic when timestamps tie.
 *
 * `ORDER BY timecreated` alone is not a total order. A user turn and its reply
 * routinely land in the same second, and the database is then free to return
 * them in any order -- which decides which turns reach the model and, in the
 * prune path, which rows get deleted. CI caught this as a once-in-a-while
 * failure on one PHP/Postgres leg; these tests pin the timestamps equal so it
 * fails every time instead.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class history_order_tiebreak_test extends \advanced_testcase {

    /**
     * Create a conversation whose messages all share one timecreated value.
     *
     * @param int $pairs Number of user/assistant pairs to create.
     * @return int The conversation id.
     */
    private function conversation_with_tied_timestamps(int $pairs): int {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $conv = conversation_manager::get_or_create_conversation($user->id, $course->id);

        for ($i = 0; $i < $pairs; $i++) {
            conversation_manager::add_message($conv->id, $user->id, $course->id, 'user', "Q{$i}");
            conversation_manager::add_message($conv->id, $user->id, $course->id, 'assistant', "A{$i}");
        }

        // The defect only shows when timecreated ties, so force the tie rather
        // than hoping the inserts land in the same second.
        $DB->set_field('local_ai_course_assistant_msgs', 'timecreated', 1788000000,
            ['conversationid' => $conv->id]);

        return (int) $conv->id;
    }

    /**
     * get_messages must return insertion order even when every timestamp is equal.
     */
    public function test_get_messages_is_stable_when_timestamps_tie(): void {
        $this->resetAfterTest();

        $convid = $this->conversation_with_tied_timestamps(3);

        $actual = array_map(
            fn($m) => $m->message,
            array_values(conversation_manager::get_messages($convid))
        );

        $this->assertSame(
            ['Q0', 'A0', 'Q1', 'A1', 'Q2', 'A2'],
            $actual,
            'get_messages returned a different order than the messages were written in. '
            . 'ORDER BY timecreated alone is not a total order; it needs a unique tiebreaker.'
        );
    }

    /**
     * The history window must keep the NEWEST turns when timestamps tie.
     *
     * This is the production consequence: get_history_for_api trims with
     * array_slice(-N), so a non-deterministic order can hand the model the
     * oldest turns and drop the most recent one.
     */
    public function test_history_window_keeps_the_newest_turns_when_timestamps_tie(): void {
        $this->resetAfterTest();

        set_config('maxhistory', '2', 'local_ai_course_assistant');
        $convid = $this->conversation_with_tied_timestamps(3);

        $history = conversation_manager::get_history_for_api($convid);
        $contents = array_column($history, 'content');

        $this->assertCount(4, $contents);
        $this->assertSame(
            ['Q1', 'A1', 'Q2', 'A2'],
            $contents,
            'The history window dropped the newest turn and kept an older one.'
        );
    }

    /**
     * The 100-message prune must delete the OLDEST rows when timestamps tie.
     *
     * This path chooses rows to DELETE, so a tie resolving the wrong way is
     * data loss, not just a display-order quirk.
     */
    public function test_prune_deletes_the_oldest_rows_when_timestamps_tie(): void {
        global $DB;
        $this->resetAfterTest();

        // add_message prunes as it goes, so after 60 pairs the conversation is
        // already at the 100-message cap. Tie the timestamps on whatever
        // survived, then trip the prune once more and watch which row goes.
        $convid = $this->conversation_with_tied_timestamps(60);
        $this->assertEquals(100, $DB->count_records('local_ai_course_assistant_msgs',
            ['conversationid' => $convid]));

        $before = array_map(
            fn($m) => $m->message,
            array_values(conversation_manager::get_messages($convid))
        );
        $oldest = $before[0];
        $newest = $before[count($before) - 1];

        $conv = $DB->get_record('local_ai_course_assistant_convs', ['id' => $convid]);
        conversation_manager::add_message($convid, $conv->userid, $conv->courseid, 'user', 'FINAL');

        $after = array_map(
            fn($m) => $m->message,
            array_values(conversation_manager::get_messages($convid))
        );

        $this->assertContains('FINAL', $after, 'the prune deleted the message that triggered it');
        $this->assertNotContains($oldest, $after,
            "the prune kept the oldest message ({$oldest}) instead of deleting it");
        $this->assertContains($newest, $after,
            "the prune deleted the newest message ({$newest}) instead of an old one");
    }

    /**
     * Source guard: every order-critical query must name a unique tiebreaker.
     *
     * The behavioural tests above cannot be watched failing on this project's
     * local setup -- MySQL/InnoDB happens to return primary-key order when
     * timecreated ties, so it resolves the ambiguity favourably by accident and
     * the pre-fix code passes. Only the Postgres CI legs disagree, and only
     * sometimes. This assertion fails on every database, so removing a
     * tiebreaker is caught at the point it is removed rather than months later
     * on one CI leg.
     */
    public function test_order_critical_queries_name_a_unique_tiebreaker(): void {
        global $CFG;
        $base = $CFG->dirroot . '/local/ai_course_assistant/';

        // file => [line-identifying substring => required ordering]
        $required = [
            'classes/conversation_manager.php' => [
                "'timecreated ASC, id ASC'" => 2,
            ],
            'classes/task/soapbox_cleanup.php' => [
                "'timecreated DESC, id DESC'" => 1,
            ],
        ];

        foreach ($required as $file => $expectations) {
            $src = file_get_contents($base . $file);
            $this->assertNotFalse($src, "{$file} is unreadable");

            foreach ($expectations as $needle => $count) {
                $this->assertSame(
                    $count,
                    substr_count($src, $needle),
                    "{$file} must order by {$needle} in {$count} place(s). Ordering by a "
                    . 'non-unique column alone is not a total order: these queries decide '
                    . 'which turns reach the model and which rows are DELETED, so a tie '
                    . 'resolving the wrong way is data loss.'
                );
            }

            // And no bare timecreated ordering may creep back in.
            $this->assertSame(
                0,
                preg_match_all("/'timecreated (?:ASC|DESC)'/", $src),
                "{$file} still orders by a bare timecreated with no unique tiebreaker."
            );
        }
    }
}
