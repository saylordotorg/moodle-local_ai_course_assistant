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
 * The voice model catalog (8.1.0): defaults, shutdown dates, replaced_by chains and loops.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\voice_model_catalog
 */
final class voice_model_catalog_test extends \advanced_testcase {
    /** @var int 2026-12-17 23:59:59 UTC, the last second before the Gemini TTS previews shut down. */
    private const BEFORE = 1797551999;

    /** @var int 2026-12-18 00:00:00 UTC. */
    private const AT = 1797552000;

    protected function tearDown(): void {
        voice_model_catalog::set_data_for_testing(null);
        parent::tearDown();
    }

    public function test_the_constants_are_the_dates_they_claim(): void {
        $this->assertSame('2026-12-17 23:59:59', gmdate('Y-m-d H:i:s', self::BEFORE));
        $this->assertSame('2026-12-18 00:00:00', gmdate('Y-m-d H:i:s', self::AT));
    }

    /** Every deprecated entry has a parseable date and a successor that is in the catalog, under the same capability. */
    public function test_the_shipped_catalog_is_consistent(): void {
        foreach (voice_model_catalog::MODELS as $provider => $caps) {
            foreach ($caps as $capability => $spec) {
                $this->assertArrayHasKey($spec['default'], $spec['models'], "{$provider} {$capability} default is listed");
                $this->assertSame(voice_model_catalog::CURRENT, $spec['models'][$spec['default']]['status'],
                    "{$provider} {$capability} default is current");
                foreach ($spec['models'] as $model => $entry) {
                    $this->assertSame(voice_model_catalog::normalise($model), $model, 'ids are stored normalised');
                    if ($entry['status'] === voice_model_catalog::DEPRECATED) {
                        $this->assertNotNull(voice_model_catalog::shutdown_time($entry), "{$model} has a date");
                        $this->assertArrayHasKey($entry['replaced_by'], $spec['models'], "{$model} successor is listed");
                    }
                }
            }
        }
    }

    public function test_gemini_seed_entries(): void {
        $this->assertSame('gemini-3.8-flash-tts', voice_model_catalog::default_model('gemini', 'tts'));
        $this->assertSame('gemini-3.8-live', voice_model_catalog::default_model('gemini', 'realtime'));
        $this->assertTrue(voice_model_catalog::is_vetted('gemini', 'tts', 'gemini-3.8-flash-lite-tts'));
        foreach (['gemini-3.1-flash-tts-preview', 'gemini-2.5-flash-preview-tts', 'gemini-2.5-pro-preview-tts'] as $old) {
            $entry = voice_model_catalog::entry('gemini', 'tts', $old);
            $this->assertSame('2026-12-18', $entry['shutdown'], $old);
            $this->assertFalse(voice_model_catalog::is_vetted('gemini', 'tts', $old));
        }
        $live = voice_model_catalog::entry('gemini', 'realtime', 'gemini-2.5-flash-native-audio-preview-12-2025');
        $this->assertSame('2027-03-31', $live['shutdown']);
        $this->assertSame('gemini-3.8-live', $live['replaced_by']);
    }

    public function test_empty_configured_means_the_provider_default(): void {
        $this->assertSame('gemini-3.8-flash-tts', voice_model_catalog::resolve_model('gemini', 'tts', '', self::AT));
        $this->assertSame('tts-1', voice_model_catalog::resolve_model('openai', 'tts', '  ', self::AT));
        $this->assertSame('gpt-realtime-mini', voice_model_catalog::resolve_model('openai', 'realtime', '', self::AT));
        $this->assertSame('grok-tts', voice_model_catalog::resolve_model('xai', 'tts', '', self::AT));
        // No default anywhere: nothing to call.
        $this->assertSame('', voice_model_catalog::resolve_model('xai', 'realtime', '', self::AT));
        $this->assertSame('', voice_model_catalog::resolve_model('nobody', 'tts', '', self::AT));
    }

    public function test_a_deprecated_model_is_used_until_its_shutdown_date_then_its_successor(): void {
        $old = 'gemini-2.5-flash-preview-tts';
        $this->assertSame($old, voice_model_catalog::resolve_model('gemini', 'tts', $old, self::BEFORE));
        $this->assertSame('gemini-3.8-flash-tts', voice_model_catalog::resolve_model('gemini', 'tts', $old, self::AT));
        $this->assertSame('gemini-3.8-flash-tts', voice_model_catalog::resolve_model('gemini', 'tts', $old, self::AT + 86400 * 400));
        $this->assertFalse(voice_model_catalog::is_retired('gemini', 'tts', $old, self::BEFORE));
        $this->assertTrue(voice_model_catalog::is_retired('gemini', 'tts', $old, self::AT));
    }

