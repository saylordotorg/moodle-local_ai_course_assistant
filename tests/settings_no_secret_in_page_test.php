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
 * No SOLA settings page ever contains a secret or the ciphertext of one, however it was rendered.
 *
 * Seeds a sentinel into every secret setting (secrets::names()), every row of the Providers and Voice tables
 * and both webhook fields, renders every SOLA settings page through Moodle's own page object, then repeats
 * that after a successful save, and after a failed one (where Moodle hands the submitted values back to
 * output_html through the admin root's error list). The page's JavaScript arguments are checked as well as
 * its HTML, because a key passed to js_call_amd is as readable as one in the markup.
 *
 * The failed saves and the successful save go through admin_write_settings(), the function the settings
 * form posts to, so the path under test is Moodle's and not a copy of it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class settings_no_secret_in_page_test extends \advanced_testcase {
    /** @var string Every plain secret in this test starts with this, so one needle finds any leak of any of them. */
    private const SENTINEL = 'SENTINEL-NOLEAK-7f3a9c2e1b4d';

    /** @var string Host of the sentinel webhook URLs. */
    private const HOOKHOST = 'hooks.sentinel-noleak.example';

    /** @var string The label of the seeded voice row that holds a legacy plain key (unique, so it can't appear by chance). */
    private const LEGACY_VOICE = 'legacy-voice-q4z';

    /** @var string[] What the seeded table rows show on a page: their models and one voice label. */
    private const SEEDED_ROWS = ['m-openai', 'm-claude', 'm-gemini', 'm-deepseek', 'm-mistral', 'm-openrouter', 'm-ollama',
        self::LEGACY_VOICE];

    /** @var string[] The settings that hold an incoming-webhook URL. */
    private const WEBHOOKS = ['anomaly_digest_slack_webhook', 'anomaly_digest_teams_webhook'];

    /** @var string[] Plain sentinels that must never appear. */
    private array $plains = [];

    /** @var string[] Ciphertext (whole, payload and pieces of it) that must never appear. */
    private array $cipherneedles = [];

    /** @var string[] The last six characters of each plain sentinel: a key cut down to its end is still a leak. */
    private array $endings = [];

    /** @var string[] Last four characters handed out => the label they belong to, so no two hints look alike. */
    private array $tails = [];

    /**
     * A test that pretends to be a web request must not leave that on for the next one, however it ends.
     */
    protected function tearDown(): void {
        settings_save_report::assume_web(null);
        parent::tearDown();
    }

    /**
     * Remember a plain secret, and the ciphertext it was stored as when it was stored.
     *
     * @param string $plain
     * @param string $stored what is in the database for it ('' when not stored yet)
     */
    private function remember(string $plain, string $stored = ''): void {
        $this->plains[$plain] = $plain;
        $this->endings[substr($plain, -6)] = $plain;
        $this->remember_cipher($stored);
    }

    /**
     * A plain sentinel secret for a label: the shared prefix, the label, and six characters that are the same
     * every time for the same label and different for every other. Its last four are what the page's "ends in"
     * hint may show, so no two labels may end alike: a hint could then be satisfied by the wrong key.
     *
     * @param string $label
     * @return string
     */
    private function secret(string $label): string {
        $plain = self::SENTINEL . '-' . $label . '-' . strtoupper(substr(md5($label), 0, 6));
        $tail = substr($plain, -4);
        $this->tails[$tail] = $this->tails[$tail] ?? $label;
        $this->assertSame($label, $this->tails[$tail], 'two sentinels end in the same four characters');
        return $plain;
    }

    /**
     * The "ends in" hint a table row shows for a key.
     *
     * @param string $plain
     * @return string
     */
    private function row_hint(string $plain): string {
        return get_string('providerkeys:saved_ends_in', 'local_ai_course_assistant', substr($plain, -4));
    }

    /**
     * The "ends in" hint a single-key field shows for a key.
     *
     * @param string $plain
     * @return string
     */
    private function field_hint(string $plain): string {
        return get_string('providerkeys:last4_hint', 'local_ai_course_assistant', s(substr($plain, -4)));
    }

    /**
     * Add everything that identifies one stored ciphertext: the whole value, the part after the method
     * prefix, its first 24 characters, and each run of 12 or more letters and digits inside it (a leak
     * through JSON would escape the slashes and break a longer needle in two).
     *
     * @param string $stored
     */
    private function remember_cipher(string $stored): void {
        if ($stored === '' || !secrets::is_encrypted($stored)) {
            return;
        }
        $payload = substr($stored, strpos($stored, ':') + 1);
        $needles = [$stored, $payload, substr($payload, 0, 24)];
        if (preg_match_all('~[A-Za-z0-9]{12,}~', $payload, $runs)) {
            $needles = array_merge($needles, $runs[0]);
        }
        foreach ($needles as $needle) {
            $this->cipherneedles[$needle] = $needle;
        }
    }

    /**
     * Learn the ciphertext of everything stored now: each secret setting and the key column of both tables.
     * Called after seeding and after every save, so a key a save has just encrypted is covered too.
     */
    private function learn_stored_ciphertext(): void {
        foreach (secrets::names() as $name) {
            $this->remember_cipher((string) get_config('local_ai_course_assistant', $name));
        }
        foreach ([provider_keys::SETTING, 'voice_providers'] as $table) {
            foreach (preg_split("/\r?\n/", (string) get_config('local_ai_course_assistant', $table)) as $line) {
                $parts = explode('|', $line);
                $this->remember_cipher(trim($parts[1] ?? ''));
            }
        }
    }

    /**
     * The sentinel a secret setting is seeded with. A webhook holds a URL, so its sentinel is one.
     *
     * @param string $name
     * @return string
     */
    private function plain_for(string $name): string {
        $plain = $this->secret($name);
        return in_array($name, self::WEBHOOKS, true) ? 'https://' . self::HOOKHOST . '/T000/B000/' . $plain : $plain;
    }

    /**
     * Seed a sentinel into every secret and both tables (a legacy plain key, two legacy credentials in a base
     * URL and a keyless row too), and learn the ciphertext of what was stored.
     */
    private function seed_every_secret(): void {
        foreach (secrets::names() as $name) {
            $plain = $this->plain_for($name);
            set_config($name, secrets::conceal($plain), 'local_ai_course_assistant');
            $this->remember($plain);
        }
        $rows = [];
        foreach (['openai', 'claude', 'gemini'] as $p) {
            $plain = $this->secret('row-' . $p);
            $this->remember($plain);
            $rows[] = $p . '|' . secrets::conceal($plain) . '|m-' . $p;
        }
        // Rows saved before 7.9.0: one holds its key in the clear, two hold a credential in the base URL (the
        // table refuses to save those until the admin moves the secret, so a save below drops them).
        $legacykey = $this->secret('row-legacy-plain');
        $urlpass = $this->secret('row-baseurl-password');
        $urlparam = $this->secret('row-baseurl-param');
        $mistral = $this->secret('row-mistral');
        $openrouter = $this->secret('row-openrouter');
        foreach ([$legacykey, $urlpass, $urlparam, $mistral, $openrouter] as $plain) {
            $this->remember($plain);
        }
        $rows[] = 'deepseek|' . $legacykey . '|m-deepseek|0.3|https://api.deepseek.example/v1';
        $rows[] = 'mistral|' . secrets::conceal($mistral) . '|m-mistral|0.5|https://user:' . $urlpass . '@gw.example/v1';
        $rows[] = 'openrouter|' . secrets::conceal($openrouter) . '|m-openrouter|0.3|https://gw.example/v1?api_key=' . $urlparam;
        $rows[] = 'ollama||m-ollama|0.2|http://localhost:11434';
        set_config(provider_keys::SETTING, implode("\n", $rows), 'local_ai_course_assistant');

        $voiceopenai = $this->secret('voice-openai');
        $voicexai = $this->secret('voice-xai-legacy-plain');
        $this->remember($voiceopenai);
        $this->remember($voicexai);
        set_config('voice_providers', 'openai|' . secrets::conceal($voiceopenai) . "|main|shimmer|nova\nxai|" . $voicexai
            . '|' . self::LEGACY_VOICE . '|eve|eve', 'local_ai_course_assistant');
        $this->learn_stored_ciphertext();
    }

    /**
     * Forget the JavaScript the page has been asked to run, so the next render's is read alone.
     */
    private function reset_page_js(): void {
        global $PAGE;
        foreach (['amdjscode' => [''], 'jsinitcode' => []] as $property => $empty) {
            $prop = new \ReflectionProperty($PAGE->requires, $property);
            $prop->setAccessible(true);
            $prop->setValue($PAGE->requires, $empty);
        }
    }

    /**
     * The JavaScript the page has been asked to run (the arguments of every js_call_amd).
     *
     * @return string
     */
    private function page_js(): string {
        global $PAGE;
        $js = '';
        foreach (['amdjscode', 'jsinitcode'] as $property) {
            $prop = new \ReflectionProperty($PAGE->requires, $property);
            $prop->setAccessible(true);
            $js .= "\n" . implode("\n", $prop->getValue($PAGE->requires));
        }
        return $js;
    }

    /**
     * Render every SOLA settings page, as Moodle does: the page's own output_html(), which gives each setting
     * the stored value, or the submitted one when the admin root holds an error for it.
     *
     * @param string[] $expecterrors the settings (without the s_local_ai_course_assistant_ prefix) a failed save
     *                               refused: their submitted values must still be held in the admin root when
     *                               the pages render, so the form shows them again. Empty starts from a clean
     *                               root, as a reload does.
     * @return array{pages: string[], html: string, js: string} page name => html, all html, all page JavaScript
     */
    private function render_all_pages(array $expecterrors = []): array {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        // No setAdminUser() here: changing user nulls the admin tree global, and with it the error list that
        // holds a failed save's submitted values. A test signs in once, before it saves anything.
        $this->reset_page_js();
        $root = \admin_get_root(true, true);
        if ($expecterrors) {
            foreach ($expecterrors as $name) {
                $this->assertArrayHasKey('s_local_ai_course_assistant_' . $name, $root->errors,
                    "the refused $name has its submitted values held, so the re-render uses them");
            }
        } else {
            $root->errors = [];
        }
        $category = $root->locate('local_ai_course_assistant');
        $this->assertNotNull($category, 'the SOLA settings category is in the admin tree');
        $pages = [];
        foreach ($category->children as $page) {
            if ($page instanceof \admin_settingpage) {
                $pages[$page->name] = $page->output_html();
            }
        }
        return ['pages' => $pages, 'html' => implode("\n", $pages), 'js' => $this->page_js()];
    }

    /**
     * The password inputs of a page, and the inputs where a typed key goes, that carry a value.
     *
     * @param string $html
     * @return array{passwords: int, newkeys: int, offenders: string[]}
     */
    private function inspect_inputs(string $html): array {
        $found = ['passwords' => 0, 'newkeys' => 0, 'offenders' => []];
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        foreach ($dom->getElementsByTagName('input') as $input) {
            $type = strtolower($input->getAttribute('type'));
            $name = $input->getAttribute('name');
            $iskey = (bool) preg_match('~\[newkey\]$~', $name);
            $found['passwords'] += $type === 'password' ? 1 : 0;
            $found['newkeys'] += $iskey ? 1 : 0;
            if (($type === 'password' || $iskey) && $input->getAttribute('value') !== '') {
                $found['offenders'][] = $name;
            }
        }
        return $found;
    }

    /**
     * Fail if a plain secret, a piece of ciphertext, or anything shaped like either is in the text.
     *
     * Each text is checked on its own and named in the failure, and the failure never prints the page.
     *
     * @param string[] $texts what was rendered: label => text (one HTML page each, and the page JavaScript)
     * @param string $when which render this is, for the failure message
     */
    private function assert_clean(array $texts, string $when): void {
        $this->assertNotEmpty($texts, "nothing was rendered, so this test would pass vacuously ($when)");
        $this->assertNotEmpty($this->cipherneedles, "no ciphertext was learned (is conceal() returning plain text?), so the "
            . "ciphertext checks would pass vacuously ($when)");
        $this->assertNotEmpty($this->endings, "no plain secret was remembered, so the plain checks would pass vacuously ($when)");
        foreach ($texts as $label => $text) {
            $where = "$label ($when)";
            $this->assertNotSame('', trim($text), "$where rendered nothing, so this check would pass vacuously");
            $this->assertFalse(str_contains($text, self::SENTINEL), "a sentinel secret, or part of one, is in $where");
            $this->assertFalse(str_contains($text, self::HOOKHOST), "a webhook URL is in $where");
            foreach ($this->plains as $plain) {
                $this->assertFalse(str_contains($text, $plain), "a plain secret is in $where");
            }
            foreach ($this->endings as $ending => $plain) {
                $this->assertFalse(str_contains($text, $ending), "the end of a plain secret is in $where");
            }
            foreach ($this->cipherneedles as $needle) {
                $this->assertFalse(str_contains($text, $needle), "ciphertext is in $where");
            }
            $this->assertSame(0, preg_match('~\b(sodium|openssl-aes-256-ctr):[A-Za-z0-9+/=\\\\]{8,}~', $text),
                "ciphertext-shaped text is in $where");
            $inputs = $this->inspect_inputs($text);
            $this->assertSame([], $inputs['offenders'], "a password or new-key input carries a value in $where");
        }
    }

    /**
     * Every saved key shows its OWN "ends in" hint, and every saved webhook says only that a URL is saved.
     *
     * What should show is worked out from what is stored now, one assertion per row or field, so a hint that
     * belongs to another key (or one that is missing) fails by name. The last four are taken with substr()
     * from the revealed key, not with provider_keys::last4() whose output is what is being checked.
     *
     * @param string $html all the rendered pages
     * @param string $when
     */
    private function assert_hints(string $html, string $when): void {
        $seen = ['provider rows' => 0, 'voice rows' => 0, 'single keys' => 0, 'webhooks' => 0];
        foreach (provider_keys::stored_rows() as $i => $row) {
            if ($row['stored_key'] === '') {
                continue;
            }
            $seen['provider rows']++;
            $this->assertTrue(str_contains($html, $this->row_hint(secrets::reveal($row['stored_key']))),
                "provider row $i ({$row['provider']}) shows the last four of its own key ($when)");
        }
        foreach (voice_registry::parse_rows(false) as $i => $row) {
            if ($row['apikey'] === '') {
                continue;
            }
            $seen['voice rows']++;
            $this->assertTrue(str_contains($html, $this->row_hint(secrets::reveal($row['apikey']))),
                "voice row $i ({$row['label']}) shows the last four of its own key ($when)");
        }
        foreach (secrets::names() as $name) {
            if (in_array($name, secrets::NOT_ON_SETTINGS_PAGE, true)) {
                continue;
            }
            if (in_array($name, self::WEBHOOKS, true)) {
                $seen['webhooks']++;
                continue;
            }
            $seen['single keys']++;
            $this->assertTrue(str_contains($html, $this->field_hint(secrets::get($name))),
                "the field $name shows the last four of its own key ($when)");
        }
        $url = get_string('providerkeys:saved_url_hint', 'local_ai_course_assistant');
        $this->assertGreaterThanOrEqual(count(self::WEBHOOKS), substr_count($html, $url),
            "each webhook field says a URL is saved ($when)");
        $this->assertGreaterThanOrEqual(3, $seen['provider rows'], "the provider rows with a key were checked ($when)");
        $this->assertGreaterThanOrEqual(2, $seen['voice rows'], "the voice rows with a key were checked ($when)");
        $this->assertGreaterThanOrEqual(10, $seen['single keys'], "the single-key fields were checked ($when)");
        $this->assertSame(count(self::WEBHOOKS), $seen['webhooks'], "both webhook fields were checked ($when)");
    }

    /**
     * Fail if what was seeded did not make it onto the pages: a page that silently failed to render, or one
     * that rendered without the saved state, would otherwise pass every "not contained" check.
     *
     * @param array $rendered the result of render_all_pages()
     * @param string $when
     * @param string[] $rows table rows that must be on the pages (a model or label each shows)
     * @param bool $legacyurls whether the two legacy rows with a credential in their base URL are still saved
     */
    private function assert_rendered(array $rendered, string $when, array $rows, bool $legacyurls): void {
        $html = $rendered['html'];
        $has = fn(string $needle): bool => str_contains($html, $needle);
        $this->assertGreaterThanOrEqual(11, count($rendered['pages']), "the tree walk saw every SOLA settings page ($when)");
        foreach ($rendered['pages'] as $name => $pagehtml) {
            $this->assertTrue(str_contains($pagehtml, 'id_s_local_ai_course_assistant_'),
                "the page $name rendered at least one setting ($when)");
        }
        foreach (secrets::names() as $name) {
            if (!in_array($name, secrets::NOT_ON_SETTINGS_PAGE, true)) {
                $this->assertTrue($has('s_local_ai_course_assistant_' . $name), "the setting $name is on a page that rendered ($when)");
            }
        }
        $this->assertTrue($has('s_local_ai_course_assistant_' . provider_keys::SETTING), "providers table is on a page ($when)");
        $this->assertTrue($has('s_local_ai_course_assistant_voice_providers'), "voice table is on a page ($when)");
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'sola-kr-add'), "both tables show their add button ($when)");
        $this->assertTrue(str_contains($rendered['js'], 'local_ai_course_assistant/keyed_rows'), "the table script is loaded ($when)");
        // The saved state is shown, and shown as a hint, not as a key.
        foreach ($rows as $shown) {
            $this->assertTrue($has($shown), "a saved row is on the page: $shown ($when)");
        }
        $this->assert_hints($html, $when);
        if ($legacyurls) {
            // A legacy credential in a base URL is masked, not dropped: the row is there, the secret is not.
            $this->assertTrue($has('***@gw.example'), "a legacy credential in a base URL is masked ($when)");
            $this->assertTrue($has('api_key=***'), "a legacy credential in a base URL query is masked ($when)");
        }
        // The input checks in assert_clean() saw the key inputs they are about: one password input per
        // single-key field, and a new-key input per table row.
        $inputs = $this->inspect_inputs($html);
        $singlekeys = count(secrets::names()) - count(secrets::NOT_ON_SETTINGS_PAGE);
        $this->assertGreaterThanOrEqual($singlekeys, $inputs['passwords'], "the password inputs were inspected ($when)");
        $this->assertGreaterThanOrEqual(count($rows) - 1, $inputs['newkeys'], "the new-key inputs of the table rows were inspected ($when)");
    }

    /**
     * Render everything and run every check.
     *
     * @param string $when
     * @param string[] $rows table rows that must be on the pages
     * @param bool $legacyurls whether the legacy rows with a credential in their base URL are still saved
     * @param string[] $expecterrors see render_all_pages()
     * @return array the render
     */
    private function render_and_check(string $when, array $rows = self::SEEDED_ROWS, bool $legacyurls = true,
            array $expecterrors = []): array {
        $rendered = $this->render_all_pages($expecterrors);
        $this->assert_rendered($rendered, $when, $rows, $legacyurls);
        $this->assert_clean($rendered['pages'] + ['the page JavaScript' => $rendered['js']], $when);
        return $rendered;
    }

    /**
     * Post fields the way the settings form does, through Moodle's own save function. The test must already be
     * signed in as an admin (see render_all_pages() for why this doesn't do it).
     *
     * @param array $fields setting name (without the s_local_ai_course_assistant_ prefix) => posted value
     */
    private function post(array $fields): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $form = [];
        foreach ($fields as $name => $value) {
            $form['s_local_ai_course_assistant_' . $name] = $value;
        }
        \admin_write_settings($form);
    }

    /**
     * The rows of a table as the form posts them when no key is retyped: each existing row keeps its id.
     *
     * @param array[] $stored provider_keys::stored_rows() style rows (id and the column values)
     * @param string[] $columns the column names after the key
     * @return array[]
     */
    private function keep_all(array $stored, array $columns): array {
        $rows = [];
        foreach ($stored as $r) {
            $posted = ['newkey' => '', 'keepid' => $r['id']];
            foreach ($columns as $column) {
                $posted[$column] = $r[$column];
            }
            $rows[] = $posted;
        }
        return $rows;
    }

    /**
     * The voice table's rows as stored, with the row ids the form would carry.
     *
     * @return array[]
     */
    private function voice_rows(): array {
        $rows = [];
        foreach (voice_registry::parse_rows(false) as $r) {
            $rows[] = ['id' => provider_keys::rowid($r['provider'] . '|' . $r['label'], $r['apikey'])] + $r;
        }
        return $rows;
    }

    public function test_every_secret_is_absent_on_first_load_and_reload(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->seed_every_secret();
        $this->render_and_check('first load');
        $this->render_and_check('reload');
        $this->render_and_check('third load');
    }

    /**
     * The masking scan in settings_secret_masking_test reads settings.php for declarations with a literal name,
     * so it can't see a secret declared in a loop with a built name (the eight avatar keys and webhook
     * secrets). This walks the real admin tree instead: every listed secret on a page is the masking field
     * (the webhook URLs are its subclass), so it logs a mask and shows only a last four.
     */
    public function test_every_listed_secret_on_a_page_is_the_masking_field(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $category = \admin_get_root(true, true)->locate('local_ai_course_assistant');
        $seen = [];
        $wrong = [];
        foreach ($category->children as $page) {
            if (!$page instanceof \admin_settingpage) {
                continue;
            }
            foreach ($page->settings as $setting) {
                if ($setting->plugin !== 'local_ai_course_assistant' || !secrets::is_secret($setting->name)) {
                    continue;
                }
                $seen[$setting->name] = $setting;
                if (!$setting instanceof admin_setting_secret_field) {
                    $wrong[] = $setting->name . ' (' . get_class($setting) . ')';
                }
            }
        }
        $this->assertSame([], $wrong, 'these listed secrets are not admin_setting_secret_field');
        $expected = array_values(array_diff(secrets::names(), secrets::NOT_ON_SETTINGS_PAGE));
        $this->assertSame([], array_values(array_diff($expected, array_keys($seen))), 'every listed secret is on a settings page');
        $this->assertGreaterThanOrEqual(17, count($seen));
        // The loop-built ones, by name, so the walk is known to have reached them.
        foreach (secrets::AVATAR_PROVIDERS as $provider) {
            $this->assertArrayHasKey($provider . '_api_key', $seen);
            $this->assertArrayHasKey($provider . '_webhook_secret', $seen);
        }
        foreach (self::WEBHOOKS as $name) {
            $this->assertInstanceOf(admin_setting_webhook_url::class, $seen[$name]);
        }
    }

    public function test_every_secret_is_absent_after_a_successful_save(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->seed_every_secret();
        $columns = ['provider', 'models', 'temperature', 'baseurl'];
        $typedrow = $this->secret('typed-on-save');
        $typedvoice = $this->secret('typed-voice-on-save');
        $typedsingle = $this->secret('typed-single-on-save');
        $typedhook = 'https://' . self::HOOKHOST . '/T000/B000/' . $this->secret('typed-hook-on-save');
        $voicelabel = 'new-voice-label-nl7x';
        foreach ([$typedrow, $typedvoice, $typedsingle, $typedhook] as $typed) {
            $this->remember($typed);
        }

        // The Providers table: every row kept with no key retyped, one new row with a typed key. A legacy row
        // that holds a credential in its base URL would be refused, so those two are dropped from this save.
        $stored = array_values(array_filter(provider_keys::stored_rows(),
            static fn(array $r): bool => !in_array($r['provider'], ['mistral', 'openrouter'], true)));
        $providerrows = $this->keep_all($stored, $columns);
        $providerrows[] = ['provider' => 'together', 'newkey' => $typedrow, 'keepid' => '', 'models' => 'm-together',
            'temperature' => '', 'baseurl' => ''];
        $voicerows = $this->keep_all($this->voice_rows(), ['provider', 'label', 'realtime_voice', 'tts_voice']);
        $voicerows[] = ['provider' => 'xai', 'newkey' => $typedvoice, 'keepid' => '', 'label' => $voicelabel,
            'realtime_voice' => 'eve', 'tts_voice' => 'eve'];

        $this->post([
            provider_keys::SETTING => ['rows' => $providerrows, 'present' => '1'],
            'voice_providers' => ['rows' => $voicerows, 'present' => '1'],
            'embed_apikey' => $typedsingle,
            'anomaly_digest_slack_webhook' => $typedhook,
        ]);
        $this->assertSame([], \admin_get_root()->errors, 'the save went through');
        $this->assertSame($typedrow, provider_keys::key_for('together'), 'the typed key was saved');
        $this->assertSame($typedsingle, secrets::get('embed_apikey'));
        $this->assertSame($typedhook, secrets::get('anomaly_digest_slack_webhook'));
        $this->assertCount(count($stored) + 1, provider_keys::stored_rows());
        // The typed voice key was saved too: the new row is stored with its own key (read back decrypted).
        $newvoice = array_values(array_filter(voice_registry::parse_rows(),
            static fn(array $r): bool => $r['label'] === $voicelabel));
        $this->assertCount(1, $newvoice, 'the new voice row was saved');
        $this->assertSame('xai', $newvoice[0]['provider']);
        $this->assertSame($typedvoice, $newvoice[0]['apikey'], 'the new voice row holds the typed key');
        $this->assertCount(3, voice_registry::parse_rows(), 'the two seeded voice rows are still there');
        $this->learn_stored_ciphertext();

        // The saved rows are on the page (the two legacy rows with a credential in the URL were dropped), and
        // the new ones show as a hint and nothing else of their key.
        $rows = ['m-openai', 'm-claude', 'm-gemini', 'm-deepseek', 'm-ollama', 'm-together', self::LEGACY_VOICE, $voicelabel];
        $rendered = $this->render_and_check('after a successful save', $rows, false);
        // Each typed key shows its own last four, and the four of one are not those of another.
        $tails = [substr($typedrow, -4), substr($typedvoice, -4), substr($typedsingle, -4)];
        $this->assertCount(3, array_unique($tails), 'the typed keys end differently');
        $this->assertTrue(str_contains($rendered['html'], $this->row_hint($typedrow)), 'the new provider row shows the end of its key');
        $this->assertTrue(str_contains($rendered['html'], $this->row_hint($typedvoice)), 'the new voice row shows the end of its key');
        $this->assertTrue(str_contains($rendered['html'], $this->field_hint($typedsingle)), 'the saved single key shows the end of its key');
        $this->render_and_check('reload after a successful save', $rows, false);
    }

    public function test_a_typed_key_is_never_echoed_after_a_failed_save_through_moodles_own_path(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->seed_every_secret();
        $typedrow = $this->secret('typed-and-refused');
        $typedvoice = $this->secret('typed-voice-refused');
        $typedhook = $this->secret('typed-hook-refused-not-a-url');
        $typedsingle = $this->secret('typed-single-refused');
        foreach ([$typedrow, $typedvoice, $typedhook, $typedsingle] as $typed) {
            $this->remember($typed);
        }
        $before = [
            provider_keys::SETTING => (string) get_config('local_ai_course_assistant', provider_keys::SETTING),
            'voice_providers' => (string) get_config('local_ai_course_assistant', 'voice_providers'),
            'anomaly_digest_slack_webhook' => (string) get_config('local_ai_course_assistant', 'anomaly_digest_slack_webhook'),
        ];

        // Each of these is refused: a temperature of 99, a provider the voice table doesn't know, and a
        // webhook value that isn't a URL. The single key field has no validation, so it is saved, and
        // is checked on the render after it. What the form re-shows for the refused ones is what Moodle keeps
        // in the admin root's error list.
        $this->post([
            provider_keys::SETTING => ['rows' => [['provider' => 'openai', 'newkey' => $typedrow, 'keepid' => '',
                'models' => '', 'temperature' => '99', 'baseurl' => '']], 'present' => '1'],
            'voice_providers' => ['rows' => [['provider' => 'gemini', 'newkey' => $typedvoice, 'keepid' => '',
                'label' => '', 'realtime_voice' => '', 'tts_voice' => '']], 'present' => '1'],
            'anomaly_digest_slack_webhook' => $typedhook,
            'embed_apikey' => $typedsingle,
        ]);
        $errors = \admin_get_root()->errors;
        $this->assertArrayHasKey('s_local_ai_course_assistant_' . provider_keys::SETTING, $errors, 'the providers save was refused');
        $this->assertArrayHasKey('s_local_ai_course_assistant_voice_providers', $errors, 'the voice save was refused');
        $this->assertArrayHasKey('s_local_ai_course_assistant_anomaly_digest_slack_webhook', $errors, 'the webhook save was refused');
        foreach ($before as $name => $value) {
            $this->assertSame($value, (string) get_config('local_ai_course_assistant', $name), "$name is unchanged by a refused save");
        }
        $this->learn_stored_ciphertext();

        // Moodle's own re-render: page->output_html() hands each failed setting the submitted array.
        $this->assertNotEmpty(\admin_get_root()->errors, 'the submitted values are still held for the re-render');
        $this->render_and_check('failed save, re-rendered from the submitted values', self::SEEDED_ROWS, true, [
            provider_keys::SETTING,
            'voice_providers',
            'anomaly_digest_slack_webhook',
        ]);
        // Then the page as it is on the next load, when the errors are gone.
        $this->render_and_check('reload after a failed save');
    }

    public function test_a_typed_key_is_never_echoed_by_either_table_when_given_the_submitted_array(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->seed_every_secret();
        $typed = $this->secret('typed-and-refused-direct');
        $this->remember($typed);

        $table = new admin_setting_provider_keys('local_ai_course_assistant/comparison_providers', 'k', 'd');
        $submitted = ['rows' => [['provider' => 'openai', 'newkey' => $typed, 'keepid' => '', 'models' => '',
            'temperature' => '99', 'baseurl' => '']]];
        $this->assertNotSame('', $table->write_setting($submitted));
        $this->reset_page_js();
        $html = $table->output_html($submitted);
        $this->assertTrue(str_contains($html, 'sola-kr-add'), 'the provider table rendered');
        $this->assert_clean(['provider table' => $html, 'the page JavaScript' => $this->page_js()], 'after a failed save');

        $voice = new admin_setting_voice_providers('local_ai_course_assistant/voice_providers', 'v', 'd');
        $voicesubmitted = ['rows' => [['provider' => 'gemini', 'newkey' => $typed, 'keepid' => '', 'label' => '',
            'realtime_voice' => '', 'tts_voice' => '']]];
        $this->assertNotSame('', $voice->write_setting($voicesubmitted));
        $this->reset_page_js();
        $html = $voice->output_html($voicesubmitted);
        $this->assertTrue(str_contains($html, 'sola-kr-add'), 'the voice table rendered');
        $this->assert_clean(['voice table' => $html, 'the page JavaScript' => $this->page_js()], 'after a failed save');
    }

    /**
     * What a save leaves behind for people to read: the config log, and the notice on the next admin page.
     */
    public function test_the_config_log_and_the_settings_changed_notice_never_carry_a_key(): void {
        global $DB, $SESSION;
        $this->resetAfterTest();
        $this->setAdminUser();
        settings_save_report::assume_web(true);
        $this->seed_every_secret();
        $mark = (int) $DB->get_field_sql('SELECT COALESCE(MAX(id), 0) FROM {config_log}');
        unset($SESSION->local_ai_course_assistant_savedchanges);

        $typedrow = $this->secret('typed-log-row');
        $typedvoice = $this->secret('typed-log-voice');
        $typedsingle = $this->secret('typed-log-single');
        $typedhook = 'https://' . self::HOOKHOST . '/T000/B000/' . $this->secret('typed-log-hook');
        foreach ([$typedrow, $typedvoice, $typedsingle, $typedhook] as $typed) {
            $this->remember($typed);
        }
        // Replace the key of one existing row of each table (a typed key on the same provider), add one, and
        // replace a single key and a webhook, so there is an old and a new ciphertext in play for every kind.
        $providerrows = [];
        foreach (provider_keys::stored_rows() as $r) {
            if (in_array($r['provider'], ['mistral', 'openrouter'], true)) {
                continue;
            }
            $providerrows[] = ['provider' => $r['provider'], 'newkey' => $r['provider'] === 'openai' ? $typedrow : '',
                'keepid' => $r['id'], 'models' => $r['models'], 'temperature' => $r['temperature'], 'baseurl' => $r['baseurl']];
        }
        $voicerows = [];
        foreach ($this->voice_rows() as $r) {
            $voicerows[] = ['provider' => $r['provider'], 'newkey' => $r['provider'] === 'openai' ? $typedvoice : '',
                'keepid' => $r['id'], 'label' => $r['label'], 'realtime_voice' => $r['realtime_voice'], 'tts_voice' => $r['tts_voice']];
        }
        $this->post([
            provider_keys::SETTING => ['rows' => $providerrows, 'present' => '1'],
            'voice_providers' => ['rows' => $voicerows, 'present' => '1'],
            'embed_apikey' => $typedsingle,
            'anomaly_digest_slack_webhook' => $typedhook,
        ]);
        $this->assertSame([], \admin_get_root()->errors, 'the save went through');
        $this->learn_stored_ciphertext();

        // The config log.
        $rows = $DB->get_records_select('config_log', 'plugin = ? AND id > ?', ['local_ai_course_assistant', $mark], 'id ASC');
        $names = array_unique(array_map(static fn($r) => $r->name, $rows));
        foreach ([provider_keys::SETTING, 'voice_providers', 'embed_apikey', 'anomaly_digest_slack_webhook'] as $name) {
            $this->assertContains($name, $names, "the save logged $name, so the log check below isn't vacuous");
        }
        foreach ($rows as $row) {
            foreach ([(string) $row->value, (string) $row->oldvalue] as $logged) {
                $this->assertStringNotContainsString(self::SENTINEL, $logged, "config_log holds a secret ({$row->name})");
                $this->assertStringNotContainsString(self::HOOKHOST, $logged, "config_log holds a URL ({$row->name})");
                $this->assertDoesNotMatchRegularExpression('~(sodium|openssl-aes-256-ctr):~', $logged,
                    "config_log holds ciphertext ({$row->name})");
                foreach ($this->cipherneedles as $needle) {
                    $this->assertStringNotContainsString($needle, $logged, "config_log holds ciphertext ({$row->name})");
                }
            }
        }

        // The notice on the next admin page. Reloading the admin tree after the save (as the settings page
        // does) already turned what the save left in the session into a notice, so flushing is a no-op here,
        // and is done only in case that changes.
        settings_save_report::flush();
        $text = '';
        foreach (\core\notification::fetch() as $notice) {
            $text .= $notice->get_message();
        }
        $this->assertStringContainsString('embed_apikey', $text, 'the notice names the setting that changed');
        $this->assertStringContainsString(get_string('savereport:secret', 'local_ai_course_assistant'), $text);
        $this->assertStringNotContainsString(self::SENTINEL, $text);
        $this->assertStringNotContainsString(self::HOOKHOST, $text);
        $this->assertDoesNotMatchRegularExpression('~(sodium|openssl-aes-256-ctr):~', $text);
        foreach ($this->cipherneedles as $needle) {
            $this->assertStringNotContainsString($needle, $text);
        }
    }

    /**
     * An admin's save logs both tables with every key blanked, so the notice never sees one. A table written by
     * set_config() (an upgrade step, a script) reaches the log whole, though, with ciphertext in the key
     * column and a legacy key in the clear in the old value. The notice has to hide those too.
     */
    public function test_the_save_report_notice_never_carries_a_key_even_if_it_is_handed_one(): void {
        $this->resetAfterTest();
        $plain = $this->secret('report');
        $legacy = $this->secret('report-legacy-plain');
        $otherplain = $this->secret('report-other');
        $stored = secrets::conceal($plain);
        $other = secrets::conceal($otherplain);
        $this->remember($plain, $stored);
        $this->remember($otherplain, $other);
        $this->remember($legacy);
        $changes = [
            provider_keys::SETTING => ['openai|' . $stored . '|m', 'openai|' . $other . '|m'],
            'voice_providers' => ['openai|' . $stored . '|main|shimmer|nova', 'openai|' . $other . '|main|shimmer|nova'],
        ];
        $legacychange = [provider_keys::SETTING => ['openai|' . $legacy . '|m', 'claude|' . $legacy . '|m']];
        // And every secret setting by name, with a ciphertext on each side.
        foreach (secrets::names() as $name) {
            $changes[$name] = [$stored, $other];
        }
        foreach ([$changes, $legacychange] as $handed) {
            $report = settings_save_report::describe($handed);
            $text = strip_tags($report['message']);
            $this->assertNotSame('', $text);
            $this->assertFalse(str_contains($text, self::SENTINEL), 'a plain secret is in the notice');
            $this->assertSame(0, preg_match('~(sodium|openssl-aes-256-ctr):~', $text), 'ciphertext is in the notice');
            foreach ($this->cipherneedles as $needle) {
                $this->assertFalse(str_contains($text, $needle), 'ciphertext is in the notice');
            }
        }
        // The table change still reads as a change: the provider moved from openai to claude.
        $this->assertTrue(str_contains($report['message'], 'claude'), 'the redacted difference is still shown');
    }

    /**
     * The per-course page is a script, not a settings page, so it can't be rendered here. What it can do is
     * hand its template a saved course key: it doesn't. The context carries a yes/no ("a key is stored"), a
     * fixed placeholder and fixed labels, and the key input is rendered empty, with no variable in it that
     * could carry a value. This pins that at the source: the array that becomes the template context, and
     * the input in the template.
     */
    public function test_the_course_settings_page_hands_its_template_no_key(): void {
        $script = (string) file_get_contents(__DIR__ . '/../course_settings.php');
        $template = (string) file_get_contents(__DIR__ . '/../templates/course_settings.mustache');
        $this->assertNotSame('', $script);
        $this->assertNotSame('', $template);

        // The array that is rendered: from where it is built to the render call.
        $start = strpos($script, '$templatedata = [');
        $end = strpos($script, "render_from_template('local_ai_course_assistant/course_settings'");
        $this->assertNotFalse($start, 'the template context is built in $templatedata');
        $this->assertNotFalse($end, 'and rendered with it');
        $this->assertGreaterThan($start, $end);
        $context = substr($script, $start, $end - $start);
        $this->assertGreaterThan(40, substr_count($context, "\n"), 'the slice is the whole context, not a fragment');

        // Everything in it that mentions a key, and what each is built from: fixed strings and a boolean.
        $keylines = [];
        foreach (explode("\n", $context) as $line) {
            if (stripos($line, 'apikey') !== false || stripos($line, 'haskey') !== false) {
                $keylines[] = trim($line);
            }
        }
        $this->assertSame([
            "'apikeylabel' => get_string('settings:apikey', 'local_ai_course_assistant'),",
            "'apikeyplaceholder' => \$keyph,",
            "'haskey' => (bool) \$haskey,",
            "'apikeyclearlabel' => get_string('coursesettings:apikey_clear', 'local_ai_course_assistant'),",
            "'apikeydesc' => get_string('settings:apikey_desc', 'local_ai_course_assistant'),",
        ], $keylines, 'the context mentions a key only as labels, a placeholder and a yes/no');

        // The placeholder is one of two fixed strings, and the yes/no is whether a key is stored.
        $this->assertMatchesRegularExpression(
            "~\\\$keyph = \\\$haskey\\s*\\?\\s*get_string\\('coursesettings:apikey_stored', 'local_ai_course_assistant'\\)"
            . "\\s*:\\s*get_string\\('coursesettings:using_global', 'local_ai_course_assistant'\\);~",
            $script, 'the placeholder is one of two fixed strings');
        $this->assertStringContainsString("\$haskey = \$current && \$current->apikey !== '';", $script,
            'the stored key is reduced to a yes/no');

        // The stored record and the revealed keys are never passed on: $current is only ever tested or read
        // a named field at a time (never apikey), and no key variable of the form handling is in the context.
        preg_match_all('~\$current(?!\w)(->\w+)?~', $context, $uses, PREG_SET_ORDER);
        $this->assertNotEmpty($uses);
        foreach ($uses as $use) {
            $this->assertNotSame('->apikey', $use[1] ?? '', 'the course key is not read into the context');
        }
        foreach (['$storedkey', '$storedraw', "\$globalcfg['apikey']", '$existingkey', "\$data['apikey']"] as $variable) {
            $this->assertStringNotContainsString($variable, $context, "$variable never reaches the template context");
        }

        // The input: rendered empty, with nothing in the tag but the placeholder variable.
        $this->assertSame(1, preg_match('~<input[^>]*name="apikey"[^>]*>~s', $template, $input), 'the key input is in the template');
        $this->assertStringContainsString('value=""', $input[0]);
        preg_match_all('~\{\{[^}]*\}\}~', $input[0], $variables);
        $this->assertSame(['{{apikeyplaceholder}}'], $variables[0], 'the only variable in the key input is the placeholder');
    }

    /**
     * Every line of course_settings.php that touches a course key, whatever it is called, is one of the known
     * safe ones: reading the typed key, reducing a stored key to a yes/no, the two fixed placeholder strings,
     * the labels. A new line that reads or prints a key (a last four, a debug dump, the global key put back
     * into the page) is not on the list, so it fails here and gets looked at before it ships.
     */
    public function test_every_line_in_course_settings_that_touches_a_course_key_is_a_known_safe_one(): void {
        $script = (string) file_get_contents(__DIR__ . '/../course_settings.php');
        $this->assertNotSame('', $script);
        $safe = [
            // The posted form: the typed key is read, judged by the guard and saved, never printed.
            "'apikey'      => optional_param('apikey', '', PARAM_RAW_TRIMMED),",
            "\$typedkey = \$data['apikey'];",
            "\$existingkey = course_config_manager::get(\$courseid);",
            "\$storedraw = (\$existingkey && isset(\$existingkey->apikey)) ? (string) \$existingkey->apikey : '';",
            "\$storedkey = \\local_ai_course_assistant\\secrets::reveal(\$storedraw);",
            "\$keydecision = \\local_ai_course_assistant\\course_key_guard::resolve(",
            "\$typedkey,",
            "\$storedkey,",
            "\$data['apikey'] = \$keydecision['apikey'];",
            "if (\$data['apikey'] === '' && \$storedkey === '' && \$storedraw !== '') {",
            "\$data['apikey'] = \$storedraw;",
            "foreach (\$keydecision['notices'] as [\$noticetype, \$noticekey, \$noticea]) {",
            "if (optional_param('apikey_clear', 0, PARAM_INT)) {",
            "\$data['apikey'] = '';",
            // The page: a stored key becomes a yes/no and one of two fixed sentences, and the labels.
            "\$haskey = \$current && \$current->apikey !== '';",
            "\$keyph = \$haskey",
            "? get_string('coursesettings:apikey_stored', 'local_ai_course_assistant')",
            "'apikeylabel' => get_string('settings:apikey', 'local_ai_course_assistant'),",
            "'apikeyplaceholder' => \$keyph,",
            "'haskey' => (bool) \$haskey,",
            "'apikeyclearlabel' => get_string('coursesettings:apikey_clear', 'local_ai_course_assistant'),",
            "'apikeydesc' => get_string('settings:apikey_desc', 'local_ai_course_assistant'),",
        ];
        $found = [];
        foreach (explode("\n", $script) as $line) {
            $line = trim($line);
            if (preg_match('~apikey|\$typedkey|\$existingkey|\$storedraw|\$storedkey|\$keydecision|\$haskey|\$keyph~i', $line)) {
                $found[] = $line;
            }
        }
        $this->assertGreaterThanOrEqual(20, count($found), 'the scan found the key lines');
        $this->assertSame([], array_values(array_diff($found, $safe)),
            'a line in course_settings.php that touches a course key is new: check it never reaches the page, then add it here');
        $this->assertSame([], array_values(array_diff($safe, $found)),
            'a known safe line is gone or changed: update this list so it keeps pinning the real lines');
    }
}
