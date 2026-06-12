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
 * Prompt debug log: entry formatting, file rotation, and entry parsing.
 *
 * Extracted (v6.6.0) from the inline implementations in sse.php (writer) and
 * prompt_debug_view.php (parser) so both share one definition and PHPUnit can
 * exercise the full write -> parse round trip without a web request. The log
 * format is the contract between the two halves; tests/prompt_debug_test.php
 * pins it.
 *
 * The log contains learner messages: treat it as PII once enabled. The file
 * lives at moodledata/temp/sola_prompt_debug.log and rotates at 1 MiB.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prompt_debug {

    /** Rotation threshold in bytes. */
    const MAX_LOG_BYTES = 1048576;

    /**
     * Absolute path of the debug log file.
     *
     * @return string
     */
    public static function log_path(): string {
        global $CFG;
        return $CFG->dataroot . '/temp/sola_prompt_debug.log';
    }

    /**
     * Build one log entry string from explicit inputs (no globals).
     *
     * @param array $p Entry parts:
     *   - timestamp (string)         'YYYY-MM-DD HH:MM:SS'
     *   - courseid (int)
     *   - userid (int)
     *   - provider (string)
     *   - systemprompt (string)      assembled system prompt
     *   - budgetchars (int)
     *   - breakdowntext (?string)    preformatted section breakdown, or null
     *   - retrievedchunks (array)    each: content, score, cmid, modtype
     *   - history (array)            each: role, content
     *   - message (string)           current user message
     *   - attachmentmeta (?array)    filename, mime, size — or null
     *   - pdfextractedchars (int)    0 when no extracted PDF text
     * @return string
     */
    public static function format_entry(array $p): string {
        $systemprompt = (string) ($p['systemprompt'] ?? '');
        $entry = "=== " . $p['timestamp'] . " courseid={$p['courseid']} userid={$p['userid']} provider="
            . ($p['provider'] ?? '') . " ===\n"
            . "Total: " . strlen($systemprompt) . " chars (~" . (int) (strlen($systemprompt) / 4) . " tokens)\n"
            . "Budget: " . (int) ($p['budgetchars'] ?? 8000) . " chars\n";

        if (!empty($p['breakdowntext'])) {
            $entry .= "Sections (by category):\n" . $p['breakdowntext'] . "\n";
        } else {
            // Fallback: heading-based split for legacy paths.
            $rows = [];
            foreach (preg_split('/\n## /', $systemprompt) as $i => $section) {
                $heading = $i === 0 ? 'PREAMBLE' : trim(substr($section, 0, strpos("\n", $section) ?: 60), "# \n");
                $rows[] = sprintf("%6d chars  %s", strlen($section), $heading);
            }
            $entry .= "Sections:\n" . implode("\n", $rows) . "\n";
        }

        // Retrieved RAG chunks with score and source, so the debug page can
        // show WHICH passages the retriever selected and how strongly they
        // matched. Only present when RAG retrieved.
        $chunks = $p['retrievedchunks'] ?? [];
        if (!empty($chunks)) {
            $entry .= "--- RETRIEVED CHUNKS (" . count($chunks) . ", top-k by relevance) ---\n";
            foreach (array_values($chunks) as $ci => $ch) {
                $cscore = isset($ch['score']) ? number_format((float) $ch['score'], 4) : 'n/a';
                $ccmid = isset($ch['cmid']) ? (int) $ch['cmid'] : 0;
                $cmod = (string) ($ch['modtype'] ?? '');
                $ccontent = (string) ($ch['content'] ?? '');
                $entry .= "[c:{$ci}] score={$cscore} cmid={$ccmid} modtype={$cmod} ("
                    . strlen($ccontent) . " chars)\n" . $ccontent . "\n\n";
            }
        }
        $entry .= "--- ASSEMBLED SYSTEM PROMPT ---\n" . $systemprompt . "\n\n";

        // History + current user message — the ACTUAL final payload going to
        // the model; the system prompt alone does not reveal history drift.
        $history = $p['history'] ?? [];
        $entry .= "--- HISTORY (" . count($history) . " messages) ---\n";
        foreach ($history as $i => $h) {
            $role = (string) ($h['role'] ?? '?');
            $content = (string) ($h['content'] ?? '');
            $entry .= "[{$i}] {$role} (" . strlen($content) . " chars): {$content}\n";
        }
        $message = (string) ($p['message'] ?? '');
        $entry .= "\n--- CURRENT USER MESSAGE (" . strlen($message) . " chars) ---\n" . $message . "\n";

        $attachmentmeta = $p['attachmentmeta'] ?? null;
        if ($attachmentmeta !== null) {
            $entry .= "\n--- ATTACHMENT ---\n"
                . "filename={$attachmentmeta['filename']} mime={$attachmentmeta['mime']} "
                . "size={$attachmentmeta['size']}\n";
            if (!empty($p['pdfextractedchars'])) {
                $entry .= "pdf_extracted_chars=" . (int) $p['pdfextractedchars'] . "\n";
            }
        }
        $entry .= "================================================================\n\n";
        return $entry;
    }

    /**
     * Append an entry to the log, truncating first when the file exceeds the
     * rotation threshold.
     *
     * @param string $entry
     * @return void
     */
    public static function write_entry(string $entry): void {
        $logpath = self::log_path();
        if (file_exists($logpath) && filesize($logpath) > self::MAX_LOG_BYTES) {
            file_put_contents($logpath, '');
        }
        file_put_contents($logpath, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Parse raw log content into structured entries, newest first.
     *
     * @param string $content Raw file content.
     * @param int $limit Maximum number of entries to return.
     * @return array<int, array<string, mixed>>
     */
    public static function parse_entries(string $content, int $limit): array {
        $blocks = preg_split('/^={60,}\s*$/m', $content, -1, PREG_SPLIT_NO_EMPTY);
        $blocks = array_reverse($blocks); // Most recent first.

        $out = [];
        foreach ($blocks as $block) {
            if (count($out) >= $limit) {
                break;
            }
            $block = trim($block);
            if ($block === '' || strpos($block, '=== ') !== 0) {
                continue;
            }
            $parsed = self::parse_one_entry($block);
            if ($parsed !== null) {
                $out[] = $parsed;
            }
        }
        return $out;
    }

    /**
     * Parse a single entry block into structured data for the mustache template.
     *
     * @param string $block Trimmed block content (no closing delimiter).
     * @return array<string, mixed>|null
     */
    public static function parse_one_entry(string $block): ?array {
        // Header line: === YYYY-MM-DD HH:MM:SS courseid=N userid=N provider=X ===.
        if (!preg_match('/^=== (.+?) ===\s*$/m', $block, $hm)) {
            return null;
        }
        $headerline = $hm[1];
        $timestamp = '';
        $courseid = 0;
        $userid = 0;
        $provider = '';
        if (preg_match('/^(\S+ \S+) courseid=(\d+) userid=(\d+) provider=(\S*)$/', $headerline, $pm)) {
            $timestamp = $pm[1];
            $courseid = (int) $pm[2];
            $userid = (int) $pm[3];
            $provider = $pm[4];
        } else {
            $timestamp = $headerline;
        }

        $total = '';
        $budget = '';
        if (preg_match('/^Total:\s*(.+)$/m', $block, $tm)) {
            $total = trim($tm[1]);
        }
        if (preg_match('/^Budget:\s*(.+)$/m', $block, $bm)) {
            $budget = trim($bm[1]);
        }

        // Section breakdown table (between "Sections" line and the next "--- " marker).
        $sectionstext = '';
        if (preg_match('/Sections[^\n]*:\n(.*?)(?=\n---|\n$)/s', $block, $sm)) {
            $sectionstext = rtrim($sm[1]);
        }

        $systemprompt = self::extract_named_block($block, 'ASSEMBLED SYSTEM PROMPT');
        $history = self::extract_named_block($block, 'HISTORY');
        $message = self::extract_named_block($block, 'CURRENT USER MESSAGE');
        $attachment = self::extract_named_block($block, 'ATTACHMENT');
        $chunks = self::extract_named_block($block, 'RETRIEVED CHUNKS');
        $chunkcount = ($chunks !== null) ? substr_count($chunks, '[c:') : 0;

        // Both badges require non-zero chars AND no DROPPED flag in the
        // breakdown line; TRUNCATED surfaces as its own state so the diagnosis
        // is unambiguous from the card header alone.
        $pagestate = self::section_state($sectionstext, 'current_page_content',
            (string) $systemprompt, '## Current Page Content');
        $topicstate = self::section_state($sectionstext, 'topic_focus',
            (string) $systemprompt, '## Current focus');

        return [
            'timestamp'         => $timestamp,
            'courseid'          => $courseid,
            'userid'            => $userid,
            'provider'          => $provider,
            'total'             => $total,
            'budget'            => $budget,
            'sections'          => $sectionstext,
            'system_prompt'     => (string) $systemprompt,
            'history'           => (string) $history,
            'message'           => (string) $message,
            'attachment'        => (string) $attachment,
            'has_attachment'    => $attachment !== null && trim($attachment) !== '',
            'chunks'            => (string) $chunks,
            'has_chunks'        => $chunks !== null && trim((string) $chunks) !== '',
            'chunk_count'       => $chunkcount,
            'page_state'        => $pagestate,
            'page_kept'         => $pagestate === 'kept',
            'page_truncated'    => $pagestate === 'truncated',
            'page_dropped'      => $pagestate === 'dropped',
            'page_absent'       => $pagestate === 'absent',
            'topic_state'       => $topicstate,
            'topic_kept'        => $topicstate === 'kept',
            'topic_truncated'   => $topicstate === 'truncated',
            'topic_dropped'     => $topicstate === 'dropped',
            'topic_absent'      => $topicstate === 'absent',
        ];
    }

    /**
     * Resolve a section's state from the breakdown text and (as a fallback)
     * from whether its heading appears in the assembled prompt body.
     *
     * @param string $sectionstext The "Sections (by category):" body.
     * @param string $sectionname  e.g. "current_page_content".
     * @param string $promptbody   The full assembled system prompt.
     * @param string $heading      Heading to look for in the body.
     * @return string 'kept' | 'truncated' | 'dropped' | 'absent'
     */
    public static function section_state(string $sectionstext, string $sectionname,
            string $promptbody, string $heading): string {
        $pattern = '/^\s*(\d+)\s+' . preg_quote($sectionname, '/') . '\b(.*)$/m';
        if (preg_match($pattern, $sectionstext, $m)) {
            $chars = (int) $m[1];
            $flags = (string) $m[2];
            if (stripos($flags, 'DROPPED') !== false || $chars === 0) {
                return 'dropped';
            }
            if (stripos($flags, 'TRUNCATED') !== false) {
                return 'truncated';
            }
            return 'kept';
        }
        if ($heading !== '' && strpos($promptbody, $heading) !== false) {
            return 'kept';
        }
        return 'absent';
    }

    /**
     * Extract the body of a `--- NAME ---` block within an entry. Returns null
     * when the named block is absent (so the template can hide its row).
     *
     * @param string $block
     * @param string $name
     * @return string|null
     */
    public static function extract_named_block(string $block, string $name): ?string {
        $pattern = '/--- ' . preg_quote($name, '/') . '[^\n]*---\n(.*?)(?=\n--- |\Z)/s';
        if (preg_match($pattern, $block, $m)) {
            return rtrim($m[1]);
        }
        return null;
    }

    /**
     * Split pasted playground text into simulated chunk arrays. Chunks are
     * separated by lines containing only `---`; blanks are skipped.
     *
     * @param string $raw
     * @return array<int, array<string, mixed>>
     */
    public static function sim_chunks_from_text(string $raw): array {
        $chunks = [];
        $parts = preg_split('/^\s*---\s*$/m', $raw);
        foreach ($parts as $i => $part) {
            $part = trim($part);
            if ($part !== '') {
                $chunks[] = [
                    'content'    => $part,
                    'score'      => 1.0,
                    'cmid'       => 0,
                    'modtype'    => 'sim',
                    'chunkindex' => $i,
                ];
            }
        }
        return $chunks;
    }
}
