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
 * FAQ manager — parses and provides FAQ content for the AI system prompt.
 *
 * FAQ entries are stored in admin settings as plain text, one per line:
 * Q: question text | A: answer text
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class faq_manager {

    /**
     * modtype recorded on FAQ chunks, so they are distinguishable from course
     * material everywhere the index is read.
     */
    public const MODTYPE = 'faq';

    /**
     * Is the FAQ served by retrieval rather than injected into every prompt?
     *
     * The FAQ used to be assembled unconditionally: 4,451 characters on every
     * turn, including the great majority it cannot help. On a staging course
     * that was a third of the fixed overhead standing between the budget and
     * the retrieved course content, and it was pushing real passages out.
     *
     * Retrieval is only used when there is an index to retrieve from. If RAG is
     * off, or the FAQ has never been embedded, or an administrator has edited it
     * since, the caller falls back to injecting it -- a site must never silently
     * lose its FAQ, or keep answering from one that was already changed.
     *
     * Deliberately not memoized. It is one indexed lookup against a model call,
     * and a cached answer would go stale inside cron and between tests for no
     * measurable gain.
     *
     * @return bool
     */
    public static function is_retrievable(): bool {
        global $DB;

        if (!get_config('local_ai_course_assistant', 'rag_enabled')) {
            return false;
        }
        $raw = (string) get_config('local_ai_course_assistant', 'faq_content');
        if (trim($raw) === '') {
            return false;
        }
        if ((string) get_config('local_ai_course_assistant', 'faq_indexed_hash') !== self::content_hash($raw)) {
            return false;
        }

        return $DB->record_exists('local_ai_course_assistant_chunks', [
            'courseid' => SITEID,
            'modtype' => self::MODTYPE,
        ]);
    }

    /**
     * Hash of the FAQ setting, used to detect that the index is stale.
     *
     * @param string|null $raw Defaults to the stored setting.
     * @return string
     */
    public static function content_hash(?string $raw = null): string {
        if ($raw === null) {
            $raw = (string) get_config('local_ai_course_assistant', 'faq_content');
        }
        return sha1(trim($raw));
    }

    /**
     * Embed each FAQ pair as its own retrievable chunk.
     *
     * Stored against SITEID rather than duplicated into every course: the FAQ
     * is a single site-wide setting, and copying seventeen pairs into each of
     * thirty-odd course indexes would mean re-embedding all of them every time
     * an administrator fixes a typo.
     *
     * One chunk per question-and-answer pair, deliberately. Retrieval scores
     * whole chunks, so a pair is the unit that either answers the learner or
     * does not; splitting an answer across chunks would surface half of one.
     *
     * Idempotent and best-effort: unchanged content is a no-op, and a provider
     * failure leaves the previous index in place rather than a half-built one.
     *
     * @param bool $force Reindex even when the content hash is unchanged.
     * @return array{indexed: int, skipped: bool, error: string|null}
     */
    public static function index_faq(bool $force = false): array {
        global $DB;

        $out = ['indexed' => 0, 'skipped' => false, 'error' => null];

        $raw = (string) get_config('local_ai_course_assistant', 'faq_content');
        $hash = self::content_hash($raw);

        if (trim($raw) === '') {
            // The setting was cleared. Drop the index so retrieval cannot keep
            // serving answers an administrator has deleted.
            $DB->delete_records('local_ai_course_assistant_chunks', [
                'courseid' => SITEID,
                'modtype' => self::MODTYPE,
            ]);
            unset_config('faq_indexed_hash', 'local_ai_course_assistant');
            return $out;
        }

        if (!$force && (string) get_config('local_ai_course_assistant', 'faq_indexed_hash') === $hash
                && $DB->record_exists('local_ai_course_assistant_chunks',
                    ['courseid' => SITEID, 'modtype' => self::MODTYPE])) {
            $out['skipped'] = true;
            return $out;
        }

        $entries = self::parse_faq($raw);
        if (empty($entries)) {
            $out['error'] = 'faq_content is set but no Q/A pairs could be parsed';
            return $out;
        }

        try {
            $provider = \local_ai_course_assistant\embedding_provider\base_embedding_provider::create_from_config();
            $modelname = $provider->get_model();
            $dtype = $provider->effective_dtype();
        } catch (\Throwable $e) {
            $out['error'] = 'embedding provider unavailable: ' . $e->getMessage();
            return $out;
        }

        // Build every row before touching the table, so a provider failure
        // halfway through leaves the existing index intact.
        $records = [];
        foreach (array_values($entries) as $idx => $entry) {
            $text = 'Q: ' . $entry['question'] . "\nA: " . $entry['answer'];
            // Sanitised on the same terms as course material. The FAQ is
            // admin-authored rather than learner-authored, but it is still text
            // that re-enters the prompt at retrieval time.
            $sanitized = \local_ai_course_assistant\security::sanitize_rag_chunk($text);
            try {
                $vector = $provider->embed($sanitized['text']);
            } catch (\Throwable $e) {
                $out['error'] = 'embedding failed on pair ' . ($idx + 1) . ': ' . $e->getMessage();
                return $out;
            }
            if (empty($vector)) {
                $out['error'] = 'embedding returned nothing for pair ' . ($idx + 1);
                return $out;
            }

            $record = new \stdClass();
            $record->courseid    = SITEID;
            $record->cmid        = 0;
            $record->modtype     = self::MODTYPE;
            $record->chunkindex  = $idx;
            $record->content     = $sanitized['text'];
            $record->contenthash = sha1($sanitized['text']);
            $isfloat = ($dtype === \local_ai_course_assistant\embedding_compat::DTYPE_FLOAT);
            $record->embedding     = $isfloat ? json_encode($vector) : null;
            $record->embedding_bin = rag_retriever::pack_vector($vector, $dtype);
            $record->embed_model   = $modelname;
            $record->embed_dtype   = $dtype;
            $record->timecreated   = time();
            $record->timeindexed   = time();
            $records[] = $record;
        }

        $DB->delete_records('local_ai_course_assistant_chunks', [
            'courseid' => SITEID,
            'modtype' => self::MODTYPE,
        ]);
        foreach ($records as $record) {
            $DB->insert_record('local_ai_course_assistant_chunks', $record);
            $out['indexed']++;
        }
        set_config('faq_indexed_hash', $hash, 'local_ai_course_assistant');

        return $out;
    }
    /**
     * Get the FAQ content formatted for inclusion in the system prompt.
     *
     * @return string Formatted FAQ text, or empty string if no FAQ configured.
     */
    public static function get_faq_for_prompt(): string {
        $raw = get_config('local_ai_course_assistant', 'faq_content');
        if (empty($raw)) {
            return '';
        }

        $entries = self::parse_faq($raw);
        if (empty($entries)) {
            return '';
        }

        $lines = ["Here is a FAQ you should use to answer common support questions:"];
        foreach ($entries as $entry) {
            $lines[] = "Q: {$entry['question']}";
            $lines[] = "A: {$entry['answer']}";
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Parse raw FAQ text into structured entries.
     *
     * Supports two formats:
     * - Single line: Q: question | A: answer
     * - Multi-line: Q: question (newline) A: answer
     *
     * @param string $raw
     * @return array Array of ['question' => '...', 'answer' => '...'].
     */
    public static function parse_faq(string $raw): array {
        $entries = [];
        $lines = explode("\n", str_replace("\r\n", "\n", $raw));

        $currentq = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Single-line format: Q: ... | A: ...
            if (preg_match('/^Q:\s*(.+?)\s*\|\s*A:\s*(.+)$/i', $line, $matches)) {
                $entries[] = [
                    'question' => trim($matches[1]),
                    'answer' => trim($matches[2]),
                ];
                $currentq = null;
                continue;
            }

            // Multi-line format.
            if (preg_match('/^Q:\s*(.+)$/i', $line, $matches)) {
                $currentq = trim($matches[1]);
                continue;
            }

            if ($currentq !== null && preg_match('/^A:\s*(.+)$/i', $line, $matches)) {
                $entries[] = [
                    'question' => $currentq,
                    'answer' => trim($matches[1]),
                ];
                $currentq = null;
                continue;
            }
        }

        return $entries;
    }
}
