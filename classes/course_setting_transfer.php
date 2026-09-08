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

defined('MOODLE_INTERNAL') || die();

/**
 * Which per-course settings may travel inside a course backup.
 *
 * Per-course overrides live in config_plugins as "<prefix><courseid>". Course
 * backup carries them so a duplicated course keeps its pedagogy decisions, and
 * restore writes them back under the new course id.
 *
 * This is an allowlist, and deliberately so. The first implementation used a
 * denylist of credential-looking substrings, which is the wrong shape twice
 * over: restore writes whatever the archive contains, so a user with restore
 * capability but no site:config could hand-edit an .mbz and set arbitrary
 * plugin config scoped to the course they restore into; and a denylist has to
 * be updated in advance of every future setting, so the credential added in
 * some later release would travel by default. An allowlist fails closed --
 * a new setting simply does not travel until someone adds it here, and
 * course_setting_transfer_test fails if a per-course setting is read in the
 * codebase without a decision having been made about it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_setting_transfer {

    /**
     * Setting-name prefixes that may travel. A name qualifies when it is one of
     * these followed by nothing but the course id.
     */
    public const TRANSFERABLE_PREFIXES = [
        // Widget and display.
        'sola_enabled_course_',
        'sola_autoopen_course_',
        'sola_voicetab_course_',
        'sola_usertesting_course_',
        'avatar_animation_course_',
        'starters_course_',
        // Pedagogy.
        'socratic_mode_course_',
        'socratic_mode_enabled_course_',
        'prompt_verbosity_course_',
        'worked_examples_enabled_course_',
        'external_resources_enabled_course_',
        'essay_feedback_enabled_course_',
        'code_sandbox_enabled_course_',
        'flashcards_enabled_course_',
        'english_lock_course_',
        // Mastery and paths.
        'mastery_enabled_course_',
        'mastery_chip_enabled_course_',
        'mastery_dashboard_enabled_course_',
        'mastery_starter_enabled_course_',
        'crossmastery_enabled_course_',
        'learning_path_enabled_course_',
        'program_path_enabled_course_',
        'talking_avatar_enabled_course_',
        // Soapbox.
        'soapbox_enabled_course_',
        'soapbox_level_course_',
        // Retrieval and outreach.
        'rag_enabled_course_',
        'digest_email_enabled_course_',
    ];

    /**
     * May this setting name travel inside a course backup?
     *
     * @param string $name Full config_plugins name, e.g. "socratic_mode_course_42".
     * @return bool
     */
    public static function is_transferable(string $name): bool {
        foreach (self::TRANSFERABLE_PREFIXES as $prefix) {
            if (strncmp($name, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            $suffix = substr($name, strlen($prefix));
            // Only the course id may follow, so "rag_enabled_course_7" travels
            // and a hypothetical "rag_enabled_course_7_apikey" does not.
            if ($suffix !== '' && ctype_digit($suffix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every transferable setting for one course, as name => value.
     *
     * @param int $courseid
     * @return array
     */
    public static function collect_for_course(int $courseid): array {
        $out = [];
        foreach ((array) get_config('local_ai_course_assistant') as $name => $value) {
            if (self::is_transferable((string) $name)
                    && substr($name, -strlen('_' . $courseid)) === '_' . $courseid) {
                $out[(string) $name] = (string) $value;
            }
        }
        return $out;
    }
}
