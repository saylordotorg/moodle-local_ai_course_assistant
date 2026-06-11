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
 * Authoring and operations tool for signed policy bundles (v6.4.0).
 *
 * Modes:
 *   --keygen [--out=DIR]      Generate an Ed25519 keypair. Writes the private
 *                             key (base64, mode 0600) to DIR (default CWD) and
 *                             prints the public key to paste into the
 *                             policy_bundle_pubkey admin setting.
 *   --sign --payload=FILE --key=FILE [--out=FILE]
 *                             Validate a payload JSON (allowlist, version,
 *                             scalar values) and wrap it in a signed envelope.
 *   --verify --bundle=FILE [--pubkey=B64]
 *                             Verify an envelope. Public key defaults to the
 *                             site's policy_bundle_pubkey setting.
 *   --status                  Show sync configuration and last outcome.
 *   --sync                    Run a sync now (same code path as the daily task).
 *
 * Example authoring flow (private key stays on the operator's machine and
 * must NEVER be committed or uploaded):
 *   php policy_bundle_tool.php --keygen --out=$HOME/.sola
 *   php policy_bundle_tool.php --sign --payload=bundle.json --key=$HOME/.sola/sola_policy_private.key --out=signed.json
 *   aws s3 cp signed.json s3://your-bucket/sola/policy.json
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_ai_course_assistant\policy_bundle;

$usage = <<<TXT
Usage:
  php policy_bundle_tool.php --keygen [--out=DIR]
  php policy_bundle_tool.php --sign --payload=FILE --key=FILE [--out=FILE]
  php policy_bundle_tool.php --verify --bundle=FILE [--pubkey=B64]
  php policy_bundle_tool.php --status
  php policy_bundle_tool.php --sync

TXT;

[$options, $unrecognized] = cli_get_params(
    [
        'keygen'  => false,
        'sign'    => false,
        'verify'  => false,
        'status'  => false,
        'sync'    => false,
        'out'     => '',
        'payload' => '',
        'key'     => '',
        'bundle'  => '',
        'pubkey'  => '',
        'help'    => false,
    ],
    ['h' => 'help']
);

if ($options['help'] || (!$options['keygen'] && !$options['sign'] && !$options['verify']
        && !$options['status'] && !$options['sync'])) {
    cli_write($usage);
    exit(0);
}

if ($options['keygen']) {
    $dir = $options['out'] !== '' ? rtrim($options['out'], '/') : getcwd();
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        cli_error("Cannot create directory: {$dir}");
    }
    $keypair = sodium_crypto_sign_keypair();
    $secret = base64_encode(sodium_crypto_sign_secretkey($keypair));
    $public = base64_encode(sodium_crypto_sign_publickey($keypair));
    $keyfile = $dir . '/sola_policy_private.key';
    if (file_exists($keyfile)) {
        cli_error("Refusing to overwrite existing key file: {$keyfile}");
    }
    file_put_contents($keyfile, $secret . "\n");
    chmod($keyfile, 0600);
    cli_writeln("Private key written to: {$keyfile} (mode 0600)");
    cli_writeln('KEEP IT OUT of the repo, Dropbox shares, and any web-reachable path.');
    cli_writeln('');
    cli_writeln('Public key (paste into the "Policy bundle public key" admin setting):');
    cli_writeln($public);
    exit(0);
}

