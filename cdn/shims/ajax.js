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