    public function test_spelling_is_normalised_and_unknown_models_pass_through(): void {
        $this->assertSame('gemini-3.8-flash-tts',
            voice_model_catalog::resolve_model('Gemini', 'tts', ' models/Gemini-2.5-Flash-Preview-TTS ', self::AT));
        // A model newer than the catalog is the admin's call.
        $this->assertSame('gemini-9-flash-tts', voice_model_catalog::resolve_model('gemini', 'tts', 'gemini-9-flash-tts', self::AT));
        $this->assertFalse(voice_model_catalog::is_retired('gemini', 'tts', 'gemini-9-flash-tts', self::AT));
        $this->assertFalse(voice_model_catalog::is_vetted('gemini', 'tts', 'gemini-9-flash-tts'));
    }

    /** A chain of retired models is followed to the first one still running. */
    public function test_a_chain_is_followed_past_retired_steps(): void {
        voice_model_catalog::set_data_for_testing(['p' => ['tts' => ['default' => 'd', 'models' => [
            'a' => ['status' => 'deprecated', 'shutdown' => '2026-01-01', 'replaced_by' => 'b'],
            'b' => ['status' => 'deprecated', 'shutdown' => '2026-06-01', 'replaced_by' => 'c'],
            'c' => ['status' => 'deprecated', 'shutdown' => '2027-01-01', 'replaced_by' => 'd'],
            'd' => ['status' => 'current', 'shutdown' => null, 'replaced_by' => null],
        ]]]]);
        $jan = gmmktime(12, 0, 0, 1, 2, 2026);
        $jul = gmmktime(12, 0, 0, 7, 1, 2026);
        $next = gmmktime(12, 0, 0, 2, 1, 2027);
        $this->assertSame('b', voice_model_catalog::resolve_model('p', 'tts', 'a', $jan));
        $this->assertSame('c', voice_model_catalog::resolve_model('p', 'tts', 'a', $jul));
        $this->assertSame('d', voice_model_catalog::resolve_model('p', 'tts', 'a', $next));
        $this->assertSame('c', voice_model_catalog::resolve_model('p', 'tts', 'b', $jul));
    }

    /** A loop or a step outside the catalog never sends a known-dead model: the default is used. */
    public function test_a_loop_or_broken_chain_falls_back_to_the_default(): void {
        voice_model_catalog::set_data_for_testing(['p' => ['tts' => ['default' => 'safe', 'models' => [
            'x' => ['status' => 'deprecated', 'shutdown' => '2026-01-01', 'replaced_by' => 'y'],
            'y' => ['status' => 'deprecated', 'shutdown' => '2026-01-01', 'replaced_by' => 'x'],
            'self' => ['status' => 'deprecated', 'shutdown' => '2026-01-01', 'replaced_by' => 'self'],
            'gone' => ['status' => 'deprecated', 'shutdown' => '2026-01-01', 'replaced_by' => 'not-in-catalog'],
            'orphan' => ['status' => 'deprecated', 'shutdown' => '2026-01-01', 'replaced_by' => null],
            'safe' => ['status' => 'current', 'shutdown' => null, 'replaced_by' => null],
        ]]]]);
        $now = gmmktime(0, 0, 0, 3, 1, 2026);
        foreach (['x', 'y', 'self', 'gone', 'orphan'] as $model) {
            $this->assertNull(voice_model_catalog::successor('p', 'tts', $model, $now), $model);
            $this->assertSame('safe', voice_model_catalog::resolve_model('p', 'tts', $model, $now), $model);
        }
    }

    /** If even the default is retired the catalog is wrong; the configured id is sent rather than a guess. */
    public function test_an_inconsistent_catalog_sends_the_configured_model(): void {
        voice_model_catalog::set_data_for_testing(['p' => ['tts' => ['default' => 'a', 'models' => [
            'a' => ['status' => 'deprecated', 'shutdown' => '2026-01-01', 'replaced_by' => null],
        ]]]]);
        $this->assertSame('a', voice_model_catalog::resolve_model('p', 'tts', '', gmmktime(0, 0, 0, 3, 1, 2026)));
    }

