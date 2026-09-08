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
 * Benchmark result store (v7.4.0) — persistence + query layer over
 * local_ai_course_assistant_bench.
 *
 * WHY THIS EXISTS. "Similar quality" was, until this table, a claim in a
 * markdown draft. admin/cli/run_tutor_golden.php computed the whole decision
 * row — rubric mean out of 15, average cents/call, P50/P95 TTFT, error count,
 * Pareto flag — wrote it to a CSV under dataroot and discarded it. Nothing in
 * the plugin could read a benchmark result, so:
 *
 *   - llm_optimizer can only rank provider+model pairs that already have >= 30
 *     live rated rows in the trailing 30 days. It can rank what you already
 *     run; it can never recommend a model you have not deployed. This table is
 *     how a candidate model gets evidence without being put in front of
 *     learners first.
 *   - the measurement needed shell access, so in practice it happened when an
 *     engineer felt like it, and its result lived in a file nobody re-read.
 *
 * TWO FIELDS ARE LOAD-BEARING, and both are about refusing false comparisons:
 *
 *   quality_n  — how many items were actually scored. A 3-prompt smoke run and
 *                a 50-prompt golden run both produce "a rubric mean out of 15",
 *                and the small one is noise. {@see latest_by_function()} filters
 *                on it rather than trusting the caller to notice.
 *   fixture_n / fixture_set / harness — which question set produced the number.
 *                A mean over the domain-tagged set is not comparable to a mean
 *                over tutor_prompts.json, however similar the two numbers look.
 *   plugin_release — the system prompt and the rubric ship in the code and
 *                change between releases, so a score is only interpretable
 *                alongside the release that produced it. Stamped at start_run().
 *
 * Nothing here writes a file. Results are rows.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_bench {
    /** @var string Benchmark result table. */
    public const TABLE = model_registry::TABLE_BENCH;

    /** @var string Row created, run not started (the web trigger queued it). */
    public const STATUS_QUEUED = 'queued';

    /** @var string A runner has claimed the row and is calling the model. */
    public const STATUS_RUNNING = 'running';

    /** @var string Finished with results. */
    public const STATUS_COMPLETE = 'complete';

    /** @var string Finished without results; `message` says why. */
    public const STATUS_FAILED = 'failed';

    /**
     * Minimum scored items before a run may be compared against another run.
     *
     * Five is deliberately low — it is a floor against nonsense (a 1-prompt
     * "did it answer at all" probe ranking above a 50-prompt golden run),
     * not a claim that five prompts are enough to choose a production model.
     * Callers wanting a stricter bar pass their own.
     *
     * @var int
     */
    public const MIN_QUALITY_N = 5;

    /**
     * Fields a caller may set when opening a run.
     *
     * @var string[]
     */
    private const START_FIELDS = [
        'harness', 'sola_function', 'registry_key', 'provider', 'model_name',
        'fixture_set', 'judge_provider', 'judge_model', 'quality_metric',
    ];

    /**
     * Fields a caller may set when closing a run.
     *
     * @var string[]
     */
    private const RESULT_FLOATS = ['quality_raw', 'quality_max', 'cost_cents_per_call'];

    /**
     * Integer result fields.
     *
     * @var string[]
     */
    private const RESULT_INTS = ['quality_n', 'p50_ttft_ms', 'p95_ttft_ms', 'p50_total_ms', 'calls', 'errors'];

    /**
     * Open a benchmark run and return its opaque run id.
     *
     * The row exists from this moment on, which is the point: a web-triggered
     * run is visible on the admin page as `queued` before any model is called,
     * so an operator who queues a benchmark and then watches cron do nothing
     * can see that the request was recorded. A run that dies mid-flight leaves
     * a `running` row rather than nothing at all.
     *
     * @param array $meta Any of: harness, sola_function, registry_key, provider,
     *                    model_name, fixture_set, fixture_n, params (array or
     *                    JSON string), judge_provider, judge_model,
     *                    quality_metric, status (queued|running), createdby,
     *                    runid (supply your own; one is generated otherwise).
     * @return string The runid, to pass to complete_run()/fail_run().
     * @throws \coding_exception When status is not queued or running.
     */
    public static function start_run(array $meta): string {
        global $DB;

        $status = strtolower(trim((string) ($meta['status'] ?? self::STATUS_QUEUED)));
        if (!in_array($status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true)) {
            // A run cannot be born complete: complete_run() is what records a
            // result, and it is the only thing that computes quality_score.
            throw new \coding_exception(
                'model_bench::start_run status must be queued or running, got "' . $status . '"');
        }

        $record = new \stdClass();
        foreach (self::START_FIELDS as $field) {
            $value = isset($meta[$field]) ? trim((string) $meta[$field]) : '';
            $record->$field = $value === '' ? null : $value;
        }
        if ($record->registry_key !== null) {
            $record->registry_key = strtolower($record->registry_key);
        }
        $record->fixture_n = isset($meta['fixture_n']) && $meta['fixture_n'] !== ''
            ? (int) $meta['fixture_n'] : null;
        $record->params = self::encode_params($meta['params'] ?? null);
        $record->plugin_release = self::plugin_release();
        $record->status = $status;
        $record->message = null;
        $record->createdby = !empty($meta['createdby']) ? (int) $meta['createdby'] : null;
        $record->timecreated = time();
        $record->timecompleted = null;
        $record->runid = self::generate_runid($meta['runid'] ?? null);

        $DB->insert_record(self::TABLE, $record);
        return $record->runid;
    }

    /**
     * Move a queued run to running, refusing if someone else already has it.
     *
     * Moodle's own adhoc-task locking is the real mutual exclusion; this is a
     * second, cheaper signal so the admin page can distinguish "cron has not
     * picked this up yet" from "cron is working on it", and so a re-queued
     * duplicate does not double-bill. Read-check-write, not atomic: a lost race
     * costs one duplicated benchmark, never a corrupted row.
     *
     * @param string $runid
     * @return bool True if this caller now owns the run.
     */
    public static function claim_run(string $runid): bool {
        global $DB;

        $row = $DB->get_record(self::TABLE, ['runid' => $runid], 'id, status');
        if (!$row || (string) $row->status !== self::STATUS_QUEUED) {
            return false;
        }
        $DB->set_field(self::TABLE, 'status', self::STATUS_RUNNING, ['id' => $row->id]);
        return true;
    }

    /**
     * Record a finished run's aggregate.
     *
     * quality_score is derived here and only here: raw / max, so a rubric mean
     * of 14.56 out of 15 and a recall@3 of 0.725 land on one 0..1 axis and can
     * share a chart. When max is missing or zero the score stays null — an
     * un-normalizable metric must read as "unknown", not as 0.0, which would
     * rank the model last.
     *
     * @param string $runid Run to close.
     * @param array $result Any of: quality_metric, quality_raw, quality_max,
     *                      quality_n, cost_cents_per_call, p50_ttft_ms,
     *                      p95_ttft_ms, p50_total_ms, calls, errors, message,
     *                      provider, model_name, fixture_set, fixture_n, params.
     * @return void
     * @throws \dml_exception When the runid does not exist.
     */
    public static function complete_run(string $runid, array $result): void {
        global $DB;

        $existing = $DB->get_record(self::TABLE, ['runid' => $runid], '*', MUST_EXIST);

        $record = new \stdClass();
        $record->id = (int) $existing->id;
        foreach (self::RESULT_FLOATS as $field) {
            if (array_key_exists($field, $result)) {
                $record->$field = self::nullable_float($result[$field]);
            }
        }
        foreach (self::RESULT_INTS as $field) {
            if (array_key_exists($field, $result)) {
                $record->$field = $result[$field] === null || $result[$field] === ''
                    ? null : (int) $result[$field];
            }
        }
        foreach (['quality_metric', 'provider', 'model_name', 'fixture_set', 'message'] as $field) {
            if (array_key_exists($field, $result)) {
                $value = trim((string) $result[$field]);
                $record->$field = $value === '' ? null : $value;
            }
        }
        if (array_key_exists('fixture_n', $result)) {
            $record->fixture_n = $result['fixture_n'] === null || $result['fixture_n'] === ''
                ? null : (int) $result['fixture_n'];
        }
        if (array_key_exists('params', $result)) {
            $record->params = self::encode_params($result['params']);
        }

        // Fall back to what is already stored, so a partial second write (an
        // operator filling in a cost that was missing) cannot blank the score
        // it is not touching.
        $raw = $record->quality_raw ?? self::nullable_float($existing->quality_raw);
        $max = $record->quality_max ?? self::nullable_float($existing->quality_max);
        $record->quality_raw = $raw;
        $record->quality_max = $max;
        $record->quality_score = ($raw === null || $max === null || (float) $max == 0.0)
            ? null
            : (float) $raw / (float) $max;

        $record->status = self::STATUS_COMPLETE;
        $record->timecompleted = time();

        $DB->update_record(self::TABLE, $record);
    }

    /**
     * Record a run that produced no usable result, with the reason.
     *
     * The message is the whole value of this method. A failed benchmark is a
     * normal outcome — most often "there are no credentials for that vendor" —
     * and the operator's next action is entirely determined by which failure it
     * was, so the row has to carry it. {@see history()} returns failed runs for
     * exactly this reason.
     *
     * @param string $runid
     * @param string $message Operator-facing reason. Truncated to 1000 chars.
     * @return void
     * @throws \dml_exception When the runid does not exist.
     */
    public static function fail_run(string $runid, string $message): void {
        global $DB;

        $existing = $DB->get_record(self::TABLE, ['runid' => $runid], 'id', MUST_EXIST);

        $record = new \stdClass();
        $record->id = (int) $existing->id;
        $record->status = self::STATUS_FAILED;
        $record->message = mb_substr(trim($message), 0, 1000);
        $record->timecompleted = time();
        $DB->update_record(self::TABLE, $record);
    }

    /**
     * The newest comparable run per model for one SOLA function.
     *
     * This is what the recommendation engine and the admin card read, so it
     * applies the comparability rules rather than leaving them to each caller:
     *
     *   - only `complete` runs,
     *   - only runs with quality_n >= $minqualityn, applied BEFORE "newest per
     *     model". A 3-prompt smoke run must not shadow last week's 50-prompt
     *     result for the same model — hiding real evidence behind noise is
     *     worse than either showing or ignoring the noise.
     *   - one row per model. Identity is the registry key when the run was
     *     attributed to one, else provider + model name, so two models served
     *     by the same provider stay two rows. (The reason this is not a
     *     get_records_sql with model_name first: that keys the array by
     *     model_name and silently collapses the same model served by two
     *     providers, which is the get_records_sql first-column trap that has
     *     produced four separate defects in this plugin.)
     *
     * Each row carries `comparable_group` = harness/fixture_set/fixture_n. Two
     * rows with different groups answer different questions and must not be
     * subtracted from one another; the caller renders the group so a human can
     * see it. plugin_release is returned separately rather than folded into the
     * group: prompts do drift between releases, but grouping on it would empty
     * the card on the first release after a benchmark, which reads as "no
     * evidence" rather than "evidence from v7.3".
     *
     * @param string $function SOLA function (chat, quiz, classifier, embedding, rerank, judge).
     * @param int|null $minqualityn Comparability floor. Null takes the
     *                 `bench_min_quality_n` admin setting, falling back to
     *                 MIN_QUALITY_N — the floor is a judgement call about how
     *                 much evidence is enough, so it has to be adjustable from
     *                 a form rather than by editing this constant.
     * @return array<int, array<string, mixed>> Newest first.
     */
    public static function latest_by_function(string $function, ?int $minqualityn = null): array {
        global $DB;

        $floor = $minqualityn === null ? self::configured_floor() : (int) $minqualityn;

        // Leads with the unique b.id, and uses a recordset anyway: the dedupe
        // to one-row-per-model happens in PHP because model identity is
        // COALESCE-shaped (registry key, else provider+model) and expressing
        // that as a portable GROUP BY across MySQL and Postgres is more
        // fragile than a loop over an ordered read.
        $sql = "SELECT b.*
                  FROM {" . self::TABLE . "} b
                 WHERE b.sola_function = :fn
                   AND b.status = :complete
                   AND b.quality_n IS NOT NULL
                   AND b.quality_n >= :floor
              ORDER BY b.timecreated DESC, b.id DESC";
        $params = ['fn' => $function, 'complete' => self::STATUS_COMPLETE, 'floor' => $floor];

        $out = [];
        $seen = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $identity = self::model_identity($row);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $out[] = self::export_row($row);
        }
        $rs->close();

        return $out;
    }

    /**
     * Every run for one model, newest first, whatever its status.
     *
     * Matches on the registry key OR the raw model name, because runs recorded
     * before a model was registered carry only the latter, and an operator
     * looking at a model's history wants both.
     *
     * @param string $modelkey Registry key or model name.
     * @param string|null $function Restrict to one SOLA function, or null for all.
     * @param int $limit Maximum rows, 0 for no limit.
     * @return array<int, array<string, mixed>>
     */
    public static function history(string $modelkey, ?string $function = null, int $limit = 100): array {
        global $DB;

        $key = strtolower(trim($modelkey));
        $where = "(LOWER(b.registry_key) = :key1 OR LOWER(b.model_name) = :key2)";
        $params = ['key1' => $key, 'key2' => $key];
        if ($function !== null && trim($function) !== '') {
            $where .= " AND b.sola_function = :fn";
            $params['fn'] = trim($function);
        }
        $sql = "SELECT b.*
                  FROM {" . self::TABLE . "} b
                 WHERE $where
              ORDER BY b.timecreated DESC, b.id DESC";

        $out = [];
        $rs = $DB->get_recordset_sql($sql, $params, 0, $limit > 0 ? $limit : 0);
        foreach ($rs as $row) {
            $out[] = self::export_row($row);
        }
        $rs->close();

        return $out;
    }

    /**
     * One run by id, or null.
     *
     * @param string $runid
     * @return array<string, mixed>|null
     */
    public static function get_run(string $runid): ?array {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['runid' => $runid]);
        return $row ? self::export_row($row) : null;
    }

    /**
     * The comparability floor an admin configured, or the shipped default.
     *
     * @return int
     */
    public static function configured_floor(): int {
        $configured = get_config('local_ai_course_assistant', 'bench_min_quality_n');
        if ($configured === false || trim((string) $configured) === '') {
            return self::MIN_QUALITY_N;
        }
        // A floor of 0 would let a 1-prompt probe rank against a 50-prompt run,
        // which is the whole failure this guards; treat it as "use the default".
        $value = (int) $configured;
        return $value > 0 ? $value : self::MIN_QUALITY_N;
    }

    /**
     * Nearest-rank percentile, the same definition the CLI harness uses.
     *
     * Lives here so the adhoc task and the CLI cannot drift into two different
     * P95s of the same data.
     *
     * @param array $values
     * @param int $p 0..100
     * @return int|float|null Null when there is nothing to take a percentile of.
     */
    public static function percentile(array $values, int $p) {
        if (empty($values)) {
            return null;
        }
        sort($values);
        $idx = (int) ceil(($p / 100) * count($values)) - 1;
        return $values[max(0, min(count($values) - 1, $idx))];
    }

    /**
     * Stable identity for "the same model" across runs.
     *
     * @param \stdClass $row
     * @return string
     */
    private static function model_identity(\stdClass $row): string {
        $key = strtolower(trim((string) ($row->registry_key ?? '')));
        if ($key !== '') {
            return 'key:' . $key;
        }
        return 'pm:' . strtolower(trim((string) ($row->provider ?? '')))
            . '|' . strtolower(trim((string) ($row->model_name ?? '')));
    }

    /**
     * DB row to the public array shape.
     *
     * @param \stdClass $row
     * @return array<string, mixed>
     */
    private static function export_row(\stdClass $row): array {
        $tofloat = static function ($v) {
            return $v === null || $v === '' ? null : (float) $v;
        };
        $toint = static function ($v) {
            return $v === null || $v === '' ? null : (int) $v;
        };
        $params = [];
        if (!empty($row->params)) {
            $decoded = json_decode((string) $row->params, true);
            $params = is_array($decoded) ? $decoded : [];
        }
        return [
            'id'                  => (int) $row->id,
            'runid'               => (string) $row->runid,
            'harness'             => $row->harness !== null ? (string) $row->harness : null,
            'sola_function'       => $row->sola_function !== null ? (string) $row->sola_function : null,
            'registry_key'        => $row->registry_key !== null ? (string) $row->registry_key : null,
            'provider'            => $row->provider !== null ? (string) $row->provider : null,
            'model_name'          => $row->model_name !== null ? (string) $row->model_name : null,
            'fixture_set'         => $row->fixture_set !== null ? (string) $row->fixture_set : null,
            'fixture_n'           => $toint($row->fixture_n),
            'params'              => $params,
            'judge_provider'      => $row->judge_provider !== null ? (string) $row->judge_provider : null,
            'judge_model'         => $row->judge_model !== null ? (string) $row->judge_model : null,
            'quality_metric'      => $row->quality_metric !== null ? (string) $row->quality_metric : null,
            'quality_raw'         => $tofloat($row->quality_raw),
            'quality_max'         => $tofloat($row->quality_max),
            'quality_score'       => $tofloat($row->quality_score),
            'quality_n'           => $toint($row->quality_n),
            'cost_cents_per_call' => $tofloat($row->cost_cents_per_call),
            'p50_ttft_ms'         => $toint($row->p50_ttft_ms),
            'p95_ttft_ms'         => $toint($row->p95_ttft_ms),
            'p50_total_ms'        => $toint($row->p50_total_ms),
            'calls'               => $toint($row->calls),
            'errors'              => $toint($row->errors),
            'plugin_release'      => $row->plugin_release !== null ? (string) $row->plugin_release : null,
            'status'              => (string) $row->status,
            'message'             => $row->message !== null ? (string) $row->message : null,
            'createdby'           => $toint($row->createdby),
            'timecreated'         => (int) $row->timecreated,
            'timecompleted'       => $toint($row->timecompleted),
            // Rows from different groups are not each other's baseline.
            'comparable_group'    => implode('/', [
                (string) ($row->harness ?? ''),
                (string) ($row->fixture_set ?? ''),
                (string) ($row->fixture_n ?? ''),
            ]),
        ];
    }

    /**
     * Params to a JSON string, or null.
     *
     * @param mixed $params Array, JSON string, or null.
     * @return string|null
     */
    private static function encode_params($params): ?string {
        if ($params === null || $params === '' || $params === []) {
            return null;
        }
        if (is_string($params)) {
            return $params;
        }
        $encoded = json_encode($params);
        return $encoded === false ? null : $encoded;
    }

    /**
     * Cast to float, preserving null/'' as null.
     *
     * @param mixed $value
     * @return float|null
     */
    private static function nullable_float($value): ?float {
        return $value === null || $value === '' ? null : (float) $value;
    }

    /**
     * A unique run id that fits the 40-char column.
     *
     * @param string|null $supplied Caller-supplied id, if any.
     * @return string
     */
    private static function generate_runid(?string $supplied): string {
        $supplied = trim((string) $supplied);
        if ($supplied !== '') {
            return mb_substr($supplied, 0, 40);
        }
        // 36 chars, collision-free without a round trip to check.
        return \core\uuid::generate();
    }

    /**
     * The plugin release string to stamp on a run.
     *
     * Read from version.php rather than from config, because config_plugins
     * stores only the numeric version and the release string is what a human
     * reading a benchmark row recognizes.
     *
     * @return string|null
     */
    private static function plugin_release(): ?string {
        global $CFG;
        static $release = false;
        if ($release !== false) {
            return $release;
        }
        $release = null;
        $file = $CFG->dirroot . '/local/ai_course_assistant/version.php';
        if (is_readable($file)) {
            $plugin = new \stdClass();
            include($file);
            if (!empty($plugin->release)) {
                $release = mb_substr((string) $plugin->release, 0, 20);
            }
        }
        return $release;
    }
}
