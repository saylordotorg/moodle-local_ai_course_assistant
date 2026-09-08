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
 * Student-mode exit hotkey.
 *
 * Ctrl+Shift+A leaves the admin's student-preview mode from any page. Moved out
 * of an inline <script> in chat_widget.mustache for CONTRIB-10574 #201.
 *
 * @module     local_ai_course_assistant/student_mode
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    return {
        /**
         * Bind the exit hotkey.
         *
         * @returns {void}
         */
        init: function() {
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.shiftKey && (e.key === 'A' || e.key === 'a')) {
                    e.preventDefault();
                    window.location.href = window.location.pathname + '?sola_student_mode=0';
                }
            });
        }
    };
});
