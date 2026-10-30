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

use local_ai_course_assistant\tests\settings_tree;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/settings_tree.php');
require_once(__DIR__ . '/fixtures/prompt_metrics_seed.php');

/**
 * The settings usage report (7.10.0): which settings a site changed, never what it changed them to.
 *
 * Every test that plants a value also asserts the value, its hash and (for a
 * secret) its ciphertext appear nowhere in the report array, its JSON or the
 * text the CLI prints.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\settings_usage
 */
final class settings_usage_test extends \advanced_testcase {
    /** @var string The plugin component. */
    private const PLUGIN = 'local_ai_course_assistant';

    /** @var string A plain text setting with a non-empty default. */
    private const TEXT_SETTING = 'audit_retention_days';

    /** @var string A plain text setting whose default is empty. */
    private const EMPTY_DEFAULT_SETTING = 'anomaly_digest_recipient_email';

    /** @var string A secret setting. */
    private const SECRET_SETTING = 'zendesk_token';

    /** @var string The per-course config table. */
    private const COURSE_TABLE = 'local_ai_course_assistant_course_cfg';

    /**
     * Sign in as an administrator, so settings.php registers the plugin's pages.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The default a setting registers in the live admin tree.
     *
     * @param string $name Setting name.
     * @return string The default as a string.
     */
    private function default_of(string $name): string {
        foreach (settings_tree::settings(settings_tree::category()) as $setting) {
            if ($setting->name === $name) {
                return (string) $setting->get_defaultsetting();
            }
        }
        $this->fail("The admin tree has no setting named $name.");
    }

    /**
     * Assert none of the given strings appears in the report, its JSON or its text form.
     *
     * @param array $report A settings_usage::report() result.
     * @param string[] $needles Strings that must not appear anywhere.
     */
    private function assert_nowhere(array $report, array $needles): void {
        $haystacks = [
            'the report array' => print_r($report, true),
            'the report JSON' => json_encode($report),
            'the CLI text' => settings_usage::render_text($report),
        ];
        foreach ($needles as $needle) {
            $this->assertNotSame('', $needle);
            foreach ($haystacks as $where => $text) {
                $this->assertStringNotContainsString($needle, $text, "A stored value leaked into $where");
                $this->assertStringNotContainsString(md5($needle), $text, "A value hash leaked into $where");
            }
        }
    }

    public function test_a_setting_stored_at_its_default_reports_default(): void {
        set_config(self::TEXT_SETTING, $this->default_of(self::TEXT_SETTING), self::PLUGIN);
        $report = settings_usage::report();
        $this->assertSame('default', $report['settings'][self::TEXT_SETTING]);
    }

    public function test_a_changed_setting_reports_changed_and_its_value_appears_nowhere(): void {
        $sentinel = 'sentinel-value-7c41e9@example.invalid';
        set_config(self::EMPTY_DEFAULT_SETTING, $sentinel, self::PLUGIN);
        $report = settings_usage::report(true, true);
        $this->assertSame('changed', $report['settings'][self::EMPTY_DEFAULT_SETTING]);
        $this->assert_nowhere($report, [$sentinel]);
    }

    public function test_a_value_that_differs_only_by_a_trailing_space_is_changed(): void {
        $default = $this->default_of(self::TEXT_SETTING);
        set_config(self::TEXT_SETTING, $default . ' ', self::PLUGIN);
        $this->assertSame('changed', settings_usage::report()['settings'][self::TEXT_SETTING]);
    }

    public function test_a_setting_with_no_stored_row_reports_not_stored(): void {
        unset_config(self::TEXT_SETTING, self::PLUGIN);
        $report = settings_usage::report();
        $this->assertSame('not stored', $report['settings'][self::TEXT_SETTING]);
    }

    public function test_a_secret_that_is_set_reports_secret_set_and_neither_key_nor_ciphertext_appears(): void {
        global $DB;
        $key = 'sk-sentinel-secret-5d92b7f1a4c8e630';
        set_config(self::SECRET_SETTING, secrets::conceal($key), self::PLUGIN);
        $stored = (string) $DB->get_field('config_plugins', 'value', ['plugin' => self::PLUGIN, 'name' => self::SECRET_SETTING]);
        $this->assertTrue(secrets::is_encrypted($stored), 'the planted secret must be stored as ciphertext');

        $report = settings_usage::report(true, true);
        $this->assertSame('secret set', $report['settings'][self::SECRET_SETTING]);
        $this->assert_nowhere($report, [$key, $stored]);
    }

