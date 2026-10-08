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

use local_ai_course_assistant\autoupgrade\candidates;
use local_ai_course_assistant\autoupgrade\discovery;
use local_ai_course_assistant\autoupgrade\roles;

/**
 * v7.8.0 model discovery: listing, registering and marking candidates.
 *
 * The provider lists are replayed from the shapes each vendor returns. What
 * matters most is what is NOT marked: a model with no exact price, a preview,
 * an embedding model, a model priced far outside the current one's band, or a
 * model from another provider.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\autoupgrade\discovery
 * @covers     \local_ai_course_assistant\autoupgrade\candidates
 */
final class autoupgrade_discovery_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        model_registry::reset_cache();
        model_capabilities::reset_cache();
    }

    /**
     * Price a model in the registry as an admin would.
     *
     * @param string $key
     * @param float $in
     * @param float $out
     * @return void
     */
    private function price(string $key, float $in, float $out): void {
        model_registry::upsert(['modelkey' => $key, 'input_rate' => $in, 'output_rate' => $out], 'manual', 2);
    }

    /**
     * Fake HTTP: a body per URL fragment, and a log of headers sent.
     *
     * @param array $bodies
     * @param array $seen
     * @return callable
     */
    private function http(array $bodies, array &$seen): callable {
        return function (string $url, array $headers) use ($bodies, &$seen) {
            $seen[] = ['url' => $url, 'headers' => $headers];
            foreach ($bodies as $fragment => $body) {
                if (str_contains($url, $fragment)) {
                    return [200, json_encode($body)];
                }
            }
            return [404, ''];
        };
    }

    public function test_each_vendor_list_shape_is_parsed(): void {
        $this->assertSame(
            ['gpt-4o-mini', 'gpt-6-luna'],
            discovery::parse_list('openai', json_encode(['data' => [['id' => 'gpt-6-luna'], ['id' => 'gpt-4o-mini']]]))
        );
        $this->assertSame(['gemini-2.5-flash'], discovery::parse_list('gemini', json_encode(['models' => [
            ['name' => 'models/gemini-2.5-flash', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/text-embedding-004', 'supportedGenerationMethods' => ['embedContent']],
        ]])));
        $this->assertSame(
            ['openai/gpt-oss-120b'],
            discovery::parse_list('together', json_encode([['id' => 'openai/gpt-oss-120b']])),
            'Together returns a bare list.'
        );
        $this->assertSame([], discovery::parse_list('openai', 'not json'));
    }

    public function test_a_price_counts_only_when_it_is_this_models(): void {
        $this->price('claude-haiku', 1.0, 5.0);
        $this->price('gpt-5-mini', 0.25, 2.0);
        $this->price('gemini/gemini-3.5-flash-lite', 0.30, 2.50);
        $this->assertNull(
            discovery::known_price('claude', 'claude-haiku-5-5'),
            'A family catch-all priced claude-haiku-5-5 at ten times its real rate.'
        );
        $this->assertSame(
            'gpt-5-mini',
            discovery::known_price('openai', 'gpt-5-mini-2025-08-07')['key'],
            'A dated snapshot is the same model.'
        );
        $this->assertNull(discovery::known_price('openai', 'gpt-5-mini-turbo'));
        $this->assertSame(
            'gemini/gemini-3.5-flash-lite',
            discovery::known_price('gemini', 'gemini-3.5-flash-lite')['key'],
            'The LiteLLM spelling counts for the provider that uses it.'
        );
    }

    public function test_a_listed_model_that_cannot_be_called_is_not_a_candidate(): void {
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');
        set_config('provider', 'gemini', 'local_ai_course_assistant');
        set_config('comparison_providers', "gemini|AIzaSECRETKEY1234567890|gemini-2.5-flash", 'local_ai_course_assistant');
        $this->price('gemini/gemini-2.5-flash', 0.30, 2.50);
        $this->price('gemini/gemini-3.5-flash-lite', 0.30, 2.50);
        $this->price('gemini/gemini-3.6-flash', 0.30, 2.50);
        $probed = [];
        $probe = function (string $provider, string $model) use (&$probed): bool {
            $probed[] = $model;
            return $model !== 'gemini-3.5-flash-lite';
        };
        $seen = [];
        $http = $this->http([
            'generativelanguage' => ['models' => array_map(function ($id) {
                return ['name' => 'models/' . $id, 'supportedGenerationMethods' => ['generateContent']];
            }, ['gemini-2.5-flash', 'gemini-3.5-flash-lite', 'gemini-3.6-flash'])],
        ], $seen);
        $summary = (new discovery($http, $probe))->run();
        $chat = $summary['candidates'][roles::CHAT];
        $this->assertNotContains('gemini-3.5-flash-lite', $chat, 'A model the API refuses is not offered.');
        $this->assertContains('gemini-3.6-flash', $chat);
        $this->assertContains('gemini-3.5-flash-lite', $probed);
        $this->assertNotContains('gemini-2.5-flash', $probed, 'The current model is never probed.');
    }

    public function test_run_registers_marks_and_never_leaks_a_key(): void {
        global $DB;
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');
        set_config('provider', 'gemini', 'local_ai_course_assistant');
        set_config(
            'comparison_providers',
            "gemini|AIzaSECRETKEY1234567890|gemini-2.5-flash\nopenai|sk-SECRETKEY123|gpt-4o-mini",
            'local_ai_course_assistant'
        );
        set_config('spend_failover_chain', 'chat:openai', 'local_ai_course_assistant');
        $this->price('gemini/gemini-2.5-flash', 0.30, 2.50);
        $this->price('gemini/gemini-3.5-flash-lite', 0.30, 2.50);
        $this->price('gemini/gemini-3.8-flash', 0.75, 3.75);
        $this->price('gemini/gemini-2.0-flash-lite', 0.075, 0.30);
        $this->price('gpt-4o-mini', 0.15, 0.60);
        $this->price('gpt-6-luna', 0.10, 0.50);
        $this->price('gpt-5.4', 2.50, 15.0);

        $seen = [];
        $http = $this->http([
            'generativelanguage' => ['models' => array_map(function ($id) {
                return ['name' => 'models/' . $id, 'supportedGenerationMethods' => ['generateContent']];
            }, ['gemini-2.5-flash', 'gemini-3.5-flash-lite', 'gemini-3.8-flash', 'gemini-2.0-flash-lite',
                'gemini-3-flash-preview', 'gemini-2.5-flash-preview-tts', 'gemini-9-unpriced'])],
            'api.openai.com' => ['data' => [['id' => 'gpt-4o-mini'], ['id' => 'gpt-6-luna'], ['id' => 'gpt-5.4'],
                ['id' => 'text-embedding-3-small'], ['id' => 'gpt-4o-mini-2024-07-18']]],
        ], $seen);
        $summary = (new discovery($http))->run();

        $chat = $summary['candidates'][roles::CHAT];
        $this->assertContains('gemini-3.5-flash-lite', $chat);
        $this->assertContains('gemini-2.5-flash [off]', $chat, 'Thinking off is a configuration variant.');
        $this->assertNotContains('gemini-3.8-flash', $chat, '2.1x the list price is outside the band.');
        $this->assertNotContains('gemini-2.0-flash-lite', $chat, 'Under a fifth of the price is a different class.');
        $this->assertNotContains('gemini-3-flash-preview', $chat, 'Previews are never candidates.');
        $this->assertNotContains('gemini-9-unpriced', $chat, 'No candidate without a known price.');
        $this->assertSame(
            ['gpt-6-luna'],
            $summary['candidates'][roles::FAILOVER],
            'Same provider as the failover model; gpt-5.4 is far outside the band.'
        );

        // The Gemini model priced only under the LiteLLM key is registered under
        // its own id with that price, so its calls are not billed as free.
        $row = $DB->get_record(model_registry::TABLE_MODELS, ['modelkey' => 'gemini-3.5-flash-lite']);
        $this->assertEqualsWithDelta(0.30, (float) $row->input_rate, 1e-9);
        $this->assertSame('upstream', $row->source);

        foreach ($summary as $part) {
            $this->assertStringNotContainsString('SECRETKEY', json_encode($part));
        }
        $this->assertStringNotContainsString(
            'SECRETKEY',
            (string) get_config('local_ai_course_assistant', 'autoupgrade_last_discovery_result')
        );
        $this->assertStringNotContainsString('SECRETKEY', json_encode($DB->get_records(candidates::TABLE)));
        // Keys travel only as request headers, never in the URL.
        foreach ($seen as $request) {
            $this->assertStringNotContainsString('SECRETKEY', $request['url']);
        }
    }

    public function test_only_rows_discovery_wrote_get_their_price_refreshed(): void {
        global $DB;
        // A LiteLLM row for the same id, written by the price feed: not ours.
        model_registry::upsert(['modelkey' => 'gemini-3.1-flash-lite', 'input_rate' => 0.11, 'output_rate' => 0.22], 'upstream');
        $this->price('gemini/gemini-3.1-flash-lite', 0.25, 1.50);
        $discovery = new discovery(fn() => [200, '']);
        $this->assertFalse($discovery->register('gemini', 'gemini-3.1-flash-lite'));
        $this->assertEqualsWithDelta(0.11, (float) $DB->get_field(
            model_registry::TABLE_MODELS,
            'input_rate',
            ['modelkey' => 'gemini-3.1-flash-lite']
        ), 1e-9, 'A row discovery did not write is left alone.');

        // A row discovery wrote follows its rate card entry when it changes.
        $this->price('gemini/gemini-3.5-flash-lite', 0.30, 2.50);
        $this->assertTrue($discovery->register('gemini', 'gemini-3.5-flash-lite'));
        $this->price('gemini/gemini-3.5-flash-lite', 0.40, 3.00);
        $this->assertTrue($discovery->register('gemini', 'gemini-3.5-flash-lite'));
        $this->assertEqualsWithDelta(0.40, (float) $DB->get_field(
            model_registry::TABLE_MODELS,
            'input_rate',
            ['modelkey' => 'gemini-3.5-flash-lite']
        ), 1e-9);

        // An admin's own row is never touched.
        $this->price('gemini-3.6-flash', 0.10, 0.20);
        $this->price('gemini/gemini-3.6-flash', 0.75, 3.75);
        $this->assertFalse($discovery->register('gemini', 'gemini-3.6-flash'));
        $this->assertEqualsWithDelta(0.10, (float) $DB->get_field(
            model_registry::TABLE_MODELS,
            'input_rate',
            ['modelkey' => 'gemini-3.6-flash']
        ), 1e-9);
    }

    public function test_the_current_model_under_its_snapshot_name_is_not_a_candidate(): void {
        $this->price('claude-haiku-4-5', 1.0, 5.0);
        $this->price('claude-haiku-4-5-20251001', 1.0, 5.0);
        $marked = discovery::mark_candidates(
            'quiz',
            ['provider' => 'claude', 'model' => 'claude-haiku-4-5', 'variant' => ''],
            ['claude-haiku-4-5-20251001']
        );
        $this->assertSame([], $marked);
    }

    public function test_no_candidates_when_the_current_model_has_no_price(): void {
        $this->price('gpt-6-luna', 0.10, 0.50);
        $marked = discovery::mark_candidates(
            roles::FAILOVER,
            ['provider' => 'openai', 'model' => 'unpriced-model', 'variant' => ''],
            ['gpt-6-luna']
        );
        $this->assertSame([], $marked);
    }

    public function test_a_model_that_disappears_is_retired_and_comes_back(): void {
        global $DB;
        $this->price('gpt-4o-mini', 0.15, 0.60);
        $this->price('gpt-6-luna', 0.10, 0.50);
        $current = ['provider' => 'openai', 'model' => 'gpt-4o-mini', 'variant' => ''];
        discovery::mark_candidates(roles::FAILOVER, $current, ['gpt-6-luna']);
        discovery::mark_candidates(roles::FAILOVER, $current, []);
        $this->assertSame(candidates::RETIRED, $DB->get_field(candidates::TABLE, 'status', ['model' => 'gpt-6-luna']));
        discovery::mark_candidates(roles::FAILOVER, $current, ['gpt-6-luna']);
        $this->assertSame(candidates::CANDIDATE, $DB->get_field(candidates::TABLE, 'status', ['model' => 'gpt-6-luna']));
    }
}
