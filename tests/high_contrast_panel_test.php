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
 * Every surface the high-contrast theme writes white text onto must be repainted (S13).
 *
 * Found on staging, 2026-09-02. The high-contrast rule sets color:#fff on the
 * drawer; .aica-settings-panel is an absolutely-positioned overlay that keeps
 * its own background:#fff and was never repainted, so four settings labels
 * rendered white on white -- a measured ratio of exactly 1:1 -- and the panel's
 * link sat at 1.22:1.
 *
 * The general shape of the bug is "inherits the theme's colour without its
 * background", so this pins the specific surface rather than trying to model
 * the cascade.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class high_contrast_panel_test extends \advanced_testcase {

    /**
     * Load styles.css.
     *
     * @return string
     */
    private static function css(): string {
        global $CFG;
        return (string) file_get_contents($CFG->dirroot . '/local/ai_course_assistant/styles.css');
    }

    /**
     * The settings panel gets its own dark surface under high contrast.
     */
    public function test_settings_panel_is_repainted_under_high_contrast(): void {
        $css = self::css();

        $this->assertMatchesRegularExpression(
            '/\.aica-high-contrast\s+\.aica-settings-panel\s*\{[^}]*background:\s*#000/i',
            $css,
            'the settings panel keeps its white background under high contrast, so its '
            . 'inherited white text is invisible (1:1)'
        );
        $this->assertMatchesRegularExpression(
            '/\.aica-high-contrast\s+\.aica-settings-panel\s*\{[^}]*color:\s*#fff/i',
            $css,
            'the settings panel does not set an explicit text colour under high contrast'
        );
    }

    /**
     * The labels the tester measured at 1:1 are named explicitly, so a future
     * refactor that drops one of them fails here rather than in someone's face.
     */
    public function test_panel_labels_and_controls_are_covered(): void {
        $css = self::css();
        foreach (['__section-title', '__title', '__empty-note'] as $cls) {
            $this->assertStringContainsString(
                '.aica-settings-panel' . $cls,
                $css,
                "high-contrast rules do not mention .aica-settings-panel{$cls}"
            );
        }
        $this->assertMatchesRegularExpression(
            '/\.aica-high-contrast\s+\.aica-settings-panel__select[^{]*\{[^}]*color:\s*#000/i',
            $css,
            'panel form controls need a dark text colour on their light surface'
        );
    }
}
