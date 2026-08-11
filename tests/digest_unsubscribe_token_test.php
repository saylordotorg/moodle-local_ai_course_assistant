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
 * Tests for the HMAC token behind unauthenticated unsubscribe links.
 *
 * Flagged by the pre-7.0.0 audit as untested: it is the only thing standing
 * between `digest_unsubscribe.php` (no login, no capability check, by design for
 * RFC 8058 one-click unsubscribe) and anyone unsubscribing anyone. These tests
 * pin forgery rejection, tamper detection and expiry rather than the happy path.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\digest_unsubscribe_token
 */
final class digest_unsubscribe_token_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_round_trip_returns_the_same_pair(): void {
        $token = digest_unsubscribe_token::mint(4217, 99);
        $this->assertSame([4217, 99], digest_unsubscribe_token::verify($token));
    }

    public function test_garbage_is_rejected(): void {
        foreach (['', 'x', 'a.b.c', 'not-a-token', '....', 'YWJj'] as $bad) {
            $this->assertNull(
                digest_unsubscribe_token::verify($bad),
                "unexpectedly accepted: {$bad}"
            );
        }
    }

    public function test_a_tampered_payload_is_rejected(): void {
        // Re-encode a payload naming a different user, keeping the real signature.
        $token = digest_unsubscribe_token::mint(4217, 99);
        [$payload, $sig] = explode('.', $token);
        $decoded = base64_decode(strtr($payload, '-_', '+/'), false);
        $forged = str_replace('4217', '4218', $decoded);
        $this->assertNotSame($decoded, $forged, 'test fixture did not actually change the payload');
        $rebuilt = rtrim(strtr(base64_encode($forged), '+/', '-_'), '=') . '.' . $sig;

        // The whole point of the signature: swapping the user id must not verify.
        $this->assertNull(digest_unsubscribe_token::verify($rebuilt));
    }

    public function test_a_forged_signature_is_rejected(): void {
        $token = digest_unsubscribe_token::mint(4217, 99);
        [$payload] = explode('.', $token);
        $forged = $payload . '.' . rtrim(strtr(base64_encode('bogus-signature'), '+/', '-_'), '=');

        $this->assertNull(digest_unsubscribe_token::verify($forged));
    }

    public function test_an_expired_token_is_rejected(): void {
        // Negative TTL mints something already past its expiry.
        $token = digest_unsubscribe_token::mint(4217, 99, -60);
        $this->assertNull(digest_unsubscribe_token::verify($token));
    }

    public function test_a_token_close_to_expiry_still_verifies(): void {
        $token = digest_unsubscribe_token::mint(4217, 99, 120);
        $this->assertSame([4217, 99], digest_unsubscribe_token::verify($token));
    }

    public function test_tokens_are_bound_to_this_site(): void {
        global $CFG;
        $token = digest_unsubscribe_token::mint(4217, 99);
        // The signing key is $CFG->siteidentifier, so a token minted elsewhere
        // must not verify here. Simulating that by rotating the identifier.
        $CFG->siteidentifier = 'some-other-site-identifier';

        $this->assertNull(digest_unsubscribe_token::verify($token));
    }

    public function test_different_pairs_produce_different_tokens(): void {
        $a = digest_unsubscribe_token::mint(4217, 99);
        $b = digest_unsubscribe_token::mint(4218, 99);
        $c = digest_unsubscribe_token::mint(4217, 100);

        $this->assertNotSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function test_url_points_at_the_unsubscribe_endpoint_and_carries_a_valid_token(): void {
        $url = digest_unsubscribe_token::url(4217, 99);
        $this->assertStringContainsString('/local/ai_course_assistant/digest_unsubscribe.php', $url);

        $query = parse_url($url, PHP_URL_QUERY);
        $this->assertNotEmpty($query);
        parse_str($query, $params);
        $this->assertArrayHasKey('token', $params);
        // The link in a real email must actually work.
        $this->assertSame([4217, 99], digest_unsubscribe_token::verify($params['token']));
    }
}
