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
 * Remote configuration is opt-in, and clearing the setting really switches it off.
 *
 * CONTRIB-10574 #267. The setting shipped with the Saylor repository's raw
 * GitHub URL as its default, and an empty value fell back to that same URL, so
 * every install fetched a file from one organisation's branch once an hour and
 * applied its system prompt, instruction blocks and default model. An
 * administrator who cleared the field to stop it did not stop it.
 *
 * The fix has two halves and neither was tested when it was written. The
 * default is now empty and get() returns early on empty, and an upgrade step
 * removes the historic default from sites that never chose it. These tests
 * pin both, plus the suffix match in the course-deleted observer that was
 * rewritten in the same change.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\remote_config_manager::get
 */
final class remote_config_optin_test extends \advanced_testcase {

    /**
     * The URL this plugin used to ship as the default.
     *
     * Written out in full here on purpose. The production copy is assembled
     * from two concatenated pieces, so a test that greps the source for one
     * piece would miss a typo in the other. This literal is compared against
     * the production constant below, which catches a change to either half.
     */
    private const HISTORIC_DEFAULT =
        'https://raw.githubusercontent.com/saylordotorg/moodle-local_ai_course_assistant/main/sola-config.json';

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \cache::make('local_ai_course_assistant', 'remoteconfig')->purge();
    }

    /**
     * The shipped default is empty, so a fresh install fetches nothing.
     */
    public function test_the_default_url_is_empty(): void {
        $this->assertSame('', remote_config_manager::DEFAULT_URL,
            'A non-empty default means every install that never touched this setting '
                . 'fetches configuration from someone else\'s server. That is #267.');
    }

    /**
     * An unset setting yields no configuration and no HTTP call.
     *
     * The network assertion is the point. Returning [] while still making the
     * request would look identical to a caller and would not close #267.
     */
    public function test_an_unset_setting_fetches_nothing(): void {
        unset_config('remoteconfigurl', 'local_ai_course_assistant');

        $this->assert_nothing_is_fetched('an unset setting');
    }

    /**
     * An empty or whitespace-only setting is the same as unset.
     *
     * An administrator who clears the field in the admin form stores '', not
     * null, and someone who selects the text and types a space stores ' '.
     * Both are the same intent.
     *
     * @dataProvider blank_value_provider
     * @param string $value
     */
    public function test_a_blank_setting_fetches_nothing(string $value): void {
        set_config('remoteconfigurl', $value, 'local_ai_course_assistant');

        $this->assert_nothing_is_fetched('the blank value ' . var_export($value, true));
    }

    /**
     * Blank values an administrator can actually produce.
     *
     * @return array<string, string[]>
     */
    public static function blank_value_provider(): array {
        return [
            'cleared field' => [''],
            'a single space' => [' '],
            'whitespace only' => ["  \t \n "],
        ];
    }

    /**
     * The result of disabling is cached, so repeated calls stay off the network.
     */
    public function test_the_disabled_result_is_cached(): void {
        set_config('remoteconfigurl', '', 'local_ai_course_assistant');

        $this->assert_nothing_is_fetched('the first call');
        $this->assert_nothing_is_fetched('the cached second call');
    }

    /**
     * The upgrade step removes the historic default and nothing else.
     *
     * This calls the real method the upgrade step calls. An earlier version
     * reimplemented the comparison, which meant it passed whatever the upgrade
     * step actually did: a typo in the URL, an inverted comparison, or a switch
     * to comparing against DEFAULT_URL (now the empty string, which would clear
     * every site's setting) would all have left this green while real sites
     * kept fetching.
     *
     * @dataProvider stored_url_provider
     * @param string $stored
     * @param bool $shouldclear
     * @param string $why
     */
    public function test_the_upgrade_step_clears_only_the_historic_default(
        string $stored,
        bool $shouldclear,
        string $why
    ): void {
        set_config('remoteconfigurl', $stored, 'local_ai_course_assistant');

        $cleared = remote_config_manager::clear_historic_default();
        $after = get_config('local_ai_course_assistant', 'remoteconfigurl');

        $this->assertSame($shouldclear, $cleared,
            'clear_historic_default() reported the wrong outcome: ' . $why);

        if ($shouldclear) {
            $this->assertFalse($after, $why);
        } else {
            $this->assertSame($stored, $after, $why);
        }
    }

    /**
     * Values a site could be carrying when it upgrades.
     *
     * @return array<string, array{0: string, 1: bool, 2: string}>
     */
    public static function stored_url_provider(): array {
        return [
            'the historic default' => [
                self::HISTORIC_DEFAULT,
                true,
                'A site carrying the old default never chose it, so the upgrade removes it',
            ],
            'the historic default with whitespace' => [
                ' ' . self::HISTORIC_DEFAULT . ' ',
                true,
                'trim() covers a value that picked up whitespace in the admin form',
            ],
            'a site\'s own url' => [
                'https://config.example.edu/sola.json',
                false,
                'A deliberate choice must survive the upgrade untouched',
            ],
            'the same repo on another branch' => [
                'https://raw.githubusercontent.com/saylordotorg/'
                    . 'moodle-local_ai_course_assistant/develop/sola-config.json',
                false,
                'Nobody reaches this value by accident, so it is a choice and is kept',
            ],
            'the same repo, http not https' => [
                'http://raw.githubusercontent.com/saylordotorg/'
                    . 'moodle-local_ai_course_assistant/main/sola-config.json',
                false,
                'Not the shipped default, so not ours to remove; it is refused at fetch '
                    . 'time by is_safe_provider_url instead',
            ],
            'already cleared' => [
                '',
                false,
                'An empty value is already disabled and needs no upgrade action',
            ],
        ];
    }

    /**
     * The production constant is the URL this test thinks it is.
     *
     * The production copy is two concatenated string literals, so a typo in
     * either half would otherwise go unnoticed by a test that only ever
     * compares the constant against itself.
     */
    public function test_the_historic_url_is_the_one_this_plugin_shipped(): void {
        $this->assertSame(self::HISTORIC_DEFAULT,
            remote_config_manager::HISTORIC_DEFAULT_URL,
            'HISTORIC_DEFAULT_URL no longer matches the URL this plugin shipped as its '
                . 'default, so the upgrade step will not recognise the sites carrying it '
                . 'and they will keep fetching remote configuration.');
    }

    /**
     * The upgrade step calls the shared method rather than its own copy.
     *
     * The logic was inline until the review of b9c2e3bc pointed out that an
     * inline copy can only be covered by a test that reimplements it. If it
     * ever moves back, the tests above would silently stop testing the thing
     * that runs on a real upgrade.
     */
    public function test_the_upgrade_step_calls_the_shared_method(): void {
        global $CFG;
        $src = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/db/upgrade.php');
        $this->assertNotFalse($src);

        $block = substr($src, (int) strpos($src, 'oldversion < 2026100100'));
        $block = substr($block, 0, (int) strpos($block, 'upgrade_plugin_savepoint'));

        $this->assertStringContainsString('clear_historic_default()', $block,
            'The 2026100100 upgrade step no longer calls '
                . 'remote_config_manager::clear_historic_default(), so the tests above '
                . 'no longer describe what a real upgrade does.');
        $this->assertStringNotContainsString('raw.githubusercontent.com', $block,
            'The upgrade step has its own copy of the URL again. One copy, in '
                . 'remote_config_manager, is what stops the two drifting apart.');
    }

    /**
     * Deleting a course removes its own per-course settings and no others.
     *
     * The observer used to query config_plugins directly and now walks
     * get_config() matching the suffix "_course_<id>" at the end of the name.
     * The failure that shape invites is a prefix collision: deleting course 5
     * reaching into course 15 and course 51. The existing observer test cannot
     * see it, because its "keep" course gets the next sequential id and never
     * lines up the digits.
     *
     * Settings are written directly rather than through a real course deletion
     * so the ids can be chosen. The observer is then called with an event
     * carrying the colliding id.
     */
    public function test_deleting_a_course_leaves_a_longer_course_id_alone(): void {
        $course = $this->getDataGenerator()->create_course();
        $deleted = (int) $course->id;

        // Two ids that collide with $deleted on a naive match: one with a digit
        // appended, one with a digit prepended.
        $appended = (int) ($deleted . '7');
        $prepended = (int) ('9' . $deleted);

        set_config('rag_enabled_course_' . $deleted, '1', 'local_ai_course_assistant');
        set_config('soapbox_enabled_course_' . $deleted, '1', 'local_ai_course_assistant');
        set_config('rag_enabled_course_' . $appended, '1', 'local_ai_course_assistant');
        set_config('rag_enabled_course_' . $prepended, '1', 'local_ai_course_assistant');
        set_config('rag_enabled', '1', 'local_ai_course_assistant');

        $event = \core\event\course_deleted::create([
            'objectid' => $deleted,
            'context' => \context_system::instance(),
            'other' => [
                'shortname' => $course->shortname,
                'fullname' => $course->fullname,
                'idnumber' => $course->idnumber,
            ],
        ]);
        observer::course_deleted($event);

        $config = (array) get_config('local_ai_course_assistant');

        $this->assertArrayNotHasKey('rag_enabled_course_' . $deleted, $config,
            'The deleted course kept its per-course override');
        $this->assertArrayNotHasKey('soapbox_enabled_course_' . $deleted, $config,
            'The deleted course kept a second per-course override');

        $this->assertArrayHasKey('rag_enabled_course_' . $appended, $config,
            'Deleting course ' . $deleted . ' also removed the setting for course '
                . $appended . ', whose id merely starts with the same digits');
        $this->assertArrayHasKey('rag_enabled_course_' . $prepended, $config,
            'Deleting course ' . $deleted . ' also removed the setting for course '
                . $prepended . ', whose id merely ends with the same digits');
        $this->assertArrayHasKey('rag_enabled', $config,
            'Deleting a course removed the site-wide setting of the same name');
    }

    /**
     * get() returns nothing AND does not reach the network.
     *
     * Returning [] is not on its own the fix: the old code would also have
     * returned [] if Saylor's file happened to be unreachable, while still
     * making the request every hour. What #267 asks for is that the request
     * is not made at all.
     *
     * The proof is a queued mock response. Moodle's \curl pops one of these
     * instead of making a real request, so if get() reached the network it
     * would consume this mock and return the configuration inside it. Getting
     * [] back while the mock is still queued means no request was made, and
     * the second half asserts the mock really is still there, so a change that
     * stopped mocking from working could not quietly pass this test.
     *
     * @param string $case Description used in the failure message.
     */
    private function assert_nothing_is_fetched(string $case): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $sentinel = '{"model_default":"fetched-when-it-should-not-have-been"}';
        \curl::mock_response($sentinel);

        $this->assertSame([], remote_config_manager::get(),
            'With ' . $case . ', get() returned remote configuration, so it fetched one.');

        // The mock is still queued, which is what proves the call never happened.
        $probe = new \curl();
        $this->assertSame($sentinel, $probe->get('https://example.invalid/probe'),
            'With ' . $case . ', get() consumed the queued response, so it did reach '
                . 'the network and only the result was empty.');
    }
}
