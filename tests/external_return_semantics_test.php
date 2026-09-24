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

use core_external\external_api;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * What clean_returnvalue() actually does, pinned, because we kept getting it wrong.
 *
 * Five places in this plugin asserted that external_single_structure "throws on any
 * undeclared key rather than ignoring it", and built an account of a production
 * outage on top of that: a commit message, two code comments and two test
 * docblocks. It is not true, and the outage did not happen. Both the defect and its
 * fix landed before the v7.5.1 tag, so no release ever carried it.
 *
 * The rule is asymmetric, and the asymmetry is the whole point:
 *
 *   An UNDECLARED key in the response is silently DROPPED. clean_returnvalue()
 *   builds its result from the declared keys and never looks at what is left over,
 *   so the consumer simply never receives the field. Nothing fails, nothing is
 *   logged, and the next person to look wonders why the browser has no value.
 *
 *   A DECLARED key that is ABSENT and VALUE_REQUIRED THROWS invalid_response_exception.
 *   On an ajax endpoint that fires after the provider has been billed and the row
 *   written, which is the expensive failure and the one worth defending against.
 *
 * Both of our actual decisions were right and neither changes: declare max_score
 * rather than strip it, and carry every declared key through every early return.
 * Only the stated reasons were wrong, in the direction that makes a reviewer
 * distrust the rest.
 *
 * This file exists so the rule is checked rather than remembered. If Moodle ever
 * makes the undeclared direction strict, this fails and the comments get updated
 * with it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \core_external\external_api::clean_returnvalue
 */
final class external_return_semantics_test extends \basic_testcase {
    /**
     * A structure with one required key, for both directions.
     *
     * @return external_single_structure
     */
    private function structure(): external_single_structure {
        return new external_single_structure([
            'declared' => new external_value(PARAM_INT, 'A key the structure declares'),
        ]);
    }

    /**
     * An undeclared key is dropped, quietly, and the call succeeds.
     *
     * @return void
     */
    public function test_an_undeclared_key_is_dropped_not_rejected(): void {
        $result = external_api::clean_returnvalue(
            $this->structure(),
            ['declared' => 7, 'undeclared' => 'this never reaches the caller']
        );

        $this->assertSame(['declared' => 7], $result);
        $this->assertArrayNotHasKey(
            'undeclared',
            $result,
            'The field is gone and nothing said so. A consumer expecting it gets null, which is '
                . 'why an undeclared key is a data bug rather than an error.'
        );
    }

    /**
     * A declared, required key that is absent throws.
     *
     * @return void
     */
    public function test_a_missing_required_key_throws(): void {
        $this->expectException(\core\exception\invalid_response_exception::class);

        external_api::clean_returnvalue($this->structure(), []);
    }

    /**
     * The same asymmetry one level down, inside a multiple structure.
     *
     * Worth its own case because the program-outcomes payload nests a multiple
     * structure inside the summary, and a rule that held only at the top level
     * would be a different rule.
     *
     * @return void
     */
    public function test_the_same_rule_applies_inside_a_nested_structure(): void {
        $description = new external_single_structure([
            'rows' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'An id'),
                ])
            ),
        ]);

        $result = external_api::clean_returnvalue(
            $description,
            ['rows' => [['id' => 1, 'stowaway' => 'dropped here too']]]
        );

        $this->assertSame(['rows' => [['id' => 1]]], $result);
    }

    /**
     * Nothing in the plugin still states the rule the wrong way round.
     *
     * A guard on prose, which is unusual and deliberate. The false claim survived
     * five rewrites of the surrounding code because comments are not executed, and
     * it was load-bearing: it was the stated reason for two defensive decisions and
     * for an account of an outage that never happened.
     *
     * @return void
     */
    public function test_no_comment_claims_an_undeclared_key_throws(): void {
        $root = realpath(__DIR__ . '/..');
        $offenders = [];

        foreach (['classes', 'tests'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php' || $file->getFilename() === basename(__FILE__)) {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                if (self::claims_undeclared_keys_are_rejected($source)) {
                    $offenders[] = str_replace($root . '/', '', $file->getPathname());
                }
            }
        }

        sort($offenders);
        $this->assertSame(
            [],
            $offenders,
            'An undeclared key is DROPPED, not rejected. See this file for what actually happens. '
                . 'Say that instead, and do not describe a consequence the mechanism cannot have.'
        );
    }

    /**
     * Does this source ASSERT that an undeclared key is rejected?
     *
     * Deliberately not a plain keyword match. The corrected comments have to be
     * able to say "an undeclared key is dropped, not rejected", and a guard that
     * flagged the correction as well as the error would be uninstallable, which is
     * the usual reason a prose guard gets deleted a week later.
     *
     * So it looks for the claim and the rejection verb near each other, and then
     * asks whether anything between them turns it into a denial.
     *
     * @param string $source File contents.
     * @return bool
     */
    private static function claims_undeclared_keys_are_rejected(string $source): bool {
        // The claim has more than one spelling, which is how two copies survived
        // the first correction pass. One said "strict both ways" and one said
        // "throws on any key it was not told about", and a guard matching the
        // literal words "undeclared key" saw neither of them. A reviewer found
        // both. So match the IDEA, in either order, and let the negation check
        // below spare a comment that is correcting the claim rather than making
        // it.
        $subject = '(?:undeclared key|key it was not told about|key it does not declare'
            . '|key that is not declared|key the structure does not declare)';
        $verb = '(?:throws?|rejects?|rejected|fails?|failed|errors?)';
        $patterns = [
            "/{$subject}(.{0,120}?)\b{$verb}\b/is",
            "/\b{$verb}\b(.{0,120}?){$subject}/is",
            // This one asserts it with no verb anywhere near, so it needs its own
            // pattern and an empty capture group to keep the negation check happy.
            '/\bstrict (?:both ways|in both directions)\b()/i',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                $between = $match[1] . ($match[2] ?? '');
                if (preg_match('/\b(not|never|rather than|instead of|no longer)\b/i', $between)) {
                    continue;
                }
                return true;
            }
        }

        return false;
    }
}
