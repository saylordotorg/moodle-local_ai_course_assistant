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

namespace local_ai_course_assistant\provider;

/**
 * v6.0.1 regression test for the v5.11.0 Claude temperature deny-list.
 *
 * Anthropic deprecated the `temperature` parameter on reasoning-class Opus
 * models. Sending it returns HTTP 400 "temperature is deprecated for this
 * model" — and the SSE write function swallows the error body, so the
 * failure surfaced as the generic "Sorry, something went wrong" string.
 *
 * v5.11.0 added a per-prefix deny-list (`model_supports_temperature()`)
 * that skips the temperature parameter for matching models. This test
 * locks the deny-list contents and behaviour so a future refactor can't
 * accidentally re-enable temperature on reasoning models and reintroduce
 * the silent-error regression.
 *
 * The method is private; we use reflection to invoke it because the
 * deny-list contract is what callers depend on, not the surface API.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\claude_provider
 */
final class claude_provider_temperature_denylist_test extends \advanced_testcase {

    /**
     * Invoke the private static `model_supports_temperature($model)` via
     * reflection. The method is private by design (callers go through
     * `chat_completion`); the test pins the contract.
     *
     * @param string $model Model identifier to test.
     * @return bool Whether the model supports a temperature parameter.
     */
    private function supports(string $model): bool {
        $r = new \ReflectionClass(claude_provider::class);
        $m = $r->getMethod('model_supports_temperature');
        $m->setAccessible(true);
        return (bool) $m->invoke(null, $model);
    }

    public function test_opus_4_7_rejects_temperature(): void {
        $this->assertFalse($this->supports('claude-opus-4-7'));
    }

    public function test_opus_4_8_rejects_temperature(): void {
        $this->assertFalse($this->supports('claude-opus-4-8'));
    }

    public function test_opus_4_9_rejects_temperature_when_anthropic_ships_it(): void {
        // Pre-staged in the deny-list to match Anthropic's pattern. If a
        // future release breaks this, the deny-list isn't pre-empting
        // the next reasoning model.
        $this->assertFalse($this->supports('claude-opus-4-9'));
    }

    public function test_opus_4_7_with_date_suffix_still_rejected(): void {
        // Anthropic uses both bare names (claude-opus-4-8) and date-stamped
        // aliases (claude-opus-4-8-20251101). Prefix match must catch both.
        $this->assertFalse($this->supports('claude-opus-4-7-20251015'));
        $this->assertFalse($this->supports('claude-opus-4-8-20251101'));
    }

    public function test_older_opus_4_1_still_accepts_temperature(): void {
        // Pre-reasoning Opus models — temperature is fine.
        $this->assertTrue($this->supports('claude-opus-4-1'));
    }

    public function test_opus_4_5_still_accepts_temperature(): void {
        $this->assertTrue($this->supports('claude-opus-4-5'));
    }

    public function test_sonnet_models_accept_temperature(): void {
        $this->assertTrue($this->supports('claude-sonnet-4-6'));
        $this->assertTrue($this->supports('claude-sonnet-4-5'));
    }

    public function test_haiku_models_accept_temperature(): void {
        $this->assertTrue($this->supports('claude-haiku-4-5'));
        $this->assertTrue($this->supports('claude-haiku-3-5'));
    }

    public function test_legacy_claude_3_models_accept_temperature(): void {
        $this->assertTrue($this->supports('claude-3-opus-20240229'));
        $this->assertTrue($this->supports('claude-3-5-sonnet-20241022'));
    }

    public function test_empty_model_defaults_to_supports_temperature(): void {
        // Defensive: a misconfigured row with empty model should not
        // accidentally trip the deny-list. Default to supporting temp.
        $this->assertTrue($this->supports(''));
    }
}
