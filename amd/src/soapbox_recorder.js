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
 * Soapbox in-browser recorder (v6.8.14): getUserMedia + MediaRecorder at a
 * capped bitrate, min/max timer, then upload via soapbox_uploader. MP4 is
 * preferred where the browser can record it (cross-device playback), WebM is
 * the fallback. No auto tests (browser media APIs); the upload orchestration is
 * tested separately in soapbox_uploader.
 *
 * @module     local_ai_course_assistant/soapbox_recorder
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['local_ai_course_assistant/soapbox_uploader', 'core/str'], function(Uploader, Str) {

    /**
     * Pick a MediaRecorder mime type the browser supports, MP4 first.
     *
     * @param {string} mode video|audio
     * @return {string}
     */
    var pickMime = function(mode) {
        var video = ['video/mp4;codecs=avc1', 'video/mp4',
            'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'];
        var audio = ['audio/mp4', 'audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/webm'];
        var list = (mode === 'audio') ? audio : video;
        for (var i = 0; i < list.length; i++) {
            if (window.MediaRecorder && window.MediaRecorder.isTypeSupported(list[i])) {
                return list[i];
            }
        }
        return '';
    };

    /**
     * File extension for a recorded mime type.
     *
     * @param {string} mime
     * @return {string}
     */
    var extFor = function(mime) {
        if (mime.indexOf('mp4') > -1) {
            return 'mp4';
        }
        if (mime.indexOf('ogg') > -1) {
            return 'ogg';
        }
        return 'webm';
    };

    /**
     * Format seconds as m:ss.
     *
     * @param {number} s
     * @return {string}
     */
    var fmt = function(s) {
        var m = Math.floor(s / 60);
        var ss = s % 60;
        return m + ':' + (ss < 10 ? '0' : '') + ss;
    };

    return {
        /**
         * Wire up a recorder against a container.
         *
         * @param {object} config assignid, mode, minSeconds, maxSeconds,
         *                        quality {width,height,videoKbps,audioKbps}, topicid
         * @param {object} sel CSS selectors: root, preview, record, stop, timer, status, result
         */
        init: function(config, sel) {
            var root = document.querySelector(sel.root);
            if (!root) {
                return;
            }
            var preview = root.querySelector(sel.preview);
            var recBtn = root.querySelector(sel.record);
            var stopBtn = root.querySelector(sel.stop);
            var timerEl = root.querySelector(sel.timer);
            var statusEl = root.querySelector(sel.status);
            var resultEl = root.querySelector(sel.result);
            var quality = config.quality || {width: 854, height: 480, videoKbps: 500, audioKbps: 40};

            var stream = null;
            var recorder = null;
            var chunks = [];
            var startedAt = 0;
            var timerId = null;
            var mime = '';

            var setStatus = function(msg) {
                if (statusEl) {
                    statusEl.textContent = msg;
                }
            };

            var stopTimer = function() {
                if (timerId) {
                    window.clearInterval(timerId);
                    timerId = null;
                }
            };

            var getConstraints = function() {
                if (config.mode === 'audio') {
                    return {audio: true, video: false};
                }
                return {
                    audio: true,
                    video: {
                        width: {ideal: quality.width},
                        height: {ideal: quality.height},
                        frameRate: {max: 24}
                    }
                };
            };

            var stopStream = function() {
                if (stream) {
                    stream.getTracks().forEach(function(t) {
                        t.stop();
                    });
                    stream = null;
                }
            };

            var handleStop = function() {
                var elapsed = Math.floor((Date.now() - startedAt) / 1000);
                stopStream();
                if (config.minSeconds && elapsed < config.minSeconds) {
                    setStatus('That was too short. Aim for at least ' + fmt(config.minSeconds) + '.');
                    if (recBtn) {
                        recBtn.disabled = false;
                    }
                    return;
                }
                var blob = new Blob(chunks, {type: mime || 'application/octet-stream'});
                setStatus('Uploading...');
                Uploader.uploadRecording({
                    assignid: config.assignid,
                    topicid: config.topicid || 0,
                    blob: blob,
                    ext: extFor(mime || (config.mode === 'audio' ? 'audio/webm' : 'video/webm')),
                    contentType: mime || 'application/octet-stream',
                    durationSeconds: elapsed
                }).then(function(res) {
                    setStatus('Uploaded.');
                    if (resultEl) {
                        resultEl.textContent = '';
                        var link = document.createElement('a');
                        link.href = res.viewurl;
                        link.target = '_blank';
                        link.rel = 'noopener';
                        link.textContent = 'View your recording';
                        resultEl.appendChild(link);
                    }
                    if (recBtn) {
                        recBtn.disabled = false;
                    }
                    return res;
                }).catch(function() {
                    setStatus('Upload failed. Please try again.');
                    if (recBtn) {
                        recBtn.disabled = false;
                    }
                });
            };

            var tick = function() {
                var el = Math.floor((Date.now() - startedAt) / 1000);
                if (timerEl) {
                    timerEl.textContent = fmt(el);
                }
                if (config.maxSeconds && el >= config.maxSeconds - 15) {
                    root.classList.add('sbx-near-max');
                }
                if (config.maxSeconds && el >= config.maxSeconds) {
                    stop();
                }
            };

            var stop = function() {
                if (recorder && recorder.state !== 'inactive') {
                    recorder.stop();
                }
                stopTimer();
                root.classList.remove('sbx-recording');
                if (stopBtn) {
                    stopBtn.disabled = true;
                }
            };

            var start = function() {
                navigator.mediaDevices.getUserMedia(getConstraints()).then(function(s) {
                    stream = s;
                    if (preview && config.mode !== 'audio') {
                        preview.srcObject = s;
                        preview.muted = true;
                        preview.play();
                    }
                    mime = pickMime(config.mode);
                    var opts = {};
                    if (mime) {
                        opts.mimeType = mime;
                    }
                    if (config.mode !== 'audio') {
                        opts.videoBitsPerSecond = quality.videoKbps * 1000;
                    }
                    opts.audioBitsPerSecond = quality.audioKbps * 1000;
                    chunks = [];
                    recorder = new window.MediaRecorder(s, opts);
                    recorder.ondataavailable = function(e) {
                        if (e.data && e.data.size) {
                            chunks.push(e.data);
                        }
                    };
                    recorder.onstop = handleStop;
                    recorder.start();
                    startedAt = Date.now();
                    root.classList.remove('sbx-near-max');
                    root.classList.add('sbx-recording');
                    if (recBtn) {
                        recBtn.disabled = true;
                    }
                    if (stopBtn) {
                        stopBtn.disabled = false;
                    }
                    timerId = window.setInterval(tick, 500);
                    setStatus('Recording...');
                    return s;
                }).catch(function() {
                    Str.get_string('soapbox:mic_denied', 'local_ai_course_assistant').then(function(m) {
                        setStatus(m);
                        return m;
                    }).catch(function() {
                        setStatus('Camera or microphone permission was denied.');
                    });
                });
            };

            if (!window.MediaRecorder || !navigator.mediaDevices) {
                setStatus('This browser cannot record. Please use a recent Chrome, Edge, or Safari.');
                if (recBtn) {
                    recBtn.disabled = true;
                }
                return;
            }
            if (recBtn) {
                recBtn.addEventListener('click', start);
            }
            if (stopBtn) {
                stopBtn.disabled = true;
                stopBtn.addEventListener('click', stop);
            }
        }
    };
});
