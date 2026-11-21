/**
 * Gemini Live and the voice-driven avatar (8.2.0), checked with no browser and no network.
 *
 * Covers, against BOTH amd/src and the built amd/build bundle (Moodle serves the build):
 *   - the mic resampler (24 kHz and 48 kHz to 16 kHz PCM16, 40 ms chunks, no seam between blocks),
 *     including the copy that runs inside the AudioWorklet, which is built from the function's text;
 *   - PCM16 decoding of model audio, the rate read from its mime type, server message sorting;
 *   - a whole session against a fake WebSocket and AudioContext: the setup message, gapless
 *     playback, barge-in, typed text, the usage report and teardown;
 *   - avatar_motion's mouth and head motion bounds;
 *   - chat.js and ui.js wiring in source and build.
 *
 * What it can't check: real audio, a real Live endpoint, the AudioWorklet in a real browser.
 *
 * Run: node tests/js/gemini-live-check.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (...p) => fs.readFileSync(path.join(root, ...p), 'utf8');
let failures = 0;
const check = (name, ok, detail) => {
    if (ok) {
        console.log('  ok    ' + name);
    } else {
        failures += 1;
        console.log('  FAIL  ' + name + (detail ? '  -> ' + detail : ''));
    }
};

/**
 * Load an AMD module that has no dependencies, with the given globals.
 *
 * @param {string} src
 * @param {Object} globals
 * @returns {Object}
 */
const loadAmd = (src, globals) => {
    let mod = null;
    const names = Object.keys(globals || {});
    // eslint-disable-next-line no-new-func
    new Function('define', ...names, src)((deps, f) => {
        mod = (typeof deps === 'function' ? deps : f)();
    }, ...names.map((n) => globals[n]));
    return mod;
};

const sources = {
    src: read('amd', 'src', 'gemini_live.js'),
    build: read('amd', 'build', 'gemini_live.min.js'),
};

// ---- Fakes for a session --------------------------------------------------------------------
const makeEnv = () => {
    const env = {sockets: [], started: [], stopped: [], closedCtx: 0, rafs: 0};
    class FakeWS {
        constructor(url) {
            this.url = url;
            this.sent = [];
            this.readyState = 1;
            this.listeners = {};
            env.sockets.push(this);
        }
        addEventListener(type, fn) {
            (this.listeners[type] = this.listeners[type] || []).push(fn);
        }
        fire(type, ev) {
            (this.listeners[type] || []).forEach((fn) => fn(ev || {}));
        }
        send(data) {
            this.sent.push(JSON.parse(data));
        }
        close(code) {
            this.readyState = 3;
            this.closedWith = code;
        }
    }
    FakeWS.OPEN = 1;
    const node = () => ({connect() {}, disconnect() {}});
    class FakeCtx {
        constructor() {
            this.currentTime = 1;
            this.state = 'running';
            this.destination = {};
            this.audioWorklet = {addModule: () => Promise.resolve()};
        }
        createGain() {
            return Object.assign(node(), {gain: {value: 1, setValueAtTime() {}}});
        }
        createAnalyser() {
            return Object.assign(node(), {frequencyBinCount: 128, getByteFrequencyData() {}});
        }
        createBuffer(ch, len, rate) {
            return {duration: len / rate, rate: rate, copyToChannel() {}};
        }
        createBufferSource() {
            const src = Object.assign(node(), {
                start(at) {
                    this.at = at;
                    env.started.push(this);
                },
                stop() {
                    env.stopped.push(this);
                },
            });
            return src;
        }
        createMediaStreamSource() {
            return node();
        }
        close() {
            env.closedCtx += 1;
            return Promise.resolve();
        }
    }
    class FakeWorkletNode {
        constructor() {
            this.port = {};
        }
        connect() {}
        disconnect() {}
    }
    env.globals = {
        WebSocket: FakeWS,
        window: {AudioContext: FakeCtx},
        AudioWorkletNode: FakeWorkletNode,
        URL: {createObjectURL: () => 'blob:x', revokeObjectURL() {}},
        Blob: function Blob() {},
        requestAnimationFrame: () => {
            env.rafs += 1;
            return 1;
        },
        cancelAnimationFrame() {},
        navigator: {},
        TextDecoder: TextDecoder,
        atob: (s) => Buffer.from(s, 'base64').toString('binary'),
        btoa: (s) => Buffer.from(s, 'binary').toString('base64'),
    };
    env.Ctx = FakeCtx;
    return env;
};

