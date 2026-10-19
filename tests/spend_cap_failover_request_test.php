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
use local_ai_course_assistant\provider\chat_refused_exception;

/**
 * A spend-cap failover asks the failover for ITS model, at ITS base URL (v7.13.0).
 *
 * When a spend cap trips, base_provider::create_from_config() swaps the provider and the key for the first
 * line of the failover chain. Until 7.13.0 it left the model and the base URL of the primary in place, so a
 * Gemini primary with a capped month sent "gemini-2.5-flash" to OpenAI (HTTP 404, model not found), and sent
 * the primary's own gateway URL along with another vendor's key. build_fallbacks() fixed the same thing for
 * the per-call chain in v7.2.8; the cap path never got the fix (spend analysis 2026-10-19, "What a learner
 * sees when a cap trips").
 *
 * These tests build the provider the way a chat turn does, with the cap really tripped (a chat row that
 * costs more than the cap, the way radar_batch_test exhausts it), and read what the built provider would
 * send: provider_id() and model_id() are public, the base URL and key are read by reflection.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\base_provider::create_from_config
 */
final class spend_cap_failover_request_test extends \advanced_testcase {
    /** @var string The refusal a tripped cap with no failover gives, unchanged since 7.2.0. */
    private const CAP_MESSAGE = 'SOLA spend cap reached for this period; no failover provider configured.';

    /** @var string The primary's own gateway, which must never reach another vendor. */
    private const PRIMARY_URL = 'https://primary-gateway.example/v1beta/openai';

    /** @var string A failover row's own model: not OpenAI's default (gpt-4o-mini), so ignoring it cannot pass. */
    private const ROW_MODEL = 'gpt-4.1-mini';

    /** @var string A base URL a failover row sets for itself. */
    private const ROW_URL = 'https://failover-gateway.example/v1';

    /** @var \stdClass The course the learner is in. */
    private \stdClass $course;

    /**
     * A Gemini primary on its default endpoint, one learner in one course, and no cap yet.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->redirectEmails();
        $this->cfg('provider', 'gemini');
        $this->cfg('apikey', 'AIzaPrimaryKey0000000000000000');
        $this->cfg('model', 'gemini-2.5-flash');
        $this->cfg('apibaseurl', '');
        $this->cfg('failover_per_call_enabled', 0);
        $this->course = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_user());
    }

    // Case (a): the failover's own model, and not the primary's gateway.

    /**
     * Gemini primary, capped; the failover row is OpenAI with its own model and no base URL.
     *
     * The built provider is OpenAI, holds the failover's key, asks for the row's model (a model that is NOT
     * OpenAI's default, so using the provider default instead would fail too), and does not send the course's
     * gateway URL (the primary's) to OpenAI: with no URL of its own the row means the vendor's own endpoint,
     * but only while the site `apibaseurl` is empty, as setUp() makes it. A site-level URL is still read by the
     * constructor for any provider built without one (build_fallbacks() has the same limit). Before 7.13.0 the
     * model was "gemini-2.5-flash" and the URL the course's.
     *
     * @return void
     */
    public function test_a_failover_with_its_own_model_sends_that_model_and_not_the_primary_gateway(): void {
        \local_ai_course_assistant\course_config_manager::save((int) $this->course->id, [
            'enabled' => 1,
            'apibaseurl' => self::PRIMARY_URL,
        ]);
        $this->failover_rows('openai|sk-failover-key-0000000000|' . self::ROW_MODEL);
        $this->trip_the_chat_cap();

        $built = $this->build();

        $this->assertSame('openai', $built->provider_id(), 'the cap did not move chat onto the failover provider');
        $this->assertSame(
            self::ROW_MODEL,
            $built->model_id(),
            'the failover was asked for a model other than its own row model'
        );
        $this->assertSame(
            'sk-failover-key-0000000000',
            $this->read($built, 'apikey'),
            'the failover must carry its own key'
        );
        $this->assertSame(
            'https://api.openai.com',
            $this->read($built, 'baseurl'),
            'the primary course\'s gateway URL was sent to the failover vendor'
        );
    }

    // Case (b): no model on the failover row means the provider's default, never the primary's name.

    /**
     * Gemini primary, capped; the failover row is OpenAI with no model column at all.
     *
     * It must not be asked for "gemini-2.5-flash". With nothing of its own it answers on its provider default
     * (gpt-4o-mini), exactly what build_fallbacks() does when the provider differs from the primary's.
     *
     * @return void
     */
    public function test_a_failover_row_with_no_model_uses_its_provider_default_not_the_primary_name(): void {
        $this->failover_rows('openai|sk-failover-key-0000000000');
        $this->trip_the_chat_cap();

        $built = $this->build();

        $this->assertSame('openai', $built->provider_id());
        $this->assertNotSame(
            'gemini-2.5-flash',
            $built->model_id(),
            'OpenAI was asked for the primary\'s model name, which it answers with HTTP 404'
        );
        $this->assertSame('gpt-4o-mini', $built->model_id(), 'a row with no model must use its provider default');
    }

