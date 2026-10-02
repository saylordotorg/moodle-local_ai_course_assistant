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
 * Practice-rubric admin editor.
 *
 * Replaces the 280-line inline script rubric_admin.php used to echo
 * (CONTRIB-10574 #273 and #278). The behaviour is unchanged: a drag-to-reorder
 * list of criterion cards whose state is serialised into the hidden
 * criteria_json field the page posts, plus a preview dialog.
 *
 * Three things moved with it:
 *
 * 1. The cards and the preview dialog render through core/templates instead of
 *    being assembled node by node, so the markup lives in Mustache files and
 *    the escaping is the template engine's job.
 * 2. Every style attribute the old script set in JavaScript is now a class in
 *    styles.css.
 * 3. The criteria, the outcome list and the translated strings arrive in the
 *    root element's data-config attribute rather than as echoed JSON literals
 *    or js_call_amd arguments, which carry a 1 KB advisory limit a rubric can
 *    exceed.
 *
 * @module     local_ai_course_assistant/rubric_admin
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/templates', 'core/notification'], function(Templates, Notification) {

    /** @type {Array} The rubric being edited. This array is the source of truth. */
    var criteria = [];
    /** @type {Object} Pre-resolved UI strings from PHP. */
    var strs = {};
    /** @type {Array} Course outcomes available for per-criterion mapping. */
    var objectives = [];

    var container = null;
    /**
     * @type {number} Bumped on every render so a slow render that has been
     * superseded discards its own result instead of painting stale cards.
     */
    var renderToken = 0;

    /**
     * Build the outcome options for one criterion.
     *
     * @param {Object} c The criterion.
     * @returns {Array} Option contexts.
     */
    function objectiveOptions(c) {
        return objectives.map(function(o) {
            return {
                id: o.id,
                label: (o.code ? o.code + ' ' : '') + o.title,
                selected: parseInt(c.objectiveid, 10) === o.id
            };
        });
    }

    /**
     * Template context for one criterion card.
     *
     * @param {Object} c The criterion.
     * @param {number} idx Its position in the list.
     * @returns {Object} Context.
     */
    function cardContext(c, idx) {
        return {
            idx: idx,
            number: idx + 1,
            name: c.name || '',
            description: c.description || '',
            maxscore: c.max_score || 5,
            hasobjectives: objectives.length > 0,
            objectives: objectiveOptions(c),
            str: strs
        };
    }

    /**
     * Move the criterion at one index to another and repaint.
     *
     * @param {number} from Source index.
     * @param {number} to Destination index.
     */
    function moveCriterion(from, to) {
        var moved = criteria.splice(from, 1)[0];
        criteria.splice(to, 0, moved);
        renderAll();
    }

    /**
     * Swap two adjacent criteria and repaint.
     *
     * @param {number} a First index.
     * @param {number} b Second index.
     */
    function swapCriteria(a, b) {
        var tmp = criteria[a];
        criteria[a] = criteria[b];
        criteria[b] = tmp;
        renderAll();
    }

    /**
     * Wire one rendered card back to its entry in the criteria array.
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
        var c = criteria[idx];
        if (!c) {
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
                moveCriterion(fromIdx, toIdx);
            }
        });

        card.querySelector('.aica-rb-up').addEventListener('click', function() {
            if (idx > 0) {
                swapCriteria(idx, idx - 1);
            }
        });
        card.querySelector('.aica-rb-down').addEventListener('click', function() {
            if (idx < criteria.length - 1) {
                swapCriteria(idx, idx + 1);
            }
        });
        card.querySelector('.aica-rb-delete').addEventListener('click', function() {
            if (window.confirm(strs.confirmdelete)) {
                criteria.splice(idx, 1);
                renderAll();
            }
        });

        var nameInp = card.querySelector('.aica-rb-name-input');
        nameInp.addEventListener('input', function() {
            c.name = nameInp.value;
        });

        var scoreInp = card.querySelector('.aica-rb-score-input');
        scoreInp.addEventListener('input', function() {
            c.max_score = Math.max(1, parseInt(scoreInp.value, 10) || 5);
        });

        var descInp = card.querySelector('.aica-rb-desc-input');
        descInp.addEventListener('input', function() {
            c.description = descInp.value;
        });

        var outSel = card.querySelector('.aica-rb-objective-select');
        if (outSel) {
            outSel.addEventListener('change', function() {
                c.objectiveid = parseInt(outSel.value, 10) || 0;
            });
        }
    }

    /**
     * Repaint the whole criterion list.
     *
     * @returns {Promise} Resolves once the cards are in the page and bound.
     */
    function renderAll() {
        var token = ++renderToken;
        return Templates.render('local_ai_course_assistant/rubric_admin_criteria', {
            criteria: criteria.map(cardContext)
        }).then(function(html, js) {
            if (token !== renderToken) {
                return null;
            }
            Templates.replaceNodeContents(container, html, js);
            var cards = container.querySelectorAll('.aica-rb-card');
            Array.prototype.forEach.call(cards, function(card) {
                bindCard(card, token);
            });
            return null;
        }).catch(Notification.exception);
    }

    /**
     * Open the read-only preview dialog for the rubric currently in the editor.
     */
    function openPreview() {
        var total = 0;
        var context = {
            title: strs.previewtitle,
            typenote: strs.previewtype,
            closelabel: strs.previewclose,
            criteria: criteria.map(function(c, idx) {
                var max = c.max_score || 5;
                var dots = [];
                var s;
                total += max;
                for (s = 1; s <= max; s++) {
                    dots.push({n: s});
                }
                return {
                    number: idx + 1,
                    name: c.name || strs.previewunnamed,
                    hasdescription: !!c.description,
                    description: c.description || '',
                    scorelabel: strs.previewscore,
                    maxscore: max,
                    dots: dots
                };
            })
        };
        // Moodle's string cache hands back the raw placeholder, so the
        // substitution has to happen here rather than in get_string().
        context.total = String(strs.previewtotal).replace('{$a}', total);

        Templates.render('local_ai_course_assistant/rubric_admin_preview', context)
            .then(function(html, js) {
                var holder = document.createElement('div');
                document.body.appendChild(holder);
                Templates.replaceNodeContents(holder, html, js);

                var close = function() {
                    if (holder.parentNode) {
                        holder.parentNode.removeChild(holder);
                    }
                };
                var overlay = holder.querySelector('.sola-rb-preview-overlay');
                if (overlay) {
                    overlay.addEventListener('click', function(e) {
                        if (e.target === overlay) {
                            close();
                        }
                    });
                }
                var closeBtn = holder.querySelector('.sola-rb-preview-close');
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
         * argument: a rubric plus its translated strings runs well past the
         * 1 KB that Moodle warns about for js_call_amd arguments.
         *
         * @returns {void}
         */
        init: function() {
            var root = document.querySelector('.aica-rubric-admin');
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
            criteria = config.criteria || [];
            strs = config.strings || {};
            objectives = config.objectives || [];

            container = document.getElementById('aica-criteria-container');
            if (!container) {
                return;
            }

            var addBtn = document.getElementById('aica-add-criterion-btn');
            if (addBtn) {
                addBtn.addEventListener('click', function() {
                    criteria.push({name: '', description: '', max_score: 5});
                    renderAll().then(function() {
                        var cards = container.querySelectorAll('.aica-rb-card');
                        if (cards.length) {
                            cards[cards.length - 1].scrollIntoView({behavior: 'smooth', block: 'center'});
                        }
                        return null;
                    }).catch(Notification.exception);
                });
            }

            var form = document.getElementById('aica-rubric-form');
            if (form) {
                form.addEventListener('submit', function() {
                    document.getElementById('aica-criteria-json').value = JSON.stringify(criteria);
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

            // Both selects used to carry an inline handler attribute.
            var scopeSelect = document.getElementById('aica-scope-select');
            if (scopeSelect) {
                scopeSelect.addEventListener('change', function() {
                    scopeSelect.form.submit();
                });
            }
            var sampleSelect = document.getElementById('aica-rb-sample');
            if (sampleSelect) {
                sampleSelect.addEventListener('change', function() {
                    if (sampleSelect.value) {
                        window.location.assign(sampleSelect.value);
                    }
                });
            }

            renderAll();
        }
    };
});
