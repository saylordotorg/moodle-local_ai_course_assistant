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
 * The key inventory finds every place a key is stored and never hands out a key.
 *
 * Failure messages here never print a key: a sentinel that leaked is named by its index only.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\key_inventory
 */
final class key_inventory_test extends \advanced_testcase {

    /** @var string One OpenAI key stored in six places. */
    private const SHARED = 'sk-proj-INVSHARED-SENTINEL-0002-SHRD';
    /** @var string A Claude row key. */
    private const ROW = 'sk-ant-INVROW-SENTINEL-0001-ROWK';
    /** @var string Rerank key. */
    private const RERANK = 'pa-INVRERANK-SENTINEL-0003-RRNK';
    /** @var string Embedding migration target key. */
    private const MIGRATION = 'sk-proj-INVMIGR-SENTINEL-0004-MIGR';
    /** @var string Realtime key. */
    private const REALTIME = 'sk-proj-INVRT-SENTINEL-0005-RTRT';
    /** @var string Self-hosted speech-to-text key. */
    private const STT = 'stt-INVSTT-SENTINEL-0006-STTK';
    /** @var string Voice row key. */
    private const VOICE = 'xai-INVVOICE-SENTINEL-0007-VOIC';
    /** @var string A different course key with the same last four as SHARED. */
    private const OTHERCOURSE = 'sk-ant-INVCOURSE-SENTINEL-0008-SHRD';

    /** @var string[] Every stored value the test planted (ciphertext and plain), to search the output for. */
    private array $stored = [];

