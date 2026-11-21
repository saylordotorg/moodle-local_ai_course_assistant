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

use local_ai_course_assistant\autoupgrade\discovery;
use local_ai_course_assistant\autoupgrade\notifier;

/**
 * Keeps the voice models in use ahead of provider shutdowns (8.1.0).
 *
 * Daily (task voice_model_watch) it works out which voice models this site calls, warns about any whose
 * announced shutdown is WARN_DAYS or fewer away, and asks each provider it holds a key for (the model list the
 * chat discovery already reads, a GET with the key in a header) whether the catalog models in use are still
 * offered. Findings go to the spend alert recipients through the model upgrade notifier, each finding once.
 *
 * It never changes a setting. What the site calls after a shutdown is decided at call time by
 * voice_model_catalog::resolve_model(), which only ever follows the catalog's declared replaced_by.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class voice_model_watch {
    /** @var int Warn this many days before a shutdown. */
    public const WARN_DAYS = 45;

    /** @var string[] Providers whose model list is read. xAI's list doesn't name its voice models. */
    public const LISTED_PROVIDERS = ['gemini', 'openai'];

    /** @var string Prefix of the once-per-finding flags. */
    private const FLAG = 'voice_model_watch_';

    /**
     * The voice models this site calls, as [provider, capability, model] (model as configured, catalog spelling).
     *
     * @param array[]|null $rows voice_registry::parse_rows() rows; null reads the site's table
     * @return array[]
     */
    public static function models_in_use(?array $rows = null): array {
        $legacy = $rows === null;
        $rows = $rows ?? voice_registry::parse_rows(false);
        $out = [];
        foreach ($rows as $row) {
            $provider = strtolower((string) ($row['provider'] ?? ''));
            if (!voice_model_catalog::has_provider($provider)) {
                continue;
            }
            if (voice_registry::supports($provider, voice_model_catalog::TTS)) {
                $model = voice_model_catalog::normalise((string) ($row['tts_model'] ?? ''));
                $model = $model !== '' ? $model : voice_model_catalog::default_model($provider, voice_model_catalog::TTS);
                if ($model !== '') {
                    $out[] = [$provider, voice_model_catalog::TTS, $model];
                }
            }
            // 8.2.0: the row's own Realtime model (and, for Gemini Live, the gemini_live_model setting) before the
            // catalog default, as voice_registry::model_for() reads them.
            $rt = voice_model_catalog::normalise(voice_registry::row_model($row, voice_model_catalog::REALTIME));
            if ($rt === '' && $provider === 'gemini') {
                $rt = voice_model_catalog::normalise((string) get_config('local_ai_course_assistant', 'gemini_live_model'));
            }
            $rt = $rt !== '' ? $rt : voice_model_catalog::default_model($provider, voice_model_catalog::REALTIME);
            if ($rt !== '' && voice_registry::supports($provider, voice_model_catalog::REALTIME)) {
                $out[] = [$provider, voice_model_catalog::REALTIME, $rt];
            }
        }
        // The single-key fallback is OpenAI, on the catalog defaults.
        if ($legacy && $rows === [] && voice_registry::is_configured()) {
            foreach ([voice_model_catalog::TTS, voice_model_catalog::REALTIME] as $cap) {
                $out[] = ['openai', $cap, voice_model_catalog::default_model('openai', $cap)];
            }
        }
        $unique = [];
        foreach ($out as $item) {
            $unique[implode('|', $item)] = $item;
        }
        return array_values($unique);
    }

    /**
     * What is worth telling an admin, from the models in use and the providers' lists. Pure.
     *
     * @param array[] $inuse From models_in_use().
     * @param array $listed provider => string[] of model ids it lists; a provider absent here wasn't read
     * @param int $now
     * @return array{warnings: array[], missing: array[]}
     */
    public static function check(array $inuse, array $listed, int $now): array {
        $warnings = voice_model_catalog::retiring_within(self::WARN_DAYS, $now, $inuse);
        $missing = [];
        foreach ($inuse as [$provider, $capability, $model]) {
            if (!isset($listed[$provider])) {
                continue;
            }
            $offered = array_map([voice_model_catalog::class, 'normalise'], $listed[$provider]);
            // The model as configured, what the site calls now, and, for a deprecated model, what it will call
            // after the shutdown date (the declared successor), so a successor that vanished is found in time.
            $targets = [$model, voice_model_catalog::resolve_model($provider, $capability, $model, $now)];
            $entry = voice_model_catalog::entry($provider, $capability, $model);
            $at = $entry === null ? null : voice_model_catalog::shutdown_time($entry);
            if ($at !== null) {
                $targets[] = voice_model_catalog::resolve_model($provider, $capability, $model, max($now, $at));
            }
            $targets = array_unique($targets);
            foreach ($targets as $target) {
                if (voice_model_catalog::entry($provider, $capability, $target) === null
                        || voice_model_catalog::is_retired($provider, $capability, $target, $now)
                        || in_array($target, $offered, true)) {
                    continue;
                }
                $missing[$provider . '|' . $capability . '|' . $target] = [
                    'provider' => $provider,
                    'capability' => $capability,
                    'model' => $target,
                    'configured' => $target === $model,
                ];
            }
        }
        return ['warnings' => $warnings, 'missing' => array_values($missing)];
    }

    /**
     * Read each provider's model list. Keys stay inside this method and discovery::list_models().
     *
     * @param array[] $inuse
     * @param callable|null $http Replaces the network for tests, as in discovery.
     * @param array|null $credentials provider => [apikey, baseurl]; null reads the site's keys
     * @return array{listed: array<string, string[]>, errors: array<string, string>}
     */
    public static function list_provider_models(array $inuse, ?callable $http = null, ?array $credentials = null): array {
        $providers = array_values(array_intersect(self::LISTED_PROVIDERS, array_unique(array_column($inuse, 0))));
        $creds = $credentials ?? self::credentials($providers);
        $listed = [];
        $errors = [];
        $discovery = new discovery($http);
        foreach ($providers as $provider) {
            if (empty($creds[$provider]['apikey']) || trim((string) ($creds[$provider]['baseurl'] ?? '')) !== '') {
                // No key, or a key for a gateway whose list says nothing about the provider's own models.
                continue;
            }
            try {
                $listed[$provider] = $discovery->list_models($provider, $creds[$provider], true);
                if ($listed[$provider] === []) {
                    // An empty list proves nothing; don't report every model as missing.
                    unset($listed[$provider]);
                    $errors[$provider] = 'empty model list';
                }
            } catch (\Throwable $e) {
                $errors[$provider] = \core_text::substr(security::redact_secrets($e->getMessage()), 0, 200);
            }
        }
        return ['listed' => $listed, 'errors' => $errors];
    }

    /**
     * Keys for listing: the chat discovery's (provider rows, then the site key), then a voice row's own key.
     *
     * @param string[] $providers
     * @return array<string, array{apikey: string, baseurl: string}>
     */
    private static function credentials(array $providers): array {
        $creds = array_intersect_key(discovery::credentials(), array_flip($providers));
        foreach (voice_registry::parse_rows(true) as $row) {
            $p = strtolower($row['provider']);
            if (in_array($p, $providers, true) && empty($creds[$p]['apikey']) && $row['apikey'] !== '') {
                $creds[$p] = ['apikey' => $row['apikey'], 'baseurl' => ''];
            }
        }
        return $creds;
    }

    /**
     * Email the findings not reported before, one email for all of them.
     *
     * @param array $result From check().
     * @param int $now
     * @return int Findings newly reported.
     */
    public static function notify_new(array $result, int $now): int {
        $lines = [];
        $flags = [];
        foreach ($result['warnings'] as $w) {
            $flag = self::FLAG . substr(sha1('warn|' . $w['provider'] . '|' . $w['capability'] . '|' . $w['model']
                . '|' . $w['shutdown'] . '|' . (int) $w['retired']), 0, 16);
            if (get_config('local_ai_course_assistant', $flag)) {
                continue;
            }
            $a = (object) ['provider' => $w['provider'], 'model' => $w['model'], 'date' => $w['shutdown'],
                'days' => max(0, $w['days_left']), 'to' => $w['resolves_to'] !== '' ? $w['resolves_to'] : '-'];
            $lines[] = '- ' . get_string($w['retired'] ? 'voicemodels:line_retired' : 'voicemodels:line_retiring',
                'local_ai_course_assistant', $a);
            $flags[] = $flag;
        }
        foreach ($result['missing'] as $m) {
            $flag = self::FLAG . substr(sha1('missing|' . $m['provider'] . '|' . $m['capability'] . '|' . $m['model']), 0, 16);
            if (get_config('local_ai_course_assistant', $flag)) {
                continue;
            }
            $lines[] = '- ' . get_string('voicemodels:line_missing', 'local_ai_course_assistant',
                (object) ['provider' => $m['provider'], 'model' => $m['model']]);
            $flags[] = $flag;
        }
        if ($lines === []) {
            return 0;
        }
        notifier::voice_model_report(implode("\n", $lines));
        foreach ($flags as $flag) {
            set_config($flag, (string) $now, 'local_ai_course_assistant');
        }
        return count($lines);
    }

    /**
     * One full run: models in use, provider lists, findings, email.
     *
     * @param int|null $now
     * @param callable|null $http
     * @param array|null $credentials
     * @return array{inuse: array, warnings: array, missing: array, errors: array, reported: int}
     */
    public static function run(?int $now = null, ?callable $http = null, ?array $credentials = null): array {
        $now = $now ?? time();
        $inuse = self::models_in_use();
        $lists = $inuse === [] ? ['listed' => [], 'errors' => []] : self::list_provider_models($inuse, $http, $credentials);
        $result = self::check($inuse, $lists['listed'], $now);
        $reported = self::notify_new($result, $now);
        return [
            'inuse' => $inuse,
            'warnings' => $result['warnings'],
            'missing' => $result['missing'],
            'errors' => $lists['errors'],
            'reported' => $reported,
        ];
    }

    /**
     * Record a call that the provider refused as an unknown model and that was retried on the catalog successor.
     *
     * @param string $provider
     * @param string $capability
     * @param string $from
     * @param string $to
     * @param bool $worked
     * @param int $userid
     * @return void
     */
    public static function record_fallback(string $provider, string $capability, string $from, string $to,
            bool $worked, int $userid): void {
        audit_logger::log('voice_model_fallback', $userid, 0, [
            'provider' => $provider,
            'capability' => $capability,
            'from' => $from,
            'to' => $to,
            'outcome' => $worked ? 'rescued' : 'failed',
        ]);
        debugging("SOLA voice: {$provider} refused model {$from} as unknown; retried on {$to} ("
            . ($worked ? 'worked' : 'failed') . ').', DEBUG_DEVELOPER);
        try {
            notifier::voice_model_fallback($provider, $from, $to, $worked);
        } catch (\Throwable $e) {
            debugging('Voice model fallback email failed: ' . get_class($e), DEBUG_DEVELOPER);
        }
    }
}
