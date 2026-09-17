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

/**
 * Built-in conversation starters follow the learner's language.
 *
 * Until v7.5.0 every built-in name and description was an English literal
 * inside starter_manager::get_defaults(). That is why the i18n completeness
 * test never saw them: a learner on a Spanish site read Spanish everywhere in
 * the drawer except the starter chips and their tooltips.
 *
 * The subtle half is storage. save_global_starters() persists whatever the
 * admin form posted, and that form renders built-ins in the ADMIN's language,
 * so the first Save froze the built-ins into that language for every learner on
 * the site.
 *
 * These tests do not switch language, because force_current_language() is a
 * no-op unless the CORE lang pack for that language is installed, which it is
 * not on a plain test site or in CI. Asserting on a switch would have produced
 * a test that silently proved nothing. What is pinned instead is the decision
 * the code makes -- "is this text still the canonical built-in?" -- which is
 * the part that breaks, and which is language-independent.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\starter_manager
 */

namespace local_ai_course_assistant;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for translatable built-in starters.
 */
final class starter_localization_test extends \advanced_testcase {

    /**
     * Effective starters for a course, keyed by starter key.
     *
     * @param int $courseid
     * @return array
     */
    private function effective(int $courseid): array {
        return array_column(
            starter_manager::get_effective_starters($courseid, true, true),
            null,
            'key'
        );
    }

    /**
     * Built-in names and descriptions come from lang strings, not literals.
     *
     * This is what makes them translatable at all, and what makes the i18n
     * completeness test able to see them.
     *
     * @return void
     */
    public function test_defaults_come_from_lang_strings(): void {
        $this->resetAfterTest();
        $bykey = array_column(starter_manager::get_defaults(), null, 'key');

        foreach ($bykey as $key => $starter) {
            $suffix = str_replace('-', '_', $key);
            $this->assertSame(
                branding::str('starters:builtin_' . $suffix),
                $starter['name'],
                $key . ' name is not sourced from its lang string'
            );
            $this->assertSame(
                branding::str('starters:builtin_' . $suffix . '_desc'),
                $starter['description'],
                $key . ' description is not sourced from its lang string'
            );
        }
    }

    /**
     * Every built-in has both a name and a description key.
     *
     * A missing one is invisible in review and loud at runtime: get_string()
     * returns "[[starters:builtin_x]]" straight into a chip label.
     *
     * @return void
     */
    public function test_every_builtin_has_both_lang_keys(): void {
        $this->resetAfterTest();
        $missing = [];
        foreach (starter_manager::get_defaults() as $starter) {
            $suffix = str_replace('-', '_', $starter['key']);
            foreach (['', '_desc'] as $part) {
                $id = 'starters:builtin_' . $suffix . $part;
                if (!get_string_manager()->string_exists($id, 'local_ai_course_assistant')) {
                    $missing[] = $id;
                }
            }
        }
        $this->assertSame([], $missing);
    }

    /**
     * Nothing is frozen: a stored built-in still matching the canonical text is
     * re-resolved through the lang system on every read.
     *
     * In an English-only test site the resolved value equals what was stored,
     * so what this actually pins is that the value goes back through
     * branding::str() rather than being served from config verbatim. Combined
     * with test_a_renamed_builtin_is_left_alone(), that fixes both branches.
     *
     * @return void
     */
    public function test_a_stored_canonical_builtin_is_resolved_not_served_verbatim(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        starter_manager::save_global_starters(starter_manager::get_defaults());
        $bykey = $this->effective($course->id);

        $this->assertArrayHasKey('quiz', $bykey);
        $this->assertSame(branding::str('starters:builtin_quiz'), $bykey['quiz']['name']);
        $this->assertSame(branding::str('starters:builtin_quiz_desc'), $bykey['quiz']['description']);
    }

    /**
     * An administrator's own wording survives.
     *
     * The relocalization may only touch text nobody has edited, or it would
     * silently undo a rename on every page load. One character of difference is
     * enough to count as edited.
     *
     * @return void
     */
    public function test_a_renamed_builtin_is_left_alone(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $starters = starter_manager::get_defaults();
        foreach ($starters as $i => $s) {
            if ($s['key'] === 'quiz') {
                $starters[$i]['name'] = 'Test my knowledge';
                // Differs from the canonical description by one character.
                $starters[$i]['description'] = branding::str('starters:builtin_quiz_desc') . '.';
            }
        }
        starter_manager::save_global_starters($starters);

        $bykey = $this->effective($course->id);
        $this->assertSame('Test my knowledge', $bykey['quiz']['name']);
        $this->assertSame(
            branding::str('starters:builtin_quiz_desc') . '.',
            $bykey['quiz']['description']
        );
    }

    /**
     * Saving stores the English canonical for an unedited built-in.
     *
     * This is the half that stops a non-English admin session from pinning its
     * own language onto every learner, and it is also what keeps
     * localize_builtin() able to tell "untouched" from "renamed" afterwards.
     *
     * @return void
     */
    public function test_saving_stores_the_english_canonical(): void {
        $this->resetAfterTest();

        starter_manager::save_global_starters(starter_manager::get_defaults());

        $saved = array_column(
            json_decode(get_config('local_ai_course_assistant', starter_manager::CONFIG_KEY), true),
            null,
            'key'
        );

        $this->assertSame('Quiz Me', $saved['quiz']['name']);
        $this->assertSame(
            'Generates a practice quiz on the current material',
            $saved['quiz']['description']
        );
    }

    /**
     * A custom starter is never touched by any of this.
     *
     * @return void
     */
    public function test_a_custom_starter_keeps_its_admin_text(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $starters = starter_manager::get_defaults();
        $starters[] = [
            'key'         => 'lablogs',
            'name'        => 'Lab logs',
            'description' => 'Help me write up this week lab log',
            'prompt'      => 'Help me write my lab log.',
            'icon'        => 'pencil',
            'type'        => 'prompt',
            'enabled'     => true,
            'sort_order'  => 99,
            'builtin'     => false,
            'conditional' => '',
        ];
        starter_manager::save_global_starters($starters);

        $bykey = $this->effective($course->id);
        $this->assertSame('Lab logs', $bykey['lablogs']['name']);
        $this->assertSame('Help me write up this week lab log', $bykey['lablogs']['description']);
    }
}
