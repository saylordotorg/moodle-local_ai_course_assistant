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

/**
 * An embedding provider that answers from memory.
 *
 * Lets the shadow-index path be exercised end to end with no API call and no
 * spend, which matters because the guarantee under test — that the vectors
 * serving retrieval survive a migration — cannot be verified by reading the
 * code, only by running it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class shadow_fake_embedding_provider extends base_embedding_provider {
    /** @var string Model this fake claims to be. */
    private string $fakemodel;

    /** @var int Calls made, so a test can assert work was or was not done. */
    public int $calls = 0;

    /**
     * @param string $model Model name to record against every vector.
     */
    public function __construct(string $model = 'fake-target-model') {
        parent::__construct();
        $this->fakemodel = $model;
    }

    /**
     * The model recorded on every chunk this provider embeds.
     *
     * @return string
     */
    public function get_model(): string {
        return $this->fakemodel;
    }

    /**
     * @return string
     */
    protected function get_default_model(): string {
        return $this->fakemodel;
    }

    /**
     * @return string
     */
    protected function get_default_base_url(): string {
        return 'https://example.invalid';
    }

    /**
     * @param string $text
     * @return float[]
     */
    public function embed(string $text): array {
        $this->calls++;
        return [0.5, 0.25, 0.125, 0.0625];
    }

    /**
     * @param string[] $texts
     * @return float[][]
     */
    public function embed_batch(array $texts): array {
        $out = [];
        foreach ($texts as $text) {
            $out[] = $this->embed($text);
        }
        return $out;
    }
}

/**
 * Shadow indexing: new vectors land alongside the old ones.
 *
 * This is the safety property the whole embedding migration rests on. Before
 * v7.4.0 the only way to re-embed a course against a new model was to change
 * the live settings first, at which point every course retrieved nothing until
 * it had been re-indexed.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\content_indexer::index_course
 */
