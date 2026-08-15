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
 * Create a ready-to-demo course with sections, pages, and a book.
 *
 * Produces a course that looks plausible on camera: three topic sections,
 * each with a page resource; one book with two chapters. Returns the
 * course id so you can pass it to seed_demo_data.php.
 *
 * Usage (run from Moodle root):
 *   php local/ai_course_assistant/admin/cli/create_demo_course.php \
 *       --shortname=DEMO101 --fullname="Demo Course 101" --category=1
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');

[$options, $unrecognized] = cli_get_params([
    'shortname' => 'DEMO101',
    'fullname'  => 'Demo Course 101',
    'category'  => 1,
    'help'      => false,
], ['h' => 'help']);

if ($options['help']) {
    cli_writeln("Create a demo course with sample sections, pages, and a book.");
    cli_writeln("");
    cli_writeln("Usage: php create_demo_course.php [--shortname=DEMO101] [--fullname=\"Demo Course 101\"] [--category=1]");
    exit(0);
}

global $DB;

$shortname = $options['shortname'];
$fullname  = $options['fullname'];
$category  = (int) $options['category'];

// Skip if the course already exists.
$existing = $DB->get_record('course', ['shortname' => $shortname]);
if ($existing) {
    cli_writeln("Course already exists: id={$existing->id}, shortname={$existing->shortname}");
    cli_writeln("Delete it in Moodle admin first if you want to recreate it.");
    exit(0);
}

cli_writeln("Creating course '{$fullname}' (shortname={$shortname})...");

$coursedata = (object) [
    'category'       => $category,
    'shortname'      => $shortname,
    'fullname'       => $fullname,
    'summary'        => 'A demo course for showcasing the AI Course Assistant. '
        . 'Contains sample pages and a two-chapter book to illustrate how the '
        . 'assistant grounds its answers in course content.',
    'summaryformat'  => FORMAT_HTML,
    'format'         => 'topics',
    'numsections'    => 3,
    'startdate'      => time(),
    'visible'        => 1,
];
$course = create_course($coursedata);
cli_writeln("  course id = {$course->id}");

// Topics and page content keyed by section.
$sections = [
    1 => [
        'name' => 'Getting Started',
        'pages' => [
            [
                'name' => 'Welcome and course overview',
                'content' => '<p>Welcome to the course. Over the next few weeks you will learn '
                    . 'how to think about a topic from multiple perspectives, connect theory to '
                    . 'practice, and build confidence applying what you learn.</p>'
                    . '<p>Each week combines short readings, a practice activity, and a short '
                    . 'check-in quiz. The AI Course Assistant is available on every page if you '
                    . 'get stuck or want a concept explained differently.</p>',
            ],
            [
                'name' => 'How to succeed in this course',
                'content' => '<p>Three habits predict success:</p><ul>'
                    . '<li>Read actively. Ask yourself what the author is trying to prove.</li>'
                    . '<li>Practice regularly. Even ten minutes a day beats one long cram session.</li>'
                    . '<li>Use the assistant. It knows the course material and can adapt to your level.</li>'
                    . '</ul>',
            ],
        ],
    ],
    2 => [
        'name' => 'Core Concepts',
        'pages' => [
            [
                'name' => 'Foundational terminology',
                'content' => '<p>Before we go further, let us make sure we share a vocabulary. '
                    . 'Three terms are used throughout this course:</p>'
                    . '<ol><li><strong>Hypothesis.</strong> A testable statement about how something works.</li>'
                    . '<li><strong>Evidence.</strong> Observations that support or challenge a hypothesis.</li>'
                    . '<li><strong>Inference.</strong> A reasoned conclusion drawn from evidence.</li></ol>'
                    . '<p>Keep these distinct in your mind. Conflating them is a common source of errors.</p>',
            ],
            [
                'name' => 'A worked example',
                'content' => '<p>Imagine we want to know whether a particular study habit improves '
                    . 'retention. We form the hypothesis: "Spaced repetition improves long-term retention '
                    . 'compared to massed practice."</p>'
                    . '<p>We collect evidence by running a two-week study where half the students use '
                    . 'spaced repetition and the other half cram. We measure recall after two weeks.</p>'
                    . '<p>The inference we draw depends on the size and consistency of the difference we observe.</p>',
            ],
        ],
    ],
    3 => [
        'name' => 'Applying What You Learned',
        'pages' => [
            [
                'name' => 'Practice prompts',
                'content' => '<p>Try these on your own, then ask the assistant to check your reasoning:</p>'
                    . '<ol><li>State a hypothesis about study habits that you believe to be true.</li>'
                    . '<li>List two pieces of evidence that would support it.</li>'
                    . '<li>List one piece of evidence that would challenge it.</li></ol>',
            ],
            [
                'name' => 'Preparing for the final',
                'content' => '<p>The final covers the three key terms, the worked example, and your '
                    . 'ability to apply them to a new scenario. The assistant can generate unlimited '
                    . 'practice quizzes from this course, so work through several before the final.</p>',
            ],
        ],
    ],
];

