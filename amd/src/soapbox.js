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
 * Soapbox speech-practice page controller.
 *
 * Replaces the inline <script> that soapbox.php used to echo (CONTRIB-10574
 * #273 and #278). Soapbox is deprecated in 7.5.2 and is removed in 8.0, so this
 * is a move and nothing more: both speech-to-text paths, the timer, the minimum
 * transcript length and every message are what they were.
 *
 * Three mechanical things changed with the move, none of them behavioural:
 *
 * 1. The raw fetch() against /lib/ajax/service.php is now a core/ajax call, so
 *    the sesskey and the request envelope come from Moodle rather than from a
 *    URL interpolated into the page. The audio upload stays a plain fetch()
 *    because it posts a binary blob, which the web service layer cannot carry.
 * 2. The hand-rolled HTML escaper and the innerHTML concatenation are gone. The
 *    scores render through core/templates, which escapes the model-supplied
 *    text for us. That escaper was the only thing between an AI response and
 *    injected markup, so replacing it with the template engine is the point of
 *    the change, not a side effect.
 * 3. Config and strings arrive in the root element's data-config attribute
 *    rather than as PHP-interpolated JavaScript literals. They are still
 *    resolved server-side, so there is no window in which the page shows an
 *    English fallback.
 *
 * @module     local_ai_course_assistant/soapbox
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/templates', 'core/notification'], function(Ajax, Templates, Notification) {

    /** @type {number} Shortest transcript worth spending a model call on. */
    var MIN_TRANSCRIPT_CHARS = 40;
    /** @type {number} Grace period for the last Web Speech result to land, ms. */
    var BROWSER_FLUSH_MS = 400;

    var cfg = {};
    var strs = {};
    var sttMode = 'server';

    var recordBtn, timerEl, statusEl, resultEl, modeNote;
    var recording = false;
    var startTs = 0;
    var timerInt = null;
    var mediaRec = null;
    var chunks = [];
    var recognizer = null;
    var browserTranscript = '';

    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;

    /**
     * Show or hide an element without touching an inline style attribute.
     *
     * @param {HTMLElement} el
     * @param {boolean} visible
     */
    var toggle = function(el, visible) {
        if (el) {
            el.classList.toggle('sola-soapbox-hidden', !visible);
        }
    };

    /**
     * Put a plain message in the result area inside an alert.
     *
     * @param {string} text
     * @param {string} tone Bootstrap alert suffix, 'warning' or 'danger'.
     * @param {string} detail Optional status code shown in a code element.
     */
    var showAlert = function(text, tone, detail) {
        var alertEl = document.createElement('div');
        alertEl.className = 'alert alert-' + tone;
        alertEl.appendChild(document.createTextNode(text));
        if (detail) {
            var code = document.createElement('code');
            // Set as textContent, so a status code echoed back by the server
            // can never become markup.
            code.textContent = detail;
            alertEl.appendChild(document.createTextNode(' '));
            alertEl.appendChild(code);
        }
        resultEl.textContent = '';
        resultEl.appendChild(alertEl);
        toggle(resultEl, true);
    };

    /**
     * Map a server status code to a friendly learner-facing message. Unknown
     * codes fall back to the generic error with the raw code kept for
     * diagnosis.
     *
     * @param {string} code
     * @return {string}
     */
    var errMsg = function(code) {
        // Keys are server status codes, not JavaScript identifiers.
        var map = {
            'too_short': strs.tooShort,
            'provider_error': strs.errProvider,
            'parse_error': strs.errParse,
            'disabled': strs.errDisabled
        };
        return map[code] || (strs.error + ' (' + code + ')');
    };

    /**
     * Zero-padded mm:ss.
     *
     * @param {number} s Seconds.
     * @return {string}
     */
    var fmt = function(s) {
        return ('0' + Math.floor(s / 60)).slice(-2) + ':' + ('0' + (s % 60)).slice(-2);
    };

    /**
     * One second of the recording timer.
     */
    var tick = function() {
        timerEl.textContent = fmt(Math.floor((Date.now() - startTs) / 1000));
    };

    /**
     * Set or clear the status line beside the record button.
     *
     * @param {string} txt
     */
    var setBusy = function(txt) {
        statusEl.textContent = txt || '';
    };

    /**
     * Show a plain warning in the result area and clear the busy state.
     *
     * @param {string} msg
     */
    var showWarn = function(msg) {
        setBusy('');
        showAlert(msg, 'warning', '');
    };

    /**
     * Run a call whose only failure mode is an exception we deliberately
     * swallow. SpeechRecognition throws on a double start and on a stop while
     * idle, and both were caught and ignored before this module existed.
     *
     * @param {Function} fn
     */
    var ignoreError = function(fn) {
        try {
            fn();
        } catch (x) {
            return;
        }
    };

    /**
     * Render the rubric scores for one run.
     *
     * @param {object} res Response from local_ai_course_assistant_score_speech.
     * @return {Promise}
     */
    var renderResult = function(res) {
        var tips = (res.tips || []).map(function(text) {
            return {text: text};
        });
        var context = {
            criteria: (res.criteria || []).map(function(c) {
                return {
                    name: c.name,
                    // This is rubric_manager::is_assessed() in JavaScript:
                    // excluded only on an explicit false, so undefined, null,
                    // 0, "0" and "" all count, exactly as PHP counts them. The
                    // older form greyed out a row the total had counted.
                    assessed: c.assessed !== false,
                    score: c.score,
                    feedback: c.feedback
                };
            }),
            overall: res.overall || '',
            hastips: tips.length > 0,
            tips: tips
        };
        return Templates.render('local_ai_course_assistant/soapbox_result', context)
            .then(function(html) {
                Templates.replaceNodeContents(resultEl, html, '');
                toggle(resultEl, true);
                var empty = document.getElementById('sb-history-empty');
                if (empty) {
                    toggle(empty, false);
                }
                return null;
            });
    };

    /**
     * Score a finished transcript and show the result.
     *
     * @param {string} transcript
     * @param {number} durationSec
     */
    var finishWithTranscript = function(transcript, durationSec) {
        if (!transcript || transcript.trim().length < MIN_TRANSCRIPT_CHARS) {
            setBusy('');
            showAlert(strs.tooShort, 'warning', '');
            recordBtn.disabled = false;
            return;
        }
        setBusy(strs.scoring);
        Ajax.call([{
            methodname: 'local_ai_course_assistant_score_speech',
            args: {
                courseid: cfg.courseid,
                transcript: transcript,
                name: (document.getElementById('sb-name').value || ''),
                topic: (document.getElementById('sb-topic').value || ''),
                targetsec: parseInt(document.getElementById('sb-target').value, 10) || 0,
                durationsec: durationSec || 0,
                mode: (document.getElementById('sb-mode').value || 'informative')
            }
        }])[0]
        .then(function(res) {
            recordBtn.disabled = false;
            setBusy('');
            if (!res || !res.success) {
                showAlert(errMsg(res && res.message ? res.message : 'unknown'), 'warning', '');
                return null;
            }
            return renderResult(res);
        })
        .catch(function() {
            recordBtn.disabled = false;
            setBusy('');
            showAlert(strs.error, 'danger', '');
        });
    };

    /**
     * Upload a recorded blob to soapbox_transcribe.php, then score it.
     *
     * @param {Blob} blob
     * @param {string} mime
     * @param {number} durationSec
     */
    var uploadForTranscription = function(blob, mime, durationSec) {
        setBusy(strs.transcribing);
        var fd = new FormData();
        fd.append('audio', blob, 'speech.' + (mime.indexOf('mp4') !== -1 ? 'mp4' : 'webm'));
        fd.append('sesskey', cfg.sesskey);
        fd.append('courseid', String(cfg.courseid));
        // Still a plain fetch: this posts binary audio, which the web service
        // layer cannot carry. soapbox_transcribe.php checks the sesskey itself.
        // The HTTP status is kept aside rather than threaded through a nested
        // then(), which is the only structural difference from the inline
        // script this replaced.
        var httpOk = true;
        fetch(cfg.transcribeurl, {method: 'POST', credentials: 'same-origin', body: fd})
            .then(function(r) {
                httpOk = r.ok;
                return r.json();
            })
            .then(function(j) {
                if (!httpOk || !j || !j.text) {
                    recordBtn.disabled = false;
                    setBusy('');
                    showAlert(strs.errTranscribe, 'warning',
                        (j && j.error) ? j.error : 'transcription');
                    return null;
                }
                finishWithTranscript(j.text, durationSec);
                return null;
            })
            .catch(function() {
                recordBtn.disabled = false;
                setBusy('');
                showAlert(strs.error, 'danger', '');
            });
    };

    /**
     * Start the recording UI and the timer.
     */
    var beginUI = function() {
        recording = true;
        startTs = Date.now();
        timerEl.textContent = '00:00';
        timerInt = setInterval(tick, 1000);
        recordBtn.textContent = strs.stop;
        recordBtn.classList.remove('btn-primary');
        recordBtn.classList.add('btn-danger');
        setBusy(strs.recording);
        toggle(resultEl, false);
    };

    /**
     * Stop the recording UI and the timer.
     */
    var endUI = function() {
        recording = false;
        clearInterval(timerInt);
        recordBtn.textContent = strs.record;
        recordBtn.classList.remove('btn-danger');
        recordBtn.classList.add('btn-primary');
        recordBtn.disabled = true;
    };

    // ---- Server mode: MediaRecorder -> upload blob -> soapbox_transcribe.php ----

    /**
     * Begin a server-mode recording.
     */
    var startServer = function() {
        // Capability gate FIRST. On an insecure (plain-http) context
        // navigator.mediaDevices is undefined, so the old code threw a
        // synchronous TypeError inside the click handler -- no promise, no
        // catch, no alert: a silent dead Record button. And every failure that
        // DID reach the catch below, including "this browser cannot record at
        // all", was reported as a microphone-permission denial.
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
            showWarn(strs.noMediaSupport);
            return;
        }
        navigator.mediaDevices.getUserMedia({audio: true}).then(function(stream) {
            chunks = [];
            var mime = '';
            ['audio/webm', 'audio/mp4', 'audio/ogg'].some(function(m) {
                if (MediaRecorder.isTypeSupported(m)) {
                    mime = m;
                    return true;
                }
                return false;
            });
            try {
                mediaRec = mime ? new MediaRecorder(stream, {mimeType: mime}) : new MediaRecorder(stream);
            } catch (e) {
                // Release the mic -- without this the browser's recording
                // indicator stayed lit with nothing on screen to explain it.
                stream.getTracks().forEach(function(t) {
                    t.stop();
                });
                showWarn(strs.error + ' (' + ((e && e.name) || 'MediaRecorder') + ')');
                return;
            }
            mediaRec.ondataavailable = function(e) {
                if (e.data && e.data.size) {
                    chunks.push(e.data);
                }
            };
            mediaRec.onstop = function() {
                stream.getTracks().forEach(function(t) {
                    t.stop();
                });
                var durationSec = Math.floor((Date.now() - startTs) / 1000);
                var recmime = mediaRec.mimeType || '';
                var blob = new Blob(chunks, {type: recmime || 'audio/webm'});
                uploadForTranscription(blob, recmime, durationSec);
            };
            mediaRec.start();
            beginUI();
            return;
        }).catch(function(err) {
            var denied = err && (err.name === 'NotAllowedError'
                || err.name === 'PermissionDeniedError' || err.name === 'SecurityError');
            showWarn(denied ? strs.micDenied
                : (strs.error + (err && err.name ? ' (' + err.name + ')' : '')));
        });
    };

    /**
     * Finish a server-mode recording.
     */
    var stopServer = function() {
        if (mediaRec && mediaRec.state !== 'inactive') {
            mediaRec.stop();
        }
        endUI();
    };

    // ---- Browser mode: Web Speech API (free, no server) ----

    /**
     * Begin a browser-mode recording.
     */
    var startBrowser = function() {
        if (!SR) {
            window.alert(strs.noBrowserStt);
            return;
        }
        browserTranscript = '';
        recognizer = new SR();
        recognizer.continuous = true;
        recognizer.interimResults = false;
        recognizer.lang = document.documentElement.lang || 'en-US';
        recognizer.onresult = function(e) {
            for (var i = e.resultIndex; i < e.results.length; i++) {
                if (e.results[i].isFinal) {
                    browserTranscript += e.results[i][0].transcript + ' ';
                }
            }
        };
        recognizer.onerror = function(ev) {
            if (ev.error === 'not-allowed') {
                window.alert(strs.micDenied);
            }
        };
        // Keep going on auto-stop.
        recognizer.onend = function() {
            if (recording) {
                ignoreError(function() {
                    recognizer.start();
                });
            }
        };
        ignoreError(function() {
            recognizer.start();
        });
        beginUI();
    };

    /**
     * Finish a browser-mode recording.
     */
    var stopBrowser = function() {
        var durationSec = Math.floor((Date.now() - startTs) / 1000);
        endUI();
        if (recognizer) {
            ignoreError(function() {
                recognizer.stop();
            });
        }
        setTimeout(function() {
            finishWithTranscript(browserTranscript, durationSec);
        }, BROWSER_FLUSH_MS);
    };

    /**
     * Wire up the page.
     */
    var init = function() {
        var root = document.querySelector('.aica-soapbox');
        if (!root) {
            return;
        }
        sttMode = root.getAttribute('data-stt-mode') || 'server';
        try {
            cfg = JSON.parse(root.getAttribute('data-config') || '{}');
        } catch (e) {
            Notification.exception(e);
            return;
        }
        strs = cfg.strings || {};

        recordBtn = document.getElementById('sb-record');
        timerEl = document.getElementById('sb-timer');
        statusEl = document.getElementById('sb-status');
        resultEl = document.getElementById('sb-result');
        modeNote = document.getElementById('sb-mode-note');
        if (!recordBtn || !timerEl || !statusEl || !resultEl || !modeNote) {
            return;
        }

        modeNote.textContent = ((sttMode === 'browser') ? strs.browserNote : strs.serverNote) || '';

        recordBtn.addEventListener('click', function() {
            if (!recording) {
                if (sttMode === 'browser') {
                    startBrowser();
                } else {
                    startServer();
                }
            } else {
                if (sttMode === 'browser') {
                    stopBrowser();
                } else {
                    stopServer();
                }
            }
        });
    };

    return {init: init};
});
