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
 * End-of-course survey admin editor.
 *
 * Replaces the 320-line inline script survey_admin.php used to echo
 * (CONTRIB-10574 #273 and #278). The behaviour is unchanged: a drag-to-reorder
 * list of question cards whose state is serialised into the hidden
 * questions_json field the page posts, plus a preview dialog.
 *
 * Three things moved with it:
 *
 * 1. The cards and the preview dialog render through core/templates instead of
 *    being assembled node by node, so the markup lives in Mustache files and
 *    the escaping is the template engine's job.
 * 2. Every style attribute the old script set in JavaScript is now a class in
 *    styles.css.
 * 3. The questions and the translated strings arrive in the root element's
 *    data-config attribute rather than as echoed JSON literals or js_call_amd
 *    arguments, which carry a 1 KB advisory limit a survey exceeds.
 *
 * @module     local_ai_course_assistant/survey_admin
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/templates', 'core/notification'], function(Templates, Notification) {

    /** @type {Array} The survey being edited. This array is the source of truth. */
    var questions = [];
    /** @type {Object} Pre-resolved UI strings from PHP. */
    var strs = {};

    var container = null;
    /**
     * @type {number} Bumped on every render so a slow render that has been
     * superseded discards its own result instead of painting stale cards.
     */
    var renderToken = 0;

    /**
     * The human-readable name of a question type.
     *
     * @param {string} type The stored type slug.
     * @returns {string} Its label.
     */
    function typeLabel(type) {
        if (type === 'multiple_choice') {
            return strs.typemultiplechoice;
        }
        if (type === 'rating') {
            return strs.typerating;
        }
        return strs.typeopentext;
    }

    /**
     * Substitute the option number into the numbered-option label.
     *
     * Moodle's string cache hands back the raw placeholder, so this cannot be
     * done in get_string().
     *
     * @param {number} n The one-based option number.
     * @returns {string} The label.
     */
    function optionLabel(n) {
        return String(strs.optionn).replace('{n}', n);
    }

    /**
     * Template context for one question card.
     *
     * @param {Object} q The question.
     * @param {number} idx Its position in the list.
     * @returns {Object} Context.
     */
    function cardContext(q, idx) {
        var types = [
            {value: 'multiple_choice', label: strs.typemultiplechoice},
            {value: 'long_text', label: strs.typeopentext},
            {value: 'rating', label: strs.typerating}
        ];
        return {
            idx: idx,
            number: idx + 1,
            typelabel: typeLabel(q.type),
            text: q.text || '',
            typeoptions: types.map(function(t) {
                return {value: t.value, label: t.label, selected: q.type === t.value};
            }),
            ismultiplechoice: q.type === 'multiple_choice',
            options: (q.options || []).map(function(opt, oi) {
                return {oi: oi, value: opt, arialabel: optionLabel(oi + 1)};
            }),
            israting: q.type === 'rating',
            min: q.min || 1,
            max: q.max || 5,
            minlabel: q.min_label || '',
            maxlabel: q.max_label || '',
            str: strs
        };
    }

    /**
     * Move the question at one index to another and repaint.
     *
     * @param {number} from Source index.
     * @param {number} to Destination index.
     */
    function moveQuestion(from, to) {
        var moved = questions.splice(from, 1)[0];
        questions.splice(to, 0, moved);
        renderAll();
    }

    /**
     * Swap two adjacent questions and repaint.
     *
     * @param {number} a First index.
     * @param {number} b Second index.
     */
    function swapQuestions(a, b) {
        var tmp = questions[a];
        questions[a] = questions[b];
        questions[b] = tmp;
        renderAll();
    }

    /**
     * Wire the multiple-choice option rows on one card.
     *
     * @param {HTMLElement} card The card element.
     * @param {Object} q Its question.
     */
    function bindOptions(card, q) {
        var inputs = card.querySelectorAll('.aica-sq-opt-input');
        Array.prototype.forEach.call(inputs, function(inp) {
            var oi = parseInt(inp.getAttribute('data-oi'), 10);
            inp.addEventListener('input', function() {
                q.options[oi] = inp.value;
            });
        });

        var removes = card.querySelectorAll('.aica-sq-opt-remove');
        Array.prototype.forEach.call(removes, function(btn) {
            var oi = parseInt(btn.getAttribute('data-oi'), 10);
            btn.addEventListener('click', function() {
                q.options.splice(oi, 1);
                renderAll();
            });
        });

        var addOpt = card.querySelector('.aica-sq-add-opt');
        if (addOpt) {
            addOpt.addEventListener('click', function() {
                if (!q.options) {
                    q.options = [];
                }
                q.options.push(strs.newoption);
                renderAll();
            });
        }
    }

    /**
     * Wire the four rating fields on one card.
     *
     * @param {HTMLElement} card The card element.
     * @param {Object} q Its question.
     */
    function bindRating(card, q) {
        var minInp = card.querySelector('.aica-sq-min-input');
        if (minInp) {
            minInp.addEventListener('input', function() {
                // Not `|| 1`: a typed 0 is falsy, so the old form silently
                // rewrote it to 1 and the server-side bounds check never saw
                // the value the admin actually entered.
                q.min = Number.isNaN(parseInt(minInp.value, 10)) ? 1 : parseInt(minInp.value, 10);
            });
        }
        var maxInp = card.querySelector('.aica-sq-max-input');
        if (maxInp) {
            maxInp.addEventListener('input', function() {
                q.max = Number.isNaN(parseInt(maxInp.value, 10)) ? 5 : parseInt(maxInp.value, 10);
            });
        }
        var minLblInp = card.querySelector('.aica-sq-minlabel-input');
        if (minLblInp) {
            minLblInp.addEventListener('input', function() {
                q.min_label = minLblInp.value;
            });
        }
        var maxLblInp = card.querySelector('.aica-sq-maxlabel-input');
        if (maxLblInp) {
            maxLblInp.addEventListener('input', function() {
                q.max_label = maxLblInp.value;
            });
        }
    }

    /**
     * Wire one rendered card back to its entry in the questions array.
     *
     * @param {HTMLElement} card The card element.
     */
    function bindCard(card, boundToken) {
        // Issue #273/#278 review: renderAll() is asynchronous now, where the
        // old inline script repainted synchronously. The token below guarded
        // the PAINT but not the handlers already bound to the cards still on
        // screen while a render is in flight, and those close over indices
        // into an array that has already been mutated. A second click during
        // that window acted on the wrong row, or silently on nothing.
        //
        // A card bound in generation N stops responding the moment generation
        // N+1 starts. Losing a click during a repaint is the right failure;
        // deleting the wrong row is not.
        var stale = function() {
            return boundToken !== undefined && boundToken !== renderToken;
        };
        var idx = parseInt(card.getAttribute('data-idx'), 10);
        var q = questions[idx];
        if (!q) {
            return;
        }

        card.addEventListener('dragstart', function(e) {
            if (stale()) {
                return;
            }
            card.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', idx);
        });
        card.addEventListener('dragend', function() {
            if (stale()) {
                return;
            }
            card.classList.remove('dragging');
        });
        card.addEventListener('dragover', function(e) {
            if (stale()) {
                return;
            }
            e.preventDefault();
        });
        card.addEventListener('drop', function(e) {
            if (stale()) {
                return;
            }
            e.preventDefault();
            var fromIdx = parseInt(e.dataTransfer.getData('text/plain'), 10);
            var toIdx = parseInt(card.getAttribute('data-idx'), 10);
            if (fromIdx !== toIdx) {
                moveQuestion(fromIdx, toIdx);
            }
        });

        card.querySelector('.aica-sq-up').addEventListener('click', function() {
            if (idx > 0) {
                swapQuestions(idx, idx - 1);
            }
        });
        card.querySelector('.aica-sq-down').addEventListener('click', function() {
            if (idx < questions.length - 1) {
                swapQuestions(idx, idx + 1);
            }
        });
        card.querySelector('.aica-sq-delete').addEventListener('click', function() {
            if (window.confirm(strs.confirmdelete)) {
                questions.splice(idx, 1);
                renderAll();
            }
        });

        var typeSelect = card.querySelector('.aica-sq-type-select');
        typeSelect.addEventListener('change', function() {
            q.type = typeSelect.value;
            if (q.type === 'multiple_choice' && !q.options) {
                q.options = [optionLabel(1), optionLabel(2)];
            }
            if (q.type === 'rating') {
                q.min = q.min || 1;
                q.max = q.max || 5;
            }
            renderAll();
        });

        var textInput = card.querySelector('.aica-sq-text-input');
        textInput.addEventListener('input', function() {
            q.text = textInput.value;
        });

        if (q.type === 'multiple_choice') {
            bindOptions(card, q);
        }
        if (q.type === 'rating') {
            bindRating(card, q);
        }
    }

    /**
     * Repaint the whole question list.
     *
     * @returns {Promise} Resolves once the cards are in the page and bound.
     */
    function renderAll() {
        var token = ++renderToken;
        return Templates.render('local_ai_course_assistant/survey_admin_questions', {
            questions: questions.map(cardContext)
        }).then(function(html, js) {
            if (token !== renderToken) {
                return null;
            }
            Templates.replaceNodeContents(container, html, js);
            var cards = container.querySelectorAll('.aica-sq-card');
            Array.prototype.forEach.call(cards, function(card) {
                bindCard(card, token);
            });
            return null;
        }).catch(Notification.exception);
    }

    /**
     * Open the read-only preview dialog for the survey currently in the editor.
     */
    function openPreview() {
        var titleInput = document.getElementById('aica-survey-title');
        var context = {
            title: (titleInput && titleInput.value) || strs.previewtitle,
            closelabel: strs.previewclose,
            questions: questions.map(function(q, idx) {
                var dots = [];
                var r;
                if (q.type === 'rating') {
                    for (r = (q.min || 1); r <= (q.max || 5); r++) {
                        dots.push({n: r});
                    }
                }
                return {
                    number: idx + 1,
                    text: q.text || strs.previewnotext,
                    ismultiplechoice: q.type === 'multiple_choice',
                    options: (q.options || []).map(function(opt) {
                        return {label: opt};
                    }),
                    israting: q.type === 'rating',
                    hasminlabel: !!q.min_label,
                    minlabel: q.min_label || '',
                    hasmaxlabel: !!q.max_label,
                    maxlabel: q.max_label || '',
                    dots: dots,
                    isopentext: q.type !== 'multiple_choice' && q.type !== 'rating',
                    answerhint: strs.previewanswerhint
                };
            })
        };

        Templates.render('local_ai_course_assistant/survey_admin_preview', context)
            .then(function(html, js) {
                var holder = document.createElement('div');
                document.body.appendChild(holder);
                Templates.replaceNodeContents(holder, html, js);

                var close = function() {
                    if (holder.parentNode) {
                        holder.parentNode.removeChild(holder);
                    }
                };
                var overlay = holder.querySelector('.sola-sq-preview-overlay');
                if (overlay) {
                    overlay.addEventListener('click', function(e) {
                        if (e.target === overlay) {
                            close();
                        }
                    });
                }
                var closeBtn = holder.querySelector('.sola-sq-preview-close');
                if (closeBtn) {
                    closeBtn.addEventListener('click', close);
                }
                return null;
            }).catch(Notification.exception);
    }

    return {
        /**
         * Build the editor.
         *
         * The configuration is read from the page rather than taken as an
         * argument: a survey plus its translated strings runs well past the
         * 1 KB that Moodle warns about for js_call_amd arguments.
         *
         * @returns {void}
         */
        init: function() {
            var root = document.querySelector('.aica-survey-admin');
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
            questions = config.questions || [];
            strs = config.strings || {};

            container = document.getElementById('aica-questions-container');
            if (!container) {
                return;
            }

            var addBtn = document.getElementById('aica-add-question-btn');
            if (addBtn) {
                addBtn.addEventListener('click', function() {
                    questions.push({type: 'long_text', text: ''});
                    renderAll().then(function() {
                        var cards = container.querySelectorAll('.aica-sq-card');
                        if (cards.length) {
                            cards[cards.length - 1].scrollIntoView({behavior: 'smooth', block: 'center'});
                        }
                        return null;
                    }).catch(Notification.exception);
                });
            }

            var form = document.getElementById('aica-survey-form');
            if (form) {
                form.addEventListener('submit', function() {
                    document.getElementById('aica-questions-json').value = JSON.stringify(questions);
                });
            }

            var resetBtn = document.getElementById('aica-reset-btn');
            if (resetBtn) {
                resetBtn.addEventListener('click', function() {
                    if (window.confirm(strs.confirmreset)) {
                        document.getElementById('aica-reset-form').submit();
                    }
                });
            }

            var previewBtn = document.getElementById('aica-preview-btn');
            if (previewBtn) {
                previewBtn.addEventListener('click', openPreview);
            }

            // The scope select used to carry an inline handler attribute.
            var scopeSelect = document.getElementById('aica-scope-select');
            if (scopeSelect) {
                scopeSelect.addEventListener('change', function() {
                    scopeSelect.form.submit();
                });
            }

            renderAll();
        }
    };
});
