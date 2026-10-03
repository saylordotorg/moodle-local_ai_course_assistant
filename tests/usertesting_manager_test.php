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
 * User-testing task sets: the rating-scale defect that is #288's twin.
 *
 * The survey page and this one were written from the same template and carry
 * the same two unchecked lines. This one is worse in two ways: the tasks are
 * posted as a single PARAM_RAW JSON blob, so the number inputs' min/max
 * attributes never see the values at all, and usertesting_manager had no
 * read-path repair of any kind. A task stored with min > max renders zero
 * rating buttons while amd/src/ui.js refuses to advance until the learner
 * picks one, so the panel simply cannot be completed.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\usertesting_manager
 */
final class usertesting_manager_test extends \advanced_testcase {

    /** @var string Table name for task sets. */
    private const TABLE = 'local_ai_course_assistant_ut_tasks';

    /**
     * Write a task set straight to the database, bypassing every validator.
     *
     * @param array $tasks Task definitions to store.
     * @param int $courseid Scope, 0 for the global default.
     * @return int The new task set ID.
     */
    private function store_raw_taskset(array $tasks, int $courseid = 0): int {
        global $DB;

        return $DB->insert_record(self::TABLE, (object) [
            'courseid' => $courseid,
            'title' => 'Raw fixture',
            'tasks' => json_encode($tasks),
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * A rating task, optionally with bounds.
     *
     * @param array $extra Fields merged over the base task.
     * @return array
     */
    private function rating_task(array $extra): array {
        return array_merge([
            'type' => 'action_then_rate',
            'instruction' => 'Ask the assistant something.',
            'rating_label' => 'How did that go?',
        ], $extra);
    }

    /**
     * Bounds stored the wrong way round come back usable.
     */
    public function test_reversed_bounds_are_swapped_on_read(): void {
        $this->resetAfterTest();

        $this->store_raw_taskset([$this->rating_task(['min' => 5, 'max' => 1])]);

        $taskset = usertesting_manager::get_active_taskset(0);

        $this->assertSame(1, $taskset->tasks[0]['min']);
        $this->assertSame(5, $taskset->tasks[0]['max']);
    }

    /**
     * A scale wide enough to hang the tab is brought inside the ceiling.
     */
    public function test_an_oversized_max_is_clamped_on_read(): void {
        $this->resetAfterTest();

        $this->store_raw_taskset([$this->rating_task(['min' => 1, 'max' => 500000])]);

        $taskset = usertesting_manager::get_active_taskset(0);

        $this->assertSame(1, $taskset->tasks[0]['min']);
        $this->assertSame(usertesting_manager::RATING_SCALE_MAX, $taskset->tasks[0]['max']);
    }

    /**
     * A range outside the window keeps its number of steps rather than collapsing.
     */
    public function test_an_out_of_window_scale_keeps_its_span(): void {
        $this->resetAfterTest();

        $this->store_raw_taskset([$this->rating_task(['min' => 11, 'max' => 12])]);

        $taskset = usertesting_manager::get_active_taskset(0);

        $this->assertSame(9, $taskset->tasks[0]['min']);
        $this->assertSame(10, $taskset->tasks[0]['max']);
    }

    /**
     * Whatever is stored, the learner gets at least one button and never a flood.
     */
    public function test_every_scale_read_back_is_answerable(): void {
        $this->resetAfterTest();

        $cases = [
            ['min' => 5, 'max' => 1],
            ['min' => 0, 'max' => 0],
            ['min' => 500000, 'max' => 1],
            ['min' => -3, 'max' => -1],
            [],
        ];

        foreach ($cases as $i => $bounds) {
            $this->store_raw_taskset([$this->rating_task($bounds)], $i + 1);

            $taskset = usertesting_manager::get_active_taskset($i + 1);
            $min = $taskset->tasks[0]['min'];
            $max = $taskset->tasks[0]['max'];
            $label = json_encode($bounds);

            $this->assertGreaterThanOrEqual(usertesting_manager::RATING_SCALE_MIN, $min, $label);
            $this->assertLessThanOrEqual($max, $min, "renders no buttons for {$label}");
            $this->assertLessThanOrEqual(usertesting_manager::RATING_SCALE_MAX, $max, $label);
        }
    }

    /**
     * Tasks that are not ratings are returned untouched.
     */
    public function test_non_rating_tasks_are_left_alone(): void {
        $this->resetAfterTest();

        $this->store_raw_taskset([[
            'type' => 'free_response',
            'instruction' => 'Tell us what you think.',
        ]]);

        $taskset = usertesting_manager::get_active_taskset(0);

        $this->assertArrayNotHasKey('min', $taskset->tasks[0]);
        $this->assertArrayNotHasKey('max', $taskset->tasks[0]);
    }

    /**
     * The raw loader hands back exactly what is stored, for the same reason
     * the survey one does: the admin editor posts back what it is seeded with.
     */
    public function test_the_raw_loader_does_not_repair_the_scale(): void {
        $this->resetAfterTest();

        $this->store_raw_taskset([$this->rating_task(['min' => 5, 'max' => 1])]);

        $raw = usertesting_manager::get_active_taskset_raw(0);

        $this->assertSame(5, $raw->tasks[0]['min']);
        $this->assertSame(1, $raw->tasks[0]['max']);
    }
}
