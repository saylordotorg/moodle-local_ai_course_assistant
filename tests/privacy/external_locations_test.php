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

namespace local_ai_course_assistant\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\external_location;
use core_privacy\local\metadata\types\subsystem_link;

/**
 * Every place learner data leaves Moodle is declared in the privacy metadata.
 *
 * CONTRIB-10574 #340: the reviewer found Soapbox storage and WhatsApp delivery
 * missing after the earlier pass declared five destinations. An audit of every
 * outbound call then found more (the embedding and rerank service, the Redash
 * analytics export, the talking-avatar vendor, Moodle's AI subsystem, and data
 * the AI and voice providers receive that their fields did not name). This
 * file pins the full list, field by field, and the guard at the bottom fails
 * when a file starts calling out without saying where its data goes.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\privacy\provider::get_metadata
 */
final class external_locations_test extends \advanced_testcase {
    /**
     * Every external destination and exactly the personal data sent to it.
     *
     * A field listed here that the code does not send is as wrong as a field
     * the code sends that is missing, so the comparison is exact.
     */
    private const EXPECTED = [
        'ai_provider' => [
            'message', 'coursecontext', 'firstname', 'history', 'learnerprofile', 'submission', 'videoframes',
        ],
        'voice_provider' => ['audio', 'text', 'recording', 'instructions'],
        'zendesk' => ['name', 'email', 'message', 'coursecontext'],
        'radar_webhook' => ['report'],
        'code_sandbox_runtime' => ['ipaddress', 'useragent'],
        'soapbox_storage' => ['recording', 'slidedeck', 'frames', 'objectkey', 'ipaddress'],
        'whatsapp_delivery' => ['phonenumber', 'message'],
        'embedding_provider' => ['query'],
        'analytics_export' => ['learner', 'usage', 'feedback', 'survey', 'transcript', 'radarreport'],
        'talking_avatar' => ['audio', 'video', 'ipaddress'],
    ];

    /**
     * Files under classes/ and the plugin root that make outbound HTTP calls.
     *
     * Each reason is either "declared:" followed by the destination(s) above it
     * sends personal data to, or "not personal:" followed by why nothing about a
     * learner is in the request. Keep it short and true: a file added here with a
     * hand-wave defeats the guard.
     */
    private const OUTBOUND_ALLOWLIST = [
        'classes/autoupgrade/discovery.php' => 'not personal: lists the models a provider offers, using the site key',
        'classes/content_extractor.php' => 'not personal: fetches a course video transcript URL while indexing course content',
        'classes/embedding_provider/base_embedding_provider.php' => 'declared:embedding_provider',
        'classes/embedding_provider/voyage_reranker.php' => 'declared:embedding_provider',
        'classes/external/get_realtime_token.php' => 'declared:voice_provider',
        'classes/external/soapbox_get_playback.php' => 'declared:soapbox_storage',
        'classes/external/soapbox_render_deck.php' => 'declared:soapbox_storage',
        'classes/external/warm_stt.php' => 'not personal: an empty wake-up request to the self-hosted transcription server',
        'classes/policy_bundle.php' => 'not personal: downloads the signed settings bundle',
        'classes/price_source.php' => 'not personal: downloads a model price list',
        'classes/provider/base_provider.php' => 'declared:ai_provider',
        'classes/provider/openai_compatible_provider.php' => 'declared:ai_provider',
        'classes/radar_delivery.php' => 'declared:radar_webhook',
        'classes/rate_card_refresher.php' => 'not personal: downloads a model price list',
        'classes/redash_client.php' => 'declared:analytics_export',
        'classes/reminder_manager.php' => 'declared:whatsapp_delivery',
        'classes/remote_config_manager.php' => 'not personal: downloads remote configuration',
        'classes/soapbox_gesture_vision.php' => 'declared:soapbox_storage,ai_provider',
        'classes/soapbox_scorer.php' => 'declared:soapbox_storage,voice_provider',
        'classes/soapbox_storage.php' => 'declared:soapbox_storage',
        'classes/soapbox_storage_probe.php' => 'not personal: writes and reads back a test object holding no learner data',
        'classes/talking_avatar/base_provider.php' => 'declared:talking_avatar',
        'classes/zendesk_client.php' => 'declared:zendesk',
        'soapbox_transcribe.php' => 'declared:voice_provider',
        'transcribe.php' => 'declared:voice_provider',
        'tts.php' => 'declared:voice_provider',
    ];

    /**
     * Code (comments stripped) that opens a connection to another host.
     */
    private const OUTBOUND_PATTERNS = [
        '/new\s+\\\\?curl\s*\(/i',
        '/\bcurl_init\s*\(/i',
        '/\b(?:file_get_contents|fopen)\s*\(\s*[\'"]https?:/i',
        '/\b(?:fsockopen|stream_socket_client)\s*\(/i',
        '/\\\\core\\\\http_client\b/i',
        '/\bdownload_file_content\s*\(/i',
    ];

    /**
     * The plugin's external locations, keyed by name, with their field names.
     *
     * @return array
     */
    private static function declared(): array {
        $out = [];
        foreach (provider::get_metadata(new collection('local_ai_course_assistant'))->get_collection() as $item) {
            if ($item instanceof external_location) {
                $out[$item->get_name()] = array_keys($item->get_privacy_fields());
            }
        }
        return $out;
    }

    /**
     * Soapbox storage is declared with the recording and everything uploaded with it.
     *
     * @return void
     */
    public function test_soapbox_storage_is_declared_with_what_is_uploaded(): void {
        $declared = self::declared();
        $this->assertArrayHasKey('soapbox_storage', $declared, 'Soapbox object storage is not declared.');
        $this->assertSame(self::EXPECTED['soapbox_storage'], $declared['soapbox_storage']);
    }

