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
 * Seed fake demo students and conversation history for a course.
 *
 * Creates realistic-looking users, enrolls them, and populates the
 * conversation, message, rating, and feedback tables so the Analytics
 * dashboard has plausible data to show during a demo.
 *
 * Usage (run from Moodle root):
 *   php local/ai_course_assistant/admin/cli/seed_demo_data.php \
 *       --courseid=2 --users=15 --weeks=4
 *
 * Options:
 *   --courseid=N   Target course id (required)
 *   --users=N      Number of demo students to create (default 15)
 *   --weeks=N      Spread conversations over N past weeks (default 4)
 *   --clear        Remove existing demo_* users and their data before seeding
 *   --dryrun       Show what would happen; make no changes
 *   --help         Print this help
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/enrollib.php');

[$options, $unrecognized] = cli_get_params([
    'courseid' => 0,
    'users'    => 15,
    'weeks'    => 4,
    'clear'    => false,
    'dryrun'   => false,
    'help'     => false,
], ['h' => 'help']);

if ($options['help'] || empty($options['courseid'])) {
    cli_writeln("Seed demo conversation data for the AI Course Assistant.");
    cli_writeln("");
    cli_writeln("Usage: php seed_demo_data.php --courseid=N [--users=15] [--weeks=4] [--clear] [--dryrun]");
    cli_writeln("");
    cli_writeln("  --courseid=N   Target course id (required)");
    cli_writeln("  --users=N      Demo students to create (default 15)");
    cli_writeln("  --weeks=N      Spread over N past weeks (default 4)");
    cli_writeln("  --clear        Remove existing demo_* users and data first");
    cli_writeln("  --dryrun       Preview only; no changes");
    exit(0);
}

global $DB;

$courseid = (int) $options['courseid'];
$course = $DB->get_record('course', ['id' => $courseid], 'id,fullname,shortname', MUST_EXIST);

$numusers = max(1, (int) $options['users']);
$numweeks = max(1, (int) $options['weeks']);
$clear    = !empty($options['clear']);
$dryrun   = !empty($options['dryrun']);

cli_writeln("Target course: [{$course->id}] {$course->fullname}");
cli_writeln("Users to seed: {$numusers}");
cli_writeln("Time window:   last {$numweeks} weeks");
cli_writeln("Mode:          " . ($dryrun ? "DRY RUN" : "LIVE"));
cli_writeln("");

// ────────────────────────────────────────────────────────────────────────
// Name pools. Intentionally generic and international.
// ────────────────────────────────────────────────────────────────────────
$firstnames = [
    'Alex', 'Jordan', 'Sam', 'Taylor', 'Morgan', 'Casey', 'Riley', 'Quinn',
    'Avery', 'Harper', 'Rowan', 'Sage', 'Emerson', 'Skylar', 'Reese',
    'Priya', 'Amara', 'Nia', 'Zara', 'Yuki', 'Mei', 'Aya', 'Ines',
    'Mateo', 'Diego', 'Luca', 'Noa', 'Kai', 'Arjun', 'Omar', 'Finn',
];
$lastnames = [
    'Rivera', 'Chen', 'Patel', 'Okafor', 'Santos', 'Nguyen', 'Kim', 'Novak',
    'Abadi', 'Silva', 'Johansson', 'Tanaka', 'Bernal', 'Ali', 'Ward',
    'Mendoza', 'Hassan', 'Park', 'Brooks', 'Singh', 'Dubois', 'Rossi',
];

// Representative student questions by topic. Course-agnostic.
$topics = [
    ['theme' => 'Getting started', 'questions' => [
        'Where should I start with this course?',
        'Can you give me a study plan for the next two weeks?',
        'How long does this course typically take to complete?',
    ]],
    ['theme' => 'Concept explanation', 'questions' => [
        'Can you explain this section in simpler terms?',
        'What is the main idea I should take away from this unit?',
        'How does this concept connect to the previous chapter?',
    ]],
    ['theme' => 'Practice and application', 'questions' => [
        'Can you quiz me on the last unit?',
        'Give me an example problem similar to the one on this page.',
        'What is a real-world example of this concept?',
    ]],
    ['theme' => 'Exam prep', 'questions' => [
        'What topics are most likely to be on the final exam?',
        'I got a poor grade on the last quiz. What should I review?',
        'Can you make flashcards for the key terms in this unit?',
    ]],
    ['theme' => 'Motivation and study skills', 'questions' => [
        'I am struggling to stay focused. Any suggestions?',
        'How should I schedule my study time around a full-time job?',
        'What is a good way to retain information long term?',
    ]],
];

