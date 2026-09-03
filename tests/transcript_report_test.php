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
 * Anonymised transcript reporting (v7.2.10).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\transcript_report
 */
final class transcript_report_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $alice;
    /** @var \stdClass */
    private $bob;

    /**
     * A course with two learners and a handful of messages.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $this->alice = $this->getDataGenerator()->create_user();
        $this->bob = $this->getDataGenerator()->create_user();
    }

    /**
     * Insert one message row.
     *
     * @param int $userid
     * @param string $role
     * @param string $text
     * @param int $when
     * @param int|null $cmid
     * @param int $convid
     * @return int
     */
    private function msg(int $userid, string $role, string $text, int $when,
            ?int $cmid = null, int $convid = 1): int {
        global $DB;
        return (int) $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => $convid,
            'userid' => $userid,
            'courseid' => (int) $this->course->id,
            'role' => $role,
            'message' => $text,
            'cmid' => $cmid,
            'interaction_type' => 'chat',
            'timecreated' => $when,
        ]);
    }

    /**
     * Attach an objective attempt to a message, so the outcome filter has
     * something real to select on.
     *
     * @param int $msgid
     * @param int $iscorrect
     * @param int $objectiveid
     * @return void
     */
    private function outcome(int $msgid, int $iscorrect, int $objectiveid = 11): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_obj_att', (object) [
            'userid' => (int) $this->alice->id,
            'courseid' => (int) $this->course->id,
            'objectiveid' => $objectiveid,
            'source' => 'classifier',
            'msgid' => $msgid,
            'iscorrect' => $iscorrect,
            'timecreated' => time(),
        ]);
    }

    /**
     * A cell that Excel would execute as a formula is neutralised; ordinary
     * text is untouched. Learner-authored message text lands in these cells,
     * so this is reachable by anyone who can type into the chat box.
     */
    public function test_csv_cells_are_escaped_against_formula_injection(): void {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tSUM", "\r=cmd"] as $dangerous) {
            $this->assertSame("'" . $dangerous, transcript_report::csv_cell($dangerous),
                "formula-leading cell not neutralised: " . json_encode($dangerous));
        }
        foreach (['hello', 'What is management?', '', '1+1', 'a=b'] as $safe) {
            $this->assertSame($safe, transcript_report::csv_cell($safe),
                'ordinary text should pass through unchanged: ' . json_encode($safe));
        }
    }

    /**
     * Pseudonyms are stable within a report, meaningless across reports, and
     * are not the known-weak 'Student NNNN' format.
     */
    public function test_pseudonyms_are_stable_per_report_and_not_the_weak_format(): void {
        $salt1 = transcript_report::new_salt();
        $salt2 = transcript_report::new_salt();
        $a1 = transcript_report::pseudonym((int) $this->alice->id, $salt1);

        $this->assertSame($a1, transcript_report::pseudonym((int) $this->alice->id, $salt1),
            'the same learner must get the same label throughout one report');
        $this->assertNotSame($a1, transcript_report::pseudonym((int) $this->bob->id, $salt1),
            'two learners must not share a label within a report');
        $this->assertNotSame($a1, transcript_report::pseudonym((int) $this->alice->id, $salt2),
            'labels must not survive across reports, or two exports could be joined');
        $this->assertDoesNotMatchRegularExpression('/^Student \d{1,4}$/', $a1,
            'the new report must not reuse the reversible, colliding Student NNNN scheme');
        // NOT assertStringNotContainsString($alice->id, $a1): the label is six
        // chars from the alphabet 0-9A-F, which contains every decimal digit,
        // and Moodle's harness resets sequences so the id is a single digit.
        // P(a single digit appears in 6 uniform hex chars) = 1-(15/16)^6 ~= 32%,
        // so that assertion red-lit about one run in three with a message
        // implying a leak that was not there. Asserting the shape is
        // deterministic and strictly stronger: an opaque fixed-width token
        // cannot encode the id however the id is numbered.
        $this->assertMatchesRegularExpression('/^Learner [0-9A-F]{6}$/', $a1,
            'the label must be an opaque fixed-width hex token');
    }

    /**
     * role='system' telemetry rows are never conversation.
     */
    public function test_system_telemetry_rows_are_excluded(): void {
        $t = time();
        $this->msg((int) $this->alice->id, 'user', 'a learner question', $t);
        $this->msg((int) $this->alice->id, 'assistant', 'an answer', $t + 1);
        $this->msg((int) $this->alice->id, 'system', '[Embedding] cost row', $t + 2);

        $rows = transcript_report::transcripts(['courseid' => (int) $this->course->id],
            transcript_report::new_salt());

        $this->assertCount(2, $rows, 'system telemetry leaked into the transcript');
        foreach ($rows as $r) {
            $this->assertContains($r['role'], ['user', 'assistant']);
        }
    }

    /**
     * The date range filters on both ends.
     */
    public function test_date_range_filters_both_ends(): void {
        $base = mktime(12, 0, 0, 6, 15, 2026);
        $this->msg((int) $this->alice->id, 'user', 'before the window', $base - 86400 * 5);
        $this->msg((int) $this->alice->id, 'user', 'inside the window', $base);
        $this->msg((int) $this->alice->id, 'user', 'after the window', $base + 86400 * 5);

        $rows = transcript_report::transcripts([
            'courseid' => (int) $this->course->id,
            'from' => $base - 3600,
            'to' => $base + 3600,
        ], transcript_report::new_salt());

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('inside the window', $rows[0]['message']);
    }

    /**
     * Topic search matches message text and binds its parameter, so a LIKE
     * metacharacter is data rather than syntax.
     */
    public function test_topic_search_matches_text_and_treats_wildcards_as_data(): void {
        $t = time();
        $this->msg((int) $this->alice->id, 'user', 'tell me about photosynthesis', $t);
        $this->msg((int) $this->alice->id, 'user', 'tell me about corporations', $t + 1);

        $hit = transcript_report::transcripts(
            ['courseid' => (int) $this->course->id, 'topic' => 'photosynthesis'],
            transcript_report::new_salt());
        $this->assertCount(1, $hit);

        // A bare % must not behave as "match everything".
        $wild = transcript_report::transcripts(
            ['courseid' => (int) $this->course->id, 'topic' => '%'],
            transcript_report::new_salt());
        $this->assertCount(0, $wild,
            'a LIKE wildcard in the topic box was treated as syntax, not as text');
    }

    /**
     * A real section that holds no modules must return nothing, not everything.
     * Dropping the clause instead of emitting a false predicate would silently
     * widen the report to the whole course.
     */
    public function test_empty_section_returns_no_rows_rather_than_all_rows(): void {
        $t = time();
        $this->msg((int) $this->alice->id, 'user', 'somewhere in the course', $t);

        $rows = transcript_report::transcripts([
            'courseid' => (int) $this->course->id,
            'section' => 3,
        ], transcript_report::new_salt());

        $this->assertCount(0, $rows,
            'filtering by a module-less unit returned the whole course');
    }

    /**
     * CSV output carries a header row and escapes its cells.
     */
    public function test_csv_has_header_and_escapes_cells(): void {
        $t = time();
        $this->msg((int) $this->alice->id, 'user', '=SUM(1,1) is a formula', $t);
        $rows = transcript_report::transcripts(['courseid' => (int) $this->course->id],
            transcript_report::new_salt());
        $csv = transcript_report::to_csv($rows);

        $this->assertStringContainsString('conversation', $csv, 'header row missing');
        $this->assertStringContainsString("'=SUM(1,1)", $csv,
            'a formula-leading message was not neutralised in the CSV');
    }

    /**
     * Summaries collapse to one row per conversation.
     */
    public function test_summaries_are_one_row_per_conversation(): void {
        $t = time();
        $this->msg((int) $this->alice->id, 'user', 'q1', $t, null, 11);
        $this->msg((int) $this->alice->id, 'assistant', 'a1', $t + 1, null, 11);
        $this->msg((int) $this->bob->id, 'user', 'q2', $t + 2, null, 22);

        // The conversation column is a per-report label now, not the raw PK, so
        // the expected keys have to be derived with the same salt.
        $salt = transcript_report::new_salt();
        $rows = transcript_report::summaries(['courseid' => (int) $this->course->id], $salt);

        $this->assertCount(2, $rows);
        $byconv = [];
        foreach ($rows as $r) {
            $byconv[$r['conversation']] = $r;
        }
        $this->assertSame(2, $byconv[transcript_report::conversation_label(11, $salt)]['messages']);
        $this->assertSame(1, $byconv[transcript_report::conversation_label(22, $salt)]['messages']);
    }

    /**
     * The conversation column must be as unlinkable as the learner column.
     *
     * The raw conversationid is the _convs PK, and get_or_create_conversation()
     * keys on (userid, courseid) -- one row per learner per course -- so it is a
     * stable per-learner identifier. Emitting it beside the salted learner label
     * would restore the exact join the pseudonyms exist to remove.
     */
    public function test_conversation_label_does_not_survive_across_reports(): void {
        $salt1 = transcript_report::new_salt();
        $salt2 = transcript_report::new_salt();
        $convid = 4321;

        $c1 = transcript_report::conversation_label($convid, $salt1);

        $this->assertSame($c1, transcript_report::conversation_label($convid, $salt1),
            'one conversation must keep one label throughout a report, or rows cannot be grouped');
        $this->assertNotSame($c1, transcript_report::conversation_label($convid, $salt2),
            'the label must not survive across reports, or two exports could be joined on it');
        $this->assertNotSame($c1, transcript_report::conversation_label($convid + 1, $salt1),
            'two conversations must not share a label within a report');
        $this->assertMatchesRegularExpression('/^C-[0-9A-F]{6}$/', $c1,
            'the label must be an opaque fixed-width token');
        $this->assertStringNotContainsString((string) $convid, $c1,
            'a 4-digit id must not appear verbatim in a 6-char hex token');
    }

    /**
     * No emitted row, in either shape, may carry the raw conversation PK.
     */
    public function test_no_row_leaks_the_raw_conversation_id(): void {
        $salt = transcript_report::new_salt();
        $filters = ['courseid' => $this->course->id];
        $t = time();
        $this->msg((int) $this->alice->id, 'user', 'hello', $t, null, 31);
        $this->msg((int) $this->alice->id, 'assistant', 'hi', $t + 1, null, 31);
        $this->msg((int) $this->bob->id, 'user', 'hey', $t + 2, null, 32);

        foreach (['transcripts', 'summaries'] as $shape) {
            $rows = transcript_report::$shape($filters, $salt);
            $this->assertNotEmpty($rows, "the $shape fixture produced no rows to check");
            foreach ($rows as $r) {
                $this->assertIsString($r['conversation'],
                    "$shape must emit a label, not the raw integer PK");
                $this->assertStringStartsWith('C-', $r['conversation']);
            }
        }
    }

    /**
     * Outcome is its own filter, not a modifier on the objective filter.
     *
     * The UI renders them as two independent selects and the objective defaults
     * to 0 = "All". Nesting outcome inside the objective branch meant Status
     * "Not met" + Objective "All" returned every row while the export audit
     * logged the outcome as applied.
     */
    public function test_outcome_filter_applies_with_no_objective_selected(): void {
        $salt = transcript_report::new_salt();
        $t = time();

        $met = $this->msg((int) $this->alice->id, 'user', 'i got it', $t, null, 91);
        $failed = $this->msg((int) $this->alice->id, 'user', 'still stuck', $t + 1, null, 92);
        $this->outcome($met, 1);
        $this->outcome($failed, 0);

        $all = transcript_report::transcripts(['courseid' => $this->course->id], $salt);
        $notmet = transcript_report::transcripts(
            ['courseid' => $this->course->id, 'objectiveid' => 0, 'outcome' => '0'], $salt);
        $metrows = transcript_report::transcripts(
            ['courseid' => $this->course->id, 'objectiveid' => 0, 'outcome' => '1'], $salt);

        // Guard against a vacuous pass: the fixture must actually produce rows.
        $this->assertNotEmpty($notmet, 'the not-met fixture row was not returned');
        $this->assertNotEmpty($metrows, 'the met fixture row was not returned');

        $this->assertLessThan(count($all), count($notmet),
            'outcome=0 with no objective selected must narrow the set, not return everything');
        $this->assertSame(['still stuck'], array_column($notmet, 'message'),
            'outcome=0 must return only the not-met message');
        $this->assertSame(['i got it'], array_column($metrows, 'message'),
            'outcome=1 must return only the met message');
    }
}
