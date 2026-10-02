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
 * Per-course settings page behaviour.
 *
 * Replaces the inline script course_settings.php used to echo
 * (CONTRIB-10574 #273 and #278). The page has exactly one piece of behaviour:
 * the "Reset to global" button next to the system prompt asks for confirmation
 * and then drops the site-wide default prompt into the textarea. That is
 * unchanged here.
 *
 * The default prompt and the confirm text arrive in the root element's
 * data-config attribute rather than as js_call_amd arguments. A system prompt
 * runs to several kilobytes on its own, well past the 1 KB advisory limit those
 * arguments carry, which is what issue #277 flagged on the starter admin page.
 *
 * @module     local_ai_course_assistant/course_settings
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    return {
        /**
         * Wire the reset-prompt button.
         *
         * Every lookup is guarded: the button and the textarea both live in the
         * provider card, and a page that ever stops rendering that card should
         * not take the rest of the settings form down with it.
         *
         * @returns {void}
         */
        init: function() {
            var root = document.querySelector('.aica-course-settings');
            if (!root) {
                return;
            }
            var raw = root.getAttribute('data-config');
            if (!raw) {
                return;
            }
            var config;
            try {
                config = JSON.parse(raw);
            } catch (e) {
                return;
            }

            var button = document.getElementById('btn-reset-prompt');
            var textarea = document.getElementById('systemprompt');
            if (!button || !textarea) {
                return;
            }

            button.addEventListener('click', function() {
                if (window.confirm(config.resetconfirm)) { // eslint-disable-line no-alert
                    textarea.value = config.globalprompt;
                }
            });
        }
    };
});
