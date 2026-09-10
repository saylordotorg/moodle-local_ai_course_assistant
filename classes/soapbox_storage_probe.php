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
 * On-demand round-trip probe for Soapbox object storage (v7.4.3).
 *
 * Soapbox stores learner recordings in S3 via presigned URLs. Until this
 * existed there was no way to find out whether the configured credentials
 * actually worked except to have a learner record a speech and lose it --
 * the settings page shows a key is present, which is not the same as the key
 * being valid, the bucket existing, or the IAM policy granting the four
 * operations Soapbox needs.
 *
 * So this performs a real round-trip against the configured bucket with a
 * tiny object under a `__selftest/` prefix: PUT, HEAD (size), GET (byte
 * comparison), DELETE, then HEAD again to confirm it is gone. Each operation
 * is reported separately, because which one fails is the diagnosis: a PUT
 * failure is usually credentials or s3:PutObject, a GET failure with a
 * successful PUT is s3:GetObject, and a DELETE failure leaves rows the
 * retention task can never clean up.
 *
 * Runs only when an admin presses Run -- never on page load, and never on a
 * cron path.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class soapbox_storage_probe {

    /** @var string Probe passed. */
    public const STATUS_PASS = 'pass';

    /** @var string Probe raised a non-fatal concern. */
    public const STATUS_WARN = 'warn';

    /** @var string Probe failed. */
    public const STATUS_FAIL = 'fail';

    /** @var string Key prefix for probe objects, so they are obvious in the bucket. */
    private const PROBE_PREFIX = '__selftest/';

    /**
     * Build a result row.
     *
     * @param string $status One of the STATUS_* constants.
     * @param string $message Human-readable detail.
     * @return array
     */
    private static function row(string $status, string $message): array {
        return ['status' => $status, 'message' => $message];
    }

    /**
     * Run the full storage round-trip.
     *
     * @return array List of ['label' => string, 'status' => string, 'message' => string].
     */
    public static function run_all(): array {
        global $CFG, $USER;
        require_once($CFG->libdir . '/filelib.php');

        $rows = [];

        // 1. Configuration. Report which piece is missing rather than a bare "not configured",
        //    because the settings page shows five separate fields.
        $bucket = trim((string) get_config('local_ai_course_assistant', 'soapbox_storage_bucket'));
        $region = trim((string) get_config('local_ai_course_assistant', 'soapbox_storage_region'));
        $haskey = trim((string) get_config('local_ai_course_assistant', 'soapbox_storage_key')) !== '';
        $hassecret = trim((string) get_config('local_ai_course_assistant', 'soapbox_storage_secret')) !== '';

        $missing = [];
        if (!$haskey) {
            $missing[] = 'access key';
        }
        if (!$hassecret) {
            $missing[] = 'secret key';
        }
        if ($bucket === '') {
            $missing[] = 'bucket';
        }
        if ($region === '') {
            $missing[] = 'region';
        }

        if ($missing) {
            $rows[] = ['label' => 'Configuration'] + self::row(
                self::STATUS_FAIL,
                'Missing: ' . implode(', ', $missing) . '. Soapbox cannot store recordings until these are set.'
            );
            return $rows;
        }

        $rows[] = ['label' => 'Configuration'] + self::row(
            self::STATUS_PASS,
            "Bucket {$bucket} in {$region}; credentials present."
        );

        $storage = new soapbox_storage();
        // A distinctive body so a GET that silently returns someone else's object,
        // or an HTML error page, cannot be mistaken for success.
        $body = 'sola-soapbox-selftest-' . bin2hex(random_bytes(8));
        $key = self::PROBE_PREFIX . 'probe-' . (int) $USER->id . '-' . time() . '.txt';
        $uploaded = false;

        // 2. PUT.
        try {
            $puturl = $storage->presign_put($key, 300);
            $curl = new \curl();
            // Same reason as soapbox_storage::delete_object(): Moodle's curl helpers
            // add an Authorization header, and S3 refuses a request carrying both
            // that and a query-string SigV4 signature.
            $curl->put($puturl, ['file' => $body], ['CURLOPT_HTTPHEADER' => ['Authorization:']]);
            $code = (int) ($curl->get_info()['http_code'] ?? 0);
            if ($code >= 200 && $code < 300) {
                $uploaded = true;
                $rows[] = ['label' => 'Upload (PUT)'] + self::row(
                    self::STATUS_PASS,
                    "Wrote {$key} (HTTP {$code})."
                );
            } else {
                $rows[] = ['label' => 'Upload (PUT)'] + self::row(
                    self::STATUS_FAIL,
                    "HTTP {$code}. Check the credentials and that the IAM policy grants "
                    . "s3:PutObject on {$bucket}/" . soapbox_storage::prefix() . '*.'
                );
                return $rows;
            }
        } catch (\Throwable $e) {
            $rows[] = ['label' => 'Upload (PUT)'] + self::row(
                self::STATUS_FAIL,
                security::redact_secrets($e->getMessage())
            );
            return $rows;
        }

        // 3. HEAD — size.
        $size = $storage->object_size($key);
        if ($size === null) {
            $rows[] = ['label' => 'Size (HEAD)'] + self::row(
                self::STATUS_WARN,
                'HEAD did not return a size. Uploads may still work, but the retention '
                . 'task uses this to report storage use. Check s3:GetObject / s3:ListBucket.'
            );
        } else if ($size !== strlen($body)) {
            $rows[] = ['label' => 'Size (HEAD)'] + self::row(
                self::STATUS_WARN,
                "Reported {$size} bytes, expected " . strlen($body) . '.'
            );
        } else {
            $rows[] = ['label' => 'Size (HEAD)'] + self::row(
                self::STATUS_PASS,
                "{$size} bytes, matching what was written."
            );
        }

        // 4. GET — and compare the bytes, not just the status code.
        try {
            $geturl = $storage->presign_get($key, 300);
            $curl = new \curl();
            $got = $curl->get($geturl);
            $code = (int) ($curl->get_info()['http_code'] ?? 0);
            if ($code >= 200 && $code < 300 && (string) $got === $body) {
                $rows[] = ['label' => 'Download (GET)'] + self::row(
                    self::STATUS_PASS,
                    'Retrieved the object and the contents match byte for byte.'
                );
            } else if ($code >= 200 && $code < 300) {
                $rows[] = ['label' => 'Download (GET)'] + self::row(
                    self::STATUS_FAIL,
                    'HTTP ' . $code . ' but the body did not match what was uploaded. '
                    . 'A proxy or bucket policy may be returning something else.'
                );
            } else {
                $rows[] = ['label' => 'Download (GET)'] + self::row(
                    self::STATUS_FAIL,
                    "HTTP {$code}. Learners could record but never play back. "
                    . 'Check that the IAM policy grants s3:GetObject.'
                );
            }
        } catch (\Throwable $e) {
            $rows[] = ['label' => 'Download (GET)'] + self::row(
                self::STATUS_FAIL,
                security::redact_secrets($e->getMessage())
            );
        }

        // 5. DELETE, and 6. confirm it is actually gone. Always attempt the delete,
        //    even if earlier steps failed, so the probe does not litter the bucket.
        if ($uploaded) {
            if ($storage->delete_object($key)) {
                $stillthere = $storage->object_size($key);
                if ($stillthere === null) {
                    $rows[] = ['label' => 'Delete'] + self::row(
                        self::STATUS_PASS,
                        'Deleted, and a follow-up HEAD confirms it is gone.'
                    );
                } else {
                    $rows[] = ['label' => 'Delete'] + self::row(
                        self::STATUS_WARN,
                        'DELETE reported success but the object is still readable. '
                        . 'Retention may not actually remove learner recordings.'
                    );
                }
            } else {
                $rows[] = ['label' => 'Delete'] + self::row(
                    self::STATUS_FAIL,
                    'Delete failed, so the retention task cannot clean up recordings. '
                    . "The probe object {$key} is still in the bucket -- remove it by hand. "
                    . 'Check that the IAM policy grants s3:DeleteObject.'
                );
            }
        }

        return $rows;
    }
}
