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

namespace local_ai_course_assistant\task;

use local_ai_course_assistant\model_bench;
use local_ai_course_assistant\model_registry;
use local_ai_course_assistant\provider\base_provider;
use local_ai_course_assistant\token_cost_manager;

/**
 * Ad-hoc task: benchmark one registry model and persist the result (v7.4.0).
 *
 * WHY AN ADHOC TASK. Benchmarking a candidate model used to require shell
 * access to admin/cli/run_tutor_golden.php, which means that after the next
 * production release — no deploys, no filesystem — nobody could take the
 * measurement at all. Queue this task from the admin page instead and cron
 * runs it. The CLI stays as a convenience; it is no longer the only path.
 *
 * Custom data:
 *   registry_key    (required) local_ai_course_assistant_models.modelkey.
 *   sola_function   which SOLA function this measures (chat, quiz, ...). Recorded,
 *                   and it is what latest_by_function() groups on.
 *   harness         'tutor_golden' (the only implemented one; anything else
 *                   fails the run with a message saying so, rather than
 *                   silently producing a number from a different measurement).
 *   samples         prompts to send, clamped to MAX_SAMPLES.
 *   model_name      exact model id to call; defaults to the registry key, which
 *                   is a prefix and is usually also a valid model id.
 *   provider        override the registry row's provider.
 *   runid           claim this pre-created queued row instead of opening a new
 *                   one, so the operator's "queued" row is the row that fills in.
 *   createdby       user who asked for the run (audit only).
 *   temperature, judge_provider, judge_model, fixture_set — optional overrides.
 *
 * NO FILE IS WRITTEN. The fixture set is read from disk; every output is a row.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_model_benchmark extends \core\task\adhoc_task {

    /**
     * Aggregated benchmark spend, keyed 'answer' and 'judge'.
     *
     * An INSTANCE property, not a local, so a run that throws part-way through
     * can still flush what it already spent: the loop can be 49 calls into 50
     * when it dies, and those calls were invoiced. Accumulated as we go and
     * never re-read from the provider at the end, because get_last_token_usage()
     * only ever holds the most recent call.
     *
     * @var array
     */
    private $benchspend = [];

    /** @var string The only harness implemented here. */
    public const HARNESS_TUTOR_GOLDEN = 'tutor_golden';

    /** @var int Prompts sent when the caller does not say. */
    public const DEFAULT_SAMPLES = 10;

    /**
     * Hard ceiling on prompts per run.
     *
     * Each prompt costs two live model calls (answer + judge). Fifty is the
     * size of the golden set, and also keeps a single cron run inside the
     * 120-calls-per-60s provider rate limit that applies to non-CLI callers.
     *
     * @var int
     */
    public const MAX_SAMPLES = 50;

    /** @var string Default fixture set, relative to the plugin root. */
    public const DEFAULT_FIXTURE = 'tests/golden/tutor_prompts.json';

    /** @var float Maximum rubric total: three dimensions scored 1-5. */
    public const RUBRIC_MAX = 15.0;

    /**
     * The tutor system prompt under test.
     *
     * SHARED WITH admin/cli/run_tutor_golden.php, which references this
     * constant rather than keeping its own copy. Two copies of a benchmark's
     * system prompt is two benchmarks whose numbers look comparable and are
     * not — and since the prompt ships in the code, every run also stamps
     * plugin_release so an old score stays interpretable after it changes.
     *
     * @var string
     */
    public const SYSTEM_PROMPT = "You are SOLA, Saylor University's AI learning coach. "
        . "Coach learners toward understanding rather than handing over answers. "
        . "Match the learner's language. Stay focused on the course material.";

    /**
     * The rubric judge's instructions. Shared with the CLI harness, as above.
     *
     * @var string
     */
    public const JUDGE_PROMPT = <<<TXT
You are evaluating a tutoring AI's response on three dimensions. Score 1 to 5 on each.

Original student prompt is provided. Tutor response is provided. Score the response.

