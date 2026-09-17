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
 * The chip labels the browser reads at a language switch are where it looks.
 *
 * amd/src/speech.js holds two same-shaped maps keyed by the same 45 language
 * codes: SUPPORTED_LANGS (STT/TTS voice metadata) and STARTER_LABELS (chip
 * text). chat.js reads only the second.
 *
 * v7.5.0 added a `focusNext` label to all 45 rows of the FIRST one. Everything
 * looked right -- 45 translations, present in the built bundle, reviewed as a
 * diff -- and the chip stayed English in every language, because
 * getStarterLabels() returns STARTER_LABELS[code] and nothing merges the two.
 * No test referenced either map, so nothing objected.
 *
 * These tests read the source the way the browser does: find STARTER_LABELS,
 * and assert against that object alone.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */

namespace local_ai_course_assistant;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests over the STARTER_LABELS table in amd/src/speech.js.
 */
final class starter_labels_js_test extends \advanced_testcase {

    /** Labels every locale row must carry, and the chip each one drives. */
    private const REQUIRED_LABELS = [
        'helpPage',
        'quiz',
        'studyPlan',
        'aiProjectCoach',
        'ellPractice',
        'ellPronunciation',
        'focusNext',
    ];

    /**
     * STARTER_LABELS parsed out of the source, as [langcode => [key => value]].
     *
     * @return array
     */
    private function starter_labels(): array {
        $src = file_get_contents(__DIR__ . '/../amd/src/speech.js');
        $this->assertNotFalse($src, 'amd/src/speech.js is unreadable');

        $start = strpos($src, 'const STARTER_LABELS = {');
        $this->assertNotFalse($start, 'STARTER_LABELS has been renamed or removed');
        $end = strpos($src, "\n    };", $start);
        $this->assertNotFalse($end, 'STARTER_LABELS is not terminated as expected');
        $block = substr($src, $start, $end - $start);

        $out = [];
        foreach (explode("\n", $block) as $line) {
            if (!preg_match("/^\s*'([a-z_]+)':\s*\{(.*)\},?\s*$/", $line, $m)) {
                continue;
            }
            $row = [];
            preg_match_all(
                "/(\w+):\s*('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")/",
                $m[2],
                $pairs,
                PREG_SET_ORDER
            );
            foreach ($pairs as $pair) {
                $row[$pair[1]] = substr($pair[2], 1, -1);
            }
            $out[$m[1]] = $row;
        }
        return $out;
    }

    /**
     * Every locale carries every chip label, in STARTER_LABELS itself.
     *
     * @return void
     */
    public function test_every_locale_has_every_chip_label(): void {
        $labels = $this->starter_labels();
        $this->assertGreaterThanOrEqual(45, count($labels), 'locale count dropped');

        $missing = [];
        foreach ($labels as $code => $row) {
            foreach (self::REQUIRED_LABELS as $key) {
                if (($row[$key] ?? '') === '') {
                    $missing[] = $code . '.' . $key;
                }
            }
        }
        $this->assertSame([], $missing, 'chip labels missing from STARTER_LABELS');
    }

    /**
     * English is absent on purpose.
     *
     * getStarterLabels() returns null for English so the caller keeps the
     * server-rendered text from lang/en. An `en` row here would be a second
     * source of truth for strings that already exist as lang keys.
     *
     * @return void
     */
    public function test_english_is_not_in_the_table(): void {
        $this->assertArrayNotHasKey('en', $this->starter_labels());
    }

    /**
     * The built bundle carries what the source does.
     *
     * Moodle serves amd/build, so a source-only change is invisible in the
     * browser. This catches the stale-build trap for these strings
     * specifically, using a value no other table holds.
     *
     * @return void
     */
    public function test_the_built_bundle_is_not_stale(): void {
        $build = file_get_contents(__DIR__ . '/../amd/build/speech.min.js');
        $this->assertNotFalse($build, 'amd/build/speech.min.js is missing');

        $labels = $this->starter_labels();
        foreach (['fr', 'ja', 'ar'] as $code) {
            $this->assertStringContainsString(
                $labels[$code]['focusNext'],
                $build,
                "speech.min.js does not carry the {$code} focus-next label; rebuild it"
            );
        }
    }
}
