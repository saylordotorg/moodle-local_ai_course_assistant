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
 * Object storage for Soapbox recordings (v6.8.13): AWS SigV4 presigned URLs so
 * the browser uploads straight to S3 and the PHP server never handles the bytes.
 *
 * The AWS SDK is not bundled with Moodle, so SigV4 is implemented here. The
 * signing is a pure static method pinned in tests against AWS's published
 * presigned-URL worked example, so correctness does not depend on live calls.
 *
 * Recordings are small (tens of MB), so a single presigned PUT with client
 * retry is used rather than multipart. A daily task expires objects past the
 * retention window (a bucket lifecycle rule on the prefix is the backstop).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class soapbox_storage {

    /** @var string Default bucket (shared archive bucket, under a soapbox/ prefix). */
    const DEFAULT_BUCKET = 'archive-course';

    /** @var string Default key prefix within the bucket. */
    const DEFAULT_PREFIX = 'soapbox/';

    /** @var string Default region. */
    const DEFAULT_REGION = 'us-east-1';

    /** @var int Default presigned-URL lifetime (seconds). */
    const DEFAULT_EXPIRY = 900;

    /**
     * Configured bucket.
     *
     * @return string
     */
    public static function bucket(): string {
        $v = trim((string) get_config('local_ai_course_assistant', 'soapbox_storage_bucket'));
        return $v !== '' ? $v : self::DEFAULT_BUCKET;
    }

    /**
     * Configured region.
     *
     * @return string
     */
    public static function region(): string {
        $v = trim((string) get_config('local_ai_course_assistant', 'soapbox_storage_region'));
        return $v !== '' ? $v : self::DEFAULT_REGION;
    }

    /**
     * Configured key prefix (always ends with a slash, never starts with one).
     *
     * @return string
     */
    public static function prefix(): string {
        $v = trim((string) get_config('local_ai_course_assistant', 'soapbox_storage_prefix'));
        if ($v === '') {
            $v = self::DEFAULT_PREFIX;
        }
        return rtrim(ltrim($v, '/'), '/') . '/';
    }

    /**
     * Whether storage credentials are configured.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return trim((string) get_config('local_ai_course_assistant', 'soapbox_storage_key')) !== ''
            && trim((string) get_config('local_ai_course_assistant', 'soapbox_storage_secret')) !== '';
    }

    /**
     * The S3 virtual-hosted-style host for the configured bucket.
     *
     * @return string
     */
    public static function host(): string {
        return self::bucket() . '.s3.' . self::region() . '.amazonaws.com';
    }

    /**
     * Build a unique object key for a new recording, under the prefix.
     *
     * @param int $courseid
     * @param int $userid
     * @param string $ext File extension without the dot (mp4 / webm / ogg).
     * @return string
     */
    public static function make_object_key(int $courseid, int $userid, string $ext): string {
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($ext)) ?: 'bin';
        $token = random_string(24);
        return self::prefix() . $courseid . '/' . $userid . '/' . $token . '.' . $ext;
    }

    /**
     * Presigned PUT URL for uploading an object (browser -> S3 directly).
     *
     * @param string $key Object key (no leading slash).
     * @param int $expires Seconds until the URL expires.
     * @return string
     */
    public function presign_put(string $key, int $expires = self::DEFAULT_EXPIRY): string {
        return self::presign_url([
            'host'      => self::host(),
            'region'    => self::region(),
            'service'   => 's3',
            'accesskey' => (string) get_config('local_ai_course_assistant', 'soapbox_storage_key'),
            'secretkey' => (string) get_config('local_ai_course_assistant', 'soapbox_storage_secret'),
            'method'    => 'PUT',
            'uri'       => self::encode_key_path($key),
            'expires'   => $expires,
            'timestamp' => time(),
        ]);
    }

    /**
     * Presigned GET URL for viewing / downloading an object.
     *
     * @param string $key
     * @param int $expires
     * @return string
     */
    public function presign_get(string $key, int $expires = self::DEFAULT_EXPIRY): string {
        return self::presign_url([
            'host'      => self::host(),
            'region'    => self::region(),
            'service'   => 's3',
            'accesskey' => (string) get_config('local_ai_course_assistant', 'soapbox_storage_key'),
            'secretkey' => (string) get_config('local_ai_course_assistant', 'soapbox_storage_secret'),
            'method'    => 'GET',
            'uri'       => self::encode_key_path($key),
            'expires'   => $expires,
            'timestamp' => time(),
        ]);
    }

    /**
     * Size of an object in bytes, or null if it does not exist / cannot be read.
     * Used by finalize to confirm the browser actually uploaded before a row is
     * recorded. Signs a HEAD request (method is part of the SigV4 canonical
     * request, so a GET-signed URL cannot be reused for HEAD).
     *
     * @param string $key
     * @return int|null
     */
    public function object_size(string $key): ?int {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $url = self::presign_url([
            'host'      => self::host(),
            'region'    => self::region(),
            'service'   => 's3',
            'accesskey' => (string) get_config('local_ai_course_assistant', 'soapbox_storage_key'),
            'secretkey' => (string) get_config('local_ai_course_assistant', 'soapbox_storage_secret'),
            'method'    => 'HEAD',
            'uri'       => self::encode_key_path($key),
            'expires'   => 300,
            'timestamp' => time(),
        ]);
        $curl = new \curl();
        $curl->head($url);
        $info = $curl->get_info();
        if ((int) ($info['http_code'] ?? 0) !== 200) {
            return null;
        }
        $size = $info['download_content_length'] ?? null;
        return ($size !== null && $size >= 0) ? (int) $size : null;
    }

    /**
     * Delete an object from storage. Returns true on a 2xx/404 (already gone),
     * false on any other failure so the caller can leave the row for retry.
     * A bucket lifecycle rule on the prefix is the backstop for anything missed.
     *
     * @param string $key
     * @return bool
     */
    public function delete_object(string $key): bool {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $url = self::presign_url([
            'host'      => self::host(),
            'region'    => self::region(),
            'service'   => 's3',
            'accesskey' => (string) get_config('local_ai_course_assistant', 'soapbox_storage_key'),
            'secretkey' => (string) get_config('local_ai_course_assistant', 'soapbox_storage_secret'),
            'method'    => 'DELETE',
            'uri'       => self::encode_key_path($key),
            'expires'   => 300,
            'timestamp' => time(),
        ]);
        $curl = new \curl();
        $curl->delete($url);
        $code = (int) ($curl->get_info()['http_code'] ?? 0);
        return ($code >= 200 && $code < 300) || $code === 404;
    }

    /**
     * URI-encode an object key path, preserving slashes between segments.
     *
     * @param string $key
     * @return string Leading-slash canonical URI path.
     */
    public static function encode_key_path(string $key): string {
        $segments = explode('/', ltrim($key, '/'));
        return '/' . implode('/', array_map('rawurlencode', $segments));
    }

    /**
     * Build an AWS Signature Version 4 presigned URL (query-parameter auth).
     *
     * Pure: given identical inputs it returns an identical URL, so it is pinned
     * in tests against AWS's published worked example. `uri` must already be a
     * canonical (per-segment encoded) path beginning with '/'.
     *
     * @param array $o host, region, service, accesskey, secretkey, method, uri,
     *                 expires (int), timestamp (int), optional extraquery (assoc),
     *                 optional scheme.
     * @return string
     */
    public static function presign_url(array $o): string {
        $method = strtoupper((string) $o['method']);
        $host = (string) $o['host'];
        $region = (string) $o['region'];
        $service = (string) ($o['service'] ?? 's3');
        $uri = (string) $o['uri'];
        $expires = (int) $o['expires'];
        $ts = (int) $o['timestamp'];
        $scheme = (string) ($o['scheme'] ?? 'https');

        $amzdate = gmdate('Ymd\THis\Z', $ts);
        $datestamp = gmdate('Ymd', $ts);
        $algorithm = 'AWS4-HMAC-SHA256';
        $scope = $datestamp . '/' . $region . '/' . $service . '/aws4_request';

        $query = array_merge([
            'X-Amz-Algorithm'     => $algorithm,
            'X-Amz-Credential'    => $o['accesskey'] . '/' . $scope,
            'X-Amz-Date'          => $amzdate,
            'X-Amz-Expires'       => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ], $o['extraquery'] ?? []);
        ksort($query);
        $pairs = [];
        foreach ($query as $k => $v) {
            $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        $canonicalquery = implode('&', $pairs);

        $canonicalheaders = 'host:' . $host . "\n";
        $signedheaders = 'host';
        $payloadhash = 'UNSIGNED-PAYLOAD';
        $canonicalrequest = $method . "\n" . $uri . "\n" . $canonicalquery . "\n"
            . $canonicalheaders . "\n" . $signedheaders . "\n" . $payloadhash;

        $stringtosign = $algorithm . "\n" . $amzdate . "\n" . $scope . "\n"
            . hash('sha256', $canonicalrequest);

        $kdate = hash_hmac('sha256', $datestamp, 'AWS4' . $o['secretkey'], true);
        $kregion = hash_hmac('sha256', $region, $kdate, true);
        $kservice = hash_hmac('sha256', $service, $kregion, true);
        $ksigning = hash_hmac('sha256', 'aws4_request', $kservice, true);
        $signature = hash_hmac('sha256', $stringtosign, $ksigning);

        return $scheme . '://' . $host . $uri . '?' . $canonicalquery . '&X-Amz-Signature=' . $signature;
    }
}
