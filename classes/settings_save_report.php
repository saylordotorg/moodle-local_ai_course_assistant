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
 * Tells an administrator exactly which settings a save changed.
 *
 * The all-settings page (admin/category.php?category=local_ai_course_assistant)
 * puts every setting in one form, and Moodle writes back every field that
 * differs from the stored value. On 2026-10-07 one such save flipped the AI
 * provider from gemini to auto next to an unrelated checkbox, and nothing on
 * the screen said so. Moodle's "Changes saved" banner names no setting.
 *
 * config_log_created events are collected into the session as they fire, then
 * shown as one notice on the next admin page. Credentials are never shown.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_save_report {

    /** @var string Session key for the pending changes. */
    private const SESSIONKEY = 'local_ai_course_assistant_savedchanges';

    /** @var int Changes above this count get the "check this list" advice. */
    public const MANY = 8;

    /** @var int Most changes listed; a fresh install saves hundreds at once. */
    public const LISTED = 30;

    /** @var int Longest value shown, in characters. */
    private const SHOWN = 48;

    /** @var string[] Settings whose change is worth a warning on its own. */
    private const KEY_SETTINGS = ['provider', 'model', 'apikey', 'comparison_providers', 'failover_per_call_enabled',
        'support_enabled'];

    /** @var ?bool Tests set this; the PHPUnit runner is a CLI request. */
    private static ?bool $assumeweb = null;

    /**
     * Treat the request as a web request (true), or restore detection (null). For tests.
     *
     * @param ?bool $web
     */
    public static function assume_web(?bool $web): void {
        self::$assumeweb = $web;
    }

    /**
     * Only a person saving in the admin UI should see or leave a notice.
     *
     * @return bool
     */
    private static function is_web_request(): bool {
        return self::$assumeweb ?? !(CLI_SCRIPT || (defined('AJAX_SCRIPT') && AJAX_SCRIPT));
    }

    /**
     * Observer for config_log_created: remember one of our settings changing.
     *
     * @param \core\event\config_log_created $event
     */
    public static function observe(\core\event\config_log_created $event): void {
        global $SESSION;
        if (!self::is_web_request() || empty($SESSION)) {
            return;
        }
        $other = $event->other;
        if (($other['plugin'] ?? '') !== 'local_ai_course_assistant' || empty($other['name'])) {
            return;
        }
        $name = (string) $other['name'];
        $pending = $SESSION->{self::SESSIONKEY} ?? [];
        // Keep the first old value and the last new one if a name repeats.
        $old = array_key_exists($name, $pending) ? $pending[$name][0] : ($other['oldvalue'] ?? null);
        $pending[$name] = [$old, $other['value'] ?? null];
        $SESSION->{self::SESSIONKEY} = $pending;
    }

    /**
     * Show and clear whatever the last save left behind. Safe to call anywhere.
     */
    public static function flush(): void {
        global $SESSION;
        if (!self::is_web_request() || empty($SESSION->{self::SESSIONKEY})) {
            return;
        }
        $pending = $SESSION->{self::SESSIONKEY};
        unset($SESSION->{self::SESSIONKEY});
        // v7.8.8: a save that touched the backup providers gets their settings
        // problems shown right away, not discovered on the day a failover needs them.
        $backupsettings = ['comparison_providers' => 1, 'spend_failover_chain' => 1, 'failover_per_call_enabled' => 1];
        if (array_intersect_key($pending, $backupsettings)) {
            $problems = failover_verifier::static_problems();
            if ($problems) {
                \core\notification::add(
                    get_string('verify:saved_warning', 'local_ai_course_assistant') . ' ' . implode(' ', array_map('s', $problems)),
                    \core\notification::WARNING
                );
            }
        }
        self::emit_default_provider_warnings($pending);
        $report = self::describe($pending);
        if ($report['changed'] === 0) {
            return;
        }
        \core\notification::add(
            $report['message'],
            $report['warn'] ? \core\notification::WARNING : \core\notification::INFO
        );
    }

    /**
     * Warn when a save may have left the default provider without a key.
     *
     * Raised once per flush, and only when the save touched one of the four settings that decide which key
     * chat uses (the default provider, its model, the gateway URL, or the provider table). The words come
     * from provider_keys::default_provider_warnings(), which reads the settings as they are now. Moodle runs
     * a setting's updated-callback while the save is still half done, so the warnings are read here instead,
     * on the next page, when every value is final.
     *
     * @param array $pending Setting name => [old value, new value].
     * @return int how many warnings were raised
     */
    public static function emit_default_provider_warnings(array $pending): int {
        $names = ['provider', 'model', 'apibaseurl', 'comparison_providers'];
        if (!array_intersect_key($pending, array_flip($names))) {
            return 0;
        }
        $warnings = provider_keys::default_provider_warnings();
        foreach ($warnings as $warning) {
            \core\notification::warning($warning);
        }
        return count($warnings);
    }

    /**
     * Turn pending changes into a message.
     *
     * @param array $changes Setting name => [old value, new value].
     * @return array{changed: int, warn: bool, message: string}
     */
    public static function describe(array $changes): array {
        $lines = [];
        $warn = false;
        $total = count($changes);
        foreach ($changes as $name => [$old, $new]) {
            $name = (string) $name;
            if (in_array($name, self::KEY_SETTINGS, true)) {
                $warn = true;
            }
            if (count($lines) < self::LISTED) {
                $lines[] = '<code>' . s($name) . '</code> ' . self::change_text($name, $old, $new);
            }
        }
        if ($total > count($lines)) {
            $lines[] = '&hellip;';
        }
        $count = $total;
        if ($count === 0) {
            return ['changed' => 0, 'warn' => false, 'message' => ''];
        }
        $message = get_string('savereport:summary', 'local_ai_course_assistant', $count)
            . '<ul><li>' . implode('</li><li>', $lines) . '</li></ul>';
        if ($count > self::MANY) {
            $warn = true;
            $message .= get_string('savereport:many', 'local_ai_course_assistant', $count);
        }
        return ['changed' => $count, 'warn' => $warn, 'message' => $message];
    }

    /**
     * One change, as text: old to new, with credentials and line-ending noise handled.
     *
     * @param string $name
     * @param ?string $old
     * @param ?string $new
     * @return string HTML
     */
    private static function change_text(string $name, ?string $old, ?string $new): string {
        if (config_log_audit::is_secret_name($name)) {
            return get_string('savereport:secret', 'local_ai_course_assistant');
        }
        $norm = static fn(?string $v): string => trim(str_replace("\r\n", "\n", (string) $v));
        if ($norm($old) === $norm($new)) {
            // The provider table reaches the log with every key blanked, so equal text there means only a
            // key changed, unless the old value had Windows line endings.
            if ($name === provider_keys::SETTING && strpos((string) $old, "\r") === false) {
                return get_string('savereport:keychanged', 'local_ai_course_assistant');
            }
            return get_string('savereport:lineendings', 'local_ai_course_assistant');
        }
        return self::show($old) . ' &rarr; ' . self::show($new);
    }

    /**
     * A value for display: escaped, one line, shortened.
     *
     * @param ?string $value
     * @return string HTML
     */
    private static function show(?string $value): string {
        $value = trim((string) preg_replace('/\s+/', ' ', (string) $value));
        if ($value === '') {
            return '<em>' . get_string('savereport:empty', 'local_ai_course_assistant') . '</em>';
        }
        return '<strong>' . s(\core_text::substr($value, 0, self::SHOWN)
            . (\core_text::strlen($value) > self::SHOWN ? '...' : '')) . '</strong>';
    }
}
