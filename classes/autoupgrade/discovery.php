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

namespace local_ai_course_assistant\autoupgrade;

use local_ai_course_assistant\model_capabilities;
use local_ai_course_assistant\provider\base_provider;
use local_ai_course_assistant\provider\model_not_found_exception;
use local_ai_course_assistant\model_registry;
use local_ai_course_assistant\security;

/**
 * Model discovery (v7.8.0): what each configured provider offers today.
 *
 * Lists models from every provider SOLA holds a key for (OpenAI, Anthropic,
 * Google, and any OpenAI-compatible endpoint that serves /models), registers
 * the chat models it finds in the model registry with a price from the rate
 * card, and marks CANDIDATES for each role in use: same provider as the model
 * the role runs now, a plausible tier for the role, and a KNOWN price.
 *
 * "Known price" is deliberately strict. A registry price counts only when its
 * key is the model id itself, a dated or versioned snapshot of it, or the
 * LiteLLM spelling of it ("gemini/<id>"). A family catch-all like
 * 'claude-haiku' does NOT count: it priced claude-haiku-5-5 at ten times its
 * real rate in the shipped baseline, and the cheaper direction of the same
 * error would make an expensive model look like a saving.
 *
 * "Plausible tier" is a price band: a model whose production-shaped list price
 * (5,000 input and 500 output tokens) is between BAND_MIN and BAND_MAX of the
 * current model's. Below the band is a different class of model (a 5x cheaper
 * model replacing a premium tutor), above it is very unlikely to measure as the
 * same cost or cheaper, and evaluating it would spend the monthly budget on a
 * foregone result. Preview, experimental and moving "-latest" aliases are
 * never candidates: a production tutor should not move onto a model that can
 * be withdrawn, or change, without notice.
 *
 * Nothing here calls a model, spends money or changes a setting. Listing is a
 * GET with the provider's key; the key is never logged, stored or shown.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discovery {
    /** @var string Marks a registry row discovery wrote, so only those get their copied price refreshed. */
    public const DISCOVERY_NOTE = '[model discovery]';

    /** @var float Lowest production-shaped list price, as a fraction of the current model's. */
    public const BAND_MIN = 0.2;

    /** @var float Highest, as a multiple. */
    public const BAND_MAX = 1.5;

    /** @var int Input tokens of the production-shaped turn used for the band. */
    public const TURN_INPUT_TOKENS = 5000;

    /** @var int Output tokens of the same. */
    public const TURN_OUTPUT_TOKENS = 500;

    /**
     * Name fragments that mark a model as not a chat model, or not one a
     * production tutor should run on.
     *
     * @var string[]
     */
    public const EXCLUDE_FRAGMENTS = [
        'embed', 'tts', 'whisper', 'transcribe', 'dall-e', 'image', 'audio', 'realtime', 'moderation',
        'search', 'computer-use', 'codex', 'sora', 'davinci', 'babbage', 'live', 'robotics', 'deep-research',
        'preview', '-exp', 'latest', 'guard', 'aqa', 'veo', 'imagen', 'lyria', 'cyber',
    ];

    /** @var string LiteLLM key prefixes per provider, for the price lookup. */
    private const LITELLM_PREFIX = [
        'gemini' => 'gemini/',
        'together' => 'together_ai/',
        'openrouter' => 'openrouter/',
        'mistral' => 'mistral/',
        'deepseek' => 'deepseek/',
        'xai' => 'xai/',
    ];

    /** @var callable HTTP GET: fn(string $url, array $headers): array{0: int, 1: string} */
    private $http;

    /** @var array<string, bool> Probe answers this request, so a model is asked about once. */
    private static array $probed = [];

    /** @var callable|null Probe: fn(string $provider, string $model): bool. Null skips probing (tests). */
    private $probe;

    /**
     * Constructor.
     *
     * @param callable|null $http Replaces the network for tests.
     * @param callable|null $probe Asks whether a model can really be called; null skips the check.
     */
    public function __construct(?callable $http = null, ?callable $probe = null) {
        $this->http = $http ?? [self::class, 'http_get'];
        $this->probe = $probe;
    }

    /**
     * The discovery the scheduled task and the admin page run: real network, real probe.
     *
     * @return self
     */
    public static function live(): self {
        return new self(null, [self::class, 'probe']);
    }

    /**
     * Whether a listed model can actually be called.
     *
     * A provider's model list can name a model its API then refuses:
     * gemini-2.5-flash-lite was listed by Google and answered 404. Offering such a
     * model as a candidate wastes an evaluation slot and test budget. One
     * one-token call settles it. Only a clear "no such model" (HTTP 404) says no;
     * a timeout, a rate limit, a key problem or a missing comparison row proves
     * nothing about the model, so those keep the candidate.
     *
     * @param string $provider
     * @param string $model
     * @param callable|null $factory fn(provider, model): client, for tests.
     * @return bool False only when the provider says the model does not exist.
     */
    public static function probe(string $provider, string $model, ?callable $factory = null): bool {
        // One call per model per run: the same model is a candidate for several roles.
        $key = strtolower($provider . '|' . $model);
        if ($factory === null && isset(self::$probed[$key])) {
            return self::$probed[$key];
        }
        try {
            $client = $factory !== null
                ? $factory($provider, $model)
                : base_provider::create_for_comparison($provider, $model, 0, false);
            $client->chat_completion('Reply with the single word OK.', [['role' => 'user', 'content' => 'ok']], ['max_tokens' => 16]);
            $ok = true;
        } catch (model_not_found_exception $e) {
            $ok = false;
        } catch (\Throwable $e) {
            $ok = true;
        }
        if ($factory === null) {
            self::$probed[$key] = $ok;
        }
        return $ok;
    }

    /**
     * Discover, register and mark candidates for every role in use.
     *
     * @return array{providers: array, registered: int, candidates: array, errors: array}
     */
    public function run(): array {
        $summary = ['providers' => [], 'registered' => 0, 'candidates' => [], 'errors' => []];
        $credentials = self::credentials();
        $listed = [];
        foreach ($credentials as $provider => $cred) {
            try {
                $listed[$provider] = $this->list_models($provider, $cred);
                $summary['providers'][$provider] = count($listed[$provider]);
            } catch (\Throwable $e) {
                // One provider failing to list must not stop the others.
                $summary['errors'][$provider] = \core_text::substr(security::redact_secrets($e->getMessage()), 0, 200);
            }
        }

        foreach ($listed as $provider => $models) {
            foreach ($models as $model) {
                if (self::is_chat_model($model) && $this->register($provider, $model)) {
                    $summary['registered']++;
                }
            }
        }

        foreach (roles::all() as $role => $current) {
            if (!$current['inuse'] || !isset($listed[$current['provider']])) {
                continue;
            }
            $marked = self::mark_candidates($role, $current, $listed[$current['provider']], $this->probe);
            $summary['candidates'][$role] = $marked;
        }
        set_config('autoupgrade_last_discovery', (string) time(), 'local_ai_course_assistant');
        set_config('autoupgrade_last_discovery_result', json_encode([
            'providers' => $summary['providers'],
            'registered' => $summary['registered'],
            'errors' => array_keys($summary['errors']),
        ]), 'local_ai_course_assistant');
        return $summary;
    }

    /**
     * Mark the candidates for one role from the models its provider lists.
     *
     * @param string $role
     * @param array $current From roles::current().
     * @param string[] $models Model ids the provider lists.
     * @param callable|null $probe fn(provider, model): bool; a model it rejects is not marked.
     * @return string[] "model [variant]" labels marked.
     */
    public static function mark_candidates(string $role, array $current, array $models, ?callable $probe = null): array {
        $incumbent = self::known_price($current['provider'], $current['model']);
        if ($incumbent === null) {
            // Without the current model's price there is nothing to compare a
            // candidate's cost against, so nothing can be a candidate.
            return [];
        }
        $incumbentturn = self::turn_cost($incumbent);
        $marked = [];
        $keep = [];
        $models = self::drop_dated_duplicates($models);
        foreach ($models as $model) {
            $name = model_capabilities::normalise($model);
            $incname = model_capabilities::normalise($current['model']);
            // The current model under another name is not a candidate: Anthropic
            // lists claude-haiku-4-5-20251001, the snapshot a site configured as
            // claude-haiku-4-5 already runs.
            if (self::same_model($name, $incname) || self::same_model($incname, $name) || !self::is_chat_model($model)) {
                continue;
            }
            $price = self::known_price($current['provider'], $model);
            if ($price === null) {
                continue;
            }
            $ratio = $incumbentturn > 0 ? self::turn_cost($price) / $incumbentturn : INF;
            if ($ratio < self::BAND_MIN || $ratio > self::BAND_MAX) {
                continue;
            }
            if ($probe !== null && !$probe($current['provider'], $model)) {
                continue;
            }
            $keep[] = $model;
            candidates::upsert($role, $current['provider'], $model, roles::VARIANT_DEFAULT, sprintf(
                'Listed by %s on %s; list price %.2fx the current model for a production-shaped turn (%s).',
                $current['provider'],
                userdate(time(), '%Y-%m-%d'),
                $ratio,
                $price['key']
            ));
            $marked[] = $model;
        }

        // The current model with thinking switched off is a configuration
        // variant, and counts as a candidate where the role has a reasoning
        // setting and the model can actually stop thinking.
        $spec = roles::spec($role);
        if ($spec['variantkey'] !== null && $current['variant'] !== roles::VARIANT_THINKING_OFF) {
            $profile = model_capabilities::profile($current['provider'], $current['model']);
            $canoff = !empty($profile['thinks']) && ($profile['thinking_off'] ?? 'allowed') !== 'forbidden'
                && in_array(
                    $profile['reasoning'],
                    [model_capabilities::REASONING_GEMINI, model_capabilities::REASONING_OPENAI],
                    true
                );
            if ($canoff) {
                candidates::upsert(
                    $role,
                    $current['provider'],
                    $current['model'],
                    roles::VARIANT_THINKING_OFF,
                    'The current model with thinking switched off (reasoning effort off).'
                );
                $marked[] = $current['model'] . ' [' . roles::VARIANT_THINKING_OFF . ']';
                $keep[] = $current['model'];
            }
        }
        candidates::retire_missing($role, $current['provider'], $keep);
        return $marked;
    }

    /**
     * Providers SOLA holds a key for: comparison rows first, then the site key.
     *
     * The key leaves this method only inside the returned array, which is
     * handed straight to list_models(). Nothing logs or stores it.
     *
     * @return array<string, array{apikey: string, baseurl: string}>
     */
    public static function credentials(): array {
        $out = [];
        $raw = (string) (get_config('local_ai_course_assistant', 'comparison_providers') ?: '');
        foreach (preg_split("/\r?\n/", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $provider = strtolower($parts[0] ?? '');
            if ($provider === '' || ($parts[1] ?? '') === '' || isset($out[$provider])) {
                continue;
            }
            $out[$provider] = ['apikey' => $parts[1], 'baseurl' => $parts[4] ?? ''];
        }
        $siteprovider = strtolower((string) (get_config('local_ai_course_assistant', 'provider') ?: ''));
        $sitekey = (string) (\local_ai_course_assistant\secrets::get('apikey') ?: '');
        if ($siteprovider !== '' && $siteprovider !== 'auto' && $sitekey !== '' && !isset($out[$siteprovider])) {
            $out[$siteprovider] = [
                'apikey' => $sitekey,
                'baseurl' => (string) (get_config('local_ai_course_assistant', 'apibaseurl') ?: ''),
            ];
        }
        unset($out['stub'], $out['coreai']);
        return $out;
    }

    /**
     * Model ids a provider lists.
     *
     * @param string $provider
     * @param array $cred apikey, baseurl.
     * @return string[]
     */
    public function list_models(string $provider, array $cred): array {
        $key = (string) $cred['apikey'];
        $base = rtrim((string) ($cred['baseurl'] ?? ''), '/');
        switch ($provider) {
            case 'claude':
                $url = ($base !== '' ? $base : 'https://api.anthropic.com') . '/v1/models?limit=1000';
                $headers = ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
                break;
            case 'gemini':
                // The native list, not the OpenAI-compatibility one: it says
                // which models generate content.
                $url = 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000';
                $headers = ['x-goog-api-key: ' . $key];
                break;
            case 'openai':
                $url = ($base !== '' ? $base : 'https://api.openai.com') . (str_contains($base, '/v1') ? '' : '/v1')
                    . '/models';
                $headers = ['Authorization: Bearer ' . $key];
                break;
            default:
                $defaults = [
                    'together' => 'https://api.together.xyz',
                    'openrouter' => 'https://openrouter.ai/api',
                    'xai' => 'https://api.x.ai',
                    'deepseek' => 'https://api.deepseek.com',
                    'mistral' => 'https://api.mistral.ai',
                ];
                $base = $base !== '' ? $base : ($defaults[$provider] ?? '');
                if ($base === '') {
                    return [];
                }
                $url = $base . (str_contains($base, '/v1') ? '' : '/v1') . '/models';
                $headers = ['Authorization: Bearer ' . $key];
        }
        if (!security::is_safe_provider_url($url)) {
            throw new \moodle_exception('error', 'local_ai_course_assistant', '', null, 'Model list URL refused by the SSRF check');
        }
        [$status, $body] = call_user_func($this->http, $url, $headers);
        if ($status < 200 || $status >= 300) {
            throw new \moodle_exception('error', 'local_ai_course_assistant', '', null, "Model list returned HTTP {$status}");
        }
        return self::parse_list($provider, (string) $body);
    }

    /**
     * Model ids out of a provider's list response.
     *
     * @param string $provider
     * @param string $body
     * @return string[]
     */
    public static function parse_list(string $provider, string $body): array {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [];
        }
        $ids = [];
        if ($provider === 'gemini') {
            foreach ((array) ($data['models'] ?? []) as $m) {
                $methods = (array) ($m['supportedGenerationMethods'] ?? []);
                if (!in_array('generateContent', $methods, true)) {
                    continue;
                }
                $ids[] = model_capabilities::normalise((string) ($m['name'] ?? ''));
            }
        } else {
            $list = isset($data['data']) && is_array($data['data']) ? $data['data'] : (array_is_list($data) ? $data : []);
            foreach ($list as $m) {
                if (is_array($m) && !empty($m['id'])) {
                    $ids[] = (string) $m['id'];
                }
            }
        }
        $ids = array_values(array_unique(array_filter($ids, static function ($id) {
            return $id !== '' && strlen($id) <= 100;
        })));
        sort($ids);
        return $ids;
    }

    /**
     * Is this a chat model SOLA could tutor with?
     *
     * @param string $model
     * @return bool
     */
    public static function is_chat_model(string $model): bool {
        $m = strtolower($model);
        foreach (self::EXCLUDE_FRAGMENTS as $fragment) {
            if (str_contains($m, $fragment)) {
                return false;
            }
        }
        return true;
    }

    /**
     * A price the registry knows for exactly this model, or null.
     *
     * @param string $provider
     * @param string $model
     * @return array{input: float, output: float, key: string}|null
     */
    public static function known_price(string $provider, string $model): ?array {
        $name = model_capabilities::normalise($model);
        $prov = model_registry::provenance_for($name);
        if ($prov['prefix'] !== null && self::same_model($name, (string) $prov['prefix'])) {
            return ['input' => (float) $prov['input'], 'output' => (float) $prov['output'], 'key' => (string) $prov['prefix']];
        }
        return self::alias_price($provider, $name);
    }

    /**
     * Register a listed model in the registry.
     *
     * A model the registry has no row for gets one (provider, capability chat).
     * When the rate card knows its price only under the LiteLLM spelling
     * ("gemini/gemini-3.8-flash"), that price is copied onto the row, because
     * SOLA records and prices calls under the provider's own id and would
     * otherwise bill the model at nothing. A copied price is refreshed when the
     * rate card entry changes. Never touches a row discovery did not write, and
     * model_registry::upsert() refuses to overwrite a row an admin wrote.
     *
     * @param string $provider
     * @param string $model
     * @return bool True when a row was inserted or its copied price refreshed.
     */
    public function register(string $provider, string $model): bool {
        global $DB;
        $name = model_capabilities::normalise($model);
        $existing = $DB->get_record(model_registry::TABLE_MODELS, ['modelkey' => $name]);
        $alias = self::alias_price($provider, $name);
        $outcome = null;

        if ($existing) {
            $ours = str_contains((string) $existing->notes, self::DISCOVERY_NOTE);
            if (!$ours || $alias === null || $existing->source !== 'upstream') {
                return false;
            }
            if ((float) $existing->input_rate === $alias['input'] && (float) $existing->output_rate === $alias['output']) {
                return false;
            }
            model_registry::upsert(['modelkey' => $name, 'input_rate' => $alias['input'],
                'output_rate' => $alias['output']], 'upstream', null, $outcome);
            return $outcome === 'updated';
        }

        $row = ['modelkey' => $name, 'provider' => $provider, 'capability' => 'chat',
            'notes' => self::DISCOVERY_NOTE . ' Listed by ' . $provider . '.'];
        $own = model_registry::provenance_for($name);
        $ownexact = $own['prefix'] !== null && self::same_model($name, (string) $own['prefix']);
        if (!$ownexact && $alias !== null) {
            $row['input_rate'] = $alias['input'];
            $row['output_rate'] = $alias['output'];
            $row['notes'] .= ' Price from the rate card entry ' . $alias['key'] . '.';
        }
        model_registry::upsert($row, 'upstream', null, $outcome);
        return $outcome === 'inserted';
    }

    /**
     * The price the rate card holds under the LiteLLM spelling of a model.
     *
     * @param string $provider
     * @param string $name Normalised model id.
     * @return array{input: float, output: float, key: string}|null
     */
    private static function alias_price(string $provider, string $name): ?array {
        $prefix = self::LITELLM_PREFIX[$provider] ?? null;
        if ($prefix === null) {
            return null;
        }
        $alias = model_registry::provenance_for($prefix . $name);
        if ($alias['prefix'] === null || !self::same_model($prefix . $name, (string) $alias['prefix'])) {
            return null;
        }
        return ['input' => (float) $alias['input'], 'output' => (float) $alias['output'], 'key' => (string) $alias['prefix']];
    }

    /**
     * Production-shaped list cost of one turn, in USD.
     *
     * @param array $price input, output per 1M tokens.
     * @return float
     */
    public static function turn_cost(array $price): float {
        return (self::TURN_INPUT_TOKENS * (float) $price['input'] + self::TURN_OUTPUT_TOKENS * (float) $price['output'])
            / 1000000;
    }

    /**
     * Does a registry key price exactly this model (itself or a dated snapshot)?
     *
     * @param string $model Normalised model id.
     * @param string $key Registry key that matched.
     * @return bool
     */
    private static function same_model(string $model, string $key): bool {
        if ($model === $key) {
            return true;
        }
        if (!str_starts_with($model, $key)) {
            return false;
        }
        // The id is the key plus a snapshot suffix: -20251001, -2025-04-16, -001, @20250101.
        return preg_match('/^(?:[-@](?:\d{8}|\d{4}-\d{2}-\d{2}|\d{3}))$/', substr($model, strlen($key))) === 1;
    }

    /**
     * Drop dated snapshots whose undated alias is also listed.
     *
     * @param string[] $models
     * @return string[]
     */
    private static function drop_dated_duplicates(array $models): array {
        $set = array_flip(array_map('strtolower', $models));
        $out = [];
        foreach ($models as $model) {
            $base = preg_replace('/(?:-\d{8}|-\d{4}-\d{2}-\d{2}|-\d{3})$/', '', strtolower($model));
            if ($base !== strtolower($model) && isset($set[$base])) {
                continue;
            }
            $out[] = $model;
        }
        return $out;
    }

    /**
     * The real network GET.
     *
     * @param string $url
     * @param array $headers
     * @return array{0: int, 1: string}
     */
    public static function http_get(string $url, array $headers): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setopt(array_merge([
            'CURLOPT_HTTPHEADER' => $headers,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => 20,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_FOLLOWLOCATION' => false,
        ], security::resolve_pin_options($url)));
        $body = $curl->get($url);
        if ($curl->error) {
            return [0, ''];
        }
        return [(int) ($curl->get_info()['http_code'] ?? 0), (string) $body];
    }
}
