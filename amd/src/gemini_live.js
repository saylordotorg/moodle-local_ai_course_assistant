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
 * Gemini Live voice mode (8.2.0): two-way speech over Google's Live API WebSocket.
 *
 * Public API mirrors realtime.js (connect, disconnect, isConnected, sendText, ELL_INSTRUCTIONS,
 * ellInstructions) so chat.js picks one or the other by the provider the token endpoint returns.
 *
 * - The browser holds only a single-use ephemeral token minted by get_realtime_token; the
 *   model, system instruction, voice and AUDIO output are locked into that token server-side.
 * - Mic: an AudioWorklet (never ScriptProcessor) resamples to 16 kHz and sends PCM16 in 40 ms
 *   chunks. Model audio (24 kHz PCM16) plays through a gapless queue; when the server says the
 *   turn was interrupted (the learner spoke over it), queued audio stops at once.
 * - Playback runs through one AnalyserNode for the whole session, handed to the caller
 *   (onAnalyser) so the avatar's mouth follows the voice.
 * - Cost guardrails: the session ends at maxSeconds and after idleSeconds with nobody speaking
 *   (the token itself dies shortly after maxSeconds, server-side). Google's usageMetadata is
 *   summed and handed to onUsage once, when the session ends.
 *
 * The Live message shapes (setup, realtimeInput.audio, clientContent, serverContent with
 * modelTurn/inputTranscription/outputTranscription/interrupted/turnComplete, usageMetadata,
 * goAway) follow Google's Live API documentation as remembered at the time of writing; they were
 * not checked against a live session.
 *
 * @module     local_ai_course_assistant/gemini_live
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    /** Rate Gemini Live expects for microphone audio. */
    var INPUT_RATE = 16000;
    /** Rate Gemini Live sends audio at, unless the chunk's mime type says otherwise. */
    var OUTPUT_RATE = 24000;
    /** Samples per mic chunk sent: 40 ms at 16 kHz. */
    var CHUNK_SAMPLES = 640;
    /** Mic RMS above which the learner counts as speaking, for the idle timer. */
    var SPEECH_RMS = 0.02;
    /** Default caps when the server sends none. */
    var DEFAULT_MAX_SECONDS = 600;
    /** How often the silence limit is checked, and how long a pending model answer holds it off. */
    var IDLE_CHECK_MS = 250;
    var REPLY_WAIT_MS = 20000;
    /** Seconds of silence before listening stops (8.3.1: 5; the server sends the configured value). */
    var DEFAULT_IDLE_SECONDS = 5;

    /**
     * Citation and source markers ([[c:1]], [SOURCE:activity:522]) never reach the transcript (8.3.1). A marker
     * arrives over several chunks, so a partly arrived one is held back. push() takes the next chunk and returns
     * the text to show, or ''.
     *
     * @return {{push: function(string): string}}
     */
    var makeMarkerFilter = function() {
        var markerRe = /\s*(?:\[\[c:\d+\]\]|\[{1,3}SOURCE:[^[\]]*\]{1,3})/gi;
        var prefixRe = /^\[{1,3}(?:c(?::\d*)?|S(?:O(?:U(?:R(?:C(?:E(?::[^[\]]*)?)?)?)?)?)?)?\]{0,2}$/i;
        var raw = '';
        var emitted = 0;
        return {
            push: function(chunk) {
                raw += chunk;
                var end = raw.length;
                var i = raw.lastIndexOf('[');
                if (i !== -1) {
                    while (i > 0 && raw.charAt(i - 1) === '[') {
                        i--;
                    }
                    if (prefixRe.test(raw.slice(i))) {
                        end = i;
                    }
                }
                var clean = raw.slice(0, end).replace(markerRe, '').replace(/\s+$/, '');
                if (clean.length <= emitted) {
                    return '';
                }
                var out = clean.slice(emitted);
                emitted = clean.length;
                return out;
            }
        };
    };

    var ELL_INSTRUCTIONS = 'You are {$a} in a real-time voice conversation. Keep responses brief (2-3 sentences). ' +
        '[ELL Coaching Mode] You are an English conversation and pronunciation coach.';

    /**
     * Resample one block of float samples to 16 kHz PCM16, carrying the fractional position across
     * blocks so consecutive blocks join without a click. Pure and self-contained: its source text is
     * also what runs inside the AudioWorklet (see workletSource()), so the tested code is the code
     * that runs.
     *
     * @param {Object} st State: {pos: number, last: number, buf: Int16Array, n: number}
     * @param {Float32Array} input One block at inRate
     * @param {number} inRate Input sample rate
     * @param {number} outRate Output sample rate
     * @param {Function} emit Called with each full Int16Array chunk and its RMS (0..1)
     */
    var resampleBlock = function(st, input, inRate, outRate, emit) {
        var step = inRate / outRate;
        var len = input.length;
        if (!len) {
            return;
        }
        while (st.pos <= len - 1) {
            var i = Math.floor(st.pos);
            var f = st.pos - i;
            var a = i < 0 ? st.last : input[i];
            var b = (i + 1 < len) ? input[i + 1] : a;
            var s = Math.max(-1, Math.min(1, a + f * (b - a)));
            st.buf[st.n] = s < 0 ? s * 0x8000 : s * 0x7fff;
            st.sq += s * s;
            st.n++;
            if (st.n === st.buf.length) {
                emit(st.buf, Math.sqrt(st.sq / st.n));
                st.buf = new Int16Array(st.buf.length);
                st.n = 0;
                st.sq = 0;
            }
            st.pos += step;
        }
        st.pos -= len;
        st.last = input[len - 1];
    };

    /**
     * A fresh resampler state.
     *
     * @param {number} chunk Samples per emitted chunk
     * @returns {Object}
     */
    var newResamplerState = function(chunk) {
        return {pos: 0, last: 0, buf: new Int16Array(chunk), n: 0, sq: 0};
    };

    /**
     * The AudioWorklet module source: a processor that runs resampleBlock on the mic and posts
     * each 40 ms chunk (and its level) to the main thread. Built from the function's own text,
     * loaded from a Blob URL: no file, no network.
     *
     * @returns {string}
     */
    var workletSource = function() {
        return 'var resampleBlock = ' + resampleBlock.toString() + ';\n' +
            'class SolaLiveCapture extends AudioWorkletProcessor {\n' +
            '  constructor() {\n' +
            '    super();\n' +
            '    this.st = {pos: 0, last: 0, buf: new Int16Array(' + CHUNK_SAMPLES + '), n: 0, sq: 0};\n' +
            '  }\n' +
            '  process(inputs) {\n' +
            '    var ch = inputs[0] && inputs[0][0];\n' +
            '    if (ch && ch.length) {\n' +
            '      var port = this.port;\n' +
            '      resampleBlock(this.st, ch, sampleRate, ' + INPUT_RATE + ', function(buf, rms) {\n' +
            '        port.postMessage({pcm: buf.buffer, rms: rms}, [buf.buffer]);\n' +
            '      });\n' +
            '    }\n' +
            '    return true;\n' +
            '  }\n' +
            '}\n' +
            'registerProcessor("sola-live-capture", SolaLiveCapture);\n';
    };

    /**
     * ArrayBuffer to base64.
     *
     * @param {ArrayBuffer} buffer
     * @returns {string}
     */
    var arrayBufferToBase64 = function(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.byteLength; i += 0x8000) {
            binary += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
        }
        return btoa(binary);
    };

    /**
     * Base64 little-endian PCM16 to Float32 samples.
     *
     * @param {string} b64
     * @returns {Float32Array}
     */
    var pcm16Base64ToFloat32 = function(b64) {
        var binary = atob(b64);
        var count = Math.floor(binary.length / 2);
        var out = new Float32Array(count);
        for (var i = 0; i < count; i++) {
            var lo = binary.charCodeAt(2 * i);
            var hi = binary.charCodeAt(2 * i + 1);
            var v = hi * 256 + lo;
            if (v >= 0x8000) {
                v -= 0x10000;
            }
            out[i] = v / 32768;
        }
        return out;
    };

    /**
     * The sample rate named in a mime type such as "audio/pcm;rate=24000", else the default.
     *
     * @param {string} mime
     * @returns {number}
     */
    var rateFromMime = function(mime) {
        var m = /rate=(\d+)/.exec(mime || '');
        var rate = m ? parseInt(m[1], 10) : 0;
        return (rate >= 8000 && rate <= 48000) ? rate : OUTPUT_RATE;
    };

    /**
     * Token counts out of one usageMetadata object (missing fields read as 0).
     *
     * @param {Object|null} usage
     * @returns {{prompt: number, response: number}}
     */
    var usageCounts = function(usage) {
        if (!usage || typeof usage !== 'object') {
            return {prompt: 0, response: 0};
        }
        var prompt = parseInt(usage.promptTokenCount, 10) || 0;
        var response = parseInt(usage.responseTokenCount, 10) ||
            parseInt(usage.candidatesTokenCount, 10) || 0;
        return {prompt: Math.max(0, prompt), response: Math.max(0, response)};
    };

    /**
     * Sort one server message into the events this module acts on. Pure.
     *
     * @param {Object} msg Parsed JSON from the socket
     * @returns {Object} {setupComplete, audio: [{data, mime}], userText, modelText, interrupted,
     *                   turnComplete, usage, goAway, error}
     */
    /**
     * The audio chunks in a serverContent.modelTurn.
     *
     * @param {Object} sc serverContent
     * @returns {Array<{data: string, mime: string}>}
     */
    var audioParts = function(sc) {
        var parts = (sc.modelTurn && sc.modelTurn.parts) || [];
        var out = [];
        parts.forEach(function(part) {
            var inline = part && (part.inlineData || part.inline_data);
            if (inline && typeof inline.data === 'string' && inline.data !== '') {
                out.push({data: inline.data, mime: inline.mimeType || inline.mime_type || ''});
            }
        });
        return out;
    };

    /**
     * The text of a transcription object, or ''.
     *
     * @param {Object|undefined} t
     * @returns {string}
     */
    var transcriptText = function(t) {
        return (t && typeof t.text === 'string') ? t.text : '';
    };

    var classify = function(msg) {
        var out = {
            setupComplete: false, audio: [], userText: '', modelText: '', interrupted: false,
            turnComplete: false, usage: null, goAway: false, error: ''
        };
        if (!msg || typeof msg !== 'object') {
            return out;
        }
        out.setupComplete = !!msg.setupComplete;
        var sc = msg.serverContent;
        if (sc && typeof sc === 'object') {
            out.audio = audioParts(sc);
            out.userText = transcriptText(sc.inputTranscription);
            out.modelText = transcriptText(sc.outputTranscription);
            out.interrupted = !!sc.interrupted;
            out.turnComplete = !!sc.turnComplete;
        }
        if (msg.usageMetadata) {
            out.usage = usageCounts(msg.usageMetadata);
        }
        if (msg.goAway) {
            out.goAway = true;
        }
        if (msg.error) {
            out.error = (msg.error.message || String(msg.error));
        }
        return out;
    };

    /**
     * A positive integer from a value the server sent, else the default.
     *
     * @param {*} value
     * @param {number} fallback
     * @returns {number}
     */
    var positiveInt = function(value, fallback) {
        var n = parseInt(value, 10);
        return n > 0 ? n : fallback;
    };

    // ---- Session state ----------------------------------------------------------------------

    /** @type {WebSocket|null} */
    var ws = null;
    /** @type {AudioContext|null} */
    var audioCtx = null;
    /** @type {AudioWorkletNode|null} */
    var captureNode = null;
    /** @type {MediaStreamAudioSourceNode|null} */
    var micSource = null;
    /** @type {MediaStream|null} */
    var micStream = null;
    /** @type {MediaStream|null} */
    var micStreamIn = null;
    /** @type {GainNode|null} */
    var masterGain = null;
    /** @type {AnalyserNode|null} */
    var analyser = null;
    /** @type {AudioBufferSourceNode[]} */
    var playing = [];
    /** @type {number} AudioContext time the next chunk starts at */
    var playHead = 0;
    /** @type {string} */
    var currentState = 'disconnected';
    /** @type {Object} */
    var cb = {};
    /** @type {boolean} */
    var started = false;
    /** @type {boolean} */
    var modelTurnOpen = false;
    /** @type {number} */
    var sessionStartMs = 0;
    /** @type {number} */
    var lastActivityMs = 0;
    /** @type {number} When a learner turn went out and the model hasn't started to answer, else 0. */
    var replyPendingSince = 0;
    /** @type {{push: function(string): string}} */
    var modelTextFilter = makeMarkerFilter();
    /** @type {{prompt: number, response: number}} */
    var usageTotal = {prompt: 0, response: 0};
    /** @type {boolean} */
    var usageReported = false;
    /** @type {Object} */
    var limits = {maxSeconds: DEFAULT_MAX_SECONDS, idleSeconds: DEFAULT_IDLE_SECONDS, sessionId: 0};
    var timers = {connect: null, warn: null, cap: null, idle: null};
    /** @type {number|null} */
    var rafId = null;
    /** @type {HTMLElement|null} */
    var overlayRoot = null;
    /** @type {string|null} */
    var workletUrl = null;

    var setState = function(state) {
        currentState = state;
        if (cb.onStateChange) {
            cb.onStateChange(state);
        }
    };

    /**
     * Report an error once, then end the session. beforeStart tells chat.js the live session never
     * began, so it can fall back to the regular voice mode.
     *
     * @param {string} msg
     */
    var fail = function(msg) {
        var onError = cb.onError;
        var beforeStart = !started;
        cb.onError = null;
        // The caller hears about it first, while its session is still current, so it can fall
        // back to the regular voice mode; then everything is released.
        if (onError) {
            onError(msg, {beforeStart: beforeStart, provider: 'gemini'});
        }
        disconnect();
    };

    var send = function(obj) {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify(obj));
        }
    };

    var stopPlayback = function() {
        playing.forEach(function(src) {
            try {
                src.onended = null;
                src.stop();
            } catch (e) { /* Already stopped. */ }
        });
        playing = [];
        playHead = 0;
    };

    var animateLevel = function() {
        if (!analyser || !overlayRoot) {
            rafId = null;
            return;
        }
        var data = new Uint8Array(analyser.frequencyBinCount);
        analyser.getByteFrequencyData(data);
        var sum = 0;
        for (var i = 0; i < data.length; i++) {
            sum += data[i];
        }
        overlayRoot.style.setProperty('--aica-voice-level', (sum / data.length / 255).toFixed(3));
        rafId = requestAnimationFrame(animateLevel);
    };

    /**
     * Queue one chunk of model audio right after the previous one (gapless).
     *
     * @param {string} b64
     * @param {string} mime
     */
    var enqueueAudio = function(b64, mime) {
        if (!audioCtx || !analyser) {
            return;
        }
        var samples = pcm16Base64ToFloat32(b64);
        if (!samples.length) {
            return;
        }
        var buf = audioCtx.createBuffer(1, samples.length, rateFromMime(mime));
        buf.copyToChannel(samples, 0);
        var src = audioCtx.createBufferSource();
        src.buffer = buf;
        src.connect(analyser);
        var startAt = Math.max(audioCtx.currentTime + 0.01, playHead);
        src.start(startAt);
        playHead = startAt + buf.duration;
        playing.push(src);
        src.onended = function() {
            playing = playing.filter(function(p) {
                return p !== src;
            });
            if (!playing.length && !modelTurnOpen && currentState === 'speaking') {
                setState('idle');
            }
        };
        lastActivityMs = Date.now();
        if (currentState !== 'speaking') {
            setState('speaking');
        }
    };

    /**
     * Pass both sides' transcripts on, and note what they say about whose turn it is (8.3.1): a learner turn
     * means the model's answer is on its way; model text means it has started to answer.
     *
     * @param {Object} ev One classified server message
     */
    var handleTranscripts = function(ev) {
        if (ev.userText && cb.onTranscript) {
            lastActivityMs = Date.now();
            replyPendingSince = Date.now();
            cb.onTranscript('user', ev.userText);
        }
        if (ev.modelText) {
            replyPendingSince = 0;
            var shown = modelTextFilter.push(ev.modelText);
            if (shown && cb.onTranscript) {
                cb.onTranscript('assistant', shown);
            }
        }
    };

    var handleEvents = function(ev) {
        if (ev.error) {
            fail(ev.error);
            return;
        }
        if (ev.setupComplete && !started) {
            started = true;
            sessionStartMs = Date.now();
            lastActivityMs = sessionStartMs;
            if (timers.connect) {
                clearTimeout(timers.connect);
                timers.connect = null;
            }
            startTimers();
            startMic();
            setState('idle');
        }
        if (ev.interrupted) {
            // Barge-in: the learner spoke over the model. Drop everything queued.
            stopPlayback();
            modelTurnOpen = false;
            modelTextFilter = makeMarkerFilter();
            setState('listening');
        }
        if (ev.audio.length) {
            replyPendingSince = 0;
            modelTurnOpen = true;
            ev.audio.forEach(function(a) {
                enqueueAudio(a.data, a.mime);
            });
        }
        handleTranscripts(ev);
        if (ev.usage) {
            usageTotal.prompt += ev.usage.prompt;
            usageTotal.response += ev.usage.response;
        }
        if (ev.turnComplete) {
            modelTurnOpen = false;
            replyPendingSince = 0;
            modelTextFilter = makeMarkerFilter();
            lastActivityMs = Date.now();
            if (!playing.length && currentState === 'speaking') {
                setState('idle');
            }
            // Audio only: no chip marker can ride along, so offer the caller's chips.
            if (cb.onSuggestions && cb.fallbackChips && cb.fallbackChips.length) {
                cb.onSuggestions(cb.fallbackChips.slice());
            }
        }
        if (ev.goAway) {
            if (cb.onTranscript) {
                cb.onTranscript('assistant', '\n(The live voice session has ended.)');
            }
            disconnect();
        }
    };

    var handleMessage = function(event) {
        var text = event.data;
        if (text instanceof ArrayBuffer) {
            text = new TextDecoder('utf-8').decode(new Uint8Array(text));
        }
        var msg;
        try {
            msg = JSON.parse(text);
        } catch (e) {
            return;
        }
        handleEvents(classify(msg));
    };

    var startTimers = function() {
        var maxMs = limits.maxSeconds * 1000;
        if (limits.maxSeconds > 120) {
            timers.warn = setTimeout(function() {
                if (cb.onTranscript) {
                    cb.onTranscript('assistant', '\n(One minute left in this live voice session.)');
                }
            }, maxMs - 60000);
        }
        timers.cap = setTimeout(function() {
            if (cb.onTranscript) {
                cb.onTranscript('assistant', '\n(This live voice session reached its time limit. ' +
                    'Start voice mode again to keep going.)');
            }
            disconnect();
        }, maxMs);
        timers.idle = setInterval(function() {
            // While the model is speaking or about to answer (up to 20 s), nobody is "idle": hold the
            // clock at zero so the silence count starts when the model stops, not when it started.
            var waiting = replyPendingSince && Date.now() - replyPendingSince < REPLY_WAIT_MS;
            if (playing.length || modelTurnOpen || waiting) {
                lastActivityMs = Date.now();
                return;
            }
            if (Date.now() - lastActivityMs > limits.idleSeconds * 1000) {
                // Stop listening so an open microphone doesn't keep running up the bill. The owner
                // (chat.js) offers a Resume button; without one the old end-of-session note shows.
                if (cb.onIdleStop) {
                    cb.onIdleStop();
                } else if (cb.onTranscript) {
                    cb.onTranscript('assistant', '\n(The live voice session ended because nobody spoke for a while.)');
                }
                disconnect();
            }
        }, IDLE_CHECK_MS);
    };

    var startMic = function() {
        var getStream = micStreamIn ? Promise.resolve(micStreamIn) :
            navigator.mediaDevices.getUserMedia({audio: {echoCancellation: true, noiseSuppression: true}});
        micStreamIn = null;
        getStream.then(function(stream) {
            micStream = stream;
            if (!audioCtx) {
                stream.getTracks().forEach(function(t) {
                    t.stop();
                });
                micStream = null;
                return null;
            }
            if (!audioCtx.audioWorklet || typeof AudioWorkletNode === 'undefined') {
                throw new Error('This browser cannot capture audio for live voice.');
            }
            workletUrl = URL.createObjectURL(new Blob([workletSource()], {type: 'application/javascript'}));
            return audioCtx.audioWorklet.addModule(workletUrl);
        }).then(function() {
            if (!audioCtx || !micStream) {
                return null;
            }
            micSource = audioCtx.createMediaStreamSource(micStream);
            captureNode = new AudioWorkletNode(audioCtx, 'sola-live-capture');
            captureNode.port.onmessage = function(e) {
                if (!e.data || !e.data.pcm) {
                    return;
                }
                if (e.data.rms >= SPEECH_RMS) {
                    lastActivityMs = Date.now();
                }
                send({realtimeInput: {audio: {
                    data: arrayBufferToBase64(e.data.pcm),
                    mimeType: 'audio/pcm;rate=' + INPUT_RATE
                }}});
            };
            // A muted sink keeps the worklet pulled without echoing the mic.
            var mute = audioCtx.createGain();
            mute.gain.value = 0;
            micSource.connect(captureNode);
            captureNode.connect(mute);
            mute.connect(audioCtx.destination);
            return null;
        }).catch(function(err) {
            fail('Microphone could not start for live voice: ' + (err && err.message ? err.message : err));
        });
    };

    /**
     * Open a Gemini Live session.
     *
     * @param {string} token Ephemeral token ("auth_tokens/...") from get_realtime_token
     * @param {string} instructions Unused: the instruction is locked into the token server-side
     * @param {string} voice Unused: the voice is locked into the token server-side
     * @param {Object} callbacks {onTranscript, onStateChange, onError(msg, info), onSuggestions,
     *     fallbackChips, onAnalyser(analyser), onUsage(usage), endpoint, model, maxSeconds,
     *     idleSeconds, sessionId}
     * @param {HTMLElement|null} overlayEl Voice bar, for the --aica-voice-level CSS variable
     * @param {AudioContext|null} audioCtxIn Created in the user gesture (iOS)
     * @param {MediaStream|null} micStreamParam Acquired in the user gesture (iOS)
     */
    var connect = function(token, instructions, voice, callbacks, overlayEl, audioCtxIn, micStreamParam) {
        if (ws) {
            disconnect();
        }
        cb = callbacks || {};
        overlayRoot = overlayEl || null;
        micStreamIn = micStreamParam || null;
        started = false;
        modelTurnOpen = false;
        replyPendingSince = 0;
        modelTextFilter = makeMarkerFilter();
        usageTotal = {prompt: 0, response: 0};
        usageReported = false;
        limits = {
            maxSeconds: positiveInt(cb.maxSeconds, DEFAULT_MAX_SECONDS),
            idleSeconds: positiveInt(cb.idleSeconds, DEFAULT_IDLE_SECONDS),
            sessionId: positiveInt(cb.sessionId, 0),
        };
        setState('connecting');

        var Ctx = window.AudioContext || window.webkitAudioContext;
        audioCtx = audioCtxIn || (Ctx ? new Ctx({sampleRate: OUTPUT_RATE}) : null);
        if (!audioCtx || !token || !cb.endpoint || !cb.model) {
            fail('Live voice is not available in this browser.');
            return;
        }
        if (audioCtx.state === 'suspended') {
            audioCtx.resume().catch(function() { /* Resumed on the next gesture. */ });
        }
        masterGain = audioCtx.createGain();
        masterGain.gain.value = 1;
        masterGain.connect(audioCtx.destination);
        analyser = audioCtx.createAnalyser();
        analyser.fftSize = 256;
        analyser.smoothingTimeConstant = 0.55;
        analyser.connect(masterGain);
        if (cb.onAnalyser) {
            cb.onAnalyser(analyser);
        }
        if (overlayRoot && !rafId) {
            rafId = requestAnimationFrame(animateLevel);
        }

        var sep = cb.endpoint.indexOf('?') === -1 ? '?' : '&';
        try {
            ws = new WebSocket(cb.endpoint + sep + 'access_token=' + encodeURIComponent(token));
        } catch (e) {
            fail('Live voice could not connect.');
            return;
        }
        ws.binaryType = 'arraybuffer';
        ws.addEventListener('open', function() {
            // Everything that matters is locked into the token; this names the model and asks for
            // transcripts of both sides.
            send({setup: {
                model: 'models/' + cb.model,
                generationConfig: {responseModalities: ['AUDIO']},
                inputAudioTranscription: {},
                outputAudioTranscription: {}
            }});
            timers.connect = setTimeout(function() {
                timers.connect = null;
                if (!started) {
                    fail('Live voice timed out while connecting.');
                }
            }, 10000);
        });
        ws.addEventListener('message', handleMessage);
        ws.addEventListener('error', function() {
            fail('Live voice connection failed.');
        });
        ws.addEventListener('close', function(e) {
            if (!ws) {
                return;
            }
            if (e.code !== 1000 && e.code !== 1001) {
                fail(e.reason ? ('Live voice closed: ' + e.reason) : 'Live voice connection closed unexpectedly.');
                return;
            }
            disconnect();
        });
    };

    /**
     * End the session and release everything: socket, mic, worklet, audio, timers. Reports usage
     * once through onUsage.
     */
    var disconnect = function() {
        Object.keys(timers).forEach(function(k) {
            if (timers[k]) {
                clearTimeout(timers[k]);
                clearInterval(timers[k]);
                timers[k] = null;
            }
        });
        if (rafId) {
            cancelAnimationFrame(rafId);
            rafId = null;
        }
        if (overlayRoot) {
            overlayRoot.style.removeProperty('--aica-voice-level');
        }
        stopPlayback();
        if (captureNode) {
            captureNode.port.onmessage = null;
            try {
                captureNode.disconnect();
            } catch (e) { /* Already disconnected. */ }
            captureNode = null;
        }
        if (micSource) {
            try {
                micSource.disconnect();
            } catch (e) { /* Already disconnected. */ }
            micSource = null;
        }
        [micStream, micStreamIn].forEach(function(s) {
            if (s) {
                s.getTracks().forEach(function(t) {
                    t.stop();
                });
            }
        });
        micStream = null;
        micStreamIn = null;
        if (workletUrl) {
            URL.revokeObjectURL(workletUrl);
            workletUrl = null;
        }
        var socket = ws;
        ws = null;
        if (socket) {
            try {
                socket.close(1000, 'Session ended');
            } catch (e) { /* Already closed. */ }
        }
        analyser = null;
        masterGain = null;
        if (audioCtx) {
            audioCtx.close().catch(function() { /* Already closed. */ });
            audioCtx = null;
        }
        if (!usageReported && limits.sessionId && cb.onUsage) {
            usageReported = true;
            cb.onUsage({
                sessionId: limits.sessionId,
                durationSec: sessionStartMs ? Math.round((Date.now() - sessionStartMs) / 1000) : 0,
                promptTokens: usageTotal.prompt,
                responseTokens: usageTotal.response
            });
        }
        sessionStartMs = 0;
        cb.onError = null;
        if (currentState !== 'disconnected') {
            setState('disconnected');
        }
    };

    var isConnected = function() {
        return ws !== null && ws.readyState === WebSocket.OPEN;
    };

    /**
     * Send typed text (a chip or the opening prompt) as a learner turn.
     *
     * @param {string} text
     */
    var sendText = function(text) {
        if (!isConnected() || !text) {
            return;
        }
        if (playing.length) {
            stopPlayback();
        }
        lastActivityMs = Date.now();
        replyPendingSince = Date.now();
        if (cb.onTranscript) {
            cb.onTranscript('user', text);
        }
        send({clientContent: {turns: [{role: 'user', parts: [{text: text}]}], turnComplete: true}});
    };

    var ellInstructions = function(name) {
        return ELL_INSTRUCTIONS.split('{$a}').join(name || 'the learning assistant');
    };

    return {
        connect: connect,
        disconnect: disconnect,
        isConnected: isConnected,
        sendText: sendText,
        ELL_INSTRUCTIONS: ELL_INSTRUCTIONS,
        ellInstructions: ellInstructions,
        // Pure helpers, exported for tests/js/gemini-live-check.js.
        _test: {
            resampleBlock: resampleBlock,
            newResamplerState: newResamplerState,
            workletSource: workletSource,
            pcm16Base64ToFloat32: pcm16Base64ToFloat32,
            rateFromMime: rateFromMime,
            usageCounts: usageCounts,
            classify: classify,
            arrayBufferToBase64: arrayBufferToBase64,
            makeMarkerFilter: makeMarkerFilter,
        },
    };
});
