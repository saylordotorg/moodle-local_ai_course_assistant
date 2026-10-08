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

/**
 * Test fixture: scripted HTTP for the provider self-heal tests.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_course_assistant;

/**
 * Scripted replacement for the two HTTP methods every provider calls.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait fake_http {
    /** @var array Remaining scripted responses. */
    public array $script = [];

    /** @var array Decoded bodies sent, in order. */
    public array $sent = [];

    /** @var string Provider id to report. */
    public string $fakeid = 'openai';

    /**
     * Report a real provider id, not the anonymous class name.
     *
     * @return string
     */
    public function provider_id(): string {
        return $this->fakeid;
    }

    /**
     * Non-streaming send.
     *
     * @param string $url
     * @param array $headers
     * @param string $body
     * @return string
     */
    protected function http_post(string $url, array $headers, string $body): string {
        $this->sent[] = json_decode($body, true);
        $next = array_shift($this->script);
        if (is_array($next)) {
            $this->check_http_error((int) $next[0], (string) $next[1]);
        }
        return (string) $next;
    }

    /**
     * Streaming send. An entry ['partial', status, body] forwards bytes then fails.
     *
     * @param string $url
     * @param array $headers
     * @param string $body
     * @param callable $writecallback
     * @return void
     */
    protected function http_post_stream(string $url, array $headers, string $body, callable $writecallback): void {
        $this->sent[] = json_decode($body, true);
        $next = array_shift($this->script);
        if (is_array($next) && $next[0] === 'partial') {
            $writecallback("data: " . json_encode(['choices' => [['delta' => ['content' => 'Par']]]]) . "\n");
            $this->check_http_error((int) $next[1], (string) $next[2]);
        }
        if (is_array($next)) {
            $this->check_http_error((int) $next[0], (string) $next[1]);
        }
        $writecallback((string) $next);
    }
}