foreach ($sections as $sectionnum => $section) {
    // Rename section.
    $DB->set_field(
        'course_sections',
        'name',
        $section['name'],
        ['course' => $course->id, 'section' => $sectionnum]
    );

    foreach ($section['pages'] as $page) {
        $module = new stdClass();
        $module->course = $course->id;
        $module->section = $sectionnum;
        $module->visible = 1;
        $module->module = $DB->get_field('modules', 'id', ['name' => 'page']);
        $module->modulename = 'page';
        $module->name = $page['name'];
        $module->intro = '';
        $module->introformat = FORMAT_HTML;
        $module->content = $page['content'];
        $module->contentformat = FORMAT_HTML;
        $module->display = 0;
        $module->printheading = 1;
        $module->printintro = 0;
        $module->printlastmodified = 1;
        $module->cmidnumber = '';
        $module->groupmode = 0;
        $module->groupingid = 0;

        add_moduleinfo($module, $course);
        cli_writeln("  section {$sectionnum}: page '{$page['name']}' added");
    }
}

// Add a short book to section 2.
$bookid = $DB->get_field('modules', 'id', ['name' => 'book']);
if ($bookid) {
    $book = new stdClass();
    $book->course = $course->id;
    $book->section = 2;
    $book->visible = 1;
    $book->module = $bookid;
    $book->modulename = 'book';
    $book->name = 'Deeper reading: evidence and inference';
    $book->intro = 'A short two-chapter companion to the core concepts section.';
    $book->introformat = FORMAT_HTML;
    $book->numbering = 1;
    $book->navstyle = 2;
    $book->customtitles = 0;
    $book->cmidnumber = '';
    $book->groupmode = 0;
    $book->groupingid = 0;
    $bookmod = add_moduleinfo($book, $course);

    $chapters = [
        ['title' => 'The difference between observation and inference',
         'content' => '<p>Observation is what you see. Inference is what you think it means. '
            . 'A single observation can support many inferences. Good scientists hold their '
            . 'inferences loosely until enough evidence converges.</p>'],
        ['title' => 'How much evidence is enough?',
         'content' => '<p>There is no universal answer, but three criteria help. First, does the '
            . 'evidence come from multiple independent sources? Second, is it consistent across time? '
            . 'Third, have you actively looked for counter-evidence?</p>'],
    ];
    foreach ($chapters as $i => $chapter) {
        $DB->insert_record('book_chapters', (object) [
            'bookid' => $bookmod->instance,
            'pagenum' => $i + 1,
            'subchapter' => 0,
            'title' => $chapter['title'],
            'content' => $chapter['content'],
            'contentformat' => FORMAT_HTML,
            'hidden' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
            'importsrc' => '',
        ]);
    }
    cli_writeln("  section 2: book 'Deeper reading' with 2 chapters added");
}

rebuild_course_cache($course->id);

cli_writeln("");
cli_writeln("Done. Course id: {$course->id}");
cli_writeln("");
cli_writeln("Next step: seed conversations with");
cli_writeln("  php local/ai_course_assistant/admin/cli/seed_demo_data.php --courseid={$course->id} --users=15 --weeks=4");