    public function test_a_secret_stored_as_plain_text_still_reports_secret_set_and_is_not_printed(): void {
        // A key forced in config.php, or one between deploy and upgrade, has no encryption prefix.
        $key = 'plain-sentinel-secret-0b3e6d58';
        set_config(self::SECRET_SETTING, $key, self::PLUGIN);
        $report = settings_usage::report();
        $this->assertSame('secret set', $report['settings'][self::SECRET_SETTING]);
        $this->assert_nowhere($report, [$key]);
    }

    public function test_a_secret_never_saved_reports_secret_unset(): void {
        unset_config(self::SECRET_SETTING, self::PLUGIN);
        $this->assertSame('secret unset', settings_usage::report()['settings'][self::SECRET_SETTING]);
    }

    public function test_a_secret_saved_as_an_empty_string_reports_secret_unset(): void {
        set_config(self::SECRET_SETTING, '', self::PLUGIN);
        $this->assertSame('secret unset', settings_usage::report()['settings'][self::SECRET_SETTING]);
    }

    public function test_every_secret_is_reported_as_set_or_unset_and_never_as_a_plain_state(): void {
        $report = settings_usage::report();
        foreach (secrets::names() as $name) {
            $this->assertContains($report['settings'][$name] ?? null, ['secret set', 'secret unset'], $name);
        }
    }

    public function test_every_setting_in_the_admin_tree_is_reported_once_and_no_secret_is_missing(): void {
        $expected = [];
        foreach (settings_tree::settings(settings_tree::category()) as $setting) {
            if ($setting instanceof \admin_setting_heading || $setting instanceof \admin_setting_description) {
                continue;
            }
            $expected[$setting->name] = true;
        }
        // The legacy apikey mirror is a stored secret with no field on any page.
        foreach (secrets::names() as $name) {
            $expected[$name] = true;
        }
        $report = settings_usage::report();
        $actual = array_keys($report['settings']);
        sort($actual);
        $names = array_keys($expected);
        sort($names);
        $this->assertSame($names, $actual);
        $this->assertGreaterThan(250, count($actual), 'the walk found too few settings to be the whole tree');
        foreach ($report['settings'] as $name => $state) {
            $this->assertContains($state, ['default', 'changed', 'secret set', 'secret unset', 'not stored'], $name);
        }
    }

    public function test_the_summary_counts_match_the_settings_list(): void {
        set_config(self::EMPTY_DEFAULT_SETTING, 'x-sentinel-changed', self::PLUGIN);
        set_config(self::SECRET_SETTING, secrets::conceal('sk-summary-sentinel-1'), self::PLUGIN);
        unset_config(self::TEXT_SETTING, self::PLUGIN);
        $report = settings_usage::report();

        $counts = array_count_values($report['settings']);
        $summary = $report['summary'];
        $this->assertSame(['default', 'changed', 'secret_set', 'secret_unset', 'not_stored'], array_keys($summary));
        $this->assertSame($counts['default'] ?? 0, $summary['default']);
        $this->assertSame($counts['changed'] ?? 0, $summary['changed']);
        $this->assertSame($counts['secret set'] ?? 0, $summary['secret_set']);
        $this->assertSame($counts['secret unset'] ?? 0, $summary['secret_unset']);
        $this->assertSame($counts['not stored'] ?? 0, $summary['not_stored']);
        $this->assertSame(count($report['settings']), array_sum($summary));
        $this->assertGreaterThanOrEqual(1, $summary['changed']);
        $this->assertGreaterThanOrEqual(1, $summary['secret_set']);
        $this->assertGreaterThanOrEqual(1, $summary['not_stored']);
    }

    public function test_percourse_and_tables_are_null_unless_asked_for(): void {
        $report = settings_usage::report();
        $this->assertSame(['settings', 'summary', 'percourse', 'tables'], array_keys($report));
        $this->assertNull($report['percourse']);
        $this->assertNull($report['tables']);
    }

