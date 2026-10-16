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

use core\check\result;
use local_ai_course_assistant\check\failover_providers;
use local_ai_course_assistant\provider\provider_http_exception;

/**
 * Checks on the failover backups (v7.8.8).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\failover_verifier
 * @covers     \local_ai_course_assistant\check\failover_providers
 */
final class failover_verifier_test extends \advanced_testcase {
    /**
     * Configure the chain and the rows.
     *
     * @param string $rows comparison_providers text.
     * @param string $chain spend_failover_chain text.
     */
    private function configure(string $rows, string $chain): void {
        set_config('comparison_providers', $rows, 'local_ai_course_assistant');
        set_config('spend_failover_chain', $chain, 'local_ai_course_assistant');
    }

    /**
     * Write a recorded probe.
     *
     * @param string $label
     * @param bool $ok
     * @param int $age Seconds ago.
     */
    private function probe_row(string $label, bool $ok, int $age): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_audit', (object) [
            'action' => failover_verifier::AUDIT_EVENT_PROBE, 'userid' => 0, 'courseid' => 0,
            'ipaddress' => '', 'useragent' => '',
            'details' => json_encode(['label' => $label, 'model' => 'gpt-4o-mini', 'ok' => $ok,
                'status' => $ok ? 0 : 404, 'detail' => $ok ? '' : 'HTTP 404: model not found', 'ms' => 12]),
            'timecreated' => time() - $age,
        ]);
    }

    public function test_a_clean_setup_has_no_problems(): void {
        $this->resetAfterTest();
        $this->configure("openai|sk-test-key-1|gpt-4o-mini||\nclaude|sk-ant-test-2|claude-haiku-4-5||", "chat:openai\nvoice:openai");
        set_config('failover_per_call_enabled', 1, 'local_ai_course_assistant');
        $this->assertSame([], failover_verifier::static_problems());
    }

    public function test_a_chain_line_with_no_row_is_reported(): void {
        $this->resetAfterTest();
        $this->configure("openai|sk-test-key-1|gpt-4o-mini||", "chat:openai\nchat:xai");
        $problems = failover_verifier::static_problems();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('chat:xai', $problems[0]);
        $this->assertStringContainsString('no row', $problems[0]);
    }

    public function test_a_row_without_a_key_is_reported(): void {
        $this->resetAfterTest();
        $this->configure("openai||gpt-4o-mini||", "chat:openai");
        $problems = failover_verifier::static_problems();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('no API key', $problems[0]);
    }

    public function test_a_display_name_model_is_reported_with_the_fix(): void {
        $this->resetAfterTest();
        $this->configure("openai|sk-test-key-1|GPT-4o-mini||", "chat:openai");
        $problems = failover_verifier::static_problems();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('"GPT-4o-mini"', $problems[0]);
        $this->assertStringContainsString('gpt-4o-mini', $problems[0]);
        $this->configure("openai|sk-test-key-1|GPT 4o mini||", "chat:openai");
        $this->assertStringContainsString('gpt-4o-mini', failover_verifier::static_problems()[0]);
    }

    public function test_a_custom_base_url_may_use_any_model_spelling(): void {
        $this->resetAfterTest();
        $this->configure("openai|sk-test-key-1|My-Deployment||https://example.openai.azure.com", "chat:openai");
        $this->assertSame([], failover_verifier::static_problems());
    }

    public function test_display_name_problem_flags_a_capitalised_hosted_model_only(): void {
        $this->assertStringContainsString('gpt-4o-mini', failover_verifier::display_name_problem('openai', 'GPT-4o-mini'));
        $this->assertSame('', failover_verifier::display_name_problem('openai', 'gpt-4o-mini'));
        $this->assertSame('', failover_verifier::display_name_problem('openrouter', 'Meta Llama'));
        $this->assertSame('', failover_verifier::display_name_problem('openai', 'GPT-4o', 'https://gateway.example'));
    }

    public function test_display_name_problem_covers_every_hosted_provider_and_no_other(): void {
        foreach (['openai', 'claude', 'gemini', 'mistral', 'deepseek', 'xai'] as $provider) {
            $this->assertNotSame('', failover_verifier::display_name_problem($provider, 'Some Model'), $provider);
        }
        foreach (['ollama', 'openrouter', 'together', 'minimax', 'coreai', 'custom', ''] as $provider) {
            $this->assertSame('', failover_verifier::display_name_problem($provider, 'Some Model'), "'{$provider}'");
        }
        $this->assertSame('', failover_verifier::display_name_problem('openai', ''), 'no model, nothing to check');
    }

    public function test_display_name_problem_names_the_provider_the_model_and_the_fix(): void {
        $message = failover_verifier::display_name_problem('openai', 'GPT 4o mini');
        $this->assertStringContainsString('openai', $message);
        $this->assertStringContainsString('"GPT 4o mini"', $message);
        $this->assertStringContainsString('gpt-4o-mini', $message);
        $this->assertSame($message, failover_verifier::display_name_problem('OpenAI', 'GPT 4o mini'), 'case blind');
    }

    /**
     * The runtime serves a label from the first row WITH a key; a keyless row listed before it is not the answer.
     */
    public function test_a_keyless_row_before_a_keyed_one_with_the_same_label_is_not_a_problem(): void {
        $this->resetAfterTest();
        $this->configure("openai||gpt-4o-mini||\nopenai|sk-test-key-1|gpt-4o-mini||", "chat:openai");
        $this->assertSame([], failover_verifier::static_problems());
        $this->configure("openai||gpt-4o-mini||\nopenai||gpt-4o-mini||", "chat:openai");
        $this->assertCount(1, failover_verifier::static_problems(), 'no row has a key');
    }

    /**
     * A fixed or removed backup must not keep showing its old failed probe.
     */
    public function test_results_for_a_removed_label_or_a_corrected_model_are_not_reported_as_current(): void {
        $this->resetAfterTest();
        $this->configure("openai|sk-test-key-1|gpt-4o-mini||", "chat:openai");
        $this->probe_row('openai', false, 60);
        $this->probe_row('gone', false, 60);
        $all = array_keys(failover_verifier::latest_results());
        sort($all);
        $this->assertSame(['gone', 'openai'], $all, 'unfiltered: both');
        $this->assertSame(['openai'], array_keys(failover_verifier::latest_results(null, true)), 'a removed label is dropped');

        // The failed probe was for gpt-4o-mini; the admin has since corrected the row to another model.
        $this->configure("openai|sk-test-key-1|gpt-4.1-mini||", "chat:openai");
        $this->assertSame([], failover_verifier::latest_results(null, true));
        $check = (new failover_providers())->get_result();
        $this->assertSame(result::INFO, $check->get_status(), 'unverified, not an error, until the next probe');
    }

    public function test_per_call_failover_with_no_resolvable_backup_is_reported(): void {
        $this->resetAfterTest();
        set_config('failover_per_call_enabled', 1, 'local_ai_course_assistant');
        $this->configure("openai|sk-test-key-1|gpt-4o-mini||", "voice:openai");
        $problems = failover_verifier::static_problems();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('no backup', $problems[0]);
        set_config('failover_per_call_enabled', 0, 'local_ai_course_assistant');
        $this->assertSame([], failover_verifier::static_problems());
    }

    /**
     * The point of building the probe with the failover's own constructor: the
     * model the probe sends is the row's text, byte for byte. The admin picker
     * did not, which is how a capitalised row passed it and failed in production.
     */
    public function test_the_probe_uses_the_rows_model_exactly_as_typed_and_records_the_outcome(): void {
        global $DB;
        $this->resetAfterTest();
        $this->configure("openai|sk-test-key-1|GPT-4o-mini||", "chat:openai");
        $seen = [];
        $results = failover_verifier::probe_all(function ($provider) use (&$seen) {
            $seen[] = $provider->model_id();
            throw new provider_http_exception(404, 'model does not exist sk-proj-' . str_repeat('A', 30));
        });
        $this->assertSame(['GPT-4o-mini'], $seen);
        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['ok']);
        $this->assertSame(404, $results[0]['status']);
        $this->assertStringContainsString('HTTP 404', $results[0]['detail']);
        $this->assertStringNotContainsString('AAAAAAAA', $results[0]['detail'], 'a key in the error is redacted');

        $rows = $DB->get_records('local_ai_course_assistant_audit', ['action' => failover_verifier::AUDIT_EVENT_PROBE]);
        $this->assertCount(1, $rows);
        $details = json_decode(reset($rows)->details, true);
        $this->assertSame('openai', $details['label']);
        $this->assertFalse($details['ok']);
    }

    /**
     * With the site provider on 'auto', a backup of the same provider that names no model of its own uses
     * the site's model, as it does in a real failover. The probe used to pass 'auto' straight through, so it
     * took the provider's default model instead and checked a model the failover would never send.
     */
    public function test_an_auto_site_provider_is_resolved_before_the_backups_are_built(): void {
        $this->resetAfterTest();
        set_config('provider', 'auto', 'local_ai_course_assistant');
        set_config('model', 'gemini-2.5-pro', 'local_ai_course_assistant');
        set_config('apikey', 'AIzaTestKeyForTheSiteProvider', 'local_ai_course_assistant');
        $this->configure("gemini|AIzaTestKeyForTheBackup|||", "chat:gemini");
        $models = [];
        failover_verifier::probe_all(function ($provider) use (&$models) {
            $models[] = $provider->model_id();
        });
        $this->assertSame(['gemini-2.5-pro'], $models, 'the site model, not the provider default gemini-2.5-flash');
    }

    public function test_a_backup_that_answers_is_recorded_ok(): void {
        $this->resetAfterTest();
        $this->configure("openai|sk-test-key-1|gpt-4o-mini||\nclaude|sk-ant-test-2|claude-haiku-4-5||", "chat:openai\nchat:claude");
        $results = failover_verifier::probe_all(function ($provider) {
            return;
        });
        $this->assertSame(['openai', 'claude'], array_column($results, 'label'));
        $this->assertSame([true, true], array_column($results, 'ok'));
        $this->assertSame(['gpt-4o-mini', 'claude-haiku-4-5'], array_column($results, 'model'));
    }

    public function test_no_chain_means_nothing_to_probe(): void {
        $this->resetAfterTest();
        $this->configure('', '');
        $this->assertSame([], failover_verifier::probe_all(function () {
            $this->fail('nothing to send');
        }));
    }

    public function test_the_latest_result_counts_consecutive_failures_only(): void {
        $this->resetAfterTest();
        $this->probe_row('openai', true, 5 * HOURSECS);
        $this->probe_row('openai', false, 3 * HOURSECS);
        $this->probe_row('openai', false, HOURSECS);
        $this->probe_row('claude', false, 2 * HOURSECS);
        $this->probe_row('claude', true, HOURSECS);
        $latest = failover_verifier::latest_results();
        $this->assertFalse($latest['openai']['ok']);
        $this->assertSame(2, $latest['openai']['failures'], 'two in a row, then an earlier success');
        $this->assertTrue($latest['claude']['ok']);
        $this->assertSame(0, $latest['claude']['failures']);
    }

    public function test_results_older_than_the_freshness_window_are_ignored(): void {
        $this->resetAfterTest();
        $this->probe_row('openai', false, failover_verifier::FRESH_WITHIN + HOURSECS);
        $this->assertSame([], failover_verifier::latest_results());
    }

    public function test_the_status_check_tells_the_states_apart(): void {
        $this->resetAfterTest();
        $check = new failover_providers();

        $this->configure('', '');
        $this->assertSame(result::INFO, $check->get_result()->get_status(), 'no backup configured');

        $this->configure("openai|sk-test-key-1|gpt-4o-mini||", "chat:openai");
        $this->assertSame(result::INFO, $check->get_result()->get_status(), 'configured but never checked');

        $this->probe_row('openai', true, HOURSECS);
        $this->assertSame(result::OK, $check->get_result()->get_status());

        $this->probe_row('openai', false, 60);
        $this->assertSame(result::WARNING, $check->get_result()->get_status(), 'one failed check');
        $this->assertStringContainsString('model not found', $check->get_result()->get_summary());

        $this->probe_row('openai', false, 30);
        $this->assertSame(result::ERROR, $check->get_result()->get_status(), 'two failed checks in a row');
    }

    public function test_a_settings_problem_alone_is_a_warning(): void {
        $this->resetAfterTest();
        $this->configure("openai|sk-test-key-1|GPT-4o-mini||", "chat:openai");
        $this->probe_row('openai', true, 60);
        $summary = (new failover_providers())->get_result();
        $this->assertSame(result::WARNING, $summary->get_status());
        $this->assertStringContainsString('gpt-4o-mini', $summary->get_summary());
    }

    public function test_saving_the_backup_settings_shows_their_problems_on_the_next_page(): void {
        global $SESSION;
        $this->resetAfterTest();
        $this->configure("openai|sk-test-key-1|gpt-4o-mini||", "chat:openai\nchat:xai");
        settings_save_report::assume_web(true);
        $SESSION->local_ai_course_assistant_savedchanges = ['spend_failover_chain' => ["chat:openai", "chat:openai\nchat:xai"]];
        settings_save_report::flush();
        settings_save_report::assume_web(null);
        $text = implode(' ', array_map(fn($n) => $n->get_message(), \core\notification::fetch()));
        $this->assertStringContainsString('Check your failover settings', $text);
        $this->assertStringContainsString('chat:xai', $text);
    }

    public function test_saving_unrelated_settings_adds_no_failover_notice(): void {
        global $SESSION;
        $this->resetAfterTest();
        $this->configure("openai|sk-test-key-1|gpt-4o-mini||", "chat:openai\nchat:xai");
        settings_save_report::assume_web(true);
        $SESSION->local_ai_course_assistant_savedchanges = ['greeting' => ['hi', 'hello']];
        settings_save_report::flush();
        settings_save_report::assume_web(null);
        $text = implode(' ', array_map(fn($n) => $n->get_message(), \core\notification::fetch()));
        $this->assertStringNotContainsString('Check your failover settings', $text);
    }
}
