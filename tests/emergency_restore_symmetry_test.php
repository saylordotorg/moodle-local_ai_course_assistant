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
 * restore() must be the exact inverse of disable() (v7.3.2, findings F26/F39).
 *
 * Three of the five flags used to hard-write '1' on restore with no saved
 * baseline. The one that bites is outreach_master_enabled, which SHIPS '0':
 * MASTER KILL followed by Restore turned on a safety switch the operator had
 * never enabled. An emergency control that does not put things back the way it
 * found them is worse than none, because the operator believes it did.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\emergency_control::disable
 * @covers     \local_ai_course_assistant\emergency_control::restore
 */
final class emergency_restore_symmetry_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Every flag disable() touches must round-trip to its original value.
     *
     * @return array<string, array{0: string, 1: array<string,string>}>
     */
    public static function flag_provider(): array {
        return [
            'outreach off before the incident' => [
                emergency_control::FLAG_OUTREACH,
                ['outreach_master_enabled' => '0'],
            ],
            'outreach on before the incident' => [
                emergency_control::FLAG_OUTREACH,
                ['outreach_master_enabled' => '1'],
            ],
            'rag deliberately off before the incident' => [
                emergency_control::FLAG_RAG,
                ['rag_enabled' => '0', 'rag_auto_reindex_drifted' => '0'],
            ],
            'rag on before the incident' => [
                emergency_control::FLAG_RAG,
                ['rag_enabled' => '1', 'rag_auto_reindex_drifted' => '1'],
            ],
        ];
    }

    /**
     * @dataProvider flag_provider
     * @param string $flag
     * @param array<string,string> $before
     */
    public function test_restore_returns_every_flag_to_its_prior_value(string $flag, array $before): void {
        foreach ($before as $k => $v) {
            set_config($k, $v, 'local_ai_course_assistant');
        }

        emergency_control::disable([$flag], 'test', 'phpunit');
        emergency_control::restore([$flag], 'test', 'phpunit');

        foreach ($before as $k => $v) {
            $this->assertSame($v, (string) get_config('local_ai_course_assistant', $k),
                "$k did not round-trip through disable/restore");
        }
    }

    /**
     * The master kill is the path an operator actually uses under pressure, and
     * it fans out to every flag. A site with outreach off must still have it off
     * afterwards.
     */
    public function test_master_kill_and_restore_does_not_enable_outreach(): void {
        set_config('outreach_master_enabled', '0', 'local_ai_course_assistant');
        set_config('enabled', '1', 'local_ai_course_assistant');

        emergency_control::disable([emergency_control::FLAG_ALL], 'incident', 'phpunit');
        $this->assertSame('0', (string) get_config('local_ai_course_assistant', 'outreach_master_enabled'));

        emergency_control::restore([emergency_control::FLAG_ALL], 'all clear', 'phpunit');

        $this->assertSame('0', (string) get_config('local_ai_course_assistant', 'outreach_master_enabled'),
            'restore must not enable a switch that was off before the incident');
        $this->assertSame('1', (string) get_config('local_ai_course_assistant', 'enabled'),
            'the master switch was on before, so it must be on after');
    }

    /**
     * A blank voice label is the default on an unconfigured site. The old code
     * guarded the stash on the value being non-empty, so blanking an already
     * blank label saved nothing and restore had nothing to put back.
     */
    public function test_blank_voice_label_round_trips(): void {
        set_config('voice_active_realtime', '', 'local_ai_course_assistant');

        emergency_control::disable([emergency_control::FLAG_VOICE], 'test', 'phpunit');
        $this->assertSame('1', (string) get_config('local_ai_course_assistant', 'emergency_voice_disabled'),
            'the enforcing flag must be set');

        emergency_control::restore([emergency_control::FLAG_VOICE], 'test', 'phpunit');
        $this->assertSame('', (string) get_config('local_ai_course_assistant', 'voice_active_realtime'));
        $this->assertFalse(get_config('local_ai_course_assistant', 'emergency_voice_disabled'),
            'the enforcing flag must be cleared');
    }

    /**
     * Disabling twice must not capture the already-zeroed value as the baseline.
     */
    public function test_double_disable_keeps_the_true_original(): void {
        set_config('outreach_master_enabled', '1', 'local_ai_course_assistant');

        emergency_control::disable([emergency_control::FLAG_OUTREACH], 'first', 'phpunit');
        emergency_control::disable([emergency_control::FLAG_OUTREACH], 'again', 'phpunit');
        emergency_control::restore([emergency_control::FLAG_OUTREACH], 'clear', 'phpunit');

        $this->assertSame('1', (string) get_config('local_ai_course_assistant', 'outreach_master_enabled'),
            'a second disable must not overwrite the stashed original with the zeroed value');
    }
}