    public function test_percourse_groups_per_course_setting_names_by_base_and_never_prints_a_value(): void {
        $before = settings_usage::report(true)['percourse']['overrides'];
        $value = 'course-override-sentinel-91d3f2';
        set_config('rag_enabled_course_12', $value, self::PLUGIN);
        set_config('rag_enabled_course_13', $value, self::PLUGIN);
        set_config('rag_enabled_course_140', $value, self::PLUGIN);
        set_config('soapbox_level_course_12', $value, self::PLUGIN);
        // Not per-course names: a non-numeric suffix, and a name that only contains the words.
        set_config('rag_enabled_course_x', $value, self::PLUGIN);
        set_config('course_default_cap', $value, self::PLUGIN);

        $report = settings_usage::report(true);
        $now = $report['percourse']['overrides'];
        $this->assertSame(($before['rag_enabled'] ?? 0) + 3, $now['rag_enabled'] ?? 0);
        $this->assertSame(($before['soapbox_level'] ?? 0) + 1, $now['soapbox_level'] ?? 0);
        $this->assertArrayNotHasKey('rag_enabled_course_x', $now);
        $this->assertArrayNotHasKey('rag_enabled_course', $now);
        $this->assertArrayNotHasKey('course_default_cap', $now);
        foreach ($now as $base => $count) {
            $this->assertIsString($base);
            $this->assertIsInt($count);
        }
        $this->assertSame(array_sum($now), $report['percourse']['override_rows']);
        $this->assertGreaterThanOrEqual(3, $report['percourse']['courses']);
        $this->assertLessThanOrEqual($report['percourse']['override_rows'], $report['percourse']['courses']);
        $this->assert_nowhere($report, [$value]);
    }

    public function test_percourse_counts_course_config_rows_and_which_fields_they_set(): void {
        global $DB;
        $now = time();
        $key = 'sk-course-sentinel-key-44ab90cd';
        $prompt = 'COURSE-PROMPT-SENTINEL tell the student a secret';
        $rows = [
            // A full override: provider, model, own key, base URL, prompt, temperature 0 and a cap.
            ['courseid' => 901, 'enabled' => 1, 'provider' => 'openai', 'model' => 'gpt-sentinel-model',
                'apikey' => secrets::conceal($key), 'apibaseurl' => 'https://gateway.sentinel.invalid/v1',
                'systemprompt' => $prompt, 'temperature' => 0, 'spend_cap_monthly' => 25],
            // A provider and model only; empty strings and a zero cap must not count.
            ['courseid' => 902, 'enabled' => 0, 'provider' => 'claude', 'model' => 'claude-sentinel',
                'apikey' => '', 'apibaseurl' => '', 'systemprompt' => '', 'temperature' => null, 'spend_cap_monthly' => 0],
            // Nothing set at all.
            ['courseid' => 903, 'enabled' => 1, 'provider' => null, 'model' => null,
                'apikey' => null, 'apibaseurl' => null, 'systemprompt' => null, 'temperature' => null,
                'spend_cap_monthly' => null],
        ];
        foreach ($rows as $row) {
            $DB->insert_record(self::COURSE_TABLE, (object) ($row + ['timecreated' => $now, 'timemodified' => $now]));
        }

        $report = settings_usage::report(true);
        $config = $report['percourse']['config_rows'];
        $this->assertSame(3, $config['rows']);
        $this->assertSame(2, $config['enabled']);
        $this->assertSame(2, $config['provider']);
        $this->assertSame(2, $config['model']);
        $this->assertSame(1, $config['own_key']);
        $this->assertSame(1, $config['base_url']);
        $this->assertSame(1, $config['system_prompt']);
        $this->assertSame(1, $config['temperature']);
        $this->assertSame(1, $config['spend_cap']);
        $this->assert_nowhere($report, [$key, $prompt, 'gpt-sentinel-model', 'gateway.sentinel.invalid']);
    }

    public function test_percourse_on_a_site_with_no_course_config_rows_counts_zero(): void {
        $config = settings_usage::report(true)['percourse']['config_rows'];
        $this->assertSame(0, $config['rows']);
        foreach (['enabled', 'provider', 'model', 'own_key', 'base_url', 'system_prompt', 'temperature', 'spend_cap'] as $field) {
            $this->assertSame(0, $config[$field], $field);
        }
    }

