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
 * Post-deploy health checks for SOLA (v5.4.2+).
 *
 * Runs immediately after every plugin upgrade to verify the install
 * actually succeeded. Each check is independent and returns a
 * pass/fail/warn result with a one-line message. The CLI wrapper at
 * admin/cli/health_check.php mtraces the output and exits 0 on full
 * pass, 1 on any fail.
 *
 * Designed for partner ops teams (e.g. Catalyst) to wire into their
 * deploy procedure as the last step — anything red is a known-broken
 * upgrade that should be rolled back before traffic resumes.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class health_check {

    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';
    public const STATUS_WARN = 'warn';

    /**
     * Run every check and return a summary.
     *
     * @return array{checks:array<int,array{name:string,status:string,message:string}>,
     *               passed:int, failed:int, warned:int}
     */
    public static function run_all(): array {
        $checks = [
            self::check_plugin_version_matches_savepoints(),
            self::check_all_scheduled_tasks_registered(),
            self::check_lang_strings_load(),
            self::check_privacy_provider_metadata(),
            self::check_no_cron_tasks_stuck(),
            self::check_audit_log_writable(),
            self::check_no_falsy_default_get_config_pattern(),
            self::check_provider_resolves_when_enabled(),
        ];

        $passed = $failed = $warned = 0;
        foreach ($checks as $c) {
            switch ($c['status']) {
                case self::STATUS_PASS: $passed++; break;
                case self::STATUS_FAIL: $failed++; break;
                case self::STATUS_WARN: $warned++; break;
            }
        }

        return [
            'checks' => $checks,
            'passed' => $passed,
            'failed' => $failed,
            'warned' => $warned,
        ];
    }

    /**
     * 1. The version stored in mdl_config_plugins for our plugin must match
     *    or exceed the latest savepoint declared in db/upgrade.php. A
     *    half-applied upgrade (cli/install.php interrupted, db/upgrade.php
     *    threw mid-block) leaves the version row behind the savepoint —
     *    this catches it.
     */
    public static function check_plugin_version_matches_savepoints(): array {
        $name = 'plugin_version_vs_db_savepoints';
        global $CFG;
        $versionfile = $CFG->dirroot . '/local/ai_course_assistant/version.php';
        if (!file_exists($versionfile)) {
            return self::fail($name, 'version.php missing — plugin not installed.');
        }
        $plugin = new \stdClass();
        include $versionfile;
        $declared = (int) ($plugin->version ?? 0);

        $stored = (int) get_config('local_ai_course_assistant', 'version');
        if ($stored === 0) {
            return self::fail($name, 'No version row in config_plugins for local_ai_course_assistant '
                . '— the install/upgrade never completed.');
        }
        if ($stored < $declared) {
            return self::fail($name, "DB version {$stored} is behind plugin version.php {$declared}. "
                . 'A db/upgrade.php savepoint did not commit. Re-run upgrade.');
        }
        return self::pass($name, "DB version {$stored} matches/exceeds plugin {$declared}.");
    }

    /**
     * 2. Every classes/task/*.php must be registered in mdl_task_scheduled.
     *    A missing row means the task class exists but cron will never
     *    fire it — silent failure mode.
     */
    public static function check_all_scheduled_tasks_registered(): array {
        $name = 'scheduled_tasks_registered';
        global $CFG, $DB;

        $taskdir = $CFG->dirroot . '/local/ai_course_assistant/classes/task';
        if (!is_dir($taskdir)) {
            return self::fail($name, 'classes/task directory missing.');
        }

        $expected = [];
        foreach (glob($taskdir . '/*.php') as $f) {
            $base = basename($f, '.php');
            // Adhoc tasks (queued from request) are not in mdl_task_scheduled.
            // Identify them by the parent class. Cheap: read first 80 lines.
            $head = file_get_contents($f, false, null, 0, 4096);
            if (strpos($head, 'extends \\core\\task\\adhoc_task') !== false
                || strpos($head, 'extends adhoc_task') !== false) {
                continue;
            }
            $expected[] = '\\local_ai_course_assistant\\task\\' . $base;
        }

        $missing = [];
        foreach ($expected as $cls) {
            if (!$DB->record_exists('task_scheduled', ['classname' => $cls])) {
                $missing[] = $cls;
            }
        }

        if ($missing) {
            return self::fail($name, count($missing) . ' scheduled task(s) not registered: '
                . implode(', ', array_map(static fn($c) => substr($c, strrpos($c, '\\') + 1), $missing)));
        }

        return self::pass($name, count($expected) . ' scheduled tasks registered.');
    }

    /**
     * 3. Sample several known lang string keys and verify none come back
     *    as the literal `[[key]]` placeholder Moodle returns on lookup
     *    failure. A missing string ships to the learner as visible
     *    placeholder text.
     */
    public static function check_lang_strings_load(): array {
        $name = 'lang_strings_load';
        // Representative sample across the major string families.
        $samplekeys = [
            'pluginname',
            'chat:placeholder',
            'chat:send',
            'chat:close',
            'task:audit_cleanup',
            'task:send_reminders',
            'usersettings:delete_all_button',
            'usersettings:data_deleted',
            'settings:mastery_threshold',
            'reminder:study_tip_prefix',
        ];
        $missing = [];
        foreach ($samplekeys as $k) {
            $s = get_string($k, 'local_ai_course_assistant');
            if ($s === '' || strpos($s, '[[' . $k . ']]') !== false) {
                $missing[] = $k;
            }
        }
        if ($missing) {
            return self::fail($name, 'Missing/empty lang strings: ' . implode(', ', $missing));
        }
        return self::pass($name, count($samplekeys) . ' representative lang strings loaded cleanly.');
    }

    /**
     * 4. Privacy provider must declare metadata. A blank metadata row means
     *    no Article 15 (export) or Article 17 (delete) coverage of the
     *    plugin's tables — GDPR compliance gap.
     */
    public static function check_privacy_provider_metadata(): array {
        $name = 'privacy_provider_metadata';
        $providerclass = '\\local_ai_course_assistant\\privacy\\provider';
        if (!class_exists($providerclass)) {
            return self::fail($name, 'privacy/provider class is missing.');
        }
        try {
            $collection = new \core_privacy\local\metadata\collection('local_ai_course_assistant');
            $providerclass::get_metadata($collection);
            $items = $collection->get_collection();
            if (empty($items)) {
                return self::fail($name, 'privacy provider returned an empty metadata collection.');
            }
            $tables = 0;
            foreach ($items as $item) {
                if ($item instanceof \core_privacy\local\metadata\types\database_table) {
                    $tables++;
                }
            }
            if ($tables === 0) {
                return self::fail($name, 'privacy provider declares no database_table items '
                    . '(GDPR Articles 15 and 17 will silently omit SOLA tables).');
            }
            return self::pass($name, "privacy provider declares {$tables} database_table item(s).");
        } catch (\Throwable $e) {
            return self::fail($name, 'privacy provider get_metadata threw: ' . $e->getMessage());
        }
    }

    /**
     * 5. None of the plugin's scheduled tasks may have a `faildelay` over
     *    1 hour. The exact failure mode that surfaced on degrees.saylor.org
     *    with enrol_programs cron — instant-fail loops grow the backoff
     *    until the maxfaildelay alert fires days later.
     */
    public static function check_no_cron_tasks_stuck(): array {
        $name = 'no_cron_tasks_stuck';
        global $DB;
        $rows = $DB->get_records_select('task_scheduled',
            $DB->sql_like('classname', ':pattern'),
            ['pattern' => '%local_ai_course_assistant\\\\task\\\\%']);
        $stuck = [];
        foreach ($rows as $row) {
            if ((int) $row->faildelay > 3600) {
                $short = substr($row->classname, strrpos($row->classname, '\\') + 1);
                $stuck[] = "{$short} (faildelay=" . (int) $row->faildelay . 's)';
            }
        }
        if ($stuck) {
            return self::fail($name, 'Stuck SOLA cron tasks: ' . implode(', ', $stuck)
                . '. Run them by hand with admin/cli/scheduled_task.php --execute=<class> '
                . 'and capture the exception.');
        }
        return self::pass($name, count($rows) . ' SOLA scheduled tasks healthy (no faildelay over 1h).');
    }

    /**
     * 6. Insert + select round-trip on the audit table. If audit is broken,
     *    the security/compliance trail breaks silently — the SOC2 control
     *    we depend on for incident review.
     */
    public static function check_audit_log_writable(): array {
        $name = 'audit_log_writable';
        global $DB;
        $marker = 'health_check_' . bin2hex(random_bytes(8));
        try {
            $id = $DB->insert_record('local_ai_course_assistant_audit', (object)[
                'action' => 'health_check_probe', 'userid' => 0, 'courseid' => 0,
                'ipaddress' => '', 'useragent' => '',
                'details' => json_encode(['marker' => $marker]),
                'timecreated' => time(),
            ]);
            $row = $DB->get_record('local_ai_course_assistant_audit', ['id' => $id]);
            if (!$row || strpos((string) $row->details, $marker) === false) {
                return self::fail($name, 'audit row inserted but read-back failed.');
            }
            $DB->delete_records('local_ai_course_assistant_audit', ['id' => $id]);
            return self::pass($name, 'audit table insert + select + delete round-trip OK.');
        } catch (\Throwable $e) {
            return self::fail($name, 'audit table is not writable: ' . $e->getMessage());
        }
    }

    /**
     * 7. Re-run the v5.3.33 static-analysis test for the `?:` numeric
     *    fall-through pattern at runtime. Catches a hand-edit on the
     *    deployed server (rare but possible) that PHPUnit hasn't seen.
     */
    public static function check_no_falsy_default_get_config_pattern(): array {
        $name = 'no_falsy_default_pattern';
        global $CFG;
        $rootdir = $CFG->dirroot . '/local/ai_course_assistant/classes';
        if (!is_dir($rootdir)) {
            return self::fail($name, 'classes directory missing — plugin is not installed.');
        }
        $offenders = [];
        $defaults = [
            '[1-9][0-9]*(?:\\.[0-9]+)?',
            '(?:self|static|[A-Z][A-Za-z0-9_]+)::[A-Z][A-Z0-9_]+',
        ];
        $regex = "/\\((int|float)\\)\\s*\\(\\s*get_config\\s*\\(\\s*'local_ai_course_assistant'.*?\\?:\\s*("
            . implode('|', $defaults) . ")/";
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($rootdir));
        foreach ($rii as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }
            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
            foreach ($lines as $i => $line) {
                if (preg_match($regex, $line)) {
                    $offenders[] = str_replace($rootdir . '/', '', $file->getPathname()) . ':' . ($i + 1);
                    if (count($offenders) >= 5) {
                        break 2;
                    }
                }
            }
        }
        if ($offenders) {
            return self::fail($name, 'Found `?: <numeric|::CONST>` fall-through patterns: '
                . implode(', ', $offenders) . '. See v5.3.33 release notes for the fix pattern.');
        }
        return self::pass($name, 'No falsy-default `?:` patterns in classes/.');
    }

    /**
     * 8. If the plugin is enabled AND a provider is configured, the
     *    base_provider factory must instantiate without throwing. Catches
     *    a deploy where the admin set provider= to an unknown id, or where
     *    the provider class file is missing post-upgrade.
     */
    public static function check_provider_resolves_when_enabled(): array {
        $name = 'provider_resolves_when_enabled';
        if (!get_config('local_ai_course_assistant', 'enabled')) {
            return self::pass($name, 'Plugin is not enabled — provider check skipped.');
        }
        $providerid = (string) get_config('local_ai_course_assistant', 'provider');
        if ($providerid === '') {
            return [
                'name' => $name, 'status' => self::STATUS_WARN,
                'message' => 'Plugin is enabled but no provider is configured. Set one in plugin settings.',
            ];
        }
        try {
            $instance = \local_ai_course_assistant\provider\base_provider::create_from_config(0);
            if (!is_object($instance)) {
                return self::fail($name, "provider factory returned non-object for provider={$providerid}.");
            }
            return self::pass($name, "provider={$providerid} resolves cleanly.");
        } catch (\Throwable $e) {
            return self::fail($name,
                "provider={$providerid} factory threw: " . $e->getMessage());
        }
    }

    private static function pass(string $name, string $message): array {
        return ['name' => $name, 'status' => self::STATUS_PASS, 'message' => $message];
    }

    private static function fail(string $name, string $message): array {
        return ['name' => $name, 'status' => self::STATUS_FAIL, 'message' => $message];
    }
}
