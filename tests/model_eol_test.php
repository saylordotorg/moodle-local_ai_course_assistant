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

use local_ai_course_assistant\task\model_price_drift_check;

defined('MOODLE_INTERNAL') || die();

/**
 * v7.4.4 model lifecycle: end-of-life dates, the surface they apply to, and
 * the drift-check trigger that turns a retirement into a warning.
 *
 * The defect this covers is the one nothing in the plugin could see. Every
 * existing monitor watches PRICE — the drift check compares rate cards, the
 * anomaly detector watches spend, the re-open rule watches price and adoption.
 * A model being switched off moves none of those numbers: it keeps working and
 * keeps billing at the listed price right up until the shutoff, and then every
 * call fails at once. Before this the first symptom of a retirement was a
 * production outage.
 *
 * The second half of these tests is about NOT crying wolf, and it is not
 * theoretical. In the 2026-09-08 model review gemini-2.5-flash was reported as
 * retiring on 2026-10-16. True — of the Vertex AI lifecycle, which is not the
 * Gemini Developer API this plugin calls. A bare date would have raised a
 * production-outage alarm for a model that was going nowhere, and an operator
 * who is paged once about a non-event stops reading the pages. Recording which
 * surface a date applies to is what keeps the alert worth reading.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\model_registry
 * @covers     \local_ai_course_assistant\model_registry_page
 * @covers     \local_ai_course_assistant\task\model_price_drift_check
 */