const pcmB64 = (samples) => {
    const b = Buffer.alloc(samples.length * 2);
    samples.forEach((v, i) => b.writeInt16LE(v, i * 2));
    return b.toString('base64');
};

const tick = () => new Promise((r) => setImmediate(r));

const runFor = async(label, src) => {
    console.log(label);
    const env = makeEnv();
    const G = loadAmd(src, env.globals);
    const T = G._test;

    // Resampler.
    const resample = (inRate, blocks, blockLen, fn) => {
        const st = T.newResamplerState(640);
        const out = [];
        let t = 0;
        for (let b = 0; b < blocks; b++) {
            const block = new Float32Array(blockLen);
            for (let i = 0; i < blockLen; i++, t++) {
                block[i] = fn(t / inRate);
            }
            T.resampleBlock(st, block, inRate, 16000, (buf, rms) => out.push({buf: Int16Array.from(buf), rms}));
        }
        return out;
    };
    const sine = (sec) => 0.5 * Math.sin(2 * Math.PI * 440 * sec);
    const r24 = resample(24000, 300, 128, sine);
    // 300 blocks of 128 at 24 kHz = 1.6 s = 25600 samples at 16 kHz = 40 chunks of 640.
    check('24 kHz in: 1.6 s comes out as 40 chunks of 640 samples (16 kHz, 40 ms)', r24.length === 40,
        String(r24.length));
    const r48 = resample(48000, 600, 128, sine);
    check('48 kHz in: the same 1.6 s, the same 40 chunks', r48.length === 40, String(r48.length));
    // No seam: every 16 kHz sample is within interpolation error of the true sine at its time.
    let worst = 0;
    r24.forEach((c, ci) => c.buf.forEach((v, i) => {
        const t = (ci * 640 + i) / 16000;
        worst = Math.max(worst, Math.abs(v / 32767 - sine(t)));
    }));
    check('the resampled signal follows the source across block boundaries', worst < 0.02, worst.toFixed(4));
    check('the chunk level is the RMS of the chunk (a 0.5 sine reads about 0.35)',
        Math.abs(r24[5].rms - 0.3536) < 0.01, r24[5].rms.toFixed(4));
    const loud = resample(16000, 10, 128, () => 3);
    check('samples past full scale are clipped, not wrapped', loud[0].buf[0] === 32767);

    // The AudioWorklet copy, run as a worklet would run it.
    const posted = [];
    let Proc = null;
    // eslint-disable-next-line no-new-func
    new Function('AudioWorkletProcessor', 'registerProcessor', 'sampleRate', T.workletSource())(
        class {
            constructor() {
                this.port = {postMessage: (m) => posted.push(m)};
            }
        },
        (name, cls) => {
            Proc = name === 'sola-live-capture' ? cls : null;
        },
        24000
    );
    check('the worklet module registers sola-live-capture', Proc !== null);
    if (Proc) {
        const p = new Proc();
        for (let b = 0; b < 30; b++) {
            p.process([[new Float32Array(128).fill(0.25)]]);
        }
        // 30 x 128 at 24 kHz = 2560 samples at 16 kHz = 4 chunks.
        check('the worklet posts 40 ms PCM16 chunks with their level', posted.length === 4 &&
            posted[0].pcm.byteLength === 1280 && Math.abs(posted[0].rms - 0.25) < 0.001, String(posted.length));
        check('the worklet keeps running', p.process([[]]) === true);
    }

    // Decoding.
    const dec = T.pcm16Base64ToFloat32(pcmB64([-32768, 32767, 0, -1, 16384]));
    check('PCM16 little-endian decodes with its sign',
        dec[0] === -1 && Math.abs(dec[1] - 0.99997) < 1e-4 && dec[2] === 0 && dec[3] < 0 && dec[4] === 0.5);
    check('the rate comes from the mime type', T.rateFromMime('audio/pcm;rate=24000') === 24000 &&
        T.rateFromMime('audio/pcm;rate=16000') === 16000 && T.rateFromMime('') === 24000 &&
        T.rateFromMime('audio/pcm;rate=999999') === 24000);
    const u = T.usageCounts({promptTokenCount: 120, responseTokenCount: 30});
    check('usage counts are read, with the older candidatesTokenCount accepted',
        u.prompt === 120 && u.response === 30 && T.usageCounts({candidatesTokenCount: 7}).response === 7 &&
        T.usageCounts(null).prompt === 0);
    const ev = T.classify({serverContent: {
        modelTurn: {parts: [{inlineData: {mimeType: 'audio/pcm;rate=24000', data: 'AAA='}}, {text: 'x'}]},
        inputTranscription: {text: 'hi'}, outputTranscription: {text: 'hello'},
        interrupted: true, turnComplete: true,
    }, usageMetadata: {promptTokenCount: 5, responseTokenCount: 6}});
    check('a server message is sorted into audio, both transcripts, interruption, turn end and usage',
        ev.audio.length === 1 && ev.userText === 'hi' && ev.modelText === 'hello' && ev.interrupted &&
        ev.turnComplete && ev.usage.prompt === 5 && ev.usage.response === 6);
    check('setupComplete, goAway and junk', T.classify({setupComplete: {}}).setupComplete &&
        T.classify({goAway: {timeLeft: '5s'}}).goAway && T.classify('x').audio.length === 0);

    // A whole session against the fakes.
    const states = [];
    const transcript = [];
    const errors = [];
    let usage = null;
    let analyserSeen = null;
    const ctx = new env.Ctx();
    G.connect('auth_tokens/abc', 'ignored', 'Puck', {
        endpoint: 'wss://live.example/ws',
        model: 'gemini-3.8-live',
        sessionId: 42,
        maxSeconds: 600,
        idleSeconds: 90,
        fallbackChips: ['Tell me more'],
        onStateChange: (s) => states.push(s),
        onTranscript: (role, text) => transcript.push(role + ':' + text),
        onError: (m, info) => errors.push([m, info]),
        onAnalyser: (a) => {
            analyserSeen = a;
        },
        onUsage: (x) => {
            usage = x;
        },
    }, null, ctx, {getTracks: () => [{stop() {}}]});
    const ws = env.sockets[0];
    check('the token goes in the access_token query, URL-encoded',
        ws && ws.url === 'wss://live.example/ws?access_token=auth_tokens%2Fabc', ws && ws.url);
    check('the playback analyser is handed over for the avatar', analyserSeen !== null);
    ws.fire('open');
    const setup = ws.sent[0] && ws.sent[0].setup;
    check('the first message is the setup naming the model and asking for transcripts',
        setup && setup.model === 'models/gemini-3.8-live' && setup.inputAudioTranscription &&
        setup.outputAudioTranscription);
    ws.fire('message', {data: JSON.stringify({setupComplete: {}})});
    await tick();
    check('setupComplete moves to idle (which sends the opening text in chat.js)', states.includes('idle'));
    check('the session is connected', G.isConnected());

    const chunk = pcmB64(new Array(2400).fill(1000)); // 0.1 s at 24 kHz.
    ws.fire('message', {data: JSON.stringify({serverContent: {modelTurn: {parts: [
        {inlineData: {mimeType: 'audio/pcm;rate=24000', data: chunk}},
        {inlineData: {mimeType: 'audio/pcm;rate=24000', data: chunk}},
    ]}}, usageMetadata: {promptTokenCount: 100, responseTokenCount: 50}})});
    check('model audio plays: state speaking', states[states.length - 1] === 'speaking');
    check('two chunks are scheduled back to back with no gap', env.started.length === 2 &&
        Math.abs(env.started[1].at - (env.started[0].at + 0.1)) < 1e-9,
        env.started.map((s) => s.at).join(','));
    // Binary frames carry JSON too.
    const bin = new TextEncoder().encode(JSON.stringify({serverContent: {interrupted: true},
        usageMetadata: {promptTokenCount: 10, responseTokenCount: 5}}));
    ws.fire('message', {data: bin.buffer});
    check('barge-in: an interruption stops everything queued', env.stopped.length === 2);
    check('barge-in: state listening', states[states.length - 1] === 'listening');
    ws.fire('message', {data: JSON.stringify({serverContent: {inputTranscription: {text: 'what is osmosis'},
        outputTranscription: {text: 'Osmosis is'}}})});
    check('both sides are transcribed', transcript.includes('user:what is osmosis') &&
        transcript.includes('assistant:Osmosis is'));
    G.sendText('Give me an example');
    const last = ws.sent[ws.sent.length - 1];
    check('typed text is a complete user turn', last.clientContent && last.clientContent.turnComplete === true &&
        last.clientContent.turns[0].parts[0].text === 'Give me an example');
    G.disconnect();
    check('teardown closes the socket normally and the audio context',
        ws.closedWith === 1000 && env.closedCtx === 1 && !G.isConnected());
    check('usage is reported once with the summed tokens and the session id',
        usage && usage.sessionId === 42 && usage.promptTokens === 110 && usage.responseTokens === 55, JSON.stringify(usage));
    usage = null;
    G.disconnect();
    check('a second disconnect reports nothing again', usage === null);
    check('a clean session raises no error', errors.length === 0, JSON.stringify(errors));

    // An error before the session starts says so, so chat.js can fall back.
    const env2 = makeEnv();
    const G2 = loadAmd(src, env2.globals);
    const errs2 = [];
    let usage2 = null;
    G2.connect('auth_tokens/abc', '', '', {
        endpoint: 'wss://live.example/ws', model: 'gemini-3.8-live', sessionId: 7,
        onError: (m, info) => errs2.push(info),
        onUsage: (x) => {
            usage2 = x;
        },
    }, null, new env2.Ctx(), null);
    env2.sockets[0].fire('close', {code: 1008, reason: 'bad token'});
    check('a failure before setupComplete is flagged beforeStart', errs2.length === 1 && errs2[0].beforeStart === true);
    check('a session that never started reports 0 s, closing its row', usage2 && usage2.durationSec === 0);
};

