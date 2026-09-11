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
 * The embedding path must survive a transient rate limit.
 *
 * Embeddings were the only provider path with no backoff: the chat path has had
 * base_provider::with_transient_retry() since v5.10.0, and this one threw on the
 * first 429. Combined with the FAQ embedding one Q/A pair at a time and being
 * all-or-nothing, a concurrent bulk re-embed made the site FAQ permanently
 * un-embeddable in production -- it failed at pair 4 on every attempt, because
 * every attempt restarted at pair 1.
 *
 * The consequence was silent: the retriever skips FAQ chunks left on the old
 * model, and context_builder falls back to injecting the whole FAQ inline, which
 * costs prompt budget on every turn with no error anywhere.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class embedding_rate_limit_test extends \advanced_testcase {

    /**
     * Read the embedding base class source.
     *
     * @return string
     */
    private function src(): string {
        global $CFG;
        $s = file_get_contents($CFG->dirroot
            . '/local/ai_course_assistant/classes/embedding_provider/base_embedding_provider.php');
        $this->assertNotFalse($s);
        return $s;
    }

    /**
     * A 429 must be marked transient so the retry wrapper can act on it.
     */
    public function test_rate_limit_is_marked_transient(): void {
        $this->resetAfterTest();
        $src = $this->src();

        $i = strpos($src, '$httpcode === 429');
        $this->assertNotFalse($i, 'the 429 branch is gone');
        // Generous window: the branch carries explanatory comments, and a slice
        // too small silently misses the assertion target and reports a defect
        // that is not there.
        $block = substr($src, $i, 2000);

        $this->assertMatchesRegularExpression(
            "/'transient'\s*=>\s*true/",
            $block,
            'A 429 must carry the transient marker in debuginfo, or the retry wrapper '
            . 'cannot distinguish it from a permanent failure and will rethrow immediately.'
        );
        $this->assertStringContainsString(
            'retry_after',
            $block,
            "Retry-After must be captured; a vendor's own hint beats a fixed backoff."
        );
    }

    /**
     * The POST must actually be wrapped in a bounded retry.
     */
    public function test_http_post_retries_with_bounded_backoff(): void {
        $this->resetAfterTest();
        $src = $this->src();

        $this->assertStringContainsString(
            'private function http_post_once(',
            $src,
            'http_post() must delegate to a single-attempt helper so it can retry it.'
        );
        $this->assertMatchesRegularExpression(
            '/backend_retry_attempts/',
            $src,
            'Retry depth must reuse the chat path\'s setting, so an operator tunes one '
            . 'knob rather than two that can disagree.'
        );
        $this->assertMatchesRegularExpression(
            '/backend_retry_max_wait/',
            $src,
            'A vendor Retry-After must be clamped, or a hostile or mistaken header '
            . 'could park a web request for minutes.'
        );

        // The retry must be bounded, never a bare while(true) with no exit.
        $i = strpos($src, 'protected function http_post(');
        $body = substr($src, $i, 2600);
        $this->assertMatchesRegularExpression(
            '/\$tries\s*>=\s*\$attempts/',
            $body,
            'The loop must exit on attempt count; an unbounded retry against a rate '
            . 'limit is worse than failing.'
        );
    }

    /**
     * The FAQ must embed in ONE batched call, not one per pair.
     */
    public function test_faq_embeds_in_a_single_batched_call(): void {
        global $CFG;
        $this->resetAfterTest();

        $src = file_get_contents($CFG->dirroot
            . '/local/ai_course_assistant/classes/faq_manager.php');
        $this->assertNotFalse($src);

        $i = strpos($src, 'function index_faq');
        $this->assertNotFalse($i);
        $body = substr($src, $i);

        $this->assertStringContainsString(
            '$provider->embed_batch(',
            $body,
            'index_faq must use the batch API. One call per Q/A pair is N chances to '
            . 'trip a rate limit for a run that restarts from pair 1 on any failure.'
        );
        $this->assertStringNotContainsString(
            '$provider->embed(',
            $body,
            'No per-pair embed() calls should remain in index_faq.'
        );
        $this->assertStringContainsString(
            'count($vectors) !== count($texts)',
            $body,
            'A short batch must be refused rather than written, or pairs are silently '
            . 'dropped from an index that then looks complete.'
        );
    }
}
