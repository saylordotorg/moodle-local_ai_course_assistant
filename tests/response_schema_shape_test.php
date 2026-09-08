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
 * Structured-output schemas must reach the wire intact (v7.3.2, finding F17).
 *
 * Two shapes are passed by callers in this plugin: a wrapped
 * ['name' => .., 'schema' => [..]] and a bare JSON Schema. The providers only
 * read the wrapped one, so score_speech, score_essay and generate_flashcards --
 * which all pass bare schemas -- transmitted "schema": null. Every Soapbox
 * speech score, essay score and flashcard generation on a non-core-AI provider
 * failed, and score_speech reported it as a generic provider outage with the
 * real reason discarded.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\openai_compatible_provider
 * @covers     \local_ai_course_assistant\provider\claude_provider
 */
final class response_schema_shape_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Invoke a provider's protected build_body().
     *
     * @param object $provider
     * @param array $options
     * @return array
     */
    private function build(object $provider, array $options): array {
        $m = new \ReflectionMethod($provider, 'build_body');
        $m->setAccessible(true);
        $raw = $m->invoke($provider, 'sys', [['role' => 'user', 'content' => 'hi']], false, $options);
        return is_array($raw) ? $raw : json_decode($raw, true);
    }

    /**
     * A bare JSON Schema must arrive as the schema, not as null.
     */
    public function test_bare_schema_reaches_the_openai_body(): void {
        $p = new \local_ai_course_assistant\provider\openai_provider(['apikey' => 'x', 'model' => 'gpt-4o-mini']);
        $bare = [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string']],
            'required' => ['a'],
            'additionalProperties' => false,
        ];

        $body = $this->build($p, ['response_schema' => $bare]);
        $schema = $body['response_format']['json_schema']['schema'] ?? null;

        $this->assertIsArray($schema, 'a bare schema must not arrive as null');
        $this->assertSame('object', $schema['type']);
        $this->assertSame(['a' => ['type' => 'string']], $schema['properties']);
    }

    /**
     * The wrapped shape must keep working -- generate_quiz and the classifier
     * depend on it, so the tolerance must not become a regression.
     */
    public function test_wrapped_schema_still_reaches_the_openai_body(): void {
        $p = new \local_ai_course_assistant\provider\openai_provider(['apikey' => 'x', 'model' => 'gpt-4o-mini']);
        $wrapped = [
            'name' => 'my_tool',
            'schema' => ['type' => 'object', 'properties' => ['b' => ['type' => 'integer']]],
        ];

        $body = $this->build($p, ['response_schema' => $wrapped]);

        $this->assertSame('my_tool', $body['response_format']['json_schema']['name']);
        $this->assertSame(['b' => ['type' => 'integer']],
            $body['response_format']['json_schema']['schema']['properties']);
    }

    /**
     * Same tolerance on the Claude tool-use path.
     */
    public function test_both_shapes_reach_the_claude_body(): void {
        $p = new \local_ai_course_assistant\provider\claude_provider(['apikey' => 'x', 'model' => 'claude-sonnet-5']);

        $bare = $this->build($p, ['response_schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]);
        $this->assertIsArray($bare['tools'][0]['input_schema']);
        $this->assertSame('object', $bare['tools'][0]['input_schema']['type']);

        $wrapped = $this->build($p, ['response_schema' => [
            'name' => 't', 'schema' => ['type' => 'object', 'properties' => ['b' => ['type' => 'integer']]],
        ]]);
        $this->assertSame(['b' => ['type' => 'integer']], $wrapped['tools'][0]['input_schema']['properties']);
    }

    /**
     * Every schema this plugin sends must carry additionalProperties:false on
     * each object node, because the provider hard-codes strict => true and
     * OpenAI rejects a strict schema without it.
     */
    public function test_shipped_schemas_are_strict_mode_valid(): void {
        $m = new \ReflectionMethod(\local_ai_course_assistant\external\generate_flashcards::class, 'build_response_schema');
        $m->setAccessible(true);
        $schema = $m->invoke(null, false);

        $this->assertArrayHasKey('additionalProperties', $schema,
            'root object needs additionalProperties:false under strict mode');
        $this->assertFalse($schema['additionalProperties']);
        $this->assertFalse($schema['properties']['cards']['items']['additionalProperties'],
            'nested object nodes need it too');
    }
}