final class content_indexer_shadow_test extends \advanced_testcase {
    /** @var string Body long enough to clear the extractor's minimum length. */
    private const BODY = 'Marginal cost is the additional cost incurred by producing one more unit of '
        . 'output. It is central to the theory of the firm, because a profit-maximising producer sets '
        . 'output where marginal cost equals marginal revenue. This paragraph exists to be long enough '
        . 'for the extractor to treat it as indexable content rather than dropping it.';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('rag_enabled', 1, 'local_ai_course_assistant');
        set_config('embed_provider', 'openai', 'local_ai_course_assistant');
        set_config('embed_model', 'old-live-model', 'local_ai_course_assistant');
        set_config('embed_dtype', 'float', 'local_ai_course_assistant');
    }

    /**
     * A course with one page module carrying indexable text.
     *
     * @return \stdClass
     */
    private function course_with_content(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Marginal cost',
            'content' => self::BODY,
            'contentformat' => FORMAT_HTML,
        ]);
        return $course;
    }

    /**
     * Chunk rows for a course, grouped by the model they were embedded with.
     *
     * @param int $courseid
     * @return array<string, int>
     */
    private function models_for(int $courseid): array {
        global $DB;
        $out = [];
        // Leads with the unique id; the grouping is done in PHP so two models
        // cannot collapse into one result key.
        $rs = $DB->get_recordset_select(
            'local_ai_course_assistant_chunks',
            'courseid = :courseid',
            ['courseid' => $courseid],
            '',
            'id, embed_model'
        );
        foreach ($rs as $row) {
            $key = (string) ($row->embed_model ?? '');
            $out[$key] = ($out[$key] ?? 0) + 1;
        }
        $rs->close();
        return $out;
    }

    public function test_a_shadow_run_leaves_the_serving_vectors_in_place(): void {
        $course = $this->course_with_content();

        // Build the index that is serving retrieval today.
        $live = new shadow_fake_embedding_provider('old-live-model');
        $stats = content_indexer::index_course((int) $course->id, false, ['provider' => $live]);
        $this->assertGreaterThan(0, $stats['indexed'], 'The baseline index must exist to be preserved.');
        $before = $this->models_for((int) $course->id);
        $this->assertArrayHasKey('old-live-model', $before);
        $baselinecount = $before['old-live-model'];

        // Now migrate, in shadow mode, against a different model.
        $target = new shadow_fake_embedding_provider('new-target-model');
        $migrated = content_indexer::index_course((int) $course->id, false, [
            'provider' => $target,
            'shadow'   => true,
        ]);

        $this->assertGreaterThan(0, $migrated['indexed']);
        $this->assertTrue($migrated['shadow'] ?? false, 'The run must declare itself a shadow run.');

        $after = $this->models_for((int) $course->id);
        $this->assertSame(
            $baselinecount,
            $after['old-live-model'] ?? 0,
            'Every vector serving retrieval must survive the migration run.'
        );
        $this->assertSame(
            $baselinecount,
            $after['new-target-model'] ?? 0,
            'Every chunk must also have a vector in the new embedding space.'
        );
    }

    public function test_a_normal_run_still_replaces_the_old_vectors(): void {
        // The control. Shadow mode is opt-in; the ordinary reindex must keep
        // behaving exactly as it did, or a routine reindex would double the
        // index every time the model changed.
        $course = $this->course_with_content();

        $live = new shadow_fake_embedding_provider('old-live-model');
        content_indexer::index_course((int) $course->id, false, ['provider' => $live]);
        $baseline = array_sum($this->models_for((int) $course->id));

        $target = new shadow_fake_embedding_provider('new-target-model');
        content_indexer::index_course((int) $course->id, false, ['provider' => $target]);

        $after = $this->models_for((int) $course->id);
        $this->assertSame(0, $after['old-live-model'] ?? 0);
        $this->assertSame($baseline, $after['new-target-model'] ?? 0);
    }

    public function test_a_second_shadow_run_re_embeds_nothing(): void {
        // Resumability, and the reason it matters: a re-queued or retried course
        // must not bill the whole course again. The skip test compares the
        // stored model as well as the content hash.
        $course = $this->course_with_content();
        $live = new shadow_fake_embedding_provider('old-live-model');
        content_indexer::index_course((int) $course->id, false, ['provider' => $live]);

        $first = new shadow_fake_embedding_provider('new-target-model');
        $one = content_indexer::index_course((int) $course->id, false, [
            'provider' => $first, 'shadow' => true,
        ]);
        $this->assertGreaterThan(0, $first->calls);

        $second = new shadow_fake_embedding_provider('new-target-model');
        $two = content_indexer::index_course((int) $course->id, false, [
            'provider' => $second, 'shadow' => true,
        ]);

        $this->assertSame(0, $two['indexed'], 'Nothing was left to embed.');
        $this->assertSame($one['indexed'], $two['skipped'], 'Every chunk should have been skipped.');
        $this->assertSame(
            0,
            $second->calls,
            'A second pass that called the API again would bill for work already paid for.'
        );
    }

    public function test_a_shadow_run_never_prunes(): void {
        // A shadow run's hash set covers only what it re-embedded. Treating
        // anything outside it as stale would delete the index still serving
        // retrieval — the one thing a migration must not do.
        global $DB;
        $course = $this->course_with_content();
        $live = new shadow_fake_embedding_provider('old-live-model');
        content_indexer::index_course((int) $course->id, false, ['provider' => $live]);

        // A row whose content no longer exists in the source. A normal run
        // would prune it; a shadow run must not touch it.
        $orphan = (int) $DB->insert_record('local_ai_course_assistant_chunks', (object) [
            'courseid' => (int) $course->id,
            'cmid' => 999999,
            'modtype' => 'page',
            'chunkindex' => 0,
            'content' => 'content that is no longer in the course',
            'contenthash' => sha1('gone'),
            'embedding' => json_encode([0.1]),
            'embed_model' => 'old-live-model',
            'embed_dtype' => 'float',
            'timecreated' => time(),
            'timeindexed' => time(),
        ]);

        $target = new shadow_fake_embedding_provider('new-target-model');
        content_indexer::index_course((int) $course->id, false, [
            'provider' => $target, 'shadow' => true,
        ]);

        $this->assertTrue(
            $DB->record_exists('local_ai_course_assistant_chunks', ['id' => $orphan]),
            'A shadow run must not prune.'
        );
    }

    public function test_the_migration_state_reads_complete_after_a_shadow_run(): void {
        // The page's progress is measured from these rows, so the two halves
        // have to agree: a completed shadow run must read as complete.
        $course = $this->course_with_content();
        $live = new shadow_fake_embedding_provider('old-live-model');
        content_indexer::index_course((int) $course->id, false, ['provider' => $live]);

        set_config(
            embedding_migration::SETTING_MODEL,
            'new-target-model',
            'local_ai_course_assistant'
        );
        $states = array_column(embedding_migration::course_states(), null, 'courseid');
        $this->assertSame(
            embedding_migration::STATE_NOTSTARTED,
            $states[(int) $course->id]['state']
        );

        $target = new shadow_fake_embedding_provider('new-target-model');
        content_indexer::index_course((int) $course->id, false, [
            'provider' => $target, 'shadow' => true,
        ]);

        $states = array_column(embedding_migration::course_states(), null, 'courseid');
        $this->assertSame(
            embedding_migration::STATE_COMPLETE,
            $states[(int) $course->id]['state']
        );
        $this->assertTrue(embedding_migration::progress()['allcomplete']);

        // And afterwards the old vectors are exactly the superseded set: one
        // per chunk, each with a live-model counterpart... once the operator
        // has flipped the live setting.
        set_config('embed_model', 'new-target-model', 'local_ai_course_assistant');
        $this->assertSame(
            $states[(int) $course->id]['migrated'],
            embedding_migration::superseded_count(),
            'Every old vector now has a replacement, so every one is superseded.'
        );
    }
}
