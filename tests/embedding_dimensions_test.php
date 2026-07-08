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

use local_ai_course_assistant\embedding_provider\base_embedding_provider;
use local_ai_course_assistant\embedding_provider\openai_embedding_provider;
use local_ai_course_assistant\embedding_provider\voyage_embedding_provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Embedding output-width defaulting (v6.8.10).
 *
 * An unset embed_dimensions must resolve to 0 ("provider native"), never a
 * hard 1536 -- that OpenAI-shaped width made every Voyage embedding call fail.
 * Voyage additionally guards its output_dimension so an invalid configured
 * width (e.g. a 1536 left over from a provider switch) is omitted rather than
 * sent and rejected.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\embedding_provider\base_embedding_provider
 * @covers     \local_ai_course_assistant\embedding_provider\voyage_embedding_provider::mrl_output_dimension
 */
final class embedding_dimensions_test extends \advanced_testcase {

    public function test_unset_dimensions_default_to_native_zero(): void {
        $this->resetAfterTest();
        unset_config('embed_dimensions', 'local_ai_course_assistant');
        $provider = new openai_embedding_provider();
        $this->assertSame(0, $provider->get_dimensions());
    }

    public function test_empty_string_dimensions_default_to_native_zero(): void {
        $this->resetAfterTest();
        set_config('embed_dimensions', '', 'local_ai_course_assistant');
        $provider = new voyage_embedding_provider();
        $this->assertSame(0, $provider->get_dimensions());
    }

    public function test_explicit_dimensions_are_honored(): void {
        $this->resetAfterTest();
        set_config('embed_dimensions', '512', 'local_ai_course_assistant');
        $provider = new openai_embedding_provider();
        $this->assertSame(512, $provider->get_dimensions());
    }

    public function test_voyage_mrl_omits_unset_native_and_invalid_widths(): void {
        // 0/unset, the native 1024, and any non-MRL width (incl. an
        // OpenAI-shaped 1536) must omit output_dimension.
        $this->assertNull(voyage_embedding_provider::mrl_output_dimension(0));
        $this->assertNull(voyage_embedding_provider::mrl_output_dimension(1024));
        $this->assertNull(voyage_embedding_provider::mrl_output_dimension(1536));
        $this->assertNull(voyage_embedding_provider::mrl_output_dimension(999));
        $this->assertNull(voyage_embedding_provider::mrl_output_dimension(-1));
    }

    public function test_voyage_mrl_sends_valid_non_native_widths(): void {
        $this->assertSame(256, voyage_embedding_provider::mrl_output_dimension(256));
        $this->assertSame(512, voyage_embedding_provider::mrl_output_dimension(512));
        $this->assertSame(2048, voyage_embedding_provider::mrl_output_dimension(2048));
    }
}
