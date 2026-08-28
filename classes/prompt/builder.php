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

namespace local_ai_course_assistant\prompt;

defined('MOODLE_INTERNAL') || die();

/**
 * Assemble a list of {@see section} objects into a final system prompt
 * within a total character budget.
 *
 * Ordering: sections are emitted by category in {@see section::CATEGORY_ORDER}
 * (identity → context → learner → behavior → markers → safety). Within a
 * category, sections are ordered by descending priority. The category headings
 * themselves are NOT printed — categories are an organisational concept, not
 * a literal prompt heading. Each section's `## Heading` already lives in its
 * content.
 *
 * Budget pressure: when total content exceeds the budget, sections are dropped
 * in ascending priority order until the prompt fits. Sections with `min_chars > 0`
 * are truncated rather than dropped (used for context blocks like RAG passages
 * where partial content is still useful). The safety category is exempt from
 * truncation — security guidance always lands in full.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class builder {
    /**
     * Assemble the final prompt and per-section breakdown.
     *
     * @param section[] $sections
     * @param int $budget_chars Total budget for the assembled prompt (0 = no limit).
     * @return array{prompt: string, breakdown: array<string, array{category: string, priority: int, chars: int, used: bool, truncated: bool}>}
     */
    public static function assemble(array $sections, int $budget_chars = 0): array {
        // Group by category in canonical order, then sort by descending priority.
        $by_category = array_fill_keys(section::CATEGORY_ORDER, []);
        foreach ($sections as $sec) {
            if (!isset($by_category[$sec->category])) {
                // Unknown category lands at the end.
                $by_category[$sec->category] = [];
            }
            $by_category[$sec->category][] = $sec;
        }
        foreach ($by_category as &$bucket) {
            usort($bucket, function (section $a, section $b): int {
                return $b->priority <=> $a->priority;
            });
        }
        unset($bucket);

        $breakdown = [];
        $dropped = [];
        $truncated = [];

        // v5.6.0: enforce per-section max_chars BEFORE the drop-on-priority
        // fallback. The proportional-budget model in context_builder writes
        // each section's share into max_chars. Sections that exceed their
        // cap get truncated to that cap (preserving min_chars when set);
        // legacy callers that pass max_chars=0 keep the prior unlimited
        // behavior. CAT_SAFETY sections are exempt and never get clipped.
        foreach ($sections as $sec) {
            if ($sec->category === section::CAT_SAFETY || $sec->max_chars <= 0) {
                continue;
            }
            if ($sec->length() <= $sec->max_chars) {
                continue;
            }
            $newlen = max($sec->min_chars, $sec->max_chars);
            if ($newlen >= $sec->length()) {
                continue;
            }
            $sec->content = self::truncate_content($sec->content, $newlen);
            $truncated[$sec->name] = true;
        }

        // Compute current total. If it fits, emit unchanged.
        $total = 0;
        foreach ($sections as $sec) {
            $total += $sec->length();
        }

        if ($budget_chars > 0 && $total > $budget_chars) {
            // Build a flat list of (category, section) sorted by priority asc,
            // skipping the safety category. Drop sections from the front until
            // we fit. Sections with min_chars > 0 truncate instead.
            $candidates = [];
            foreach (section::CATEGORY_ORDER as $cat) {
                if ($cat === section::CAT_SAFETY) {
                    continue;
                }
                foreach ($by_category[$cat] ?? [] as $sec) {
                    $candidates[] = $sec;
                }
            }
            usort($candidates, function (section $a, section $b): int {
                return $a->priority <=> $b->priority;
            });

            foreach ($candidates as $sec) {
                if ($total <= $budget_chars) {
                    break;
                }
                $excess = $total - $budget_chars;
                if ($sec->min_chars > 0 && $sec->length() - $excess >= $sec->min_chars) {
                    // Truncate from the end with an ellipsis marker.
                    $newlen = max($sec->min_chars, $sec->length() - $excess);
                    $sec->content = self::truncate_content($sec->content, $newlen);
                    $total = 0;
                    foreach ($sections as $s) {
                        $total += $s->length();
                    }
                    $truncated[$sec->name] = true;
                } else {
                    // Drop the section entirely.
                    $total -= $sec->length();
                    $sec->content = '';
                    $dropped[$sec->name] = true;
                }
            }
        }

        // Emit in canonical order. Skip empty content (dropped sections).
        $parts = [];
        foreach (section::CATEGORY_ORDER as $cat) {
            foreach ($by_category[$cat] ?? [] as $sec) {
                if ($sec->length() === 0) {
                    $breakdown[$sec->name] = [
                        'category'  => $sec->category,
                        'priority'  => $sec->priority,
                        'chars'     => 0,
                        'used'      => false,
                        'truncated' => false,
                    ];
                    continue;
                }
                $parts[] = $sec->content;
                $breakdown[$sec->name] = [
                    'category'  => $sec->category,
                    'priority'  => $sec->priority,
                    'chars'     => $sec->length(),
                    'used'      => true,
                    'truncated' => isset($truncated[$sec->name]),
                ];
            }
        }

        return [
            'prompt'    => implode("\n", $parts),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Format a category-level summary suitable for the prompt-debug log.
     *
     * @param array $breakdown Output of {@see assemble}.
     * @return string
     */
    public static function format_breakdown(array $breakdown): string {
        $by_cat = [];
        foreach ($breakdown as $name => $info) {
            $by_cat[$info['category']][] = compact('name', 'info');
        }
        $lines = [];
        foreach (section::CATEGORY_ORDER as $cat) {
            if (empty($by_cat[$cat])) {
                continue;
            }
            $cat_total = 0;
            foreach ($by_cat[$cat] as $row) {
                $cat_total += $row['info']['chars'];
            }
            $lines[] = sprintf("[%s] %d chars total", $cat, $cat_total);
            foreach ($by_cat[$cat] as $row) {
                $flags = [];
                if (!$row['info']['used']) {
                    $flags[] = 'DROPPED';
                }
                if ($row['info']['truncated']) {
                    $flags[] = 'TRUNCATED';
                }
                $lines[] = sprintf(
                    "    %5d  %s%s",
                    $row['info']['chars'],
                    $row['name'],
                    $flags ? ' [' . implode(',', $flags) . ']' : ''
                );
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Truncate a section, leaving any untrusted fence it opened properly closed.
     *
     * Course content is wrapped by security::fence_untrusted() in
     * "[[UNTRUSTED ...]] ... [[/UNTRUSTED ...]]" markers that tell the model the
     * enclosed text is reference material and must never be followed as
     * instructions. A byte-wise cut lands mid-fence and discards the closing
     * marker, so everything after it in the assembled prompt -- persona, house
     * style, output markers and the safety block itself -- reads as if it were
     * inside the untrusted region. Re-close whatever the cut left open.
     *
     * @param string $content
     * @param int $newlen Byte length to cut to.
     * @return string
     */
    private static function truncate_content(string $content, int $newlen): string {
        $cut = substr($content, 0, $newlen);

        // A cut can land part-way through a marker, leaving a dangling "[[UNTRU"
        // or "[[/UNTRU". Drop the fragment FIRST, then count -- deciding what is
        // unclosed before removing it gets the arithmetic wrong whenever the
        // fragment happens to be a closing marker.
        $lastmarker = strrpos($cut, '[[');
        if ($lastmarker !== false && strpos($cut, ']]', $lastmarker) === false) {
            $cut = substr($cut, 0, $lastmarker);
        }

        // Labels, in the order they were opened.
        preg_match_all('/\[\[UNTRUSTED ([^\]]*?)\s*\x{2014}/u', $cut, $opens);
        $openlabels = $opens[1] ?? [];
        $closecount = substr_count($cut, '[[/UNTRUSTED');

        $unclosed = array_slice($openlabels, $closecount);
        foreach (array_reverse($unclosed) as $label) {
            $cut .= "\n[[/UNTRUSTED " . $label . "]]";
        }

        return $cut . "\n[…truncated by prompt budget…]";
    }
}
