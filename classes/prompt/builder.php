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

        // Compute current total. If it fits, emit unchanged.
        $total = 0;
        foreach ($sections as $sec) {
            $total += $sec->length();
        }

        $breakdown = [];
        $dropped = [];
        $truncated = [];

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
                    $sec->content = substr($sec->content, 0, $newlen) . "\n[…truncated by prompt budget…]";
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
                $lines[] = sprintf("    %5d  %s%s",
                    $row['info']['chars'],
                    $row['name'],
                    $flags ? ' ['.implode(',', $flags).']' : '');
            }
        }
        return implode("\n", $lines);
    }
}
