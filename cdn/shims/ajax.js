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
 * Browser shim for core/ajax used by the standalone bundle.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Shim for Moodle's core/ajax module.
 *
 * Replaces Ajax.call() with direct fetch() to Moodle's web service endpoint.
 * The sesskey is read from the widget's data-sesskey attribute.
 */

function getSesskey() {
    const el = document.getElementById('local-ai-course-assistant');
    return el ? el.dataset.sesskey : '';
}

function call(requests) {
    const sesskey = getSesskey();
    const serviceUrl = '/lib/ajax/service.php?sesskey=' + encodeURIComponent(sesskey);

    const body = requests.map(function(req, index) {
        return {
            index: index,
            methodname: req.methodname,
            args: req.args || {},
        };
    });

    const promise = fetch(serviceUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(body),
    })
    .then(function(response) {
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        return response.json();
    });

    // Return an array of Promises, one per request (matching core/ajax interface).
    return requests.map(function(_req, index) {
        return promise.then(function(results) {
            const result = results[index];
            if (result.error) {
                throw result.exception || new Error(result.errorcode || 'Unknown error');
            }
            return result.data;
        });
    });
}

export default {call: call};
export {call};
