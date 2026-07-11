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
 * SigV4 presigner and key/prefix helpers for Soapbox storage (v6.8.13).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\soapbox_storage
 */
final class soapbox_storage_test extends \advanced_testcase {

    /**
     * Pin SigV4 against AWS's published presigned-URL worked example
     * (S3 GET https://examplebucket.s3.amazonaws.com/test.txt, 2013-05-24,
     * X-Amz-Expires=86400). The documented signature is
     * aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404.
     */
    public function test_sigv4_matches_aws_published_example(): void {
        $url = soapbox_storage::presign_url([
            'host'      => 'examplebucket.s3.amazonaws.com',
            'region'    => 'us-east-1',
            'service'   => 's3',
            'accesskey' => 'AKIAIOSFODNN7EXAMPLE',
            'secretkey' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'method'    => 'GET',
            'uri'       => '/test.txt',
            'expires'   => 86400,
            'timestamp' => gmmktime(0, 0, 0, 5, 24, 2013),
        ]);
        $this->assertStringContainsString(
            'X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
            $url);
        // Sanity on the query shape.
        $this->assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $url);
        $this->assertStringContainsString(
            'X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request', $url);
        $this->assertStringContainsString('X-Amz-Expires=86400', $url);
        $this->assertStringContainsString('X-Amz-SignedHeaders=host', $url);
    }

    public function test_encode_key_path_preserves_slashes(): void {
        $this->assertSame('/soapbox/2/5/abc.mp4',
            soapbox_storage::encode_key_path('soapbox/2/5/abc.mp4'));
        $this->assertSame('/soapbox/2/5/abc.mp4',
            soapbox_storage::encode_key_path('/soapbox/2/5/abc.mp4'));
        // A space in a segment is percent-encoded, slashes are not.
        $this->assertSame('/a%20b/c', soapbox_storage::encode_key_path('a b/c'));
    }

    public function test_prefix_normalizes_slashes(): void {
        $this->resetAfterTest();
        set_config('soapbox_storage_prefix', '/videos/soapbox/', 'local_ai_course_assistant');
        $this->assertSame('videos/soapbox/', soapbox_storage::prefix());
        set_config('soapbox_storage_prefix', '', 'local_ai_course_assistant');
        $this->assertSame('soapbox/', soapbox_storage::prefix());
    }

    public function test_defaults_and_host(): void {
        $this->resetAfterTest();
        set_config('soapbox_storage_bucket', '', 'local_ai_course_assistant');
        set_config('soapbox_storage_region', '', 'local_ai_course_assistant');
        $this->assertSame('archive-course', soapbox_storage::bucket());
        $this->assertSame('us-east-1', soapbox_storage::region());
        $this->assertSame('archive-course.s3.us-east-1.amazonaws.com', soapbox_storage::host());
    }

    public function test_make_object_key_under_prefix(): void {
        $this->resetAfterTest();
        set_config('soapbox_storage_prefix', 'soapbox/', 'local_ai_course_assistant');
        $key = soapbox_storage::make_object_key(7, 42, 'MP4');
        $this->assertStringStartsWith('soapbox/7/42/', $key);
        $this->assertStringEndsWith('.mp4', $key);
    }

    public function test_make_deck_key_under_learner_path(): void {
        $this->resetAfterTest();
        set_config('soapbox_storage_prefix', 'soapbox/', 'local_ai_course_assistant');
        $key = soapbox_storage::make_deck_key(7, 42);
        // Starts with the learner's own path (so the finalize ownership check
        // passes) and is a PDF under a deck/ segment.
        $this->assertStringStartsWith('soapbox/7/42/', $key);
        $this->assertStringContainsString('/deck/', $key);
        $this->assertStringEndsWith('.pdf', $key);
    }
}
