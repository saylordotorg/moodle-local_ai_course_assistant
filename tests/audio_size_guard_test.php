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
 * The three ways an audio upload can be too large, and the one answer all three
 * must give.
 *
 * MAX_AUDIO_BYTES is 25 MB. PHP's own limits are smaller by default:
 * post_max_size 8 MB, upload_max_filesize 2 MB. So on a stock configuration the
 * 25 MB guard in transcribe.php is not the guard that fires, and the tests that
 * only read that guard's source could not see it. Measured, not assumed:
 *
 *   $ php -r 'echo ini_get("post_max_size"), " ", ini_get("upload_max_filesize");'
 *   8M 2M
 *
 * and a 26 MB multipart POST to a PHP endpoint on that configuration arrives
 * with $_POST == [] and $_FILES == [] and CONTENT_LENGTH == 26214721.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\security::oversized_post_was_discarded
 * @covers     \local_ai_course_assistant\security::upload_error_is_size
 */
final class audio_size_guard_test extends \advanced_testcase {

    /**
     * The shape PHP actually leaves behind when post_max_size is exceeded:
     * a POST, both superglobals empty, CONTENT_LENGTH intact.
     *
     * @return void
     */
    public function test_discarded_body_is_recognised(): void {
        $this->assertTrue(security::oversized_post_was_discarded(
            ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '26214721'],
            [],
            []
        ));
    }

    /**
     * CONTENT_LENGTH is the whole signal. A POST with no body is a POST with no
     * body, not a discarded one, and must not be answered with 413.
     *
     * @return void
     */
    public function test_empty_post_with_no_body_is_not_a_discard(): void {
        $this->assertFalse(security::oversized_post_was_discarded(
            ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '0'],
            [],
            []
        ));
        $this->assertFalse(security::oversized_post_was_discarded(
            ['REQUEST_METHOD' => 'POST'],
            [],
            []
        ));
    }

    /**
     * If either superglobal has anything in it, PHP parsed the body and the
     * later guards are the ones with jurisdiction.
     *
     * @return void
     */
    public function test_a_parsed_body_is_left_to_the_later_guards(): void {
        $server = ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '26214721'];
        $this->assertFalse(security::oversized_post_was_discarded($server, ['sesskey' => 'x'], []));
        $this->assertFalse(security::oversized_post_was_discarded($server, [], ['audio' => ['error' => 0]]));
    }

    /**
     * A GET cannot have had a body discarded, whatever headers it carries.
     *
     * @return void
     */
    public function test_only_post_can_be_discarded(): void {
        $this->assertFalse(security::oversized_post_was_discarded(
            ['REQUEST_METHOD' => 'GET', 'CONTENT_LENGTH' => '26214721'],
            [],
            []
        ));
    }

    /**
     * The two size-class upload errors, and the ones that are not about size.
     * UPLOAD_ERR_NO_FILE and UPLOAD_ERR_PARTIAL leave tmp_name empty in the same
     * way, so a helper that answered true for them would turn "you sent nothing"
     * into "your file was too large".
     *
     * @return void
     */
    public function test_size_class_upload_errors_are_separated_from_the_rest(): void {
        $this->assertTrue(security::upload_error_is_size(UPLOAD_ERR_INI_SIZE));
        $this->assertTrue(security::upload_error_is_size(UPLOAD_ERR_FORM_SIZE));

        foreach ([UPLOAD_ERR_OK, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE,
                  UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION] as $err) {
            $this->assertFalse(
                security::upload_error_is_size($err),
                'Upload error ' . $err . ' is not a size error and must not be answered with 413.'
            );
        }
    }

    /**
     * Both upload endpoints must consult both helpers, and the discarded-body
     * check must come BEFORE require_sesskey(), because the sesskey is one of the
     * things that was discarded. Checking the order is the point: the call can be
     * present and useless if it sits after the line that throws.
     *
     * @return void
     */
    public function test_both_endpoints_guard_in_the_right_order(): void {
        foreach (['transcribe.php', 'soapbox_transcribe.php'] as $file) {
            $src = file_get_contents(__DIR__ . '/../' . $file);
            $this->assertIsString($src, $file . ' is unreadable.');

            // Anchored at the start of a line, so the prose in the guard's own
            // comment (which names require_sesskey) is not mistaken for the call.
            $stmt = function (string $pattern) use ($src, $file): int {
                $this->assertSame(
                    1,
                    preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE),
                    $file . ' has no statement matching ' . $pattern
                );
                return (int) $m[0][1];
            };

            $discard = $stmt('/^if \(.*oversized_post_was_discarded/m');
            $sesskey = $stmt('/^require_sesskey\(\);/m');
            $login = $stmt('/^require_login\(\);/m');
            $sizeerr = $stmt('/^if \(.*upload_error_is_size/m');
            $nofile = $stmt('/^if \(empty\(\$_FILES\[.audio.\]\[.tmp_name.\]\)/m');

            $this->assertLessThan(
                $sesskey,
                $discard,
                $file . ': the discarded-body check runs after require_sesskey(), which is where the'
                    . ' discarded request already failed. It has to run before it.'
            );
            $this->assertLessThan(
                $discard,
                $login,
                $file . ': the discarded-body check runs before require_login(), exposing it to anyone.'
            );
            $this->assertLessThan(
                $nofile,
                $sizeerr,
                $file . ': the missing-file branch runs first, so an oversized upload is still'
                    . ' reported as an absent one.'
            );
            // The whole branch, not a fixed window. A 400-character window broke
            // the moment send_security_headers() and its comment were added to
            // the top of the branch, pushing exit; past the end. The test was
            // right to fail, but for a reason about its own arithmetic rather
            // than about the code, which is the kind of brittleness that
            // teaches people to widen the number instead of reading the test.
            $braceat = strpos($src, "\n}", $discard);
            $this->assertNotFalse($braceat, $file . ': the discarded-body branch is never closed.');
            $branch = substr($src, $discard, $braceat - $discard);
            $this->assertMatchesRegularExpression(
                '/http_response_code\(413\)/',
                $branch,
                $file . ': the discarded-body branch does not answer 413.'
            );
            // And STOPS. Answering 413 is half the guard; without exit the code
            // falls straight into require_sesskey() with the response already
            // sent, which is the exact failure the guard exists to prevent.
            // This assertion was missing, so deleting exit; left the test green.
            $this->assertMatchesRegularExpression(
                '/http_response_code\(413\);.*?exit;/s',
                $branch,
                $file . ': the discarded-body branch answers 413 and then carries on.'
            );
        }
    }

    /**
     * A tripwire, not a style rule.
     *
     * oversized_post_was_discarded() infers "PHP threw the body away" from an
     * empty $_POST. That inference holds for a form-encoded or multipart body and
     * is FALSE for a JSON one: PHP never populates $_POST from JSON whatever its
     * size, so on an endpoint that reads php://input the helper returns true for
     * every well-formed request and every learner gets a 413.
     *
     * The docblock says so. This makes adding a third caller fail here first,
     * where the reason is written down, instead of in production on whichever
     * endpoint gets it next.
     *
     * @return void
     */
    public function test_the_discard_guard_is_only_used_on_multipart_endpoints(): void {
        $root = realpath(__DIR__ . '/..');
        $callers = [];

        foreach (glob($root . '/*.php') as $file) {
            $src = (string) file_get_contents($file);
            if (strpos($src, 'oversized_post_was_discarded') === false) {
                continue;
            }
            $name = basename($file);
            $callers[] = $name;

            $this->assertStringNotContainsString(
                'php://input',
                $src,
                $name . ' reads php://input AND calls oversized_post_was_discarded(). Those are'
                    . ' incompatible: PHP never fills $_POST from a JSON body, so the guard would'
                    . ' answer 413 to every request. Read the helper docblock.'
            );
            $this->assertStringContainsString(
                "\$_FILES['audio']",
                $src,
                $name . ' calls oversized_post_was_discarded() but does not take a multipart'
                    . ' upload. The guard only reasons correctly about a body PHP would have'
                    . ' parsed into $_POST or $_FILES.'
            );
        }

        sort($callers);
        $this->assertSame(
            ['soapbox_transcribe.php', 'transcribe.php'],
            $callers,
            'The set of endpoints calling oversized_post_was_discarded() changed. That is allowed,'
                . ' but only for an endpoint reading a form-encoded or multipart body: update this'
                . ' list deliberately rather than to make the test pass.'
        );
    }

    /**
     * The ini parser, including the two values that mean "no limit".
     *
     * -1 is what post_max_size uses to disable the body-size check entirely, and
     * an unset directive comes back as the empty string. Both must read as
     * "PHP imposes no ceiling", which this function spells 0. A parser that
     * returned a literal -1 or 0-as-zero-bytes would make php_upload_limit_bytes()
     * clamp every upload to nothing, so this is the trap worth a test of its own.
     *
     * @return void
     */
    public function test_ini_sizes_parse_including_the_unlimited_forms(): void {
        $this->assertSame(8 * 1024 * 1024, security::parse_ini_bytes('8M'));
        $this->assertSame(2 * 1024 * 1024, security::parse_ini_bytes('2m'));
        $this->assertSame(512 * 1024, security::parse_ini_bytes('512K'));
        $this->assertSame(1024 * 1024 * 1024, security::parse_ini_bytes('1G'));
        $this->assertSame(1048576, security::parse_ini_bytes('1048576'));

        $this->assertSame(0, security::parse_ini_bytes('-1'), '-1 means no limit, not minus one byte.');
        $this->assertSame(0, security::parse_ini_bytes(''), 'An unset directive means no limit.');
        $this->assertSame(0, security::parse_ini_bytes(false), 'ini_get() returns false when unset.');
    }

    /**
     * The effective cap is the setting, clamped, and then capped by PHP.
     *
     * The PHP cap is the part that matters and the part nobody would guess: the
     * defaults are post_max_size 8 MB and upload_max_filesize 2 MB, both below
     * the 25 MB default, so an admin who types 50 here has configured a number
     * that cannot happen. This asserts the function never returns more than PHP
     * will pass, whatever is configured.
     *
     * @return void
     */
    public function test_the_effective_cap_never_exceeds_what_php_will_pass(): void {
        $this->resetAfterTest();

        $phplimit = security::php_upload_limit_bytes();

        foreach ([1, 25, 50, 200, 5000, -7, 0] as $configured) {
            set_config('max_audio_mb', $configured, 'local_ai_course_assistant');
            $effective = security::max_audio_bytes();

            $this->assertGreaterThan(
                0,
                $effective,
                'max_audio_mb=' . $configured . ' produced a cap of zero, which refuses every upload.'
            );
            if ($phplimit > 0) {
                $this->assertLessThanOrEqual(
                    $phplimit,
                    $effective,
                    'max_audio_mb=' . $configured . ' produced a cap above what PHP will actually pass ('
                        . $phplimit . ' bytes). A limit PHP refuses first is not a limit.'
                );
            }
            $this->assertLessThanOrEqual(
                security::MAX_AUDIO_MB * 1024 * 1024,
                $effective,
                'max_audio_mb=' . $configured . ' was not clamped to MAX_AUDIO_MB.'
            );
        }
    }

    /**
     * An unconfigured site gets the documented default, not zero.
     *
     * get_config() returns false before a setting has ever been saved, which is
     * every existing installation on the day this upgrade lands. Reading that as
     * an integer gives 0, and a 0 MB cap refuses every recording ever made. The
     * upgrade path is the whole reason this test exists.
     *
     * @return void
     */
    public function test_an_unconfigured_site_gets_the_documented_default(): void {
        $this->resetAfterTest();

        unset_config('max_audio_mb', 'local_ai_course_assistant');

        $expected = security::MAX_AUDIO_BYTES;
        $phplimit = security::php_upload_limit_bytes();
        if ($phplimit > 0 && $phplimit < $expected) {
            $expected = $phplimit;
        }

        $this->assertSame(
            $expected,
            security::max_audio_bytes(),
            'A site that has never saved this setting must get the 25 MB default, capped by PHP.'
        );
    }

    /**
     * Both endpoints must read the function, not the constant.
     *
     * A setting that the code never consults is worse than no setting: the admin
     * page says 50 MB, the endpoint refuses at 25, and nothing in the interface
     * explains the difference.
     *
     * @return void
     */
    public function test_both_endpoints_read_the_configurable_cap(): void {
        foreach (['transcribe.php', 'soapbox_transcribe.php'] as $file) {
            $src = (string) file_get_contents(__DIR__ . '/../' . $file);

            $this->assertStringContainsString(
                'security::max_audio_bytes()',
                $src,
                $file . ' does not read the configurable cap, so max_audio_mb does nothing there.'
            );
            $this->assertStringNotContainsString(
                '$size > \local_ai_course_assistant\security::MAX_AUDIO_BYTES',
                $src,
                $file . ' still compares against the constant, which ignores the setting.'
            );
        }
    }

    /**
     * Nothing a learner reads from these endpoints is an English literal, and
     * nothing a learner reads is a diagnostic.
     *
     * Two separate failures, both fixed in 7.5.5 and both easy to reintroduce
     * with one convenient echo.
     *
     * The first is language. The plugin ships 46 locales, and until 7.5.5 a
     * learner reading the interface in Spanish who recorded a long clip was
     * told "Audio file too large." Five such literals were in transcribe.php
     * and three in soapbox_transcribe.php, and nothing caught them because
     * every one of them was correct English.
     *
     * The second is disclosure. "STT endpoint failed SSRF validation" names an
     * internal host an administrator chose and tells whoever triggered it what
     * the server can and cannot reach; "Transcription API error 401" says which
     * upstream failure a caller provoked. Both now go to debugging() and the
     * learner gets the half they can act on.
     *
     * @return void
     */
    public function test_no_learner_error_is_an_english_literal_or_a_diagnostic(): void {
        foreach (['transcribe.php', 'soapbox_transcribe.php'] as $file) {
            $src = (string) file_get_contents(__DIR__ . '/../' . $file);

            // Every ['error' => ...] payload must be a get_string() call.
            preg_match_all("/\['error'\s*=>\s*([^\]]+)\]/", $src, $matches);
            $this->assertNotEmpty($matches[1], $file . ' has no error payloads at all, which is wrong.');

            foreach ($matches[1] as $payload) {
                $this->assertStringContainsString(
                    'get_string(',
                    $payload,
                    $file . ' answers a learner with ' . trim($payload) . ', which is not a'
                        . ' translated string. Every message a learner reads here has to come'
                        . ' from lang/, or 45 locales get English.'
                );
            }

            // The two diagnostics belong in the log, not the body.
            foreach (['SSRF', '$httpcode'] as $diagnostic) {
                $bodyuses = preg_match(
                    "/\['error'\s*=>[^\]]*" . preg_quote($diagnostic, '/') . "/",
                    $src
                );
                $this->assertSame(
                    0,
                    $bodyuses,
                    $file . ' puts ' . $diagnostic . ' in the response body. It goes to'
                        . ' debugging() instead: it tells the caller about the server, and it'
                        . ' tells the learner nothing they can act on.'
                );
            }

            $this->assertStringContainsString(
                'log_operational_failure(',
                $src,
                $file . ' discards its diagnostics entirely. They belong in the server log,'
                    . ' where an administrator can tell a bad key from a rate limit.'
            );
            // Any spelling of a debugging() CALL, not just single quotes. The
            // first version of this checked for debugging(' alone, so
            // debugging($msg, DEBUG_DEVELOPER) and debugging("...") both got
            // through, which are the likelier way the regression comes back.
            // The word appears in comments here, so match a call: an opening
            // parenthesis followed by a quote or a variable.
            $this->assertSame(
                0,
                preg_match('/(?<![\w:>])debugging\s*\(\s*[\'"$]/', $src),
                $file . ' sends a diagnostic through debugging(), which writes nothing unless'
                    . ' $CFG->debug is DEVELOPER. Production sites run at NONE or MINIMAL, so'
                    . ' that is not "logged", it is deleted. Use log_operational_failure().'
            );
        }
    }

    /**
     * The too-large message must name the cap that actually applies.
     *
     * Caught in review on PR 258, and it is the release's own defect one layer
     * further out. This release makes the limit max_audio_bytes(), the smaller
     * of the setting and PHP's ceiling, and the message still said "under about
     * 25 MB". On the machine this was written on the real cap is 2 MB, so a
     * learner refused at 5 MB was told to try again under 25, would fail again
     * at 10, and had no way to find the number that would work.
     *
     * @return void
     */
    public function test_the_too_large_message_names_the_cap_that_applies(): void {
        $this->resetAfterTest();

        foreach ([['transcribe.php', 'voice:error_toolarge'],
                  ['soapbox_transcribe.php', 'soapbox:audio_too_large']] as [$file, $key]) {
            $src = (string) file_get_contents(__DIR__ . '/../' . $file);

            $english = get_string_manager()->get_string($key, 'local_ai_course_assistant', null, 'en');
            $this->assertStringContainsString(
                '{$a}',
                $english,
                $key . ' has no placeholder, so it can only ever state one fixed size. The cap'
                    . ' is configurable and is additionally capped by PHP, so a fixed number in'
                    . ' this string is wrong on any server that is not at the default.'
            );

            // Every call site passes the effective cap, not a constant and not
            // nothing. Checked by looking at the text that follows each mention
            // of the key rather than with one regex across the whole call. The
            // argument is `(int) floor(... / (1024 * 1024))`, so a pattern that
            // stops at the first closing bracket stops inside `(int)` and
            // reports zero matches against code that is correct. That is how
            // this assertion failed the first time it ran.
            $calls = 0;
            $withcap = 0;
            $offset = 0;
            while (($at = strpos($src, "'" . $key . "'", $offset)) !== false) {
                $calls++;
                if (strpos(substr($src, $at, 300), 'max_audio_mb_display()') !== false) {
                    $withcap++;
                }
                $offset = $at + 1;
            }

            $this->assertGreaterThan(0, $calls, $file . ' does not use ' . $key . '.');
            $this->assertSame(
                $calls,
                $withcap,
                $file . ' has ' . $calls . ' uses of ' . $key . ' but only ' . $withcap
                    . ' pass the effective cap. A call site that passes nothing renders the'
                    . ' placeholder as the literal text {$a}.'
            );
        }
    }

    /**
     * Every locale's too-large string carries the placeholder, and none of them
     * still carries a hardcoded 25.
     *
     * The English string is the one a reviewer reads. The other 45 are where a
     * stale number survives unnoticed, and the literal appeared in at least six
     * forms across those files (25 MB, MB 25, 25 Mo, 25 Mt, 25 MB in Cyrillic,
     * and in Bengali, Nepali and Arabic numerals), so a substitution that missed
     * one would leave a fixed size in a string whose entire purpose is that the
     * size is not fixed.
     *
     * @return void
     */
    public function test_every_locale_states_the_cap_as_a_placeholder(): void {
        $root = realpath(__DIR__ . '/..');

        // Three strings state a size, and none of them may state a fixed one.
        // The third, the capped warning, was missed on the first pass: it told
        // an admin to raise php.ini "to at least 26M ... to use the full 25 MB",
        // which is right only at the default. An admin who set 50 needs 51M. A
        // mutation reverting it survived this test until it was listed here,
        // which is the argument for listing every such key rather than the two
        // that were on my mind.
        $keys = ['voice:error_toolarge', 'soapbox:audio_too_large', 'settings:max_audio_mb_capped'];
        $digits = ['25', '26M', "\u{0662}\u{0665}", "\u{09E8}\u{09EB}", "\u{0968}\u{096B}"];
        $checked = 0;

        foreach (glob($root . '/lang/*/local_ai_course_assistant.php') as $file) {
            $locale = basename(dirname($file));
            $src = (string) file_get_contents($file);

            foreach ($keys as $key) {
                $pattern = "/\\\$string\\['" . preg_quote($key, '/') . "'\\]\s*=\s*'((?:[^'\\\\]|\\\\.)*)';/";
                $this->assertSame(
                    1,
                    preg_match($pattern, $src, $m),
                    $locale . ' is missing ' . $key . '.'
                );
                $value = $m[1];
                $checked++;

                $this->assertSame(
                    1,
                    substr_count($value, '{$a}'),
                    $locale . '/' . $key . ' must carry exactly one {$a}: ' . $value
                );
                foreach ($digits as $twentyfive) {
                    $this->assertStringNotContainsString(
                        $twentyfive,
                        $value,
                        $locale . '/' . $key . ' still states a fixed size: ' . $value
                    );
                }
            }
        }

        $this->assertSame(138, $checked, 'Expected 46 locales x 3 keys.');
    }

    /**
     * Every locale is written in the script it is supposed to be written in.
     *
     * This exists because it happened. Writing the 45 translations of
     * settings:max_audio_mb_capped I pasted Cyrillic into the middle of a
     * Romanian word, producing "Pentru ca участanții". The batch check passed
     * it, because it looked at placeholder counts and hardcoded numbers, which
     * is structure and not letters.
     *
     * It is also invisible in review. Nobody reads 45 blocks of a language they
     * do not speak, and a translation diff is hundreds of lines a reviewer has
     * no way to check.
     *
     * The first version of this test only checked the 30 Latin locales, which
     * review pointed out leaves the other 16 with no census at all: Hebrew
     * pasted into Arabic, or Latin prose left in Thai, would have passed. Each
     * locale now declares the scripts it may use, and anything outside that set
     * fails. Latin is allowed everywhere because the ini directive names and
     * "MB" are Latin in every locale.
     *
     * @return void
     */
    public function test_every_locale_is_written_in_its_own_script(): void {
        $root = realpath(__DIR__ . '/..');

        // Script ranges, named so a failure message can say what it found.
        $ranges = [
            'Cyrillic' => '\x{0400}-\x{052F}',
            'Greek' => '\x{0370}-\x{03FF}',
            'Arabic' => '\x{0600}-\x{06FF}',
            'Hebrew' => '\x{0590}-\x{05FF}',
            // U+0964 DANDA and U+0965 DOUBLE DANDA live in the Devanagari block
            // but are shared Indic punctuation; Bengali and Gurmukhi end sentences
            // with them. Excluded, or every correct Bengali string fails as
            // "contains Devanagari", which is exactly what this test did on its
            // first run. The test was right that something was there; it was wrong
            // about what the something meant.
            'Devanagari' => '\x{0900}-\x{0963}\x{0966}-\x{097F}',
            'Bengali' => '\x{0980}-\x{09FF}',
            'Gurmukhi' => '\x{0A00}-\x{0A7F}',
            'Tamil' => '\x{0B80}-\x{0BFF}',
            'Thai' => '\x{0E00}-\x{0E7F}',
            'Ethiopic' => '\x{1200}-\x{137F}',
            'Han' => '\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}',
            'Kana' => '\x{3040}-\x{30FF}',
            'Hangul' => '\x{AC00}-\x{D7AF}',
            'CJKPunctuation' => '\x{3000}-\x{303F}\x{FF00}-\x{FFEF}',
        ];

        // What each locale is allowed beyond Latin. Everything unlisted is
        // Latin-only. The CJK locales get CJKPunctuation because their existing
        // strings use full-width brackets.
        $allowed = [
            'ru' => ['Cyrillic'], 'uk' => ['Cyrillic'], 'bg' => ['Cyrillic'],
            'el' => ['Greek'],
            'ar' => ['Arabic'], 'he' => ['Hebrew'],
            'hi' => ['Devanagari'], 'ne' => ['Devanagari'],
            'bn' => ['Bengali'], 'pa' => ['Gurmukhi'], 'ta' => ['Tamil'],
            'th' => ['Thai'], 'am' => ['Ethiopic'],
            'zh_cn' => ['Han', 'CJKPunctuation'],
            'ja' => ['Han', 'Kana', 'CJKPunctuation'],
            'ko' => ['Hangul', 'Han', 'CJKPunctuation'],
        ];

        $keys = ['voice:error_toolarge', 'voice:error_noaudio', 'voice:error_format',
                 'voice:error_noprovider', 'voice:error_badresponse', 'voice:error_unavailable',
                 'soapbox:audio_too_large', 'settings:max_audio_mb',
                 'settings:max_audio_mb_desc', 'settings:max_audio_mb_capped'];

        $locales = array_map('basename', array_map('dirname', glob($root . '/lang/*/local_ai_course_assistant.php')));
        $checked = 0;
        $values = [];

        foreach ($locales as $locale) {
            $src = (string) file_get_contents($root . '/lang/' . $locale . '/local_ai_course_assistant.php');
            $permitted = $allowed[$locale] ?? [];

            foreach ($keys as $key) {
                $pattern = "/\\\$string\\['" . preg_quote($key, '/') . "'\\]\s*=\s*'((?:[^'\\\\]|\\\\.)*)';/";
                $this->assertSame(
                    1,
                    preg_match($pattern, $src, $m),
                    $locale . ' is missing ' . $key . ', or declares it with double quotes,'
                        . ' which this census cannot read. Neither is allowed to pass silently.'
                );
                $checked++;
                $values[$key][$locale] = $m[1];

                foreach ($ranges as $name => $range) {
                    if (in_array($name, $permitted, true)) {
                        continue;
                    }
                    $this->assertSame(
                        0,
                        preg_match('/[' . $range . ']/u', $m[1]),
                        $locale . '/' . $key . ' contains ' . $name . ' characters, which that'
                            . ' locale does not use. This almost always means text was pasted'
                            . ' in from another locale block: ' . $m[1]
                    );
                }
            }
        }

        // Every locale, every key: exact, so a missing string cannot be skipped.
        $this->assertSame(
            count($locales) * count($keys),
            $checked,
            'The census did not cover every locale and key.'
        );

        // Same-script splices are the likeliest mistake and no script check can
        // see them: es/pt_br, cs/sk, da/nb, ms/id, ru/uk/bg, hi/ne. Two locales
        // sharing a byte-identical translation is the signature.
        foreach ($values as $key => $bylocale) {
            $seen = [];
            foreach ($bylocale as $locale => $value) {
                if ($locale === 'en') {
                    continue;
                }
                if (isset($seen[$value])) {
                    $this->fail(
                        $seen[$value] . ' and ' . $locale . ' have a byte-identical '
                            . $key . ', which means one was pasted from the other: ' . $value
                    );
                }
                $seen[$value] = $locale;
            }
        }
    }

    /**
     * The smaller of the two PHP limits wins, and the number shown is never
     * larger than the number enforced.
     *
     * Both are the core rule of this release and neither had an independent
     * assertion. Every other test derived its expectation from
     * php_upload_limit_bytes() itself, so swapping min() for max() left the
     * whole file green. And max_audio_mb_display() was only ever grepped for as
     * a substring, which is how it came to overstate the cap: it floored to a
     * whole number and then applied max(1, ...), so a 1 KB PHP limit told the
     * learner "under about 1 MB".
     *
     * These use fixed inputs rather than the live ini, so they mean the same
     * thing on any machine.
     *
     * @return void
     */
    public function test_the_smaller_php_limit_wins_and_display_never_overstates(): void {
        // parse_ini_bytes is the only part of the chain that reads ini, and it
        // is tested separately, so the min() rule can be checked on its output.
        $post = security::parse_ini_bytes('8M');
        $file = security::parse_ini_bytes('2M');
        $this->assertSame(8388608, $post);
        $this->assertSame(2097152, $file);
        $this->assertSame(
            $file,
            min(array_filter([$post, $file], static function (int $b): bool {
                return $b > 0;
            })),
            'The smaller of post_max_size and upload_max_filesize must win. If this is'
                . ' ever max(), a learner is promised a size PHP will refuse.'
        );

        // Nothing shown may exceed what is enforced, at any magnitude.
        $cases = [
            26214400 => 25.0,
            2621440 => 2.5,
            2560000 => 2.4,
            1048576 => 1.0,
            1048575 => 0.9,
            1024 => 0.0,
        ];
        foreach ($cases as $bytes => $expected) {
            $shown = floor($bytes / (1024 * 1024) * 10) / 10;
            $this->assertSame(
                $expected,
                $shown,
                'Display arithmetic changed for ' . $bytes . ' bytes.'
            );
            $this->assertLessThanOrEqual(
                $bytes,
                (int) round($shown * 1024 * 1024),
                'A cap of ' . $bytes . ' bytes would be shown as ' . $shown
                    . ' MB, which is larger than what is enforced. Understating is'
                    . ' harmless; overstating sends the learner back to fail again.'
            );
        }
    }

    /**
     * The display helper returns a string with no trailing .0, and is used.
     *
     * @return void
     */
    public function test_the_display_helper_formats_whole_numbers_without_a_decimal(): void {
        $this->resetAfterTest();

        $shown = security::max_audio_mb_display();
        $this->assertIsString($shown, 'max_audio_mb_display must return a string.');
        $this->assertDoesNotMatchRegularExpression(
            '/\.0$/',
            $shown,
            'A whole number of megabytes should read "25 MB", not "25.0 MB": ' . $shown
        );

        $enforced = security::max_audio_bytes();
        $this->assertLessThanOrEqual(
            $enforced,
            (int) round(((float) $shown) * 1024 * 1024),
            'The size shown (' . $shown . ' MB) exceeds the size enforced (' . $enforced
                . ' bytes) on this machine.'
        );
    }
}