    /**
     * Store a course's own key straight into course_cfg.
     *
     * @param int $courseid
     * @param string $provider
     * @param string|null $apikey the column value as stored (null for a restored row with no key)
     * @return void
     */
    private function course_key(int $courseid, string $provider, ?string $apikey): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_course_cfg', (object) [
            'courseid' => $courseid, 'enabled' => 1, 'provider' => $provider, 'apikey' => $apikey,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        if ($apikey !== null && $apikey !== '') {
            $this->stored[] = $apikey;
        }
    }

    /**
     * Set a plugin setting and remember the stored value.
     *
     * @param string $name
     * @param string $value
     * @return void
     */
    private function cfg(string $name, string $value): void {
        set_config($name, $value, 'local_ai_course_assistant');
        $this->stored[] = $value;
    }

    /**
     * Plant a key in every store, plus an undecryptable value in three of them and two keyless course rows.
     *
     * @return array shortname => course id
     */
    private function plant(): array {
        $foreign = 'sodium:' . base64_encode(random_bytes(80));
        $this->cfg('provider', 'openai');
        $this->cfg('comparison_providers', 'openai|' . secrets::conceal(self::SHARED) . "|gpt-4o-mini\n"
            . "# openai|parked|m\n"
            . 'claude|' . secrets::conceal(self::ROW) . "|claude-haiku-4-5||https://user:pw@gw.example.test/v1\n"
            . "claude|$foreign|m");
        $this->cfg('embed_apikey', secrets::conceal(self::SHARED));
        $this->cfg('rerank_apikey', secrets::conceal(self::RERANK));
        $this->cfg('embed_migration_target_apikey', secrets::conceal(self::MIGRATION));
        $this->cfg('embed_migration_target_provider', 'voyage');
        $this->cfg('realtime_apikey', self::REALTIME);
        $this->cfg('stt_selfhosted_apikey', secrets::conceal(self::STT));
        $this->cfg('stt_selfhosted_url', 'https://whisper.example.test');
        $this->cfg('voice_providers', 'xai|' . secrets::conceal(self::VOICE) . "|grok-voice\nopenai|$foreign|broken");
        $this->cfg('apikey', secrets::conceal(self::SHARED));
        $ids = [];
        foreach (['INV1', 'INV2', 'INV3', 'INV4', 'INVNULL', 'INVEMPTY', 'INVFOREIGN'] as $short) {
            $ids[$short] = (int) $this->getDataGenerator()->create_course(
                ['shortname' => $short, 'fullname' => "Inventory $short"])->id;
        }
        $this->course_key($ids['INV1'], 'openai', secrets::conceal(self::SHARED));
        $this->course_key($ids['INV2'], 'openai', secrets::conceal(self::SHARED));
        $this->course_key($ids['INV3'], '', self::SHARED);
        $this->course_key($ids['INV4'], 'claude', secrets::conceal(self::OTHERCOURSE));
        $this->course_key($ids['INVNULL'], 'openai', null);
        $this->course_key($ids['INVEMPTY'], 'openai', '');
        $this->course_key($ids['INVFOREIGN'], 'openai', $foreign);
        return $ids;
    }

    /**
     * Every planted plain key.
     *
     * @return string[]
     */
    private function sentinels(): array {
        return [self::SHARED, self::ROW, self::RERANK, self::MIGRATION, self::REALTIME, self::STT, self::VOICE,
            self::OTHERCOURSE];
    }

    public function test_every_store_is_listed_by_kind_and_ref_and_keyless_courses_are_not(): void {
        $this->resetAfterTest();
        $this->plant();

        $places = key_inventory::places();
        $this->assertDebuggingNotCalled();

        $pairs = array_map(fn($p) => $p['kind'] . ':' . $p['ref'], $places);
        $this->assertEqualsCanonicalizing([
            'row:openai', 'row:claude',
            'pasted:embed_apikey', 'pasted:rerank_apikey', 'pasted:embed_migration_target_apikey',
            'pasted:realtime_apikey', 'pasted:stt_selfhosted_apikey',
            'voice:xai', 'mirror:apikey',
            'course:INV1', 'course:INV2', 'course:INV3', 'course:INV4',
        ], $pairs, 'one place per stored key; NULL, empty and undecryptable values are not places');
        foreach ($places as $p) {
            $this->assertSame(['kind', 'ref', 'provider', 'courseid', 'course', 'last4', 'fingerprint', 'baseurl'],
                array_keys($p), 'a place carries exactly the documented fields');
            $this->assertMatchesRegularExpression('~^[0-9a-f]{12}$~', $p['fingerprint']);
            $this->assertSame($p['kind'] === 'course', $p['courseid'] > 0);
        }
    }

    public function test_providers_courses_and_base_urls_are_attributed(): void {
        $this->resetAfterTest();
        $ids = $this->plant();
        $byref = [];
        foreach (key_inventory::places() as $p) {
            $byref[$p['kind'] . ':' . $p['ref']] = $p;
        }
        $this->assertSame('openai', $byref['pasted:embed_apikey']['provider'], 'embedding defaults to openai');
        $this->assertSame('voyage', $byref['pasted:rerank_apikey']['provider']);
        $this->assertSame('voyage', $byref['pasted:embed_migration_target_apikey']['provider']);
        $this->assertSame('openai', $byref['pasted:realtime_apikey']['provider']);
        $this->assertSame('', $byref['pasted:stt_selfhosted_apikey']['provider']);
        $this->assertSame('https://whisper.example.test', $byref['pasted:stt_selfhosted_apikey']['baseurl']);
        $this->assertSame('xai', $byref['voice:xai']['provider']);
        $this->assertSame('openai', $byref['mirror:apikey']['provider']);
        $this->assertTrue('https://***@gw.example.test/v1' === $byref['row:claude']['baseurl'],
            'a credential in a base URL is masked');
        $this->assertSame('openai', $byref['course:INV3']['provider'], 'a course with no provider uses the site one');
        $this->assertSame('claude', $byref['course:INV4']['provider']);
        $this->assertSame($ids['INV4'], $byref['course:INV4']['courseid']);
        $this->assertSame('Inventory INV4', $byref['course:INV4']['course']);
        $this->assertSame('ROWK', $byref['row:claude']['last4']);
    }

    public function test_one_key_gets_one_fingerprint_and_the_same_last_four_does_not_merge_two_keys(): void {
        $this->resetAfterTest();
        $this->plant();
        $places = key_inventory::places();
        $shared = key_inventory::fingerprint(self::SHARED);
        $holders = array_map(fn($p) => $p['kind'] . ':' . $p['ref'],
            array_values(array_filter($places, fn($p) => $p['fingerprint'] === $shared)));
        $this->assertEqualsCanonicalizing(['row:openai', 'pasted:embed_apikey', 'mirror:apikey',
            'course:INV1', 'course:INV2', 'course:INV3'], $holders);

        $other = key_inventory::fingerprint(self::OTHERCOURSE);
        $this->assertNotSame($shared, $other);
        $this->assertSame(provider_keys::last4(self::SHARED), provider_keys::last4(self::OTHERCOURSE));
        $inv4 = array_values(array_filter($places, fn($p) => $p['ref'] === 'INV4'))[0];
        $this->assertSame($other, $inv4['fingerprint']);
        $this->assertSame('SHRD', $inv4['last4']);
    }

    public function test_groups_are_keyed_by_fingerprint_and_ordered_by_place_count(): void {
        $this->resetAfterTest();
        $this->plant();
        $groups = key_inventory::groups();
        $this->assertDebuggingNotCalled();

        $counts = array_map(fn($g) => count($g['places']), array_values($groups));
        $sorted = $counts;
        rsort($sorted);
        $this->assertSame($sorted, $counts, 'most places first');
        $first = reset($groups);
        $this->assertSame(key_inventory::fingerprint(self::SHARED), key($groups));
        $this->assertSame(6, count($first['places']));
        $this->assertSame('SHRD', $first['last4']);
        $this->assertSame('openai', $first['vendor'], 'the key prefix names the vendor');
        foreach ($groups as $fp => $group) {
            $this->assertSame($fp, $group['fingerprint']);
            $this->assertSame(['fingerprint', 'last4', 'vendor', 'places'], array_keys($group));
        }
        $this->assertSame('', $groups[key_inventory::fingerprint(self::STT)]['vendor'], 'no prefix and no provider');
        $this->assertSame('voyage', $groups[key_inventory::fingerprint(self::RERANK)]['vendor'],
            'no prefix, so the one provider its places share');
        $this->assertSame(8, count($groups));
    }

    public function test_no_output_form_carries_a_key_or_its_ciphertext(): void {
        $this->resetAfterTest();
        $this->plant();

        ob_start();
        try {
            $places = key_inventory::places();
            $groups = key_inventory::groups();
        } finally {
            $printed = (string) ob_get_clean();
        }
        $this->assertDebuggingNotCalled();
        $this->assertTrue($printed === '', 'the walk prints nothing');

        $blob = json_encode($places) . "\n" . json_encode($groups) . "\n" . print_r($places, true) . "\n"
            . print_r($groups, true) . "\n" . var_export($places, true) . "\n" . var_export($groups, true)
            . "\n" . $printed;
        foreach ($this->sentinels() as $i => $key) {
            $this->assertFalse(str_contains($blob, $key), "sentinel $i appears in the output");
            for ($at = 0; $at + 8 <= strlen($key); $at++) {
                $this->assertFalse(str_contains($blob, substr($key, $at, 8)),
                    "an 8-character piece of sentinel $i appears in the output");
            }
        }
        $this->assertFalse(str_contains($blob, 'sodium:'), 'no ciphertext prefix in the output');
        $this->assertFalse(str_contains($blob, 'openssl-aes'), 'no ciphertext prefix in the output');
        // Every ciphertext planted: standalone values and the ones inside the key and voice table lines.
        $bodies = 0;
        foreach ($this->stored as $i => $value) {
            preg_match_all('~(?:sodium|openssl-aes-256-ctr):([A-Za-z0-9+/=]{24,})~', $value, $m);
            foreach ($m[1] as $j => $body) {
                $bodies++;
                $this->assertFalse(str_contains($blob, substr($body, 0, 24)),
                    "stored ciphertext $i.$j appears in the output");
            }
        }
        $this->assertTrue($bodies >= 14, 'the ciphertext search saw the table lines as well as the settings');
    }

    public function test_an_empty_site_has_no_places_and_no_groups(): void {
        $this->resetAfterTest();
        $this->assertSame([], key_inventory::places());
        $this->assertSame([], key_inventory::groups());
    }

    /**
     * course_cfg.apikey is a TEXT column: Oracle can't compare a CLOB with '' (and reads '' as NULL), so every query
     * on it goes through $DB->sql_isnotempty(), as secrets, course_key_guard and settings_usage do. This suite runs
     * on MySQL and PostgreSQL only, so the idiom is pinned at the source.
     */
    public function test_the_course_key_query_filters_the_text_column_with_the_portable_idiom(): void {
        $source = (string) file_get_contents(__DIR__ . '/../classes/key_inventory.php');
        $this->assertNotSame('', $source);
        $this->assertStringContainsString(
            "sql_isnotempty(self::COURSE_TABLE, 'apikey', true, true)", $source,
            'the course key query uses sql_isnotempty() for the nullable TEXT column');
        $this->assertSame(0, preg_match("~apikey\\s*(<>|!=)\\s*''~", $source),
            'no raw comparison of the TEXT column with an empty string');
        $this->assertSame(0, preg_match("~['\"]\\s*apikey\\s+IS\\s+NOT\\s+NULL~i", $source),
            'no hand-written NULL test of the TEXT column either');
    }

    public function test_a_course_row_holding_only_whitespace_is_not_a_place(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->course_key((int) $course->id, 'openai', " \t ");
        $this->assertSame([], key_inventory::places(), 'whitespace is not a stored key');
    }
}
