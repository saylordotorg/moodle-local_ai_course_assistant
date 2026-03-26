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
