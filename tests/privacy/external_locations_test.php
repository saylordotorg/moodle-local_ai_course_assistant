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
     * Every external destination and the personal data the 7.12.2 audit found sent to it.
     *
     * The comparison with get_metadata() is exact, so neither can change without
     * the other. That only proves the two agree: a field missing from BOTH (data
     * the code sends that nobody listed) isn't caught here. The audit table in
     * the 7.12.2 task report is the evidence for the list itself, and the scans
     * below catch a new outbound call site, not new data on an existing one.
     */
    private const EXPECTED = [
        'ai_provider' => [
            'message', 'coursecontext', 'firstname', 'history', 'learnerprofile', 'submission', 'videoframes',
            'otherproviders',
        ],
        'voice_provider' => ['audio', 'text', 'recording', 'instructions', 'ipaddress'],
        'zendesk' => ['name', 'email', 'message', 'coursecontext', 'ticket'],
        'radar_webhook' => ['report', 'email'],
        'code_sandbox_runtime' => ['ipaddress', 'useragent'],
        'soapbox_storage' => ['recording', 'slidedeck', 'frames', 'objectkey', 'ipaddress'],
        'whatsapp_delivery' => ['phonenumber', 'message'],
        'embedding_provider' => ['query'],
        'analytics_export' => ['learner', 'usage', 'feedback', 'survey', 'transcript', 'radarreport'],
        'talking_avatar' => ['audio', 'video', 'ipaddress'],
        'widget_cdn' => ['ipaddress', 'useragent'],
        'browser_speech' => ['audio', 'text'],
    ];

    /**
     * Files under classes/, admin/ and the plugin root that make outbound HTTP calls.
     *
     * Known gaps of the pattern scan: `new \curl;` without parentheses, a class
     * instantiated through a variable (`new $class()`), and `file_get_contents()`
     * or `fopen()` of a URL held in a variable aren't seen. Every such call in the
     * tree today is also in a file listed here for another reason, or none exists.
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
        'admin/cli/generate_conversational_fixtures.php' => 'not personal: a model writes questions about course content',
        'admin/cli/run_rag_fixture_benchmark.php' => 'not personal: scores retrieval on fixture questions and course content',
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
     * Browser-side code (amd/src, templates) that reaches another host from the learner's browser.
     *
     * Both misses found in review of the first 7.12.2 pass (the widget CDN and
     * browser speech) were browser-side, which the PHP scan can't see. Same rule
     * as the PHP allowlist: "declared:<link>" or "not personal: <why>".
     */
    private const BROWSER_ALLOWLIST = [
        'amd/src/realtime.js' => 'declared:voice_provider',
        'amd/src/sandbox.js' => 'declared:code_sandbox_runtime',
        'amd/src/soapbox.js' => 'declared:browser_speech',
        'amd/src/speech.js' => 'declared:browser_speech',
        'amd/src/ui.js' => 'declared:talking_avatar',
        'amd/src/voice.js' => 'declared:browser_speech',
        'templates/chat_widget.mustache' => 'declared:widget_cdn',
    ];

    /**
     * JavaScript (comments stripped) that opens a connection or loads code from elsewhere.
     *
     * Not seen: a fetch() or XMLHttpRequest to a URL held in a variable, such as
     * the presigned S3 upload in soapbox.js (declared as soapbox_storage).
     */
    private const JS_PATTERNS = [
        '/new\s+WebSocket\s*\(/',
        '/createElement\s*\(\s*[\'"](?:script|iframe)[\'"]/i',
        '/SpeechRecognition/',
        '/(?:fetch\s*\(|\.src\s*=|\.open\s*\()\s*(?:[\'"\x60][A-Z]+[\'"\x60]\s*,\s*)?[\'"\x60](?:https?|wss?):\/\//i',
    ];

    /**
     * Mustache markup (comments stripped) that loads a script, stylesheet or frame from a variable or another host.
     */
    private const TEMPLATE_PATTERNS = [
        '/<(?:script|iframe)\b[^>]*\bsrc\s*=\s*["\']?\s*(?:\{\{|https?:|\/\/)/i',
        '/<link\b[^>]*\bhref\s*=\s*["\']?\s*(?:\{\{|https?:|\/\/)/i',
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
     * The PHP files the guard scans: everything under classes/ and admin/, plus the plugin root.
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
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/admin', \FilesystemIterator::SKIP_DOTS));
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
     * Whether JavaScript source reaches another host, ignoring comments.
     *
     * @param string $source JavaScript source.
     * @return bool
     */
    public static function js_calls_out(string $source): bool {
        $code = preg_replace('~/\*.*?\*/~s', '', $source);
        $code = preg_replace('~^\s*//.*$~m', '', $code);
        foreach (self::JS_PATTERNS as $pattern) {
            if (preg_match($pattern, $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a Mustache template loads something from a variable or another host, ignoring comments.
     *
     * @param string $source Template source.
     * @return bool
     */
    public static function template_calls_out(string $source): bool {
        $code = preg_replace('~\{\{!.*?\}\}~s', '', $source);
        $code = preg_replace('~<!--.*?-->~s', '', $code);
        foreach (self::TEMPLATE_PATTERNS as $pattern) {
            if (preg_match($pattern, $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The browser-side guard: a script or template that reaches another host must say where data goes.
     *
     * @return void
     */
    public function test_every_browser_file_that_calls_out_is_on_the_allowlist(): void {
        $root = dirname(__DIR__, 2);
        $unlisted = [];
        $stale = [];
        $files = [];
        foreach (glob($root . '/amd/src/*.js') as $f) {
            $files['amd/src/' . basename($f)] = self::js_calls_out((string) file_get_contents($f));
        }
        foreach (glob($root . '/templates/*.mustache') as $f) {
            $files['templates/' . basename($f)] = self::template_calls_out((string) file_get_contents($f));
        }
        $this->assertNotEmpty($files, 'No browser files found, so this scan proves nothing.');
        foreach ($files as $rel => $out) {
            if ($out && !isset(self::BROWSER_ALLOWLIST[$rel])) {
                $unlisted[] = $rel;
            } else if (!$out && isset(self::BROWSER_ALLOWLIST[$rel])) {
                $stale[] = $rel;
            }
        }
        $this->assertSame(
            [],
            $unlisted,
            "These browser files reach another host and are not in BROWSER_ALLOWLIST:\n  " . implode("\n  ", $unlisted)
                . "\nDeclare the destination in classes/privacy/provider.php get_metadata() and list the file"
                . " as 'declared:<name>', or as 'not personal: <why>'."
        );
        $this->assertSame([], $stale, 'BROWSER_ALLOWLIST names files that no longer call out: ' . implode(', ', $stale));
    }

    /**
     * Every browser allowlist reason is well formed, and a declared one names real destinations.
     *
     * @return void
     */
    public function test_every_browser_allowlist_reason_names_a_declared_destination(): void {
        $declared = self::declared();
        $bad = [];
        foreach (self::BROWSER_ALLOWLIST as $rel => $reason) {
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
     * The browser scanners: comments don't count, and each kind of reach does.
     *
     * @return void
     */
    public function test_the_browser_scanners_see_calls_and_ignore_comments(): void {
        $this->assertFalse(self::js_calls_out("/* new WebSocket(u) */\n// SpeechRecognition\nvar a = 1;\n"));
        $this->assertFalse(self::js_calls_out("fetch(M.cfg.wwwroot + '/x.php');\n"));
        $this->assertTrue(self::js_calls_out("var ws = new WebSocket(url);\n"));
        $this->assertTrue(self::js_calls_out("var s = document.createElement('script');\n"));
        $this->assertTrue(self::js_calls_out("var f = document.createElement(\"iframe\");\n"));
        $this->assertTrue(self::js_calls_out("var r = new window.webkitSpeechRecognition();\n"));
        $this->assertTrue(self::js_calls_out("fetch('https://example.com/collect');\n"));
        $this->assertTrue(self::js_calls_out("xhr.open('POST', 'https://example.com/x');\n"));
        $this->assertTrue(self::js_calls_out("img.src = 'https://example.com/p.gif';\n"));
        $this->assertFalse(self::template_calls_out("{{! <script src=\"{{x}}\"></script> }}\n<div></div>\n"));
        $this->assertTrue(self::template_calls_out("<script src=\"{{cdn_js_url}}\" defer></script>\n"));
        $this->assertTrue(self::template_calls_out("<link rel=\"stylesheet\" href=\"https://cdn.example/x.css\" />\n"));
        $this->assertTrue(self::template_calls_out("<iframe src=\"{{url}}\"></iframe>\n"));
    }

    /**
     * Every plugin user preference the code sets is declared.
     *
     * @return void
     */
    public function test_every_user_preference_is_declared(): void {
        $names = [];
        foreach (provider::get_metadata(new collection('local_ai_course_assistant'))->get_collection() as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\user_preference) {
                $names[] = $item->get_name();
            }
        }
        sort($names);
        $this->assertSame([
            'aica_sola_consent_given',
            'local_ai_course_assistant_avatar',
            'local_ai_course_assistant_digest_optin_COURSEID',
            'local_ai_course_assistant_intro_dismissed',
            'local_ai_course_assistant_last_courseid',
            'sola_outreach_milestones',
        ], $names);

        // And the code sets no preference that isn't on the list.
        $root = dirname(__DIR__, 2);
        $set = [];
        foreach (self::scanned_files($root) as $rel) {
            $src = (string) file_get_contents($root . '/' . $rel);
            if (preg_match_all("/set_user_preference\\(\\s*'([a-z0-9_]+)'/i", $src, $m)) {
                foreach ($m[1] as $name) {
                    $set[$name] = true;
                }
            }
        }
        $missing = [];
        foreach (array_keys($set) as $name) {
            $declared = in_array($name, $names, true)
                || ($name === 'local_ai_course_assistant_digest_optin_');
            if (!$declared) {
                $missing[] = $name;
            }
        }
        $this->assertNotEmpty($set, 'Found no set_user_preference call, so this check proves nothing.');
        $this->assertSame([], $missing, 'Preferences set but not declared: ' . implode(', ', $missing));
    }

    /**
     * A user's preferences are exported with a readable description; another user's are not.
     *
     * @return void
     */
    public function test_user_preferences_are_exported(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        set_user_preference('local_ai_course_assistant_avatar', 'avatar_03', $user);
        set_user_preference('local_ai_course_assistant_last_courseid', $course->id, $user);
        set_user_preference('local_ai_course_assistant_intro_dismissed', 1, $user);
        set_user_preference('aica_sola_consent_given', 1760000000, $user);
        set_user_preference('sola_outreach_milestones', '1', $user);
        set_user_preference('local_ai_course_assistant_digest_optin_' . $course->id, '1', $user);
        set_user_preference('local_ai_course_assistant_avatar', 'avatar_07', $other);

        provider::export_user_preferences($user->id);
        $writer = \core_privacy\local\request\writer::with_context(\context_system::instance());
        $prefs = $writer->get_user_preferences('local_ai_course_assistant');

        $this->assertSame('avatar_03', $prefs->local_ai_course_assistant_avatar->value);
        $this->assertSame((string) $course->id, (string) $prefs->local_ai_course_assistant_last_courseid->value);
        $this->assertSame('1', (string) $prefs->local_ai_course_assistant_intro_dismissed->value);
        $consent = \core_privacy\local\request\transform::datetime(1760000000);
        $this->assertSame($consent, $prefs->aica_sola_consent_given->value);
        $this->assertSame('1', (string) $prefs->sola_outreach_milestones->value);
        $digest = 'local_ai_course_assistant_digest_optin_' . $course->id;
        $this->assertSame('1', (string) $prefs->$digest->value);
        foreach ((array) $prefs as $name => $pref) {
            $this->assertNotSame('', trim((string) $pref->description), "No description for {$name}");
        }
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
