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
 * Usability-testing task admin editor.
 *
 * Replaces the inline script usertesting_admin.php used to echo
 * (CONTRIB-10574 #273 and #278). The behaviour is unchanged: a drag-to-reorder
 * list of task cards whose state is serialised into the hidden tasks_json
 * field the page posts, plus a preview dialog.
 *
 * Three things moved with it:
 *
 * 1. The cards and the preview dialog render through core/templates instead of
 *    being assembled node by node, so the markup lives in Mustache files and
 *    the escaping is the template engine's job.
 * 2. Every style attribute the old script set in JavaScript is now a class in
 *    styles.css.
 * 3. The tasks and the translated strings arrive in the root element's
 *    data-config attribute rather than as echoed JSON literals or js_call_amd
 *    arguments, which carry a 1 KB advisory limit a task set exceeds.
 *
 * @module     local_ai_course_assistant/usertesting_admin
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/templates', 'core/notification'], function(Templates, Notification) {

    /** @type {Array} The task set being edited. This array is the source of truth. */
    var tasks = [];
    /** @type {Object} Pre-resolved UI strings from PHP. */
    var strs = {};

    var container = null;
    /**
     * @type {number} Bumped on every render so a slow render that has been
     * superseded discards its own result instead of painting stale cards.
     */
    var renderToken = 0;

    /**
     * The human-readable name of a task type.
     *
     * One lookup used by both the card badge and the preview, so the two never
     * disagree about what a stored type is called.
     *
     * @param {string} type The stored type slug.
     * @returns {string} Its label.
     */
    function taskTypeLabel(type) {
        if (type === 'action_then_rate') {
            return strs.typeactionrate;
        }
        if (type === 'multiple_choice') {
            return strs.typemultiplechoice;
        }
        return strs.typefreeresponse;
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
     * Template context for one task card.
     *
     * @param {Object} t The task.
     * @param {number} idx Its position in the list.
     * @returns {Object} Context.
     */
    function cardContext(t, idx) {
        var types = [
            {value: 'action_then_rate', label: strs.typeactionrate},
            {value: 'free_response', label: strs.typefreeresponse},
            {value: 'multiple_choice', label: strs.typemultiplechoice}
        ];
        var isactionrate = t.type === 'action_then_rate';
        // Strict, not a fallback: the old script showed this field only for the
        // exact 'free_response' slug, and a stored type can be any slug that
        // survives PARAM_ALPHANUMEXT.
        var isfreeresponse = t.type === 'free_response';
        return {
            idx: idx,
            number: idx + 1,
            typelabel: taskTypeLabel(t.type),
            instruction: t.instruction || '',
            typeoptions: types.map(function(ty) {
                return {value: ty.value, label: ty.label, selected: t.type === ty.value};
            }),
            isactionrate: isactionrate,
            ratinglabel: t.rating_label || '',
            min: t.min || 1,
            max: t.max || 5,
            minlabel: t.min_label || '',
            maxlabel: t.max_label || '',
            ismultiplechoice: t.type === 'multiple_choice',
            options: (t.options || []).map(function(opt, oi) {
                return {oi: oi, value: opt, arialabel: optionLabel(oi + 1)};
            }),
            // The same stored field under two names: a follow-up question on an
            // action-and-rate task, an additional prompt on a free-response one.
            hasfollowupfield: isactionrate || isfreeresponse,
            followup: t.follow_up || '',
            followuplabel: isactionrate ? strs.followup : strs.addprompt,
            followuparia: isactionrate ? strs.followuparia : strs.addpromptaria,
            str: strs
        };
    }

    /**
     * Move the task at one index to another and repaint.
     *
     * @param {number} from Source index.
     * @param {number} to Destination index.
     */
    function moveTask(from, to) {
        var moved = tasks.splice(from, 1)[0];
        tasks.splice(to, 0, moved);
        renderAll();
    }

    /**
     * Swap two adjacent tasks and repaint.
     *
     * @param {number} a First index.
     * @param {number} b Second index.
     */
    function swapTasks(a, b) {
        var tmp = tasks[a];
        tasks[a] = tasks[b];
        tasks[b] = tmp;
        renderAll();
    }

    /**
     * Wire the multiple-choice option rows on one card.
     *
     * @param {HTMLElement} card The card element.
     * @param {Object} t Its task.
     */
    function bindOptions(card, t) {
        var inputs = card.querySelectorAll('.aica-ut-opt-input');
        Array.prototype.forEach.call(inputs, function(inp) {
            var oi = parseInt(inp.getAttribute('data-oi'), 10);
            inp.addEventListener('input', function() {
                t.options[oi] = inp.value;
            });
        });

        var removes = card.querySelectorAll('.aica-ut-opt-remove');
        Array.prototype.forEach.call(removes, function(btn) {
            var oi = parseInt(btn.getAttribute('data-oi'), 10);
            btn.addEventListener('click', function() {
                t.options.splice(oi, 1);
                renderAll();
            });
        });

        var addOpt = card.querySelector('.aica-ut-add-opt');
        if (addOpt) {
            addOpt.addEventListener('click', function() {
                if (!t.options) {
                    t.options = [];
                }
                t.options.push(strs.newoption);
                renderAll();
            });
        }
    }

    /**
     * Wire the rating label and the four rating fields on one card.
     *
     * @param {HTMLElement} card The card element.
     * @param {Object} t Its task.
     */
    function bindRating(card, t) {
        var rlInp = card.querySelector('.aica-ut-ratinglabel-input');
        if (rlInp) {
            rlInp.addEventListener('input', function() {
                t.rating_label = rlInp.value;
            });
        }
        var minInp = card.querySelector('.aica-ut-min-input');
        if (minInp) {
            minInp.addEventListener('input', function() {
                t.min = parseInt(minInp.value, 10) || 1;
            });
        }
        var maxInp = card.querySelector('.aica-ut-max-input');
        if (maxInp) {
            maxInp.addEventListener('input', function() {
                t.max = parseInt(maxInp.value, 10) || 5;
            });
        }
        var minLblInp = card.querySelector('.aica-ut-minlabel-input');
        if (minLblInp) {
            minLblInp.addEventListener('input', function() {
                t.min_label = minLblInp.value;
            });
        }
        var maxLblInp = card.querySelector('.aica-ut-maxlabel-input');
        if (maxLblInp) {
            maxLblInp.addEventListener('input', function() {
                t.max_label = maxLblInp.value;
            });
        }
    }

    /**
     * Wire one rendered card back to its entry in the tasks array.
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
        var t = tasks[idx];
        if (!t) {
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
                moveTask(fromIdx, toIdx);
            }
        });

        card.querySelector('.aica-ut-up').addEventListener('click', function() {
            if (idx > 0) {
                swapTasks(idx, idx - 1);
            }
        });
        card.querySelector('.aica-ut-down').addEventListener('click', function() {
            if (idx < tasks.length - 1) {
                swapTasks(idx, idx + 1);
            }
        });
        card.querySelector('.aica-ut-delete').addEventListener('click', function() {
            if (window.confirm(strs.confirmdelete)) {
                tasks.splice(idx, 1);
                renderAll();
            }
        });

        var typeSelect = card.querySelector('.aica-ut-type-select');
        typeSelect.addEventListener('change', function() {
            t.type = typeSelect.value;
            if (t.type === 'action_then_rate') {
                t.rating_label = t.rating_label || strs.ratinglabeldefault;
                t.min = t.min || 1;
                t.max = t.max || 5;
            }
            if (t.type === 'multiple_choice' && !t.options) {
                t.options = [optionLabel(1), optionLabel(2)];
            }
            renderAll();
        });

        var instrInput = card.querySelector('.aica-ut-instruction-input');
        instrInput.addEventListener('input', function() {
            t.instruction = instrInput.value;
        });

        var followup = card.querySelector('.aica-ut-followup-input');
        if (followup) {
            followup.addEventListener('input', function() {
                t.follow_up = followup.value;
            });
        }

        if (t.type === 'action_then_rate') {
            bindRating(card, t);
        }
        if (t.type === 'multiple_choice') {
            bindOptions(card, t);
        }
    }

    /**
     * Repaint the whole task list.
     *
     * @returns {Promise} Resolves once the cards are in the page and bound.
     */
    function renderAll() {
        var token = ++renderToken;
        return Templates.render('local_ai_course_assistant/usertesting_admin_tasks', {
            tasks: tasks.map(cardContext)
        }).then(function(html, js) {
            if (token !== renderToken) {
                return null;
            }
            Templates.replaceNodeContents(container, html, js);
            var cards = container.querySelectorAll('.aica-ut-card');
            Array.prototype.forEach.call(cards, function(card) {
                bindCard(card, token);
            });
            return null;
        }).catch(Notification.exception);
    }

    /**
     * Open the read-only preview dialog for the task set currently in the editor.
     */
    function openPreview() {
        var titleInput = document.getElementById('aica-ut-title');
        var context = {
            title: (titleInput && titleInput.value) || strs.previewtitle,
            closelabel: strs.previewclose,
            tasks: tasks.map(function(t, idx) {
                return {
                    label: String(strs.previewtasklabel)
                        .replace('{n}', idx + 1)
                        .replace('{t}', taskTypeLabel(t.type)),
                    instruction: t.instruction || strs.previewnoinstruction,
                    isactionrate: t.type === 'action_then_rate',
                    raterange: String(strs.previewraterange)
                        .replace('{l}', t.rating_label || strs.previewratefallback)
                        .replace('{min}', t.min || 1)
                        .replace('{max}', t.max || 5),
                    hasfollowup: !!t.follow_up,
                    followup: String(strs.previewfollowup).replace('{t}', t.follow_up || ''),
                    ismultiplechoice: t.type === 'multiple_choice',
                    options: (t.options || []).map(function(opt) {
                        return {label: opt};
                    })
                };
            })
        };

        Templates.render('local_ai_course_assistant/usertesting_admin_preview', context)
            .then(function(html, js) {
                var holder = document.createElement('div');
                document.body.appendChild(holder);
                Templates.replaceNodeContents(holder, html, js);

                var close = function() {
                    if (holder.parentNode) {
                        holder.parentNode.removeChild(holder);
                    }
                };
                var overlay = holder.querySelector('.sola-ut-preview-overlay');
                if (overlay) {
                    overlay.addEventListener('click', function(e) {
                        if (e.target === overlay) {
                            close();
                        }
                    });
                }
                var closeBtn = holder.querySelector('.sola-ut-preview-close');
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
         * argument: a task set plus its translated strings runs well past the
         * 1 KB that Moodle warns about for js_call_amd arguments.
         *
         * @returns {void}
         */
        init: function() {
            var root = document.querySelector('.aica-ut-admin');
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
            tasks = config.tasks || [];
            strs = config.strings || {};

            container = document.getElementById('aica-tasks-container');
            if (!container) {
                return;
            }

            var addBtn = document.getElementById('aica-add-task-btn');
            if (addBtn) {
                addBtn.addEventListener('click', function() {
                    tasks.push({
                        type: 'action_then_rate',
                        instruction: '',
                        rating_label: strs.ratinglabeldefault,
                        min: 1,
                        max: 5,
                        min_label: '',
                        max_label: '',
                        follow_up: ''
                    });
                    renderAll().then(function() {
                        var cards = container.querySelectorAll('.aica-ut-card');
                        if (cards.length) {
                            cards[cards.length - 1].scrollIntoView({behavior: 'smooth', block: 'center'});
                        }
                        return null;
                    }).catch(Notification.exception);
                });
            }

            var form = document.getElementById('aica-ut-form');
            if (form) {
                form.addEventListener('submit', function() {
                    document.getElementById('aica-tasks-json').value = JSON.stringify(tasks);
                });
            }

            var resetBtn = document.getElementById('aica-ut-reset-btn');
            if (resetBtn) {
                resetBtn.addEventListener('click', function() {
                    if (window.confirm(strs.confirmreset)) {
                        document.getElementById('aica-ut-reset-form').submit();
                    }
                });
            }

            var previewBtn = document.getElementById('aica-ut-preview-btn');
            if (previewBtn) {
                previewBtn.addEventListener('click', openPreview);
            }

            // The scope select used to carry an inline onchange attribute.
            var scopeSelect = document.getElementById('aica-ut-scope');
            if (scopeSelect) {
                scopeSelect.addEventListener('change', function() {
                    scopeSelect.form.submit();
                });
            }

            renderAll();
        }
    };
});
