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
 * Sample still frames from a finished recording, in the browser (v7.5.1).
 *
 * Body-language feedback needs stills. The obvious way to get them is ffmpeg on
 * the server, and that is not available: ffmpeg is not installed on the Saylor
 * fleet, and Catalyst's production containers are a separate build nobody has
 * confirmed. A server-side sampler would therefore have degraded silently to
 * "no visual criteria, ever" on exactly the sites this feature is for.
 *
 * So the frames are taken here, where the recording already exists as a Blob in
 * memory, using a hidden video element and a canvas. No server binary, no
 * exec(), no second download of a large object.
 *
 * Two things this module refuses to do:
 *
 * It never fails the upload. Every path resolves, to a Blob or to null, and the
 * caller treats null as "no visual feedback this time". A learner losing their
 * attempt because a canvas call threw would be a far worse outcome than losing
 * one section of feedback.
 *
 * It never reads video.duration. For a MediaRecorder WebM blob that is commonly
 * Infinity until a large seek forces the browser to re-index, which is a known
 * Chrome behaviour and would put every sample time at NaN. The true elapsed
 * seconds are passed in by the recorder, which measured them.
 *
 * @module     local_ai_course_assistant/soapbox_frames
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    /** @type {number} Frames per sheet. Three columns by two rows. */
    var FRAME_COUNT = 6;
    /** @type {number} Columns in the contact sheet. */
    var COLS = 3;
    /** @type {number} Width of one cell, in pixels. */
    var CELL_W = 426;
    /** @type {number} Height of one cell, in pixels. */
    var CELL_H = 240;
    /** @type {number} JPEG quality for the sheet. */
    var QUALITY = 0.72;
    /** @type {number} Give up on the whole extraction after this long. */
    var TIMEOUT_MS = 12000;

    /**
     * Timestamps to sample, spread across the middle 80% of the recording.
     *
     * The first and last tenth are skipped deliberately: that is where a learner
     * is reaching for the mouse, settling into frame, or already turning away,
     * and body-language feedback drawn from those moments would be about
     * operating the recorder rather than about presenting.
     *
     * Pure, and exported, so the arithmetic is testable without a browser.
     *
     * @param {number} durationSeconds True elapsed seconds, as measured by the recorder.
     * @param {number} count How many frames.
     * @return {Array<number>} Timestamps in seconds, or [] if the duration is unusable.
     */
    var sampleTimes = function(durationSeconds, count) {
        var d = Number(durationSeconds);
        var n = Number(count);
        if (!isFinite(d) || d <= 0 || !isFinite(n) || n < 1) {
            return [];
        }
        if (n === 1) {
            return [d * 0.5];
        }
        var out = [];
        for (var k = 0; k < n; k++) {
            out.push(d * (0.1 + 0.8 * (k / (n - 1))));
        }
        return out;
    };

    /**
     * Seek a video element and resolve once the frame is actually ready.
     *
     * Resolves on the `seeked` event rather than after a timer: a timer races
     * the decoder and yields duplicate or blank frames on a slow device.
     *
     * @param {HTMLVideoElement} video
     * @param {number} t Target time in seconds.
     * @return {Promise<boolean>} true if the seek landed.
     */
    var seekTo = function(video, t) {
        return new Promise(function(resolve) {
            var done = false;
            var finish = function(ok) {
                if (done) {
                    return;
                }
                done = true;
                video.removeEventListener('seeked', onSeeked);
                video.removeEventListener('error', onError);
                resolve(ok);
            };
            var onSeeked = function() {
                finish(true);
            };
            var onError = function() {
                finish(false);
            };
            video.addEventListener('seeked', onSeeked);
            video.addEventListener('error', onError);
            // A seek that never completes must not hang the whole upload.
            setTimeout(function() {
                finish(false);
            }, 3000);
            try {
                video.currentTime = t;
            } catch (e) {
                finish(false);
            }
        });
    };

    /**
     * Build one contact sheet of stills from a recorded blob.
     *
     * @param {Blob} blob The recording.
     * @param {number} durationSeconds True elapsed seconds.
     * @param {Object} [opts] Optional overrides, used by tests.
     * @return {Promise<Blob|null>} The sheet, or null when frames cannot be taken.
     */
    var extractSheet = function(blob, durationSeconds, opts) {
        var options = opts || {};
        var count = options.count || FRAME_COUNT;
        var url = null;
        var video = null;

        var cleanup = function() {
            try {
                if (video) {
                    video.pause();
                    video.removeAttribute('src');
                    video.load();
                }
            } catch (e) {
                // Nothing useful to do; we are already tearing down.
            }
            if (url) {
                try {
                    URL.revokeObjectURL(url);
                } catch (e2) {
                    // Same.
                }
                url = null;
            }
        };

        return new Promise(function(resolve) {
            var settled = false;
            var finish = function(value) {
                if (settled) {
                    return;
                }
                settled = true;
                cleanup();
                resolve(value);
            };

            // Whole-operation guard. Worth having even though each seek has its
            // own: a device can stall between seeks just as easily as during one.
            setTimeout(function() {
                finish(null);
            }, options.timeoutMs || TIMEOUT_MS);

            try {
                if (!blob || !window.URL || !document.createElement('canvas').getContext) {
                    finish(null);
                    return;
                }
                var times = sampleTimes(durationSeconds, count);
                if (!times.length) {
                    finish(null);
                    return;
                }

                var rows = Math.ceil(count / COLS);
                var canvas = document.createElement('canvas');
                canvas.width = CELL_W * COLS;
                canvas.height = CELL_H * rows;
                var ctx = canvas.getContext('2d');
                if (!ctx || !canvas.toBlob) {
                    finish(null);
                    return;
                }
                ctx.fillStyle = '#000';
                ctx.fillRect(0, 0, canvas.width, canvas.height);

                url = URL.createObjectURL(blob);
                video = document.createElement('video');
                video.muted = true;
                video.playsInline = true;
                video.preload = 'auto';
                video.src = url;

                var drawn = 0;
                var next = function(i) {
                    if (i >= times.length) {
                        if (!drawn) {
                            finish(null);
                            return;
                        }
                        canvas.toBlob(function(out) {
                            finish(out || null);
                        }, 'image/jpeg', options.quality || QUALITY);
                        return;
                    }
                    seekTo(video, times[i]).then(function(ok) {
                        if (ok && video.videoWidth > 0) {
                            var cx = (i % COLS) * CELL_W;
                            var cy = Math.floor(i / COLS) * CELL_H;
                            // Letterbox rather than stretch: a distorted aspect
                            // ratio would change what posture looks like.
                            var scale = Math.min(CELL_W / video.videoWidth, CELL_H / video.videoHeight);
                            var dw = Math.max(1, Math.round(video.videoWidth * scale));
                            var dh = Math.max(1, Math.round(video.videoHeight * scale));
                            ctx.drawImage(
                                video,
                                cx + Math.floor((CELL_W - dw) / 2),
                                cy + Math.floor((CELL_H - dh) / 2),
                                dw,
                                dh
                            );
                            drawn++;
                        }
                        next(i + 1);
                        return null;
                    }).catch(function() {
                        next(i + 1);
                    });
                };

                var start = function() {
                    // A blob with no video track (audio-only, or a codec the
                    // browser will not decode) yields no usable frames.
                    if (!video.videoWidth && !video.videoHeight) {
                        // videoWidth is 0 until metadata; only bail if loadeddata
                        // has already fired and it is still 0.
                        if (video.readyState >= 2) {
                            finish(null);
                            return;
                        }
                    }
                    next(0);
                };

                video.addEventListener('loadeddata', start, {once: true});
                video.addEventListener('error', function() {
                    finish(null);
                }, {once: true});
            } catch (e) {
                finish(null);
            }
        });
    };

    return {
        extractSheet: extractSheet,
        // Exported for unit tests; the arithmetic decides which moments of a
        // learner's talk get judged, so it should not only be exercised through
        // a headless browser.
        _sampleTimes: sampleTimes,
        FRAME_COUNT: FRAME_COUNT
    };
});
