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
 * v4.1 / F1 — Single source of truth for "what should this learner do next."
 *
 * One service class, one query path, multiple consumers:
 *   - chat focus-next starter (replaces the old templated LLM prompt)
 *   - learner_weekly_digest scheduled task (replaces inline weak-objectives lookup)
 *   - any future external integration that wants a learner's recommended actions
 *
 * The output shape is deliberately stable: an ordered array of recommendations,
 * each carrying enough data for either a chat-card render, an email line item,
 * or a third-party JSON consumer to do something useful without a follow-up
 * call.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class next_best_action {

    /** @var int Default recommendation count. */
    private const DEFAULT_COUNT = 3;

    /**
     * Build a list of next-best-action recommendations for a learner on a
     * course. Returns an empty array when mastery is off, no objectives are
     * defined, or the learner has no weak objectives — callers can render
     * an empty state ("looking great, nothing to nudge on") accordingly.
     *
     * Each recommendation carries:
     *   - objectiveid: int
     *   - title: string (objective title, with [code] prefix if set)
     *   - status: 'not_started' | 'learning'   (mastered objectives never appear here)
     *   - score: float (0.0–1.0, rounded)
     *   - action: 'get_started' | 'practice' | 'quiz' | 'review'
     *   - suggestion: string (one-line natural-language nudge ready to render)
     *   - moduleurl: string|null (URL of best-fit module if a heuristic match found one)
     *   - modulename: string|null (course-module name if a match found, for the link text)
     *
     * @param int $userid
     * @param int $courseid
     * @param int $count Maximum recommendations to return. Defaults to 3.
     * @return array<int, array{objectiveid:int,title:string,status:string,score:float,action:string,suggestion:string,moduleurl:?string,modulename:?string}>
     */
    public static function recommend(int $userid, int $courseid, int $count = self::DEFAULT_COUNT): array {
        if (!objective_manager::is_enabled_for_course($courseid)) {
            return [];
        }
        $count = max(1, min(10, $count));
        $weak = objective_manager::get_weak_objectives($userid, $courseid, $count);
        if (empty($weak)) {
            return [];
        }
        $modulemap = self::build_module_index($courseid);
        $out = [];
        foreach ($weak as $row) {
            $obj = $row['objective'];
            $m = $row['mastery'];
            $title = $obj->code ? "[{$obj->code}] {$obj->title}" : $obj->title;
            $action = self::pick_action($m['status'], (float) $m['score']);
            [$moduleurl, $modulename] = self::find_best_module($modulemap, $obj);
            $out[] = [
                'objectiveid' => (int) $obj->id,
                'title'       => $title,
                'status'      => (string) $m['status'],
                'score'       => round((float) $m['score'], 4),
                'action'      => $action,
                'suggestion'  => self::format_suggestion($action, $obj->title, $modulename),
                'moduleurl'   => $moduleurl,
                'modulename'  => $modulename,
            ];
        }
        return $out;
    }

    /**
     * Pick a recommended action for a (status, score) pair.
     *
     * Logic — kept simple and tweakable:
     *   not_started        → 'get_started'
     *   learning, score<.5 → 'review'   (the basics aren't there yet)
     *   learning, score<.8 → 'practice' (working on it, more reps help)
     *   learning, score≥.8 → 'quiz'     (close to mastered, lock it in)
     *
     * @param string $status
     * @param float $score
     * @return string
     */
    private static function pick_action(string $status, float $score): string {
        if ($status === 'not_started') {
            return 'get_started';
        }
        if ($score < 0.5) {
            return 'review';
        }
        if ($score < 0.8) {
            return 'practice';
        }
        return 'quiz';
    }

    /**
     * One-line natural-language nudge for a recommendation. Kept intentionally
     * short so it fits in chat cards and email line items unchanged. Lang-
     * strings carry {$a->title} and optional {$a->module} placeholders.
     *
     * @param string $action
     * @param string $objtitle
     * @param string|null $modulename
     * @return string
     */
    private static function format_suggestion(string $action, string $objtitle, ?string $modulename): string {
        $key = $modulename
            ? 'next_best_action:' . $action . '_with_module'
            : 'next_best_action:' . $action;
        $a = (object) [
            'title' => $objtitle,
            'module' => $modulename ?: '',
        ];
        return get_string($key, 'local_ai_course_assistant', $a);
    }

    /**
     * Build a small index of course-module candidates we can match objective
     * titles against. Index is per-call and small — no MUC caching needed.
     *
     * @param int $courseid
     * @return array<int, array{cmid:int, name:string, normalised:string, url:string}>
     */
    private static function build_module_index(int $courseid): array {
        $modinfo = get_fast_modinfo($courseid);
        $out = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->visible || !$cm->uservisible) {
                continue;
            }
            $name = (string) $cm->name;
            if ($name === '') {
                continue;
            }
            $out[] = [
                'cmid' => (int) $cm->id,
                'name' => $name,
                'normalised' => self::normalise($name),
                'url'  => $cm->url ? $cm->url->out(false) : '',
            ];
        }
        return $out;
    }

    /**
     * Heuristic best-module match. Counts shared words between the objective
     * title and each module name; picks the highest-overlap module if it
     * shares at least two meaningful words. Returns [url, name] or
     * [null, null] when nothing crosses the threshold.
     *
     * Deliberately conservative — a confident "open this module" link is much
     * better than a low-confidence wrong link, and the chat surface always
     * has the bare objective title as a fallback when no module matches.
     *
     * @param array $modulemap Output of build_module_index().
     * @param \stdClass $obj Objective row.
     * @return array{0:?string, 1:?string}
     */
    private static function find_best_module(array $modulemap, \stdClass $obj): array {
        if (empty($modulemap)) {
            return [null, null];
        }
        $haystack = self::normalise(($obj->title ?? '') . ' ' . ($obj->description ?? ''));
        $needlewords = array_filter(explode(' ', $haystack), function ($w) {
            return strlen($w) >= 4; // ignore short / stop-word-ish tokens
        });
        if (count($needlewords) < 2) {
            return [null, null];
        }
        $bestcm = null;
        $bestscore = 0;
        foreach ($modulemap as $cm) {
            $modwords = array_filter(explode(' ', $cm['normalised']), function ($w) {
                return strlen($w) >= 4;
            });
            if (empty($modwords)) {
                continue;
            }
            $shared = count(array_intersect($needlewords, $modwords));
            if ($shared > $bestscore) {
                $bestscore = $shared;
                $bestcm = $cm;
            }
        }
        if (!$bestcm || $bestscore < 2) {
            return [null, null];
        }
        return [$bestcm['url'] ?: null, $bestcm['name']];
    }

    /**
     * Normalise text for word-overlap matching: lowercase, strip punctuation,
     * collapse whitespace.
     *
     * @param string $s
     * @return string
     */
    private static function normalise(string $s): string {
        $s = strtolower(strip_tags($s));
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }
}
