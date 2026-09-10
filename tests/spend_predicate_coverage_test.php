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
 * Guard: a spend row that is WRITTEN must also be COUNTED.
 *
 * analytics::spend_rows_predicate() is an allow-list for role='system' rows,
 * and every spend consumer in the plugin selects through it. So adding a new
 * telemetry writer without adding its interaction_type to that list is not a
 * partial fix, it is a no-op: the row lands in the table with correct tokens,
 * and the dashboard, the spend guard and the anomaly detector all skip it.
 *
 * This has now happened three times. RAG spend read $0.00 for exactly this
 * reason. Quiz telemetry did too, until v7.0.6. And v7.4.2 initially shipped
 * the flashcards/essay/insights writers with the predicate untouched, which
 * 1487 passing tests did not catch, because every test asserted that the ROW
 * EXISTED and none asserted that anything counted it.
 *
 * The first test is the structural lint that closes the class of bug. The
 * others are the end-to-end assertion that the three v7.4.2 types actually
 * reach the two functions the AI Spend dashboard is built on.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\analytics::spend_rows_predicate
 */
final class spend_predicate_coverage_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Interaction types that are written but deliberately NOT billable.
     *
     * Every one of these is a zero-token MARKER row: a decision was recorded,
     * no provider was called, and admitting it would put a $0.00 row in the
     * spend population. The list is here, and not in a comment, so that adding
     * a new interaction_type is a deliberate act in one of two directions --
     * into the predicate, or into this array with a reason -- rather than
     * something that can be done by accident. That is the whole point: the
     * failure this test exists to catch is a writer nobody remembered to
     * account for.
     *
     * @var array<string, string>
     */
    private const NOT_BILLABLE = [
        'chat'           => "role='assistant'; matched by the predicate's first arm, not the allow-list",
        'premium_route'  => 'escalation DECISION marker, zero tokens; the escalated call is logged separately',
        'rerank_skipped' => 'records that rerank did NOT run, so there is nothing to bill',
        'quiz_open'      => 'a learner opened a quiz; no provider call',
    ];

    /**
     * Every billable interaction_type any writer emits is listed in the
     * predicate.
     *
     * STRUCTURAL, and it has to be. The previous version of this test was a
     * hand-maintained array of type names checked against the predicate string,
     * which fails only when a type is REMOVED from the predicate -- the one
     * direction the trap does not fail in. Replay the v7.4.2 incident against
     * it: a developer adds a sixth log_ancillary_usage() writer with a new
     * interaction_type and does not touch the predicate. They do not touch the
     * array either, because touching it is the same act of remembering as
     * touching the predicate. Green suite, row written, dashboard reads $0.00.
     *
     * So this reads the WRITERS. Two scans, because there are two shapes:
     * the 4th argument of log_ancillary_usage()/log_usage_array(), which is the
     * family that shipped this bug twice; and every interaction_type literal
     * assigned onto a msgs row anywhere in the plugin. A type found by either
     * scan must be in the predicate or in NOT_BILLABLE with a reason.
     */
    public function test_every_written_interaction_type_is_counted(): void {
        $predicate = analytics::spend_rows_predicate('m');
        $discovered = self::discover_written_types();

        // A scan that finds nothing would pass silently, which is the same
        // failure mode in a different costume. Floor it against the count known
        // at v7.4.4 so a broken regex is a failure, not a green run.
        $this->assertGreaterThanOrEqual(
            18,
            count($discovered),
            'the source scan found only ' . count($discovered) . ' interaction types, so the scan '
            . 'itself is broken -- a lint that discovers nothing asserts nothing'
        );

        $missing = [];
        foreach ($discovered as $type => $writers) {
            if (isset(self::NOT_BILLABLE[$type])) {
                continue;
            }
            if (strpos($predicate, "'{$type}'") === false) {
                $missing[] = "'{$type}' (written by " . implode(', ', array_unique($writers)) . ')';
            }
        }

        $this->assertSame([], $missing,
            "A telemetry writer emits an interaction_type that spend_rows_predicate() does not admit.\n"
            . "The predicate is an ALLOW-LIST for role='system' rows, so those rows are written,\n"
            . "counted by nothing, and priced at zero -- the row exists, and the dashboard reads \$0.00.\n"
            . "Add the type to analytics::spend_rows_predicate(), or -- if the row is a zero-token\n"
            . "marker with no provider call behind it -- to this test's NOT_BILLABLE list with a reason:\n  "
            . implode("\n  ", $missing));
    }

    /**
     * The scan and the expected set agree in both directions.
     *
     * The structural scan above is what closes the class of bug; this is the
     * cross-check that keeps the scan honest. A RENAME (say 'flashcards' to
     * 'flashcard') would sail past a scan-only test, because the new name would
     * be discovered and dutifully required in the predicate -- where a
     * developer would dutifully put it -- while every existing row and every
     * query written against the old name silently stopped matching. Listing the
     * names once, here, makes that rename a test failure that says so.
     */
    public function test_the_scan_and_the_expected_set_agree(): void {
        // The types the billable writers emit, as of v7.4.4.
        $expected = [
            'quiz'       => 'conversation_manager::record_quiz_usage',
            'flashcards' => 'generate_flashcards via log_ancillary_usage',
            'essay'      => 'score_essay via log_ancillary_usage',
            'insights'   => 'generate_insights via log_ancillary_usage',
            'embedding'  => 'base_embedding_provider::log_embedding_cost',
            'rerank'     => 'voyage_reranker::log_rerank_cost',
            // v7.4.4: five calls that were billed and counted by nothing.
            'mastery_signal'    => 'conversation_classifier::classify_and_record',
            'student_profile'   => 'student_profile_manager::generate_profile',
            'speech_score'      => 'external\\score_speech::execute',
            'objective_extract' => 'objective_manager::extract_via_llm',
            'slide_vision'      => 'soapbox_slide_vision::design_note',
            'model_bench'       => 'run_model_benchmark via flush_bench_spend',
            // Seven voice types have been in the predicate since v7.3.3.
            'voice'           => 'voice_registry::interaction_type fallback',
            'openai_tts'      => 'tts.php via voice_registry::interaction_type',
            'xai_tts'         => 'tts.php via voice_registry::interaction_type',
            'openai_whisper'  => 'transcribe.php via voice_registry::interaction_type',
            'openai_stt'      => 'transcribe.php via voice_registry::interaction_type',
            'xai_stt'         => 'transcribe.php via voice_registry::interaction_type',
            'selfhosted_stt'  => 'transcribe.php via voice_registry::interaction_type',
        ];

        $discovered = self::discover_written_types();
        $billable = array_diff(array_keys($discovered), array_keys(self::NOT_BILLABLE));
        sort($billable);
        $expectedkeys = array_keys($expected);
        sort($expectedkeys);

        $this->assertSame($expectedkeys, $billable,
            "The billable interaction types found in the source no longer match the expected set.\n"
            . "A NEW type here means a new billable writer: add it to this list AND to\n"
            . "analytics::spend_rows_predicate(). A MISSING type means a rename or a deleted\n"
            . "writer -- every stored row and every query using the old name is now orphaned.\n"
            . 'Found: ' . implode(', ', $billable));
    }

    /**
     * Read the writers and return every interaction_type literal they emit.
     *
     * @return array<string, string[]> type => the files that write it.
     */
    private static function discover_written_types(): array {
        $root = dirname(__DIR__);
        $found = [];

        foreach (self::source_files($root) as $file) {
            $src = file_get_contents($file);
            if ($src === false) {
                continue;
            }
            $label = ltrim(str_replace($root, '', $file), '/');

            // 1. The ancillary/telemetry family: the 4th positional argument of
            //    conversation_manager::log_ancillary_usage() / log_usage_array().
            //    These write role='system' rows unconditionally, so every type
            //    here is billable by construction and gets no exclusion.
            // Qualified on the class name deliberately: an unqualified match
            // also hits conversation_manager's own declaration and its internal
            // self:: delegation, whose 4th "argument" is the parameter variable.
            // Every real writer calls it as conversation_manager::.
            if (preg_match_all(
                    '/conversation_manager::log_(?:ancillary_usage|usage_array)\s*\((.*?)\)\s*;/s',
                    $src,
                    $calls
            )) {
                foreach ($calls[1] as $arglist) {
                    $args = self::split_arguments($arglist);
                    if (count($args) < 4) {
                        continue;
                    }
                    $type = $args[3];
                    if (!preg_match("/^'([a-z_]+)'\$/", $type, $lit)) {
                        // A computed 4th argument is invisible to this lint, and
                        // the lint is the only thing standing between a new
                        // writer and a silent $0.00. Say so rather than skip it.
                        throw new \coding_exception(
                            "A log_ancillary_usage()/log_usage_array() call in {$label} passes a "
                            . "computed interaction_type ({$type}). Pass a literal, or this lint "
                            . 'cannot verify it reaches spend_rows_predicate().'
                        );
                    }
                    $found[$lit[1]][] = $label;
                }
            }

            // 2. Every interaction_type literal assigned onto a msgs row. Catches
            //    record_quiz_usage(), the embedding and rerank cost logs, and the
            //    marker rows -- which is why NOT_BILLABLE exists.
            // A single '=', never '==', '===' or '=>': a COMPARISON against an
            // interaction type is not a writer, and treating one as a write
            // reports types this predicate has no business admitting.
            if (preg_match_all('/->interaction_type\s*=(?![=>])\s*([^;]+);/', $src, $assigns)) {
                foreach ($assigns[1] as $rhs) {
                    if (preg_match_all("/'([a-z_]+)'/", $rhs, $lits)) {
                        foreach ($lits[1] as $type) {
                            $found[$type][] = $label;
                        }
                    }
                }
            }

            // 3. voice_registry::interaction_type() is a lookup table, so its
            //    types never appear at a writer's call site at all -- tts.php and
            //    transcribe.php pass the function's RESULT. Read the table.
            if (substr($label, -strlen('voice_registry.php')) === 'voice_registry.php'
                    && preg_match('/function interaction_type\s*\(.*?\n    \}/s', $src, $body)) {
                // Only the VALUES are interaction types: the map's keys are
                // capabilities ('tts', 'stt') and provider ids. So match a
                // literal only where it follows => or the ?? default.
                if (preg_match_all("/(?:=>|\?\?)\s*'([a-z_]+)'/", $body[0], $lits)) {
                    foreach ($lits[1] as $type) {
                        $found[$type][] = $label;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Every plugin PHP source file worth scanning.
     *
     * @param string $root Plugin root.
     * @return string[]
     */
    private static function source_files(string $root): array {
        $out = glob($root . '/*.php') ?: [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/classes', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }
        return $out;
    }

    /**
     * Split a PHP argument list on top-level commas.
     *
     * @param string $arglist Raw text between the call's parentheses.
     * @return string[] Trimmed arguments in order.
     */
    private static function split_arguments(string $arglist): array {
        $args = [];
        $buf = '';
        $depth = 0;
        $quote = null;
        $len = strlen($arglist);
        for ($i = 0; $i < $len; $i++) {
            $ch = $arglist[$i];
            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === '\\') {
                    if ($i + 1 < $len) {
                        $buf .= $arglist[++$i];
                    }
                } else if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $buf .= $ch;
                continue;
            }
            if ($ch === '(' || $ch === '[') {
                $depth++;
            } else if ($ch === ')' || $ch === ']') {
                $depth--;
            }
            if ($ch === ',' && $depth === 0) {
                $args[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $args[] = trim($buf);
        }
        return $args;
    }

    /**
     * The v7.4.2 ancillary rows reach the dashboard pull contract.
     */
    public function test_ancillary_rows_are_priced_by_monthly_provider_spend(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $month = date('Y-m');
        $baseline = analytics::get_monthly_provider_spend($month);
        $before = $baseline['by_provider']['google'] ?? 0.0;

        foreach (['flashcards', 'essay', 'insights'] as $type) {
            $this->write_row($user->id, $course->id, $type, 100000, 50000);
        }

        $after = analytics::get_monthly_provider_spend($month);
        $this->assertArrayHasKey('google', $after['by_provider'],
            'the ancillary rows did not reach get_monthly_provider_spend at all');
        $this->assertGreaterThan($before, $after['by_provider']['google'],
            'flashcards/essay/insights spend did not move the dashboard figure; '
            . 'the rows are being filtered out by spend_rows_predicate()');
        $this->assertSame(0, $after['unpriced_rows'],
            'gemini-2.5-flash must be priceable, or the caveat masks the miss');
    }

    /**
     * ...and the site-wide token total.
     */
    public function test_ancillary_rows_are_counted_by_total_tokens(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $before = analytics::get_total_tokens(0, 0);
        $this->write_row($user->id, $course->id, 'flashcards', 1000, 500);
        $this->write_row($user->id, $course->id, 'essay', 1000, 500);
        $this->write_row($user->id, $course->id, 'insights', 1000, 500);
        $after = analytics::get_total_tokens(0, 0);

        // 3 rows x (1000 prompt + 500 completion) = 4500, plus the reasoning
        // written by write_row(), which is a gemini model and so counts.
        $this->assertGreaterThanOrEqual($before + 4500, $after,
            'ancillary spend rows are not in the site-wide token total');
    }

    /**
     * Insert one priced system row of the given interaction type.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $type
     * @param int $prompt
     * @param int $completion
     * @return void
     */
    private function write_row(int $userid, int $courseid, string $type, int $prompt, int $completion): void {
        global $DB;
        $row = new \stdClass();
        $row->conversationid   = 1;
        $row->userid           = $userid;
        $row->courseid         = $courseid;
        $row->role             = 'system';
        $row->message          = "[{$type}]";
        $row->tokens_used      = $prompt + $completion;
        $row->prompt_tokens    = $prompt;
        $row->completion_tokens = $completion;
        $row->reasoning_tokens = 250;
        $row->model_name       = 'gemini-2.5-flash';
        $row->provider         = 'google';
        $row->interaction_type = $type;
        $row->timecreated      = time();
        $DB->insert_record('local_ai_course_assistant_msgs', $row);
    }
}
