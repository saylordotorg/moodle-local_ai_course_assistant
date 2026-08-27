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
 * Conversation-starter admin editor.
 *
 * Drag-to-reorder card list with per-starter editing, serialising the whole set
 * back into the hidden starters_json field the page posts. Moved out of a
 * 229-line inline <script> in starter_settings.php for CONTRIB-10574 #201; the
 * logic is unchanged, with the four PHP-injected JSON blobs now arriving as an
 * init() argument instead of being echoed into the page.
 *
 * @module     local_ai_course_assistant/starter_admin
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    return {
        /**
         * Build the editor.
         *
         * @param {Object} config Injected data.
         * @param {Object} config.icons Icon key to SVG markup.
         * @param {Object} config.iconlabels Icon key to human label.
         * @param {Object} config.strings Translated UI strings.
         * @param {Array} config.starters The starter set to edit.
         * @returns {void}
         */
        init: function(config) {

            var ICONS = config.icons;
            var ICON_LABELS = config.iconlabels;
            var STR = config.strings;
            var starters = config.starters;
            var list = document.getElementById('aica-starters-list');
            var nextOrder = starters.length + 1;

            function slug(name) {
                return name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').substring(0, 30) || 'custom-' + Date.now();
            }

            function renderCard(s, idx) {
                var card = document.createElement('div');
                card.className = 'aica-starter-card';
                card.draggable = true;
                card.dataset.idx = idx;

                var typeBadge = s.type !== 'prompt' ? '<span class="aica-starter-type-badge type-' + s.type + '">' + s.type + '</span>' : '';
                var condBadge = s.conditional ? '<span class="aica-starter-type-badge">' + s.conditional + '</span>' : '';

                card.innerHTML =
                    '<div class="aica-starter-card-header">' +
                        '<span class="aica-drag-handle" title="' + escAttr(STR.drag_handle) + '">&#x2630;</span>' +
                        '<span class="aica-starter-icon-preview">' + (ICONS[s.icon] || ICONS.chat) + '</span>' +
                        '<span class="aica-starter-name">' + escHtml(s.name) + '</span>' +
                        typeBadge + condBadge +
                        '<label style="margin:0;display:flex;align-items:center;gap:4px;" onclick="event.stopPropagation()">' +
                            '<input type="checkbox" class="aica-starter-toggle" ' + (s.enabled ? 'checked' : '') + '>' +
                            '<span style="font-size:12px;color:#6c757d;">' + escHtml(STR.on) + '</span>' +
                        '</label>' +
                        '<span class="aica-starter-expand-arrow">&#x25BC;</span>' +
                    '</div>' +
                    '<div class="aica-starter-card-body">' +
                        '<div class="aica-field">' +
                            '<label>' + escHtml(STR.name) + '</label>' +
                            '<input type="text" class="aica-f-name" aria-label="' + escAttr(STR.name_aria) + '" value="' +
                                escAttr(s.name) + '" placeholder="' + escAttr(STR.name_placeholder) + '">' +
                        '</div>' +
                        '<div class="aica-field">' +
                            '<label>' + escHtml(STR.description) + '</label>' +
                            '<input type="text" class="aica-f-desc" aria-label="' + escAttr(STR.desc_aria) + '" value="' +
                                escAttr(s.description || '') + '" placeholder="' + escAttr(STR.desc_placeholder) + '">' +
                            '<div class="aica-help">' + escHtml(STR.desc_help) + '</div>' +
                        '</div>' +
                        (s.type === 'prompt' ?
                        '<div class="aica-field">' +
                            '<label>' + escHtml(STR.prompt) + '</label>' +
                            '<textarea class="aica-f-prompt" aria-label="' + escAttr(STR.prompt_aria) + '" placeholder="' +
                                escAttr(STR.prompt_placeholder) + '">' + escHtml(s.prompt || '') + '</textarea>' +
                            '<div class="aica-help">' + STR.prompt_help + '</div>' +
                        '</div>' : '') +
                        '<div class="aica-field">' +
                            '<label>' + escHtml(STR.icon) + '</label>' +
                            '<div class="aica-icon-picker">' +
                                Object.keys(ICONS).map(function(k) {
                                    var label = ICON_LABELS[k] || k;
                                    return '<span class="aica-icon-option' + (k === s.icon ? ' selected' : '') +
                                        '" data-icon="' + k + '" title="' + escAttr(label) + '">' + ICONS[k] + '</span>';
                                }).join('') +
                            '</div>' +
                        '</div>' +
                        (s.type === 'prompt' && !s.builtin ?
                        '<div class="aica-field">' +
                            '<label>' + escHtml(STR.conditional) + '</label>' +
                            '<select class="aica-f-conditional">' +
                                '<option value=""' + (!s.conditional ? ' selected' : '') + '>' + escHtml(STR.cond_always) + '</option>' +
                                '<option value="tts"' + (s.conditional === 'tts' ? ' selected' : '') + '>' +
                                    escHtml(STR.cond_tts) + '</option>' +
                                '<option value="realtime"' + (s.conditional === 'realtime' ? ' selected' : '') + '>' +
                                    escHtml(STR.cond_realtime) + '</option>' +
                            '</select>' +
                        '</div>' : '') +
                        '<div class="aica-starter-actions">' +
                            (!s.builtin
                                ? '<button type="button" class="aica-btn-delete">' + escHtml(STR.delete) + '</button>'
                                : '<span style="font-size:12px;color:#6c757d;">' + escHtml(STR.builtin_note) + '</span>') +
                        '</div>' +
                    '</div>';

                // Expand/collapse.
                card.querySelector('.aica-starter-card-header').addEventListener('click', function(e) {
                    if (e.target.closest('.aica-starter-toggle') || e.target.closest('label')) return;
                    card.classList.toggle('expanded');
                });

                // Toggle.
                card.querySelector('.aica-starter-toggle').addEventListener('change', function() {
                    starters[card.dataset.idx].enabled = this.checked;
                });

                // Name.
                var nameInput = card.querySelector('.aica-f-name');
                if (nameInput) {
                    nameInput.addEventListener('input', function() {
                        starters[card.dataset.idx].name = this.value;
                        card.querySelector('.aica-starter-name').textContent = this.value;
                    });
                }

                // Description.
                var descInput = card.querySelector('.aica-f-desc');
                if (descInput) {
                    descInput.addEventListener('input', function() {
                        starters[card.dataset.idx].description = this.value;
                    });
                }

                // Prompt.
                var promptInput = card.querySelector('.aica-f-prompt');
                if (promptInput) {
                    promptInput.addEventListener('input', function() {
                        starters[card.dataset.idx].prompt = this.value;
                    });
                }

                // Conditional.
                var condSelect = card.querySelector('.aica-f-conditional');
                if (condSelect) {
                    condSelect.addEventListener('change', function() {
                        starters[card.dataset.idx].conditional = this.value;
                    });
                }

                // Icon picker.
                card.querySelectorAll('.aica-icon-option').forEach(function(opt) {
                    opt.addEventListener('click', function() {
                        card.querySelectorAll('.aica-icon-option').forEach(function(o) { o.classList.remove('selected'); });
                        opt.classList.add('selected');
                        starters[card.dataset.idx].icon = opt.dataset.icon;
                        card.querySelector('.aica-starter-icon-preview').innerHTML = ICONS[opt.dataset.icon];
                    });
                });

                // Delete.
                var delBtn = card.querySelector('.aica-btn-delete');
                if (delBtn) {
                    delBtn.addEventListener('click', function() {
                        if (confirm(STR.confirm_delete.replace('{$a}', starters[card.dataset.idx].name))) {
                            starters.splice(card.dataset.idx, 1);
                            renderAll();
                        }
                    });
                }

                // Drag events.
                card.addEventListener('dragstart', function(e) {
                    card.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', card.dataset.idx);
                });
                card.addEventListener('dragend', function() {
                    card.classList.remove('dragging');
                });
                card.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                });
                card.addEventListener('drop', function(e) {
                    e.preventDefault();
                    var fromIdx = parseInt(e.dataTransfer.getData('text/plain'));
                    var toIdx = parseInt(card.dataset.idx);
                    if (fromIdx !== toIdx) {
                        var item = starters.splice(fromIdx, 1)[0];
                        starters.splice(toIdx, 0, item);
                        renderAll();
                    }
                });

                return card;
            }

            function renderAll() {
                list.innerHTML = '';
                starters.forEach(function(s, i) {
                    s.sort_order = i + 1;
                    list.appendChild(renderCard(s, i));
                });
            }

            function escHtml(str) { var d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
            function escAttr(str) { return str.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

            // Add new starter.
            document.getElementById('aica-add-starter').addEventListener('click', function() {
                var name = STR.new_name;
                starters.push({
                    key: slug(name + '-' + Date.now()),
                    name: name,
                    description: '',
                    prompt: '',
                    icon: 'lightbulb',
                    type: 'prompt',
                    enabled: true,
                    sort_order: starters.length + 1,
                    builtin: false,
                    conditional: ''
                });
                renderAll();
                // Expand the new card.
                var cards = list.querySelectorAll('.aica-starter-card');
                var last = cards[cards.length - 1];
                if (last) {
                    last.classList.add('expanded');
                    last.querySelector('.aica-f-name').focus();
                    last.scrollIntoView({behavior: 'smooth', block: 'center'});
                }
            });

            // Save: serialize starters to all hidden fields (top + bottom forms).
            document.querySelectorAll('.aica-save-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    // Generate keys for new custom starters based on name.
                    starters.forEach(function(s) {
                        if (!s.builtin && s.key.indexOf('custom-') === 0) {
                            s.key = slug(s.name);
                        }
                    });
                    var json = JSON.stringify(starters);
                    document.querySelectorAll('.aica-starters-json').forEach(function(el) {
                        el.value = json;
                    });
                });
            });

            renderAll();
        }
    };
});
