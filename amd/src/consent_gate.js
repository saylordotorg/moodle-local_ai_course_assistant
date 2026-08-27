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
 * First-run consent scroll gate.
 *
 * The Accept button stays disabled until the learner has scrolled the privacy
 * notice to the bottom. Moved out of an inline <script> in chat_widget.mustache
 * for CONTRIB-10574 #201; the behaviour is unchanged, including the two
 * non-obvious guards that took a bug each to find (see reevaluate()).
 *
 * @module     local_ai_course_assistant/consent_gate
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {

    return {
        /**
         * Wire the gate. Safe to call when the banner is absent.
         *
         * @returns {void}
         */
        init: function() {
            var banner = document.querySelector('.aica-consent-banner');
            if (!banner) {
                return;
            }
            var scroller = banner.querySelector('.aica-consent-scroll');
            var btn = banner.querySelector('.aica-consent-accept');
            var hint = banner.querySelector('.aica-consent-scrollhint');
            if (!scroller || !btn) {
                return;
            }

            var unlocked = false;

            /**
             * Enable the Accept button.
             *
             * @returns {void}
             */
            var unlock = function() {
                if (unlocked) {
                    return;
                }
                unlocked = true;
                btn.disabled = false;
                btn.setAttribute('aria-disabled', 'false');
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
                if (hint) {
                    hint.style.display = 'none';
                }
            };

            /**
             * Unlock if the notice has been read, or needs no scrolling.
             *
             * @returns {void}
             */
            var reevaluate = function() {
                // While the drawer is closed the region has no layout (0
                // height); a 0-height region would read as "already at the
                // bottom" and unlock before the learner has seen anything, so
                // skip until it is shown.
                if (scroller.scrollHeight <= 0 || scroller.clientHeight <= 0) {
                    return;
                }
                // True both when the policy fits without scrolling and when the
                // learner has scrolled to the bottom.
                if (scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 4) {
                    unlock();
                }
            };

            reevaluate();
            scroller.addEventListener('scroll', reevaluate);

            // The drawer may render hidden (clientHeight 0); re-check once it
            // has a real size so a short policy that needs no scrolling still
            // unlocks.
            if (window.ResizeObserver) {
                var ro = new window.ResizeObserver(reevaluate);
                ro.observe(scroller);
            }

            btn.addEventListener('click', function() {
                if (btn.disabled) {
                    return;
                }
                // Record consent through the external service (core/ajax adds
                // the sesskey); hide the banner regardless of the outcome.
                Ajax.call([{
                    methodname: 'local_ai_course_assistant_record_consent',
                    args: {}
                }])[0].always(function() {
                    banner.style.display = 'none';
                });
            });
        }
    };
});