$assistantreplies = [
    'Great question. The short answer is that this concept builds on the previous unit. Let me break it down step by step.',
    'Here is a concise summary of the key points, followed by a practice question to check your understanding.',
    'That is a common stumbling block. One way to think about it is with an everyday analogy: imagine you are organising a library.',
    'Good instinct to review this. The most important thing to remember is the relationship between these two ideas.',
    'Let me tailor this to what you have covered so far. Based on your progress, I suggest focusing on these three topics first.',
    'I can help with that. Let us start with a quick recap, then I will walk through a worked example.',
];

$providers = [
    ['provider' => 'claude', 'model' => 'claude-opus-4-6', 'promptbase' => 1500, 'completionbase' => 800],
    ['provider' => 'openai', 'model' => 'gpt-4o-mini', 'promptbase' => 1500, 'completionbase' => 700],
    ['provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'promptbase' => 1500, 'completionbase' => 750],
];

$interactiontypes = ['chat', 'chat', 'chat', 'chat', 'voice', 'quiz'];

// ────────────────────────────────────────────────────────────────────────
// Optional clear step
// ────────────────────────────────────────────────────────────────────────
if ($clear) {
    cli_writeln("Clearing existing demo_* users and their data...");
    // PERF002 bound: count first (no rows hydrated), then delete through
    // demo_seeder::clear_demo_students(), which pages the matching users with a
    // forward-only id cursor instead of loading every match into memory. Same
    // clear semantics as before — all demo_student_* users and their plugin
    // rows go away — just capped memory on a site with a large demo backlog.
    cli_writeln("  Found " . \local_ai_course_assistant\demo_seeder::count_demo_students()
        . " existing demo users");
    if (!$dryrun) {
        \local_ai_course_assistant\demo_seeder::clear_demo_students();
    }
    cli_writeln("");
}

// ────────────────────────────────────────────────────────────────────────
// Create users and enrol
// ────────────────────────────────────────────────────────────────────────
$studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
$enrolplugin = enrol_get_plugin('manual');
$enrolinstance = $DB->get_record(
    'enrol',
    ['courseid' => $courseid, 'enrol' => 'manual'],
    '*',
    MUST_EXIST
);

$userids = [];
cli_writeln("Creating {$numusers} demo users and enrolling...");
for ($i = 1; $i <= $numusers; $i++) {
    $firstname = $firstnames[array_rand($firstnames)];
    $lastname  = $lastnames[array_rand($lastnames)];
    $username  = 'demo_student_' . str_pad($i, 3, '0', STR_PAD_LEFT);
    $email     = $username . '@demo.local';

    $existing = $DB->get_record('user', ['username' => $username]);
    if ($existing) {
        cli_writeln("  [{$i}] {$username} exists (id={$existing->id})");
        $userids[] = (int) $existing->id;
        continue;
    }

    if ($dryrun) {
        cli_writeln("  [{$i}] would create {$username} ({$firstname} {$lastname})");
        continue;
    }

    $u = new stdClass();
    $u->auth       = 'manual';
    $u->confirmed  = 1;
    $u->mnethostid = $CFG->mnet_localhost_id;
    $u->username   = $username;
    $u->password   = hash_internal_user_password('DemoPass123!');
    $u->firstname  = $firstname;
    $u->lastname   = $lastname;
    $u->email      = $email;
    $u->lang       = 'en';
    $u->timecreated = time();
    $u->id = user_create_user($u, false, false);

    $enrolplugin->enrol_user($enrolinstance, $u->id, $studentrole->id);
    $userids[] = (int) $u->id;
    cli_writeln("  [{$i}] created {$username} (id={$u->id})");
}

if ($dryrun || empty($userids)) {
    cli_writeln("");
    cli_writeln("Dry run complete or no users created. Stopping before data generation.");
    exit(0);
}

// ────────────────────────────────────────────────────────────────────────
// Generate conversations and messages
// ────────────────────────────────────────────────────────────────────────
cli_writeln("");
cli_writeln("Generating conversation history...");

$now = time();
$windowstart = $now - ($numweeks * 7 * 86400);

$totalconvs = 0;
$totalmsgs  = 0;
$totalratings = 0;
$totalfeedback = 0;

foreach ($userids as $userid) {
    // 1 conversation per user for this course (the convs table has a unique
    // index on userid+courseid).
    $conv = (object) [
        'userid'       => $userid,
        'courseid'     => $courseid,
        'title'        => $topics[array_rand($topics)]['theme'],
        'offtopic_count' => 0,
        'timecreated'  => random_int($windowstart, $now - 3600),
        'timemodified' => $now,
    ];
    $conv->id = $DB->insert_record('local_ai_course_assistant_convs', $conv);
    $totalconvs++;

    // Each user sends 4 to 12 messages spread across the window.
    $numexchanges = random_int(4, 12);
    for ($x = 0; $x < $numexchanges; $x++) {
        $topic = $topics[array_rand($topics)];
        $userq = $topic['questions'][array_rand($topic['questions'])];
        $assistantreply = $assistantreplies[array_rand($assistantreplies)];
        $provider = $providers[array_rand($providers)];
        $interaction = $interactiontypes[array_rand($interactiontypes)];
        $msgtime = random_int($conv->timecreated, $now);

        $usermsg = (object) [
            'conversationid' => $conv->id,
            'userid' => $userid,
            'courseid' => $courseid,
            'role' => 'user',
            'message' => $userq,
            'tokens_used' => 0,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'model_name' => null,
            'provider' => null,
            'interaction_type' => $interaction,
            'cmid' => null,
            'timecreated' => $msgtime,
        ];
        $DB->insert_record('local_ai_course_assistant_msgs', $usermsg);

        $prompttokens = $provider['promptbase'] + random_int(-300, 300);
        $completiontokens = $provider['completionbase'] + random_int(-200, 400);
        $asstmsg = (object) [
            'conversationid' => $conv->id,
            'userid' => $userid,
            'courseid' => $courseid,
            'role' => 'assistant',
            'message' => $assistantreply,
            'tokens_used' => $prompttokens + $completiontokens,
            'prompt_tokens' => $prompttokens,
            'completion_tokens' => $completiontokens,
            'model_name' => $provider['model'],
            'provider' => $provider['provider'],
            'interaction_type' => $interaction,
            'cmid' => null,
            'timecreated' => $msgtime + random_int(5, 30),
        ];
        $asstmsgid = $DB->insert_record('local_ai_course_assistant_msgs', $asstmsg);
        $totalmsgs += 2;

        // 25% chance of a rating on the assistant message. 80% thumbs up.
        if (random_int(1, 4) === 1) {
            $rating = (random_int(1, 5) === 1) ? -1 : 1;
            $DB->insert_record('local_ai_course_assistant_msg_ratings', (object) [
                'messageid' => $asstmsgid,
                'userid' => $userid,
                'courseid' => $courseid,
                'rating' => $rating,
                'is_hallucination' => 0,
                'comment' => null,
                'timecreated' => $asstmsg->timecreated + 60,
            ]);
            $totalratings++;
        }
    }

    // 40% of users leave global feedback.
    if (random_int(1, 5) <= 2) {
        $fcomments = [
            'This has been really helpful for understanding the harder sections.',
            'I like that it remembers what I was looking at on the previous page.',
            'Sometimes the explanations are too long, but overall useful.',
            'Great for late-night studying when I cannot reach anyone else.',
            'Wish the voice feature worked on my phone, but the chat is great.',
        ];
        $DB->insert_record('local_ai_course_assistant_feedback', (object) [
            'userid' => $userid,
            'courseid' => $courseid,
            'rating' => random_int(3, 5),
            'comment' => $fcomments[array_rand($fcomments)],
            'browser' => 'Chrome',
            'os' => 'macOS',
            'device' => 'desktop',
            'screen_size' => '1440x900',
            'user_agent' => 'Mozilla/5.0 (demo)',
            'page_url' => (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
            'timecreated' => random_int($windowstart, $now),
        ]);
        $totalfeedback++;
    }
}

cli_writeln("");
cli_writeln("Done.");
cli_writeln("  Users:         " . count($userids));
cli_writeln("  Conversations: {$totalconvs}");
cli_writeln("  Messages:      {$totalmsgs}");
cli_writeln("  Ratings:       {$totalratings}");
cli_writeln("  Feedback:      {$totalfeedback}");
cli_writeln("");
cli_writeln("Purge caches if the analytics dashboard does not reflect the new data.");
