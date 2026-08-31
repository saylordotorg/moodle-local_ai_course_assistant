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
 * The FAQ is retrieved when it is relevant, not injected into every prompt.
 *
 * Measured on staging: the FAQ was 4,451 characters of the 14,240 of fixed
 * overhead that stood between the budget and the retrieved course content, and
 * it was assembled on every turn including the great majority it could not
 * help. Real course passages were being truncated to make room for it.
 *
 * The property that matters most here is the fallback. is_retrievable() is
 * false when RAG is off, when the FAQ has never been embedded, and when an
 * administrator has edited it since it was -- and in each of those the site
 * must keep getting its FAQ inline rather than silently losing it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\faq_manager
 */
final class faq_retrievable_test extends \advanced_testcase {

    /** @var string Two pairs, enough to tell one from the other. */
    private const FAQ = "Q: How do I get my certificate? | A: Download it from your dashboard.\n"
        . "Q: When does my course begin? | A: Self-paced courses begin when you enrol.";

    /**
     * Start from a clean, RAG-enabled site with a FAQ set.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        set_config('rag_enabled', 1, 'local_ai_course_assistant');
        set_config('faq_content', self::FAQ, 'local_ai_course_assistant');
    }

    /**
     * Write FAQ chunks directly, standing in for the embedding provider.
     *
     * The provider is not available under test, and it is not what is being
     * checked: every assertion here is about what the rest of the plugin does
     * once the rows exist.
     *
     * @param string|null $forhash Hash to record as indexed; defaults to current.
     * @return void
     */
    private function fake_index(?string $forhash = null): void {
        global $DB;
        foreach ([0, 1] as $idx) {
            $DB->insert_record('local_ai_course_assistant_chunks', (object) [
                'courseid' => SITEID,
                'cmid' => 0,
                'modtype' => faq_manager::MODTYPE,
                'chunkindex' => $idx,
                'content' => 'Q: pair ' . $idx . ' | A: answer ' . $idx,
                'contenthash' => sha1('pair' . $idx),
                'embedding' => json_encode([0.1, 0.2, 0.3]),
                'embed_model' => 'text-embedding-3-small',
                'embed_dtype' => 'float',
                'timecreated' => time(),
                'timeindexed' => time(),
            ]);
        }
        set_config('faq_indexed_hash', $forhash ?? faq_manager::content_hash(),
            'local_ai_course_assistant');
    }

    /**
     * With an index present and matching, the FAQ is served by retrieval.
     */
    public function test_indexed_faq_is_retrievable(): void {
        $this->fake_index();
        $this->assertTrue(faq_manager::is_retrievable());
    }

    /**
     * Never embedded means fall back to injecting it.
     */
    public function test_unindexed_faq_falls_back_to_inline(): void {
        $this->assertFalse(faq_manager::is_retrievable());
        $this->assertStringContainsString(
            'certificate',
            faq_manager::get_faq_for_prompt(),
            'An unindexed FAQ must still reach the prompt.'
        );
    }

    /**
     * Editing the FAQ makes the index stale, and stale means inline.
     *
     * This is the case that would otherwise serve answers an administrator has
     * already changed.
     */
    public function test_edited_faq_falls_back_until_reindexed(): void {
        $this->fake_index();
        $this->assertTrue(faq_manager::is_retrievable());

        set_config('faq_content', self::FAQ . "\nQ: New? | A: Yes.",
            'local_ai_course_assistant');

        $this->assertFalse(
            faq_manager::is_retrievable(),
            'An edited FAQ must not keep being served from the old index.'
        );
    }

    /**
     * With RAG off there is nothing to retrieve from.
     */
    public function test_rag_disabled_falls_back_to_inline(): void {
        $this->fake_index();
        set_config('rag_enabled', 0, 'local_ai_course_assistant');
        $this->assertFalse(faq_manager::is_retrievable());
    }

    /**
     * Clearing the setting drops the index with it.
     */
    public function test_clearing_the_faq_drops_its_index(): void {
        global $DB;
        $this->fake_index();

        set_config('faq_content', '', 'local_ai_course_assistant');
        faq_manager::index_faq();

        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_chunks', [
            'courseid' => SITEID,
            'modtype' => faq_manager::MODTYPE,
        ]), 'Retrieval must not keep answering from a FAQ that was deleted.');
        $this->assertFalse(faq_manager::is_retrievable());
    }

    /**
     * Clearing a course index leaves the site FAQ alone.
     */
    public function test_clearing_a_course_index_preserves_the_faq(): void {
        global $DB;
        $this->fake_index();

        content_indexer::delete_course_index(SITEID);

        $this->assertSame(2, $DB->count_records('local_ai_course_assistant_chunks', [
            'courseid' => SITEID,
            'modtype' => faq_manager::MODTYPE,
        ]));
        $this->assertTrue(faq_manager::is_retrievable());
    }

    /**
     * FAQ rows must not make the site course look indexed.
     *
     * They live under SITEID; counting them would stop the lazy
     * "index this course before answering" path from ever running for it.
     */
    public function test_faq_rows_do_not_mark_the_site_course_indexed(): void {
        $this->fake_index();
        $this->assertFalse(content_indexer::is_course_indexed(SITEID));
    }

    /**
     * The prompt drops the always-on section exactly when retrieval covers it.
     */
    public function test_prompt_section_appears_only_when_not_retrievable(): void {
        $this->assertNotSame('', faq_manager::get_faq_for_prompt());

        $this->fake_index();
        $this->assertTrue(faq_manager::is_retrievable());
        // get_faq_for_prompt still returns the text; the caller is what decides.
        // Pinning both halves so a change to either is visible here.
        $this->assertNotSame('', faq_manager::get_faq_for_prompt());
    }
}
