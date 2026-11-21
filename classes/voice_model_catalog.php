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
 * provider's default. A model retired with no vetted successor (OpenAI text to speech, 8.3.0) is not replaced at
 * all: resolves_to_retired() says so and voice_registry moves on to the next configured provider row.
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
     * 'replaced_by' => model or null]. A deprecated entry with no vetted successor says so with
     * 'no_successor' => true and 'replaced_by' => null (8.3.0, OpenAI text to speech). Ids are lowercase.
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
            // Gemini Live (8.2.0): get_realtime_token locks each session's token to the model resolved here, from
            // the gemini_live_model setting or this default. The native-audio preview is replaced by 3.8 Live from
            // its shutdown date, so a site still naming it keeps working.
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
            // 8.3.0: OpenAI's deprecation notice (fetched 2026-11-21 by the site owner) shuts down all three speech
            // models on 2027-01-06. The notice names gpt-realtime-2.1-mini as the replacement, but that is a
            // Realtime (WebSocket) model, not a model for POST /v1/audio/speech, so there is no same-provider drop-in
            // and no automatic successor: from the shutdown date voice_registry passes an OpenAI text to speech row
            // over for the next configured row (a Gemini row), and with none, tts.php says which setting to change.
            // tts-1 stays the default so nothing changes before that date.
            self::TTS => [
                'default' => 'tts-1',
                'models' => [
                    'tts-1' => ['status' => self::DEPRECATED, 'shutdown' => '2027-01-06', 'replaced_by' => null,
                        'no_successor' => true],
                    'tts-1-hd' => ['status' => self::DEPRECATED, 'shutdown' => '2027-01-06', 'replaced_by' => null,
                        'no_successor' => true],
                    'gpt-4o-mini-tts' => ['status' => self::DEPRECATED, 'shutdown' => '2027-01-06',
                        'replaced_by' => null, 'no_successor' => true],
                ],
            ],
            // 8.3.0: speech to text through POST /v1/audio/transcriptions, for rows on api.openai.com only. A
            // self-hosted or custom server's model name (often whisper-1 too) is never looked up here; see
            // voice_registry::stt_model(). The notice names gpt-transcribe (same endpoint) or gpt-live-transcribe
            // (Realtime) as replacements; gpt-transcribe is the one SOLA's multipart upload can use. whisper-1 stays
            // the default so nothing changes before 2027-02-26.
            self::STT => [
                'default' => 'whisper-1',
                'models' => [
                    'gpt-transcribe' => ['status' => self::CURRENT, 'shutdown' => null, 'replaced_by' => null],
                    'gpt-4o-mini-transcribe-2025-12-15' => ['status' => self::CURRENT, 'shutdown' => null,
                        'replaced_by' => null],
                    'whisper-1' => ['status' => self::DEPRECATED, 'shutdown' => '2027-02-26',
                        'replaced_by' => 'gpt-transcribe'],
                    'gpt-4o-transcribe' => ['status' => self::DEPRECATED, 'shutdown' => '2027-02-26',
                        'replaced_by' => 'gpt-transcribe'],
                    'gpt-4o-mini-transcribe' => ['status' => self::DEPRECATED, 'shutdown' => '2027-02-26',
                        'replaced_by' => 'gpt-transcribe'],
                    'gpt-4o-transcribe-diarize' => ['status' => self::DEPRECATED, 'shutdown' => '2027-02-26',
                        'replaced_by' => 'gpt-transcribe'],
                    'gpt-4o-mini-transcribe-2025-03-20' => ['status' => self::DEPRECATED, 'shutdown' => '2027-01-20',
                        'replaced_by' => 'gpt-4o-mini-transcribe-2025-12-15'],
                ],
            ],
            // 8.2.0: OpenAI's deprecation notice (fetched 2026-11-21 by the site owner) retires both Realtime
            // models on 2027-01-20. The 2.1 models are the default from 8.2.0 on.
            self::REALTIME => [
                'default' => 'gpt-realtime-2.1-mini',
                'models' => [
                    'gpt-realtime-2.1-mini' => ['status' => self::CURRENT, 'shutdown' => null, 'replaced_by' => null],
                    'gpt-realtime-2.1' => ['status' => self::CURRENT, 'shutdown' => null, 'replaced_by' => null],
                    'gpt-realtime-mini' => ['status' => self::DEPRECATED, 'shutdown' => '2027-01-20',
                        'replaced_by' => 'gpt-realtime-2.1-mini'],
                    'gpt-realtime' => ['status' => self::DEPRECATED, 'shutdown' => '2027-01-20',
                        'replaced_by' => 'gpt-realtime-2.1'],
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
     * Is this a model the provider retires with no vetted successor (8.3.0)?
     *
     * For such a model the catalog never picks a replacement on its own. Once it is retired, a caller must not
     * send it: voice_registry passes the row over for the next configured one.
     *
     * @param string $provider
     * @param string $capability
     * @param string $model
     * @return bool
     */
    public static function has_no_successor(string $provider, string $capability, string $model): bool {
        $entry = self::entry($provider, $capability, $model);
        return $entry !== null && !empty($entry['no_successor']);
    }

    /**
     * Is resolve_model()'s answer for this configured model one the provider has shut down (8.3.0)?
     *
     * True only when the catalog has nothing callable to offer: the model is retired and neither a successor nor
     * a current default exists. A caller that gets true must not make the call.
     *
     * @param string $provider
     * @param string $capability
     * @param string $configured As passed to resolve_model().
     * @param int|null $now
     * @return bool
     */
    public static function resolves_to_retired(string $provider, string $capability, string $configured,
            ?int $now = null): bool {
        $now = $now ?? time();
        return self::is_retired($provider, $capability, self::resolve_model($provider, $capability, $configured, $now),
            $now);
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
        // Nothing callable is left: a model with no successor whose default is retired too (OpenAI text to speech
        // from 2027-01-06), or an inconsistent catalog. Say what it is rather than guess; callers check
        // resolves_to_retired() and don't send it.
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
     *                 past), retired (bool), replaced_by (the declared successor), no_successor (bool, 8.3.0),
     *                 resolves_to (what resolve_model answers after shutdown)
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
                        'no_successor' => !empty($entry['no_successor']),
                        'resolves_to' => self::resolve_model($provider, $capability, $model, max($now, $at)),
                    ];
                }
            }
        }
        usort($out, static fn($a, $b) => [$a['shutdown_time'], $a['model']] <=> [$b['shutdown_time'], $b['model']]);
        return $out;
    }
}
