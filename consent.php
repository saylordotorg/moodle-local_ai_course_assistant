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
 * Record a learner's first-run SOLA consent.
 *
 * Stores the consent timestamp in a Moodle user preference so the widget
 * does not show the banner again. Purely informational; learners can always
 * delete their SOLA data from `settings_user.php` regardless of consent.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

set_user_preference('aica_sola_consent_given', time());

\local_ai_course_assistant\audit_logger::log('consent_given', (int)$USER->id, 0, []);

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'at' => time()]);
