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
 * Browser shim for core/str string lookups used by the standalone bundle.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Shim for Moodle's core/str module.
 *
 * Reads pre-loaded i18n strings from window.SOLA_I18N (injected by PHP template).
 * Returns Promises to match Moodle's async Str.get_string() / Str.get_strings() API.
 */

const strings = window.SOLA_I18N || {};

function substituteParams(str, param) {
    if (param === undefined || param === null) {
        return str;
    }
    if (typeof param === 'object' && !Array.isArray(param)) {
        var result = str;
        for (var k in param) {
            if (param.hasOwnProperty(k)) {
                result = result.replace(new RegExp('\\{\\$a->' + k + '\\}', 'g'), param[k]);
            }
        }
        return result;
    }
    return str.replace('{$a}', String(param));
}

function get_string(key, _component, param) {
    var s = strings[key] !== undefined ? strings[key] : key;
    return Promise.resolve(substituteParams(s, param));
}

function get_strings(requests) {
    var results = requests.map(function(req) {
        var s = strings[req.key] !== undefined ? strings[req.key] : req.key;
        return substituteParams(s, req.param);
    });
    return Promise.resolve(results);
}

export default {get_string: get_string, get_strings: get_strings};
export {get_string, get_strings};
