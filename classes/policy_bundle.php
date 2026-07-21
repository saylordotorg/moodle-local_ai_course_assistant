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
 * Signed policy bundle: behavior-as-data updates without a code deploy.
 *
 * A bundle is a single JSON envelope hosted at an admin-configured URL:
 *
 *   {
 *     "format":    "sola-policy-bundle-v1",
 *     "payload":   "<base64 of the payload JSON bytes>",
 *     "signature": "<base64 Ed25519 detached signature over those bytes>"
 *   }
 *
 * The payload:
 *
 *   {
 *     "version":  7,                      // strictly increasing integer
 *     "issued_at": "2026-06-11T12:00:00Z",
 *     "comment":  "raise premium triggers",
 *     "settings": { "premium_escalation_triggers": "...", ... }
 *   }
 *
 * A daily scheduled task fetches the envelope and applies it ONLY when all
 * of the following hold (fail closed — any violation rejects the whole
 * bundle and applies nothing):
 *
 *   1. The Ed25519 signature verifies against the admin-configured public
 *      key. The private key never leaves the operator's machine, so a
 *      compromised hosting bucket cannot alter site behavior.
 *   2. Every settings key is on the hard-coded ALLOWED_KEYS list below —
 *      behavior data only. API keys, URLs, webhooks, email addresses, and
 *      security settings can never be set by a bundle, signed or not.
 *   3. The payload version is strictly greater than the last applied
 *      version (replay/rollback protection).
 *
 * Every application writes an audit row listing the changed keys with old
 * and new values. Authoring tooling (keygen / sign / verify / sync) lives
 * in admin/cli/policy_bundle_tool.php.
 *
 * This mechanism moves DATA, never code. No PHP, JS, or templates can come
 * through a bundle; code changes still go through git and the deploy
 * pipeline.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class policy_bundle {

    /** @var string Envelope format identifier. */
    public const FORMAT = 'sola-policy-bundle-v1';

    /** @var int Maximum envelope size in bytes (fetch + parse guard). */
    public const MAX_BUNDLE_BYTES = 65536;

    /**
     * Settings a bundle may set. Behavior data only — deliberately excludes
     * every *_apikey, *_apibaseurl, URL, webhook, email-recipient, consent,
     * privacy, SSRF, emergency, and voice/STT setting. Fail closed: a bundle
     * containing ANY key not on this list is rejected in full.
     *
     * @var string[]
     */
    public const ALLOWED_KEYS = [
        // Chat routing and prompt shape.
        'provider',
        'model',
        'temperature',
        'maxhistory',
        'prompt_verbosity',
        'failover_per_call_enabled',
        'failover_timeout_chat',
        'failover_timeout_voice',
        'spend_failover_chain',
        // Prompt budgeting (v5.10) and proportions (v6.2).
        'prompt_budget_chars',
        'prompt_budget_auto_tune',
        'prompt_section_weights',
        'prompt_section_weights_coach',
        'prompt_context_boost_mode',
        // History selection (v6.2).
        'history_mode',
        'history_candidates',
        'history_semantic_minscore',
        // Mastery classifier (v5.11).
        'mastery_classifier_provider',
        'mastery_classifier_model',
        'mastery_classifier_threshold',
        'mastery_classifier_weight',
        // Premium escalation tier (v5.12).
        'premium_escalation_enabled',
        'premium_escalation_provider',
        'premium_escalation_model',
        'premium_escalation_triggers',
        'premium_escalation_course_tags',
        // Widget behavior (v6.5 avatar animation probe).
        'avatar_animation_enabled',
        // RAG tuning (v6.2 floor/boost, v5.11 rerank, parent-document expansion).
        'rag_enabled',
        'rag_min_similarity',
        'rag_currentpage_boost',
        'rag_return_scope',
        'rag_window_size',
        'rag_parent_max_chars',
        'rerank_enabled',
        'rerank_model',
        'rerank_candidates',
        // Spend policy (v5.13/v6.0).
        'spend_cap_site',
        'spend_cap_chat',
        'spend_cap_voice',
        'spend_cap_rag',
        'spend_cap_analytics',
        'spend_cap_period',
        'spend_cap_per_course_default',
        'cost_anomaly_enabled',
        'cost_anomaly_multiplier',
    ];

    /**
     * Fetch the configured bundle URL and apply it if valid and newer.
     *
     * Called by the daily scheduled task and by the CLI --sync mode. All
     * outcomes (including errors) are recorded in policy_bundle_last_sync /
     * policy_bundle_last_result so the settings page can show sync health.
     *
     * @return array ['status' => 'applied'|'skipped'|'error', 'detail' => string]
     */
    public static function sync(): array {
        if (!get_config('local_ai_course_assistant', 'policy_bundle_enabled')) {
            return self::record('skipped', 'policy bundle sync disabled');
        }
        $url = trim((string) get_config('local_ai_course_assistant', 'policy_bundle_url'));
        $pubkey = trim((string) get_config('local_ai_course_assistant', 'policy_bundle_pubkey'));
        if ($url === '' || $pubkey === '') {
            return self::record('skipped', 'bundle URL or public key not configured');
        }
        if (!security::is_safe_provider_url($url)) {
            return self::record('error', 'bundle URL failed SSRF validation');
        }

        // Raw curl, same as the other SOLA provider endpoints: the plugin's
        // SSRF validator above (including the admin trusted-endpoints
        // allowlist) is the security layer, consistent across all SOLA
        // outbound calls. Redirects are refused so the verified URL is the
        // fetched URL.
        global $CFG;
        require_once($CFG->libdir . '/filelib.php'); // For \curl.
        $curl = new \curl();
        $curl->setopt(array_merge([
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT'        => 20,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_MAXFILESIZE'    => self::MAX_BUNDLE_BYTES,
            // Pin to the validated IP, closing the DNS-rebinding window.
        ], security::resolve_pin_options($url)));
        $json = $curl->get($url);
        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($httpcode !== 200 || !is_string($json) || $json === '') {
            return self::record('error', 'bundle fetch failed (HTTP ' . $httpcode . ')');
        }

        return self::process_envelope($json);
    }

    /**
     * Validate and apply an envelope JSON string. Network-free seam used by
     * sync(), the CLI verifier, and the unit tests.
     *
     * @param string $json Raw envelope JSON.
     * @return array ['status' => 'applied'|'skipped'|'error', 'detail' => string]
     */
    public static function process_envelope(string $json): array {
        $pubkey = trim((string) get_config('local_ai_course_assistant', 'policy_bundle_pubkey'));
        if ($pubkey === '') {
            return self::record('skipped', 'public key not configured');
        }

        try {
            $payload = self::verify_envelope($json, $pubkey);
        } catch (\moodle_exception $e) {
            return self::record('error', $e->getMessage());
        }

        $version = (int) $payload['version'];
        $applied = (int) (get_config('local_ai_course_assistant', 'policy_bundle_applied_version') ?: 0);
        if ($version <= $applied) {
            return self::record('skipped', "bundle version {$version} is not newer than applied {$applied}");
        }

        // Apply. Keys and value types were validated in verify_envelope().
        // Wrap the whole apply (settings + version stamp) in one transaction so
        // a crash mid-loop cannot leave a partially-applied bundle whose version
        // was never advanced (which would re-apply the remainder next sync).
        global $DB;
        $changes = [];
        $tx = $DB->start_delegated_transaction();
        foreach ($payload['settings'] as $key => $value) {
            $old = get_config('local_ai_course_assistant', $key);
            $new = self::scalar_to_config($value);
            if ((string) $old === $new) {
                continue;
            }
            set_config($key, $new, 'local_ai_course_assistant');
            $changes[$key] = ['old' => ($old === false) ? null : (string) $old, 'new' => $new];
        }

        set_config('policy_bundle_applied_version', (string) $version, 'local_ai_course_assistant');
        $tx->allow_commit();
        audit_logger::log('policy_bundle_applied', 0, 0, [
            'version'   => $version,
            'issued_at' => $payload['issued_at'] ?? '',
            'comment'   => $payload['comment'] ?? '',
            'changed'   => $changes,
        ]);

        $detail = 'applied version ' . $version . ' (' . count($changes) . ' setting(s) changed)';
        return self::record('applied', $detail);
    }

    /**
     * Verify an envelope and return its decoded payload array.
     *
     * Checks: envelope shape, format id, strict base64, Ed25519 signature,
     * payload shape (positive int version, non-empty settings object),
     * allowlist membership, and scalar value types. Throws on any failure;
     * applies nothing.
     *
     * @param string $json Raw envelope JSON.
     * @param string $pubkeyb64 Base64-encoded 32-byte Ed25519 public key.
     * @return array Decoded payload.
     * @throws \moodle_exception On any validation failure.
     */
    public static function verify_envelope(string $json, string $pubkeyb64): array {
        if (strlen($json) > self::MAX_BUNDLE_BYTES) {
            throw new \moodle_exception("policy_bundle:invalid", "local_ai_course_assistant", "",
                'bundle exceeds ' . self::MAX_BUNDLE_BYTES . ' bytes');
        }
        $envelope = json_decode($json, true, 8);
        if (!is_array($envelope)) {
            throw new \moodle_exception("policy_bundle:invalid", "local_ai_course_assistant", "",
                'envelope is not valid JSON');
        }
        if (($envelope['format'] ?? '') !== self::FORMAT) {
            throw new \moodle_exception("policy_bundle:invalid", "local_ai_course_assistant", "",
                'unknown bundle format');
        }

        $payloadbytes = base64_decode((string) ($envelope['payload'] ?? ''), true);
        $signature = base64_decode((string) ($envelope['signature'] ?? ''), true);
        $pubkey = base64_decode($pubkeyb64, true);
        if ($payloadbytes === false || $signature === false || $pubkey === false) {
            throw new \moodle_exception("policy_bundle:invalid", "local_ai_course_assistant", "",
                'envelope fields are not valid base64');
        }
        if (strlen($pubkey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new \moodle_exception("policy_bundle:invalid", "local_ai_course_assistant", "",
                'public key or signature has the wrong length');
        }
        if (!sodium_crypto_sign_verify_detached($signature, $payloadbytes, $pubkey)) {
            throw new \moodle_exception("policy_bundle:invalid", "local_ai_course_assistant", "",
                'signature verification FAILED');
        }

        $payload = json_decode($payloadbytes, true, 8);
        if (!is_array($payload)) {
            throw new \moodle_exception("policy_bundle:invalid", "local_ai_course_assistant", "",
                'payload is not valid JSON');
        }
        $version = $payload['version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new \moodle_exception("policy_bundle:invalid", "local_ai_course_assistant", "",
                'payload version must be a positive integer');
        }
        $settings = $payload['settings'] ?? null;
        if (!is_array($settings) || $settings === []) {
            throw new \moodle_exception("policy_bundle:invalid", "local_ai_course_assistant", "",
                'payload settings must be a non-empty object');
        }
        foreach ($settings as $key => $value) {
            if (!in_array($key, self::ALLOWED_KEYS, true)) {
                throw new \moodle_exception("policy_bundle:invalid", "local_ai_course_assistant", "",
                    "setting '{$key}' is not on the policy bundle allowlist; bundle rejected in full");
            }
            if (!is_scalar($value)) {
                throw new \moodle_exception("policy_bundle:invalid", "local_ai_course_assistant", "",
                    "setting '{$key}' has a non-scalar value; bundle rejected in full");
            }
        }
        return $payload;
    }

    /**
     * Normalize a scalar payload value to Moodle config string form.
     *
     * @param mixed $value Scalar from the payload.
     * @return string
     */
    private static function scalar_to_config($value): string {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return (string) $value;
    }

    /**
     * Record the outcome of a sync attempt and pass it through.
     *
     * @param string $status One of applied|skipped|error.
     * @param string $detail Human-readable outcome.
     * @return array ['status' => ..., 'detail' => ...]
     */
    private static function record(string $status, string $detail): array {
        set_config('policy_bundle_last_sync', (string) time(), 'local_ai_course_assistant');
        set_config('policy_bundle_last_result', $status . ': ' . $detail, 'local_ai_course_assistant');
        return ['status' => $status, 'detail' => $detail];
    }
}
