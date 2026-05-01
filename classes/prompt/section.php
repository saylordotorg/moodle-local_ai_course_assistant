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
 * One homogeneous section of an assembled system prompt.
 *
 * v4.12.0 — replaces the linear concat-in-place style of v4.11 and earlier
 * with a structured representation so the assembler can:
 *   1. Group sections by category (so the model sees identity / context /
 *      behaviour / markers / safety as separate, internally consistent
 *      blocks rather than one undifferentiated stream — this is Tomi
 *      Molnár's "context homogeneity" critique).
 *   2. Apply per-category and per-section token budgets and gracefully
 *      drop lower-priority sections when the total exceeds the budget.
 *   3. Surface per-section sizing in the prompt-debug log so we can keep
 *      measuring objectively.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class section {

    /** @var string Category constants — used for stable section ordering and budgets. */
    public const CAT_IDENTITY = 'identity';
    public const CAT_CONTEXT  = 'context';
    public const CAT_LEARNER  = 'learner';
    public const CAT_BEHAVIOR = 'behavior';
    public const CAT_MARKERS  = 'markers';
    public const CAT_SAFETY   = 'safety';

    /** @var string Stable ordering used when assembling: top to bottom. */
    public const CATEGORY_ORDER = [
        self::CAT_IDENTITY,
        self::CAT_CONTEXT,
        self::CAT_LEARNER,
        self::CAT_BEHAVIOR,
        self::CAT_MARKERS,
        self::CAT_SAFETY,
    ];

    /** @var string Stable section name (used as map key + log label). */
    public string $name;

    /** @var string One of CAT_* category constants. */
    public string $category;

    /** @var int 1-100 — higher means more essential, kept first under budget pressure. */
    public int $priority;

    /** @var string The actual prompt text for this section. */
    public string $content;

    /** @var int Lower bound — never truncate this section below this many chars. */
    public int $min_chars;

    /**
     * @param string $name
     * @param string $category
     * @param int $priority
     * @param string $content
     * @param int $min_chars
     */
    public function __construct(string $name, string $category, int $priority, string $content, int $min_chars = 0) {
        $this->name = $name;
        $this->category = $category;
        $this->priority = $priority;
        $this->content = $content;
        $this->min_chars = $min_chars;
    }

    /**
     * Length of the section's content in characters.
     *
     * @return int
     */
    public function length(): int {
        return strlen($this->content);
    }
}
