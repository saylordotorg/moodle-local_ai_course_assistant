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

use local_ai_course_assistant\embedding_provider\openai_embedding_provider;
use local_ai_course_assistant\embedding_provider\ollama_embedding_provider;
use local_ai_course_assistant\embedding_provider\voyage_embedding_provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests that a separate query model is only reported by providers that use one.
 *
 * get_query_model() feeds the retriever's comparability check, so it has to name
 * the model that actually produced the query vector. Reporting a configured
 * value that the adapter ignored made every stored row look incomparable and
 * emptied retrieval — with a perfectly usable query vector in hand.
 *
 * These construct providers directly rather than through the factory so no API
 * call is made; only construction-time config resolution is under test.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\embedding_provider\base_embedding_provider
 */
final class embed_query_model_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_voyage_honours_a_separate_query_model(): void {
        set_config('embed_model', 'voyage-4-large', 'local_ai_course_assistant');
        set_config('embed_query_model', 'voyage-4-lite', 'local_ai_course_assistant');
        $p = new voyage_embedding_provider();

        $this->assertTrue($p->supports_query_model());
        $this->assertSame('voyage-4-large', $p->get_model(), 'documents keep the document model');
        $this->assertSame('voyage-4-lite', $p->get_query_model(), 'queries use the query model');
    }

    public function test_openai_reports_the_document_model_even_when_a_query_model_is_set(): void {
        // The regression. openai_embedding_provider sends $this->model on every
        // call and has no shared-space sibling, so a configured query model is
        // ignored — and must therefore not be reported as though it were used.
        set_config('embed_model', 'text-embedding-3-small', 'local_ai_course_assistant');
        set_config('embed_query_model', 'text-embedding-3-large', 'local_ai_course_assistant');
        $p = new openai_embedding_provider();

        $this->assertFalse($p->supports_query_model());
        $this->assertSame('text-embedding-3-small', $p->get_query_model());
        $this->assertSame($p->get_model(), $p->get_query_model());
    }

    public function test_ollama_also_reports_the_document_model(): void {
        set_config('embed_model', 'nomic-embed-text', 'local_ai_course_assistant');
        set_config('embed_query_model', 'something-else', 'local_ai_course_assistant');
        $p = new ollama_embedding_provider();

        $this->assertFalse($p->supports_query_model());
        $this->assertSame('nomic-embed-text', $p->get_query_model());
    }

    public function test_an_unset_query_model_means_the_document_model_everywhere(): void {
        set_config('embed_model', 'voyage-4', 'local_ai_course_assistant');
        set_config('embed_query_model', '', 'local_ai_course_assistant');
        $this->assertSame('voyage-4', (new voyage_embedding_provider())->get_query_model());

        set_config('embed_model', 'text-embedding-3-small', 'local_ai_course_assistant');
        $this->assertSame('text-embedding-3-small', (new openai_embedding_provider())->get_query_model());
    }

    public function test_a_whitespace_only_query_model_is_treated_as_unset(): void {
        set_config('embed_model', 'voyage-4', 'local_ai_course_assistant');
        set_config('embed_query_model', "   \n\t ", 'local_ai_course_assistant');
        $this->assertSame('voyage-4', (new voyage_embedding_provider())->get_query_model());
    }

    public function test_a_padded_query_model_is_trimmed(): void {
        // An admin pasting a model name often brings whitespace with it, and an
        // untrimmed value would fail the comparability check against the same
        // model stored without padding.
        set_config('embed_model', 'voyage-4-large', 'local_ai_course_assistant');
        set_config('embed_query_model', '  voyage-4-lite  ', 'local_ai_course_assistant');
        $this->assertSame('voyage-4-lite', (new voyage_embedding_provider())->get_query_model());
    }

    // ---------- dtype is likewise only reported where it is honoured ----------

    public function test_only_voyage_honours_a_quantized_dtype(): void {
        set_config('embed_dtype', 'int8', 'local_ai_course_assistant');

        set_config('embed_model', 'voyage-4-lite', 'local_ai_course_assistant');
        $this->assertSame('int8', (new voyage_embedding_provider())->effective_dtype());

        // A provider that cannot return quantized vectors must fall back to
        // float, because recording int8 against float32 bytes would make every
        // affected row decode to nonsense.
        set_config('embed_model', 'text-embedding-3-small', 'local_ai_course_assistant');
        $openai = new openai_embedding_provider();
        $this->assertFalse($openai->supports_dtype());
        $this->assertSame('float', $openai->effective_dtype());
        $this->assertSame('int8', $openai->get_dtype(), 'the configured value is still readable');
    }

    public function test_a_garbage_dtype_resolves_to_float_before_it_reaches_a_provider(): void {
        set_config('embed_dtype', 'int4; DROP TABLE', 'local_ai_course_assistant');
        set_config('embed_model', 'voyage-4-lite', 'local_ai_course_assistant');
        $p = new voyage_embedding_provider();
        $this->assertSame('float', $p->get_dtype());
        $this->assertSame('float', $p->effective_dtype());
    }

    public function test_contextualized_detection_keys_off_the_document_model(): void {
        set_config('embed_model', 'voyage-context-4', 'local_ai_course_assistant');
        $this->assertTrue((new voyage_embedding_provider())->is_contextualized());

        set_config('embed_model', 'voyage-4-large', 'local_ai_course_assistant');
        $this->assertFalse((new voyage_embedding_provider())->is_contextualized());

        // Must not be fooled by the substring appearing later in a name.
        set_config('embed_model', 'my-voyage-context-4-clone', 'local_ai_course_assistant');
        $this->assertFalse((new voyage_embedding_provider())->is_contextualized());
    }
}
