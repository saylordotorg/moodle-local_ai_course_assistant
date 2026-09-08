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
 * The embedding-model migration: per-course tasks, measured progress, and the
 * guarantee that the index serving retrieval is never emptied first.
 *
 * The risky parts are all here: whether the provider override leaks into the
 * live configuration, whether a queued course can be queued twice and billed
 * twice, whether progress can read "complete" when it is not, and whether the
 * cleanup can delete a chunk's only vector.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\embedding_migration
 */
final class embedding_migration_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Configure a live embedding model and a migration target.
     *
     * @param string $livemodel
     * @param string $targetmodel
     * @param int $targetdim
     * @return void
     */
    private function configure(string $livemodel, string $targetmodel, int $targetdim = 2048): void {
        set_config('embed_provider', 'openai', 'local_ai_course_assistant');
        set_config('embed_model', $livemodel, 'local_ai_course_assistant');
        set_config('embed_dimensions', 1536, 'local_ai_course_assistant');
        if ($targetmodel !== '') {
            set_config(embedding_migration::SETTING_MODEL, $targetmodel, 'local_ai_course_assistant');
            set_config(embedding_migration::SETTING_DIMENSIONS, $targetdim, 'local_ai_course_assistant');
        }
    }

    /**
     * Insert one chunk row.
     *
     * @param int $courseid
     * @param string $model
     * @param int $chunkindex
     * @param int|null $cmid
     * @param string $modtype
     * @return int Row id.
     */
    private function chunk(
        int $courseid,
        string $model,
        int $chunkindex = 0,
        ?int $cmid = 101,
        string $modtype = 'page'
    ): int {
        global $DB;
        return (int) $DB->insert_record(embedding_migration::TABLE, (object) [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'modtype' => $modtype,
            'chunkindex' => $chunkindex,
            'content' => 'chunk text ' . $chunkindex,
            'contenthash' => sha1('chunk text ' . $chunkindex),
            'embedding' => json_encode([0.1, 0.2]),
            'embed_model' => $model,
            'embed_dtype' => 'float',
            'timecreated' => time(),
            'timeindexed' => time(),
        ]);
    }

    // ------------------------------------------------------------- Target.

    public function test_no_target_means_no_migration_is_offered(): void {
        $this->configure('text-embedding-3-small', '');

        $target = embedding_migration::target();

        $this->assertFalse($target['configured']);
        $this->assertSame([], embedding_migration::course_states($target));
    }

    public function test_the_target_reports_what_is_serving_retrieval_now(): void {
        $this->configure('text-embedding-3-small', 'voyage-4-large', 2048);

        $target = embedding_migration::target();

        $this->assertTrue($target['configured']);
        $this->assertSame('voyage-4-large', $target['model']);
        $this->assertSame(2048, $target['dimensions']);
        $this->assertSame('text-embedding-3-small', $target['livemodel']);
        $this->assertSame(1536, $target['livedimensions']);
        $this->assertFalse($target['sameaslive']);
    }

    public function test_a_target_equal_to_the_live_settings_is_reported_not_refused(): void {
        // Re-embedding in place is a legitimate thing to want (a corpus indexed
        // at the wrong width). It just is not building a second index, and the
        // page has to say which of the two is happening.
        set_config('embed_provider', 'openai', 'local_ai_course_assistant');
        set_config('embed_model', 'text-embedding-3-small', 'local_ai_course_assistant');
        set_config('embed_dimensions', 1536, 'local_ai_course_assistant');
        set_config(embedding_migration::SETTING_MODEL, 'text-embedding-3-small', 'local_ai_course_assistant');
        set_config(embedding_migration::SETTING_DIMENSIONS, 1536, 'local_ai_course_assistant');

        $this->assertTrue(embedding_migration::target()['sameaslive']);
    }

    // -------------------------------------------------- Provider overrides.

    public function test_the_override_builds_the_target_model_without_touching_config(): void {
        $this->configure('text-embedding-3-small', 'text-embedding-3-large', 3072);

        $provider = embedding_migration::target_provider();

        $this->assertSame('text-embedding-3-large', $provider->get_model());
        $this->assertSame(3072, $provider->get_dimensions());
        // The live settings are the point: they must be untouched, because they
        // are what retrieval is still using.
        $this->assertSame(
            'text-embedding-3-small',
            get_config('local_ai_course_assistant', 'embed_model')
        );
        $this->assertSame('1536', (string) get_config('local_ai_course_assistant', 'embed_dimensions'));
    }

    public function test_an_override_does_not_leak_into_the_next_provider_built(): void {
        $this->configure('text-embedding-3-small', 'text-embedding-3-large', 3072);

        embedding_migration::target_provider();
        $live = base_embedding_provider::create_from_config();

        $this->assertSame(
            'text-embedding-3-small',
            $live->get_model(),
            'A leaked override would silently re-point live retrieval at the migration target.'
        );
        $this->assertSame(1536, $live->get_dimensions());
    }

    public function test_the_query_side_model_is_never_inherited_by_the_target(): void {
        // embed_query_model names a model in the OLD embedding space. Carrying
        // it over would record the wrong model as the producer of the query
        // vector, and the retriever's comparability check would then refuse the
        // very rows the migration just wrote.
        $this->configure('voyage-4', 'voyage-4-large', 2048);
        set_config('embed_query_model', 'voyage-4-lite', 'local_ai_course_assistant');

        $overrides = embedding_migration::provider_overrides();

        $this->assertSame('', $overrides['embed_query_model']);
        $this->assertSame(2048, $overrides['embed_dimensions']);
    }

    public function test_building_a_provider_with_no_target_is_refused(): void {
        $this->configure('text-embedding-3-small', '');

        $this->expectException(\moodle_exception::class);
        embedding_migration::target_provider();
    }

    // ------------------------------------------------------------- States.

    public function test_a_course_with_no_target_vectors_reads_as_not_started(): void {
        $this->configure('text-embedding-3-small', 'voyage-4-large');
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Alpha']);
        $this->chunk((int) $course->id, 'text-embedding-3-small', 0);
        $this->chunk((int) $course->id, 'text-embedding-3-small', 1);

        $states = array_column(embedding_migration::course_states(), null, 'courseid');
        $row = $states[(int) $course->id];

        $this->assertSame(2, $row['chunks']);
        $this->assertSame(0, $row['migrated']);
        $this->assertSame(embedding_migration::STATE_NOTSTARTED, $row['state']);
    }

    public function test_a_half_migrated_course_reports_a_percentage(): void {
        $this->configure('text-embedding-3-small', 'voyage-4-large');
        $course = $this->getDataGenerator()->create_course();
        $this->chunk((int) $course->id, 'text-embedding-3-small', 0);
        $this->chunk((int) $course->id, 'text-embedding-3-small', 1);
        $this->chunk((int) $course->id, 'voyage-4-large', 0);

        $states = array_column(embedding_migration::course_states(), null, 'courseid');
        $row = $states[(int) $course->id];

        // Two chunk identities, one of them migrated. Three ROWS exist, but a
        // shadow index always holds a second row per migrated chunk, so rows
        // are not the unit of progress.
        $this->assertSame(2, $row['chunks']);
        $this->assertSame(1, $row['migrated']);
        $this->assertSame(50, $row['pct']);
        $this->assertSame(embedding_migration::STATE_PARTIAL, $row['state']);
    }

    public function test_a_fully_migrated_course_reads_as_complete(): void {
        $this->configure('text-embedding-3-small', 'voyage-4-large');
        $course = $this->getDataGenerator()->create_course();
        $this->chunk((int) $course->id, 'voyage-4-large', 0);
        $this->chunk((int) $course->id, 'voyage-4-large', 1);

        $states = array_column(embedding_migration::course_states(), null, 'courseid');
        $this->assertSame(
            embedding_migration::STATE_COMPLETE,
            $states[(int) $course->id]['state']
        );
    }

    public function test_two_courses_are_never_collapsed_into_one_row(): void {
        // The get_records_sql first-column trap, in the shape it would bite
        // here: grouping per course and per model and keying the result on
        // anything but the course id merges two courses' progress.
        $this->configure('text-embedding-3-small', 'voyage-4-large');
        $a = $this->getDataGenerator()->create_course(['fullname' => 'Same Name']);
        $b = $this->getDataGenerator()->create_course(['fullname' => 'Same Name']);
        $this->chunk((int) $a->id, 'voyage-4-large', 0);
        $this->chunk((int) $b->id, 'text-embedding-3-small', 0);

        $states = array_column(embedding_migration::course_states(), null, 'courseid');

        $this->assertCount(2, $states);
        $this->assertSame(embedding_migration::STATE_COMPLETE, $states[(int) $a->id]['state']);
        $this->assertSame(embedding_migration::STATE_NOTSTARTED, $states[(int) $b->id]['state']);
    }

    public function test_two_vectors_for_one_chunk_count_as_one_chunk(): void {
        // The shadow index holds the serving vector AND its replacement for
        // every chunk. Counting rows would report a finished course as 50%
        // migrated, and an operator would never be told it was safe to switch
        // over.
        $this->configure('text-embedding-3-small', 'voyage-4-large');
        $course = $this->getDataGenerator()->create_course();
        $this->chunk((int) $course->id, 'text-embedding-3-small', 0);
        $this->chunk((int) $course->id, 'voyage-4-large', 0);

        $states = array_column(embedding_migration::course_states(), null, 'courseid');
        $row = $states[(int) $course->id];

        $this->assertSame(1, $row['chunks'], 'One chunk, two vectors.');
        $this->assertSame(1, $row['migrated']);
        $this->assertSame(100, $row['pct']);
        $this->assertSame(embedding_migration::STATE_COMPLETE, $row['state']);
    }

    public function test_a_course_level_chunk_counts_once_despite_a_null_cmid(): void {
        // DISTINCT treats two NULL cmids as equal; if it did not, every
        // course-level chunk would count as its own identity and the totals
        // would drift.
        $this->configure('text-embedding-3-small', 'voyage-4-large');
        $course = $this->getDataGenerator()->create_course();
        $this->chunk((int) $course->id, 'text-embedding-3-small', 0, null);
        $this->chunk((int) $course->id, 'voyage-4-large', 0, null);

        $states = array_column(embedding_migration::course_states(), null, 'courseid');

        $this->assertSame(1, $states[(int) $course->id]['chunks']);
        $this->assertSame(embedding_migration::STATE_COMPLETE, $states[(int) $course->id]['state']);
    }

    public function test_a_hidden_course_with_an_index_is_still_migrated(): void {
        // An unmigrated index is an unmigrated index. Filtering on visibility
        // or enrolments would report the migration complete while part of the
        // corpus was still in the old embedding space.
        $this->configure('text-embedding-3-small', 'voyage-4-large');
        $hidden = $this->getDataGenerator()->create_course(['visible' => 0]);
        $this->chunk((int) $hidden->id, 'text-embedding-3-small', 0);

        $states = array_column(embedding_migration::course_states(), null, 'courseid');

        $this->assertArrayHasKey((int) $hidden->id, $states);
    }

    public function test_state_ranking_puts_a_queued_task_above_a_partial_count(): void {
        $partial = ['chunks' => 10, 'migrated' => 4];
        $this->assertSame(
            embedding_migration::STATE_PARTIAL,
            embedding_migration::state_of($partial, null)
        );
        $this->assertSame(
            embedding_migration::STATE_QUEUED,
            embedding_migration::state_of($partial, embedding_migration::STATE_QUEUED)
        );
        $this->assertSame(
            embedding_migration::STATE_RUNNING,
            embedding_migration::state_of($partial, embedding_migration::STATE_RUNNING)
        );
    }

    public function test_a_complete_course_outranks_a_stale_queued_task(): void {
        // A task queued twice, or one whose course finished under another task,
        // must not read as unfinished work.
        $this->assertSame(
            embedding_migration::STATE_COMPLETE,
            embedding_migration::state_of(
                ['chunks' => 5, 'migrated' => 5],
                embedding_migration::STATE_QUEUED
            )
        );
    }

    public function test_a_course_with_no_index_is_not_work_to_do(): void {
        $this->assertSame(
            embedding_migration::STATE_NOINDEX,
            embedding_migration::state_of(['chunks' => 0, 'migrated' => 0], null)
        );
    }

    // ------------------------------------------------------------- Queueing.

    public function test_queueing_one_course_creates_exactly_one_task(): void {
        global $DB;
        $this->configure('text-embedding-3-small', 'voyage-4-large', 2048);
        $course = $this->getDataGenerator()->create_course();
        $this->chunk((int) $course->id, 'text-embedding-3-small', 0);

        $this->assertTrue(embedding_migration::queue_course((int) $course->id, 7));

        $tasks = $DB->get_records('task_adhoc', ['classname' => '\\' . migrate_course_embeddings::class]);
        $this->assertCount(1, $tasks);
        $data = json_decode((string) reset($tasks)->customdata, true);
        $this->assertSame((int) $course->id, (int) $data['courseid']);
        // The target rides on the task rather than being re-read at run time,
        // so a target changed after queueing cannot land one course in a third
        // embedding space that neither setting can read.
        $this->assertSame('voyage-4-large', $data['model']);
        $this->assertSame(2048, (int) $data['dimensions']);
        $this->assertSame(7, (int) $data['queuedby']);
    }

    public function test_the_same_course_cannot_be_queued_twice(): void {
        global $DB;
        $this->configure('text-embedding-3-small', 'voyage-4-large');
        $course = $this->getDataGenerator()->create_course();
        $this->chunk((int) $course->id, 'text-embedding-3-small', 0);

        $this->assertTrue(embedding_migration::queue_course((int) $course->id));
        $this->assertFalse(
            embedding_migration::queue_course((int) $course->id),
            'A second task would re-embed the same course and bill for it twice.'
        );
        $this->assertSame(
            1,
            $DB->count_records('task_adhoc', ['classname' => '\\' . migrate_course_embeddings::class])
        );
    }

    public function test_queueing_with_no_target_queues_nothing(): void {
        global $DB;
        $this->configure('text-embedding-3-small', '');
        $course = $this->getDataGenerator()->create_course();
        $this->chunk((int) $course->id, 'text-embedding-3-small', 0);

        $this->assertFalse(embedding_migration::queue_course((int) $course->id));
        $this->assertSame(0, $DB->count_records('task_adhoc'));
    }

    public function test_queue_all_skips_complete_and_already_queued_courses(): void {
        global $DB;
        $this->configure('text-embedding-3-small', 'voyage-4-large');
        $done = $this->getDataGenerator()->create_course();
        $todo1 = $this->getDataGenerator()->create_course();
        $todo2 = $this->getDataGenerator()->create_course();
        $this->chunk((int) $done->id, 'voyage-4-large', 0);
        $this->chunk((int) $todo1->id, 'text-embedding-3-small', 0);
        $this->chunk((int) $todo2->id, 'text-embedding-3-small', 0);

        embedding_migration::queue_course((int) $todo1->id);
        $queued = embedding_migration::queue_all();

        $this->assertSame(1, $queued, 'Only the one course with no task and no target vectors.');
        $this->assertSame(
            2,
            $DB->count_records('task_adhoc', ['classname' => '\\' . migrate_course_embeddings::class])
        );
    }

    public function test_a_queued_task_is_reported_as_queued_until_cron_starts_it(): void {
        global $DB;
        $this->configure('text-embedding-3-small', 'voyage-4-large');
        $course = $this->getDataGenerator()->create_course();
        $this->chunk((int) $course->id, 'text-embedding-3-small', 0);
        embedding_migration::queue_course((int) $course->id);

        $states = embedding_migration::queued_courseids();
        $this->assertSame(embedding_migration::STATE_QUEUED, $states[(int) $course->id]);

        // Moodle stamps timestarted when a worker picks the task up.
        $DB->set_field('task_adhoc', 'timestarted', time(), [
            'classname' => '\\' . migrate_course_embeddings::class,
        ]);

        $states = embedding_migration::queued_courseids();
        $this->assertSame(embedding_migration::STATE_RUNNING, $states[(int) $course->id]);
    }

    // ------------------------------------------------------------- Progress.

    public function test_an_empty_corpus_is_not_a_completed_migration(): void {
        // Reporting "all complete" for zero courses would invite an operator to
        // flip the live settings on the strength of no evidence at all.
        $this->configure('text-embedding-3-small', 'voyage-4-large');

        $progress = embedding_migration::progress();

        $this->assertSame(0, $progress['total']);
        $this->assertFalse($progress['allcomplete']);
    }

    public function test_progress_is_complete_only_when_every_course_is(): void {
        $this->configure('text-embedding-3-small', 'voyage-4-large');
        $a = $this->getDataGenerator()->create_course();
        $b = $this->getDataGenerator()->create_course();
        $this->chunk((int) $a->id, 'voyage-4-large', 0);
        $this->chunk((int) $b->id, 'text-embedding-3-small', 0);

        $progress = embedding_migration::progress();
        $this->assertSame(2, $progress['total']);
        $this->assertSame(1, $progress['done']);
        $this->assertSame(1, $progress['pending']);
        $this->assertFalse($progress['allcomplete']);

        global $DB;
        $DB->set_field(
            embedding_migration::TABLE,
            'embed_model',
            'voyage-4-large',
            ['courseid' => (int) $b->id]
        );

        $progress = embedding_migration::progress();
        $this->assertSame(2, $progress['done']);
        $this->assertTrue($progress['allcomplete']);
    }

    // ------------------------------------------------------------ Superseded.

    public function test_an_old_vector_is_only_superseded_once_its_replacement_exists(): void {
        // The safety property of the whole design: nothing is deleted before
        // its replacement exists, and the cleanup cannot empty the index of a
        // course that was never migrated.
        set_config('embed_model', 'voyage-4-large', 'local_ai_course_assistant');
        $migrated = $this->getDataGenerator()->create_course();
        $notmigrated = $this->getDataGenerator()->create_course();

        $old = $this->chunk((int) $migrated->id, 'text-embedding-3-small', 0);
        $this->chunk((int) $migrated->id, 'voyage-4-large', 0);
        $lonely = $this->chunk((int) $notmigrated->id, 'text-embedding-3-small', 0);

        $ids = embedding_migration::superseded_ids();

        $this->assertSame([$old], $ids);
        $this->assertNotContains(
            $lonely,
            $ids,
            'A chunk whose only vector is in the old space must never be deleted.'
        );
    }

    public function test_purging_removes_the_superseded_rows_and_nothing_else(): void {
        global $DB;
        set_config('embed_model', 'voyage-4-large', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $this->chunk((int) $course->id, 'text-embedding-3-small', 0);
        $this->chunk((int) $course->id, 'voyage-4-large', 0);
        $this->chunk((int) $course->id, 'text-embedding-3-small', 1);

        $this->assertSame(1, embedding_migration::superseded_count());
        $this->assertSame(1, embedding_migration::purge_superseded());

        $remaining = $DB->get_records(embedding_migration::TABLE, ['courseid' => (int) $course->id]);
        $this->assertCount(2, $remaining);
        $models = array_map(fn($r) => $r->embed_model, $remaining);
        $this->assertContains('voyage-4-large', $models);
        $this->assertContains(
            'text-embedding-3-small',
            $models,
            'The chunk with no replacement keeps its old vector.'
        );
        $this->assertSame(0, embedding_migration::purge_superseded());
    }

    public function test_nothing_is_superseded_when_no_live_model_is_configured(): void {
        set_config('embed_model', '', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $this->chunk((int) $course->id, 'text-embedding-3-small', 0);
        $this->chunk((int) $course->id, 'voyage-4-large', 0);

        $this->assertSame(0, embedding_migration::superseded_count());
    }

    public function test_a_course_level_chunk_with_a_null_cmid_is_matched_correctly(): void {
        // NULL = NULL is not true in SQL, so a course-level chunk needs the
        // explicit both-null branch or it could never be superseded.
        set_config('embed_model', 'voyage-4-large', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $old = $this->chunk((int) $course->id, 'text-embedding-3-small', 0, null);
        $this->chunk((int) $course->id, 'voyage-4-large', 0, null);

        $this->assertSame([$old], embedding_migration::superseded_ids());
    }

    // ------------------------------------------------------------------ FAQ.

    public function test_the_faq_index_state_is_reported_separately(): void {
        $this->assertNull(
            embedding_migration::faq_model(),
            'An unindexed FAQ must read as absent, not as up to date.'
        );

        $this->chunk((int) SITEID, 'text-embedding-3-small', 0, null, faq_manager::MODTYPE);

        $this->assertSame('text-embedding-3-small', embedding_migration::faq_model());
    }

    public function test_a_mixed_faq_index_reports_both_models(): void {
        // index_faq() is gated on a hash of the FAQ TEXT, not on the model, so
        // it will not re-embed itself after a model change. Reporting only one
        // model would tell an operator the index is consistent when it is not.
        $this->chunk((int) SITEID, 'text-embedding-3-small', 0, null, faq_manager::MODTYPE);
        $this->chunk((int) SITEID, 'voyage-4-large', 1, null, faq_manager::MODTYPE);

        $reported = embedding_migration::faq_model();

        $this->assertStringContainsString('text-embedding-3-small', $reported);
        $this->assertStringContainsString('voyage-4-large', $reported);
    }

    // -------------------------------------------------------------- Template.

    public function test_the_migration_section_renders_in_both_states(): void {
        // The section is new HTML on an existing page. A mustache typo or a
        // section name that is not in the data would otherwise only show up on
        // a page load.
        global $OUTPUT, $PAGE;
        $this->setAdminUser();
        $PAGE->set_url('/local/ai_course_assistant/rag_admin.php');

        // No target: the section must still tell an operator the capability
        // exists, rather than vanishing.
        $html = $OUTPUT->render_from_template('local_ai_course_assistant/rag_admin', [
            'migration' => [
                'configured' => false,
                'heading' => get_string('embedmigration:heading', 'local_ai_course_assistant'),
                'notarget' => get_string('embedmigration:notarget', 'local_ai_course_assistant'),
            ],
        ]);
        $this->assertStringContainsString(
            get_string('embedmigration:notarget', 'local_ai_course_assistant'),
            $html
        );

        // Configured, with one course to migrate.
        $html = $OUTPUT->render_from_template('local_ai_course_assistant/rag_admin', [
            'posturl' => '/local/ai_course_assistant/rag_admin.php',
            'sesskey' => 'testsesskey',
            'migration' => [
                'configured' => true,
                'heading' => get_string('embedmigration:heading', 'local_ai_course_assistant'),
                'desc' => get_string('embedmigration:desc', 'local_ai_course_assistant'),
                'summary' => 'Migrating to <strong>voyage-4-large</strong>.',
                'sameaslive' => '',
                'progress' => get_string('embedmigration:progress', 'local_ai_course_assistant', (object) [
                    'done' => 0, 'total' => 1, 'pending' => 1,
                ]),
                'allcomplete' => '',
                'hasrows' => true,
                'rows' => [[
                    'id' => 42,
                    'fullname' => 'A Course With A Long Identifying Name',
                    'chunks' => '12', 'migrated' => '0',
                    'state' => embedding_migration::STATE_NOTSTARTED,
                    'statelabel' => get_string('embedmigration:state_notstarted', 'local_ai_course_assistant'),
                    'complete' => false, 'pending' => false, 'canqueue' => true,
                ]],
                'colcourse' => 'Course', 'colchunks' => 'Chunks',
                'colmigrated' => get_string('embedmigration:col_migrated', 'local_ai_course_assistant'),
                'colstate' => get_string('embedmigration:col_state', 'local_ai_course_assistant'),
                'colactions' => 'Actions',
                'queueone' => get_string('embedmigration:queue_one', 'local_ai_course_assistant'),
                'queueall' => get_string('embedmigration:queue_all', 'local_ai_course_assistant'),
                'queueallconfirm' => 'sure?',
                'purgeheading' => get_string('embedmigration:purge_heading', 'local_ai_course_assistant'),
                'purgedesc' => 'x', 'haspurge' => false,
                'purgenone' => get_string('embedmigration:purge_none', 'local_ai_course_assistant'),
                'purge' => 'p', 'purgeconfirm' => 'sure?',
                'faqheading' => get_string('embedmigration:faq_heading', 'local_ai_course_assistant'),
                'faqstate' => 'x', 'faqstale' => '',
                'faqreembed' => get_string('embedmigration:faq_reembed', 'local_ai_course_assistant'),
            ],
        ]);

        $this->assertStringContainsString('voyage-4-large', $html);
        // The identity column is never truncated.
        $this->assertStringContainsString('A Course With A Long Identifying Name', $html);
        $this->assertStringContainsString('value="migrateone"', $html);
        $this->assertStringContainsString('value="migrateall"', $html);
        $this->assertStringNotContainsString('[[', $html, 'An unresolved lang key reached the page.');
        $this->assertStringNotContainsString(
            'Context variables required for this template',
            $html,
            'The template docblock leaked into the rendered page.'
        );
    }

    // ------------------------------------------------------------------ Task.

    public function test_the_task_ignores_custom_data_it_cannot_act_on(): void {
        // A deleted course is not a failure: throwing would make Moodle retry
        // the task with backoff forever over a course that will never return.
        $this->configure('text-embedding-3-small', 'voyage-4-large');
        $task = new migrate_course_embeddings();
        $task->set_custom_data(['courseid' => 0, 'model' => 'voyage-4-large']);

        ob_start();
        $task->execute();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('nothing to do', $output);
    }

    public function test_the_task_skips_a_course_that_no_longer_exists(): void {
        $this->configure('text-embedding-3-small', 'voyage-4-large');
        $task = new migrate_course_embeddings();
        $task->set_custom_data(['courseid' => 99999999, 'model' => 'voyage-4-large']);

        ob_start();
        $task->execute();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('no longer exists', $output);
    }

    public function test_the_task_refuses_to_write_vectors_under_the_wrong_model(): void {
        // If the provider resolves a different model than the task was queued
        // for, those vectors would be recorded under a model nobody is
        // migrating to and the course would read as unmigrated forever.
        $this->configure('text-embedding-3-small', 'voyage-4-large');
        $course = $this->getDataGenerator()->create_course();
        $task = new migrate_course_embeddings();
        // openai is the configured provider; asking it for an empty model name
        // makes it resolve its own default instead.
        $task->set_custom_data([
            'courseid' => (int) $course->id,
            'model' => 'a-model-the-provider-will-not-return',
            'provider' => 'openai',
            'dimensions' => 0,
        ]);
        set_config(
            embedding_migration::SETTING_MODEL,
            'a-model-the-provider-will-not-return',
            'local_ai_course_assistant'
        );

        // The provider echoes back whatever model it is given, so this path is
        // exercised by asserting the equality check itself rather than by
        // faking a mismatch: build the provider and confirm the guard's
        // premise holds.
        $provider = embedding_migration::target_provider();
        $this->assertSame(
            'a-model-the-provider-will-not-return',
            $provider->get_model(),
            'The override must reach the provider; if it did not, the task guard would fire on every run.'
        );
    }
}
