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

use local_ai_course_assistant\provider\openai_provider;

defined('MOODLE_INTERNAL') || die();

/**
 * OpenAI-compatible build_body multimodal image handling (v6.8.31): the single
 * attachment path and the multi-image image_datauris path (slide vision) both
 * become image_url content blocks on the latest user message.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\openai_compatible_provider
 */
final class provider_multimodal_test extends \advanced_testcase {

    /**
     * Invoke the protected build_body and return the decoded request body.
     *
     * @param array $messages
     * @param array $options
     * @return array
     */
    private function body(array $messages, array $options): array {
        $provider = new openai_provider(['apikey' => 'x', 'model' => 'gpt-4o-mini']);
        $method = new \ReflectionMethod($provider, 'build_body');
        $method->setAccessible(true);
        return json_decode($method->invoke($provider, 'sys', $messages, false, $options), true);
    }

    public function test_no_images_leaves_content_a_string(): void {
        $body = $this->body([['role' => 'user', 'content' => 'hi']], []);
        $last = end($body['messages']);
        $this->assertSame('hi', $last['content']);
    }

    public function test_image_datauris_become_image_url_blocks(): void {
        $uris = ['data:image/png;base64,AAAA', 'data:image/png;base64,BBBB'];
        $body = $this->body([['role' => 'user', 'content' => 'look']], ['image_datauris' => $uris]);
        $last = end($body['messages']);

        $this->assertIsArray($last['content']);
        $this->assertSame('text', $last['content'][0]['type']);
        $this->assertSame('look', $last['content'][0]['text']);

        $imageblocks = array_values(array_filter($last['content'], fn($b) => ($b['type'] ?? '') === 'image_url'));
        $this->assertCount(2, $imageblocks);
        $this->assertSame($uris[0], $imageblocks[0]['image_url']['url']);
        $this->assertSame($uris[1], $imageblocks[1]['image_url']['url']);
    }

    public function test_non_image_datauris_are_ignored(): void {
        $body = $this->body([['role' => 'user', 'content' => 'x']],
            ['image_datauris' => ['data:text/plain;base64,QQ', 'not-a-uri']]);
        $last = end($body['messages']);
        // Nothing valid to attach, so content stays a plain string.
        $this->assertSame('x', $last['content']);
    }
}
