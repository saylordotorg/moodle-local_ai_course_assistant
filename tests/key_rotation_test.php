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
 * Key rotation finds one key in every store and replaces it everywhere, or nowhere.
 *
 * Failure messages here never print a key or a stored value: a comparison of either is an assertTrue with a
 * message that says what failed, and a leaked sentinel is named by its index only.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\key_rotation
 */
final class key_rotation_test extends \advanced_testcase {

    /** @var string The key to rotate: no vendor prefix, so every store may hold it. Ends in AB12. */
    private const OLD = 'sk-ROTOLD-SENTINEL-0001-AB12';
    /** @var string The new key. Ends in CD34. */
    private const NEW = 'sk-ROTNEW-SENTINEL-0002-CD34';
    /** @var string Another key (Claude), which must never change. */
    private const OTHER = 'sk-ant-ROTOTHER-SENTINEL-0003-OTHR';
    /** @var string A different key with the same last four as OLD. */
    private const AMBIG = 'sk-ROTAMBIG-SENTINEL-0004-AB12';
    /** @var string A Claude key, the wrong vendor for an OpenAI place. */
    private const CLAUDENEW = 'sk-ant-ROTCLNEW-SENTINEL-0005-CLNW';
    /** @var string A key the mirror holds when it is out of step with the default row. */
    private const STALE = 'sk-ROTSTALE-SENTINEL-0006-STAL';

    /** @var string Plugin component. */
    private const PLUGIN = 'local_ai_course_assistant';

    /** @var string Per-course configuration table. */
    private const COURSE_TABLE = 'local_ai_course_assistant_course_cfg';

    /** @var string[] Every stored value the test planted (ciphertext and plain), to search the output for. */
    private array $stored = [];

    /**
     * Set a plugin setting and remember the stored value.
     *
     * @param string $name
     * @param string $value
     * @return void
     */
    private function cfg(string $name, string $value): void {
        set_config($name, $value, self::PLUGIN);
        $this->stored[] = $value;
    }

    /**
     * Conceal a key and remember the ciphertext.
     *
     * @param string $plain
     * @return string
     */
    private function c(string $plain): string {
        $stored = secrets::conceal($plain);
        $this->stored[] = $stored;
        return $stored;
    }

    /**
     * Store a course's own key straight into course_cfg.
     *
     * @param string $shortname
     * @param string $provider
     * @param string $apikey the column value as stored
     * @return int course id
     */
    private function course_key(string $shortname, string $provider, string $apikey): int {
        global $DB;
        $id = (int) $this->getDataGenerator()->create_course(['shortname' => $shortname,
            'fullname' => "Rotation $shortname"])->id;
        $DB->insert_record(self::COURSE_TABLE, (object) [
            'courseid' => $id, 'enabled' => 1, 'provider' => $provider, 'apikey' => $apikey,
            'timecreated' => 1000, 'timemodified' => 1000,
        ]);
        $this->stored[] = $apikey;
        return $id;
    }

    /**
     * Plant OLD in every kind of store (two table rows, one of them a legacy plain line, all five pasted
     * settings, a voice row, the mirror, two courses), and OTHER in a table row, a voice row and a course.
     *
     * @return void
     */
    private function plant_everywhere(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->cfg('provider', 'openai');
        $this->cfg('model', 'gpt-4o-mini');
        $this->cfg('comparison_providers', 'openai|' . $this->c(self::OLD) . "|gpt-4o-mini\n"
            . "# openai|parked|m\n"
            . 'claude|' . $this->c(self::OTHER) . "|claude-haiku-4-5\n"
            . 'Custom |  ' . self::OLD . "  |gpt-4o|0.2|https://gw.example.test/v1|extra\n");
        foreach (key_inventory::PASTED as $name) {
            $this->cfg($name, $name === 'realtime_apikey' ? self::OLD : $this->c(self::OLD));
        }
        $this->cfg('stt_selfhosted_url', 'https://whisper.example.test');
        $this->cfg('voice_providers', 'openai|' . $this->c(self::OLD) . "|alloy\nxai|" . $this->c(self::OTHER)
            . '|grok');
        $this->cfg('apikey', $this->c(self::OLD));
        $this->course_key('ROT1', 'openai', $this->c(self::OLD));
        $this->course_key('ROT2', '', self::OLD);
        $this->course_key('ROT3', 'claude', $this->c(self::OTHER));
    }

