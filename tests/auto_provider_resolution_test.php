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

use local_ai_course_assistant\provider\base_provider;

/**
 * "Auto" provider follows the configured model (v7.8.1).
 *
 * Before 7.8.1 'auto' plus any key meant openai, so a Gemini key and
 * gemini-2.5-flash sent chat to OpenAI, got a 401, and fell over to the backup
 * model without any sign of it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\base_provider
 */
final class auto_provider_resolution_test extends \advanced_testcase {
    /**
     * Call the private resolver.
     *
     * @param array $overrides
     * @return string
     */
    private function resolve(array $overrides): string {
        $m = new \ReflectionMethod(base_provider::class, 'resolve_auto_provider');
        $m->setAccessible(true);
        return $m->invoke(null, $overrides);
    }

    /**
     * Model names and the provider each one resolves to with a key present.
     *
     * @return array
     */
    public static function models_provider(): array {
        return [
            'gemini' => ['gemini-2.5-flash', 'gemini'],
            'claude' => ['claude-sonnet-5-5', 'claude'],
            'openai' => ['gpt-4o-mini', 'openai'],
            'openai o-series' => ['o3-mini', 'openai'],
            'unknown name keeps the historical default' => ['mystery-model-1', 'openai'],
            'empty keeps the historical default' => ['', 'openai'],
        ];
    }

    /**
     * @dataProvider models_provider
     * @param string $model
     * @param string $expected
     */
    public function test_key_plus_model_picks_the_makers_provider(string $model, string $expected): void {
        $this->resetAfterTest();
        set_config('model', '', 'local_ai_course_assistant');
        $this->assertSame($expected, $this->resolve(['apikey' => 'k-test-value', 'model' => $model]));
    }

    public function test_custom_base_url_keeps_openai_wire_format(): void {
        $this->resetAfterTest();
        $this->assertSame('openai', $this->resolve([
            'apikey' => 'k-test-value', 'model' => 'claude-sonnet-4-5', 'apibaseurl' => 'https://gateway.example/v1',
        ]));
    }

    public function test_site_model_is_used_when_no_override(): void {
        $this->resetAfterTest();
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');
        $this->assertSame('gemini', $this->resolve(['apikey' => 'k-test-value']));
    }

    public function test_course_model_wins_over_site_model(): void {
        $this->resetAfterTest();
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');
        $this->assertSame('claude', $this->resolve(['apikey' => 'k-test-value', 'model' => 'claude-haiku-4-5']));
    }
}
