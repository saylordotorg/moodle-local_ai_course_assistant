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
 * Add, remove and "Change key" for keyed-rows admin tables. Never reads or writes a key: a new key is only
 * ever typed by the admin into an empty field.
 *
 * @module     local_ai_course_assistant/keyed_rows
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    var counter = 0;

    /**
     * @param {String} id The wrapper id prefix.
     */
    var init = function(id) {
        var wrap = document.getElementById(id + '-wrap');
        if (!wrap) {
            return;
        }
        var tbody = wrap.querySelector('tbody');
        var tpl = wrap.querySelector('template.sola-kr-blank');
        wrap.addEventListener('click', function(e) {
            var t = e.target;
            if (t.classList.contains('sola-kr-remove')) {
                // A removed row posts nothing, so it is dropped on save.
                t.closest('tr').remove();
            } else if (t.classList.contains('sola-kr-change')) {
                var cell = t.closest('td');
                var input = cell.querySelector('.sola-kr-newkey');
                input.disabled = false;
                input.classList.remove('d-none');
                cell.querySelector('.sola-kr-saved').classList.add('d-none');
                t.classList.add('d-none');
                input.focus();
            } else if (t.classList.contains('sola-kr-add')) {
                counter++;
                var html = tpl.innerHTML.split('__IDX__').join('n' + counter + String(Date.now()));
                tbody.insertAdjacentHTML('beforeend', html);
            }
        });
    };

    return {init: init};
});