final class model_eol_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        model_registry::reset_cache();
    }

    protected function tearDown(): void {
        model_registry::reset_cache();
        parent::tearDown();
    }

    /**
     * Log N billable assistant calls for a model, inside the traffic window.
     *
     * @param string $model
     * @param string $provider
     * @param int $calls
     * @return void
     */
    private function log_calls(string $model, string $provider = 'openai', int $calls = 1): void {
        global $DB;
        for ($i = 0; $i < $calls; $i++) {
            $DB->insert_record('local_ai_course_assistant_msgs', (object) [
                'conversationid'    => 1,
                'userid'            => 2,
                'courseid'          => SITEID,
                'role'              => 'assistant',
                'message'           => 'x',
                'prompt_tokens'     => 1000,
                'completion_tokens' => 500,
                'model_name'        => $model,
                'provider'          => $provider,
                'interaction_type'  => 'chat',
                'timecreated'       => time() - 60,
            ]);
        }
    }

    /**
     * Record a lifecycle date on a registry row.
     *
     * @param string $key
     * @param int $date
     * @param string $surface
     * @param array $extra
     * @return void
     */
    private function set_eol(string $key, int $date, string $surface, array $extra = []): void {
        // Callers that also log traffic must pass rates, or the same model
        // becomes a MISSING finding as well and the counts under test stop
        // being about lifecycle. See priced_eol().

        model_registry::upsert(
            $extra + ['modelkey' => $key, 'eol_date' => $date, 'eol_surface' => $surface],
            'manual',
            7
        );
        model_registry::reset_cache();
    }

    /**
     * Record a lifecycle date on a row that also carries a price.
     *
     * Used wherever the test logs real traffic. An unpriced model in traffic is
     * legitimately a MISSING finding too, which would make the EOL counts and
     * the alert fingerprint under test depend on the price gap rather than on
     * the retirement.
     *
     * @param string $key
     * @param int $date
     * @param string $surface
     * @return void
     */
    private function priced_eol(string $key, int $date, string $surface): void {
        $this->set_eol($key, $date, $surface, ['input_rate' => 1.0, 'output_rate' => 4.0]);
    }

    /**
     * One EOL finding by model key, or null.
     *
     * @param string $key
     * @param int|null $horizon
     * @return array|null
     */
    private function eol_finding(string $key, ?int $horizon = null): ?array {
        foreach (model_price_drift_check::eol_findings($horizon) as $finding) {
            if ($finding['modelkey'] === $key) {
                return $finding;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Schema and storage.
    // ------------------------------------------------------------------

    public function test_a_lifecycle_date_round_trips_through_the_registry(): void {
        $date = model_registry_page::parse_eol_date('2026-11-15');
        $this->assertNotNull($date);
        $this->set_eol('retiring-model', $date, 'openai-api');

        $eol = model_registry::eol_for('retiring-model');
        $this->assertNotNull($eol);
        $this->assertSame($date, $eol['eol_date']);
        $this->assertSame('openai-api', $eol['eol_surface']);
        $this->assertSame('2026-11-15', model_registry_page::isodate($eol['eol_date']));
    }

    public function test_a_model_with_no_announced_eol_resolves_to_null(): void {
        // Null means "nothing announced". It deliberately does NOT mean safe,
        // and no caller may read it as a guarantee.
        $this->assertNull(model_registry::eol_for('gemini-2.5-flash'));
        $prov = model_registry::provenance_for('gemini-2.5-flash');
        $this->assertNull($prov['eol_date']);
        $this->assertFalse($prov['eol_applies']);
    }

    public function test_an_eol_row_with_no_price_does_not_erase_the_baseline_price(): void {
        // The whole reason lifecycle is cached separately from rates. An
        // operator who hears "gpt-4o-mini retires in March" must be able to
        // record that in ten seconds without typing a price — and doing so must
        // not knock the shipped baseline price out from under every historic
        // msgs row for that model, repricing them all to $0.00.
        $baseline = model_registry::rate_for('gpt-4o-mini');
        $this->assertNotNull($baseline, 'precondition: gpt-4o-mini ships with a baseline price');

        $this->set_eol('gpt-4o-mini', model_registry_page::parse_eol_date('2027-03-01'), 'openai-api');

        $after = model_registry::rate_for('gpt-4o-mini');
        $this->assertNotNull($after, 'recording a retirement must never unprice a model');
        $this->assertEqualsWithDelta($baseline['input'], $after['input'], 1e-9);
        $this->assertEqualsWithDelta($baseline['output'], $after['output'], 1e-9);
        $this->assertNotNull(model_registry::eol_for('gpt-4o-mini'));
    }

    public function test_lifecycle_resolves_by_longest_prefix_like_price_does(): void {
        $family = model_registry_page::parse_eol_date('2027-01-01');
        $specific = model_registry_page::parse_eol_date('2026-10-01');
        $this->set_eol('acme-v1', $family, 'any');
        $this->set_eol('acme-v1-mini', $specific, 'any');

        $this->assertSame($specific, model_registry::eol_for('acme-v1-mini-2026')['eol_date']);
        $this->assertSame($family, model_registry::eol_for('acme-v1-large')['eol_date']);
    }

    public function test_a_batch_marked_model_inherits_the_underlying_retirement(): void {
        // batch/<model> is the same model bought on the offline tier. It cannot
        // outlive the model it is a discount on.
        $date = model_registry_page::parse_eol_date('2026-12-31');
        $this->set_eol('gpt-4o-mini', $date, 'openai-api');
        $eol = model_registry::eol_for(token_cost_manager::BATCH_MODEL_PREFIX . 'gpt-4o-mini');
        $this->assertNotNull($eol);
        $this->assertSame($date, $eol['eol_date']);
    }

    // ------------------------------------------------------------------
    // The admin form.
    // ------------------------------------------------------------------

    public function test_the_form_stores_a_date_and_shows_it_next_to_the_price(): void {
        $this->setAdminUser();
        $result = model_registry_page::save_model([
            'modelkey'    => 'form-model',
            'input_rate'  => '1.00',
            'output_rate' => '4.00',
            'eol_date'    => '2026-11-15',
            'eol_surface' => 'openai-api',
        ], 7);
        $this->assertSame(model_registry_page::OK, $result['level']);
        model_registry::reset_cache();

        $rows = array_column(model_registry_page::effective_rows()['rows'], null, 'modelkey');
        $this->assertArrayHasKey('form-model', $rows);
        $this->assertSame('2026-11-15', $rows['form-model']['eoldate']);
        $this->assertSame('openai-api', $rows['form-model']['eolsurface']);
        $this->assertNotSame('', $rows['form-model']['eol'], 'the price row must carry a lifecycle label');
    }

    public function test_the_form_refuses_a_date_with_no_surface(): void {
        // The 2026-09-08 near-miss written down as a validation rule. Whoever
        // reads the vendor announcement knows which surface it was for; nobody
        // reading the row three weeks later does.
        $result = model_registry_page::save_model([
            'modelkey' => 'no-surface-model',
            'eol_date' => '2026-11-15',
        ], 7);
        $this->assertSame(model_registry_page::ERROR, $result['level']);
        model_registry::reset_cache();
        $this->assertNull(model_registry::eol_for('no-surface-model'));
    }

    public function test_the_form_refuses_a_date_that_is_not_a_date(): void {
        foreach (['15/11/2026', '2026-13-01', '2026-02-30', 'next tuesday', '2026-11'] as $bad) {
            $result = model_registry_page::save_model([
                'modelkey'    => 'bad-date-model',
                'eol_date'    => $bad,
                'eol_surface' => 'openai-api',
            ], 7);
            $this->assertSame(
                model_registry_page::ERROR,
                $result['level'],
                "'{$bad}' must be refused, not silently coerced into some other day"
            );
        }
        model_registry::reset_cache();
        $this->assertNull(model_registry::eol_for('bad-date-model'));
    }

    public function test_clearing_the_date_clears_the_surface_with_it(): void {
        $this->set_eol('temporary-eol', model_registry_page::parse_eol_date('2026-11-15'), 'vertex-ai');
        $this->assertNotNull(model_registry::eol_for('temporary-eol'));

        // A surface left behind with no date says nothing, and would make the
        // next editor believe a retirement was still recorded.
        model_registry_page::save_model(['modelkey' => 'temporary-eol', 'eol_date' => ''], 7);
        model_registry::reset_cache();

        $this->assertNull(model_registry::eol_for('temporary-eol'));
        $prov = model_registry::provenance_for('temporary-eol');
        $this->assertNull($prov['eol_surface']);
    }

    public function test_a_lifecycle_only_row_is_visible_and_editable_on_the_page(): void {
        // Before v7.4.4 the effective-prices table walked the RATE map, so a row
        // carrying a retirement and no price appeared nowhere on the one page
        // that can edit it.
        $this->set_eol('unpriced-but-retiring', model_registry_page::parse_eol_date('2026-11-15'), 'any');
        $rows = array_column(model_registry_page::effective_rows()['rows'], null, 'modelkey');
        $this->assertArrayHasKey('unpriced-but-retiring', $rows);
        $this->assertTrue($rows['unpriced-but-retiring']['unpriced']);
        $this->assertSame('2026-11-15', $rows['unpriced-but-retiring']['eoldate']);
    }

    // ------------------------------------------------------------------
    // The drift-check trigger.
    // ------------------------------------------------------------------

    public function test_an_approaching_retirement_in_traffic_becomes_a_finding(): void {
        $this->log_calls('doomed-model', 'openai', 12);
        $this->priced_eol('doomed-model', time() + (20 * DAYSECS), 'openai-api');

        $finding = $this->eol_finding('doomed-model', 60);
        $this->assertNotNull($finding, 'a retirement 20 days out must be reported inside a 60-day horizon');
        $this->assertSame('eol', $finding['type']);
        $this->assertTrue($finding['eol_applies']);
        $this->assertFalse($finding['eol_passed']);
        $this->assertSame(12, $finding['calls']);
        // No price is proposed, and the fields stay NULL rather than zero: a
        // zero here would render as "this model is free" in the drift table.
        $this->assertNull($finding['input']);
        $this->assertNull($finding['output']);
    }

    public function test_a_retirement_beyond_the_horizon_is_not_yet_a_finding(): void {
        $this->log_calls('far-future-model', 'openai', 3);
        $this->priced_eol('far-future-model', time() + (300 * DAYSECS), 'openai-api');
        $this->assertNull($this->eol_finding('far-future-model', 60));
        // ...and the same model IS a finding once the horizon is widened, which
        // is the setting doing its job rather than the model being missed.
        $this->assertNotNull($this->eol_finding('far-future-model', 365));
    }

    public function test_a_retirement_that_has_already_passed_is_still_reported(): void {
        // "The shutoff was three weeks ago and we are still calling it" is the
        // single state this check exists to make impossible to miss. It does
        // not stop being true because the calendar moved past it, so a passed
        // date is reported at every horizon, including zero.
        $this->log_calls('already-dead-model', 'openai', 4);
        $this->priced_eol('already-dead-model', time() - (21 * DAYSECS), 'openai-api');

        $finding = $this->eol_finding('already-dead-model', 0);
        $this->assertNotNull($finding);
        $this->assertTrue($finding['eol_passed']);
        $this->assertTrue($finding['eol_applies']);
    }

    public function test_a_retired_model_with_no_traffic_raises_nothing(): void {
        // Findings are about what is being CALLED. A retirement recorded for a
        // model this site does not use is bookkeeping, not an incident.
        $this->set_eol('shelved-model', time() + DAYSECS, 'openai-api');
        $this->assertNull($this->eol_finding('shelved-model', 60));
    }

    public function test_a_priced_model_in_traffic_is_still_checked_for_lifecycle(): void {
        // The reason models_in_traffic() had to be extracted. unpriced_models()
        // skips anything that resolves to a rate, and a model being retired
        // almost always has one — reusing it would have made this check blind
        // to exactly the production models it is for.
        $this->log_calls('gemini-2.5-flash', 'gemini', 9);
        $this->assertNotNull(model_registry::rate_for('gemini-2.5-flash'));
        $this->set_eol('gemini-2.5-flash', time() + (10 * DAYSECS), 'gemini-developer-api');

        $this->assertNotNull($this->eol_finding('gemini-2.5-flash', 60));
    }

    // ------------------------------------------------------------------
    // The false-alarm gate: WHICH surface.
    // ------------------------------------------------------------------

    public function test_a_retirement_on_a_surface_this_site_does_not_call_is_not_alerted(): void {
        // The exact 2026-09-08 near-miss, replayed. gemini-2.5-flash was
        // reported as retiring 2026-10-16 on Vertex AI; this plugin reaches it
        // through the Gemini Developer API.
        set_config('model_eol_surfaces', 'gemini-developer-api,openai-api', 'local_ai_course_assistant');
        $this->log_calls('gemini-2.5-flash', 'gemini', 30);
        $this->set_eol('gemini-2.5-flash', model_registry_page::parse_eol_date('2026-10-16'), 'vertex-ai');

        $finding = $this->eol_finding('gemini-2.5-flash', 3650);
        $this->assertNotNull($finding, 'it is still recorded and still shown — it is just not ours');
        $this->assertFalse(
            $finding['eol_applies'],
            'a Vertex AI lifecycle event must not raise a production-outage alarm on a Developer API deployment'
        );

        $summary = model_price_drift_check::run();
        $this->assertSame(0, $summary['counts']['eol'], 'the alerting count excludes other surfaces');
        $this->assertSame(1, $summary['counts']['eol_other'], 'but it is still counted and visible');
        $this->assertFalse(
            model_price_drift_check::maybe_send_alert($summary),
            'nothing alert-worthy: no email'
        );
    }

    public function test_the_same_retirement_does_alert_once_the_site_calls_that_surface(): void {
        set_config('model_eol_surfaces', 'vertex-ai', 'local_ai_course_assistant');
        $this->log_calls('gemini-2.5-flash', 'gemini', 30);
        $this->set_eol('gemini-2.5-flash', time() + (14 * DAYSECS), 'vertex-ai');

        $finding = $this->eol_finding('gemini-2.5-flash', 60);
        $this->assertNotNull($finding);
        $this->assertTrue($finding['eol_applies']);
    }

    public function test_a_site_that_has_not_declared_its_surfaces_is_told_about_everything(): void {
        // Fail loud in the unknown direction. Silence must be the result of two
        // positive statements, never of an unset setting.
        set_config('model_eol_surfaces', '', 'local_ai_course_assistant');
        $this->log_calls('somewhere-model', 'openai', 2);
        $this->priced_eol('somewhere-model', time() + (5 * DAYSECS), 'some-cloud-we-never-heard-of');

        $finding = $this->eol_finding('somewhere-model', 60);
        $this->assertNotNull($finding);
        $this->assertTrue($finding['eol_applies']);
    }

    public function test_an_any_surface_retirement_applies_however_the_site_is_configured(): void {
        set_config('model_eol_surfaces', 'openai-api', 'local_ai_course_assistant');
        $this->log_calls('universal-model', 'openai', 2);
        $this->priced_eol('universal-model', time() + (5 * DAYSECS), model_registry::EOL_SURFACE_ANY);

        $this->assertTrue($this->eol_finding('universal-model', 60)['eol_applies']);
    }

    // ------------------------------------------------------------------
    // The re-open rule.
    // ------------------------------------------------------------------

    public function test_an_eol_finding_raises_the_run_status_and_the_alert_gate(): void {
        $this->log_calls('gate-model', 'openai', 5);
        $this->priced_eol('gate-model', time() + (7 * DAYSECS), 'openai-api');

        $summary = model_price_drift_check::run();
        $this->assertSame(1, $summary['counts']['eol']);
        $this->assertSame(0, $summary['counts']['missing']);
        $this->assertSame(0, $summary['counts']['mismatch']);
        $this->assertSame(
            'findings',
            $summary['status'],
            'lifecycle must raise the status on its own; a price-only status would leave the '
                . 'admin page and the email both reporting "ok" on the day a model is switched off'
        );
        $this->assertTrue(
            model_price_drift_check::maybe_send_alert($summary),
            'the alert gate must widen with the status, or the check computes findings and emails nobody'
        );
    }

    public function test_the_same_eol_finding_does_not_re_alert_the_same_day(): void {
        $this->log_calls('dedup-model', 'openai', 5);
        $this->priced_eol('dedup-model', time() + (7 * DAYSECS), 'openai-api');

        $summary = model_price_drift_check::run();
        $this->assertTrue(model_price_drift_check::maybe_send_alert($summary));
        $this->assertFalse(
            model_price_drift_check::maybe_send_alert($summary),
            'an unchanged finding set is silent for the rest of the UTC day'
        );
    }

    public function test_a_slipped_retirement_date_re_opens_the_alert(): void {
        // The reason the date is in the fingerprint tuple. Both rate fields on
        // an EOL finding are null, so without the date every EOL row collapses
        // to "eol|<key>|0|0" and a vendor MOVING a shutoff — the one lifecycle
        // change an operator most needs to hear about twice — would be
        // deduplicated against the original alert and never sent.
        $this->log_calls('slipping-model', 'openai', 5);
        $this->priced_eol('slipping-model', time() + (7 * DAYSECS), 'openai-api');

        $first = model_price_drift_check::run();
        $this->assertTrue(model_price_drift_check::maybe_send_alert($first));

        $this->priced_eol('slipping-model', time() + (3 * DAYSECS), 'openai-api');
        $second = model_price_drift_check::run();
        $this->assertTrue(
            model_price_drift_check::maybe_send_alert($second),
            'a changed shutoff date is new information and must re-open the alert'
        );
    }

    public function test_an_other_surface_eol_cannot_re_open_an_alert(): void {
        set_config('model_eol_surfaces', 'openai-api', 'local_ai_course_assistant');
        $this->log_calls('real-problem', 'openai', 5);
        $this->priced_eol('real-problem', time() + (7 * DAYSECS), 'openai-api');

        $first = model_price_drift_check::run();
        $this->assertTrue(model_price_drift_check::maybe_send_alert($first));

        // A Vertex-only date lands mid-morning. It must not resend the morning's
        // email: a finding that cannot alert must not be able to re-open one.
        $this->log_calls('not-our-problem', 'gemini', 5);
        $this->priced_eol('not-our-problem', time() + (9 * DAYSECS), 'vertex-ai');

        $second = model_price_drift_check::run();
        $this->assertSame(1, $second['counts']['eol']);
        $this->assertSame(1, $second['counts']['eol_other']);
        $this->assertFalse(model_price_drift_check::maybe_send_alert($second));
    }

    public function test_lifecycle_findings_are_never_applied_automatically(): void {
        // Findings are proposals for price, and not even proposals for
        // lifecycle: an automated write would carry source 'drift', which
        // upsert()'s refusal rule discards on any row a human has corrected —
        // precisely the busy, hand-tuned models an EOL warning is for. So a
        // silent no-op exactly where it mattered. Nothing here writes.
        global $DB;
        $this->log_calls('untouched-model', 'openai', 5);
        $this->priced_eol('untouched-model', time() + (7 * DAYSECS), 'openai-api');
        $before = $DB->get_record(model_registry::TABLE_MODELS, ['modelkey' => 'untouched-model']);

        model_price_drift_check::run();

        $after = $DB->get_record(model_registry::TABLE_MODELS, ['modelkey' => 'untouched-model']);
        $this->assertEquals($before, $after, 'the drift check reports; a person records');
    }

    public function test_the_drift_table_offers_no_apply_button_for_a_lifecycle_finding(): void {
        $this->log_calls('render-model', 'openai', 5);
        $this->priced_eol('render-model', time() + (7 * DAYSECS), 'openai-api');
        model_price_drift_check::run();

        $rows = array_column(model_registry_page::drift_block()['rows'], null, 'modelkey');
        $this->assertArrayHasKey('render-model', $rows);
        $row = $rows['render-model'];
        $this->assertTrue($row['iseol']);
        $this->assertFalse($row['hasrates'], 'an apply button on an EOL row would write a price of nothing');
        $this->assertNotSame('', $row['eollabel']);
    }

    public function test_the_horizon_setting_is_read_and_clamped(): void {
        $this->assertSame(
            model_price_drift_check::DEFAULT_EOL_HORIZON_DAYS,
            model_price_drift_check::eol_horizon_days()
        );
        set_config('price_drift_eol_horizon_days', '14', 'local_ai_course_assistant');
        $this->assertSame(14, model_price_drift_check::eol_horizon_days());
        set_config('price_drift_eol_horizon_days', '-5', 'local_ai_course_assistant');
        $this->assertSame(0, model_price_drift_check::eol_horizon_days());
        set_config('price_drift_eol_horizon_days', 'soon', 'local_ai_course_assistant');
        $this->assertSame(
            model_price_drift_check::DEFAULT_EOL_HORIZON_DAYS,
            model_price_drift_check::eol_horizon_days(),
            'a nonsense value falls back to the default rather than to zero, which would '
                . 'silently narrow the check to "already dead"'
        );
    }
}
