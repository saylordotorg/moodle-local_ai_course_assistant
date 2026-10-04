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
 * "Reset to template" for the system prompt setting.
 *
 * Replaces an inline onclick (CONTRIB-10574 #273) that set the textarea to
 * atob('<base64 of the default prompt>'). atob() decodes to a Latin-1 byte
 * string, not UTF-8, so in 37 of the 46 locales every non-ASCII character in
 * the translated prompt came back as mojibake. The default now arrives on a
 * data-default attribute, which the browser hands back exactly as written.
 *
 * @module     local_ai_course_assistant/reset_prompt
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    var initialised = false;

    return {
        /**
         * Handle clicks on any [data-action="sola-reset-prompt"] button.
         */
        init: function() {
            if (initialised) {
                return;
            }
            initialised = true;
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('[data-action="sola-reset-prompt"]');
                if (!btn) {
                    return;
                }
                var target = document.getElementById(btn.dataset.target);
                if (!target) {
                    return;
                }
                target.value = btn.dataset.default;
                // Tell the form it changed, so Moodle's unsaved-changes check
                // notices a reset just as it would a typed edit. The old onclick
                // set .value silently.
                target.dispatchEvent(new Event('input', {bubbles: true}));
                target.dispatchEvent(new Event('change', {bubbles: true}));
            });
        }
    };
});