    /**
     * The same, when the primary's model comes from the COURSE rather than the site setting.
     *
     * @return void
     */
    public function test_a_course_model_of_the_primary_does_not_reach_a_failover_on_another_provider(): void {
        \local_ai_course_assistant\course_config_manager::save((int) $this->course->id, [
            'enabled' => 1,
            'model' => 'gemini-2.5-pro',
        ]);
        $this->failover_rows('openai|sk-failover-key-0000000000');
        $this->trip_the_chat_cap();

        $built = $this->build();

        $this->assertSame('openai', $built->provider_id());
        $this->assertSame('gpt-4o-mini', $built->model_id(), 'the course\'s Gemini model reached the failover');
    }

    // Case (c): a base URL on the failover row is used.

    /**
     * The failover row names a gateway of its own: that URL is sent, ahead of the course's and the site's.
     *
     * @return void
     */
    public function test_a_failover_row_with_its_own_base_url_uses_it(): void {
        $this->cfg('apibaseurl', 'https://site-gateway.example/v1');
        \local_ai_course_assistant\course_config_manager::save((int) $this->course->id, [
            'enabled' => 1,
            'apibaseurl' => self::PRIMARY_URL,
        ]);
        $this->failover_rows('openai|sk-failover-key-0000000000|' . self::ROW_MODEL . '||' . self::ROW_URL);
        $this->trip_the_chat_cap();

        $built = $this->build();

        $this->assertSame('openai', $built->provider_id());
        $this->assertSame(self::ROW_URL, $this->read($built, 'baseurl'), 'the failover row\'s own base URL was not used');
        $this->assertSame(self::ROW_MODEL, $built->model_id());
    }

    // Case (d): the same provider keeps the primary's model.

    /**
     * The failover is the SAME provider as the primary (a second key, say), names no model, and the primary's
     * model comes from the site setting.
     *
     * Nothing says the primary's model is wrong for it, so it keeps asking for that model instead of falling
     * back to the provider default (gpt-4o-mini here, which is not what the site chose). The cap path leaves
     * the model override exactly as it was in this case.
     *
     * @return void
     */
    public function test_a_failover_on_the_same_provider_keeps_the_primary_model(): void {
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', 'sk-primary-key-00000000000');
        $this->cfg('model', 'gpt-4o');
        $this->failover_rows('openai|sk-second-account-key-0000');
        $this->trip_the_chat_cap();

        $built = $this->build();

        $this->assertSame('openai', $built->provider_id());
        $this->assertSame('gpt-4o', $built->model_id(), 'a same-provider failover lost the primary\'s model');
        $this->assertSame(
            'sk-second-account-key-0000',
            $this->read($built, 'apikey'),
            'the cap must still swap in the failover\'s key'
        );
    }

    /**
     * The same, when the COURSE overrides the site's provider and model.
     *
     * Site: gemini / gemini-2.5-flash. Course: openai / gpt-4o. The failover row is OpenAI with a different key and
     * no model. The primary is OpenAI, so the failover is the same provider and must keep the COURSE's model. If
     * the model override were dropped the constructor would read the SITE model and OpenAI would be asked for
     * "gemini-2.5-flash" (HTTP 404). build_fallbacks() drops it in this case; the cap path deliberately does not,
     * because it kept the course's model before 7.13.0 and this is the case a blanket "unset" would break.
     *
     * @return void
     */
    public function test_a_same_provider_failover_keeps_the_course_model_when_the_course_overrides_the_provider(): void {
        \local_ai_course_assistant\course_config_manager::save((int) $this->course->id, [
            'enabled' => 1,
            'provider' => 'openai',
            'model' => 'gpt-4o',
        ]);
        $this->failover_rows('openai|sk-second-account-key-0000');
        $this->trip_the_chat_cap();

        $built = $this->build();

        $this->assertSame('openai', $built->provider_id());
        $this->assertNotSame(
            'gemini-2.5-flash',
            $built->model_id(),
            'the site model replaced the course\'s: OpenAI is asked for a Gemini model name'
        );
        $this->assertSame('gpt-4o', $built->model_id(), 'a same-provider failover lost the course\'s model');
        $this->assertSame('sk-second-account-key-0000', $this->read($built, 'apikey'));
    }

    // Case (e): no failover.

    /**
     * No chain at all: the cap refuses, with the same words as ever.
     *
     * @return void
     */
    public function test_a_tripped_cap_with_no_failover_refuses_with_the_unchanged_message(): void {
        $this->cfg('spend_failover_chain', '');
        $this->trip_the_chat_cap();

        try {
            $this->build();
            $this->fail('a tripped cap with no failover must refuse the call');
        } catch (chat_refused_exception $e) {
            $this->assertSame(self::CAP_MESSAGE, $e->getMessage());
        }
    }

