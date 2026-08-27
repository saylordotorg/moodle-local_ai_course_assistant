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

defined('MOODLE_INTERNAL') || die();

/**
 * v7.1.2: the anomaly digest gains an absolute floor.
 *
 * A percentage on a small base is noise. Learn's daily token volume swings
 * between roughly 1.7M and 3.7M with nothing behind it, which clears the default
 * 50% day-over-day threshold routinely -- and the day that produced a "76% up"
 * alert cost $1.33 in total across four models. The floor asks the question the
 * percentage cannot: is this enough money to care about?
 *
 * The behaviour that most needs pinning is the fail-open case. A floor that
 * silenced an alert because the spend could not be priced would hide exactly the
 * situation worth seeing -- an unrecognised model appearing in the mix.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\task\run_anomaly_digest
 */
final class anomaly_digest_floor_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $user;
    /** @var \stdClass */
    private $conv;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_user();
        $this->conv = conversation_manager::get_or_create_conversation(
            $this->user->id, $this->course->id);
    }

    /**
     * Write a billable assistant row inside the last 24h.
     *
     * @param string $model
     * @param int $prompt
     * @param int $completion
     * @return void
     */
    private function spend(string $model, int $prompt, int $completion): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => $this->conv->id,
            'userid' => $this->user->id,
            'courseid' => $this->course->id,
            'role' => 'assistant',
            'message' => 'reply',
            'tokens_used' => $prompt + $completion,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'model_name' => $model,
            'provider' => 'test',
            'interaction_type' => 'chat',
            'timecreated' => time() - 600,
        ]);
    }

    /**
     * Call the private helper under test.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     */
    private function call(string $method, array $args = []) {
        $task = new \local_ai_course_assistant\task\run_anomaly_digest();
        $ref = new \ReflectionMethod($task, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($ref->isStatic() ? null : $task, $args);
    }

    public function test_the_floor_is_off_by_default(): void {
        // An upgrade must not silently start suppressing alerts.
        $this->assertSame(0.0, $this->call('floor_usd'));
    }

    public function test_a_configured_floor_is_read(): void {
        set_config('anomaly_digest_floor_usd', '5.50', 'local_ai_course_assistant');
        $this->assertSame(5.5, $this->call('floor_usd'));
    }

    public function test_a_negative_floor_is_clamped_to_zero(): void {
        set_config('anomaly_digest_floor_usd', '-10', 'local_ai_course_assistant');
        $this->assertSame(0.0, $this->call('floor_usd'),
            'a negative floor would suppress nothing and confuse the log line');
    }

    public function test_a_priced_window_is_costed(): void {
        // gpt-4o-mini: $0.15/M input, $0.60/M output.
        $this->spend('gpt-4o-mini', 1_000_000, 1_000_000);
        $cost = $this->call('window_cost_usd', [86400]);
        $this->assertNotNull($cost);
        $this->assertEqualsWithDelta(0.75, $cost, 0.01);
    }

    public function test_an_empty_window_costs_nothing_rather_than_failing(): void {
        $cost = $this->call('window_cost_usd', [86400]);
        $this->assertSame(0.0, $cost,
            'no spend is $0, which is below any floor -- not unpriceable');
    }

    /**
     * The one that matters. An unrecognised model must NOT be treated as cheap.
     */
    public function test_an_unpriceable_model_fails_open(): void {
        $this->spend('gpt-4o-mini', 1000, 1000);
        $this->spend('some-model-nobody-has-a-rate-card-for', 5_000_000, 5_000_000);

        $this->assertNull($this->call('window_cost_usd', [86400]),
            'null tells the caller not to suppress; pricing part of the window '
            . 'and calling it the total would hide an unrecognised model');
    }

    public function test_rows_outside_the_window_are_not_counted(): void {
        global $DB;
        $this->spend('gpt-4o-mini', 1_000_000, 0);
        $DB->set_field('local_ai_course_assistant_msgs', 'timecreated',
            time() - (3 * 86400), ['courseid' => $this->course->id]);
        $this->assertSame(0.0, $this->call('window_cost_usd', [86400]));
    }

    /**
     * Non-billable rows must not inflate the cost past the floor. quiz_open
     * markers carry no model, and the shared predicate excludes them.
     */
    public function test_telemetry_rows_do_not_count_toward_the_floor(): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => $this->conv->id,
            'userid' => $this->user->id,
            'courseid' => $this->course->id,
            'role' => 'system',
            'message' => '[Quiz panel opened]',
            'tokens_used' => 0,
            'interaction_type' => 'quiz_open',
            'timecreated' => time() - 60,
        ]);
        $this->assertSame(0.0, $this->call('window_cost_usd', [86400]));
    }
}