1. SOCRATIC (1 to 5): Does the tutor guide the student toward discovering the answer rather than handing it over?
   5 = excellent Socratic guidance, asks clarifying questions, scaffolds hints
   3 = mixed; gives some direct content but also asks/scaffolds
   1 = pure spoonfeeding; states the answer directly without engaging the learner

2. ACCURACY (1 to 5): Is the content factually correct and well grounded?
   5 = factually solid; no errors
   3 = mostly accurate; minor issues or oversimplifications
   1 = significant factual errors

3. TONE (1 to 5): Does the response sound like a warm, encouraging, professional tutor for adult learners at a free university? Not condescending, not robotic, not overly casual.
   5 = excellent tone match
   3 = neutral / acceptable
   1 = poor tone (cold, condescending, or off)

Output STRICT JSON only, no markdown fencing, no commentary:
{"socratic": N, "accuracy": N, "tone": N, "notes": "one sentence"}
TXT;

    /**
     * Providers that legitimately need no SOLA credential.
     *
     * Mirrors base_provider::KEYLESS_PROVIDERS, which is private. Duplicated
     * only to decide whether to print the "add a comparison row" hint; getting
     * it wrong costs a misleading hint, never a wrong result.
     *
     * @var string[]
     */
    private const KEYLESS_PROVIDERS = ['stub', 'ollama', 'coreai'];

    /**
     * Task name for the ad-hoc queue screen.
     *
     * Added in the same change as the `task:run_model_benchmark` lang string.
     * Until that string existed this class deliberately kept adhoc_task's
     * default name, because an override without the string renders
     * "[[task:run_model_benchmark]]" in the admin queue.
     *
     * @return string
     */
    public function get_name(): string {
        return \local_ai_course_assistant\branding::str('task:run_model_benchmark');
    }

    /**
     * Run the benchmark and persist the aggregate.
     */
    public function execute() {
        \core_php_time_limit::raise(600);

        $data = (array) $this->get_custom_data();
        $key = strtolower(trim((string) ($data['registry_key'] ?? '')));
        $harness = trim((string) ($data['harness'] ?? self::HARNESS_TUTOR_GOLDEN));
        $function = trim((string) ($data['sola_function'] ?? 'chat'));
        // Admin-settable defaults, so the size and the judge of a web-triggered
        // run can be changed after the last deploy.
        $samples = (int) ($data['samples'] ?? self::configured_int('bench_default_samples', self::DEFAULT_SAMPLES));
        $samples = max(1, min(self::MAX_SAMPLES, $samples));
        $fixture = trim((string) ($data['fixture_set'] ?? self::DEFAULT_FIXTURE));

        if ($key === '') {
            mtrace('run_model_benchmark: no registry_key in custom data; nothing to do.');
            return;
        }

        $registry = $this->registry_row($key);
        $model = trim((string) ($data['model_name'] ?? '')) ?: (string) ($registry->modelkey ?? $key);
        $provider = strtolower(trim((string) ($data['provider'] ?? '')))
            ?: strtolower(trim((string) ($registry->provider ?? '')));

        // Open (or claim) the result row FIRST, so every exit below — including
        // the failures — is visible to the operator who queued the run.
        $runid = trim((string) ($data['runid'] ?? ''));
        if ($runid !== '') {
            if (!model_bench::claim_run($runid)) {
                // Someone already has it, or it is already finished. Re-running
                // would bill a second time and overwrite a real result.
                mtrace("run_model_benchmark: run $runid is not queued; skipping.");
                return;
            }
        } else {
            $runid = model_bench::start_run([
                'harness'        => $harness,
                'sola_function'  => $function,
                'registry_key'   => $key,
                'provider'       => $provider,
                'model_name'     => $model,
                'fixture_set'    => $fixture,
                'quality_metric' => 'rubric_mean',
                'status'         => model_bench::STATUS_RUNNING,
                'createdby'      => (int) ($data['createdby'] ?? 0),
                'params'         => ['samples' => $samples],
            ]);
        }

        // Resolve the spend owner ONCE, before anything can be written. createdby
        // is documented as audit-only and is 0 for CLI/programmatic queues, and
        // log_usage_array(..., 0, ...) would reach get_or_create_conversation(0,
        // SITEID) -- no FK guard -- and insert a conversation owned by nobody.
        $spenduserid = (int) ($data['createdby'] ?? 0);
        if ($spenduserid <= 0) {
            $admin = get_admin();
            $spenduserid = $admin ? (int) $admin->id : 0;
        }

        try {
            $this->run_and_record($runid, $data, [
                'harness'  => $harness,
                'function' => $function,
                'key'      => $key,
                'model'    => $model,
                'provider' => $provider,
                'samples'  => $samples,
                'fixture'  => $fixture,
            ]);
            $this->flush_bench_spend($spenduserid);
        } catch (\Throwable $e) {
            // A benchmark that dies must not leave a 'running' row forever, and
            // must not take cron's whole queue down with it.
            //
            // Flush FIRST: the run may have completed 49 of 50 invoiced calls
            // before throwing, and that money was spent whether or not the run
            // recorded a result. This deliberately widens the "log on the success
            // path only" rule the ancillary writers follow, because there the
            // failing call was the only call.
            $this->flush_bench_spend($spenduserid);
            model_bench::fail_run($runid, 'Benchmark aborted: ' . $e->getMessage());
            mtrace('run_model_benchmark: ' . $e->getMessage());
        }
    }

    /**
     * Everything between a claimed row and a recorded result.
     *
     * @param string $runid
     * @param array $data Raw custom data (for judge/temperature overrides).
     * @param array $ctx Resolved run context: harness, function, key, model, provider, samples, fixture.
     * @return void
     */
    private function run_and_record(string $runid, array $data, array $ctx): void {
        if ($ctx['harness'] !== self::HARNESS_TUTOR_GOLDEN) {
            model_bench::fail_run($runid, 'Harness "' . $ctx['harness'] . '" is not implemented by this task. '
                . 'Supported: ' . self::HARNESS_TUTOR_GOLDEN . '. A result from a different harness would not be '
                . 'comparable with the stored rows, so no number is recorded.');
            return;
        }

        if ($ctx['provider'] === '') {
            model_bench::fail_run($runid, 'Model "' . $ctx['key'] . '" has no provider. '
                . 'Set the Provider field on its row in the model registry (Site administration > Plugins > '
                . 'Local plugins > SOLA > Model registry) — a benchmark has to know which vendor to call.');
            return;
        }

        $hint = $this->credential_hint($ctx['provider'], $ctx['model']);
        if ($hint !== null) {
            model_bench::fail_run($runid, $hint);
            return;
        }

        $prompts = $this->load_prompts($ctx['fixture']);
        if ($prompts === null) {
            model_bench::fail_run($runid, 'Fixture set "' . $ctx['fixture'] . '" could not be read or is malformed. '
                . 'Expected a JSON file with a "prompts" array of {id, category, text} objects, '
                . 'relative to the plugin directory.');
            return;
        }
        $prompts = array_slice($prompts, 0, $ctx['samples']);

        try {
            // enforcespend false, exactly as the CLI harness does: this is
            // operator tooling deliberately measuring a provider, and a learner
            // spend cap must not silently substitute a different model and
            // report its score under the requested model's name. The emergency
            // chat stop still applies — it is a kill switch, not a cap.
            $provider = base_provider::create_for_comparison($ctx['provider'], $ctx['model'], 0, false);
        } catch (\Throwable $e) {
            model_bench::fail_run($runid, $this->provider_failure_message($ctx['provider'], $ctx['model'], $e));
            return;
        }

        $temperature = isset($data['temperature']) && $data['temperature'] !== ''
            ? (float) $data['temperature'] : 0.4;

        $calls = 0;
        $errors = 0;
        $costs = [];
        $ttfts = [];
        $totals = [];
        $answers = [];
        foreach ($prompts as $p) {
            $calls++;
            $result = $this->run_one_call($provider, (string) ($p['text'] ?? ''), $temperature);
            // Accumulate BEFORE the error branch: a call that errored may still
            // have been billed for its prompt.
            $this->accumulate_bench_spend('answer', $result['usage'] ?? null);
            if ($result['error'] !== '') {
                $errors++;
                continue;
            }
            if ($result['cost_cents'] !== null) {
                $costs[] = $result['cost_cents'];
            }
            if ($result['ttft_ms'] !== null) {
                $ttfts[] = $result['ttft_ms'];
            }
            $totals[] = $result['total_latency_ms'];
            $answers[] = ['prompt' => (string) ($p['text'] ?? ''), 'response' => $result['response']];
        }

        // Judge. A judge we cannot build is NOT a failed run: the cost and
        // latency numbers are real and worth keeping. It records quality_n = 0,
        // which puts the row under model_bench's comparability floor, so it can
        // never be mistaken for a quality measurement.
        $rubricsum = 0.0;
        $rubricn = 0;
        $judgenote = '';
        $judgeprovider = trim((string) ($data['judge_provider'] ?? ''))
            ?: self::configured_string('bench_judge_provider', 'claude');
        $judgemodel = trim((string) ($data['judge_model'] ?? ''))
            ?: self::configured_string('bench_judge_model', 'claude-sonnet-4-6');
        $judge = null;
        if (!empty($answers)) {
            $unavailable = $this->credential_hint($judgeprovider, $judgemodel);
            if ($unavailable === null) {
                try {
                    $judge = base_provider::create_for_comparison($judgeprovider, $judgemodel, 0, false);
                } catch (\Throwable $e) {
                    $unavailable = $this->exception_detail($e);
                }
            }
            if ($unavailable !== null) {
                $judgenote = 'Cost and latency measured, but NOT quality: the rubric judge ('
                    . $judgeprovider . ' / ' . $judgemodel . ') was unavailable. ' . $unavailable;
            }
        }
        if ($judge !== null) {
            foreach ($answers as $a) {
                $score = $this->score_one($judge, $a['prompt'], $a['response']);
                if ($score === null) {
                    continue;
                }
                $rubricsum += $score;
                $rubricn++;
            }
            if ($rubricn === 0) {
                $judgenote = 'The rubric judge returned no parseable score for any response, so this run '
                    . 'carries cost and latency only.';
            }
        }

        model_bench::complete_run($runid, [
            'quality_metric'      => 'rubric_mean',
            'quality_raw'         => $rubricn > 0 ? $rubricsum / $rubricn : null,
            'quality_max'         => $rubricn > 0 ? self::RUBRIC_MAX : null,
            'quality_n'           => $rubricn,
            'cost_cents_per_call' => !empty($costs) ? array_sum($costs) / count($costs) : null,
            'p50_ttft_ms'         => model_bench::percentile($ttfts, 50),
            'p95_ttft_ms'         => model_bench::percentile($ttfts, 95),
            'p50_total_ms'        => model_bench::percentile($totals, 50),
            'calls'               => $calls,
            'errors'              => $errors,
            'provider'            => $ctx['provider'],
            'model_name'          => $ctx['model'],
            'fixture_set'         => $ctx['fixture'],
            'fixture_n'           => count($prompts),
            'params'              => [
                'samples'     => $ctx['samples'],
                'temperature' => $temperature,
            ],
            'message'             => $judgenote !== '' ? $judgenote : null,
        ]);

        mtrace(sprintf(
            'run_model_benchmark: %s (%s) rubric %s/%s over %d judged, %d/%d errors, run %s',
            $ctx['model'],
            $ctx['provider'],
            $rubricn > 0 ? number_format($rubricsum / $rubricn, 2) : 'n/a',
            (string) self::RUBRIC_MAX,
            $rubricn,
            $errors,
            $calls,
            $runid
        ));
    }

    /**
     * An admin-configured integer setting, or the shipped default.
     *
     * @param string $name Setting name.
     * @param int $default
     * @return int
     */
    private static function configured_int(string $name, int $default): int {
        $value = get_config('local_ai_course_assistant', $name);
        if ($value === false || trim((string) $value) === '' || (int) $value <= 0) {
            return $default;
        }
        return (int) $value;
    }

    /**
     * An admin-configured string setting, or the shipped default.
     *
     * @param string $name Setting name.
     * @param string $default
     * @return string
     */
    private static function configured_string(string $name, string $default): string {
        $value = trim((string) (get_config('local_ai_course_assistant', $name) ?: ''));
        return $value !== '' ? $value : $default;
    }

    /**
     * One streamed completion, timed. Never throws.
     *
     * @param \local_ai_course_assistant\provider\provider_interface $provider
     * @param string $userprompt
     * @param float $temperature
     * @return array{response: string, ttft_ms: ?int, total_latency_ms: int, cost_cents: ?float, error: string}
     */
    private function run_one_call($provider, string $userprompt, float $temperature): array {
        $start = microtime(true);
        $ttft = null;
        $response = '';
        try {
            $callback = function (string $chunk) use (&$ttft, &$response, $start) {
                if ($ttft === null && $chunk !== '') {
                    $ttft = (int) round((microtime(true) - $start) * 1000);
                }
                $response .= $chunk;
            };
            $provider->chat_completion_stream(self::SYSTEM_PROMPT, [
                ['role' => 'user', 'content' => $userprompt],
            ], $callback, ['temperature' => $temperature]);
            $total = (int) round((microtime(true) - $start) * 1000);

            $usage = $provider->get_last_token_usage();
            $cost = null;
            if (!empty($usage['prompt_tokens']) && isset($usage['completion_tokens']) && !empty($usage['model'])) {
                // Priced through the registry, so a model whose price an admin
                // corrected on the form is benchmarked at the corrected price.
                // A model with no price at all yields null here, not 0.0 —
                // recording a free-looking cost is the bug this release fixes.
                $estimate = token_cost_manager::estimate_cost(
                    (string) $usage['model'],
                    (int) $usage['prompt_tokens'],
                    (int) $usage['completion_tokens'],
                    (int) ($usage['reasoning_tokens'] ?? 0)
                );
                if ($estimate !== null) {
                    $cost = round($estimate * 100, 6);
                }
            }
            return [
                'response'         => $response,
                'ttft_ms'          => $ttft,
                'total_latency_ms' => $total,
                'cost_cents'       => $cost,
                'usage'            => is_array($usage) ? $usage : null,
                'error'            => '',
            ];
        } catch (\Throwable $e) {
            // Not a hard null: claude_provider populates prompt/cached tokens from
            // the stream's opening event before any mid-stream failure, and those
            // tokens are invoiced. Hard-coding null here would discard exactly the
            // partially-billed calls this aggregate exists to preserve.
            $partial = null;
            try {
                $u = $provider->get_last_token_usage();
                $partial = (is_array($u) && !empty($u)) ? $u : null;
            } catch (\Throwable $ignored) {
                $partial = null;
            }
            return [
                'usage'            => $partial,
                'response'         => '',
                'ttft_ms'          => null,
                'total_latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'cost_cents'       => null,
                'error'            => mb_substr((string) $e->getMessage(), 0, 200),
            ];
        }
    }

    /**
     * Fold one call's usage into the run's aggregate.
     *
     * @param string     $bucket 'answer' or 'judge'.
     * @param array|null $usage  Canonical usage array, or null when nothing was billed.
     * @return void
     */
    private function accumulate_bench_spend(string $bucket, ?array $usage): void {
        if (empty($usage) || empty($usage['model'])) {
            return;
        }
        if (!isset($this->benchspend[$bucket])) {
            $this->benchspend[$bucket] = [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'cached_tokens' => 0,
                // Null, not 0. Null means "this provider does not report thinking";
                // 0 would claim it reported zero. claude_provider omits the key
                // entirely, so defaulting to 0 here would misreport every judge run.
                'reasoning_tokens' => null,
                'model' => (string) $usage['model'],
                'provider' => $usage['provider'] ?? null,
                'calls' => 0,
            ];
        }
        $acc = &$this->benchspend[$bucket];
        $acc['calls']++;
        $acc['prompt_tokens'] += (int) ($usage['prompt_tokens'] ?? 0);
        $acc['completion_tokens'] += (int) ($usage['completion_tokens'] ?? 0);
        // Anthropic reports cache_read_tokens, OpenAI cached_tokens.
        $acc['cached_tokens'] += (int) ($usage['cached_tokens'] ?? $usage['cache_read_tokens'] ?? 0);
        if (array_key_exists('reasoning_tokens', $usage) && $usage['reasoning_tokens'] !== null) {
            $acc['reasoning_tokens'] = (int) $acc['reasoning_tokens'] + (int) $usage['reasoning_tokens'];
        }
    }

    /**
     * Write the run's accumulated spend as one ledger row per bucket.
     *
     * Called from the normal tail AND from execute()'s catch, because a run that
     * dies at prompt 49 of 50 has already been billed for 49 calls.
     *
     * @param int $userid Resolved, non-zero.
     * @return void
     */
    private function flush_bench_spend(int $userid): void {
        if ($userid <= 0) {
            // Never write with userid 0: get_or_create_conversation() has no FK
            // guard and would insert a junk conversation owned by nobody.
            mtrace('  SOLA: benchmark spend not logged (no resolvable user).');
            $this->benchspend = [];
            return;
        }
        foreach ($this->benchspend as $bucket => $acc) {
            if ((int) $acc['calls'] < 1 || empty($acc['model'])) {
                continue;
            }
            \local_ai_course_assistant\conversation_manager::log_usage_array(
                $acc,
                $userid,
                0,
                'model_bench',
                '[Benchmark] ' . $bucket . ': ' . (int) $acc['calls'] . ' call(s)'
            );
        }
        $this->benchspend = [];
    }

    /**
     * Judge one response; null when it produced no usable score.
     *
     * @param \local_ai_course_assistant\provider\provider_interface $judge
     * @param string $prompt
     * @param string $response
     * @return float|null Rubric total out of RUBRIC_MAX.
     */
    private function score_one($judge, string $prompt, string $response): ?float {
        if (trim($response) === '') {
            return null;
        }
        try {
            $out = $judge->chat_completion(self::JUDGE_PROMPT, [
                ['role' => 'user', 'content' => "STUDENT PROMPT:\n" . $prompt . "\n\nTUTOR RESPONSE:\n" . $response],
            ], ['temperature' => 0.0]);
            // Read here, not in the caller's loop. The empty-response early return
            // above skips the judge entirely, so accumulating from the caller would
            // count the PREVIOUS response's tokens for a call that never happened --
            // the same stale-usage defect as claude_provider's missing reset, one
            // layer up. Judge spend appeared in no number at all before this,
            // including model_bench's own cost_cents.
            $this->accumulate_bench_spend('judge', $judge->get_last_token_usage());
        } catch (\Throwable $e) {
            return null;
        }
        $out = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim((string) $out));
        $parsed = json_decode((string) $out, true);
        if (!is_array($parsed)) {
            return null;
        }
        $total = (float) ($parsed['socratic'] ?? 0) + (float) ($parsed['accuracy'] ?? 0)
            + (float) ($parsed['tone'] ?? 0);
        // All three dimensions missing means the judge answered something else.
        return $total > 0 ? $total : null;
    }

    /**
     * The registry row for a key, or null.
     *
     * @param string $key
     * @return \stdClass|null
     */
    private function registry_row(string $key): ?\stdClass {
        global $DB;
        $row = $DB->get_record(model_registry::TABLE_MODELS, ['modelkey' => $key]);
        return $row ?: null;
    }

    /**
     * Why a provider is unreachable, phrased as the operator's next action.
     *
     * Since v6.9.7 create_for_comparison() refuses to send one vendor's key to
     * another rather than producing an opaque HTTP 400 — correct, but the
     * refusal alone does not tell an admin with no shell what to do. This
     * pre-checks the same three conditions the factory checks and answers with
     * the exact row to paste.
     *
     * @param string $providerid
     * @param string $model
     * @return string|null Null when credentials are reachable.
     */
    private function credential_hint(string $providerid, string $model): ?string {
        $providerid = strtolower(trim($providerid));
        if ($providerid === '' || in_array($providerid, self::KEYLESS_PROVIDERS, true)) {
            return null;
        }
        if ($this->has_comparison_row($providerid)) {
            return null;
        }
        $siteprovider = strtolower((string) (get_config('local_ai_course_assistant', 'provider') ?: ''));
        $sitekey = (string) (get_config('local_ai_course_assistant', 'apikey') ?: '');
        if ($providerid === $siteprovider && $sitekey !== '') {
            return null;
        }
        return 'No credentials are reachable for provider "' . $providerid . '", so ' . $model
            . ' cannot be benchmarked. Add a row to the "Comparison providers" setting '
            . '(Site administration > Plugins > Local plugins > SOLA), one per line, in the form '
            . 'provider|apikey|model[|temperature|baseurl] — for this run: '
            . $providerid . '|<' . $providerid . ' API key>|' . $model
            . ' . SOLA refuses to send the site provider\'s key to a different vendor, which is why '
            . 'the run stopped here instead of failing against the API.';
    }

    /**
     * Is there a comparison_providers row for this provider id?
     *
     * Parses the same admin textarea base_provider::lookup_comparison_row reads
     * (that method is private): label|apikey|model[|temperature|baseurl].
     *
     * @param string $providerid
     * @return bool
     */
    private function has_comparison_row(string $providerid): bool {
        $raw = (string) (get_config('local_ai_course_assistant', 'comparison_providers') ?: '');
        foreach (preg_split("/\r?\n/", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 3 || $parts[1] === '') {
                continue;
            }
            if (strtolower($parts[0]) === $providerid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Message for a provider that could not be built at all.
     *
     * @param string $providerid
     * @param string $model
     * @param \Throwable $e
     * @return string
     */
    private function provider_failure_message(string $providerid, string $model, \Throwable $e): string {
        return 'Could not build provider "' . $providerid . '" for model ' . $model . '. '
            . $this->exception_detail($e)
            . ' If this is a credential problem, add a "Comparison providers" row: '
            . $providerid . '|<' . $providerid . ' API key>|' . $model;
    }

    /**
     * The useful part of a moodle_exception — its debuginfo, where the factory
     * puts the actual reason, rather than the generic lang string.
     *
     * @param \Throwable $e
     * @return string
     */
    private function exception_detail(\Throwable $e): string {
        if ($e instanceof \moodle_exception && !empty($e->debuginfo)) {
            return (string) $e->debuginfo;
        }
        return (string) $e->getMessage();
    }

    /**
     * Load a fixture set. Read-only; nothing here writes to disk.
     *
     * @param string $relpath Plugin-relative (or absolute) path to a
     *                        tutor_prompts.json-shaped file.
     * @return array<int, array<string, mixed>>|null Null when unreadable or malformed.
     */
    private function load_prompts(string $relpath): ?array {
        global $CFG;

        $base = $CFG->dirroot . '/local/ai_course_assistant/';
        $path = $relpath;
        if ($path === '' || $path[0] !== '/') {
            $path = $base . ltrim($path, '/');
        }
        // Confine reads to the plugin directory: the fixture path can come from
        // an admin form, and a benchmark must not become a file-disclosure
        // primitive for anything outside it.
        $real = realpath($path);
        $realbase = realpath($base);
        if ($real === false || $realbase === false || !str_starts_with($real, $realbase)) {
            return null;
        }
        $raw = file_get_contents($real);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['prompts']) || !is_array($decoded['prompts'])) {
            return null;
        }
        return array_values($decoded['prompts']);
    }
}
