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
 * The one list of voice models SOLA knows: which model each provider uses by default for text to speech,
 * Realtime (Live) and speech to text, which models are current, and which are being retired, when, and by what.
 *
 * Everything that picks a voice model asks resolve_model() at call time, so a model the provider has shut down
 * never reaches it: once a deprecated model's shutdown date (a UTC date) has passed, the call goes to the model
 * its replaced_by names, following the chain while each step is itself retired. A model this list doesn't know
 * is passed through untouched (an admin may name a model newer than this file), and an empty choice means the
 * provider's default.
 *
 * Nothing here ever switches to a model that isn't in this list. The voice_model_watch task only warns.
 *
 * The 2026-11 Gemini entries come from Google's deprecation notice as relayed by the site owner and weren't
 * independently verified. They're data in MODELS below and nowhere else, so a wrong id or date is a one-line fix.
 *
 * Pure functions with an injectable "now", so the tests don't depend on today's date.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class voice_model_catalog {
    /** @var string Text to speech. Same value as voice_registry::CAPABILITY_TTS. */
    public const TTS = 'tts';

    /** @var string Bidirectional audio (OpenAI Realtime, Gemini Live). Same as voice_registry::CAPABILITY_REALTIME. */
    public const REALTIME = 'realtime';

    /** @var string Speech to text. Same as voice_registry::CAPABILITY_STT. */
    public const STT = 'stt';

    /** @var string A model that is fine to call. */
    public const CURRENT = 'current';

    /** @var string A model with an announced shutdown. Still called until its shutdown date. */
    public const DEPRECATED = 'deprecated';

    /** @var int Steps followed along a replaced_by chain before giving up on it. */
    private const MAX_CHAIN = 10;

    /** @var array|null Replacement data for unit tests (chains, loops); null uses MODELS. */
    private static ?array $testdata = null;

    /**
     * provider => capability => ['default' => model, 'models' => [model => entry]].
     *
     * An entry is ['status' => current|deprecated, 'shutdown' => 'YYYY-MM-DD' (UTC) or null,
     * 'replaced_by' => model or null]. Ids are lowercase.
     *
     * @var array<string, array<string, array{default: string, models: array<string, array>}>>
     */
    public const MODELS = [
        'gemini' => [
            self::TTS => [
                'default' => 'gemini-3.8-flash-tts',
                'models' => [
                    // Flagship. Replacement named by Google's 2026-11 notice.
                    'gemini-3.8-flash-tts' => ['status' => self::CURRENT, 'shutdown' => null, 'replaced_by' => null],
                    // Lowest latency and cheapest, per the same notice.
                    'gemini-3.8-flash-lite-tts' => ['status' => self::CURRENT, 'shutdown' => null, 'replaced_by' => null],
                    'gemini-3.1-flash-tts-preview' => ['status' => self::DEPRECATED, 'shutdown' => '2026-12-18',
                        'replaced_by' => 'gemini-3.8-flash-tts'],
                    'gemini-2.5-flash-preview-tts' => ['status' => self::DEPRECATED, 'shutdown' => '2026-12-18',
                        'replaced_by' => 'gemini-3.8-flash-tts'],
                    'gemini-2.5-pro-preview-tts' => ['status' => self::DEPRECATED, 'shutdown' => '2026-12-18',
                        'replaced_by' => 'gemini-3.8-flash-tts'],
                ],
            ],
            // Data only for now: SOLA has no Gemini Live client yet. Kept here so the watch task can warn and a
            // future Live client starts from the right model.
            self::REALTIME => [
                'default' => 'gemini-3.8-live',
                'models' => [
                    'gemini-3.8-live' => ['status' => self::CURRENT, 'shutdown' => null, 'replaced_by' => null],
                    'gemini-2.5-flash-native-audio-preview-12-2025' => ['status' => self::DEPRECATED,
                        'shutdown' => '2027-03-31', 'replaced_by' => 'gemini-3.8-live'],
                ],
            ],
        ],
        'openai' => [
            self::TTS => [
                'default' => 'tts-1',
                'models' => [
                    'tts-1' => ['status' => self::CURRENT, 'shutdown' => null, 'replaced_by' => null],
                    'tts-1-hd' => ['status' => self::CURRENT, 'shutdown' => null, 'replaced_by' => null],
                ],
            ],
            self::REALTIME => [
                'default' => 'gpt-realtime-mini',
                'models' => [
                    'gpt-realtime-mini' => ['status' => self::CURRENT, 'shutdown' => null, 'replaced_by' => null],
                ],
            ],
        ],
        'xai' => [
            self::TTS => [
                'default' => 'grok-tts',
                'models' => [
                    'grok-tts' => ['status' => self::CURRENT, 'shutdown' => null, 'replaced_by' => null],
                ],
            ],
        ],
    ];

    /**
     * Replace the catalog data in a unit test, or put MODELS back with null.
     *
     * @param array|null $data Same shape as MODELS.
     * @return void
     */
    public static function set_data_for_testing(?array $data): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('voice_model_catalog data can only be replaced in unit tests');
        }
        self::$testdata = $data;
    }

    /**
     * The catalog data in use.
     *
     * @return array
     */
    public static function data(): array {
        return self::$testdata ?? self::MODELS;
    }

    /**
     * Does the catalog list this provider?
     *
     * @param string $provider
     * @return bool
     */
    public static function has_provider(string $provider): bool {
        return isset(self::data()[strtolower($provider)]);
    }

    /**
     * A model id as the catalog spells it: trimmed, lowercase, no "models/" prefix.
     *
     * @param string $model
     * @return string
     */
    public static function normalise(string $model): string {
        $model = strtolower(trim($model));
        return str_starts_with($model, 'models/') ? substr($model, 7) : $model;
    }

    /**
     * The provider's default model for a capability, or '' when the catalog has none.
     *
     * @param string $provider
     * @param string $capability
     * @return string
     */
    public static function default_model(string $provider, string $capability): string {
        return (string) (self::data()[strtolower($provider)][$capability]['default'] ?? '');
    }

    /**
     * Every model the catalog lists for a provider and capability.
     *
     * @param string $provider
     * @param string $capability
     * @return array<string, array> model => entry
     */
    public static function models(string $provider, string $capability): array {
        return self::data()[strtolower($provider)][$capability]['models'] ?? [];
    }

    /**
     * One model's entry, or null when the catalog doesn't list it.
     *
     * @param string $provider
     * @param string $capability
     * @param string $model
     * @return array|null
     */
    public static function entry(string $provider, string $capability, string $model): ?array {
        return self::models($provider, $capability)[self::normalise($model)] ?? null;
    }

    /**
     * Is this model in the vetted list (listed and current)?
     *
     * @param string $provider
     * @param string $capability
     * @param string $model
     * @return bool
     */
    public static function is_vetted(string $provider, string $capability, string $model): bool {
        $entry = self::entry($provider, $capability, $model);
        return $entry !== null && $entry['status'] === self::CURRENT;
    }

    /**
     * The shutdown moment of an entry: 00:00 UTC on its shutdown date, or null when it has none.
     *
     * Midnight at the start of the day, so a model is never called on the day the provider turns it off.
     *
     * @param array $entry
     * @return int|null
     */
    public static function shutdown_time(array $entry): ?int {
        $date = (string) ($entry['shutdown'] ?? '');
        if (!preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $date, $m)) {
            return null;
        }
        return gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Has this model been shut down at $now?
     *
     * @param string $provider
     * @param string $capability
     * @param string $model
     * @param int $now
     * @return bool False for a model the catalog doesn't know.
     */
    public static function is_retired(string $provider, string $capability, string $model, int $now): bool {
        $entry = self::entry($provider, $capability, $model);
        if ($entry === null || $entry['status'] !== self::DEPRECATED) {
            return false;
        }
        $at = self::shutdown_time($entry);
        return $at !== null && $now >= $at;
    }

    /**
     * Follow replaced_by from $model to the first model that is not retired at $now.
     *
     * Null when the model has no declared successor, the chain leaves the catalog, or it loops.
     *
     * @param string $provider
     * @param string $capability
     * @param string $model
     * @param int $now
     * @return string|null
     */
    public static function successor(string $provider, string $capability, string $model, int $now): ?string {
        $seen = [self::normalise($model) => true];
        $current = self::normalise($model);
        for ($i = 0; $i < self::MAX_CHAIN; $i++) {
            $entry = self::entry($provider, $capability, $current);
            $next = $entry === null ? '' : self::normalise((string) ($entry['replaced_by'] ?? ''));
            if ($next === '' || isset($seen[$next]) || self::entry($provider, $capability, $next) === null) {
                return null;
            }
            if (!self::is_retired($provider, $capability, $next, $now)) {
                return $next;
            }
            $seen[$next] = true;
            $current = $next;
        }
        return null;
    }

    /**
     * The model to actually call.
     *
     * Empty $configured means the provider default. A model the catalog doesn't know is passed through as typed
     * (lowercased), because an admin may name a model newer than this file. A listed model that is still callable
     * at $now is used as is. A retired one gives way to its declared successor; if that chain is broken (no
     * successor, a loop, a step outside the catalog) the provider default is used instead, so a known-dead model
     * is never sent.
     *
     * @param string $provider
     * @param string $capability
     * @param string $configured
     * @param int|null $now Unix time; null means time().
     * @return string '' only when nothing is configured and the catalog has no default.
     */
    public static function resolve_model(string $provider, string $capability, string $configured, ?int $now = null): string {
        $now = $now ?? time();
        $model = self::normalise($configured);
        if ($model === '') {
            $model = self::default_model($provider, $capability);
            if ($model === '') {
                return '';
            }
        }
        if (!self::is_retired($provider, $capability, $model, $now)) {
            return $model;
        }
        $next = self::successor($provider, $capability, $model, $now);
        if ($next !== null) {
            return $next;
        }
        $default = self::default_model($provider, $capability);
        if ($default !== '' && !self::is_retired($provider, $capability, $default, $now)) {
            return $default;
        }
        // The catalog itself is inconsistent (its default is retired). Say what is being sent rather than guess.
        return $model;
    }

    /**
     * The model to retry with after the provider said $model doesn't exist, or null when there is none.
     *
     * The declared successor when there is one (whatever the date: the provider has already said no). For a model
     * the catalog doesn't list, the provider default, which is vetted. Never $model itself, never a model outside
     * the catalog.
     *
     * @param string $provider
     * @param string $capability
     * @param string $model
     * @param int|null $now
     * @return string|null
     */
    public static function fallback_after_not_found(string $provider, string $capability, string $model,
            ?int $now = null): ?string {
        $now = $now ?? time();
        $model = self::normalise($model);
        $entry = self::entry($provider, $capability, $model);
        $candidate = null;
        if ($entry !== null) {
            $next = self::normalise((string) ($entry['replaced_by'] ?? ''));
            if ($next !== '' && self::entry($provider, $capability, $next) !== null) {
                $candidate = self::is_retired($provider, $capability, $next, $now)
                    ? self::successor($provider, $capability, $next, $now)
                    : $next;
            }
        } else {
            $candidate = self::default_model($provider, $capability);
        }
        if ($candidate === null || $candidate === '' || $candidate === $model
                || self::is_retired($provider, $capability, $candidate, $now)) {
            return null;
        }
        return $candidate;
    }

    /**
     * Did the provider refuse the call because the model doesn't exist?
     *
     * HTTP 404, or a 400 whose body says the model isn't found or supported (Google answers an unknown model on
     * generateContent with 404 NOT_FOUND; OpenAI with 404 model_not_found or 400 invalid model).
     *
     * @param int $status
     * @param string $body
     * @return bool
     */
    public static function is_model_not_found(int $status, string $body): bool {
        if ($status === 404) {
            return true;
        }
        if ($status !== 400) {
            return false;
        }
        return (bool) preg_match('~model_not_found|model[^,"]{0,80}(not found|does not exist|is not supported)'
            . '|unknown model|invalid model~i', $body);
    }

    /**
     * Listed models whose shutdown is at most $days away from $now, soonest first, including ones already past.
     *
     * @param int $days
     * @param int|null $now
     * @param array|null $only [[provider, capability, model], ...] to limit the list to models in use; null = all
     * @return array[] Each: provider, capability, model, shutdown (date), shutdown_time, days_left (negative when
     *                 past), retired (bool), replaced_by (the declared successor), resolves_to (what resolve_model
     *                 sends after shutdown)
     */
    public static function retiring_within(int $days, ?int $now = null, ?array $only = null): array {
        $now = $now ?? time();
        $horizon = $now + $days * DAYSECS;
        $wanted = null;
        if ($only !== null) {
            $wanted = [];
            foreach ($only as $item) {
                [$p, $c, $m] = array_pad(array_values($item), 3, '');
                $wanted[strtolower((string) $p) . '|' . $c . '|' . self::normalise((string) $m)] = true;
            }
        }
        $out = [];
        foreach (self::data() as $provider => $caps) {
            foreach ($caps as $capability => $spec) {
                foreach ($spec['models'] as $model => $entry) {
                    if ($entry['status'] !== self::DEPRECATED) {
                        continue;
                    }
                    if ($wanted !== null && !isset($wanted[$provider . '|' . $capability . '|' . $model])) {
                        continue;
                    }
                    $at = self::shutdown_time($entry);
                    if ($at === null || $at > $horizon) {
                        continue;
                    }
                    $out[] = [
                        'provider' => $provider,
                        'capability' => $capability,
                        'model' => $model,
                        'shutdown' => $entry['shutdown'],
                        'shutdown_time' => $at,
                        'days_left' => (int) floor(($at - $now) / DAYSECS),
                        'retired' => $now >= $at,
                        'replaced_by' => (string) ($entry['replaced_by'] ?? ''),
                        'resolves_to' => self::resolve_model($provider, $capability, $model, max($now, $at)),
                    ];
                }
            }
        }
        usort($out, static fn($a, $b) => [$a['shutdown_time'], $a['model']] <=> [$b['shutdown_time'], $b['model']]);
        return $out;
    }
}
