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

use local_ai_course_assistant\provider\base_provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Optional slide-vision feedback for Soapbox presentations (v6.8.31, Phase 2
 * issue 15). When enabled, a single vision-capable pass (gpt-4o-mini by default)
 * looks at the rendered slide images and returns one short visual-design note,
 * complementing the text-only rubric scoring. Off by default and additionally
 * gated per assignment. Best-effort: any failure returns an empty note and the
 * rubric feedback is unaffected.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class soapbox_slide_vision {

    /** @var int Max slide images sent in the single vision pass (bounds cost). */
    const MAX_SLIDES = 12;

    /** @var int Max characters kept from the model's note. */
    const MAX_NOTE_CHARS = 800;

    /**
     * Whether the slide-vision pass should run for this assignment: the site
     * master toggle is on, the assignment opted in, and slides are enabled.
     *
     * @param object $assign Assignment record.
     * @return bool
     */
    public static function is_enabled(object $assign): bool {
        if (!get_config('local_ai_course_assistant', 'soapbox_slide_vision')) {
            return false;
        }
        return !empty($assign->slides_enabled) && !empty($assign->slide_vision);
    }

    /**
     * Evenly sample up to MAX_SLIDES images across the deck so long decks still
     * cost one bounded call while keeping first, last, and spread coverage.
     *
     * @param string[] $datauris Ordered slide image data URIs.
     * @return string[] At most MAX_SLIDES data URIs, in slide order.
     */
    public static function sample(array $datauris): array {
        $datauris = array_values($datauris);
        $n = count($datauris);
        if ($n <= self::MAX_SLIDES) {
            return $datauris;
        }
        $picked = [];
        // Even spread across [0, n-1] inclusive of both ends.
        for ($k = 0; $k < self::MAX_SLIDES; $k++) {
            $idx = (int) round($k * ($n - 1) / (self::MAX_SLIDES - 1));
            $picked[$idx] = $datauris[$idx];
        }
        ksort($picked);
        return array_values($picked);
    }

    /**
     * Produce a short visual-design note from the slide images. Returns an empty
     * string when there are no images, the provider is unavailable, or anything
     * fails; callers treat an empty note as "no vision feedback".
     *
     * @param string[] $datauris Rendered slide image data URIs (data:image/png;base64,...).
     * @param string $ptype Presentation type (informative, persuasive, ...).
     * @param int $courseid Course id (for provider resolution).
     * @return string
     */
    public static function design_note(array $datauris, string $ptype, int $courseid): string {
        $images = self::sample($datauris);
        if (empty($images)) {
            return '';
        }

        $ptype = trim($ptype) !== '' ? trim($ptype) : 'informative';
        $sysprompt = "You are a supportive presentation-design coach. You are shown the slide images from a "
            . "learner's {$ptype} presentation (a bounded sample if the deck is long). Comment ONLY on the "
            . "visual design of the slides: layout, visual hierarchy, text density, readability, consistency, "
            . "and use of visuals. Do not judge the spoken delivery or transcribe the text. Give two or three "
            . "sentences of encouraging, concrete feedback naming one clear strength and the single "
            . "highest-leverage visual improvement. Plain prose, no headings, no lists.";

        try {
            $provider = self::resolve_provider($courseid);
            $note = $provider->chat_completion(
                $sysprompt,
                [['role' => 'user', 'content' => 'Give the slide visual-design note now.']],
                ['image_datauris' => $images, 'max_tokens' => 300]
            );
        } catch (\Throwable $e) {
            return '';
        }

        $note = trim((string) $note);
        if ($note === '') {
            return '';
        }
        return \core_text::substr($note, 0, self::MAX_NOTE_CHARS);
    }

    /**
     * Resolve the provider for the vision pass. Defaults to the admin-configured
     * soapbox_vision_provider/model (openai / gpt-4o-mini) via the comparison
     * provider path, mirroring the mastery-classifier routing. Falls back to the
     * course's configured provider if that override is not usable.
     *
     * @param int $courseid
     * @return \local_ai_course_assistant\provider\provider_interface
     */
    private static function resolve_provider(int $courseid) {
        $providerid = trim((string) get_config('local_ai_course_assistant', 'soapbox_vision_provider'));
        $model = trim((string) get_config('local_ai_course_assistant', 'soapbox_vision_model'));
        if ($providerid !== '' && $model !== '') {
            try {
                return base_provider::create_for_comparison($providerid, $model, $courseid);
            } catch (\Throwable $e) {
                debugging('soapbox slide-vision provider unavailable, falling back: ' . $e->getMessage(),
                    DEBUG_DEVELOPER);
            }
        }
        return base_provider::create_from_config($courseid);
    }
}
