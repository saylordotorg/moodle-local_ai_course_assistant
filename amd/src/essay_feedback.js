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
 * Essay feedback page controller.
 *
 * Replaces the inline <script> that essay_feedback.php used to echo
 * (CONTRIB-10574 #273). Three things changed with the move, none of them
 * cosmetic:
 *
 * 1. The raw fetch() against /lib/ajax/service.php is now a core/ajax call,
 *    so the sesskey and the request envelope come from Moodle rather than
 *    from a URL interpolated into the page.
 * 2. The hand-rolled HTML escaper and the innerHTML concatenation are gone.
 *    The scores render through core/templates, which escapes the
 *    model-supplied text for us. That escaper was the only thing standing
 *    between an AI response and injected markup, so deleting it in favour of
 *    the template engine is the point of the change, not a side effect.
 * 3. Strings come from core/str instead of being baked in by PHP, so the
 *    module can be loaded on any page without a PHP round trip per string.
 *
 * @module     local_ai_course_assistant/essay_feedback
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/templates', 'core/str', 'core/notification'],
function(Ajax, Templates, Str, Notification) {

    /** @type {number} Minimum characters before we will spend a model call. */
    var MIN_ESSAY_CHARS = 80;
    /** @type {number} Rubric scores are out of four. */
    var MAX_SCORE = 4;

    // English fallbacks cover the window before core/str resolves, matching
    // the pattern in learning_radar.js.
    var strs = {
        tooShort: 'Please paste at least 80 words so the assistant has something to score.',
        error: 'Could not score this draft right now. Try again in a moment.'
    };

    var form, submitBtn, statusEl, out, courseid;

    /**
     * Show or hide an element without an inline style attribute.
     *
     * @param {HTMLElement} el
     * @param {boolean} visible
     */
    function toggle(el, visible) {
        if (el) {
            el.classList.toggle('sola-essay-hidden', !visible);
        }
    }

    /**
     * Filled-circle score meter, e.g. three of four.
     *
     * @param {number} score
     * @returns {string}
     */
    function stars(score) {
        var meter = '';
        for (var i = 0; i < MAX_SCORE; i++) {
            meter += i < score ? '●' : '○';
        }
        return meter;
    }

    /**
     * Put a plain message in the result area inside an alert.
     *
     * @param {string} text
     * @param {string} tone Bootstrap alert suffix, 'warning' or 'danger'.
     * @param {string} detail Optional status code shown in a code element.
     */
    function showAlert(text, tone, detail) {
        var alert = document.createElement('div');
        alert.className = 'alert alert-' + tone;
        alert.appendChild(document.createTextNode(text));
        if (detail) {
            var code = document.createElement('code');
            // textContent, so a status code echoed back by the service can
            // never become markup.
            code.textContent = detail;
            alert.appendChild(document.createTextNode(' '));
            alert.appendChild(code);
        }
        out.textContent = '';
        out.appendChild(alert);
        toggle(out, true);
    }

    /**
     * Re-enable the form after a run finishes, succeed or fail.
     */
    function finish() {
        submitBtn.disabled = false;
        toggle(statusEl, false);
    }

    /**
     * Render the rubric scores for one run.
     *
     * @param {object} res Response from local_ai_course_assistant_score_essay.
     */
    function renderResult(res) {
        var revisions = (res.revisions || []).map(function(text) {
            return {text: text};
        });
        var context = {
            criteria: (res.criteria || []).map(function(c) {
                return {
                    name: c.name,
                    score: c.score,
                    stars: stars(c.score),
                    feedback: c.feedback
                };
            }),
            overall: res.overall || '',
            hasrevisions: revisions.length > 0,
            revisions: revisions
        };
        return Templates.render('local_ai_course_assistant/essay_feedback_result', context)
            .then(function(html) {
                Templates.replaceNodeContents(out, html, '');
                toggle(out, true);
                return null;
            });
    }

    /**
     * Submit handler: score the pasted draft.
     *
     * @param {Event} e
     */
    function onSubmit(e) {
        e.preventDefault();
        var essay = document.getElementById('aica-essay-text').value || '';
        var rubric = document.getElementById('aica-essay-rubric').value || '';
        if (essay.trim().length < MIN_ESSAY_CHARS) {
            showAlert(strs.tooShort, 'warning', '');
            return;
        }
        submitBtn.disabled = true;
        toggle(statusEl, true);
        toggle(out, false);

        Ajax.call([{
            methodname: 'local_ai_course_assistant_score_essay',
            args: {courseid: courseid, essay: essay, rubric: rubric}
        }])[0]
        .then(function(res) {
            finish();
            if (!res || !res.success) {
                showAlert(strs.error, 'warning', (res && res.message) ? res.message : 'unknown');
                return null;
            }
            return renderResult(res);
        })
        .catch(function() {
            finish();
            showAlert(strs.error, 'danger', '');
        });
    }

    /**
     * Wire up the page.
     */
    function init() {
        form = document.getElementById('aica-essay-form');
        if (!form) {
            return;
        }
        submitBtn = document.getElementById('aica-essay-submit');
        statusEl = document.getElementById('aica-essay-status');
        out = document.getElementById('aica-essay-result');

        var root = form.closest('.sola-essay');
        courseid = parseInt(root ? root.getAttribute('data-courseid') : '0', 10) || 0;

        Str.get_strings([
            {key: 'essay_feedback:too_short', component: 'local_ai_course_assistant'},
            {key: 'essay_feedback:error', component: 'local_ai_course_assistant'}
        ]).then(function(loaded) {
            strs.tooShort = loaded[0];
            strs.error = loaded[1];
            return null;
        }).catch(Notification.exception);

        form.addEventListener('submit', onSubmit);
    }

    return {init: init};
});
