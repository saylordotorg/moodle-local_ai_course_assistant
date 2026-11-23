/**
 * Voice silence limits and transcript markers (8.3.1), checked with no browser and no network.
 *
 * Covers, against BOTH amd/src and the built amd/build bundle (Moodle serves the build):
 *   - Gemini Live: the 5 second default, the limit counting from when the model stops talking (not from when
 *     it started), speech and a pending answer holding it off, one usage report, Resume starting a new session;
 *   - OpenAI/xAI realtime (realtime.js): the same limit after the opening greeting;
 *   - the regular listen/think/speak mode (voice.js): the mic stops after the limit and Resume opens it again;
 *   - citation and source markers ([[c:1]], [SOURCE:activity:522]) never reach any transcript, however the
 *     reply is split into chunks, and are never spoken;
 *   - chat.js and ui.js wiring in source and build.
 *
 * What it can't check: a real microphone, real audio, a real provider. Those need a person on dev.
 *
 * Run: node tests/js/voice-idle-check.js
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

const loadAmd = (src, globals, deps) => {
    let mod = null;
    const names = Object.keys(globals || {});
    // eslint-disable-next-line no-new-func
    new Function('define', ...names, src)((a, b) => {
        mod = typeof a === 'function' ? a() : b.apply(null, deps || []);
    }, ...names.map((n) => globals[n]));
    return mod;
};

// ---- A clock the tests move by hand -------------------------------------------------------------
const makeClock = () => {
    let t = 1000000;
    let nextId = 0;
    let timers = [];
    const clock = {
        now: () => t,
        setTimeout: (fn, ms) => {
            timers.push({id: ++nextId, fn, at: t + ms, every: 0});
            return nextId;
        },
        setInterval: (fn, ms) => {
            timers.push({id: ++nextId, fn, at: t + ms, every: ms});
            return nextId;
        },
        clear: (id) => {
            timers = timers.filter((x) => x.id !== id);
        },
        advance: (ms) => {
            const end = t + ms;
            for (;;) {
                const due = timers.filter((x) => x.at <= end).sort((a, b) => a.at - b.at)[0];
                if (!due) {
                    break;
                }
                t = due.at;
                if (due.every) {
                    due.at += due.every;
                } else {
                    timers = timers.filter((x) => x.id !== due.id);
                }
                due.fn();
            }
            t = end;
        },
    };
    return clock;
};

const tick = () => new Promise((r) => setImmediate(r));
const pcmB64 = (samples) => {
    const b = Buffer.alloc(samples.length * 2);
    samples.forEach((v, i) => b.writeInt16LE(v, i * 2));
    return b.toString('base64');
};

// ---- Fakes shared by the Gemini and OpenAI tests ------------------------------------------------
const makeEnv = (clock) => {
    const env = {sockets: [], started: [], stopped: [], closedCtx: 0};
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
            this.sampleRate = 24000;
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
            return {duration: len / rate, rate: rate, copyToChannel() {}, getChannelData: () => new Float32Array(len)};
        }
        createBufferSource() {
            return Object.assign(node(), {
                start(at) {
                    this.at = at;
                    env.started.push(this);
                },
                stop() {
                    env.stopped.push(this);
                },
            });
        }
        createMediaStreamSource() {
            return node();
        }
        createScriptProcessor() {
            return Object.assign(node(), {onaudioprocess: null});
        }
        close() {
            env.closedCtx += 1;
            return Promise.resolve();
        }
        resume() {
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
        requestAnimationFrame: () => 1,
        cancelAnimationFrame() {},
        navigator: {},
        TextDecoder: TextDecoder,
        atob: (s) => Buffer.from(s, 'base64').toString('binary'),
        btoa: (s) => Buffer.from(s, 'binary').toString('base64'),
        Date: {now: clock.now},
        setTimeout: clock.setTimeout,
        setInterval: clock.setInterval,
        clearTimeout: clock.clear,
        clearInterval: clock.clear,
    };
    env.Ctx = FakeCtx;
    return env;
};

const stream = () => ({getTracks: () => [{stop() {}}]});
const REPLY = 'Starting is exciting! The first step is a self-assessment [[c:1]]. Next write a plan [[c:1]]. ' +
    'Identify a need [[c:2]]. [SOURCE:activity:522]';
const REPLY_CLEAN = 'Starting is exciting! The first step is a self-assessment. Next write a plan. Identify a need.';

// ---- Gemini Live ---------------------------------------------------------------------------------
const geminiRun = async(label, src) => {
    console.log(label);
    const start = (opts) => {
        const clock = makeClock();
        const env = makeEnv(clock);
        const G = loadAmd(src, env.globals);
        const seen = {idleStops: 0, usage: [], transcript: [], states: []};
        const connect = () => {
            G.connect('auth_tokens/x', '', 'Kore', Object.assign({
                endpoint: 'wss://live.example/ws', model: 'gemini-3.8-live', sessionId: 9, maxSeconds: 600,
                onStateChange: (s) => seen.states.push(s),
                onTranscript: (r, t) => seen.transcript.push(r + ':' + t),
                onUsage: (u) => seen.usage.push(u),
                onIdleStop: () => {
                    seen.idleStops += 1;
                },
            }, opts || {}), null, new env.Ctx(), stream());
            const ws = env.sockets[env.sockets.length - 1];
            ws.fire('open');
            ws.fire('message', {data: JSON.stringify({setupComplete: {}})});
            return ws;
        };
        return {clock, env, G, seen, connect};
    };
    const chunk = pcmB64(new Array(2400).fill(1000));
    const audioMsg = (n) => JSON.stringify({serverContent: {modelTurn: {parts:
        new Array(n).fill({inlineData: {mimeType: 'audio/pcm;rate=24000', data: chunk}})}}});

    // 1. The default is 5 seconds and the session stops, once, with usage reported once.
    let t = start();
    let ws = t.connect();
    await tick();
    t.clock.advance(4600);
    check('default limit: still listening after 4.6 s of silence', t.seen.idleStops === 0 && t.G.isConnected());
    t.clock.advance(1000);
    check('default limit: stops after 5 s of silence, telling the owner once',
        t.seen.idleStops === 1 && !t.G.isConnected(), String(t.seen.idleStops));
    check('the socket is closed normally and usage is reported once, closing the spend row',
        ws.closedWith === 1000 && t.seen.usage.length === 1 && t.seen.usage[0].sessionId === 9 &&
        t.seen.usage[0].durationSec >= 5 && t.seen.usage[0].durationSec <= 6, JSON.stringify(t.seen.usage));
    t.clock.advance(60000);
    check('nothing fires again after the stop', t.seen.idleStops === 1 && t.seen.usage.length === 1);

    // 2. Speech holds it off.
    t = start();
    ws = t.connect();
    await tick();
    t.clock.advance(4000);
    ws.fire('message', {data: JSON.stringify({serverContent: {inputTranscription: {text: 'what is osmosis'}}})});
    t.clock.advance(4800);
    check('a learner turn restarts the count', t.seen.idleStops === 0);

    // 3. The count starts when the model STOPS talking (the bug a 5 s limit would have exposed: the clock ran from
    //    the last audio chunk's arrival, so a long answer ended the session the moment it finished).
    t = start();
    ws = t.connect();
    await tick();
    ws.fire('message', {data: audioMsg(2)});
    // As in real life the server finishes its turn long before the audio finishes playing.
    ws.fire('message', {data: JSON.stringify({serverContent: {turnComplete: true}})});
    t.clock.advance(30000);
    check('while the model is talking nobody is idle, however long it talks',
        t.seen.idleStops === 0 && t.G.isConnected());
    t.env.started.forEach((s) => s.onended && s.onended());
    t.clock.advance(4500);
    check('the silence count starts when the model stops: still listening 4.5 s later', t.seen.idleStops === 0);
    t.clock.advance(1000);
    check('and the session stops 5 s after the model stopped', t.seen.idleStops === 1);

    // 4. A typed turn waits for the answer, but not forever.
    t = start();
    ws = t.connect();
    await tick();
    t.G.sendText('Give me an example');
    t.clock.advance(15000);
    check('waiting for the model to answer is not idle (15 s)', t.seen.idleStops === 0);
    t.clock.advance(11000);
    check('but a model that never answers cannot hold the session open: the wait ends at 20 s and the 5 s count follows',
        t.seen.idleStops === 1, String(t.seen.idleStops));

    // 5. A configured limit is used.
    t = start({idleSeconds: 12});
    ws = t.connect();
    await tick();
    t.clock.advance(11000);
    check('a configured 12 s limit is respected (alive at 11 s)', t.seen.idleStops === 0);
    t.clock.advance(2000);
    check('a configured 12 s limit is respected (stopped by 13 s)', t.seen.idleStops === 1);

    // 6. Without an owner callback the old note is shown, and the session still ends.
    t = start({onIdleStop: null});
    ws = t.connect();
    await tick();
    t.clock.advance(6000);
    check('no owner callback: the end-of-session note is shown and the session ends',
        !t.G.isConnected() && t.seen.transcript.some((x) => x.includes('nobody spoke')));

    // 7. Resume is a new session on the same module.
    t = start();
    ws = t.connect();
    await tick();
    t.clock.advance(6000);
    const ws2 = t.connect();
    await tick();
    check('Resume: connecting again opens a new socket and a live session',
        t.env.sockets.length === 2 && ws2 !== ws && t.G.isConnected());
    t.clock.advance(4000);
    check('Resume: the new session gets its own full silence window', t.seen.idleStops === 1);
    t.clock.advance(1500);
    check('Resume: and stops by itself again', t.seen.idleStops === 2);

    // 8. Markers never reach the transcript, wherever the chunks split.
    let leaks = 0;
    for (let k = 1; k < REPLY.length; k++) {
        t = start();
        ws = t.connect();
        await tick();
        [REPLY.slice(0, k), REPLY.slice(k)].forEach((part) => {
            ws.fire('message', {data: JSON.stringify({serverContent: {outputTranscription: {text: part}}})});
        });
        const text = t.seen.transcript.filter((x) => x.startsWith('assistant:')).map((x) => x.slice(10)).join('');
        if (/[[\]]/.test(text) || text !== REPLY_CLEAN) {
            leaks += 1;
        }
        t.G.disconnect();
    }
    check('markers never show in the transcript, at any split point of a reply', leaks === 0, leaks + ' splits leaked');
};

// ---- OpenAI / xAI realtime ----------------------------------------------------------------------
const realtimeRun = async(label, src) => {
    console.log(label);
    const clock = makeClock();
    const env = makeEnv(clock);
    const R = loadAmd(src, env.globals);
    const seen = {idleStops: 0, transcript: [], states: []};
    const ctx = new env.Ctx();
    R.connect('tok', '', 'shimmer', {
        endpoint: 'wss://api.example/v1/realtime?model=m', provider: 'openai', idleSeconds: 10,
        onStateChange: (s) => seen.states.push(s),
        onTranscript: (r, x) => seen.transcript.push(r + ':' + x),
        onIdleStop: () => {
            seen.idleStops += 1;
        },
    }, null, ctx, stream());
    const ws = env.sockets[0];
    ws.fire('open');
    ws.fire('message', {data: JSON.stringify({type: 'session.created'})});
    ws.fire('message', {data: JSON.stringify({type: 'session.updated'})});
    await tick();
    clock.advance(60000);
    check('the opening greeting does not count as silence', seen.idleStops === 0 && R.isConnected());
    ws.fire('message', {data: JSON.stringify({type: 'response.output_audio.delta', delta: pcmB64(new Array(2400).fill(1000))})});
    clock.advance(40000);
    check('while the model is answering nobody is idle', seen.idleStops === 0);
    ws.fire('message', {data: JSON.stringify({type: 'response.output_audio.done'})});
    ws.fire('message', {data: JSON.stringify({type: 'response.done'})});
    env.started.forEach((s) => s.onended && s.onended());
    clock.advance(9000);
    check('the 10 s count starts when the answer ends: alive at 9 s', seen.idleStops === 0);
    clock.advance(2500);
    check('realtime stops after the configured 10 s of silence, telling the owner once',
        seen.idleStops === 1 && !R.isConnected(), String(seen.idleStops));
    check('the socket is closed', ws.readyState === 3);

    // A socket the server closed has nothing left to stop for silence.
    const clock3 = makeClock();
    const env3 = makeEnv(clock3);
    const R3 = loadAmd(src, env3.globals);
    let stops3 = 0;
    R3.connect('tok', '', 'shimmer', {endpoint: 'wss://api.example/v1/realtime?model=m', provider: 'openai',
        idleSeconds: 10, onIdleStop: () => {
            stops3 += 1;
        }}, null, new env3.Ctx(), stream());
    const ws3 = env3.sockets[0];
    ws3.fire('open');
    ws3.fire('message', {data: JSON.stringify({type: 'session.created'})});
    ws3.fire('message', {data: JSON.stringify({type: 'session.updated'})});
    ws3.fire('message', {data: JSON.stringify({type: 'response.done'})});
    ws3.readyState = 3;
    ws3.fire('close', {code: 1006});
    clock3.advance(60000);
    check('a socket the server closed never reports a silence stop afterwards', stops3 === 0, String(stops3));

    // Markers in the spoken transcript.
    const env2 = makeEnv(makeClock());
    const R2 = loadAmd(src, env2.globals);
    const seen2 = [];
    R2.connect('tok', '', 'shimmer', {endpoint: 'wss://api.example/v1/realtime?model=m', provider: 'openai',
        onTranscript: (r, x) => seen2.push(r + ':' + x)}, null, new env2.Ctx(), stream());
    const ws2 = env2.sockets[0];
    ws2.fire('open');
    ws2.fire('message', {data: JSON.stringify({type: 'session.created'})});
    ws2.fire('message', {data: JSON.stringify({type: 'session.updated'})});
    ws2.fire('message', {data: JSON.stringify({type: 'response.done'})});
    [REPLY.slice(0, 40), REPLY.slice(40, 75), REPLY.slice(75)].forEach((part) => {
        ws2.fire('message', {data: JSON.stringify({type: 'response.output_audio_transcript.delta', delta: part})});
    });
    const shown = seen2.filter((x) => x.startsWith('assistant:')).map((x) => x.slice(10)).join('');
    check('realtime transcripts never show [[c:N]] or [SOURCE] markers', shown === REPLY_CLEAN, JSON.stringify(shown));
};

// ---- The regular listen / think / speak mode -----------------------------------------------------
const voiceRun = async(label, src) => {
    console.log(label);
    const clock = makeClock();
    const recs = [];
    class FakeRec {
        constructor() {
            this.started = 0;
            this.aborted = 0;
            recs.push(this);
        }
        start() {
            this.started += 1;
            if (this.onstart) {
                this.onstart();
            }
        }
        stop() {}
        abort() {
            this.aborted += 1;
        }
    }
    class FakeCtx {
        constructor() {
            this.state = 'running';
        }
        close() {
            return Promise.resolve();
        }
        resume() {
            return Promise.resolve();
        }
        createAnalyser() {
            return {connect() {}, disconnect() {}};
        }
    }
    const globals = {
        window: {SpeechRecognition: FakeRec, AudioContext: FakeCtx},
        navigator: {mediaDevices: {getUserMedia: () => Promise.resolve(stream())}},
        console: console,
        document: {querySelector: () => null},
        setTimeout: clock.setTimeout,
        clearTimeout: clock.clear,
        setInterval: clock.setInterval,
        clearInterval: clock.clear,
        requestAnimationFrame: () => 1,
        cancelAnimationFrame() {},
        fetch: () => Promise.reject(new Error('no network')),
        AbortController: AbortController,
    };
    const V = loadAmd(src, globals, [{startStream: () => ({abort() {}})}]);
    const states = [];
    let idleStops = 0;
    V.connect('', 'shimmer', {
        onStateChange: (s) => states.push(s),
        onTranscript() {},
        onIdleStop: () => {
            idleStops += 1;
        },
    }, {courseId: 1, sessKey: 'k', sseUrl: 'https://x.example/local/ai_course_assistant/sse.php', idleSeconds: 10});
    await tick();
    await tick();
    check('the mic opens and the state is listening', states[states.length - 1] === 'listening', states.join(','));
    clock.advance(9500);
    check('regular voice: still listening after 9.5 s of silence', idleStops === 0);
    recs[0].onresult({resultIndex: 0, results: [{isFinal: false, 0: {transcript: 'um'}}]});
    clock.advance(9500);
    check('speech (even a partial result) restarts the count', idleStops === 0);
    clock.advance(1000);
    check('regular voice: stops after 10 s with nobody speaking, once', idleStops === 1 && V.isConnected(), String(idleStops));
    check('the mic is closed and the state goes quiet', recs[0].aborted === 1 && states[states.length - 1] === 'idle');
    clock.advance(60000);
    check('nothing restarts the mic by itself', recs.length === 1 && idleStops === 1);
    V.resumeListening();
    clock.advance(100);
    check('Resume opens the mic again (a new recognition starts)',
        recs.length === 1 ? recs[0].started >= 2 : recs.length === 2, 'recs=' + recs.length + ' started=' + recs[0].started);
    check('and listening again', states[states.length - 1] === 'listening');
    clock.advance(10500);
    check('and the silence limit applies to the resumed listening', idleStops === 2);
    V.disconnect();

    // Markers: never shown, never spoken, at every split point.
    const T = V._test;
    let leaks = 0;
    for (let k = 1; k < REPLY.length; k++) {
        const f = T.makeDisplayFilter();
        const out = f.push(REPLY.slice(0, k)) + f.push(REPLY.slice(k));
        if (out !== REPLY_CLEAN) {
            leaks += 1;
        }
    }
    check('voice transcript: markers never show, at any split point', leaks === 0, leaks + ' splits leaked');
    const f2 = T.makeDisplayFilter();
    let streamed = '';
    for (const ch of REPLY) {
        streamed += f2.push(ch);
    }
    check('voice transcript: one character at a time gives the same clean text', streamed === REPLY_CLEAN, streamed);
    const g = T.makeDisplayFilter();
    const withNext = g.push('Hello there.') + g.push(' [SOLA_NEXT]a||b[/SOLA_NEXT]');
    check('the suggestion block is still kept out of the transcript', withNext === 'Hello there.', withNext);
    check('a free-form source label is stripped whole',
        T.stripMarkers('See unit one. [SOURCE:Unit 1: Computer Programming]') === 'See unit one.');
    check('a double-bracket source tag is stripped whole', T.stripMarkers('Yes. [[SOURCE:activity:86467]]') === 'Yes.');
    check('a partly arrived marker is detected so it is neither shown nor spoken',
        T.markerHoldStart('Yes. [[c:') === 5 && T.markerHoldStart('Yes. [SOURCE:Unit 1: Comp') === 5 &&
        T.markerHoldStart('Yes. [[c:1]]') === -1 && T.markerHoldStart('Yes [link') === -1 &&
        T.markerHoldStart('no brackets') === -1);
    check('ordinary brackets are left alone', T.stripMarkers('Use [a] and [[b]] here') === 'Use [a] and [[b]] here');
};

(async() => {
    await geminiRun('gemini_live.js (source)', read('amd', 'src', 'gemini_live.js'));
    await geminiRun('gemini_live.min.js (build)', read('amd', 'build', 'gemini_live.min.js'));
    await realtimeRun('realtime.js (source)', read('amd', 'src', 'realtime.js'));
    await realtimeRun('realtime.min.js (build)', read('amd', 'build', 'realtime.min.js'));
    await voiceRun('voice.js (source)', read('amd', 'src', 'voice.js'));
    await voiceRun('voice.min.js (build)', read('amd', 'build', 'voice.min.js'));

    console.log('wiring');
    const chatSrc = read('amd', 'src', 'chat.js');
    const chatMin = read('amd', 'build', 'chat.min.js');
    const uiSrc = read('amd', 'src', 'ui.js');
    const uiMin = read('amd', 'build', 'ui.min.js');
    check('chat.js offers Resume for the live, realtime and regular voice modes',
        (chatSrc.match(/onIdleStop:/g) || []).length === 2 && chatSrc.includes('Voice.resumeListening()') &&
        chatSrc.includes('const offerVoiceResume'));
    check('chat.js keeps the overlay while stopped for silence and does not repeat the opening prompt',
        chatSrc.includes('stoppedForSilence') && chatSrc.includes('resumingAfterSilence'));
    check('chat.js reads the silence limit and the Resume words from the widget root',
        chatSrc.includes('voiceidleseconds') && chatSrc.includes('voiceidlestopped') &&
        chatSrc.includes('voiceresumearia'));
    check('chat.min.js carries the same', chatMin.includes('resumeListening') && chatMin.includes('voiceidleseconds') &&
        chatMin.includes('voiceidlestopped') && chatMin.includes('voiceresumearia'));
    check('the Resume button has an aria-label, in source and build',
        uiSrc.includes("btn.setAttribute('aria-label'") && uiMin.includes('aria-label'));
    check('the widget template carries the limit and the words',
        read('templates', 'chat_widget.mustache').includes('data-voiceidleseconds="{{voiceidleseconds}}"') &&
        read('templates', 'chat_widget.mustache').includes('data-voiceresumearia="{{voiceresumearia}}"'));

    console.log(failures ? '\n' + failures + ' FAILED' : '\nall passed');
    process.exit(failures ? 1 : 0);
})();
