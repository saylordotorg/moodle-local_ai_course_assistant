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
 * Soapbox recording upload orchestration (v6.8.14).
 *
 * Kept separate from the MediaRecorder controller and with its network
 * dependencies injected, so the risky part (get presigned URL, PUT to S3 with
 * retry, finalize) is unit-testable without a browser or a real camera.
 *
 * @module     local_ai_course_assistant/soapbox_uploader
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {

    /**
     * Sleep helper.
     *
     * @param {number} ms
     * @return {Promise}
     */
    var wait = function(ms) {
        return new Promise(function(resolve) {
            setTimeout(resolve, ms);
        });
    };

    /**
     * PUT a blob to a presigned URL, retrying on network / 5xx failures.
     *
     * @param {function} fetchFn fetch implementation
     * @param {string} url presigned PUT URL
     * @param {Blob} blob recording data
     * @param {string} contentType MIME type
     * @param {number} retries max attempts
     * @return {Promise<void>}
     */
    var putWithRetry = function(fetchFn, url, blob, contentType, retries) {
        var attempt = 0;
        var tryOnce = function() {
            attempt++;
            return fetchFn(url, {
                method: 'PUT',
                body: blob,
                headers: {'Content-Type': contentType}
            }).then(function(resp) {
                if (resp.ok) {
                    return;
                }
                // 4xx (other than 429) will not get better on retry.
                if (resp.status < 500 && resp.status !== 429) {
                    throw new Error('Upload rejected (' + resp.status + ')');
                }
                throw new Error('Upload failed (' + resp.status + ')');
            }).catch(function(err) {
                if (attempt >= retries) {
                    throw err;
                }
                return wait(Math.min(8000, 500 * Math.pow(2, attempt))).then(tryOnce);
            });
        };
        return tryOnce();
    };

    return {
        /**
         * Upload a slide deck (PDF) ahead of recording. Returns the object key
         * to pass back to uploadRecording as deckKey.
         *
         * @param {object} opts
         * @param {number} opts.assignid
         * @param {Blob} opts.blob PDF deck data
         * @param {function} [opts.fetchFn]
         * @param {object} [opts.ajax]
         * @param {number} [opts.retries]
         * @return {Promise<string>} the deck object key
         */
        uploadDeck: function(opts) {
            var ajax = opts.ajax || Ajax;
            var fetchFn = opts.fetchFn || window.fetch.bind(window);
            var retries = opts.retries || 3;
            return ajax.call([{
                methodname: 'local_ai_course_assistant_soapbox_get_upload_url',
                args: {assignid: opts.assignid, ext: 'pdf', kind: 'deck'}
            }])[0].then(function(urlinfo) {
                return putWithRetry(fetchFn, urlinfo.uploadurl, opts.blob, 'application/pdf', retries)
                    .then(function() {
                        return urlinfo.objectkey;
                    });
            });
        },

        /**
         * Upload a finished recording end to end.
         *
         * @param {object} opts
         * @param {number} opts.assignid
         * @param {number} opts.topicid 0 for none
         * @param {Blob} opts.blob recording data
         * @param {string} opts.ext file extension (mp4/webm/...)
         * @param {string} opts.contentType MIME type for the PUT
         * @param {number} opts.durationSeconds
         * @param {string} [opts.deckKey] slide deck object key (slides mode)
         * @param {string} [opts.slideTimeline] JSON slide-advance timeline
         * @param {function} [opts.fetchFn] fetch (defaults to window.fetch)
         * @param {object} [opts.ajax] core/ajax (defaults to the module)
         * @param {number} [opts.retries] PUT attempts (default 3)
         * @return {Promise<object>} finalize result (recordingid, viewurl, expires_at)
         */
        uploadRecording: function(opts) {
            var ajax = opts.ajax || Ajax;
            var fetchFn = opts.fetchFn || window.fetch.bind(window);
            var retries = opts.retries || 3;

            return ajax.call([{
                methodname: 'local_ai_course_assistant_soapbox_get_upload_url',
                args: {assignid: opts.assignid, ext: opts.ext}
            }])[0].then(function(urlinfo) {
                return putWithRetry(fetchFn, urlinfo.uploadurl, opts.blob, opts.contentType, retries)
                    .then(function() {
                        return ajax.call([{
                            methodname: 'local_ai_course_assistant_soapbox_finalize_recording',
                            args: {
                                assignid: opts.assignid,
                                objectkey: urlinfo.objectkey,
                                topicid: opts.topicid || 0,
                                durationseconds: opts.durationSeconds || 0,
                                deckkey: opts.deckKey || '',
                                slidetimeline: opts.slideTimeline || ''
                            }
                        }])[0];
                    });
            });
        },

        // Exposed for unit testing.
        _putWithRetry: putWithRetry
    };
});
