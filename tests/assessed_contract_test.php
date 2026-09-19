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
 * One flag, one rule, everywhere it is read.
 *
 * The "assessed" flag decides whether a criterion counts toward a learner's
 * score and whether it is greyed out in three different tables. It used to be
 * implemented four times by hand, with three different answers for 0, "0" and
 * "": compute_overall() excluded only on a strict false, while every renderer
 * used a loose cast. So for those values the score COUNTED a criterion that the
 * table printing that score showed as "Not assessed".
 *
 * Nothing writes those values today, because the scoring endpoint declares the
 * field PARAM_BOOL. That is exactly why it needed pinning rather than leaving:
 * the next writer of a scores row is one loose cast away, and the failure is
 * silent and looks like a display bug.
 *
 * @package    local_ai_course_assistant
 * @covers     \local_ai_course_assistant\rubric_manager::is_assessed
 */
final class assessed_contract_test extends \basic_testcase {

    /**
     * Every value a scores row could carry, and whether it counts.
     *
     * @return array<string, array{mixed, bool}>
     */
    public static function assessed_values(): array {
        return [
            'explicit false is the ONLY exclusion' => [false, false],
            'explicit true counts' => [true, true],
            'absent counts (every row written before v7.5.1)' => ['__ABSENT__', true],
            'null counts' => [null, true],
            'integer zero counts' => [0, true],
            'string zero counts' => ['0', true],
            'empty string counts' => ['', true],
            'integer one counts' => [1, true],
            'non-empty string counts' => ['yes', true],
        ];
    }

    /**
     * The rule, pinned against every value.
     *
     * @dataProvider assessed_values
     * @param mixed $value The stored assessed value, or '__ABSENT__' for no key.
     * @param bool $expected Whether the criterion counts.
     * @return void
     */
    public function test_only_an_explicit_false_excludes_a_criterion($value, bool $expected): void {
        $criterion = ['name' => 'Delivery', 'score' => 3, 'max_score' => 5];
        if ($value !== '__ABSENT__') {
            $criterion['assessed'] = $value;
        }
        $this->assertSame($expected, rubric_manager::is_assessed($criterion));
    }

    /**
     * A malformed row does not take a score off anyone.
     *
     * @return void
     */
    public function test_a_non_array_row_counts(): void {
        $this->assertTrue(rubric_manager::is_assessed('not an array'));
        $this->assertTrue(rubric_manager::is_assessed(null));
    }

    /**
     * The total agrees with the rule, so a score and its table cannot diverge.
     *
     * @return void
     */
    public function test_compute_overall_uses_the_same_rule(): void {
        $totals = rubric_manager::compute_overall([
            ['name' => 'A', 'score' => 4, 'max_score' => 5, 'assessed' => true],
            ['name' => 'B', 'score' => 0, 'max_score' => 5, 'assessed' => false],
            // Counts, and used not to agree with the renderers.
            ['name' => 'C', 'score' => 3, 'max_score' => 5, 'assessed' => 0],
        ]);
        $this->assertSame(2, $totals['assessed'], 'only the explicit false is excluded');
        $this->assertSame(10, $totals['maxtotal'], 'and only its max leaves the denominator');
    }

    /**
     * Nobody has hand-rolled the rule again.
     *
     * This is the guard that actually prevents the regression. The four copies
     * were each individually reasonable; the defect was that they existed.
     *
     * @return void
     */
    public function test_no_reader_reimplements_the_rule(): void {
        $root = realpath(__DIR__ . '/..');
        $offenders = [];
        $loose = '/!isset\(\s*\$\w+\[.assessed.\]\s*\)\s*\|\|/';
        $strict = '/isset\(\s*\$\w+\[.assessed.\]\s*\)\s*&&\s*\$\w+\[.assessed.\]\s*===\s*false/';

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace($root . '/', '', $file->getPathname());
            if (preg_match('#^(lang|tests|cdn/node_modules|node_modules)/#', $path)) {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            if (preg_match($strict, $src)) {
                $offenders[] = $path . ' (strict copy)';
            }
            if (preg_match($loose, $src) && $path !== 'classes/external/score_speech.php') {
                $offenders[] = $path . ' (loose copy)';
            }
        }
        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "The assessed rule is implemented somewhere other than rubric_manager::is_assessed(). "
                . "Call the helper instead. The single permitted exception is the loose coercion at "
                . "the score_speech API boundary, which turns untrusted provider JSON into a real "
                . "boolean once, in the learner's favour, before anything downstream reads it."
        );
    }
}
