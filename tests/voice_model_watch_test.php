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

use local_ai_course_assistant\autoupgrade\discovery;

/**
 * The daily voice model watch (8.1.0), with fake provider lists and a fixed clock. No network.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\voice_model_watch
 * @covers     \local_ai_course_assistant\task\voice_model_watch
 * @covers     \local_ai_course_assistant\autoupgrade\notifier
 */
final class voice_model_watch_test extends \advanced_testcase {
    /** @var int 2026-11-21 00:00 UTC: 27 days before the Gemini TTS previews shut down. */
    private const NOW = 1795219200;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        set_config('spend_notify_emails', 'voice-admin@example.com', 'local_ai_course_assistant');
    }

    /**
     * A Gemini model list as Google's native endpoint shapes it.
     *
     * @param array $models name => generation methods
     * @return string
     */
    private function gemini_list(array $models): string {
        $out = [];
        foreach ($models as $name => $methods) {
            $out[] = ['name' => 'models/' . $name, 'supportedGenerationMethods' => $methods];
        }
        return json_encode(['models' => $out]);
    }

    /**
     * An email body as one line of plain text: quoted-printable decoded, Moodle's line wrapping undone.
     *
     * @param string $body
     * @return string
     */
    private function text(string $body): string {
        return trim(preg_replace('~\s+~', ' ', quoted_printable_decode($body)));
    }

    private function row(string $provider, string $model = ''): array {
        return ['provider' => $provider, 'apikey' => 'x', 'label' => $provider, 'realtime_voice' => '', 'tts_voice' => '',
            'tts_model' => $model];
    }

    public function test_the_constant_is_the_date_it_claims(): void {
        $this->assertSame('2026-11-21 00:00:00', gmdate('Y-m-d H:i:s', self::NOW));
    }

    public function test_models_in_use(): void {
        $inuse = voice_model_watch::models_in_use([
            $this->row('gemini', 'Gemini-2.5-Flash-Preview-TTS'),
            $this->row('openai'),
            $this->row('xai'),
            $this->row('openai'),                  // A duplicate is listed once.
            $this->row('elevenlabs', 'whatever'),  // Not in the catalog: nothing to watch.
        ]);
        $this->assertSame([
            ['gemini', 'tts', 'gemini-2.5-flash-preview-tts'],
            ['openai', 'tts', 'tts-1'],
            ['openai', 'realtime', 'gpt-realtime-2.1-mini'],
            ['xai', 'tts', 'grok-tts'],
        ], $inuse, 'a Gemini row is TTS only, so its Live model is not in use');
        $this->assertSame([], voice_model_watch::models_in_use([]));
    }

    public function test_a_model_in_use_within_45_days_of_shutdown_is_a_warning(): void {
        $inuse = [['gemini', 'tts', 'gemini-2.5-flash-preview-tts'], ['openai', 'tts', 'tts-1']];
        $result = voice_model_watch::check($inuse, [], self::NOW);
        $this->assertCount(1, $result['warnings']);
        $this->assertSame('gemini-2.5-flash-preview-tts', $result['warnings'][0]['model']);
        $this->assertSame(27, $result['warnings'][0]['days_left']);
        $this->assertSame('gemini-3.8-flash-tts', $result['warnings'][0]['resolves_to']);
        $this->assertSame([], $result['missing'], 'no provider list was read, so nothing is called missing');

        // 46 days out: not yet.
        $early = gmmktime(0, 0, 0, 11, 2, 2026);
        $this->assertSame([], voice_model_watch::check($inuse, [], $early)['warnings']);
        // A model not in use is never reported, however close its date.
        $this->assertSame([], voice_model_watch::check([['gemini', 'tts', 'gemini-3.8-flash-tts']], [], self::NOW)['warnings']);
    }

    public function test_a_catalog_model_the_provider_no_longer_lists_is_flagged(): void {
        $inuse = [
            ['gemini', 'tts', 'gemini-2.5-flash-preview-tts'],
            ['openai', 'tts', 'tts-1'],
            ['openai', 'realtime', 'gpt-realtime-mini'],
        ];
        $listed = [
            // Google still lists the old preview but not the successor the site would move to.
            'gemini' => ['gemini-2.5-flash-preview-tts', 'gemini-3.8-flash-lite-tts'],
            // 8.2.0: gpt-realtime-mini's successor is listed too, so only the Gemini gap is a finding.
            'openai' => ['tts-1', 'gpt-realtime-mini', 'gpt-realtime-2.1-mini'],
        ];
        $missing = voice_model_watch::check($inuse, $listed, self::NOW)['missing'];
        $this->assertSame([[
            'provider' => 'gemini', 'capability' => 'tts', 'model' => 'gemini-3.8-flash-tts', 'configured' => false,
        ]], $missing);

        // The configured model gone from the list is flagged as configured.
        $missing = voice_model_watch::check([['openai', 'tts', 'tts-1']], ['openai' => ['gpt-realtime-mini']], self::NOW)['missing'];
        $this->assertTrue($missing[0]['configured']);

        // A retired model missing from the list is expected, not a finding; an unknown model is not judged.
        $after = gmmktime(0, 0, 0, 1, 1, 2027);
        $this->assertSame([], voice_model_watch::check([['gemini', 'tts', 'gemini-2.5-flash-preview-tts'],
            ['gemini', 'tts', 'gemini-9-tts']], ['gemini' => ['gemini-3.8-flash-tts']], $after)['missing']);
    }

    public function test_the_provider_list_keeps_live_models_and_never_puts_the_key_in_the_url(): void {
        $seen = [];
        $http = function (string $url, array $headers) use (&$seen) {
            $seen[] = [$url, $headers];
            if (str_contains($url, 'generativelanguage')) {
                return [200, $this->gemini_list([
                    'gemini-3.8-flash-tts' => ['generateContent'],
                    'gemini-3.8-live' => ['bidiGenerateContent'],
                    'gemini-2.5-flash' => ['generateContent', 'countTokens'],
                ])];
            }
            return [500, ''];
        };
        $inuse = [['gemini', 'tts', 'gemini-3.8-flash-tts'], ['openai', 'tts', 'tts-1'], ['xai', 'tts', 'grok-tts']];
        $out = voice_model_watch::list_provider_models($inuse, $http, [
            'gemini' => ['apikey' => 'AIzaWATCHKEY', 'baseurl' => ''],
            'openai' => ['apikey' => 'sk-proj-WATCHKEY', 'baseurl' => ''],
            'xai' => ['apikey' => 'xai-WATCHKEY', 'baseurl' => ''],
        ]);
        $this->assertSame(['gemini-2.5-flash', 'gemini-3.8-flash-tts', 'gemini-3.8-live'], $out['listed']['gemini']);
        $this->assertArrayNotHasKey('openai', $out['listed']);
        $this->assertArrayHasKey('openai', $out['errors'], 'a failed list is an error, not an empty list');
        $this->assertStringNotContainsString('WATCHKEY', implode(' ', $out['errors']));
        $this->assertCount(2, $seen, 'xAI is not listed');
        foreach ($seen as [$url, $headers]) {
            $this->assertStringNotContainsString('WATCHKEY', $url);
        }
        $this->assertContains('x-goog-api-key: AIzaWATCHKEY', $seen[0][1]);

        // The chat discovery still drops non-generateContent models.
        $this->assertSame(['gemini-3.8-flash-tts'],
            discovery::parse_list('gemini', $this->gemini_list(['gemini-3.8-flash-tts' => ['generateContent'],
                'gemini-3.8-live' => ['bidiGenerateContent']])));
    }

    public function test_a_gateway_key_or_an_empty_list_is_not_read_as_the_providers_catalog(): void {
        $calls = 0;
        $http = function () use (&$calls) {
            $calls++;
            return [200, json_encode(['models' => []])];
        };
        $inuse = [['gemini', 'tts', 'gemini-3.8-flash-tts'], ['openai', 'tts', 'tts-1']];
        $out = voice_model_watch::list_provider_models($inuse, $http, [
            'gemini' => ['apikey' => 'k', 'baseurl' => ''],
            'openai' => ['apikey' => 'k', 'baseurl' => 'https://gateway.example.org/v1'],
        ]);
        $this->assertSame(1, $calls, 'the gateway row is skipped');
        $this->assertSame([], $out['listed'], 'an empty list proves nothing');
        $this->assertSame('empty model list', $out['errors']['gemini']);
    }

    public function test_findings_are_emailed_once_each(): void {
        $sink = $this->redirectEmails();
        $result = voice_model_watch::check([['gemini', 'tts', 'gemini-2.5-flash-preview-tts']],
            ['gemini' => ['gemini-2.5-flash-preview-tts']], self::NOW);
        $this->assertSame(2, voice_model_watch::notify_new($result, self::NOW), 'a warning and a missing successor');
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages, 'one email for all findings');
        $this->assertSame('voice-admin@example.com', $messages[0]->to);
        $body = $this->text($messages[0]->body);
        $this->assertStringContainsString('gemini-2.5-flash-preview-tts on 2026-12-18 (in 27 days)', $body);
        $this->assertStringContainsString('calls go to gemini-3.8-flash-tts', $body);
        $this->assertStringContainsString('no longer lists gemini-3.8-flash-tts', $body);
        $this->assertStringNotContainsString('[[', $body, 'brand tokens resolved');
        $sink->clear();

        // The next day: nothing new, nothing sent.
        $this->assertSame(0, voice_model_watch::notify_new($result, self::NOW + DAYSECS));
        $this->assertCount(0, $sink->get_messages());

        // Once the date passes the finding changes (retired), so it is reported once more.
        $after = voice_model_watch::check([['gemini', 'tts', 'gemini-2.5-flash-preview-tts']], [], gmmktime(0, 0, 0, 12, 19, 2026));
        $this->assertSame(1, voice_model_watch::notify_new($after, gmmktime(0, 0, 0, 12, 19, 2026)));
        $this->assertStringContainsString('shut down gemini-2.5-flash-preview-tts on 2026-12-18. Calls now go to gemini-3.8-flash-tts',
            $this->text($sink->get_messages()[0]->body));
        $sink->close();
    }

    public function test_run_reads_the_sites_rows_and_changes_no_setting(): void {
        set_config('voice_providers', 'gemini|' . secrets::conceal('AIzaRUNKEY-1234') . '|google||Kore|gemini-3.1-flash-tts-preview',
            'local_ai_course_assistant');
        $before = get_config('local_ai_course_assistant');
        $sink = $this->redirectEmails();
        $http = fn(string $url, array $headers) => [200, $this->gemini_list([
            'gemini-3.1-flash-tts-preview' => ['generateContent'],
            'gemini-3.8-flash-tts' => ['generateContent'],
        ])];
        $summary = voice_model_watch::run(self::NOW, $http, ['gemini' => ['apikey' => 'AIzaRUNKEY-1234', 'baseurl' => '']]);
        $this->assertSame([['gemini', 'tts', 'gemini-3.1-flash-tts-preview']], $summary['inuse']);
        $this->assertCount(1, $summary['warnings']);
        $this->assertSame([], $summary['missing']);
        $this->assertSame(1, $summary['reported']);
        $this->assertCount(1, $sink->get_messages());
        $sink->close();

        // Only the once-flags were added; no voice setting or model was switched.
        $after = (array) get_config('local_ai_course_assistant');
        foreach ((array) $before as $name => $value) {
            $this->assertSame($value, $after[$name], $name);
        }
        $new = array_diff(array_keys($after), array_keys((array) $before));
        foreach ($new as $name) {
            $this->assertStringStartsWith('voice_model_watch_', $name);
        }
    }

    public function test_the_scheduled_task_is_registered_daily_and_quiet_without_voice(): void {
        $task = \core\task\manager::get_scheduled_task(task\voice_model_watch::class);
        $this->assertNotEmpty($task);
        $this->assertSame('*', $task->get_day());
        $this->assertSame('3', $task->get_hour());
        $this->assertNotEmpty($task->get_name());
        $this->expectOutputRegex('/no voice provider is configured/');
        (new task\voice_model_watch())->execute();
    }

    public function test_a_refused_model_fallback_is_audited_and_emailed_once(): void {
        global $DB;
        $sink = $this->redirectEmails();
        voice_model_watch::record_fallback('gemini', 'tts', 'gemini-2.5-flash-preview-tts', 'gemini-3.8-flash-tts', true, 0);
        $this->assertDebuggingCalled();
        voice_model_watch::record_fallback('gemini', 'tts', 'gemini-2.5-flash-preview-tts', 'gemini-3.8-flash-tts', true, 0);
        $this->assertDebuggingCalled();
        $this->assertSame(2, $DB->count_records('local_ai_course_assistant_audit', ['action' => 'voice_model_fallback']));
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages, 'the second refusal sends no second email');
        $this->assertStringContainsString('gemini-2.5-flash-preview-tts', $messages[0]->subject);
        $this->assertStringContainsString('retried on gemini-3.8-flash-tts', $this->text($messages[0]->body));
        $details = json_decode($DB->get_field('local_ai_course_assistant_audit', 'details',
            ['action' => 'voice_model_fallback'], IGNORE_MULTIPLE), true);
        $this->assertSame('rescued', $details['outcome']);
        $sink->close();
    }
}