    public function test_tables_returns_an_exact_row_count_for_every_plugin_table(): void {
        global $DB;
        $now = time();
        foreach ([911, 912, 913, 914] as $courseid) {
            $DB->insert_record(self::COURSE_TABLE, (object) [
                'courseid' => $courseid, 'enabled' => 0, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        $tables = settings_usage::report(false, true)['tables'];

        $expected = [];
        foreach ($DB->get_tables() as $table) {
            if (strpos($table, self::PLUGIN) === 0) {
                $expected[] = $table;
            }
        }
        sort($expected);
        $actual = array_keys($tables);
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->assertGreaterThan(20, count($tables));
        $this->assertSame(4, $tables[self::COURSE_TABLE]);
        foreach ($tables as $table => $rows) {
            $this->assertSame((int) $DB->get_field_sql('SELECT COUNT(*) FROM {' . $table . '}'), $rows, $table);
        }
    }

    public function test_tables_do_not_include_another_plugins_tables(): void {
        $tables = settings_usage::report(false, true)['tables'];
        $this->assertArrayNotHasKey('config_plugins', $tables);
        $this->assertArrayNotHasKey('user', $tables);
        foreach (array_keys($tables) as $table) {
            $this->assertStringStartsWith(self::PLUGIN, $table);
        }
    }

    public function test_a_non_administrator_gets_an_error_not_an_empty_report(): void {
        $this->setGuestUser();
        $this->expectException(\coding_exception::class);
        settings_usage::report();
    }

    /**
     * Stored value against default, as the report decides it.
     *
     * @return array[] Stored, default, expected state.
     */
    public static function status_provider(): array {
        return [
            'no row' => [null, 'x', 'not stored'],
            'no row and an empty default' => [null, '', 'not stored'],
            'same text' => ['abc', 'abc', 'default'],
            'different text' => ['abc', 'abd', 'changed'],
            'empty stored, empty default' => ['', '', 'default'],
            'empty stored, non-empty default' => ['', 'abc', 'changed'],
            'integer default against the stored string' => ['0', 0, 'default'],
            'integer default, different number' => ['1', 0, 'changed'],
            'checkbox on' => ['1', 1, 'default'],
            'null default and an empty stored value' => ['', null, 'default'],
            'null default and a stored value' => ['x', null, 'changed'],
            'windows line endings only' => ["a\r\nb\r\nc", "a\nb\nc", 'default'],
            'old Mac line endings only' => ["a\rb", "a\nb", 'default'],
            'a real text difference past the line endings' => ["a\r\nb\r\nz", "a\nb\nc", 'changed'],
            'trailing space is a change' => ['abc ', 'abc', 'changed'],
            'a list default in another order' => ['b,a', ['a', 'b'], 'default'],
            'a list default with a different member' => ['a,c', ['a', 'b'], 'changed'],
            'an empty list default and an empty stored value' => ['', [], 'default'],
            // Moodle's multicheckbox default is keyed: the key names a choice, the value says on (1) or off (0).
            'a keyed default compares the enabled keys, in another order' => ['c,a', ['a' => 1, 'b' => 0, 'c' => 1], 'default'],
            'a keyed default with one more choice switched on' => ['a,b,c', ['a' => 1, 'b' => 0, 'c' => 1], 'changed'],
            'a keyed default with one choice switched off' => ['a', ['a' => 1, 'b' => 0, 'c' => 1], 'changed'],
            'a keyed default whose 0 and 1 values are not the choice names' => ['0,1', ['a' => 1, 'b' => 0], 'changed'],
            'a keyed default with every choice off and nothing stored' => ['', ['a' => 0, 'b' => 0], 'default'],
            'a keyed default with every choice off against a stored choice' => ['a', ['a' => 0, 'b' => 0], 'changed'],
        ];
    }

    /**
     * @dataProvider status_provider
     * @param string|null $stored The stored value, or null when there is no row.
     * @param mixed $default The registered default.
     * @param string $expected The state the report gives.
     */
    public function test_status_of_compares_stored_to_default(?string $stored, $default, string $expected): void {
        $this->assertSame($expected, settings_usage::status_of($stored, $default));
    }

    public function test_the_text_form_lists_changed_and_secret_set_settings_by_name_only(): void {
        $changed = 'text-form-sentinel-3a90c1';
        $key = 'sk-text-form-sentinel-77fe20';
        set_config(self::EMPTY_DEFAULT_SETTING, $changed, self::PLUGIN);
        set_config(self::SECRET_SETTING, secrets::conceal($key), self::PLUGIN);
        unset_config(self::TEXT_SETTING, self::PLUGIN);
        $report = settings_usage::report();
        $text = settings_usage::render_text($report);
        $lines = explode("\n", $text);

        $summary = $report['summary'];
        $this->assertStringContainsString(
            sprintf(
                '%d settings: %d default, %d changed, %d secret set, %d secret unset, %d not stored',
                count($report['settings']),
                $summary['default'],
                $summary['changed'],
                $summary['secret_set'],
                $summary['secret_unset'],
                $summary['not_stored']
            ),
            $text
        );
        $this->assertContains('changed       ' . self::EMPTY_DEFAULT_SETTING, $lines);
        $this->assertContains('secret set    ' . self::SECRET_SETTING, $lines);
        // Settings at their default, not stored or secret unset get no line of their own.
        $this->assertNotContains('not stored    ' . self::TEXT_SETTING, $lines);
        $this->assertStringNotContainsString(self::TEXT_SETTING, $text);
        $this->assert_nowhere($report, [$changed, $key]);
    }

    public function test_the_text_form_adds_the_per_course_and_table_sections_when_they_were_asked_for(): void {
        global $DB;
        set_config('rag_enabled_course_12', 'text-section-sentinel', self::PLUGIN);
        $now = time();
        $DB->insert_record(self::COURSE_TABLE, (object) [
            'courseid' => 921, 'enabled' => 1, 'provider' => 'openai', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $plain = settings_usage::render_text(settings_usage::report());
        $this->assertStringNotContainsString('Per-course', $plain);
        $this->assertStringNotContainsString('Table row counts', $plain);

        $full = settings_usage::render_text(settings_usage::report(true, true));
        $this->assertStringContainsString('Per-course overrides', $full);
        $this->assertMatchesRegularExpression('~^\s*rag_enabled\s+\d+$~m', $full);
        $this->assertMatchesRegularExpression('~Per-course config table: 1 row~', $full);
        $this->assertStringContainsString('Table row counts', $full);
        $this->assertMatchesRegularExpression('~^\s*' . self::COURSE_TABLE . '\s+1$~m', $full);
        $this->assertStringNotContainsString('text-section-sentinel', $full);
    }

    public function test_a_site_showing_the_conditional_prompt_warnings_raises_no_layout_notice(): void {
        // The report builds the admin tree, so a developer notice from settings_layout::build() (a setting with
        // no page) would land in --json. These two warnings are built only under a condition (a truncation in the
        // last week; an automatic budget with no model window), which used to send them to adv_legacy with a
        // notice on every tree build.
        set_config('prompt_truncation_seen', time() - HOURSECS, self::PLUGIN);
        \local_ai_course_assistant\tests\prompt_metrics_seed::turns(30, 5);
        set_config('prompt_budget_mode', 'auto', self::PLUGIN);
        set_config('model', '', self::PLUGIN);
        set_config('backend_context_tokens', 0, self::PLUGIN);

        $report = settings_usage::report();
        $this->assertDebuggingNotCalled('building the tree for the report raised a developer notice');
        $this->assertSame([], settings_layout::last_report()['unplaced']);
        $this->assertArrayNotHasKey('prompt_truncation_warning', $report['settings'], 'a notice stores no value');
    }

    public function test_an_undecryptable_secret_raises_no_notice_and_the_json_stays_valid(): void {
        global $CFG;
        // A database restored onto another site: ciphertext this site's key can't open. secrets::reveal() treats
        // it as unset and raises a developer debugging() notice, which a CLI run would echo into --json output.
        $CFG->debug = DEBUG_DEVELOPER;
        $CFG->debugdisplay = 1;
        $planted = 'sodium:' . base64_encode(str_repeat('undecryptable-sentinel-', 4));
        set_config(self::SECRET_SETTING, $planted, self::PLUGIN);
        $this->assertTrue(secrets::is_encrypted($planted));

        ob_start();
        try {
            $report = settings_usage::report(true, true);
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } finally {
            $printed = ob_get_clean();
        }

        // Under phpunit a debugging() call is recorded instead of printed, so the call itself is the thing to refuse.
        $this->assertDebuggingNotCalled('report() let a decryption notice through');
        $this->assertSame('', $printed, 'report() wrote to the output');
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'the JSON does not decode');
        $this->assertSame(['settings', 'summary', 'percourse', 'tables'], array_keys($decoded));
        $this->assertSame('secret unset', $decoded['settings'][self::SECRET_SETTING],
            'an undecryptable secret reads as unset, as it does everywhere else in SOLA');
        $this->assertStringNotContainsString('could not be decrypted', $json);
        $this->assertStringNotContainsString('++', $json);
        $this->assert_nowhere($report, [$planted]);
    }

    public function test_debugging_is_back_as_it_was_after_the_report(): void {
        global $CFG;
        $CFG->debug = DEBUG_DEVELOPER;
        $CFG->debugusers = '2';
        settings_usage::report();
        $this->assertSame(DEBUG_DEVELOPER, (int) $CFG->debug);
        $this->assertSame('2', $CFG->debugusers);
        // And a notice raised afterwards is still raised.
        debugging('after the report', DEBUG_DEVELOPER);
        $this->assertDebuggingCalled('after the report');
    }

    /**
     * Whether a token is a function or method call with one of the given names.
     *
     * @param array $tokens token_get_all() output without whitespace and comments.
     * @param int $i Index of the token to test.
     * @param string[] $names Lower-case function names.
     * @return bool
     */
    private static function is_call_to(array $tokens, int $i, array $names): bool {
        return is_array($tokens[$i]) && $tokens[$i][0] === T_STRING
            && in_array(strtolower($tokens[$i][1]), $names, true)
            && ($tokens[$i + 1] ?? null) === '(';
    }

    public function test_the_cli_script_writes_output_only_through_the_report_helpers(): void {
        $source = file_get_contents(__DIR__ . '/../admin/cli/settings_usage_report.php');
        $this->assertNotFalse($source);
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $tokens[] = $token;
        }
        $this->assertGreaterThan(50, count($tokens), 'the script was not read');

        $banned = ['var_dump', 'print_r', 'var_export', 'printf', 'vprintf', 'fprintf', 'vfprintf', 'debug_zval_dump',
            'fwrite', 'fputs', 'file_put_contents', 'error_log', 'mtrace', 'debugging', 'print_object', 'var_dump'];
        $clihelpers = ['cli_get_params', 'cli_error', 'cli_writeln', 'cli_heading', 'cli_separator'];
        $echoes = 0;
        foreach ($tokens as $i => $token) {
            $kind = is_array($token) ? $token[0] : null;
            $this->assertNotSame(T_INLINE_HTML, $kind, 'text outside the php tags would be printed');
            $this->assertNotSame(T_OPEN_TAG_WITH_ECHO, $kind, 'a short echo tag prints');
            $this->assertNotSame(T_PRINT, $kind, 'print writes output');
            $this->assertFalse(self::is_call_to($tokens, $i, $banned), 'the script calls ' . ($token[1] ?? '?'));
            if (is_array($token) && $token[0] === T_STRING && strncmp($token[1], 'cli_', 4) === 0
                    && ($tokens[$i + 1] ?? null) === '(') {
                $this->assertContains($token[1], $clihelpers, "{$token[1]}() is not a known Moodle CLI helper");
            }
            if ($kind === T_EXIT) {
                // exit('text') and die('text') print the text; exit(0) and a bare exit do not.
                $arg = $tokens[$i + 1] ?? null;
                if ($arg === '(') {
                    $this->assertSame(T_LNUMBER, $tokens[$i + 2][0] ?? null, 'exit() with something other than a status code');
                }
            }
            if ($kind === T_ECHO) {
                $echoes++;
                $statement = '';
                for ($j = $i + 1; $tokens[$j] !== ';'; $j++) {
                    $statement .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                }
                $this->assertMatchesRegularExpression(
                    '~^(json_encode\(\$report,[A-Z_|]+\),"\\\\n"|settings_usage::render_text\(\$report\))$~',
                    str_replace(' ', '', $statement),
                    "echo $statement prints something other than the report"
                );
            }
        }
        $this->assertSame(2, $echoes, 'the script prints the report as JSON or as text, and nothing else');
    }
}
