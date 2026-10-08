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

use local_ai_course_assistant\autoupgrade\budget;
use local_ai_course_assistant\autoupgrade\candidates;
use local_ai_course_assistant\autoupgrade\evaluator;
use local_ai_course_assistant\autoupgrade\roles;
use local_ai_course_assistant\autoupgrade\switcher;
use local_ai_course_assistant\task\discover_models;

/**
 * v7.8.0 plumbing around automatic upgrades: queueing, modes, emergency and
 * policy-bundle awareness, and the failed-turn record the watcher reads.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\task\discover_models
 * @covers     \local_ai_course_assistant\emergency_control::any_active
 * @covers     \local_ai_course_assistant\policy_bundle::managed_keys
 * @covers     \local_ai_course_assistant\autoupgrade\switcher::managed_keys
 */
final class autoupgrade_plumbing_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('model', 'gpt-4o-mini', 'local_ai_course_assistant');
    }

    public function test_the_mode_defaults_to_automatic(): void {
        $this->assertSame(switcher::MODE_AUTO, switcher::mode());
        set_config('autoupgrade_mode', 'nonsense', 'local_ai_course_assistant');
        $this->assertSame(switcher::MODE_AUTO, switcher::mode());
        set_config('autoupgrade_mode', 'off', 'local_ai_course_assistant');
        $this->assertSame(switcher::MODE_OFF, switcher::mode());
    }

    public function test_queueing_is_capped_and_spread(): void {
        global $DB;
        foreach (['a-1', 'a-2', 'a-3', 'a-4'] as $model) {
            candidates::upsert(roles::CHAT, 'openai', $model, '', 'test');
        }
        $first = discover_models::queue_due();
        $this->assertCount(discover_models::MAX_EVALS_PER_RUN, $first);
        $again = discover_models::queue_due();
        $this->assertCount(2, $again, 'The next run picks the next ones, not the queued ones again.');
        $this->assertSame(4, $DB->count_records(evaluator::TABLE));
        $this->assertSame([], discover_models::queue_due(), 'Nothing is queued twice.');
    }

    public function test_nothing_is_queued_when_the_budget_is_spent(): void {
        global $DB;
        candidates::upsert(roles::CHAT, 'openai', 'a-1', '', 'test');
        $DB->insert_record(evaluator::TABLE, (object) ['candidateid' => 0, 'role' => roles::CHAT, 'provider' => 'openai',
            'model' => 'x', 'variant' => '', 'inc_provider' => 'openai', 'inc_model' => 'gpt-4o-mini', 'inc_variant' => '',
            'status' => evaluator::COMPLETE, 'actual_cost_usd' => budget::limit(), 'timecreated' => time()]);
        $this->assertSame([], discover_models::queue_due());
    }

    public function test_a_roles_other_provider_is_not_queued(): void {
        candidates::upsert(roles::CHAT, 'gemini', 'gemini-x', '', 'test');
        $this->assertSame([], discover_models::queue_due());
    }

    public function test_any_emergency_control_counts(): void {
        $this->assertFalse(emergency_control::any_active());
        emergency_control::disable([emergency_control::FLAG_OUTREACH], 'test', 'test');
        $this->assertTrue(emergency_control::any_active());
        emergency_control::restore([emergency_control::FLAG_OUTREACH], 'test', 'test');
        $this->assertFalse(emergency_control::any_active());
        emergency_control::disable([emergency_control::FLAG_CHAT], 'test', 'test');
        $this->assertTrue(emergency_control::any_active());
    }

    public function test_the_bundle_records_the_keys_it_manages_even_when_not_newer(): void {
        $keys = sodium_crypto_sign_keypair();
        set_config('policy_bundle_pubkey', base64_encode(sodium_crypto_sign_publickey($keys)), 'local_ai_course_assistant');
        $envelope = function (int $version, array $settings) use ($keys): string {
            $payload = json_encode(['version' => $version, 'issued_at' => '2026-10-07', 'settings' => $settings]);
            return json_encode(['format' => policy_bundle::FORMAT, 'payload' => base64_encode($payload),
                'signature' => base64_encode(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($keys)))]);
        };
        policy_bundle::process_envelope($envelope(3, ['model' => 'gemini-2.5-flash', 'temperature' => 0.4]));
        $this->assertSame(['model', 'temperature'], policy_bundle::managed_keys());
        set_config('policy_bundle_managed_keys', '', 'local_ai_course_assistant');
        policy_bundle::process_envelope($envelope(3, ['cost_anomaly_multiplier' => 2]));
        $this->assertSame(
            ['cost_anomaly_multiplier'],
            policy_bundle::managed_keys(),
            'A verified bundle that is not newer still says which keys the fleet owns.'
        );
    }

    public function test_without_a_recorded_bundle_the_last_applied_changes_count(): void {
        audit_logger::log('policy_bundle_applied', 0, 0, ['version' => 5, 'changed' => [
            'model' => ['old' => 'a', 'new' => 'b'], 'spend_cap_site' => ['old' => '1', 'new' => '2']]]);
        $this->assertSame([], policy_bundle::managed_keys());
        $this->assertSame(['model', 'spend_cap_site'], switcher::managed_keys());
        set_config('policy_bundle_managed_keys', 'temperature', 'local_ai_course_assistant');
        $this->assertSame(['temperature'], switcher::managed_keys(), 'A recorded bundle wins over the audit trail.');
    }

    public function test_the_privacy_provider_declares_the_new_tables(): void {
        $collection = new \core_privacy\local\metadata\collection('local_ai_course_assistant');
        $names = array_map(function ($item) {
            return $item->get_name();
        }, privacy\provider::get_metadata($collection)->get_collection());
        $this->assertContains('local_ai_course_assistant_model_eval', $names);
        $this->assertContains('local_ai_course_assistant_model_switch', $names);
    }
}