(async() => {
    await runFor('gemini_live.js (source)', sources.src);
    await runFor('gemini_live.min.js (build)', sources.build);

    console.log('avatar_motion');
    for (const [label, src] of [['source', read('amd', 'src', 'avatar_motion.js')],
        ['build', read('amd', 'build', 'avatar_motion.min.js')]]) {
        const A = loadAmd(src, {});
        const loudSpec = new Uint8Array(128).fill(255);
        check(label + ': silence is 0, loud speech is capped at 1',
            A.levelFromSpectrum(new Uint8Array(128)) === 0 && A.levelFromSpectrum(loudSpec) === 1);
        check(label + ': the mouth opens with the level and the smile fades',
            A.mouth(0).openOpacity === '0' && A.mouth(1).openOpacity === '1' &&
            A.mouth(1).open === 'scale(1.150,1.000)' && Number(A.mouth(1).smileOpacity) < 0.1);
        check(label + ': no motion at rest', A.head(0, 1000) === '');
        let ok = true;
        for (let t = 0; t < 10000; t += 37) {
            const m = /translateY\((-?[\d.]+)px\) rotate\((-?[\d.]+)deg\)/.exec(A.head(1, t));
            if (!m || Math.abs(+m[1]) > A.MAX_LIFT + 1e-9 || Math.abs(+m[2]) > A.MAX_TILT + 1e-9) {
                ok = false;
            }
        }
        check(label + ': head motion stays within 1.5 px and 2 degrees', ok);
        check(label + ': the mouth opens faster than it closes',
            A.smooth(0, 1) > 0.5 && A.smooth(1, 0) > 0.5);
    }

    console.log('wiring');
    const chatSrc = read('amd', 'src', 'chat.js');
    const chatMin = read('amd', 'build', 'chat.min.js');
    check('chat.js loads gemini_live and picks it for provider gemini',
        chatSrc.includes("'local_ai_course_assistant/gemini_live'") &&
        /isGemini\s*=\s*result\.provider\s*===\s*'gemini'/.test(chatSrc));
    check('chat.min.js carries the same', chatMin.includes('"local_ai_course_assistant/gemini_live"') &&
        chatMin.includes('recordLiveUsage') && chatMin.includes('realtime:err_gemini_daily_cap'));
    check('repository.min.js has the usage call',
        read('amd', 'build', 'repository.min.js').includes('local_ai_course_assistant_record_live_usage'));
    const uiMin = read('amd', 'build', 'ui.min.js');
    check('ui.js and its build drive the avatar through avatar_motion',
        read('amd', 'src', 'ui.js').includes("'local_ai_course_assistant/avatar_motion'") &&
        uiMin.includes('"local_ai_course_assistant/avatar_motion"'));
    check('the generated avatar SVG is hidden from screen readers',
        uiMin.includes('aria-hidden="true" focusable="false"'));
    check('gemini_live.js uses an AudioWorklet, never a ScriptProcessor',
        sources.src.includes('AudioWorkletNode') && !sources.src.includes('createScriptProcessor'));

    console.log(failures ? '\n' + failures + ' FAILED' : '\nall passed');
    process.exit(failures ? 1 : 0);
})();
