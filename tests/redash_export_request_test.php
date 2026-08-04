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

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for redash_export_request: section allow-list, default lookback
 * window, and the de-anonymization gate on redash_export.php.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\redash_export_request
 */
final class redash_export_request_test extends \advanced_testcase {

    public function test_absent_sections_keeps_the_full_export(): void {
        // Backward compatibility: an existing data source that passes no
        // sections parameter must keep receiving every section.
        $this->assertSame(
            redash_export_request::SECTIONS,
            redash_export_request::parse_sections(''));
        $this->assertSame(
            redash_export_request::SECTIONS,
            redash_export_request::parse_sections('   '));
        $this->assertSame(
            redash_export_request::SECTIONS,
            redash_export_request::parse_sections('all'));
        $this->assertSame(
            redash_export_request::SECTIONS,
            redash_export_request::parse_sections('ALL'));
    }

    public function test_named_sections_are_the_only_ones_returned(): void {
        $this->assertSame(
            ['courses', 'feedback', 'token_costs'],
            redash_export_request::parse_sections('courses,feedback,token_costs'));

        // The heavy sections stay out unless asked for by name.
        $selected = redash_export_request::parse_sections('token_costs');
        $this->assertSame(['token_costs'], $selected);
        $this->assertNotContains('meta_ai', $selected);
        $this->assertNotContains('learning_radar_queries', $selected);
    }

    public function test_parsing_is_forgiving_about_formatting(): void {
        // Whitespace, case, and duplicates are all tolerated.
        $this->assertSame(
            ['courses', 'feedback'],
            redash_export_request::parse_sections(' Feedback , courses ,feedback, '));
    }

    public function test_response_order_is_canonical_not_caller_order(): void {
        // Written back to front; still returned in SECTIONS order so the
        // response shape does not depend on how the URL was typed.
        $this->assertSame(
            ['courses', 'feedback', 'meta_ai'],
            redash_export_request::parse_sections('meta_ai,feedback,courses'));
    }

    public function test_unknown_sections_are_dropped_but_reported(): void {
        // A typo alongside a valid name: the valid name still works.
        $this->assertSame(
            ['token_costs'],
            redash_export_request::parse_sections('token_costs,tokencosts'));
        $this->assertSame(
            ['tokencosts'],
            redash_export_request::unknown_sections('token_costs,tokencosts'));
    }

    public function test_all_unknown_sections_yields_empty_so_caller_can_reject(): void {
        // The endpoint turns this into a 400. It must NOT fall back to the full
        // export, or a typo would silently widen the payload.
        $this->assertSame([], redash_export_request::parse_sections('tokencosts,feedbck'));
        $this->assertSame(
            ['tokencosts', 'feedbck'],
            redash_export_request::unknown_sections('tokencosts,feedbck'));
    }

    public function test_unknown_sections_empty_for_valid_input(): void {
        $this->assertSame([], redash_export_request::unknown_sections('courses,feedback'));
        $this->assertSame([], redash_export_request::unknown_sections(''));
        $this->assertSame([], redash_export_request::unknown_sections('all'));
    }

    public function test_absent_since_applies_the_default_window(): void {
        $now = 1785600000;
        // -1 is the endpoint's "parameter absent" sentinel.
        $this->assertSame(
            $now - (90 * DAYSECS),
            redash_export_request::resolve_since(-1, $now, 90));
        $this->assertSame(
            $now - (30 * DAYSECS),
            redash_export_request::resolve_since(-1, $now, 30));
    }

    public function test_explicit_since_is_honoured(): void {
        $now = 1785600000;
        $this->assertSame(1780000000, redash_export_request::resolve_since(1780000000, $now, 90));
    }

    public function test_explicit_zero_still_means_all_time(): void {
        // A deliberate backfill must remain possible.
        $now = 1785600000;
        $this->assertSame(0, redash_export_request::resolve_since(0, $now, 90));
    }

    public function test_zero_window_restores_the_all_time_default(): void {
        $now = 1785600000;
        $this->assertSame(0, redash_export_request::resolve_since(-1, $now, 0));
        $this->assertSame(0, redash_export_request::resolve_since(-1, $now, -5));
    }

    public function test_window_never_produces_a_negative_timestamp(): void {
        // A clock early in the epoch must not yield a negative lower bound.
        $this->assertSame(0, redash_export_request::resolve_since(-1, 1000, 90));
    }

    public function test_window_days_falls_back_when_unset(): void {
        $this->resetAfterTest();
        $this->assertSame(
            redash_export_request::DEFAULT_WINDOW_DAYS,
            redash_export_request::window_days());

        set_config('redash_export_window_days', '', 'local_ai_course_assistant');
        $this->assertSame(
            redash_export_request::DEFAULT_WINDOW_DAYS,
            redash_export_request::window_days());
    }

    public function test_window_days_reads_the_admin_setting(): void {
        $this->resetAfterTest();
        set_config('redash_export_window_days', '14', 'local_ai_course_assistant');
        $this->assertSame(14, redash_export_request::window_days());

        set_config('redash_export_window_days', '0', 'local_ai_course_assistant');
        $this->assertSame(0, redash_export_request::window_days());
    }

    public function test_resolve_since_uses_the_setting_when_no_override_given(): void {
        $this->resetAfterTest();
        set_config('redash_export_window_days', '7', 'local_ai_course_assistant');
        $now = 1785600000;
        $this->assertSame($now - (7 * DAYSECS), redash_export_request::resolve_since(-1, $now));
    }

    public function test_deanonymize_is_denied_by_default(): void {
        $this->resetAfterTest();
        $this->assertFalse(redash_export_request::deanonymize_allowed());
    }

    public function test_deanonymize_requires_the_setting_to_be_on(): void {
        $this->resetAfterTest();
        set_config('redash_allow_deanonymized', 0, 'local_ai_course_assistant');
        $this->assertFalse(redash_export_request::deanonymize_allowed());

        set_config('redash_allow_deanonymized', 1, 'local_ai_course_assistant');
        $this->assertTrue(redash_export_request::deanonymize_allowed());
    }
}
