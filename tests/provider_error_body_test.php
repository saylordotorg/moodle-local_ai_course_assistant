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
 * A provider error must carry the provider's own explanation.
 *
 * Issue #219: a restored course pointed at a model that did not exist. The
 * provider said so in the response body, and the streaming path threw that
 * body away and called check_http_error() with a hard-coded empty string, so
 * every streaming 4xx surfaced as "[HTTP 400: ]" with nothing after the colon.
 * The bug took a manual per-course config comparison to find because the one
 * artifact that would have named it was deleted in flight.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_error_body_test extends \advanced_testcase {

    /**
     * Read the streaming helper's source once.
     *
     * @return string
     */
    private function stream_source(): string {
        global $CFG;
        $src = file_get_contents(
            $CFG->dirroot . '/local/ai_course_assistant/classes/provider/base_provider.php'
        );
        $this->assertNotFalse($src, 'base_provider.php is unreadable');
        return $src;
    }

    /**
     * The error path must pass the captured body, never a hard-coded empty string.
     */
    public function test_streaming_error_passes_the_body_to_check_http_error(): void {
        $src = $this->stream_source();

        $this->assertStringNotContainsString(
            "check_http_error(\$httpcode, '')",
            $src,
            'The streaming error path calls check_http_error() with a hard-coded empty '
            . 'string, so every provider 4xx renders as "[HTTP 400: ]" and the vendor\'s '
            . 'explanation is lost. Pass the captured error body instead.'
        );

        $this->assertMatchesRegularExpression(
            '/check_http_error\(\s*\$httpcode,\s*\R?\s*\\\\?local_ai_course_assistant\\\\security::redact_secrets\(\$errbody\)/',
            $src,
            'The captured error body must be passed through redact_secrets() before it '
            . 'reaches an exception or an audit row.'
        );
    }

    /**
     * The capture must be bounded, or an HTML error page could bloat the audit row.
     */
    public function test_error_body_capture_is_bounded(): void {
        $src = $this->stream_source();

        $this->assertStringContainsString(
            'ERROR_BODY_LIMIT',
            $src,
            'The error-body capture must be bounded by an explicit limit.'
        );
        $this->assertMatchesRegularExpression(
            '/strlen\(\$errbody\)\s*<\s*self::ERROR_BODY_LIMIT/',
            $src,
            'The accumulator must check the limit before appending, so a large error '
            . 'page cannot grow it without bound.'
        );
    }

    /**
     * The write callback must still consume every byte, so a retry stays safe.
     */
    public function test_error_body_capture_still_consumes_the_whole_body(): void {
        $src = $this->stream_source();
        $start = strpos($src, "'CURLOPT_WRITEFUNCTION'");
        $this->assertNotFalse($start, 'the streaming write callback is gone');
        $body = substr($src, $start, 1400);

        $this->assertMatchesRegularExpression(
            '/return strlen\(\$data\);\s*\/\/ Still consume it all/',
            $body,
            'The write callback must return the full length even while diverting the '
            . 'error body, or curl aborts the transfer and the bounded retry is no '
            . 'longer safe.'
        );
    }

    /**
     * A restored model this site cannot serve must be cleared, not preserved.
     */
    public function test_restore_clears_a_model_this_site_cannot_serve(): void {
        global $CFG;
        $src = file_get_contents(
            $CFG->dirroot . '/local/ai_course_assistant/backup/moodle2/'
            . 'restore_local_ai_course_assistant_plugin.class.php'
        );
        $this->assertNotFalse($src);

        $this->assertStringContainsString(
            'drop_unservable_model',
            $src,
            'A restore must not import a model name this site cannot serve; issue #219 '
            . 'was exactly that, and every learner in the course got a provider 400.'
        );

        $call = strpos($src, '$this->drop_unservable_model($data);');
        $insert = strpos($src, "insert_record('local_ai_course_assistant_course_cfg'");
        $this->assertNotFalse($call, 'the guard is defined but never called');
        $this->assertNotFalse($insert);
        $this->assertLessThan(
            $insert,
            $call,
            'The guard must run BEFORE the row is written, or the unservable model is '
            . 'persisted and the guard accomplishes nothing.'
        );
    }
}
