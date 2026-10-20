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
 * Retrieved course content is filtered by what the requesting learner can see (#341).
 *
 * The scheduled indexer runs as an administrator, so the chunk table holds text
 * from hidden and restricted activities. Retrieval selected chunks by course
 * alone, and both chat paths put them in the learner's prompt. These tests pin
 * the filter itself (rag_visibility::filter()) on real courses and activities,
 * then the whole path: rag_retriever::retrieve() with a mocked embedding call,
 * through context_builder::build_system_prompt(), and the two chat entry points.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\rag_visibility
 * @covers     \local_ai_course_assistant\rag_retriever::retrieve
 */
final class rag_visibility_test extends \advanced_testcase {
    /** @var int Next fake chunk id for chunks built as plain arrays. */
    private int $nextid = 1000;

    /**
     * Reset state and turn on conditional access, which availability tests need.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enableavailability', 1);
        supplemental_sources::reset_cache();
        rag_retriever::flush_cache();
    }

    /**
     * A chunk shaped like retriever output.
     *
     * @param int $courseid Course the chunk was indexed from.
     * @param int $cmid Course-module id, 0 for course-level text.
     * @param string $content Passage text.
     * @param float $score Similarity score.
     * @param string $modtype Module type ('page', 'course', 'faq').
     * @return array
     */
    private function chunk(int $courseid, int $cmid, string $content, float $score = 0.5, string $modtype = 'page'): array {
        return [
            'id' => $this->nextid++,
            'courseid' => $courseid,
            'content' => $content,
            'score' => $score,
            'cmid' => $cmid,
            'modtype' => $modtype,
            'chunkindex' => 0,
        ];
    }

    /**
     * Create a Page activity.
     *
     * @param \stdClass $course
     * @param array $options Extra module fields (visible, section, availability).
     * @return int The course-module id.
     */
    private function page(\stdClass $course, array $options = []): int {
        $page = $this->getDataGenerator()->create_module('page', array_merge(['course' => $course->id], $options));
        return (int) $page->cmid;
    }

    /**
     * The contents of the chunks that survived, in order.
     *
     * @param array $chunks
     * @return array
     */
    private function contents(array $chunks): array {
        return array_map(static fn(array $c): string => $c['content'], $chunks);
    }

    /**
     * A visible activity's chunk reaches a student.
     */
    public function test_visible_activity_chunk_stays_for_a_student(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $cmid = $this->page($course);

        $out = rag_visibility::filter(
            [$this->chunk((int) $course->id, $cmid, 'visible text')],
            (int) $course->id,
            (int) $student->id
        );

        $this->assertSame(['visible text'], $this->contents($out));
    }

    /**
     * A hidden activity's chunk is removed for a student, kept for staff who may see hidden activities.
     */
    public function test_hidden_activity_chunk_is_removed_for_a_student_and_kept_for_staff(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $hidden = $this->page($course, ['visible' => 0]);
        $chunks = [$this->chunk((int) $course->id, $hidden, 'staged answer key')];

        $this->assertSame([], rag_visibility::filter($chunks, (int) $course->id, (int) $student->id));

        $context = \context_course::instance($course->id);
        $this->assertTrue(has_capability('moodle/course:viewhiddenactivities', $context, $teacher));
        $this->assertSame(
            ['staged answer key'],
            $this->contents(rag_visibility::filter($chunks, (int) $course->id, (int) $teacher->id))
        );
        $this->assertSame(
            ['staged answer key'],
            $this->contents(rag_visibility::filter($chunks, (int) $course->id, (int) get_admin()->id))
        );
    }

    /**
     * An activity restricted by date the student hasn't reached is removed.
     */
    public function test_date_restricted_activity_chunk_is_removed_for_a_student(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $availability = json_encode([
            'op' => '&',
            'c' => [['type' => 'date', 'd' => '>=', 't' => time() + WEEKSECS]],
            'showc' => [true],
        ]);
        $cmid = $this->page($course, ['availability' => $availability]);

        $out = rag_visibility::filter(
            [$this->chunk((int) $course->id, $cmid, 'next week')],
            (int) $course->id,
            (int) $student->id
        );

        $this->assertSame([], $out);
    }

    /**
     * An activity restricted to a group the student isn't in is removed; a member keeps it.
     */
    public function test_group_restricted_activity_chunk_is_removed_for_a_non_member(): void {
        $course = $this->getDataGenerator()->create_course();
        $outsider = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $member = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $member->id]);
        $availability = json_encode([
            'op' => '&',
            'c' => [['type' => 'group', 'id' => (int) $group->id]],
            'showc' => [true],
        ]);
        $cmid = $this->page($course, ['availability' => $availability]);
        $chunks = [$this->chunk((int) $course->id, $cmid, 'group only')];

        $this->assertSame([], rag_visibility::filter($chunks, (int) $course->id, (int) $outsider->id));
        $this->assertSame(
            ['group only'],
            $this->contents(rag_visibility::filter($chunks, (int) $course->id, (int) $member->id))
        );
    }

    /**
     * A chunk whose activity was deleted, or whose cmid never existed in that course, is removed.
     */
    public function test_chunk_of_a_deleted_or_unknown_activity_is_removed(): void {
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $other->id, 'student');
        $deleted = $this->page($course);
        course_delete_module($deleted);
        $elsewhere = $this->page($other);

        $out = rag_visibility::filter([
            $this->chunk((int) $course->id, $deleted, 'deleted page'),
            $this->chunk((int) $course->id, 987654, 'never existed'),
            // The cm exists and is visible, but in another course: the chunk claims the wrong course.
            $this->chunk((int) $course->id, $elsewhere, 'wrong course'),
        ], (int) $course->id, (int) $student->id);

        $this->assertSame([], $out);
    }

    /**
     * A hidden section hides its activities' chunks from a student.
     */
    public function test_hidden_section_removes_its_activities_chunks_for_a_student(): void {
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $insection = $this->page($course, ['section' => 2]);
        set_section_visible((int) $course->id, 2, 0);

        $chunks = [$this->chunk((int) $course->id, $insection, 'in hidden section')];

        $this->assertSame([], rag_visibility::filter($chunks, (int) $course->id, (int) $student->id));
        $this->assertSame(
            ['in hidden section'],
            $this->contents(rag_visibility::filter($chunks, (int) $course->id, (int) get_admin()->id))
        );
    }

    /**
     * Course-level text (cmid 0) stays for a user who can access the course and goes for one who can't.
     */
    public function test_course_level_chunk_follows_course_access(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $stranger = $this->getDataGenerator()->create_user();
        $visible = $this->page($course);
        $chunks = [
            $this->chunk((int) $course->id, 0, 'course overview', 0.7, 'course'),
            $this->chunk((int) $course->id, $visible, 'visible page'),
        ];

        $this->assertSame(
            ['course overview', 'visible page'],
            $this->contents(rag_visibility::filter($chunks, (int) $course->id, (int) $student->id))
        );
        // Not enrolled and no capability: nothing from the course, activity chunks included.
        $this->assertSame([], rag_visibility::filter($chunks, (int) $course->id, (int) $stranger->id));
    }

    /**
     * A supplemental course the administrator shared is readable, but only its visible activities.
     */
    public function test_supplemental_course_is_shared_but_its_hidden_activities_are_not(): void {
        $course = $this->getDataGenerator()->create_course();
        $shared = $this->getDataGenerator()->create_course(['fullname' => 'Student Resource Center']);
        $unshared = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        set_config('supplemental_courses', (string) $shared->id, 'local_ai_course_assistant');
        supplemental_sources::reset_cache();

        $sharedvisible = $this->page($shared);
        $sharedhidden = $this->page($shared, ['visible' => 0]);
        $unsharedvisible = $this->page($unshared);

        $out = rag_visibility::filter([
            $this->chunk((int) $shared->id, 0, 'shared overview', 0.9, 'course'),
            $this->chunk((int) $shared->id, $sharedvisible, 'shared visible'),
            $this->chunk((int) $shared->id, $sharedhidden, 'shared hidden'),
            $this->chunk((int) $unshared->id, $unsharedvisible, 'not shared'),
            $this->chunk((int) $unshared->id, 0, 'not shared overview', 0.9, 'course'),
        ], (int) $course->id, (int) $student->id);

        $this->assertSame(['shared overview', 'shared visible'], $this->contents($out));
    }

    /**
     * Site FAQ chunks are not activity content and stay for everyone.
     */
    public function test_site_faq_chunks_stay(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $faq = $this->chunk(SITEID, 0, 'How do certificates work?', 0.6, faq_manager::MODTYPE);

        $this->assertSame(
            ['How do certificates work?'],
            $this->contents(rag_visibility::filter([$faq], (int) $course->id, (int) $student->id))
        );
        // Fails closed without a user, but the FAQ is site-wide and still applies.
        $this->assertSame(
            ['How do certificates work?'],
            $this->contents(rag_visibility::filter([$faq], (int) $course->id, 0))
        );
        // A site-course chunk that is not the FAQ gets no such pass.
        $this->assertSame([], rag_visibility::filter(
            [$this->chunk(SITEID, 0, 'front page text', 0.6, 'course')],
            (int) $course->id,
            0
        ));
    }

    /**
     * With no user, a shared course's text is dropped too, though shared courses need no enrolment.
     */
    public function test_no_user_gets_nothing_from_a_shared_course(): void {
        $course = $this->getDataGenerator()->create_course();
        $shared = $this->getDataGenerator()->create_course();
        set_config('supplemental_courses', (string) $shared->id, 'local_ai_course_assistant');
        supplemental_sources::reset_cache();
        $visible = $this->page($shared);

        $this->assertSame([], rag_visibility::filter([
            $this->chunk((int) $shared->id, 0, 'shared overview', 0.9, 'course'),
            $this->chunk((int) $shared->id, $visible, 'shared visible'),
        ], (int) $course->id, 0));
    }

    /**
     * Survivors keep their order, scores and every other field; the result is a list.
     */
    public function test_filter_keeps_order_and_scores(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $a = $this->page($course);
        $hidden = $this->page($course, ['visible' => 0]);
        $b = $this->page($course);
        $in = [
            $this->chunk((int) $course->id, $b, 'first', 0.91),
            $this->chunk((int) $course->id, $hidden, 'hidden', 0.88),
            $this->chunk((int) $course->id, 0, 'third', 0.52, 'course'),
            $this->chunk((int) $course->id, $a, 'fourth', 0.31),
        ];

        $out = rag_visibility::filter($in, (int) $course->id, (int) $student->id);

        $this->assertSame([$in[0], $in[2], $in[3]], $out);
        $this->assertSame([0, 1, 2], array_keys($out));
    }

    /**
     * Point the OpenAI embedding provider at a trusted fake host and queue one query vector.
     *
     * curl::mock_response() answers the next request without the network, so retrieve() runs for real.
     *
     * @param array $vector The query vector the fake returns.
     */
    private function mock_query_embedding(array $vector): void {
        \curl::mock_response(json_encode([
            'data' => [['index' => 0, 'embedding' => $vector]],
            'usage' => ['total_tokens' => 3],
        ]));
    }

    /**
     * Configure RAG so retrieve() returns every stored chunk in score order.
     */
    private function configure_rag(): void {
        set_config('rag_enabled', 1, 'local_ai_course_assistant');
        set_config('embed_provider', 'openai', 'local_ai_course_assistant');
        set_config('embed_apikey', 'sk-test-visibility-0000', 'local_ai_course_assistant');
        set_config('embed_apibaseurl', 'https://embed.sola.test/v1', 'local_ai_course_assistant');
        set_config('ssrf_trusted_endpoints', 'https://embed.sola.test', 'local_ai_course_assistant');
        set_config('rag_min_similarity', '0', 'local_ai_course_assistant');
        set_config('rag_scope', 'course', 'local_ai_course_assistant');
        set_config('rag_return_scope', 'chunk', 'local_ai_course_assistant');
        set_config('rerank_enabled', 0, 'local_ai_course_assistant');
    }

    /**
     * Store one embedded chunk row.
     *
     * @param int $courseid
     * @param int $cmid
     * @param string $content
     * @param array $vector
     * @param int $chunkindex Position of the chunk in its document.
     * @return int The chunk id.
     */
    private function store_chunk(int $courseid, int $cmid, string $content, array $vector, int $chunkindex = 0): int {
        global $DB;
        return (int) $DB->insert_record('local_ai_course_assistant_chunks', (object) [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'modtype' => $cmid > 0 ? 'page' : 'course',
            'chunkindex' => $chunkindex,
            'content' => $content,
            'contenthash' => sha1($content),
            'embedding' => null,
            'embedding_bin' => rag_retriever::pack_vector($vector, 'float'),
            'embed_model' => 'text-embedding-3-small',
            'embed_dtype' => 'float',
            'timecreated' => time(),
            'timeindexed' => time(),
        ]);
    }

    /**
     * End to end: retrieve() filters for the learner, and the prompt holds no trace of the hidden chunk.
     *
     * Also covers the caches: the per-request vector static and the application cache are warm from
     * the student's call when the administrator retrieves, and again when the student retrieves after
     * that. One user's visibility must never reach another through either.
     */
    public function test_retrieve_filters_for_the_learner_through_to_the_prompt(): void {
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Chemistry 101']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->configure_rag();
        $visible = $this->page($course);
        $hidden = $this->page($course, ['visible' => 0]);

        // Scores against the query [1, 0, 0]: hidden first, so a filter that ran before ranking or
        // refilled would show up as a changed order or count.
        $this->store_chunk((int) $course->id, $hidden, 'HIDDEN-ANSWER-KEY the answer to question 3 is B', [1.0, 0.0, 0.0]);
        $this->store_chunk((int) $course->id, $visible, 'Visible page on titration', [0.9, 0.1, 0.0]);
        $this->store_chunk((int) $course->id, 0, 'Course overview for Chemistry 101', [0.6, 0.4, 0.0]);

        $this->mock_query_embedding([1.0, 0.0, 0.0]);
        $chunks = rag_retriever::retrieve((int) $course->id, 'question three answer', 5, 0, (int) $student->id);
        $this->assertSame(['Visible page on titration', 'Course overview for Chemistry 101'], $this->contents($chunks));
        $this->assertSame([0, 1], array_keys($chunks));

        // Same request, warm caches, an administrator named explicitly: the hidden chunk is back.
        $this->mock_query_embedding([1.0, 0.0, 0.0]);
        $admin = rag_retriever::retrieve((int) $course->id, 'question three answer', 5, 0, (int) get_admin()->id);
        $this->assertSame('HIDDEN-ANSWER-KEY the answer to question 3 is B', $admin[0]['content']);

        // And the student again, after the administrator's call: still filtered.
        $this->mock_query_embedding([1.0, 0.0, 0.0]);
        $again = rag_retriever::retrieve((int) $course->id, 'question three answer', 5, 0, (int) $student->id);
        $this->assertSame($this->contents($chunks), $this->contents($again));

        // The deliberate opt-out for admin tools returns everything.
        $this->mock_query_embedding([1.0, 0.0, 0.0]);
        $all = rag_retriever::retrieve((int) $course->id, 'question three answer', 5, 0, rag_retriever::UNFILTERED);
        $this->assertCount(3, $all);

        $prompt = context_builder::build_system_prompt((int) $course->id, (int) $student->id, '', $chunks);
        $this->assertStringNotContainsString('HIDDEN-ANSWER-KEY', $prompt);
        $this->assertStringNotContainsString("(cmid: {$hidden})", $prompt);
        $this->assertStringContainsString("[c:0] (cmid: {$visible})", $prompt);
        $this->assertStringContainsString('[c:1]', $prompt);
        $this->assertStringNotContainsString('[c:2]', $prompt);
        $this->assertStringContainsString('Visible page on titration', $prompt);
    }

    /**
     * retrieve() with no user (a CLI caller that forgot to opt out) fails closed to the site FAQ only.
     */
    public function test_retrieve_without_a_user_returns_nothing_from_the_course(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->configure_rag();
        $visible = $this->page($course);
        $this->store_chunk((int) $course->id, $visible, 'Visible page', [1.0, 0.0, 0.0]);

        $this->setUser(null);
        $this->mock_query_embedding([1.0, 0.0, 0.0]);
        $this->assertSame([], rag_retriever::retrieve((int) $course->id, 'anything at all', 5));
    }

    /**
     * The default is no user even when an administrator is logged in, as in a scheduled or adhoc task.
     *
     * A caller that forgets to name the learner must not get administrator visibility silently.
     */
    public function test_retrieve_default_is_no_user_even_for_a_logged_in_administrator(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->configure_rag();
        $hidden = $this->page($course, ['visible' => 0]);
        $this->store_chunk((int) $course->id, $hidden, 'HIDDEN draft page', [1.0, 0.0, 0.0]);

        $this->setAdminUser();
        $this->mock_query_embedding([1.0, 0.0, 0.0]);
        $this->assertSame([], rag_retriever::retrieve((int) $course->id, 'anything at all', 5));
        $this->assertSame(rag_retriever::NO_USER, 0);
    }

    /**
     * A current page the learner can't see neither scopes nor boosts retrieval.
     *
     * Under document_only a hidden cmid in the request narrowed retrieval to that page's chunks, which the
     * filter then removed, so the learner got nothing. It is now treated as no page: course-wide retrieval.
     */
    public function test_a_hidden_current_page_is_ignored_for_scoping(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->configure_rag();
        set_config('rag_scope', 'document_only', 'local_ai_course_assistant');
        $visible = $this->page($course);
        $hidden = $this->page($course, ['visible' => 0]);
        $this->store_chunk((int) $course->id, $hidden, 'HIDDEN page body', [1.0, 0.0, 0.0]);
        $this->store_chunk((int) $course->id, $visible, 'Visible page body', [0.9, 0.1, 0.0]);

        $this->mock_query_embedding([1.0, 0.0, 0.0]);
        $out = rag_retriever::retrieve((int) $course->id, 'anything at all', 5, $hidden, (int) $student->id);
        $this->assertSame(['Visible page body'], $this->contents($out));

        // A visible current page still scopes as before.
        $this->mock_query_embedding([1.0, 0.0, 0.0]);
        $out = rag_retriever::retrieve((int) $course->id, 'anything at all', 5, $visible, (int) $student->id);
        $this->assertSame(['Visible page body'], $this->contents($out));
    }

    /**
     * With reranking on, the filter runs on the reranked top-k and keeps the reranker's order and scores.
     */
    public function test_retrieve_with_rerank_filters_the_reranked_set(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->configure_rag();
        set_config('rerank_enabled', 1, 'local_ai_course_assistant');
        set_config('rerank_apikey', 'pa-test-visibility-0000', 'local_ai_course_assistant');
        set_config('rerank_apibaseurl', 'https://embed.sola.test/v1', 'local_ai_course_assistant');
        set_config('rerank_min_query_chars', '0', 'local_ai_course_assistant');
        set_config('rerank_margin_threshold', '0', 'local_ai_course_assistant');
        $visible = $this->page($course);
        $hidden = $this->page($course, ['visible' => 0]);
        $this->store_chunk((int) $course->id, $hidden, 'HIDDEN answer key', [1.0, 0.0, 0.0]);
        $this->store_chunk((int) $course->id, $visible, 'Visible page', [0.9, 0.1, 0.0]);
        $this->store_chunk((int) $course->id, 0, 'Course overview', [0.6, 0.4, 0.0]);

        // Mocked responses are served last in, first out: the rerank answer is queued before the embedding.
        // Cosine order is hidden, visible, overview; the reranker puts the overview first.
        \curl::mock_response(json_encode([
            'data' => [
                ['index' => 2, 'relevance_score' => 0.9],
                ['index' => 0, 'relevance_score' => 0.8],
                ['index' => 1, 'relevance_score' => 0.7],
            ],
            'usage' => ['total_tokens' => 5],
        ]));
        $this->mock_query_embedding([1.0, 0.0, 0.0]);
        $out = rag_retriever::retrieve((int) $course->id, 'which answer is right', 3, 0, (int) $student->id);

        $this->assertSame(['Course overview', 'Visible page'], $this->contents($out));
        $this->assertSame([0.9, 0.7], array_column($out, 'score'));
    }

    /**
     * With window expansion on, a hidden activity is dropped before its siblings are fetched.
     */
    public function test_retrieve_with_window_expansion_never_expands_a_hidden_activity(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->configure_rag();
        set_config('rag_return_scope', 'window', 'local_ai_course_assistant');
        set_config('rag_window_size', 1, 'local_ai_course_assistant');
        $visible = $this->page($course);
        $hidden = $this->page($course, ['visible' => 0]);
        $this->store_chunk((int) $course->id, $hidden, 'HIDDEN first part', [1.0, 0.0, 0.0], 0);
        $this->store_chunk((int) $course->id, $hidden, 'HIDDEN second part', [0.0, 0.0, 1.0], 1);
        $this->store_chunk((int) $course->id, $visible, 'Visible first part of the page', [0.9, 0.1, 0.0], 0);
        $this->store_chunk((int) $course->id, $visible, 'Visible second part of the page', [0.0, 0.0, 1.0], 1);

        $this->mock_query_embedding([1.0, 0.0, 0.0]);
        $out = rag_retriever::retrieve((int) $course->id, 'anything at all', 2, 0, (int) $student->id);

        $text = implode("\n", $this->contents($out));
        $this->assertStringNotContainsString('HIDDEN', $text);
        $this->assertStringContainsString('Visible first part of the page', $text);
        $this->assertStringContainsString('Visible second part of the page', $text, 'the visible hit was expanded');
    }

    /**
     * The support course admits holders of the support capability who aren't enrolled, and only them.
     */
    public function test_support_course_admits_holders_of_the_support_capability(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $visitor = $this->getDataGenerator()->create_user();
        $chunks = [$this->chunk((int) $course->id, 0, 'support overview', 0.7, 'course')];

        // Not the support course: not enrolled, so nothing.
        $this->assertSame([], rag_visibility::filter($chunks, (int) $course->id, (int) $visitor->id));

        set_config('support_enabled', 1, 'local_ai_course_assistant');
        set_config('support_courseid', (int) $course->id, 'local_ai_course_assistant');
        support_mode::reset_cache();
        $this->assertSame(
            ['support overview'],
            $this->contents(rag_visibility::filter($chunks, (int) $course->id, (int) $visitor->id))
        );

        // Without the capability the support course is closed to a non-enrolled user again.
        $userrole = $DB->get_field('role', 'id', ['shortname' => 'user']);
        assign_capability(
            'local/ai_course_assistant:usesupport',
            CAP_PROHIBIT,
            $userrole,
            \context_system::instance()->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertSame([], rag_visibility::filter($chunks, (int) $course->id, (int) $visitor->id));
    }

    /**
     * A user who can't read the chatted course gets nothing from its supplemental courses either.
     */
    public function test_no_access_to_the_chatted_course_means_no_supplemental_content(): void {
        $course = $this->getDataGenerator()->create_course();
        $shared = $this->getDataGenerator()->create_course();
        $stranger = $this->getDataGenerator()->create_user();
        set_config('supplemental_courses', (string) $shared->id, 'local_ai_course_assistant');
        supplemental_sources::reset_cache();
        $visible = $this->page($shared);
        $faq = $this->chunk(SITEID, 0, 'faq answer', 0.5, faq_manager::MODTYPE);

        $out = rag_visibility::filter([
            $this->chunk((int) $shared->id, 0, 'shared overview', 0.9, 'course'),
            $this->chunk((int) $shared->id, $visible, 'shared visible'),
            $faq,
        ], (int) $course->id, (int) $stranger->id);

        $this->assertSame(['faq answer'], $this->contents($out));
    }

    /**
     * Both chat paths retrieve through the filtered retrieve(), for the requesting user, and never opt out.
     *
     * The filter lives inside retrieve(), so a chat path is safe as long as its chunks come from there
     * for the learner. This pins that, and that no chat path passes the admin-tool opt-out.
     */
    public function test_both_chat_paths_retrieve_for_the_requesting_user(): void {
        global $CFG;
        $dir = $CFG->dirroot . '/local/ai_course_assistant/';
        $paths = [
            'sse.php' => '/\$retrievedchunks\s*=\s*rag_retriever::retrieve\([^;]*\(int\)\s*\$USER->id\s*\);/s',
            'classes/external/send_message.php' =>
                '/\$retrievedchunks\s*=\s*rag_retriever::retrieve\([^;]*\(int\)\s*\$userid\s*\);/s',
        ];
        foreach ($paths as $file => $pattern) {
            $src = file_get_contents($dir . $file);
            $this->assertMatchesRegularExpression($pattern, $src, "{$file} must retrieve for the requesting user.");
            $this->assertStringNotContainsString('UNFILTERED', $src, "{$file} must not opt out of the filter.");
            // Every assignment of the chunks handed to the prompt is either empty or the filtered retrieve().
            preg_match_all('/\$retrievedchunks\s*=\s*([^;]*);/s', $src, $m);
            $this->assertNotEmpty($m[1]);
            foreach ($m[1] as $rhs) {
                $this->assertMatchesRegularExpression(
                    '/^(\[\]|rag_retriever::retrieve\()/',
                    trim($rhs),
                    "{$file} assigns retrieved chunks from somewhere other than retrieve(): {$rhs}"
                );
            }
        }

        $retriever = file_get_contents($dir . 'classes/rag_retriever.php');
        $this->assertMatchesRegularExpression('/rag_visibility::filter\(/', $retriever);
    }
}