    /**
     * A chain line whose provider row has no key is dropped, so it is the same as no failover.
     *
     * @return void
     */
    public function test_a_chain_line_with_no_usable_row_refuses_the_same_way(): void {
        $this->cfg('spend_failover_chain', 'chat:openai');
        $this->cfg('comparison_providers', 'openai||gpt-4o-mini');
        $this->trip_the_chat_cap();

        try {
            $this->build();
            $this->fail('a failover with no key is no failover; the cap must refuse the call');
        } catch (chat_refused_exception $e) {
            $this->assertSame(self::CAP_MESSAGE, $e->getMessage());
        }
    }

    // Case (f): the emergency stop is not a spend cap.

    /**
     * The emergency stop is engaged while a failover is configured and the cap is also tripped.
     *
     * The call is refused with the emergency message and never fails over: a kill switch must not be read as
     * "this provider is capped". This does NOT reach the emergency check inside the cap branch: for a
     * non-diagnostic call enforce_learner_guards() throws the same refusal earlier, before the spend guard is
     * consulted at all. What it pins is the outcome a learner gets (the emergency message, no failover provider,
     * not the spend-cap message), whichever of the two checks produces it.
     *
     * @return void
     */
    public function test_an_engaged_emergency_stop_refuses_with_its_own_message_before_any_failover(): void {
        $this->failover_rows('openai|sk-failover-key-0000000000|' . self::ROW_MODEL);
        $this->trip_the_chat_cap();
        $this->cfg('emergency_chat_disabled', '1');

        try {
            $this->build();
            $this->fail('the emergency stop must refuse the call, not move it onto the failover');
        } catch (chat_refused_exception $e) {
            $this->assertStringContainsString(
                branding::str('emergency:chat_stopped'),
                $e->getMessage(),
                'refused, but not because of the emergency stop'
            );
            $this->assertStringNotContainsString('spend cap', $e->getMessage());
        }
    }

    // Control: nothing tripped, nothing changes.

    /**
     * With the cap not reached the primary is built as ever: its model and its base URL, no failover.
     *
     * @return void
     */
    public function test_an_untripped_cap_builds_the_primary_with_its_own_model_and_base_url(): void {
        \local_ai_course_assistant\course_config_manager::save((int) $this->course->id, [
            'enabled' => 1,
            'apibaseurl' => self::PRIMARY_URL,
        ]);
        $this->failover_rows('openai|sk-failover-key-0000000000|' . self::ROW_MODEL);
        $this->cfg('spend_cap_chat', '1000');

        $built = $this->build();

        $this->assertSame('gemini', $built->provider_id());
        $this->assertSame('gemini-2.5-flash', $built->model_id());
        $this->assertSame(self::PRIMARY_URL, $this->read($built, 'baseurl'));
        $this->assertSame('AIzaPrimaryKey0000000000000000', $this->read($built, 'apikey'));
    }

    // Helpers.

    /**
     * Write one setting of this plugin.
     *
     * @param string $name
     * @param string|int $value
     * @return void
     */
    private function cfg(string $name, $value): void {
        set_config($name, $value, 'local_ai_course_assistant');
    }

    /**
     * Configure the Providers and keys rows and a chat failover chain that names the first of them.
     *
     * @param string $firstrow The failover row, in the stored provider|key|models|temperature|baseurl shape.
     * @return void
     */
    private function failover_rows(string $firstrow): void {
        $this->cfg('comparison_providers', $firstrow);
        $this->cfg('spend_failover_chain', 'chat:' . explode('|', $firstrow)[0]);
    }

    /**
     * Exhaust the chat cap the way a real month does: a chat row that costs more than the cap.
     *
     * The test asserts its own precondition, because a cap that is not actually blocked would let every
     * "the failover is built right" assertion pass against the defect by never reaching the failover.
     *
     * @return void
     */
    private function trip_the_chat_cap(): void {
        global $DB, $USER;
        $this->cfg('spend_cap_chat', '0.01');
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid'    => 1,
            'userid'            => (int) $USER->id,
            'courseid'          => (int) $this->course->id,
            'role'              => 'assistant',
            'message'           => '[chat]',
            'tokens_used'       => 2000000,
            'prompt_tokens'     => 1000000,
            'completion_tokens' => 1000000,
            'model_name'        => 'gpt-4o',
            'provider'          => 'openai',
            'interaction_type'  => 'chat',
            'timecreated'       => time(),
        ]);
        $this->assertSame(
            spend_guard::CAP_BLOCKED,
            spend_guard::check((int) $this->course->id, 'chat', false),
            'the cap is not actually blocked, so this test would pass against the defect'
        );
    }

    /**
     * Build the provider a chat turn in the course gets.
     *
     * @return base_provider
     */
    private function build(): base_provider {
        return base_provider::create_from_config((int) $this->course->id);
    }

    /**
     * Read a protected property of a built provider: what it would send.
     *
     * @param base_provider $provider
     * @param string $name 'apikey' or 'baseurl'.
     * @return string
     */
    private function read(base_provider $provider, string $name): string {
        return (string) (new \ReflectionProperty(base_provider::class, $name))->getValue($provider);
    }
}
