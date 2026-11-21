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
 * Registry for voice API providers (Realtime, TTS, STT).
 *
 * Admins configure one or more voice providers via the `voice_providers` admin
 * setting. Each row supplies a provider id (openai|xai|gemini), an API key, a human
 * label, default voice choices and, optionally, a text to speech model. Gemini rows
 * serve text to speech, and Realtime through Gemini Live once gemini_live_enabled
 * is on (8.2.0). Three separate admin settings then pick
 * which configured label is active for each capability:
 *   - voice_active_realtime
 *   - voice_active_tts
 *   - voice_active_stt
 *
 * This class parses the stored config and resolves the endpoint, key, and
 * voice for a given capability, falling back to the legacy realtime_apikey /
 * main apikey when no row is configured.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class voice_registry {
    public const CAPABILITY_REALTIME = 'realtime';
    public const CAPABILITY_TTS = 'tts';
    public const CAPABILITY_STT = 'stt';

    /** @var string Reserved voice_active_stt value selecting the selfhosted Whisper server. */
    public const SELFHOSTED_LABEL = 'selfhosted';

    /**
     * @var string The model name sent to a self-hosted or custom transcription server when none is set. It is the
     *      server's own name for its model, not OpenAI's, so the catalog never remaps it (8.3.0).
     */
    public const SELFHOSTED_DEFAULT_MODEL = 'whisper-1';

    /** @var string The only host whose model names the voice catalog retires and remaps (8.3.0). */
    public const OPENAI_HOST = 'api.openai.com';

    /**
     * @var array|null Set by resolve() when it found voice rows but every one it could use named a model its
     *      provider has shut down with no successor (8.3.0); read by last_retired(). Reset on every resolve().
     */
    private static ?array $lastretired = null;

    /**
     * @var array<string, string[]> What each voice provider can always do. Gemini is text to speech (8.1.0) and,
     *      once an admin switches it on, Realtime through Gemini Live (8.2.0, see OPT_IN_CAPABILITIES).
     */
    public const PROVIDER_CAPABILITIES = [
        'openai' => [self::CAPABILITY_REALTIME, self::CAPABILITY_TTS, self::CAPABILITY_STT],
        'xai' => [self::CAPABILITY_REALTIME, self::CAPABILITY_TTS, self::CAPABILITY_STT],
        'gemini' => [self::CAPABILITY_TTS],
    ];

    /**
     * @var array<string, array<string, string>> provider => capability => the checkbox setting that switches it on.
     *      Gemini Live is off by default: audio goes to Google and it is billed for as long as a session is open.
     */
    public const OPT_IN_CAPABILITIES = [
        'gemini' => [self::CAPABILITY_REALTIME => 'gemini_live_enabled'],
    ];

    /**
     * Can this provider serve this capability?
     *
     * A provider id outside PROVIDER_CAPABILITIES answers true, because endpoint_for() has always treated any
     * unknown id as OpenAI, and a row stored before 8.1.0 must keep doing what it did.
     *
     * @param string $provider
     * @param string $capability
     * @return bool
     */
    public static function supports(string $provider, string $capability): bool {
        $provider = strtolower($provider);
        if (!isset(self::PROVIDER_CAPABILITIES[$provider])) {
            return true;
        }
        if (in_array($capability, self::PROVIDER_CAPABILITIES[$provider], true)) {
            return true;
        }
        $setting = self::OPT_IN_CAPABILITIES[$provider][$capability] ?? '';
        return $setting !== '' && (bool) get_config('local_ai_course_assistant', $setting);
    }

    /**
     * Parse the voice_providers config into structured rows.
     * Stored format: provider|apikey|label|realtime_voice|tts_voice|tts_model|realtime_model|stt_model
     *
     * The sixth column (8.1.0), the seventh (8.2.0) and the eighth (8.3.0) are optional: a line without them reads
     * as before, with the model '' (the provider's default from voice_model_catalog).
     *
     * @param bool $reveal decrypt each row's key (false returns the stored form, for the settings page)
     * @return array List of rows with keys: provider, apikey, label, realtime_voice, tts_voice, tts_model,
     *     realtime_model, stt_model
     */
    public static function parse_rows(bool $reveal = true): array {
        $raw = get_config('local_ai_course_assistant', 'voice_providers') ?: '';
        $rows = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $provider = strtolower($parts[0] ?? '');
            if ($provider === '') {
                continue;
            }
            $rows[] = [
                'provider'       => $provider,
                // Encrypted at rest (#302). The admin form asks for the stored
                // form so the plain key never reaches the browser.
                'apikey'         => $reveal ? secrets::reveal($parts[1] ?? '') : ($parts[1] ?? ''),
                'label'          => $parts[2] ?? '',
                'realtime_voice' => $parts[3] ?? '',
                'tts_voice'      => $parts[4] ?? '',
                'tts_model'      => $parts[5] ?? '',
                'realtime_model' => $parts[6] ?? '',
                'stt_model'      => $parts[7] ?? '',
            ];
        }
        return $rows;
    }

    /**
     * Replace one key in the voice providers table, wherever it appears, editing the stored text and nothing else.
     *
     * The voice-table twin of provider_keys::replace_key(): every row whose revealed key has the fingerprint
     * $oldfingerprint (key_inventory::fingerprint()) gets $newstored in its key column, and every other byte of
     * every line stays as it was (key_inventory::replace_in_table()). Written only when a line changed; a table
     * forced in config.php is left alone (0). The caller conceals the new key.
     *
     * @param string $oldfingerprint fingerprint of the key to replace
     * @param string $newstored the new key column, already concealed with secrets::conceal()
     * @return int number of lines changed
     * @throws \coding_exception when $newstored is empty, not ciphertext, holds a "|", CR or LF, or doesn't
     *         decrypt on this site (key_inventory::check_new_stored()); the message names neither key
     */
    public static function replace_row_key(string $oldfingerprint, string $newstored): int {
        global $CFG;
        key_inventory::check_new_stored($newstored);
        if (array_key_exists('voice_providers', $CFG->forced_plugin_settings['local_ai_course_assistant'] ?? [])) {
            return 0;
        }
        $raw = (string) (get_config('local_ai_course_assistant', 'voice_providers') ?: '');
        $edit = key_inventory::replace_in_table($raw, $oldfingerprint, $newstored);
        if ($edit['changed'] > 0) {
            set_config('voice_providers', $edit['text'], 'local_ai_course_assistant');
        }
        return $edit['changed'];
    }

    /**
     * Resolve the active voice provider config for a given capability.
     *
     * Looks up the voice_active_{capability} admin setting, matches against
     * the parsed voice_providers rows by label (or falls back to first row),
     * and returns a full endpoint+key+voice bundle. Returns null when no
     * provider is configured at all.
     *
     * 8.3.0: a row whose model its provider has shut down with no successor (OpenAI text to speech from
     * 2027-01-06) is never returned. The chosen row gives way to the next row that can serve the capability, in
     * table order (a Gemini row for text to speech); when none can, the answer is null and last_retired() says
     * which model and date stopped it, so the caller can tell the admin which setting to change.
     *
     * @param string $capability One of the CAPABILITY_* constants.
     * @param int|null $now Unix time the catalog's shutdown dates are compared with; null means now().
     * @return array|null ['provider', 'apikey', 'voice', 'endpoint', 'label', 'model']
     */
    public static function resolve(string $capability, ?int $now = null): ?array {
        $now = $now ?? self::now();
        self::$lastretired = null;
        // Selfhosted Whisper STT (v6.2.0): free, so it bypasses the voice
        // spend guard entirely. It is the DEFAULT STT path whenever a server
        // URL is configured; an explicit paid label in voice_active_stt still
        // wins so hosted STT stays selectable.
        if ($capability === self::CAPABILITY_STT) {
            $selfhosted = self::selfhosted_stt_config();
            if ($selfhosted !== null) {
                $activelabel = (string) (get_config('local_ai_course_assistant', 'voice_active_stt') ?: '');
                if ($activelabel === '' || $activelabel === self::SELFHOSTED_LABEL) {
                    return $selfhosted;
                }
            }
        }

        // Spend guard: if voice is over cap, swap in the failover chain voice
        // provider (if any) before resolving. Failover returns null when none
        // is configured — in that case we still try the normal resolution so
        // the caller sees a nullable config and can surface "voice paused".
        try {
            $guardlevel = spend_guard::check(0, 'voice');
            if ($guardlevel === spend_guard::CAP_BLOCKED) {
                $failover = spend_guard::resolve_failover('voice');
                if ($failover !== null && self::supports($failover['provider'], $capability)) {
                    $model = self::model_for($failover['provider'], $capability, self::row_model($failover, $capability),
                        $now);
                    if (self::model_is_shut_down($failover['provider'], $capability, $model, $now)) {
                        self::note_retired($failover['provider'], $capability, $model);
                        return null;
                    }
                    return [
                        'provider' => $failover['provider'],
                        'apikey'   => $failover['apikey'],
                        'voice'    => self::default_voice_for($failover['provider']),
                        'endpoint' => self::endpoint_for($failover['provider'], $capability, $model),
                        'label'    => $failover['label'] . ' (failover)',
                        'model'    => $model,
                    ];
                }
                // No failover — block voice for this period.
                debugging('SOLA voice spend cap reached; no failover configured. Voice paused.', DEBUG_DEVELOPER);
                return null;
            }
        } catch (\Throwable $ignore) {
            // Guard errors never block the primary path.
        }

        $rows = self::parse_rows();
        $activelabel = get_config('local_ai_course_assistant', 'voice_active_' . $capability);

        // v7.0.5: honour the emergency kill switch.
        //
        // emergency_control disables a voice capability by stashing
        // voice_active_<cap> into voice_active_<cap>_backup and blanking the
        // live key. But a blank active label is ALSO the ordinary "admin never
        // picked a row" state, and the fallback below then selects $rows[0] —
        // so on any site with a voice_providers row configured, engaging the
        // kill switch simply moved voice onto the first row instead of stopping
        // it. Same shape as the `--chat` switch that was a silent no-op from
        // v5.4.5 to v5.12.x.
        //
        // emergency_control sets a dedicated `emergency_voice_disabled` flag for
        // exactly this reason, mirroring `emergency_chat_disabled`.
        if ((bool) get_config('local_ai_course_assistant', 'emergency_voice_disabled')) {
            debugging(
                'SOLA voice capability "' . $capability . '" is disabled by the emergency kill switch.',
                DEBUG_DEVELOPER
            );
            return null;
        }

        // A row whose provider can't serve this capability (a Gemini row asked for Realtime or STT) is passed
        // over, both as the chosen label and as the first-row fallback, so it never gets an endpoint it doesn't
        // have. With only openai and xai rows this changes nothing.
        $hadrows = !empty($rows);
        $rows = array_values(array_filter($rows, static fn($r) => self::supports($r['provider'], $capability)));
        // The chosen row first, then the others in table order (the first row is the fallback when nothing is
        // chosen, as before). 8.3.0: a row whose model has been shut down with no successor is passed over, so the
        // next configured row (a Gemini row, for OpenAI text to speech) serves instead.
        $ordered = $rows;
        if ($activelabel !== false && $activelabel !== '') {
            foreach ($rows as $i => $r) {
                if ($r['label'] === $activelabel) {
                    $ordered = array_merge([$r], array_slice($rows, 0, $i), array_slice($rows, $i + 1));
                    break;
                }
            }
        }
        $row = null;
        $model = '';
        foreach ($ordered as $r) {
            $candidate = self::model_for($r['provider'], $capability, self::row_model($r, $capability), $now);
            if (self::model_is_shut_down($r['provider'], $capability, $candidate, $now)) {
                self::note_retired($r['provider'], $capability, $candidate);
                continue;
            }
            if (self::$lastretired !== null) {
                debugging('SOLA voice: ' . self::$lastretired['provider'] . ' shut down ' . self::$lastretired['model']
                    . ' on ' . self::$lastretired['shutdown'] . ', so ' . $capability . ' uses the row "' . $r['label']
                    . '" instead.', DEBUG_DEVELOPER);
                self::$lastretired = null;
            }
            $row = $r;
            $model = $candidate;
            break;
        }
        if ($row === null && $hadrows) {
            // Rows exist but none serves this capability (or every one names a model that has been shut down): the
            // legacy single key below must not quietly take over a site that configured its voice in the table.
            return null;
        }

        if ($row === null) {
            // Legacy fallback: pre-v3.9.5 single-key config (a chosen OpenAI row, realtime_apikey or main OpenAI key).
            $legacykey = self::legacy_realtime_key();
            if (empty($legacykey)) {
                $mainprovider = get_config('local_ai_course_assistant', 'provider');
                if ($mainprovider === 'openai') {
                    $legacykey = \local_ai_course_assistant\secrets::get('apikey');
                }
            }
            if (empty($legacykey)) {
                return null;
            }
            $model = self::model_for('openai', $capability, '', $now);
            if (self::model_is_shut_down('openai', $capability, $model, $now)) {
                self::note_retired('openai', $capability, $model);
                return null;
            }
            return [
                'provider' => 'openai',
                'apikey'   => $legacykey,
                'voice'    => get_config('local_ai_course_assistant', 'realtime_voice') ?: 'shimmer',
                'endpoint' => self::endpoint_for('openai', $capability, $model),
                'label'    => '',
                'model'    => $model,
            ];
        }

        $voice = '';
        if ($capability === self::CAPABILITY_REALTIME) {
            $voice = $row['realtime_voice'];
        } else if ($capability === self::CAPABILITY_TTS) {
            $voice = $row['tts_voice'];
        }
        if ($voice === '') {
            $voice = self::default_voice_for($row['provider']);
        }

        return [
            'provider' => $row['provider'],
            'apikey'   => $row['apikey'],
            'voice'    => $voice,
            'endpoint' => self::endpoint_for($row['provider'], $capability, $model),
            'label'    => $row['label'],
            'model'    => $model,
        ];
    }

    /**
     * Which model stopped the last resolve(), when every row it could use named a model the provider has shut down
     * with no successor (8.3.0). Null when resolve() found something or failed for another reason.
     *
     * @return array|null ['provider', 'capability', 'model', 'shutdown' (YYYY-MM-DD)]
     */
    public static function last_retired(): ?array {
        return self::$lastretired;
    }

    /**
     * The error tts.php returns when the last resolve(CAPABILITY_TTS) stopped at a shut-down model (8.3.0), naming
     * the model, the date and the setting to change. Null when that isn't why it failed.
     *
     * @return string|null
     */
    public static function retired_tts_message(): ?string {
        $retired = self::$lastretired;
        if ($retired === null || $retired['capability'] !== self::CAPABILITY_TTS) {
            return null;
        }
        return branding::str('voice:error_tts_retired', (object) [
            'provider' => $retired['provider'],
            'model' => $retired['model'],
            'date' => $retired['shutdown'],
        ]);
    }

    /**
     * The time shutdown dates are compared with: Moodle's clock, so a test can freeze it
     * (advanced_testcase::mock_clock_with_frozen()).
     *
     * @return int
     */
    public static function now(): int {
        return \core\di::get(\core\clock::class)->time();
    }

    /**
     * Is the resolved model one its provider has shut down, with nothing callable in its place?
     *
     * Only for providers the catalog lists; an unknown provider id is looked up as OpenAI, as model_for() does.
     *
     * @param string $provider
     * @param string $capability
     * @param string $model Already resolved by model_for().
     * @param int $now
     * @return bool
     */
    private static function model_is_shut_down(string $provider, string $capability, string $model, int $now): bool {
        if ($model === '') {
            return false;
        }
        $provider = strtolower($provider);
        if (!voice_model_catalog::has_provider($provider)) {
            $provider = 'openai';
        }
        return voice_model_catalog::is_retired($provider, $capability, $model, $now);
    }

    /**
     * Remember the first shut-down model resolve() passed over, for last_retired().
     *
     * @param string $provider
     * @param string $capability
     * @param string $model
     * @return void
     */
    private static function note_retired(string $provider, string $capability, string $model): void {
        if (self::$lastretired !== null) {
            return;
        }
        $provider = strtolower($provider);
        $catalogprovider = voice_model_catalog::has_provider($provider) ? $provider : 'openai';
        $entry = voice_model_catalog::entry($catalogprovider, $capability, $model);
        self::$lastretired = [
            'provider' => $catalogprovider,
            'capability' => $capability,
            'model' => $model,
            'shutdown' => (string) ($entry['shutdown'] ?? ''),
        ];
    }

    /**
     * The row's own model for a capability: tts_model for text to speech, realtime_model for Realtime, stt_model for
     * speech to text (8.3.0), else ''.
     *
     * @param array $row A parsed row (or a failover lookup carrying the same keys).
     * @param string $capability
     * @return string
     */
    public static function row_model(array $row, string $capability): string {
        if ($capability === self::CAPABILITY_TTS) {
            return trim((string) ($row['tts_model'] ?? ''));
        }
        if ($capability === self::CAPABILITY_REALTIME) {
            return trim((string) ($row['realtime_model'] ?? ''));
        }
        if ($capability === self::CAPABILITY_STT) {
            return trim((string) ($row['stt_model'] ?? ''));
        }
        return '';
    }

    /**
     * The model a capability calls, resolved through voice_model_catalog at call time.
     *
     * Each capability starts from the row's model column for it (row_model()); a blank Gemini Live column falls
     * back to the gemini_live_model setting (8.2.0). Blank means the catalog default, and a retired model is
     * replaced by its successor. Since 8.3.0 that includes speech to text on OpenAI rows (whisper-1 until
     * 2027-02-26, then gpt-transcribe); a provider with no speech to text entries (xAI) gets its row's model as
     * typed, or ''. Only provider rows come through here, and they always talk to the provider's own host; the
     * self-hosted server's model never does (stt_model()).
     *
     * @param string $provider
     * @param string $capability
     * @param string $rowmodel The row's model column for this capability (row_model()).
     * @param int|null $now
     * @return string
     */
    public static function model_for(string $provider, string $capability, string $rowmodel, ?int $now = null): string {
        $provider = strtolower($provider);
        if (!voice_model_catalog::has_provider($provider)) {
            // An unknown id is served as OpenAI (endpoint_for()).
            $provider = 'openai';
        }
        $configured = trim($rowmodel);
        if ($configured === '' && $capability === self::CAPABILITY_REALTIME && $provider === 'gemini') {
            $configured = trim((string) get_config('local_ai_course_assistant', 'gemini_live_model'));
        }
        return voice_model_catalog::resolve_model($provider, $capability, $configured, $now ?? self::now());
    }

    /**
     * Map provider+capability to the correct HTTPS/WebSocket endpoint.
     *
     * The OpenAI Realtime URL names its model, resolved through voice_model_catalog (the row's realtime_model, else
     * the default gpt-realtime-2.1-mini since 8.2.0; gpt-realtime-mini and gpt-realtime retire on 2027-01-20). A
     * Gemini row has a text to speech endpoint, built from the model, and the Live endpoint while Gemini Live is on.
     *
     * @param string $provider
     * @param string $capability
     * @param string $model The model already resolved by model_for(); '' means the catalog default.
     * @return string
     */
    public static function endpoint_for(string $provider, string $capability, string $model = ''): string {
        if ($provider === 'gemini') {
            if ($capability === self::CAPABILITY_REALTIME) {
                // Gemini Live, only while it's switched on: the constrained endpoint for ephemeral tokens. The
                // browser adds the token.
                return self::supports('gemini', $capability) ? voice\gemini_live_token::WS_URL : '';
            }
            if ($capability !== self::CAPABILITY_TTS) {
                return '';
            }
            $model = $model !== '' ? $model : self::model_for('gemini', $capability, '');
            return voice\gemini_tts::endpoint($model);
        }
        if ($provider === 'xai') {
            switch ($capability) {
                case self::CAPABILITY_REALTIME:
                    return 'wss://api.x.ai/v1/realtime';
                case self::CAPABILITY_TTS:
                    return 'https://api.x.ai/v1/tts';
                case self::CAPABILITY_STT:
                    return 'https://api.x.ai/v1/stt';
            }
        }
        // OpenAI is the default for any unknown provider id.
        switch ($capability) {
            case self::CAPABILITY_REALTIME:
                $model = $model !== '' ? $model : self::model_for('openai', $capability, '');
                return 'wss://api.openai.com/v1/realtime?model=' . rawurlencode($model);
            case self::CAPABILITY_TTS:
                return 'https://api.openai.com/v1/audio/speech';
            case self::CAPABILITY_STT:
                return 'https://api.openai.com/v1/audio/transcriptions';
        }
        return '';
    }

    /**
     * Default voice identifier when a row leaves the voice column blank.
     *
     * @param string $provider
     * @return string
     */
    public static function default_voice_for(string $provider): string {
        if ($provider === 'gemini') {
            return 'Kore';
        }
        return $provider === 'xai' ? 'eve' : 'shimmer';
    }

    /**
     * Resolve the selfhosted Whisper STT config, or null when no server URL
     * is configured.
     *
     * Selfhosted servers (faster-whisper based images such as whisper-server,
     * speaches, or whisper.cpp server) expose the OpenAI compatible
     * POST /v1/audio/transcriptions endpoint, so transcribe.php can talk to
     * them with the same multipart request it sends to OpenAI. The API key is
     * optional (most selfhosted servers are keyless behind a trusted network);
     * the model name identifies the Whisper model the server has loaded.
     *
     * @return array|null ['provider','apikey','voice','endpoint','label','model']
     */
    public static function selfhosted_stt_config(): ?array {
        // Explicit master switch (v6.8.5). Unset is treated as on so existing
        // URL-configured installs keep working after upgrade; only an explicit
        // '0' disables the self-hosted path.
        if ((string) get_config('local_ai_course_assistant', 'stt_selfhosted_enabled') === '0') {
            return null;
        }
        $url = trim((string) get_config('local_ai_course_assistant', 'stt_selfhosted_url'));
        if ($url === '') {
            return null;
        }
        $model = trim((string) get_config('local_ai_course_assistant', 'stt_selfhosted_model'));
        return [
            'provider' => 'selfhosted',
            'apikey'   => trim((string) \local_ai_course_assistant\secrets::get('stt_selfhosted_apikey')),
            'voice'    => '',
            'endpoint' => self::selfhosted_stt_endpoint($url),
            'label'    => self::SELFHOSTED_LABEL,
            // The server's own model name, never remapped by the voice catalog (stt_model()).
            'model'    => $model !== '' ? $model : self::SELFHOSTED_DEFAULT_MODEL,
        ];
    }

    /**
     * Normalize an admin-entered selfhosted server URL to a full OpenAI
     * compatible transcription endpoint.
     *
     * Accepts a bare base URL (http://host:8000), a base ending in /v1, or a
     * full path that already contains /audio/transcriptions.
     *
     * @param string $url Admin-entered URL.
     * @return string Full endpoint URL.
     */
    public static function selfhosted_stt_endpoint(string $url): string {
        $url = rtrim(trim($url), '/');
        if (strpos($url, '/audio/transcriptions') !== false) {
            return $url;
        }
        if (preg_match('~/v\d+$~', $url)) {
            return $url . '/audio/transcriptions';
        }
        return $url . '/v1/audio/transcriptions';
    }

    /**
     * The model name to post to a speech to text endpoint (8.3.0), for transcribe.php, soapbox_transcribe.php and
     * soapbox_scorer.
     *
     * Only a provider row on api.openai.com is subject to the catalog's retirement mapping (whisper-1 and the 4o
     * transcribe models to gpt-transcribe from 2027-02-26). A self-hosted Whisper server, or any other base URL,
     * names its own models, and whisper-1 there is the server's name for whatever it has loaded, so it is sent
     * exactly as configured (whisper-1 when blank), whatever the date. xAI's request has always named grok-stt.
     *
     * @param array $cfg resolve(CAPABILITY_STT) output.
     * @param int|null $now
     * @return string
     */
    public static function stt_model(array $cfg, ?int $now = null): string {
        $provider = strtolower((string) ($cfg['provider'] ?? ''));
        $configured = trim((string) ($cfg['model'] ?? ''));
        if ($provider === 'xai') {
            return 'grok-stt';
        }
        if ($provider === self::SELFHOSTED_LABEL || !self::is_openai_hosted((string) ($cfg['endpoint'] ?? ''))) {
            return $configured !== '' ? $configured : self::SELFHOSTED_DEFAULT_MODEL;
        }
        return voice_model_catalog::resolve_model('openai', self::CAPABILITY_STT, $configured, $now ?? self::now());
    }

    /**
     * Does this endpoint go to OpenAI's own API host (https://api.openai.com, default port, no user info)?
     *
     * @param string $endpoint
     * @return bool
     */
    public static function is_openai_hosted(string $endpoint): bool {
        $parts = parse_url(trim($endpoint));
        if (!is_array($parts)) {
            return false;
        }
        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === self::OPENAI_HOST
            && !isset($parts['port']) && !isset($parts['user']);
    }

    /**
     * Voice identifiers known to each provider, used to populate UI selects.
     *
     * @param string $provider
     * @return array
     */
    public static function voices_for(string $provider): array {
        if ($provider === 'xai') {
            return ['eve', 'ara', 'leo', 'rex', 'sal'];
        }
        if ($provider === 'gemini') {
            // Prebuilt voices from Google's Gemini TTS documentation (2.5 models). Not checked against 3.8.
            return ['Kore', 'Charon', 'Puck', 'Zephyr', 'Aoede', 'Sulafat', 'Achird', 'Iapetus', 'Schedar', 'Erinome'];
        }
        return ['shimmer', 'alloy', 'echo', 'fable', 'onyx', 'nova'];
    }

    /**
     * The OpenAI key the single-key voice path uses: the provider row chosen in realtime_key_from, else the pasted
     * realtime_apikey. '' when neither is set.
     *
     * A row is used only when the admin chose it, so an OpenAI failover row alone never turns voice on. And only
     * a plain OpenAI row can be chosen: the single-key path always talks to api.openai.com, so another vendor's
     * key would be sent to OpenAI, and a row with its own base URL (Azure OpenAI, a gateway) holds a key for that
     * destination, not for api.openai.com. The first plain keyed OpenAI row serves, wherever it sits in the table:
     * a gateway row listed before it is skipped, not a stop, and so is a row with no key or one that cannot be
     * decrypted. A choice with no such row, or one that is gone, counts as no choice.
     *
     * @return string
     */
    public static function legacy_realtime_key(): string {
        $from = strtolower(trim((string) get_config('local_ai_course_assistant', 'realtime_key_from')));
        if ($from === 'openai') {
            foreach (\local_ai_course_assistant\provider_keys::rows(true) as $row) {
                if ($row['provider'] === 'openai' && $row['haskey'] && $row['key'] !== '' && trim($row['baseurl']) === '') {
                    return $row['key'];
                }
            }
        }
        return (string) \local_ai_course_assistant\secrets::get('realtime_apikey');
    }

    /**
     * Lightweight config-only check: is any voice provider configured?
     *
     * Unlike {@see resolve()} this does NOT run the spend guard, so it is cheap
     * enough to call on every course-page render (e.g. to decide whether to
     * show the speaker button). A configured voice_providers row serves any
     * capability via resolve()'s first-row fallback, and the legacy single-key
     * config (a chosen OpenAI row, realtime_apikey, or the main OpenAI key) also
     * counts. A provider row nobody chose does not. This fixes
     * the speaker button being gated on the legacy key alone, which hid
     * registry-configured TTS on sites running a non-OpenAI chat provider.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        if (!empty(self::parse_rows())) {
            return true;
        }
        if (!empty(self::legacy_realtime_key())) {
            return true;
        }
        return get_config('local_ai_course_assistant', 'provider') === 'openai'
            && !empty(\local_ai_course_assistant\secrets::get('apikey'));
    }

    /**
     * Return true when any voice capability (Realtime, TTS, or STT) resolves
     * to a configured provider. Used by the widget to suppress mic, speaker,
     * and voice tab affordances (and the matching help and welcome copy) on
     * installs where voice is not set up.
     *
     * @return bool
     */
    public static function any_voice_enabled(): bool {
        return self::resolve(self::CAPABILITY_REALTIME) !== null
            || self::resolve(self::CAPABILITY_TTS) !== null
            || self::resolve(self::CAPABILITY_STT) !== null;
    }

    /**
     * List of label strings from configured voice_providers rows, for UI dropdowns.
     *
     * @return array keyed by label => display string
     */
    public static function active_choices(): array {
        $rows = self::parse_rows();
        $out = [];
        foreach ($rows as $r) {
            $label = $r['label'] !== '' ? $r['label'] : ucfirst($r['provider']);
            $out[$label] = $label . ' (' . $r['provider'] . ')';
        }
        return $out;
    }

    /**
     * The analytics interaction_type for a voice call, from a closed set.
     *
     * v7.4.4. tts.php and transcribe.php used to build this by concatenating the
     * ADMIN-ENTERED provider id with '_tts' / '_stt'. Only a fixed list is
     * admitted by analytics::spend_rows_predicate() and
     * spend_guard::capability_sql('voice'), so any provider outside that list --
     * anything an admin typed that we had not anticipated -- produced rows that
     * were written, counted by nothing, and priced at zero. That is the same
     * silent-floor shape that made RAG spend read $0.00.
     *
     * Unrecognised providers now fall back to the generic 'voice', which IS in
     * both lists. A slightly coarse category beats an invisible row.
     *
     * @param string $provider Provider id from config; may be anything an admin typed.
     * @param string $kind 'tts', 'stt' or 'live'.
     * @return string An interaction_type guaranteed to be in the billable set.
     */
    public static function interaction_type(string $provider, string $kind): string {
        $known = [
            'tts' => ['openai' => 'openai_tts', 'xai' => 'xai_tts', 'gemini' => 'gemini_tts'],
            // 8.2.0: one row per Gemini Live session (voice\gemini_live).
            'live' => ['gemini' => 'gemini_live'],
            'stt' => [
                'openai' => 'openai_stt',
                'xai' => 'xai_stt',
                'whisper' => 'openai_whisper',
                self::SELFHOSTED_LABEL => 'selfhosted_stt',
            ],
        ];
        return $known[$kind][$provider] ?? 'voice';
    }
}