    /**
     * A site whose default provider (openai) has one row holding OLD, with the mirror as given.
     *
     * @param string $mirror the plain key the apikey mirror holds
     * @return void
     */
    private function plant_default(string $mirror): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->cfg('provider', 'openai');
        $this->cfg('model', 'gpt-4o-mini');
        $this->cfg('comparison_providers', 'openai|' . $this->c(self::OLD) . '|gpt-4o-mini');
        $this->cfg('apikey', $this->c($mirror));
    }

    /**
     * Every store, read through the config cache AND straight from the database, plus the audit row count.
     *
     * @return string
     */
    private function snapshot(): string {
        global $DB;
        $config = (array) get_config(self::PLUGIN);
        ksort($config);
        $rows = $DB->get_records_menu('config_plugins', ['plugin' => self::PLUGIN], 'name', 'name, value');
        return json_encode([
            'config' => $config,
            'db'     => $rows,
            'course' => array_values($DB->get_records(self::COURSE_TABLE, null, 'id')),
            'audit'  => $DB->count_records('local_ai_course_assistant_audit'),
        ]);
    }

    /**
     * kind:ref of every place holding a fingerprint.
     *
     * @param string $fingerprint
     * @return string[]
     */
    private function holders(string $fingerprint): array {
        $out = array_map(fn($p) => $p['kind'] . ':' . $p['ref'],
            array_values(array_filter(key_inventory::places(), fn($p) => $p['fingerprint'] === $fingerprint)));
        sort($out);
        return $out;
    }

    /**
     * Every planted plain key.
     *
     * @return string[]
     */
    private function sentinels(): array {
        return [self::OLD, self::NEW, self::OTHER, self::AMBIG, self::CLAUDENEW, self::STALE];
    }

    /**
     * Assert that a text carries no sentinel, no piece of one and no planted or written ciphertext.
     *
     * @param string $text
     * @param string $what what the text is, for the failure message
     * @return void
     */
    private function assert_no_key(string $text, string $what): void {
        global $DB;
        foreach ($this->sentinels() as $i => $key) {
            for ($at = 0; $at + 8 <= strlen($key); $at++) {
                $this->assertFalse(str_contains($text, substr($key, $at, 8)),
                    "an 8-character piece of sentinel $i appears in $what");
            }
        }
        $this->assertFalse(str_contains($text, 'sodium:'), "a ciphertext prefix appears in $what");
        $this->assertFalse(str_contains($text, 'openssl-aes'), "a ciphertext prefix appears in $what");
        $values = $this->stored;
        $values = array_merge($values, $DB->get_fieldset_select('config_plugins', 'value', 'plugin = ?', [self::PLUGIN]));
        $values = array_merge($values, $DB->get_fieldset_select(self::COURSE_TABLE, 'apikey', 'apikey IS NOT NULL'));
        foreach ($values as $i => $value) {
            preg_match_all('~(?:sodium|openssl-aes-256-ctr):([A-Za-z0-9+/=]{24,})~', (string) $value, $m);
            foreach ($m[1] as $j => $body) {
                $this->assertFalse(str_contains($text, substr($body, 0, 24)), "stored ciphertext $i.$j appears in $what");
            }
        }
    }

    /**
     * Every printable form of a value.
     *
     * @param mixed $value
     * @return string
     */
    private static function forms($value): string {
        return json_encode($value) . "\n" . print_r($value, true) . "\n" . var_export($value, true);
    }

    // (a) A plan lists every place and changes nothing.
    public function test_a_plan_lists_every_place_of_the_one_matching_key_and_changes_nothing(): void {
        $this->plant_everywhere();
        $before = $this->snapshot();

        $plan = key_rotation::plan(['last4' => 'AB12'], self::NEW);

        $this->assertDebuggingNotCalled();
        $this->assertTrue($plan['ok'], 'the plan is good: ' . implode(' / ', $plan['errors']));
        $this->assertSame([], $plan['errors']);
        $this->assertSame(key_inventory::fingerprint(self::OLD), $plan['group']['fingerprint']);
        $this->assertSame('AB12', $plan['group']['last4']);
        $this->assertSame(['row' => 2, 'pasted' => 5, 'voice' => 1, 'mirror' => 1, 'course' => 2], $plan['counts']);
        $this->assertSame(0, $plan['notrotated']);
        $pairs = array_map(fn($p) => $p['kind'] . ':' . $p['ref'], $plan['places']);
        sort($pairs);
        $this->assertSame(['course:ROT1', 'course:ROT2', 'mirror:apikey', 'pasted:embed_apikey',
            'pasted:embed_migration_target_apikey', 'pasted:realtime_apikey', 'pasted:rerank_apikey',
            'pasted:stt_selfhosted_apikey', 'row:custom', 'row:openai', 'voice:openai'], $pairs);
        foreach ($plan['places'] as $p) {
            $this->assertFalse($p['forced'], 'nothing is forced in config.php here');
        }
        $this->assertTrue($before === $this->snapshot(), 'a plan writes nothing');

        $byfp = key_rotation::plan(['fingerprint' => key_inventory::fingerprint(self::OLD)], self::NEW);
        $this->assertTrue($byfp['ok'], 'a fingerprint selects the same key');
        $this->assertSame($plan['counts'], $byfp['counts']);
        $this->assertTrue($before === $this->snapshot(), 'a plan by fingerprint writes nothing');
    }

    // (b) Apply replaces the key in every store kind and leaves the other key alone.
    public function test_apply_replaces_the_key_in_every_store_and_leaves_the_other_key_alone(): void {
        $this->plant_everywhere();
        $oldfp = key_inventory::fingerprint(self::OLD);
        $newfp = key_inventory::fingerprint(self::NEW);
        $otherfp = key_inventory::fingerprint(self::OTHER);
        $holders = $this->holders($oldfp);
        $otherholders = $this->holders($otherfp);
        $this->assertCount(11, $holders);
        $this->assertSame(['course:ROT3', 'row:claude', 'voice:xai'], $otherholders);
        $table = (string) get_config(self::PLUGIN, 'comparison_providers');
        $voice = (string) get_config(self::PLUGIN, 'voice_providers');

        $result = key_rotation::apply(['last4' => 'AB12'], self::NEW);

        $this->assertDebuggingNotCalled();
        $this->assertTrue($result['ok'], 'apply worked: ' . implode(' / ', $result['errors']));
        $this->assertSame(['row' => 2, 'pasted' => 5, 'voice' => 1, 'mirror' => 1, 'course' => 2], $result['counts']);
        $this->assertSame('CD34', $result['newlast4']);
        $this->assertSame([], $this->holders($oldfp), 'the old key is stored nowhere');
        $this->assertSame($holders, $this->holders($newfp), 'the new key is in every place the old one was');
        $this->assertSame($otherholders, $this->holders($otherfp), 'the other key is where it was');

        // Every line that did not hold the old key is byte-identical; the plain legacy line keeps its shape.
        $after = explode("\n", (string) get_config(self::PLUGIN, 'comparison_providers'));
        $was = explode("\n", $table);
        $this->assertCount(count($was), $after);
        foreach ([1, 2, 4] as $n) {
            $this->assertTrue($was[$n] === $after[$n], "table line $n is unchanged");
        }
        $legacy = explode('|', $after[3]);
        $this->assertTrue(count($legacy) === 6 && $legacy[0] === 'Custom ' && str_starts_with($legacy[1], '  ')
            && str_ends_with($legacy[1], '  ') && secrets::is_encrypted(trim($legacy[1]))
            && implode('|', array_slice($legacy, 2)) === 'gpt-4o|0.2|https://gw.example.test/v1|extra',
            'the legacy plain line keeps its label, spacing and columns, and now holds ciphertext');
        $vafter = explode("\n", (string) get_config(self::PLUGIN, 'voice_providers'));
        $this->assertTrue(explode("\n", $voice)[1] === $vafter[1], 'the other voice row is unchanged');

        // Every new value is ciphertext.
        foreach (key_inventory::PASTED as $name) {
            $this->assertTrue(secrets::is_encrypted((string) get_config(self::PLUGIN, $name)), "$name is ciphertext");
        }
        $this->assertTrue(secrets::is_encrypted((string) get_config(self::PLUGIN, 'apikey')), 'the mirror is ciphertext');
        global $DB;
        foreach ($DB->get_records(self::COURSE_TABLE) as $row) {
            $this->assertTrue(secrets::is_encrypted((string) $row->apikey), 'every course key is ciphertext');
        }
        $this->assertTrue(secrets::get('embed_apikey') === self::NEW, 'a pasted setting reads back as the new key');
    }

    // (b) A course row's time modified moves with its key; the other course's does not.
    public function test_a_rotated_course_row_gets_a_new_time_modified(): void {
        global $DB;
        $this->plant_everywhere();
        $result = key_rotation::apply(['last4' => 'AB12'], self::NEW);
        $this->assertTrue($result['ok']);
        $times = [];
        foreach ($DB->get_records(self::COURSE_TABLE) as $row) {
            $short = $DB->get_field('course', 'shortname', ['id' => $row->courseid]);
            $times[$short] = (int) $row->timemodified;
        }
        $this->assertGreaterThan(1000, $times['ROT1']);
        $this->assertGreaterThan(1000, $times['ROT2']);
        $this->assertSame(1000, $times['ROT3']);
    }

    // (c) Two different keys with the same last four: refused, never guessed.
    public function test_an_ambiguous_last_four_is_refused_with_the_candidates_and_changes_nothing(): void {
        $this->plant_everywhere();
        $this->course_key('AMB', 'openai', $this->c(self::AMBIG));
        $before = $this->snapshot();

        foreach (['plan', 'apply'] as $method) {
            $result = key_rotation::$method(['last4' => 'AB12'], self::NEW);
            $this->assertFalse($result['ok'], "$method refuses an ambiguous last four");
            $this->assertSame('ambiguous', $result['reason']);
            $text = implode("\n", $result['errors']);
            $this->assertStringContainsString(key_inventory::fingerprint(self::OLD), $text);
            $this->assertStringContainsString(key_inventory::fingerprint(self::AMBIG), $text);
            $this->assertStringContainsString('AB12', $text);
            $this->assert_no_key(self::forms($result), "the $method result");
            $this->assertTrue($before === $this->snapshot(), "$method changed nothing");
        }
        $this->assertNull(key_rotation::plan(['last4' => 'AB12'], self::NEW)['group']);
    }

    // (d) A last four or fingerprint that matches nothing.
    public function test_a_selector_that_matches_nothing_is_an_error(): void {
        $this->plant_everywhere();
        $before = $this->snapshot();
        foreach ([['last4' => 'ZZZZ'], ['fingerprint' => 'abcdefabcdef']] as $selector) {
            foreach (['plan', 'apply'] as $method) {
                $result = key_rotation::$method($selector, self::NEW);
                $this->assertFalse($result['ok']);
                $this->assertSame('nomatch', $result['reason']);
                $this->assertNotEmpty($result['errors']);
                if (isset($selector['last4'])) {
                    $this->assertStringContainsString('case-sensitive', implode(' ', $result['errors']));
                    $this->assertStringContainsString('last four characters only', implode(' ', $result['errors']));
                }
            }
        }
        foreach ([[], ['last4' => 'AB12', 'fingerprint' => key_inventory::fingerprint(self::OLD)], ['last4' => ''],
                ['last4' => '  ']] as $i => $selector) {
            $result = key_rotation::apply($selector, self::NEW);
            $this->assertFalse($result['ok'], "selector $i is refused");
            $this->assertSame('usage', $result['reason'], "selector $i is a usage error");
        }
        $this->assertTrue($before === $this->snapshot(), 'nothing changed');
    }

    // (e) A new key whose prefix names another vendor than a place's provider.
    public function test_a_new_key_for_another_vendor_is_refused_naming_the_place_and_provider(): void {
        $this->plant_everywhere();
        $before = $this->snapshot();

        foreach (['plan', 'apply'] as $method) {
            $result = key_rotation::$method(['last4' => 'AB12'], self::CLAUDENEW);
            $this->assertFalse($result['ok'], "$method refuses a Claude key for OpenAI places");
            $this->assertSame('refused', $result['reason']);
            $text = implode("\n", $result['errors']);
            $this->assertStringContainsString('row openai', $text, 'the OpenAI row is named');
            $this->assertStringContainsString('course ROT1', $text, 'the OpenAI course is named');
            $this->assertStringContainsString('pasted rerank_apikey', $text, 'the Voyage rerank key is named');
            $this->assertStringContainsString('claude', $text, 'the vendor the new key belongs to is named');
            $this->assertStringNotContainsString('row custom', $text, 'a row with its own base URL takes any key');
            $this->assertStringNotContainsString('stt_selfhosted', $text, 'a key with no vendor takes any key');
            $this->assert_no_key(self::forms($result), "the $method result");
            $this->assertTrue($before === $this->snapshot(), "$method changed nothing");
        }
    }

    // (f) A new key that is empty, short, or would break a table line.
    public function test_a_malformed_new_key_is_refused(): void {
        $this->plant_everywhere();
        $before = $this->snapshot();
        $bad = ['', '   ', 'short7c', ' abcdefg ', "abcd|efghijkl", "abcdefgh\nijkl", "abcdefgh\rijkl",
            "abcdefgh\0ijkl",
            "abcd\tefghijkl", "abcd efghijkl", "abcdefgh\x7Fijkl", "abcdefgh\x0Bijkl", str_repeat('k', 513)];
        foreach ($bad as $i => $newkey) {
            foreach (['plan', 'apply'] as $method) {
                $result = key_rotation::$method(['last4' => 'AB12'], $newkey);
                $this->assertFalse($result['ok'], "$method refuses bad key $i");
                $this->assertSame('refused', $result['reason']);
                $this->assertNotEmpty($result['errors']);
            }
        }
        $same = key_rotation::apply(['last4' => 'AB12'], ' ' . self::OLD . "\n");
        $this->assertFalse($same['ok'], 'the same key again is refused');
        $this->assertTrue($before === $this->snapshot(), 'nothing changed');

        $padded = key_rotation::plan(['last4' => 'AB12'], "  " . self::NEW . "\n");
        $this->assertTrue($padded['ok'], 'spaces and a newline around a key are trimmed, not refused');
        $this->assertTrue(key_rotation::plan(['last4' => 'AB12'], str_repeat('k', 512))['ok'], '512 characters is allowed');
    }

    // (g) A write that fails half way leaves every store as it was.
    public function test_a_failing_write_rolls_back_every_store_and_the_config_cache(): void {
        global $DB;
        $this->plant_everywhere();
        $this->assertFalse($DB->is_transaction_started(), 'the test itself runs outside a transaction');
        $before = $this->snapshot();
        // Warm the config cache so a stale value would show.
        $this->assertTrue(secrets::get('embed_apikey') === self::OLD, 'planted');

        $rotation = new class extends key_rotation {
            /**
             * The course write fails after every other store was written.
             *
             * @param array $courseids
             * @param string $fingerprint
             * @param string $newstored
             * @return int
             */
            protected static function write_courses(array $courseids, string $fingerprint, string $newstored): int {
                // A read inside the transaction fills the config cache with values the rollback will undo.
                get_config('local_ai_course_assistant');
                throw new \RuntimeException('planted failure ' . secrets::reveal($newstored) . ' ' . $newstored);
            }
        };
        ob_start();
        try {
            $result = $rotation::apply(['last4' => 'AB12'], self::NEW);
        } finally {
            $printed = (string) ob_get_clean();
        }

        $this->assertDebuggingNotCalled();
        $this->assertFalse($result['ok'], 'the rotation reports failure');
        $this->assertSame('failed', $result['reason']);
        $this->assertNotEmpty($result['errors']);
        $this->assertFalse($DB->is_transaction_started(), 'no transaction is left open');
        $this->assertTrue($before === $this->snapshot(), 'every store, the database and the config cache, as before');
        $this->assertTrue(secrets::get('embed_apikey') === self::OLD, 'get_config serves the old value');
        $this->assertTrue(secrets::get('apikey') === self::OLD, 'the mirror is the old key');
        $this->assertCount(11, $this->holders(key_inventory::fingerprint(self::OLD)));
        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_audit', ['action' => 'key_rotated']));
        $this->assertTrue($printed === '', 'nothing printed');
        $this->assert_no_key(self::forms($result), 'the failed result');
    }

    // Atomicity needs a transaction of its own: inside another one a rollback can't be promised.
    public function test_apply_refuses_to_run_inside_another_transaction(): void {
        global $DB;
        $this->plant_everywhere();
        $before = $this->snapshot();
        $transaction = $DB->start_delegated_transaction();
        $result = key_rotation::apply(['last4' => 'AB12'], self::NEW);
        $transaction->allow_commit();
        $this->assertFalse($result['ok']);
        $this->assertSame('refused', $result['reason']);
        $this->assertTrue($before === $this->snapshot(), 'nothing changed');
    }

    // (h) The apikey mirror follows the default provider's row.
    public function test_rotating_the_default_rows_key_moves_the_mirror_to_the_new_key(): void {
        $this->plant_default(self::OLD);
        $result = key_rotation::apply(['last4' => 'AB12'], self::NEW);
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['counts']['mirror']);
        $this->assertTrue(secrets::get('apikey') === self::NEW, 'the mirror holds the new key');
        $this->assertTrue(provider_keys::key_for('openai') === self::NEW, 'the default row holds the new key');
    }

    // (h) A mirror out of step with the row is put back in step by the sync after the commit.
    public function test_a_stale_mirror_is_resynced_to_the_rotated_default_row(): void {
        $this->plant_default(self::STALE);
        $result = key_rotation::apply(['last4' => 'AB12'], self::NEW);
        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['counts']['mirror'], 'the mirror did not hold the old key');
        $this->assertTrue(secrets::get('apikey') === self::NEW, 'the mirror was re-synced to the new key');
    }

    // (i) One audit row per rotation, with counts and last-four hints only.
    public function test_one_audit_row_holds_the_provider_counts_and_last_fours_and_no_key(): void {
        global $DB;
        $this->plant_default(self::OLD);
        $this->setAdminUser();
        $result = key_rotation::apply(['last4' => 'AB12'], self::NEW);
        $this->assertTrue($result['ok']);
        $rows = array_values($DB->get_records('local_ai_course_assistant_audit', ['action' => 'key_rotated']));
        $this->assertCount(1, $rows);
        $details = json_decode($rows[0]->details, true);
        $this->assertSame(['provider', 'fingerprint', 'old_last4', 'new_last4', 'counts', 'mirror_synced'],
            array_keys($details));
        $this->assertTrue($details['mirror_synced'], 'the default row changed, so the mirror was re-synced');
        $this->assertSame('openai', $details['provider']);
        $this->assertSame(key_inventory::fingerprint(self::OLD), $details['fingerprint']);
        $this->assertSame('AB12', $details['old_last4']);
        $this->assertSame('CD34', $details['new_last4']);
        $this->assertSame(['row' => 1, 'pasted' => 0, 'voice' => 0, 'mirror' => 1, 'course' => 0], $details['counts']);
        $this->assertSame((int) get_admin()->id, (int) $rows[0]->userid);
        $this->assert_no_key(self::forms($rows), 'the audit row');
    }

    // (j) Nothing returned, printed or audited carries a key or a ciphertext.
    public function test_no_result_or_printed_output_carries_a_key_or_its_ciphertext(): void {
        global $DB;
        $this->plant_everywhere();
        $this->course_key('AMB', 'openai', $this->c(self::AMBIG));
        ob_start();
        try {
            $results = [
                key_rotation::plan(['last4' => 'AB12'], self::NEW),
                key_rotation::plan(['fingerprint' => key_inventory::fingerprint(self::OLD)], self::CLAUDENEW),
                key_rotation::plan(['fingerprint' => key_inventory::fingerprint(self::OLD)], "abc|" . self::NEW),
                key_rotation::plan(['last4' => 'ZZZZ'], self::NEW),
                key_rotation::apply(['fingerprint' => key_inventory::fingerprint(self::OLD)], self::NEW),
                key_rotation::plan(['fingerprint' => key_inventory::fingerprint(self::NEW)], self::OLD),
            ];
        } finally {
            $printed = (string) ob_get_clean();
        }
        $this->assertDebuggingNotCalled();
        $this->assertTrue($results[4]['ok'], 'the rotation itself worked');
        $this->assertTrue($printed === '', 'nothing printed');
        $audit = $DB->get_records('local_ai_course_assistant_audit');
        $this->assertNotEmpty($audit);
        $this->assert_no_key(self::forms($results) . "\n" . self::forms($audit) . "\n" . $printed, 'the output');
    }

    // A place forced in config.php can't be written, so the rotation is refused, not half done.
    public function test_a_place_forced_in_config_php_is_reported_and_nothing_is_rotated(): void {
        global $CFG;
        $this->plant_everywhere();
        $before = $this->snapshot();
        foreach (['comparison_providers' => 'openai|' . self::OLD . '|gpt-4o-mini', 'embed_apikey' => self::OLD,
                'voice_providers' => 'openai|' . self::OLD . '|alloy', 'apikey' => self::OLD] as $name => $value) {
            $CFG->forced_plugin_settings[self::PLUGIN][$name] = $value;
            foreach (['plan', 'apply'] as $method) {
                $result = key_rotation::$method(['last4' => 'AB12'], self::NEW);
                $this->assertFalse($result['ok'], "$method refuses while $name is forced");
                $this->assertSame('refused', $result['reason']);
                $this->assertSame(1, $result['notrotated'], "the forced $name is counted as not rotated");
                $this->assertStringContainsString('config.php', implode("\n", $result['errors']));
                $this->assertStringContainsString($name === 'comparison_providers' ? 'row openai'
                    : ($name === 'voice_providers' ? 'voice openai' : $name), implode("\n", $result['errors']));
                $this->assert_no_key(self::forms($result), "the $method result");
            }
            unset($CFG->forced_plugin_settings[self::PLUGIN][$name]);
            $this->assertTrue($before === $this->snapshot(), "nothing changed while $name was forced");
        }
    }

    // The command: output only through the CLI helpers, and never a key on the command line.
    public function test_the_cli_script_writes_output_only_through_the_cli_helpers_and_reads_no_argv(): void {
        $source = file_get_contents(__DIR__ . '/../admin/cli/rotate_key.php');
        $this->assertNotFalse($source, 'the script exists');
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $tokens[] = $token;
        }
        $this->assertGreaterThan(100, count($tokens), 'the script was read');
        $banned = ['var_dump', 'print_r', 'var_export', 'printf', 'vprintf', 'fprintf', 'vfprintf', 'debug_zval_dump',
            'fwrite', 'fputs', 'file_put_contents', 'error_log', 'mtrace', 'debugging', 'print_object', 'putenv'];
        $clihelpers = ['cli_get_params', 'cli_error', 'cli_writeln', 'cli_heading', 'cli_separator'];
        foreach ($tokens as $i => $token) {
            $kind = is_array($token) ? $token[0] : null;
            $this->assertNotSame(T_INLINE_HTML, $kind, 'text outside the php tags would be printed');
            $this->assertNotSame(T_OPEN_TAG_WITH_ECHO, $kind, 'a short echo tag prints');
            $this->assertNotSame(T_PRINT, $kind, 'print writes output');
            $this->assertNotSame(T_ECHO, $kind, 'echo writes output');
            if ($kind === T_VARIABLE) {
                $this->assertNotSame('$argv', $token[1], 'the script never reads $argv');
                $this->assertNotSame('$_SERVER', $token[1], 'the script never reads $_SERVER (argv lives there)');
            }
            if ($kind === T_STRING && ($tokens[$i + 1] ?? null) === '(') {
                $this->assertNotContains(strtolower($token[1]), $banned, "the script calls {$token[1]}()");
                if (strncmp($token[1], 'cli_', 4) === 0) {
                    $this->assertContains($token[1], $clihelpers, "{$token[1]}() is not a known Moodle CLI helper");
                }
            }
            if ($kind === T_EXIT && ($tokens[$i + 1] ?? null) === '(') {
                $this->assertSame(T_LNUMBER, $tokens[$i + 2][0] ?? null, 'exit() with something other than a status code');
            }
        }
        // The key-on-the-command-line options are recognised and refused, before the key is read or a store is.
        foreach (['new-key', 'newkey', 'key', 'apikey', 'old-key'] as $option) {
            $this->assertStringContainsString("'$option' => null", $source, "--$option is recognised so it can be refused");
        }
        $refuse = strpos($source, 'key_rotation::command_line_problem(');
        $this->assertNotFalse($refuse, 'the script checks its options with key_rotation::command_line_problem()');
        $this->assertLessThan(strpos($source, 'cli_writeln('), $refuse, 'the options are checked before any output');
        foreach (['getenv(', 'STDIN', 'key_rotation::plan(', 'key_rotation::apply(', 'key_inventory::'] as $later) {
            $at = strpos($source, $later);
            $this->assertNotFalse($at, "$later is used");
            $this->assertGreaterThan($refuse, $at, "the command-line key is refused before $later");
        }
        // An unknown option is never echoed back: it could be a key.
        $this->assertStringNotContainsString('implode(\', \', $unrecognised)', $source);
    }

    // Review 1: the audit row is written in the transaction, so a failed insert rolls the rotation back.
    public function test_a_failing_audit_insert_rolls_back_every_store(): void {
        global $DB;
        $this->plant_everywhere();
        $before = $this->snapshot();
        $rotation = new class extends key_rotation {
            /**
             * The audit insert fails.
             *
             * @param int $userid
             * @param array $details
             * @return void
             */
            protected static function write_audit(int $userid, array $details): void {
                throw new \dml_write_exception('planted audit failure');
            }
        };
        $result = $rotation::apply(['last4' => 'AB12'], self::NEW);
        $this->assertDebuggingNotCalled();
        $this->assertFalse($result['ok'], 'a rotation whose audit row could not be written is not reported as done');
        $this->assertSame('failed', $result['reason']);
        $this->assertTrue($before === $this->snapshot(), 'every store as before');
        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_audit', ['action' => 'key_rotated']));
        $this->assert_no_key(self::forms($result), 'the failed result');
    }

    // Review 1: after the commit one written store is read back; if the new key is not there, it is not ok.
    public function test_a_rotation_that_cannot_be_read_back_is_not_reported_as_done(): void {
        $this->plant_default(self::OLD);
        $rotation = new class extends key_rotation {
            /**
             * Reading back finds nothing.
             *
             * @param string $newfingerprint
             * @param array $counts
             * @return bool
             */
            protected static function confirm(string $newfingerprint, array $counts): bool {
                return false;
            }
        };
        $result = $rotation::apply(['last4' => 'AB12'], self::NEW);
        $this->assertFalse($result['ok']);
        $this->assertSame('unconfirmed', $result['reason']);
        $this->assertStringContainsString('--list', implode(' ', $result['errors']));
        $this->assert_no_key(self::forms($result), 'the unconfirmed result');
        $this->assertTrue(key_rotation::apply(['last4' => 'CD34'], self::OLD)['ok'], 'the real read-back confirms');
    }

    // Review 2: a config cache filled with the old rows before the commit is dropped after it.
    public function test_a_config_cache_filled_before_the_commit_does_not_serve_the_old_key(): void {
        global $DB;
        $this->plant_everywhere();
        $rotation = new class extends key_rotation {
            /** @var array the committed rows another request would have cached */
            public static array $stale = [];

            /**
             * After the last settings write, another request caches the old committed rows.
             *
             * @param array $names
             * @param string $fingerprint
             * @param string $newstored
             * @return int
             */
            protected static function write_settings(array $names, string $fingerprint, string $newstored): int {
                $n = parent::write_settings($names, $fingerprint, $newstored);
                if ($names === ['apikey']) {
                    \cache::make('core', 'config')->set('local_ai_course_assistant', static::$stale);
                }
                return $n;
            }
        };
        $rotation::$stale = $DB->get_records_menu('config_plugins', ['plugin' => self::PLUGIN], '', 'name, value');
        $result = $rotation::apply(['last4' => 'AB12'], self::NEW);
        $this->assertTrue($result['ok'], 'apply worked');
        $this->assertTrue(secrets::get('embed_apikey') === self::NEW, 'a pasted setting reads the new key after apply');
        $this->assertTrue(secrets::get('apikey') === self::NEW, 'the mirror reads the new key after apply');
        $this->assertTrue(provider_keys::key_for('openai') === self::NEW, 'the row reads the new key after apply');
    }

    // Review minor 3: a course whose key changed since the plan is not overwritten; the rotation rolls back.
    public function test_a_course_key_changed_since_the_plan_rolls_the_rotation_back(): void {
        global $DB;
        $this->plant_everywhere();
        $before = $this->snapshot();
        $rotation = new class extends key_rotation {
            /** @var string the value another admin saved for ROT1 meanwhile */
            public static string $meanwhile = '';

            /**
             * Before the first write, ROT1's key is changed (inside the transaction, so it rolls back too).
             *
             * @param string $fingerprint
             * @param string $newstored
             * @return int
             */
            protected static function write_rows(string $fingerprint, string $newstored): int {
                global $DB;
                $courseid = $DB->get_field('course', 'id', ['shortname' => 'ROT1']);
                $DB->set_field('local_ai_course_assistant_course_cfg', 'apikey', static::$meanwhile, ['courseid' => $courseid]);
                return parent::write_rows($fingerprint, $newstored);
            }
        };
        $rotation::$meanwhile = $this->c(self::OTHER);
        $result = $rotation::apply(['last4' => 'AB12'], self::NEW);
        $this->assertFalse($result['ok']);
        $this->assertSame('failed', $result['reason']);
        $this->assertStringContainsString('changed', implode(' ', $result['errors']));
        $this->assertTrue($before === $this->snapshot(), 'every store as before');
    }

    // Review minor 4: the mirror is re-synced only when the default row or the mirror held the key.
    public function test_the_mirror_is_resynced_only_when_a_row_or_the_mirror_held_the_key(): void {
        global $DB;
        $this->plant_default(self::STALE);
        $this->course_key('AMB', 'openai', $this->c(self::AMBIG));
        $result = key_rotation::apply(['fingerprint' => key_inventory::fingerprint(self::AMBIG)], self::NEW);
        $this->assertTrue($result['ok']);
        $this->assertTrue(secrets::get('apikey') === self::STALE, 'a course-only rotation leaves the mirror alone');
        $row = $DB->get_record('local_ai_course_assistant_audit', ['action' => 'key_rotated']);
        $this->assertFalse(json_decode($row->details, true)['mirror_synced']);
    }

    // Review minor 1: every way of putting a key on the command line is refused, and none is echoed.
    public function test_the_command_line_checks_refuse_a_key_anywhere_on_the_command_line(): void {
        $ok = ['help' => false, 'list' => false, 'old-last4' => '', 'old-fingerprint' => '',
            'new-key-env' => 'SOLA_NEW_KEY', 'stdin' => false, 'apply' => false, 'new-key' => null, 'newkey' => null,
            'key' => null, 'apikey' => null, 'old-key' => null];
        $this->assertSame('', key_rotation::command_line_problem($ok, []));
        $this->assertSame('', key_rotation::command_line_problem(['old-last4' => 'AB12', 'apply' => true, 'stdin' => '1']
            + $ok, []));
        $this->assertSame('', key_rotation::command_line_problem(['old-fingerprint' => '0123456789ab'] + $ok, []));
        $bad = [
            ['new-key' => self::NEW], ['newkey' => true], ['key' => self::NEW], ['apikey' => self::NEW],
            ['old-key' => self::OLD],
            ['stdin' => self::NEW], ['apply' => self::NEW], ['list' => 'yes'], ['help' => self::NEW],
            ['old-last4' => self::NEW], ['old-last4' => 'AB1'], ['old-last4' => 'AB 2'],
            ['old-fingerprint' => self::NEW], ['old-fingerprint' => '0123456789az'], ['old-fingerprint' => '0123456789a'],
            ['new-key-env' => self::NEW], ['new-key-env' => 'sk-ant-xyz'], ['new-key-env' => 'AIzaSyKEY'],
            ['new-key-env' => 'xai-KEY'], ['new-key-env' => 'lower_case'], ['new-key-env' => '1ABC'],
            ['new-key-env' => str_repeat('A', 65)], ['new-key-env' => ''],
        ];
        foreach ($bad as $i => $override) {
            $problem = key_rotation::command_line_problem($override + $ok, []);
            $this->assertNotSame('', $problem, "bad command line $i is refused");
            $this->assert_no_key($problem, "the refusal of bad command line $i");
            $value = reset($override);
            if (is_string($value) && strlen($value) >= 4) {
                $this->assertStringNotContainsString($value, $problem, "bad command line $i is not echoed");
            }
        }
        $unknown = key_rotation::command_line_problem($ok, [self::NEW]);
        $this->assertNotSame('', $unknown, 'an unknown argument is refused');
        $this->assert_no_key($unknown, 'the unknown-argument refusal');
    }
}
