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
 * Learning path map panel (v5.9.0).
 *
 * Lazy-renders the learner's program path into the in-drawer panel: course
 * nodes (done / current / upcoming, with ordering and "course X of Y"), each a
 * keyboard-operable disclosure button expanding to its objectives and mastery.
 * Data comes from the get_learning_path web service. Status and mastery use a
 * text label plus a shape glyph (never colour alone) for WCAG 1.4.1.
 *
 * @module     local_ai_course_assistant/path_map
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/str', 'core/notification', 'local_ai_course_assistant/repository'],
function(Str, Notification, Repository) {

    var STR = {};
    var stringsReady = null;

    var STRING_KEYS = [
        'pathpanel_empty', 'path_position',
        'path_status_done', 'path_status_current', 'path_status_upcoming',
        'path_mastery_mastered', 'path_mastery_in_progress',
        'path_mastery_not_started', 'path_mastery_demonstrated_elsewhere',
    ];

    /** Shape glyph per status — a non-colour cue alongside the text label. */
    var STATUS_GLYPH = {done: '✔', current: '◉', upcoming: '○'};

    /**
     * Load and cache the panel's lang strings once.
     *
     * @returns {Promise}
     */
    var loadStrings = function() {
        if (stringsReady) {
            return stringsReady;
        }
        var requests = STRING_KEYS.map(function(key) {
            return {key: key, component: 'local_ai_course_assistant'};
        });
        stringsReady = Str.get_strings(requests).then(function(values) {
            STRING_KEYS.forEach(function(key, i) {
                STR[key] = values[i];
            });
            return STR;
        });
        return stringsReady;
    };

    /**
     * Position label "Course {index} of {total}" with the raw {$a->...} tokens
     * substituted in JS (Moodle's string cache returns them raw).
     *
     * @param {number} index
     * @param {number} total
     * @returns {string}
     */
    var positionLabel = function(index, total) {
        return (STR.path_position || 'Course {$a->index} of {$a->total}')
            .replace('{$a->index}', index).replace('{$a->total}', total);
    };

    /**
     * Build the objectives disclosure list for a course node.
     *
     * @param {object[]} objectives
     * @returns {HTMLElement}
     */
    var buildObjectives = function(objectives) {
        var ul = document.createElement('ul');
        ul.className = 'local-ai-course-assistant__path-objectives';
        objectives.forEach(function(obj) {
            var li = document.createElement('li');
            li.className = 'local-ai-course-assistant__path-objective is-' + obj.mastery;
            var title = document.createElement('span');
            title.className = 'local-ai-course-assistant__path-objective-title';
            title.textContent = obj.title;
            var state = document.createElement('span');
            state.className = 'local-ai-course-assistant__path-objective-state';
            state.textContent = STR['path_mastery_' + obj.mastery] || obj.mastery;
            li.appendChild(title);
            li.appendChild(state);
            ul.appendChild(li);
        });
        return ul;
    };

    /**
     * Build one course node as a disclosure (button + collapsible objectives).
     *
     * @param {object} course
     * @param {number} idx Zero-based index, for unique ids.
     * @returns {HTMLElement}
     */
    var buildNode = function(course, idx) {
        var node = document.createElement('div');
        node.className = 'local-ai-course-assistant__path-node is-' + course.status
            + (course.is_current ? ' is-current' : '');

        var hasObjectives = course.objectives && course.objectives.length > 0;
        var head = document.createElement(hasObjectives ? 'button' : 'div');
        head.className = 'local-ai-course-assistant__path-node-head';
        if (hasObjectives) {
            head.type = 'button';
            head.setAttribute('aria-expanded', 'false');
        }

        var glyph = document.createElement('span');
        glyph.className = 'local-ai-course-assistant__path-node-glyph';
        glyph.setAttribute('aria-hidden', 'true');
        glyph.textContent = STATUS_GLYPH[course.status] || '○';

        var label = document.createElement('span');
        label.className = 'local-ai-course-assistant__path-node-label';
        var name = document.createElement('span');
        name.className = 'local-ai-course-assistant__path-node-name';
        name.textContent = course.name;
        var meta = document.createElement('span');
        meta.className = 'local-ai-course-assistant__path-node-meta';
        meta.textContent = (STR['path_status_' + course.status] || course.status)
            + ' · ' + positionLabel(course.position, course._total);
        label.appendChild(name);
        label.appendChild(meta);

        head.appendChild(glyph);
        head.appendChild(label);
        node.appendChild(head);

        if (hasObjectives) {
            var body = buildObjectives(course.objectives);
            body.id = 'aica-path-obj-' + idx;
            body.hidden = true;
            head.setAttribute('aria-controls', body.id);
            head.addEventListener('click', function() {
                var open = head.getAttribute('aria-expanded') === 'true';
                head.setAttribute('aria-expanded', open ? 'false' : 'true');
                body.hidden = open;
            });
            node.appendChild(body);
        }
        return node;
    };

    /**
     * Render the path model into the panel body.
     *
     * @param {object} data get_learning_path response.
     * @param {HTMLElement} body Panel body element.
     */
    var render = function(data, body) {
        body.innerHTML = '';
        if (!data || !data.has_path || !data.courses || data.courses.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'local-ai-course-assistant__path-empty';
            empty.textContent = STR.pathpanel_empty || '';
            body.appendChild(empty);
            return;
        }
        data.courses.forEach(function(course, idx) {
            course._total = data.total;
            body.appendChild(buildNode(course, idx));
        });
    };

    /**
     * Resolve the panel element within a widget root.
     *
     * @param {HTMLElement} root
     * @returns {HTMLElement|null}
     */
    var panelOf = function(root) {
        return root.querySelector('.local-ai-course-assistant__path-panel');
    };

    /**
     * Open the panel and (re)load the path.
     *
     * @param {HTMLElement} root Widget root element.
     * @param {number} courseid
     */
    var open = function(root, courseid) {
        var panel = panelOf(root);
        if (!panel) {
            return;
        }
        panel.hidden = false;
        var body = panel.querySelector('.local-ai-course-assistant__path-panel-body');
        loadStrings().then(function() {
            return Repository.getLearningPath(courseid);
        }).then(function(data) {
            render(data, body);
            return data;
        }).catch(Notification.exception);
    };

    /**
     * Close the panel.
     *
     * @param {HTMLElement} root
     */
    var close = function(root) {
        var panel = panelOf(root);
        if (panel) {
            panel.hidden = true;
        }
    };

    /**
     * Toggle the panel open/closed.
     *
     * @param {HTMLElement} root
     * @param {number} courseid
     */
    var toggle = function(root, courseid) {
        var panel = panelOf(root);
        if (panel && panel.hidden) {
            open(root, courseid);
        } else {
            close(root);
        }
    };

    return {
        open: open,
        close: close,
        toggle: toggle,
    };
});
