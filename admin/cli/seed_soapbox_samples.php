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
 * Seed synthetic Soapbox speech scores so the rubric feedback and the
 * "My speeches" history have realistic data to demo and test against.
 *
 * Writes only to the practice-scores table via rubric_manager::save_score().
 * No audio and no transcript is created, because Soapbox never stores either —
 * seeding them would misrepresent what the feature retains.
 *
 * Scores follow a per-learner improvement curve rather than being uniformly
 * random: a demo of a progress feature is worthless if the progress is flat,
 * and a reviewer looking at the history should see the shape a real learner
 * produces.
 *
 * Usage:
 *   php admin/cli/seed_soapbox_samples.php --courseid=43
 *   php admin/cli/seed_soapbox_samples.php --shortname=COMM101 --per-user=4
 *   php admin/cli/seed_soapbox_samples.php --courseid=43 --dry-run
 *   php admin/cli/seed_soapbox_samples.php --courseid=43 --clear
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_ai_course_assistant\rubric_manager;

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'courseid' => 0,
    'shortname' => '',
    'per-user' => 3,
    'dry-run' => false,
    'clear' => false,
], ['h' => 'help']);

if ($unrecognized) {
    cli_error('Unrecognised option: ' . implode(', ', $unrecognized));
}

if ($options['help'] || (empty($options['courseid']) && $options['shortname'] === '')) {
    cli_writeln("Seed synthetic Soapbox speech scores for demo and testing.

  --courseid=N     Course to seed.
  --shortname=X    Course shortname, if you would rather not look up the id.
  --per-user=N     Speeches per enrolled learner (default 3).
  --dry-run        Report what would be written, write nothing.
  --clear          Delete synthetic rows previously written by this script.
                   Identified by a marker in session_meta, so a learner's real
                   attempt is never removed.

No audio or transcript is created: Soapbox does not store either.");
    exit(0);
}

// Resolve the course.
if (!empty($options['courseid'])) {
    $course = $DB->get_record('course', ['id' => (int) $options['courseid']], 'id,shortname,fullname');
} else {
    $course = $DB->get_record('course', ['shortname' => $options['shortname']], 'id,shortname,fullname', IGNORE_MULTIPLE);
}
if (!$course) {
    cli_error('Course not found.');
}
cli_writeln("Course: {$course->shortname} (id {$course->id}) — {$course->fullname}");

// Marker written into session_meta so --clear can find exactly these rows and
// nothing else. A real learner attempt has no such key.
$marker = 'seed_soapbox_samples';

if ($options['clear']) {
    $rows = $DB->get_records('local_ai_course_assistant_practice_scores',
        ['courseid' => $course->id, 'session_type' => rubric_manager::TYPE_SPEECH]);
    $deleted = 0;
    foreach ($rows as $r) {
        $meta = json_decode((string) $r->session_meta, true);
        if (is_array($meta) && !empty($meta[$marker])) {
            $DB->delete_records('local_ai_course_assistant_practice_scores', ['id' => $r->id]);
            $deleted++;
        }
    }
    cli_writeln("Deleted {$deleted} synthetic row(s). Real attempts untouched.");
    exit(0);
}

// Enrolled learners on this course.
// EXISTS instead of DISTINCT over the role join: a learner with two role
// assignments produced duplicate rows the DISTINCT then folded; leading with
// u.id keeps get_records_sql keying safe by construction.
$learners = $DB->get_records_sql(
    "SELECT u.id, u.firstname, u.lastname
       FROM {user} u
      WHERE u.deleted = 0
        AND EXISTS (SELECT 1
                      FROM {role_assignments} ra
                      JOIN {context} ctx ON ctx.id = ra.contextid
                     WHERE ctx.contextlevel = 50 AND ctx.instanceid = :courseid
                       AND ra.userid = u.id)
   ORDER BY u.id", ['courseid' => $course->id]);

if (!$learners) {
    cli_error('No enrolled users found on that course; nothing to seed against.');
}
cli_writeln('Enrolled learners: ' . count($learners));

// A speech rubric must exist for the scores to hang off. Reuse the course's
// active one when present so the seeded criteria match what the UI renders.
$rubric = rubric_manager::get_active_rubric($course->id, rubric_manager::TYPE_SPEECH);
if (!$rubric) {
    if ($options['dry-run']) {
        cli_writeln('Would create a speech rubric (none active for this course).');
        $criteria = rubric_manager::DEFAULT_SPEECH_CRITERIA;
        $rubricid = 0;
    } else {
        $rubricid = rubric_manager::create_rubric($course->id, rubric_manager::TYPE_SPEECH,
            'Soapbox Speech Rubric', rubric_manager::DEFAULT_SPEECH_CRITERIA);
        $criteria = rubric_manager::DEFAULT_SPEECH_CRITERIA;
        cli_writeln("Created speech rubric id {$rubricid}.");
    }
} else {
    $rubricid = (int) $rubric->id;
    $criteria = json_decode((string) $rubric->criteria, true) ?: rubric_manager::DEFAULT_SPEECH_CRITERIA;
    cli_writeln("Using existing rubric id {$rubricid} (" . count($criteria) . ' criteria).');
}

// Speech titles and topics that read like a real communications course.
$speeches = [
    ['Why we should compost', 'Persuasive: local environment'],
    ['My first job interview', 'Informative: personal experience'],
    ['The case for a four-day week', 'Persuasive: workplace'],
    ['How to read a nutrition label', 'Informative: how-to'],
    ['A place that changed me', 'Narrative: personal'],
    ['Public transport in our city', 'Persuasive: civic'],
    ['Learning to cook with my grandmother', 'Narrative: personal'],
    ['Why libraries still matter', 'Persuasive: community'],
];

// Per-criterion feedback keyed by band, so the text matches the number. Flat
// praise on a 2/5 is exactly the kind of demo data that makes a rubric look
// untrustworthy.
$bandfeedback = [
    2 => [
        'Long pauses broke the flow; try rehearsing the opening aloud twice before recording.',
        'The main point arrived late. State it in the first fifteen seconds.',
        'Several claims went unsupported. One concrete example each would carry them.',
        'Vocabulary repeated often. Try swapping two or three repeated words.',
        'Ran well short of the target. Add a second supporting point.',
    ],
    3 => [
        'Pace was steady with a few hesitations; the middle section was strongest.',
        'Clear opening and close, though the middle wandered slightly.',
        'Good relevance throughout; one example would benefit from more detail.',
        'Solid range of language with a couple of repeated phrases.',
        'Close to the target length, slightly rushed at the end.',
    ],
    4 => [
        'Confident delivery with natural pacing and only minor hesitation.',
        'Well structured: the signposting between points was easy to follow.',
        'Strong, specific support for each claim.',
        'Varied and precise word choice.',
        'Well judged length with a comfortable finish.',
    ],
    5 => [
        'Assured, natural delivery with excellent use of pause for emphasis.',
        'Tight structure; the close called back to the opening effectively.',
        'Every claim was supported with a vivid, relevant example.',
        'Precise, varied vocabulary used accurately throughout.',
        'Excellent time management — full use of the window without rushing.',
    ],
];

$overallfeedback = [
    'A solid attempt with a clear message. Focus next on tightening the opening.',
    'Good progress since the last recording — the structure is noticeably clearer.',
    'Strong content; delivery is the area with the most room left to grow.',
    'Confident and well organised. Keep working on varied sentence openings.',
    'Well done. The pacing was the strongest element here.',
];

$now = time();
$peruser = max(1, (int) $options['per-user']);
$written = 0;
$plan = [];

$s = 0;
foreach ($learners as $learner) {
    // Each learner gets a starting ability, an improvement rate and a ceiling,
    // all derived from the user id rather than randomly so repeated runs
    // produce a consistent cohort. The three vary independently on purpose: a
    // first pass had everyone improving at the same rate to the same cap, and
    // a history where every learner ends on an identical score reads as
    // obviously generated, which defeats the point of demo data.
    $base    = 2 + ($learner->id % 2);              // starts at 2 or 3
    $rate    = [0.5, 1.0, 1.5][$learner->id % 3];   // slow, steady or fast
    $ceiling = [4, 5, 5, 3][$learner->id % 4];      // not everyone tops out

    for ($i = 0; $i < $peruser; $i++) {
        $speech = $speeches[$s % count($speeches)];
        $s++;

        $level = min($ceiling, $base + (int) round($i * $rate));
        $level = max(2, $level);

        $scores = [];
        $total = 0;
        foreach ($criteria as $idx => $c) {
            // Vary individual criteria around the learner's level so the rows
            // are not flat, while keeping within band.
            $val = $level + (($idx + $i) % 3 === 0 ? -1 : 0);
            $val = max(1, min((int) ($c['max_score'] ?? 5), $val));
            $total += $val;
            $band = max(2, min(5, $val));
            $notes = $bandfeedback[$band];
            $scores[] = [
                'name' => $c['name'] ?? ('Criterion ' . ($idx + 1)),
                'score' => $val,
                'feedback' => $notes[$idx % count($notes)],
            ];
        }

        $maxtotal = 0;
        foreach ($criteria as $c) {
            $maxtotal += (int) ($c['max_score'] ?? 5);
        }
        $overall = $maxtotal > 0 ? (int) round(($total / $maxtotal) * 100) : 0;

        // Spread attempts backwards in time, most recent first, so the history
        // page shows a plausible cadence rather than a single timestamp.
        $daysago = ($peruser - $i) * 6 + ($learner->id % 4);
        $timecreated = $now - ($daysago * DAYSECS) - (($learner->id * 137) % DAYSECS);
        $duration = 150 + (($learner->id + $i * 37) % 120);

        $meta = [
            'name' => $speech[0],
            'topic' => $speech[1],
            'target' => 180,
            $marker => true,
        ];

        $plan[] = sprintf('  %-22s %-32s overall=%3d%%  %ds  %s ago',
            trim($learner->firstname . ' ' . $learner->lastname),
            substr($speech[0], 0, 32), $overall, $duration, $daysago . 'd');

        if (!$options['dry-run']) {
            $id = rubric_manager::save_score(
                $rubricid,
                (int) $learner->id,
                (int) $course->id,
                rubric_manager::TYPE_SPEECH,
                $scores,
                $overall,
                $overallfeedback[($learner->id + $i) % count($overallfeedback)],
                $duration,
                $meta
            );
            // save_score stamps its own timecreated; backdate so the history has
            // a spread rather than everything landing in the same minute.
            $DB->set_field('local_ai_course_assistant_practice_scores',
                'timecreated', $timecreated, ['id' => $id]);
            $written++;
        }
    }
}

cli_writeln('');
foreach ($plan as $line) {
    cli_writeln($line);
}
cli_writeln('');

if ($options['dry-run']) {
    cli_writeln('DRY RUN — nothing written. ' . count($plan) . ' row(s) would be created.');
} else {
    cli_writeln("Wrote {$written} synthetic speech score(s) to {$course->shortname}.");
    cli_writeln('Re-run with --clear to remove exactly these rows.');
}