    public function test_the_fallback_after_a_not_found_answer(): void {
        // A known deprecated model: its successor, even before the shutdown date (the provider already said no).
        $this->assertSame('gemini-3.8-flash-tts',
            voice_model_catalog::fallback_after_not_found('gemini', 'tts', 'gemini-3.1-flash-tts-preview', self::BEFORE));
        // An unknown model: the vetted default.
        $this->assertSame('gemini-3.8-flash-tts',
            voice_model_catalog::fallback_after_not_found('gemini', 'tts', 'gemini-typo-tts', self::BEFORE));
        // A current model with no successor, and the default itself: nothing to retry with.
        $this->assertNull(voice_model_catalog::fallback_after_not_found('gemini', 'tts', 'gemini-3.8-flash-lite-tts', self::AT));
        $this->assertNull(voice_model_catalog::fallback_after_not_found('gemini', 'tts', 'gemini-3.8-flash-tts', self::AT));
        $this->assertNull(voice_model_catalog::fallback_after_not_found('nobody', 'tts', 'x', self::AT));
    }

    public function test_model_not_found_detection(): void {
        $this->assertTrue(voice_model_catalog::is_model_not_found(404, ''));
        $this->assertTrue(voice_model_catalog::is_model_not_found(400,
            '{"error":{"message":"models/gemini-2.5-flash-preview-tts is not found for API version v1beta, or is not '
            . 'supported for generateContent.","status":"NOT_FOUND"}}'));
        $this->assertTrue(voice_model_catalog::is_model_not_found(400, '{"error":{"code":"model_not_found"}}'));
        $this->assertTrue(voice_model_catalog::is_model_not_found(400, '{"error":{"message":"Invalid model: tts-9"}}'));
        // Other 400s, and other statuses, are not about the model.
        $this->assertFalse(voice_model_catalog::is_model_not_found(400,
            '{"error":{"message":"Invalid value at \'generation_config.speech_config\' (voice name)","status":"INVALID_ARGUMENT"}}'));
        $this->assertFalse(voice_model_catalog::is_model_not_found(400, '{"model":"tts-1","error":"input not found"}'));
        $this->assertFalse(voice_model_catalog::is_model_not_found(401, 'model not found'));
        $this->assertFalse(voice_model_catalog::is_model_not_found(500, ''));
    }

    public function test_models_retiring_within_n_days(): void {
        $now = self::AT - 45 * 86400;
        $all = voice_model_catalog::retiring_within(45, $now);
        $names = array_column($all, 'model');
        $this->assertContains('gemini-2.5-flash-preview-tts', $names);
        $this->assertContains('gemini-3.1-flash-tts-preview', $names);
        $this->assertNotContains('gemini-2.5-flash-native-audio-preview-12-2025', $names, 'March 2027 is further away');
        $this->assertNotContains('gemini-3.8-flash-tts', $names, 'current models never appear');
        $row = $all[array_search('gemini-2.5-flash-preview-tts', $names)];
        $this->assertSame(45, $row['days_left']);
        $this->assertFalse($row['retired']);
        $this->assertSame('gemini-3.8-flash-tts', $row['resolves_to']);

        // One day short of the window: nothing.
        $this->assertSame([], voice_model_catalog::retiring_within(44, $now, [['gemini', 'tts', 'gemini-2.5-flash-preview-tts']]));
        // Limited to models in use, and already-past ones are still listed (marked retired).
        $inuse = voice_model_catalog::retiring_within(45, self::AT + 86400, [['gemini', 'tts', 'models/Gemini-2.5-Pro-Preview-TTS']]);
        $this->assertCount(1, $inuse);
        $this->assertSame('gemini-2.5-pro-preview-tts', $inuse[0]['model']);
        $this->assertTrue($inuse[0]['retired']);
        $this->assertSame(-1, $inuse[0]['days_left']);

        // The Live preview enters the window 45 days before 2027-03-31.
        $live = voice_model_catalog::retiring_within(45, gmmktime(0, 0, 0, 2, 14, 2027), [['gemini', 'realtime',
            'gemini-2.5-flash-native-audio-preview-12-2025']]);
        $this->assertCount(1, $live);
        $this->assertSame('gemini-3.8-live', $live[0]['resolves_to']);
    }

    public function test_test_data_cannot_be_left_behind_by_accident(): void {
        voice_model_catalog::set_data_for_testing(['p' => []]);
        $this->assertTrue(voice_model_catalog::has_provider('p'));
        voice_model_catalog::set_data_for_testing(null);
        $this->assertFalse(voice_model_catalog::has_provider('p'));
        $this->assertTrue(voice_model_catalog::has_provider('GEMINI'));
    }
}