if ($options['sign']) {
    if ($options['payload'] === '' || $options['key'] === '') {
        cli_error('--sign requires --payload=FILE and --key=FILE');
    }
    $payloadjson = @file_get_contents($options['payload']);
    if ($payloadjson === false) {
        cli_error('Cannot read payload file: ' . $options['payload']);
    }
    $payload = json_decode($payloadjson, true, 8);
    if (!is_array($payload)) {
        cli_error('Payload is not valid JSON');
    }
    // Pre-flight the same checks the receiving site will run, so a bad
    // bundle fails here instead of silently failing on every site at 06:20.
    $version = $payload['version'] ?? null;
    if (!is_int($version) || $version < 1) {
        cli_error('payload "version" must be a positive integer');
    }
    $settings = $payload['settings'] ?? null;
    if (!is_array($settings) || $settings === []) {
        cli_error('payload "settings" must be a non-empty object');
    }
    foreach ($settings as $key => $value) {
        if (!in_array($key, policy_bundle::ALLOWED_KEYS, true)) {
            cli_error("setting '{$key}' is not on the allowlist; refusing to sign");
        }
        if (!is_scalar($value)) {
            cli_error("setting '{$key}' has a non-scalar value; refusing to sign");
        }
    }

    $secretb64 = trim((string) @file_get_contents($options['key']));
    $secret = base64_decode($secretb64, true);
    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        cli_error('Key file does not contain a valid base64 Ed25519 secret key');
    }

    // Sign the exact payload bytes as they will travel (re-encoded compact
    // JSON), so whitespace in the source file cannot break verification.
    $payloadbytes = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $signature = sodium_crypto_sign_detached($payloadbytes, $secret);
    $envelope = json_encode([
        'format'    => policy_bundle::FORMAT,
        'payload'   => base64_encode($payloadbytes),
        'signature' => base64_encode($signature),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $outfile = $options['out'] !== '' ? $options['out'] : 'signed-bundle.json';
    file_put_contents($outfile, $envelope . "\n");
    $public = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
    cli_writeln("Signed bundle written to: {$outfile}");
    cli_writeln('version: ' . $version . ', settings: ' . count($settings)
        . ', signed by public key: ' . $public);
    exit(0);
}

if ($options['verify']) {
    if ($options['bundle'] === '') {
        cli_error('--verify requires --bundle=FILE');
    }
    $json = @file_get_contents($options['bundle']);
    if ($json === false) {
        cli_error('Cannot read bundle file: ' . $options['bundle']);
    }
    $pubkey = $options['pubkey'] !== ''
        ? $options['pubkey']
        : trim((string) get_config('local_ai_course_assistant', 'policy_bundle_pubkey'));
    if ($pubkey === '') {
        cli_error('No public key: pass --pubkey=B64 or set policy_bundle_pubkey');
    }
    try {
        $payload = policy_bundle::verify_envelope($json, $pubkey);
    } catch (\moodle_exception $e) {
        cli_error('VERIFY FAILED: ' . $e->getMessage());
    }
    cli_writeln('VERIFY OK');
    cli_writeln('version:   ' . $payload['version']);
    cli_writeln('issued_at: ' . ($payload['issued_at'] ?? '(none)'));
    cli_writeln('comment:   ' . ($payload['comment'] ?? '(none)'));
    cli_writeln('settings:');
    foreach ($payload['settings'] as $key => $value) {
        cli_writeln('  ' . $key . ' = ' . var_export($value, true));
    }
    exit(0);
}

if ($options['status']) {
    $get = fn(string $k) => get_config('local_ai_course_assistant', $k);
    $lastsync = (int) ($get('policy_bundle_last_sync') ?: 0);
    cli_writeln('enabled:         ' . ($get('policy_bundle_enabled') ? 'yes' : 'no'));
    cli_writeln('url:             ' . ($get('policy_bundle_url') ?: '(not set)'));
    cli_writeln('pubkey:          ' . ($get('policy_bundle_pubkey') ? 'configured' : '(not set)'));
    cli_writeln('applied version: ' . ((int) ($get('policy_bundle_applied_version') ?: 0)));
    cli_writeln('last sync:       ' . ($lastsync ? userdate($lastsync) : 'never'));
    cli_writeln('last result:     ' . ($get('policy_bundle_last_result') ?: '(none)'));
    exit(0);
}

if ($options['sync']) {
    $result = policy_bundle::sync();
    cli_writeln($result['status'] . ': ' . $result['detail']);
    exit($result['status'] === 'error' ? 1 : 0);
}
