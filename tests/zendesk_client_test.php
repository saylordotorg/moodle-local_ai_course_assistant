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
 * Tests for zendesk_client ticket body formatting (v6.9.0 page-context line).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\zendesk_client
 */
final class zendesk_client_test extends \basic_testcase {

    /**
     * The page URL appears on its own line, between Course and Original Question.
     */
    public function test_body_includes_page_line_after_course(): void {
        $user = (object) ['firstname' => 'Ana', 'lastname' => 'Morales', 'email' => 'ana@example.com'];
        $course = (object) ['fullname' => 'ESL001: Elementary English as a Second Language'];
        $pageurl = 'https://learn.example.org/mod/quiz/view.php?id=42';

        $body = zendesk_client::format_ticket_body($user, $course, $pageurl, 'Hola?', 'Summary here.');

        $this->assertStringContainsString("Page: {$pageurl}\n", $body);
        $cpos = strpos($body, 'Course:');
        $ppos = strpos($body, 'Page:');
        $qpos = strpos($body, 'Original Question:');
        $this->assertNotFalse($ppos);
        $this->assertTrue($cpos < $ppos, 'Page line should come after Course');
        $this->assertTrue($ppos < $qpos, 'Page line should come before Original Question');
    }

    /**
     * An empty page URL omits the Page line entirely (no dangling label).
     */
    public function test_body_omits_page_line_when_empty(): void {
        $user = (object) ['firstname' => 'A', 'lastname' => 'B', 'email' => 'a@example.com'];
        $course = (object) ['fullname' => 'C'];

        $body = zendesk_client::format_ticket_body($user, $course, '   ', 'q', 's');

        $this->assertStringNotContainsString('Page:', $body);
        $this->assertStringContainsString('Course: C', $body);
        $this->assertStringContainsString('Original Question: q', $body);
    }
}