    /**
     * WhatsApp delivery is declared with the phone number and the message.
     *
     * @return void
     */
    public function test_whatsapp_delivery_is_declared_with_the_number_and_message(): void {
        $declared = self::declared();
        $this->assertArrayHasKey('whatsapp_delivery', $declared, 'WhatsApp reminder delivery is not declared.');
        $this->assertSame(self::EXPECTED['whatsapp_delivery'], $declared['whatsapp_delivery']);
    }

    /**
     * The full list of destinations and fields is exactly what the audit found.
     *
     * @return void
     */
    public function test_every_destination_is_declared_with_exactly_its_fields(): void {
        $this->assertSame(self::EXPECTED, self::declared());
    }

    /**
     * Routing chat through Moodle's AI subsystem is declared as a subsystem link.
     *
     * @return void
     */
    public function test_the_core_ai_subsystem_is_linked(): void {
        $links = [];
        foreach (provider::get_metadata(new collection('local_ai_course_assistant'))->get_collection() as $item) {
            if ($item instanceof subsystem_link) {
                $links[$item->get_name()] = $item->get_summary();
            }
        }
        $this->assertArrayHasKey('core_ai', $links);
        $this->assertSame('privacy:metadata:core_ai', $links['core_ai']);
    }

    /**
     * Every summary and field of every declared destination has an English string.
     *
     * @return void
     */
    public function test_every_destination_string_exists(): void {
        $sm = get_string_manager();
        $missing = [];
        foreach (provider::get_metadata(new collection('local_ai_course_assistant'))->get_collection() as $item) {
            if (!($item instanceof external_location) && !($item instanceof subsystem_link)) {
                continue;
            }
            $keys = array_values($item->get_privacy_fields());
            $keys[] = $item->get_summary();
            foreach ($keys as $key) {
                if (!$sm->string_exists($key, 'local_ai_course_assistant')) {
                    $missing[] = $key;
                }
            }
        }
        $this->assertSame([], $missing, 'Privacy strings missing from lang/en: ' . implode(', ', $missing));
    }

    /**
     * The PHP files the guard scans: everything under classes/ plus the plugin root.
     *
     * @param string $root The plugin directory.
     * @return array Paths relative to the plugin directory.
     */
    private static function scanned_files(string $root): array {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/classes', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        foreach (glob($root . '/*.php') as $file) {
            $files[] = basename($file);
        }
        sort($files);
        return $files;
    }

    /**
     * Whether a PHP source makes an outbound call, ignoring comments.
     *
     * @param string $source PHP source code.
     * @return bool
     */
    public static function calls_out(string $source): bool {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }
        foreach (self::OUTBOUND_PATTERNS as $pattern) {
            if (preg_match($pattern, $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The guard: a file that starts calling another host must say where its data goes.
     *
     * @return void
     */
    public function test_every_file_that_calls_out_is_on_the_allowlist(): void {
        $root = dirname(__DIR__, 2);
        $unlisted = [];
        $stale = [];
        foreach (self::scanned_files($root) as $rel) {
            $out = self::calls_out((string) file_get_contents($root . '/' . $rel));
            if ($out && !isset(self::OUTBOUND_ALLOWLIST[$rel])) {
                $unlisted[] = $rel;
            } else if (!$out && isset(self::OUTBOUND_ALLOWLIST[$rel])) {
                $stale[] = $rel;
            }
        }
        $this->assertSame(
            [],
            $unlisted,
            "These files make outbound HTTP calls and are not in OUTBOUND_ALLOWLIST:\n  " . implode("\n  ", $unlisted)
                . "\nIf the request carries anything about a learner, declare the destination in"
                . " classes/privacy/provider.php get_metadata() with add_external_location_link() and list the file"
                . " here as 'declared:<name>'; otherwise list it as 'not personal: <why>'."
        );
        $this->assertSame([], $stale, 'OUTBOUND_ALLOWLIST names files that no longer call out: ' . implode(', ', $stale));
    }

    /**
     * Every allowlist reason is well formed, and a declared one names real destinations.
     *
     * @return void
     */
    public function test_every_allowlist_reason_names_a_declared_destination(): void {
        $declared = self::declared();
        $bad = [];
        foreach (self::OUTBOUND_ALLOWLIST as $rel => $reason) {
            if (strpos($reason, 'not personal: ') === 0 && strlen($reason) > strlen('not personal: ')) {
                continue;
            }
            if (strpos($reason, 'declared:') !== 0) {
                $bad[] = $rel . ' (reason must start with declared: or not personal:)';
                continue;
            }
            foreach (explode(',', substr($reason, strlen('declared:'))) as $name) {
                if (!isset($declared[trim($name)])) {
                    $bad[] = $rel . ' (' . trim($name) . ' is not declared)';
                }
            }
        }
        $this->assertSame([], $bad);
    }

    /**
     * The scanner itself: comments don't count, and each kind of call does.
     *
     * @return void
     */
    public function test_the_scanner_sees_calls_and_ignores_comments(): void {
        $this->assertFalse(self::calls_out("<?php\n// \$c = new \\curl();\n/* curl_init() */\n"));
        $this->assertFalse(self::calls_out("<?php\nif (!function_exists('curl_init')) { return; }\n"));
        $this->assertTrue(self::calls_out("<?php\n\$c = new \\curl();\n"));
        $this->assertTrue(self::calls_out("<?php\n\$h = curl_init(\$url);\n"));
        $this->assertTrue(self::calls_out("<?php\n\$b = file_get_contents('https://example.com/x');\n"));
        $this->assertTrue(self::calls_out("<?php\n\$c = new \\core\\http_client();\n"));
    }
}
