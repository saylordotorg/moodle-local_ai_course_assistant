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

namespace local_ai_course_assistant\check;

use core\check\check;
use core\check\result;
use local_ai_course_assistant\key_inventory;

/**
 * Status check: which API keys are stored in more than one place? (7.11.0)
 *
 * A key can sit in a Providers and keys row, a pasted-key setting, a voice provider row, the legacy apikey
 * copy of the default key, and a course's own settings. Replacing such a key means changing every place it
 * is stored, so this lists each key that is stored in two or more places, by its last four characters and the
 * places that hold it, and points at admin/cli/rotate_key.php, which replaces a key everywhere in one step.
 *
 * The legacy apikey setting is an automatic copy of the default provider's row, which the site keeps in step
 * itself, so it does not count toward "two or more places": a key qualifies only with at least two places of
 * the other kinds. A qualifying key that is also held by the copy lists the copy too (a rotation changes it)
 * and says it is an automatic copy.
 *
 * It is advice, not a fault: the result is always INFO, and at most KEY_LIMIT keys are listed. It reads
 * key_inventory::groups() and nothing else, so it never holds a key: the page shows last-four hints, provider
 * names, setting names and course short names. If the inventory can't be read the result says so in fixed text.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_places extends check {
    /** @var int Most places listed for one key; the rest are counted ("and 6 more"). */
    public const PLACE_LIMIT = 12;

    /** @var int Most keys listed; the rest are counted ("and 3 more keys"). */
    public const KEY_LIMIT = 10;

    /**
     * Name shown in the status list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('check:key_places', 'local_ai_course_assistant');
    }

    /**
     * List the keys stored in more than one place, or say there are none.
     *
     * @return result always INFO
     */
    public function get_result(): result {
        try {
            $groups = $this->groups();
        } catch (\Throwable) {
            // Fixed text only: an exception's message could quote a value, and a status page must never fail.
            return new result(result::INFO, get_string('check:key_places_unreadable', 'local_ai_course_assistant'));
        }
        if (!$groups) {
            return new result(result::INFO, get_string('check:key_places_none', 'local_ai_course_assistant'));
        }
        // groups() is ordered by place count, so the keys kept in the most places come first. The automatic apikey
        // copy is not a place of the admin's own, so a key needs two places of the other kinds to be listed.
        $repeated = array_filter($groups, function (array $group): bool {
            $own = array_filter($group['places'], fn(array $place): bool => $place['kind'] !== 'mirror');
            return count($own) >= 2;
        });
        if (!$repeated) {
            return new result(result::INFO, get_string('check:key_places_single', 'local_ai_course_assistant'));
        }
        $items = '';
        foreach (array_slice($repeated, 0, self::KEY_LIMIT) as $group) {
            $items .= '<li>' . $this->describe($group) . '</li>';
        }
        $left = count($repeated) - self::KEY_LIMIT;
        $more = $left > 0
            ? '<p>' . get_string('check:key_places_morekeys', 'local_ai_course_assistant', $left) . '</p>'
            : '';
        $details = '<ul>' . $items . '</ul>' . $more . '<p>'
            . get_string('check:key_places_advice', 'local_ai_course_assistant') . '</p>';
        return new result(
            result::INFO,
            get_string('check:key_places_some', 'local_ai_course_assistant', count($repeated)),
            $details
        );
    }

    /**
     * Every stored key with the places that hold it. A seam: a test makes it throw.
     *
     * @return array key_inventory::groups()
     */
    protected function groups(): array {
        return key_inventory::groups();
    }

    /**
     * One line for a key: its hint, the provider it is for, and the places that hold it.
     *
     * @param array $group a key_inventory::groups() entry: fingerprint, last4, vendor and places
     * @return string HTML, every value from the inventory escaped
     */
    private function describe(array $group): string {
        $component = 'local_ai_course_assistant';
        $key = $group['last4'] !== ''
            ? get_string('check:key_places_keyhint', $component, s($group['last4']))
            : get_string('check:key_places_keyshort', $component);
        $vendor = $group['vendor'] !== ''
            ? get_string('check:key_places_vendor', $component, s($group['vendor']))
            : '';
        // The automatic copy is always named, after the places of the admin's own, so cutting the list can't hide it.
        $own = array_values(array_filter($group['places'], fn(array $place): bool => $place['kind'] !== 'mirror'));
        $copies = array_values(array_filter($group['places'], fn(array $place): bool => $place['kind'] === 'mirror'));
        $places = [];
        foreach (array_merge(array_slice($own, 0, self::PLACE_LIMIT), $copies) as $place) {
            $places[] = '<span class="sola-keyplace">' . $this->place_label($place) . '</span>';
        }
        $list = implode(', ', $places);
        $left = count($own) - self::PLACE_LIMIT;
        if ($left > 0) {
            $list .= ' ' . get_string('check:key_places_more', $component, $left);
        }
        return get_string('check:key_places_group', $component, (object) [
            'key' => $key,
            'vendor' => $vendor,
            'count' => count($group['places']),
            'places' => $list,
        ]);
    }

    /**
     * Say where one place is, in words an administrator can find on the site.
     *
     * @param array $place a key_inventory::places() entry
     * @return string HTML; the ref (a provider id, setting name or course short name) is escaped
     */
    private function place_label(array $place): string {
        $component = 'local_ai_course_assistant';
        $ref = s($place['ref']);
        switch ($place['kind']) {
            case 'row':
                return get_string('check:key_places_row', $component, $ref);
            case 'pasted':
                return get_string('check:key_places_pasted', $component, $ref);
            case 'voice':
                return get_string('check:key_places_voice', $component, $ref);
            case 'mirror':
                return get_string('check:key_places_mirror', $component);
            case 'course':
                return get_string('check:key_places_course', $component, $ref);
            default:
                return s($place['kind'] . ' ' . $place['ref']);
        }
    }
}
