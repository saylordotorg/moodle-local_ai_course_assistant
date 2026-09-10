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
 * Spend must be attributed to the provider that actually served the call.
 *
 * Consumers used to re-derive the provider from course configuration, which is
 * what config said SHOULD have served the turn. Four things make that wrong in
 * production -- a premium-router escalation, 'auto' resolution, a spend-cap
 * failover, and the per-call failover chain -- and `by_provider` is the one key
 * the external spend dashboard reads, so a misattributed row moves real money
 * onto a vendor that never saw the request.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class spend_attribution_test extends \advanced_testcase {

    /**
     * Every provider derives its own id from its class name, matching the ids
     * base_provider::instantiate() switches on.
     */
    public function test_providers_report_their_own_id(): void {
        $this->resetAfterTest();

        $cases = [
            \local_ai_course_assistant\provider\openai_provider::class => 'openai',
            \local_ai_course_assistant\provider\gemini_provider::class => 'gemini',
            \local_ai_course_assistant\provider\claude_provider::class => 'claude',
            \local_ai_course_assistant\provider\mistral_provider::class => 'mistral',
        ];
        foreach ($cases as $class => $expected) {
            $p = new $class(['apikey' => 'x', 'model' => 'm']);
            $this->assertSame(
                $expected,
                $p->provider_id(),
                "{$class} must report '{$expected}', the id instantiate() switches on."
            );
        }
    }

    /**
     * The canonical usage array must name the serving provider, because that is
     * the single place both the streaming and non-streaming paths agree on.
     */
    public function test_usage_array_carries_the_serving_provider(): void {
        $this->resetAfterTest();

        $p = new \local_ai_course_assistant\provider\gemini_provider(
            ['apikey' => 'x', 'model' => 'gemini-2.5-flash']
        );
        $m = new \ReflectionMethod($p, 'shape_usage');
        $m->setAccessible(true);

        $usage = $m->invoke($p, ['prompt_tokens' => 10, 'completion_tokens' => 20], 'gemini-2.5-flash');

        $this->assertIsArray($usage);
        $this->assertArrayHasKey(
            'provider',
            $usage,
            'Without this, every consumer has to re-derive the provider from config, '
            . 'which is the defect: config says what should have served the turn.'
        );
        $this->assertSame('gemini', $usage['provider']);
    }

    /**
     * The failover wrapper must answer for the member that ran, not for itself.
     */
    public function test_failover_chain_does_not_report_itself_as_the_vendor(): void {
        $this->resetAfterTest();

        $src = file_get_contents(
            (new \ReflectionClass(\local_ai_course_assistant\provider\failover_chain::class))->getFileName()
        );
        $this->assertStringContainsString(
            'function provider_id',
            $src,
            "failover_chain must override provider_id(); the class-name default would "
            . "report 'failover_chain', a vendor that does not exist and cannot be priced."
        );
        $this->assertMatchesRegularExpression(
            '/lastused->provider_id\(\)/',
            $src,
            'It must delegate to the member that actually served the call.'
        );
    }

    /**
     * An admin-entered voice provider must never produce an interaction_type
     * outside the billable set.
     */
    public function test_voice_interaction_type_is_always_billable(): void {
        $this->resetAfterTest();

        $billable = ['voice', 'openai_tts', 'xai_tts', 'openai_whisper', 'openai_stt',
                     'xai_stt', 'selfhosted_stt'];

        // Known providers keep their specific type...
        $this->assertSame('openai_tts', voice_registry::interaction_type('openai', 'tts'));
        $this->assertSame('selfhosted_stt', voice_registry::interaction_type('selfhosted', 'stt'));

        // ...and anything an admin might type falls back to a listed type, never
        // to something the predicate silently drops.
        foreach (['elevenlabs', 'azure', 'deepgram', '', 'Openai', 'my-custom-tts'] as $typed) {
            foreach (['tts', 'stt'] as $kind) {
                $t = voice_registry::interaction_type($typed, $kind);
                $this->assertContains(
                    $t,
                    $billable,
                    "provider '{$typed}' ({$kind}) produced interaction_type '{$t}', which "
                    . 'analytics::spend_rows_predicate() does not admit -- the row would be '
                    . 'written, counted by nothing, and priced at zero.'
                );
            }
        }
    }

    /**
     * The analytics cap must cover scheduled Learning Radar runs.
     */
    public function test_analytics_cap_covers_scheduled_meta_rows(): void {
        $this->resetAfterTest();

        $m = new \ReflectionMethod(spend_guard::class, 'capability_sql');
        $m->setAccessible(true);
        $sql = $m->invoke(null, 'analytics');

        $this->assertStringContainsString(
            'meta_scheduled',
            $sql,
            'record_meta_query() writes meta_scheduled for cron runs, so matching only '
            . "'meta' let every scheduled Learning Radar call escape the analytics cap."
        );
    }

    /**
     * Self-hosted Whisper is free and must not match a priced rate-card prefix.
     */
    public function test_selfhosted_whisper_is_not_priced_as_hosted(): void {
        $this->resetAfterTest();

        $this->assertNotNull(
            token_cost_manager::estimate_cost('whisper-1', 1000, 0),
            'hosted whisper-1 is expected to be priced; if not, this test proves nothing'
        );
        $this->assertNull(
            token_cost_manager::estimate_cost('selfhosted-whisper-1', 1000, 0),
            'A self-hosted Whisper model must fall outside every rate prefix, or free '
            . 'transcription is billed at the hosted rate.'
        );

        // The above only proves the PREFIX is unpriced. It says nothing about
        // whether transcribe.php actually applies it -- and asserting the former
        // while believing it proves the latter is how a guard ends up passing
        // against the defect it exists to catch. So pin the endpoint's behaviour
        // too. transcribe.php is a request script rather than a unit, so this is
        // a source-level assertion by necessity.
        global $CFG;
        $src = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/transcribe.php');
        $this->assertNotFalse($src);

        $assign = strpos($src, "\$model = !empty(\$cfg['model'])");
        $this->assertNotFalse($assign, 'transcribe.php no longer resolves a model name');

        $prefix = strpos($src, "'selfhosted-' . \$model", $assign);
        $this->assertNotFalse(
            $prefix,
            'transcribe.php must record a self-hosted Whisper model under a prefix that '
            . 'matches no rate card. Pricing keys off model_name, NOT interaction_type, '
            . "so 'whisper-1' from a free self-hosted server was billed at the hosted rate."
        );
        $this->assertStringContainsString(
            'SELFHOSTED_LABEL',
            substr($src, $assign, $prefix - $assign + 200),
            'The prefix must be applied on the self-hosted branch specifically, not to '
            . 'every model, or hosted Whisper stops being priced at all.'
        );
    }
}
