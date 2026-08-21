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
 * Learner-facing flashcard review page (v3.9.22).
 *
 * Lists all due cards and lets the learner self-grade each one with three
 * buttons (Again / Hard / Easy) that map to SM-2 lite quality 1 / 3 / 5.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\flashcard_manager;
use local_ai_course_assistant\security;

require_login();

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($courseid);
require_capability('local/ai_course_assistant:use', $context);

if (!flashcard_manager::is_enabled_for_course($courseid)) {
    throw new \moodle_exception('flashcards:disabled', 'local_ai_course_assistant');
}

$pageurl = new moodle_url('/local/ai_course_assistant/flashcards.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_course($course);
$PAGE->set_title(get_string('flashcards:title', 'local_ai_course_assistant'));
$PAGE->set_heading($course->fullname);

security::send_security_headers(true);

$due = flashcard_manager::get_due((int) $USER->id, $courseid, 30);

// Card question/answer text is run through format_text() here rather than in the
// template: the template receives ready-to-print HTML and emits it unescaped.
$cards = [];
foreach ($due as $card) {
    $cards[] = [
        'id' => (int) $card->id,
        'question' => format_text($card->question, FORMAT_PLAIN),
        'answer' => format_text($card->answer, FORMAT_PLAIN),
    ];
}

$templatedata = [
    'title' => get_string('flashcards:title', 'local_ai_course_assistant'),
    'intro' => get_string('flashcards:intro', 'local_ai_course_assistant'),
    'hasdue' => !empty($cards),
    'nodue' => get_string('flashcards:no_due', 'local_ai_course_assistant'),
    'sesskey' => sesskey(),
    'courseid' => $courseid,
    'serviceurl' => (new moodle_url('/lib/ajax/service.php', ['sesskey' => sesskey()]))->out(false),
    'questionlabel' => get_string('flashcards:question', 'local_ai_course_assistant'),
    'answerlabel' => get_string('flashcards:answer', 'local_ai_course_assistant'),
    'revealbtn' => get_string('flashcards:reveal', 'local_ai_course_assistant'),
    'againbtn' => get_string('flashcards:again', 'local_ai_course_assistant'),
    'hardbtn' => get_string('flashcards:hard', 'local_ai_course_assistant'),
    'easybtn' => get_string('flashcards:easy', 'local_ai_course_assistant'),
    'sessioncomplete' => get_string('flashcards:session_complete', 'local_ai_course_assistant'),
    'cards' => $cards,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/flashcards', $templatedata);
echo $OUTPUT->footer();
