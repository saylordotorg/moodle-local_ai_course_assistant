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

use local_ai_course_assistant\embedding_provider\base_embedding_provider;
use local_ai_course_assistant\task\migrate_course_embeddings;

/**
 * Per-course migration to a new embedding model, queued from the web.
 *
 * WHY THIS IS NOT ONE COMMAND
 *
 * Adopting a new embedding model (voyage-4-large at 2048, say) invalidates
 * every stored vector: rag_retriever refuses to score a query against chunks
 * from a different embedding space, so the moment the live settings change,
 * every course retrieves NOTHING until it has been re-embedded. On a
 * production-shaped site that is hours of billable embedding work, and a single
 * command that fails at course nine leaves eight courses migrated, five not,
 * and no way to resume except starting over.
 *
 * So the migration is one adhoc task per course:
 *
 *  - it parallelizes across cron runs (Moodle runs several adhoc tasks per
 *    pass, and more with more cron workers);
 *  - a failure retries ONE course, not the whole corpus — Moodle's own adhoc
 *    retry/backoff does that for free;
 *  - progress is visible per course, and it is MEASURED rather than tracked:
 *    the state of a course is derived from the chunk rows themselves, so there
 *    is no separate progress record that can disagree with reality;
 *  - it is resumable and idempotent. A course re-queued halfway through skips
 *    the chunks already carrying the target model (content_indexer's hash check
 *    compares the model too) and embeds only the rest.
 *
 * WHY THE OLD INDEX KEEPS SERVING
 *
 * The task re-embeds in SHADOW mode: new vectors are written alongside the
 * existing ones and the per-chunk upsert deletes only rows already recorded
 * against the target model. The retriever skips rows whose embedding model is
 * not comparable with the query's, so during the migration each course holds
 * two vector sets and serves from the one the live settings name. Nothing is
 * deleted before its replacement exists.
 *
 * The live embedding settings are NEVER changed by this code. The operator
 * flips them when the page reports every course complete; retrieval switches
 * over on save, in one step, with the new vectors already in place. Afterwards
 * {@see purge_superseded()} removes the old vectors — and only those that have
 * a live-model counterpart, so it can never leave a chunk with no vector.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class embedding_migration {
    /** @var string Chunk table. */
    public const TABLE = 'local_ai_course_assistant_chunks';

    /** @var string Setting: target provider ('' = keep the live provider). */
    public const SETTING_PROVIDER = 'embed_migration_target_provider';

    /** @var string Setting: target model ('' = no migration configured). */
    public const SETTING_MODEL = 'embed_migration_target_model';

    /** @var string Setting: target width (0 = the model's native width). */
    public const SETTING_DIMENSIONS = 'embed_migration_target_dimensions';

    /** @var string Setting: target provider's key ('' = reuse the live key). */
    public const SETTING_APIKEY = 'embed_migration_target_apikey';

    /** @var string Course has chunks, none of them at the target model. */
    public const STATE_NOTSTARTED = 'notstarted';

    /** @var string Some chunks at the target model, some not. */
    public const STATE_PARTIAL = 'partial';

    /** @var string Every chunk at the target model. */
    public const STATE_COMPLETE = 'complete';

    /** @var string A task exists for this course and cron has not started it. */
    public const STATE_QUEUED = 'queued';

    /** @var string A task for this course is running now. */
    public const STATE_RUNNING = 'running';

    /** @var string Nothing indexed, so nothing to migrate. */
    public const STATE_NOINDEX = 'noindex';

    /**
     * Provider options for the target-provider setting.
     *
     * Kept in step with base_embedding_provider::create_from_config()'s switch.
     * The empty option means "the provider already configured", which is the
     * common case: most migrations change the model or the width, not the
     * vendor.
     *
     * @return array<string, string>
     */
    public static function provider_options(): array {
        return [
            ''       => get_string('settings:embed_migration_provider_inherit', 'local_ai_course_assistant'),
            'openai' => 'openai',
            'voyage' => 'voyage',
            'ollama' => 'ollama',
        ];
    }

    /**
     * The configured migration target, and the settings serving retrieval now.
     *
     * @return array{configured: bool, provider: string, model: string,
     *               dimensions: int, apikey: string, liveprovider: string,
     *               livemodel: string, livedimensions: int, sameaslive: bool}
     */
    public static function target(): array {
        $liveprovider = (string) (get_config('local_ai_course_assistant', 'embed_provider') ?: 'openai');
        $livemodel = trim((string) (get_config('local_ai_course_assistant', 'embed_model') ?: ''));
        $rawlivedim = get_config('local_ai_course_assistant', 'embed_dimensions');
        $livedimensions = ($rawlivedim === false || $rawlivedim === '') ? 0 : (int) $rawlivedim;

        $model = trim((string) (get_config('local_ai_course_assistant', self::SETTING_MODEL) ?: ''));
        $provider = trim((string) (get_config('local_ai_course_assistant', self::SETTING_PROVIDER) ?: ''));
        $rawdim = get_config('local_ai_course_assistant', self::SETTING_DIMENSIONS);
        $dimensions = ($rawdim === false || $rawdim === '') ? 0 : (int) $rawdim;
        $apikey = trim((string) (get_config('local_ai_course_assistant', self::SETTING_APIKEY) ?: ''));

        $effectiveprovider = $provider !== '' ? $provider : $liveprovider;

        return [
            'configured'     => $model !== '',
            'provider'       => $effectiveprovider,
            'model'          => $model,
            'dimensions'     => $dimensions,
            'apikey'         => $apikey,
            'liveprovider'   => $liveprovider,
            'livemodel'      => $livemodel,
            'livedimensions' => $livedimensions,
            // Not an error: re-embedding in place is a legitimate thing to want
            // (a corpus indexed at the wrong width, say). The page says so
            // rather than pretending a second index is being built.
            'sameaslive'     => $model !== '' && $model === $livemodel
                && $effectiveprovider === $liveprovider && $dimensions === $livedimensions,
        ];
    }

    /**
     * The config overrides that build a provider for the target.
     *
     * Only non-empty values are included, so an unset target provider or key
     * falls through to the live setting rather than blanking it.
     *
     * @param array|null $target Result of {@see target()}, or null to read it.
     * @return array<string, mixed>
     */
    public static function provider_overrides(?array $target = null): array {
        $target = $target ?? self::target();
        $overrides = ['embed_model' => $target['model']];
        if ($target['provider'] !== '') {
            $overrides['embed_provider'] = $target['provider'];
        }
        if ($target['apikey'] !== '') {
            $overrides['embed_apikey'] = $target['apikey'];
        }
        // 0 is meaningful (use the model's native width), so it is always sent.
        $overrides['embed_dimensions'] = (int) $target['dimensions'];
        // The query-side model must not be inherited: it names a model in the
        // OLD space, and a provider built with it would record the wrong model
        // as the producer of the query vector.
        $overrides['embed_query_model'] = '';

        return $overrides;
    }

    /**
     * Build a provider for the migration target.
     *
     * @param array|null $target
     * @return base_embedding_provider
     * @throws \moodle_exception When no target is configured, or the provider is unknown.
     */
    public static function target_provider(?array $target = null): base_embedding_provider {
        $target = $target ?? self::target();
        if (!$target['configured']) {
            throw new \moodle_exception(
                'chat:error_notconfigured',
                'local_ai_course_assistant',
                '',
                null,
                'no embedding migration target is configured (' . self::SETTING_MODEL . ' is empty)'
            );
        }
        return base_embedding_provider::create_from_config(self::provider_overrides($target));
    }

    /**
     * Per-course migration state, measured from the chunk rows.
     *
     * Counts CHUNK IDENTITIES (course + module + chunk index), not rows. During
     * a shadow migration a course holds two rows per chunk — the vector serving
     * retrieval and its replacement — so counting rows would report a finished
     * course as 50% migrated and an operator would never be told it was safe to
     * switch over.
     *
     * Every course that holds chunks appears, whether or not it is visible or
     * has enrolments: an unmigrated index is an unmigrated index, and hiding a
     * hidden course's chunks would report the migration complete while part of
     * the corpus was still in the old space.
     *
     * @param array|null $target
     * @return array<int, array{courseid: int, fullname: string, chunks: int,
     *               migrated: int, remaining: int, pct: int, state: string}>
     */
    public static function course_states(?array $target = null): array {
        global $DB;

        $target = $target ?? self::target();
        if (!$target['configured']) {
            return [];
        }
        $queued = self::queued_courseids();

        $total = self::identity_counts(null);
        $migrated = self::identity_counts($target['model']);
        if (empty($total)) {
            return [];
        }

        // Names in one read, keyed on the unique id.
        [$insql, $params] = $DB->get_in_or_equal(array_keys($total), SQL_PARAMS_NAMED, 'cid');
        $names = [];
        $rs = $DB->get_recordset_select('course', "id {$insql}", $params, 'fullname ASC, id ASC', 'id, fullname');
        foreach ($rs as $row) {
            $names[(int) $row->id] = (string) $row->fullname;
        }
        $rs->close();

        $out = [];
        foreach ($names as $id => $fullname) {
            $chunks = (int) ($total[$id] ?? 0);
            $done = (int) ($migrated[$id] ?? 0);
            $row = [
                'courseid'  => $id,
                'fullname'  => $fullname,
                'chunks'    => $chunks,
                'migrated'  => $done,
                'remaining' => max(0, $chunks - $done),
                'pct'       => $chunks > 0 ? (int) floor(($done / $chunks) * 100) : 0,
            ];
            $row['state'] = self::state_of($row, $queued[$id] ?? null);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Distinct chunk identities per course, optionally restricted to one model.
     *
     * The DISTINCT is over (courseid, cmid, chunkindex), which is the plugin's
     * de-facto chunk identity — content_indexer upserts on exactly that triple.
     * A derived table rather than COUNT(DISTINCT a, b, c), which is MySQL-only.
     * DISTINCT treats two NULL cmids as equal, so course-level chunks group
     * correctly rather than each counting as its own identity.
     *
     * @param string|null $model Restrict to chunks embedded with this model.
     * @return array<int, int> courseid => identity count.
     */
    private static function identity_counts(?string $model): array {
        global $DB;

        $params = ['siteid' => SITEID];
        $where = 'courseid > :siteid';
        if ($model !== null) {
            $where .= ' AND embed_model = :model';
            $params['model'] = $model;
        }

        $sql = "SELECT d.courseid, COUNT(*) AS n
                  FROM (SELECT DISTINCT courseid, cmid, chunkindex
                          FROM {" . self::TABLE . "}
                         WHERE {$where}) d
              GROUP BY d.courseid";

        $out = [];
        // A recordset, not get_records_sql: the first selected column would
        // become the array key there, which happens to be what we want here but
        // is exactly the habit that has produced four defects in this plugin.
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $out[(int) $row->courseid] = (int) $row->n;
        }
        $rs->close();

        return $out;
    }

    /**
     * Which state one course is in.
     *
     * A queued or running task outranks the measured counts: an operator who
     * has just pressed the button needs to see that the request was recorded,
     * not "not started".
     *
     * @param array $row Course counts.
     * @param string|null $taskstate STATE_QUEUED, STATE_RUNNING or null.
     * @return string
     */
    public static function state_of(array $row, ?string $taskstate): string {
        $chunks = (int) ($row['chunks'] ?? 0);
        $migrated = (int) ($row['migrated'] ?? 0);
        if ($chunks === 0) {
            return self::STATE_NOINDEX;
        }
        if ($migrated >= $chunks) {
            // Complete outranks a queued task: a task queued twice, or one
            // whose course finished under another task, must not read as
            // unfinished work.
            return self::STATE_COMPLETE;
        }
        if ($taskstate !== null) {
            return $taskstate;
        }
        return $migrated > 0 ? self::STATE_PARTIAL : self::STATE_NOTSTARTED;
    }

    /**
     * Courses with a migration task in the queue, and whether it has started.
     *
     * Read from Moodle's own adhoc queue rather than from a status column of
     * our own: the queue IS the truth about what is pending, and a second
     * record of it could only ever disagree. `timestarted` is set when a task
     * is picked up, which is what separates queued from running.
     *
     * @return array<int, string> courseid => STATE_QUEUED|STATE_RUNNING
     */
    public static function queued_courseids(): array {
        global $DB;

        $out = [];
        $rs = $DB->get_recordset(
            'task_adhoc',
            ['classname' => '\\' . migrate_course_embeddings::class],
            'id ASC',
            'id, customdata, timestarted'
        );
        foreach ($rs as $row) {
            $data = json_decode((string) $row->customdata, true);
            $courseid = is_array($data) ? (int) ($data['courseid'] ?? 0) : 0;
            if ($courseid <= 0) {
                continue;
            }
            $state = empty($row->timestarted) ? self::STATE_QUEUED : self::STATE_RUNNING;
            // A running task wins over a second queued one for the same course.
            if (($out[$courseid] ?? '') !== self::STATE_RUNNING) {
                $out[$courseid] = $state;
            }
        }
        $rs->close();

        return $out;
    }

    /**
     * Queue one course.
     *
     * @param int $courseid
     * @param int $userid Who asked, recorded on the task's custom data.
     * @return bool True when a task was queued; false when one already exists.
     */
    public static function queue_course(int $courseid, int $userid = 0): bool {
        $target = self::target();
        if (!$target['configured'] || $courseid <= 0) {
            return false;
        }
        if (isset(self::queued_courseids()[$courseid])) {
            // Queueing a second task would re-embed the same course twice and
            // bill for it twice.
            return false;
        }

        $task = new migrate_course_embeddings();
        $task->set_custom_data([
            'courseid'   => $courseid,
            'model'      => $target['model'],
            'provider'   => $target['provider'],
            'dimensions' => (int) $target['dimensions'],
            'queuedby'   => $userid,
        ]);
        \core\task\manager::queue_adhoc_task($task);

        return true;
    }

    /**
     * Queue every course that is not already complete, queued or running.
     *
     * @param int $userid
     * @return int Number of tasks queued.
     */
    public static function queue_all(int $userid = 0): int {
        $queued = 0;
        foreach (self::course_states() as $row) {
            if (in_array($row['state'], [
                self::STATE_COMPLETE, self::STATE_QUEUED, self::STATE_RUNNING, self::STATE_NOINDEX,
            ], true)) {
                continue;
            }
            if (self::queue_course((int) $row['courseid'], $userid)) {
                $queued++;
            }
        }
        return $queued;
    }

    /**
     * Overall progress across every indexed course.
     *
     * @param array|null $states Result of {@see course_states()}, or null to read it.
     * @return array{total: int, done: int, pending: int, chunks: int,
     *               migrated: int, allcomplete: bool}
     */
    public static function progress(?array $states = null): array {
        $states = $states ?? self::course_states();
        $out = ['total' => 0, 'done' => 0, 'pending' => 0, 'chunks' => 0, 'migrated' => 0];
        foreach ($states as $row) {
            if ($row['state'] === self::STATE_NOINDEX) {
                continue;
            }
            $out['total']++;
            $out['chunks'] += (int) $row['chunks'];
            $out['migrated'] += (int) $row['migrated'];
            if ($row['state'] === self::STATE_COMPLETE) {
                $out['done']++;
            } else {
                $out['pending']++;
            }
        }
        // An empty corpus is not a completed migration. Reporting "all
        // complete" for zero courses would invite an operator to flip the live
        // settings on the strength of no evidence at all.
        $out['allcomplete'] = $out['total'] > 0 && $out['pending'] === 0;

        return $out;
    }

    /**
     * How many chunk rows are superseded: an older embedding model, where the
     * same chunk already has a vector under the LIVE model.
     *
     * @return int
     */
    public static function superseded_count(): int {
        return count(self::superseded_ids());
    }

    /**
     * Ids of the superseded chunk rows.
     *
     * Deliberately narrow: a row qualifies only when a vector for the same
     * course + module + chunk index already exists under the live model. A
     * blanket "delete everything not on the live model" would empty the index
     * of any course not yet migrated — the failure this whole design exists to
     * avoid — and it would do it at the exact moment an operator was tidying
     * up.
     *
     * Expressed as a self-JOIN and resolved in two steps (select ids, then
     * delete by id) rather than as a DELETE ... WHERE EXISTS over the same
     * table: MySQL refuses to reference a DELETE's target table in a subquery
     * (error 1093), so the tidy single-statement version would work on
     * PostgreSQL and fail on the database this site actually runs.
     *
     * @return int[] Distinct row ids.
     */
    public static function superseded_ids(): array {
        global $DB;

        $livemodel = trim((string) (get_config('local_ai_course_assistant', 'embed_model') ?: ''));
        if ($livemodel === '') {
            // With no live model configured, nothing can be proven superseded.
            return [];
        }

        // Leads with the unique old.id, and read through a recordset so a chunk
        // matched by two newer rows cannot silently collapse a result key.
        $sql = "SELECT old.id
                  FROM {" . self::TABLE . "} old
                  JOIN {" . self::TABLE . "} newer
                    ON newer.courseid = old.courseid
                   AND newer.chunkindex = old.chunkindex
                   AND (newer.cmid = old.cmid
                        OR (newer.cmid IS NULL AND old.cmid IS NULL))
                   AND newer.embed_model = :livemodel2
                 WHERE old.embed_model IS NOT NULL
                   AND old.embed_model <> :livemodel
              ORDER BY old.id ASC";

        $ids = [];
        $rs = $DB->get_recordset_sql($sql, ['livemodel' => $livemodel, 'livemodel2' => $livemodel]);
        foreach ($rs as $row) {
            $ids[(int) $row->id] = true;
        }
        $rs->close();

        return array_keys($ids);
    }

    /**
     * Delete the superseded rows.
     *
     * @return int Rows deleted.
     */
    public static function purge_superseded(): int {
        global $DB;

        $ids = self::superseded_ids();
        if (empty($ids)) {
            return 0;
        }
        foreach (array_chunk($ids, 500) as $batch) {
            [$insql, $params] = $DB->get_in_or_equal($batch, SQL_PARAMS_NAMED, 'cid');
            $DB->delete_records_select(self::TABLE, "id {$insql}", $params);
        }
        // The retriever caches decoded vectors for the life of the process.
        rag_retriever::flush_cache();

        return count($ids);
    }

    /**
     * The model the site FAQ index was embedded with, or null when unindexed.
     *
     * The FAQ lives under SITEID and is embedded from one admin setting, so it
     * is not part of the per-course migration. It matters here because
     * index_faq() is gated on a hash of the FAQ TEXT, not on the model: after a
     * model change it will not re-embed itself, the retriever will skip it, and
     * context_builder will quietly fall back to injecting the whole FAQ inline.
     * That is a prompt-budget regression with no error anywhere, so the page
     * shows the state and offers the one action that fixes it.
     *
     * @return string|null
     */
    public static function faq_model(): ?string {
        global $DB;

        // Distinct models rather than one arbitrary row: a mid-migration FAQ
        // could hold two, and reporting only one would tell an operator the
        // index is consistent when it is not.
        $models = [];
        $rs = $DB->get_recordset_sql(
            "SELECT MIN(ch.id) AS id, ch.embed_model
               FROM {" . self::TABLE . "} ch
              WHERE ch.courseid = :siteid AND ch.modtype = :faqtype
           GROUP BY ch.embed_model
           ORDER BY ch.embed_model ASC",
            ['siteid' => SITEID, 'faqtype' => faq_manager::MODTYPE]
        );
        foreach ($rs as $row) {
            $models[] = (string) ($row->embed_model ?? '');
        }
        $rs->close();

        if (empty($models)) {
            return null;
        }
        return implode(', ', $models);
    }
}
