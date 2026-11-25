/**
 * Answer safety for live voice (8.4.0 red team, RT3), checked with no browser, no audio and no network.
 *
 * Live voice (Gemini Live, OpenAI and xAI realtime) runs browser to provider. While the course's answer safety mode
 * is Watch or Enforce the browser posts each finished turn to check_voice_turn and acts on the verdict. This check
 * loads the REAL realtime.js, gemini_live.js and voice_safety.js, from amd/src AND from amd/build (Moodle serves
 * the build), around a fake socket, a fake audio context and a fake server, and pins:
 *
 *   - with no ticket (mode Off) there is no tracker: nothing is collected, nothing is posted, and the engines
 *     behave as before;
 *   - each finished assistant turn is posted once, numbered from 1, with the learner's words and the answer as the
 *     transcript shows it (no citation, source or suggestion markers); the opening greeting is not a turn;
 *   - a block stops THAT turn's audio at once and hands the safe message over once, as plain text; a verdict that
 *     arrives after the learner has moved on never stops the later answer's audio;
 *   - a warning hands its line over and leaves the audio alone; a failed or empty reply changes nothing;
 *   - a learner transcript that arrives after the answer finished still goes with that turn (up to 1.5 s); words
 *     spoken over an answer go with the next turn; a Gemini turn cut off by the learner is posted once;
 *   - when the session ends (closed, or stopped for silence) a waiting turn is sent, and words no answer followed
 *     are posted alone as the next turn, once; nothing is taken after that;
 *   - the safe message is spoken once through tts.php, or by the browser's own voice when that fails;
 *   - each answer's transcript is handed over with its turn number (the greeting is 0), and chat.js tags the line
 *     with it, so a block or warning changes that turn's line even when a later turn is on screen, and a verdict
 *     that comes back after the session ended still replaces the line on screen (existing lines only, no audio);
 *   - chat.js and repository.js wiring, in source and build, including the Enforce refusal turning into the
 *     regular voice mode.
 *
 * A planted-defect run at the end breaks a copy of the source in twenty-seven ways and requires the checks to fail each time.
 *
 * What it can't check: a real provider, real audio, how much of an answer is heard before the verdict arrives,
 * and whether the provider's transcript is accurate. The talking avatar speaks inside the vendor's frame and is
 * not covered by any of this.
 *
 * Run: node tests/js/voice-safety-check.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (...p) => fs.readFileSync(path.join(root, ...p), 'utf8');

const loadAmd = (src, globals, deps) => {
    let mod = null;
    const names = Object.keys(globals || {});
    // eslint-disable-next-line no-new-func
    new Function('define', ...names, src)((a, b) => {
        mod = typeof a === 'function' ? a() : b.apply(null, deps || []);
    }, ...names.map((n) => globals[n]));
    return mod;
};

const tick = () => new Promise((r) => setImmediate(r));
const settle = async() => {
    for (let i = 0; i < 6; i++) {
        await tick();
    }
};
const pcmB64 = (samples) => {
    const b = Buffer.alloc(samples.length * 2);
    samples.forEach((v, i) => b.writeInt16LE(v, i * 2));
    return b.toString('base64');
};
const CHUNK = pcmB64(new Array(2400).fill(1000));

const makeClock = () => {
    let t = 1000000;
    let nextId = 0;
    let timers = [];
    return {
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
};

const makeEnv = (clock) => {
    const env = {sockets: [], started: [], stopped: []};
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
        msg(obj) {
            this.fire('message', {data: JSON.stringify(obj)});
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
            return {duration: len / rate, copyToChannel() {}, getChannelData: () => new Float32Array(len)};
        }
        createBufferSource() {
            return Object.assign(node(), {
                start() {
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
        decodeAudioData(buf, ok) {
            ok({decoded: true});
        }
        close() {
            return Promise.resolve();
        }
        resume() {
            return Promise.resolve();
        }
    }
    env.globals = {
        WebSocket: FakeWS,
        window: {AudioContext: FakeCtx},
        AudioWorkletNode: class {
            constructor() {
                this.port = {};
            }
            connect() {}
            disconnect() {}
        },
        URL: {createObjectURL: () => 'blob:x', revokeObjectURL() {}},
        Blob: function Blob() {},
        requestAnimationFrame: () => 1,
        cancelAnimationFrame() {},
        navigator: {},
        document: {getElementById: () => null},
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

const micStream = () => ({getTracks: () => [{stop() {}}]});
const SAFE = 'I can\'t confirm dates or grant extensions. Please check the course schedule or ask your instructor.';
const BAD = 'Sure, I\'ve granted you a one-week extension [[c:1]] on the Unit 3 assignment. [SOURCE:activity:522]';
const BAD_CLEAN = 'Sure, I\'ve granted you a one-week extension on the Unit 3 assignment.';

/**
 * A live session on a real engine with the real tracker and a fake server.
 *
 * @param {string} kind 'realtime' or 'gemini'
 * @param {Object} s the sources
 * @param {Object} o {ticket, noTracker}
 * @returns {Object}
 */
const session = (kind, s, o) => {
    o = o || {};
    const clock = makeClock();
    const env = makeEnv(clock);
    const engine = loadAmd(kind === 'gemini' ? s.gemini : s.realtime, env.globals);
    const VS = loadAmd(s.safety, env.globals);
    const t = {clock, env, engine, VS, posts: [], blocks: [], warns: [], states: [], shown: [], shownTurns: [],
        verdicts: []};
    // Each post gets a verdict the test resolves by hand.
    t.tracker = VS.create({
        ticket: o.ticket === undefined ? 'TICKET' : o.ticket,
        post: (turn) => {
            t.posts.push(turn);
            return new Promise((resolve, reject) => {
                t.verdicts.push({resolve, reject});
            });
        },
        stop: (n) => engine.stopSpeaking(n),
        onBlock: (text, n) => t.blocks.push([text, n]),
        onWarn: (text, n) => t.warns.push([text, n]),
    });
    const callbacks = {
        endpoint: 'wss://live.example/ws', provider: 'openai', model: 'gemini-3.8-live', sessionId: 9,
        maxSeconds: 600, idleSeconds: 600,
        onStateChange: (st) => t.states.push(st),
        onTranscript: (r, x, n) => {
            t.shown.push(r + ':' + x);
            t.shownTurns.push([r, n]);
        },
        onTurnText: t.tracker ? t.tracker.text : null,
        onTurnEnd: t.tracker ? t.tracker.turnEnd : null,
    };
    engine.connect('tok', '', 'shimmer', callbacks, null, new env.Ctx(), micStream());
    const ws = env.sockets[0];
    t.ws = ws;
    ws.fire('open');
    if (kind === 'gemini') {
        ws.msg({setupComplete: {}});
        t.user = (text) => ws.msg({serverContent: {inputTranscription: {text}}});
        t.answer = (text) => {
            ws.msg({serverContent: {modelTurn: {parts: [{inlineData: {mimeType: 'audio/pcm;rate=24000', data: CHUNK}}]}}});
            ws.msg({serverContent: {outputTranscription: {text}}});
        };
        t.done = () => ws.msg({serverContent: {turnComplete: true}});
        t.interrupt = () => ws.msg({serverContent: {interrupted: true}});
    } else {
        ws.msg({type: 'session.created'});
        ws.msg({type: 'session.updated'});
        // The opening greeting: spoken, shown, never a turn.
        ws.msg({type: 'response.output_audio.delta', delta: CHUNK});
        ws.msg({type: 'response.output_audio_transcript.delta', delta: 'Hi! How can I help today?'});
        ws.msg({type: 'response.output_audio.done'});
        ws.msg({type: 'response.done'});
        t.user = (text) => ws.msg({type: 'conversation.item.input_audio_transcription.completed', transcript: text});
        t.answer = (text) => {
            ws.msg({type: 'response.output_audio.delta', delta: CHUNK});
            ws.msg({type: 'response.output_audio_transcript.delta', delta: text.slice(0, 20)});
            ws.msg({type: 'response.output_audio_transcript.delta', delta: text.slice(20)});
            ws.msg({type: 'response.output_audio.done'});
        };
        t.done = () => ws.msg({type: 'response.done'});
    }
    t.greetingAudio = env.started.length;
    return t;
};

const engineChecks = async(kind, label, s, check) => {
    console.log(label);
    // 1. Mode Off: no ticket, no tracker, nothing collected or posted; the engine works as before.
    let t = session(kind, s, {ticket: ''});
    check('no ticket: no tracker is made', t.tracker === null);
    t.user('Can I get an extension?');
    t.answer(BAD);
    t.done();
    t.clock.advance(5000);
    await settle();
    check('no ticket: nothing is posted and the transcript still shows',
        t.posts.length === 0 && t.shown.some((x) => x.startsWith('assistant:')) && t.engine.isConnected());
    check('the engine has stopSpeaking, and a turn that is not playing stops nothing',
        typeof t.engine.stopSpeaking === 'function' && t.engine.stopSpeaking(99) === false);

    // 2. One finished turn, posted once, clean, numbered 1; the greeting is not a turn.
    t = session(kind, s);
    await settle();
    check('the opening greeting (or the bare connection) posts nothing', t.posts.length === 0, JSON.stringify(t.posts));
    t.user('Can I get an extension on unit 3?');
    t.answer(BAD);
    check('nothing is posted before the turn is over', t.posts.length === 0);
    t.done();
    await settle();
    check('the finished turn is posted once, as turn 1, with the ticket', t.posts.length === 1 && t.posts[0].turn === 1
        && t.posts[0].ticket === 'TICKET', JSON.stringify(t.posts));
    check('with the learner\'s words and the answer as the transcript shows it (no markers)',
        t.posts[0].question === 'Can I get an extension on unit 3?' && t.posts[0].answer === BAD_CLEAN,
        JSON.stringify(t.posts[0]));
    check('and no greeting text in it', t.posts[0].answer.indexOf('How can I help') === -1);

    // 3. Block: that turn's audio stops at once, the safe message is handed over once, as plain text.
    const playingBefore = t.env.stopped.length;
    check('the audio is still playing while the verdict is on its way', t.env.started.length > t.greetingAudio
        && t.env.stopped.length === playingBefore);
    t.verdicts[0].resolve({recorded: true, action: 'block', text: SAFE, wellbeing: false, reason: 'ok'});
    await settle();
    check('block: the turn\'s audio is stopped', t.env.stopped.length > playingBefore,
        t.env.stopped.length + ' stopped');
    check('block: the safe message is handed over exactly once', t.blocks.length === 1 && t.blocks[0][0] === SAFE
        && t.blocks[0][1] === 1, JSON.stringify(t.blocks));
    check('block: the engine is no longer speaking', t.states[t.states.length - 1] !== 'speaking',
        t.states.slice(-3).join(','));
    check('block: the session itself goes on', t.engine.isConnected());

    // 4. A verdict that arrives late never stops a later answer.
    t = session(kind, s);
    t.user('Can I get an extension on unit 3?');
    t.answer(BAD);
    t.done();
    await settle();
    t.env.started.forEach((src) => src.onended && src.onended());
    t.user('OK. What is osmosis?');
    t.answer('Osmosis is the movement of water across a membrane.');
    const stoppedBefore = t.env.stopped.length;
    t.verdicts[0].resolve({recorded: true, action: 'block', text: SAFE});
    await settle();
    check('a late block does not stop the next answer\'s audio', t.env.stopped.length === stoppedBefore,
        (t.env.stopped.length - stoppedBefore) + ' stopped');
    check('but the safe message is still handed over', t.blocks.length === 1);
    t.done();
    await settle();
    check('the next turn is posted as turn 2 with its own question', t.posts.length === 2 && t.posts[1].turn === 2
        && t.posts[1].question === 'OK. What is osmosis?', JSON.stringify(t.posts[1]));
    // Each answer's transcript is handed over with the turn it belongs to, the number its verdict will carry, so
    // the verdict for turn 1 can find turn 1's line while turn 2 is on screen (the greeting is turn 0).
    const said = t.shownTurns.filter((x) => x[0] === 'assistant' && x[1]).map((x) => x[1]);
    check('assistant transcript carries its turn number (1 then 2)', said.length >= 2 && said[0] === 1
        && said[said.length - 1] === 2 && said.every((n, i) => i === 0 || n >= said[i - 1]), JSON.stringify(said));
    check('the turn number on the transcript matches the posted turn', t.posts.every((p) => said.includes(p.turn)));
    if (kind === 'realtime') {
        const greet = t.shownTurns.find((x) => x[0] === 'assistant');
        check('the greeting is shown as turn 0, not turn 1', greet && greet[1] === 0, JSON.stringify(greet));
    }

    // 5. Warn: the line is handed over without its markdown wrapper; the audio is left alone.
    t = session(kind, s);
    t.user('My landlord kept my deposit.');
    t.answer('You should sue your landlord right away.');
    t.done();
    await settle();
    const stoppedW = t.env.stopped.length;
    t.verdicts[0].resolve({recorded: true, action: 'warn', text: '_Please check this with your instructor._'});
    await settle();
    check('warn: the check line is handed over as plain text', t.warns.length === 1
        && t.warns[0][0] === 'Please check this with your instructor.', JSON.stringify(t.warns));
    check('warn: the audio is not interrupted and nothing is blocked', t.env.stopped.length === stoppedW
        && t.blocks.length === 0);

    // 6. Nothing to do, an empty reply, or a failed post: nothing happens.
    t = session(kind, s);
    for (let i = 0; i < 3; i++) {
        t.user('Question ' + i);
        t.answer('An ordinary answer.');
        t.done();
        await settle();
        t.env.started.forEach((src) => src.onended && src.onended());
    }
    const stoppedN = t.env.stopped.length;
    t.verdicts[0].resolve({recorded: true, action: 'none', text: ''});
    t.verdicts[1].resolve(null);
    t.verdicts[2].reject(new Error('network'));
    await settle();
    check('none, an empty reply and a failed post change nothing', t.env.stopped.length === stoppedN
        && t.blocks.length === 0 && t.warns.length === 0 && t.engine.isConnected());
    check('three turns were posted as 1, 2, 3', t.posts.map((p) => p.turn).join(',') === '1,2,3');

    // 7. The learner's transcript arrives after the answer finished: the turn waits for it (up to 1.5 s).
    t = session(kind, s);
    t.answer('Your extension has been approved.');
    t.done();
    await settle();
    check('a turn with no question yet is not posted at once', t.posts.length === 0);
    t.clock.advance(800);
    t.user('Did my extension go through?');
    t.clock.advance(800);
    await settle();
    check('the late transcript goes with its turn', t.posts.length === 1
        && t.posts[0].question === 'Did my extension go through?', JSON.stringify(t.posts));
    // And when none ever comes, the turn is posted without one.
    t = session(kind, s);
    t.answer('Your extension has been approved.');
    t.done();
    t.clock.advance(1400);
    await settle();
    check('still waiting at 1.4 s', t.posts.length === 0);
    t.clock.advance(200);
    await settle();
    check('posted without a question after 1.5 s', t.posts.length === 1 && t.posts[0].question === ''
        && t.posts[0].answer === 'Your extension has been approved.', JSON.stringify(t.posts));
    // A waiting turn is not lost when the next one ends first, or the session ends.
    t = session(kind, s);
    t.answer('First answer.');
    t.done();
    t.tracker.flush();
    await settle();
    check('flush sends a turn that was still waiting (the session is ending)', t.posts.length === 1);

    // 7b. Words that come more than 1.5 s after the last answer, and then the session ends: they are posted alone
    // when it ends (a distress statement at the end of a session must not go unrecorded).
    t = session(kind, s);
    t.user('Can I get an extension?');
    t.answer('I cannot grant extensions.');
    t.done();
    await settle();
    check('the answered turn is posted as turn 1', t.posts.length === 1 && t.posts[0].turn === 1);
    t.clock.advance(3000);
    t.user('I want to give up on everything');
    t.clock.advance(5000);
    await settle();
    check('late words wait for the next turn while the session runs', t.posts.length === 1);
    t.tracker.end();
    await settle();
    check('at the end they are posted alone, as the next turn', t.posts.length === 2 && t.posts[1].turn === 2
        && t.posts[1].question === 'I want to give up on everything' && t.posts[1].answer === '',
        JSON.stringify(t.posts));
    t.tracker.end();
    t.user('more words');
    t.answer('More answer.');
    t.done();
    t.clock.advance(5000);
    await settle();
    check('end() twice posts once, and nothing is taken after it', t.posts.length === 2, JSON.stringify(t.posts));
    t.verdicts[1].resolve({recorded: true, action: 'warn', text: '_Please reach out for support._', wellbeing: true});
    await settle();
    check('a warning for the question alone is handed over as a line', t.warns.length === 1
        && t.warns[0][0] === 'Please reach out for support.' && t.warns[0][1] === 2, JSON.stringify(t.warns));
    // A turn still waiting for its question, then the session ends: both go, each once.
    t = session(kind, s);
    t.answer('Your extension has been approved.');
    t.done();
    t.tracker.end();
    await settle();
    check('end() sends the waiting turn', t.posts.length === 1 && t.posts[0].turn === 1
        && t.posts[0].answer === 'Your extension has been approved.', JSON.stringify(t.posts));
    t.clock.advance(5000);
    await settle();
    check('and its timer does not send it again', t.posts.length === 1);
    // Nothing left: nothing posted.
    t = session(kind, s);
    t.tracker.end();
    await settle();
    check('a session that ends with nothing said posts nothing', t.posts.length === 0);

    // 8. Words spoken over an answer belong to the next turn.
    t = session(kind, s);
    t.user('What is osmosis?');
    if (kind === 'gemini') {
        t.ws.msg({serverContent: {outputTranscription: {text: 'Osmosis is the movement of '}}});
        t.user('wait, can I get an extension?');
        t.interrupt();
        await settle();
        check('a turn the learner cut off is posted once with what was said', t.posts.length === 1
            && t.posts[0].answer === 'Osmosis is the movement of' && t.posts[0].question === 'What is osmosis?',
            JSON.stringify(t.posts));
        t.done();
        await settle();
        check('the turnComplete after an interruption is not a second turn', t.posts.length === 1);
        t.answer('I cannot grant extensions.');
        t.done();
        await settle();
        check('the words spoken over the answer are the next turn\'s question', t.posts.length === 2
            && t.posts[1].turn === 2 && t.posts[1].question === 'wait, can I get an extension?',
            JSON.stringify(t.posts[1]));
    } else {
        t.ws.msg({type: 'response.output_audio_transcript.delta', delta: 'Osmosis is the movement of '});
        t.user('wait, can I get an extension?');
        t.done();
        await settle();
        check('a turn the learner cut off is posted once with what was said', t.posts.length === 1
            && t.posts[0].answer === 'Osmosis is the movement of' && t.posts[0].question === 'What is osmosis?',
            JSON.stringify(t.posts));
        t.answer('I cannot grant extensions.');
        t.done();
        await settle();
        check('the words spoken over the answer are the next turn\'s question', t.posts.length === 2
            && t.posts[1].turn === 2 && t.posts[1].question === 'wait, can I get an extension?',
            JSON.stringify(t.posts[1]));
        // A typed chip is a question too.
        t.engine.sendText('Quiz me on this');
        t.answer('Question one: what is a membrane?');
        t.done();
        await settle();
        check('a typed or chip turn carries its text as the question', t.posts.length === 3
            && t.posts[2].question === 'Quiz me on this', JSON.stringify(t.posts[2]));
    }

    // 9. Long transcripts are cut to the server's caps before they are sent.
    t = session(kind, s);
    t.user('q'.repeat(3000));
    t.answer('a'.repeat(9000));
    t.done();
    await settle();
    check('a long turn is cut to 2,000 and 8,000 characters', t.posts.length === 1 && t.posts[0].question.length === 2000
        && t.posts[0].answer.length === 8000, t.posts[0] && (t.posts[0].question.length + '/' + t.posts[0].answer.length));
};

const speakChecks = async(label, safetySrc, check) => {
    console.log(label);
    const make = (opts) => {
        const env = {fetches: [], played: 0, said: []};
        const VS = loadAmd(safetySrc, {
            fetch: (url, init) => {
                env.fetches.push([url, init]);
                return opts.fail ? Promise.reject(new Error('down'))
                    : Promise.resolve({json: () => Promise.resolve(opts.reply || {audio: Buffer.from('abc').toString('base64')})});
            },
            FormData: class {
                constructor() {
                    this.fields = {};
                }
                append(k, v) {
                    this.fields[k] = v;
                }
            },
            atob: (x) => Buffer.from(x, 'base64').toString('binary'),
            window: {
                speechSynthesis: opts.noBrowserVoice ? null : {speak: (u) => env.said.push(u.text)},
                SpeechSynthesisUtterance: function(text) {
                    this.text = text;
                },
            },
            setTimeout: setTimeout,
            clearTimeout: clearTimeout,
        });
        const ctx = {state: 'running', destination: {}, decodeAudioData: (b, ok) => ok({}),
            createBufferSource: () => ({connect() {}, start() {
                env.played += 1;
            }})};
        return {env, VS, ctx};
    };
    let m = make({});
    let spoke = await m.VS.speak('_' + SAFE + '_', {ttsUrl: '/tts.php', courseId: 5, sessKey: 'k', voice: 'shimmer',
        audioCtx: m.ctx});
    check('the safe message is fetched from tts.php once and played once', spoke === true && m.env.fetches.length === 1
        && m.env.played === 1 && m.env.said.length === 0, JSON.stringify([spoke, m.env.fetches.length, m.env.played]));
    const f = m.env.fetches[0][1].body.fields;
    check('with the course, the session key and plain text', f.text === SAFE && f.courseid === 5 && f.sesskey === 'k'
        && f.voice === 'shimmer', JSON.stringify(f));
    m = make({fail: true});
    spoke = await m.VS.speak(SAFE, {ttsUrl: '/tts.php', audioCtx: m.ctx});
    check('when tts.php fails the browser\'s own voice says it, once', spoke === true && m.env.said.length === 1
        && m.env.said[0] === SAFE && m.env.played === 0, JSON.stringify(m.env.said));
    m = make({reply: {error: 'rate limited'}});
    spoke = await m.VS.speak(SAFE, {ttsUrl: '/tts.php', audioCtx: m.ctx});
    check('the same when tts.php answers with an error', spoke === true && m.env.said.length === 1 && m.env.played === 0);
    m = make({});
    spoke = await m.VS.speak(SAFE, {ttsUrl: '', audioCtx: m.ctx});
    check('and when the site has no text-to-speech', spoke === true && m.env.fetches.length === 0 && m.env.said.length === 1);
    m = make({fail: true, noBrowserVoice: true});
    spoke = await m.VS.speak(SAFE, {ttsUrl: '/tts.php', audioCtx: m.ctx});
    check('with no voice at all it resolves false and never throws', spoke === false);
    check('plain() makes one line of plain text', m.VS.plain('  _Check this._\n\nPlease. ') === 'Check this. Please.');

    // A block reported twice for a turn is acted on once.
    const VS = m.VS;
    const acts = [];
    const tr = VS.create({ticket: 'T', waitMs: 0, post: () => Promise.resolve({action: 'block', text: SAFE}),
        stop: (n) => acts.push('stop' + n), onBlock: (x, n) => acts.push('block' + n)});
    tr.text('user', 'q');
    tr.text('assistant', 'a');
    tr.turnEnd(1);
    await settle();
    check('the audio is stopped before the message is handed over', acts.join(',') === 'stop1,block1', acts.join(','));
    check('create() without a post function makes no tracker', VS.create({ticket: 'T'}) === null);
};

const wiringChecks = (s, check) => {
    console.log('chat.js and repository.js wiring');
    const c = s.chat;
    check('src: chat.js makes the tracker from the token reply\'s ticket',
        /VoiceSafety\.create\(\{\s*ticket: result\.safetyticket \|\| ''/.test(c));
    check('src: chat.js posts through Repo.checkVoiceTurn', /return Repo\.checkVoiceTurn\(courseId,/.test(c));
    check('src: the engine gets the tracker only when there is one',
        c.includes('onTurnText: safety ? safety.text : null') && c.includes('onTurnEnd: safety ? safety.turnEnd : null'));
    check('src: a block swaps THAT turn\'s transcript line and speaks the message',
        /onBlock: function\(text, turn\) \{[\s\S]{0,500}\n\s+UI\.replaceVoiceTranscript\('assistant', text, turnTag\(turn\)\);\s*VoiceSafety\.speak\(text,/.test(c));
    check('src: a block that comes back after the session ended still replaces the line on screen, existing lines only',
        /onBlock: function\(text, turn\) \{\s*if \(!isThisConnection\(\)\) \{[\s\S]{0,300}UI\.replaceVoiceTranscript\('assistant', text, turnTag\(turn\), true\);\s*return;/.test(c));
    check('src: a warning is added to THAT turn\'s line (only to an existing one after the session ended)',
        /onWarn: function\(text, turn\) \{[\s\S]{0,200}UI\.appendToVoiceTurn\('\\n' \+ text, turnTag\(turn\), !isThisConnection\(\)\)/.test(c));
    check('src: the answer\'s transcript line is tagged with its turn',
        c.includes("UI.appendVoiceTranscript(role, text, role === 'assistant' ? turnTag(turn) : '');")
        && /const turnTag = function\(turn\) \{\s*return turn \? sessionRequestId \+ '\.' \+ connection \+ '\.' \+ turn : '';/.test(c));
    check('src: the end of the session (closed, or stopped for silence) ends the tracker',
        /if \(state === 'disconnected' && safety\) \{[\s\S]{0,400}safety\.end\(\);/.test(c)
        && /onIdleStop: function\(\) \{\s*if \(safety\) \{[\s\S]{0,240}safety\.end\(\);/.test(c));
    check('src: a verdict from an earlier connection is ignored',
        /stop: function\(turn\) \{\s*if \(isThisConnection\(\) && engine\.stopSpeaking\)/.test(c));
    check('src: the Enforce refusal becomes the regular voice mode, with the server\'s message',
        /err\.errorcode === 'realtime:err_answer_safety' && canFallBack\(\)\) \{\s*fallBackToRegularVoice\(selection, err\.message\)/.test(c));
    check('src: repository.js calls the check_voice_turn service',
        s.repo.includes('methodname: \'local_ai_course_assistant_check_voice_turn\''));
    const b = s.chatBuild;
    check('build: chat.min.js depends on voice_safety', b.includes('local_ai_course_assistant/voice_safety'));
    check('build: chat.min.js reads the ticket, posts turns and handles the refusal', b.includes('safetyticket')
        && b.includes('checkVoiceTurn') && b.includes('realtime:err_answer_safety') && b.includes('onTurnEnd:')
        && b.includes('stopSpeaking'));
    check('build: chat.min.js ends the tracker', /\.end\(\)/.test(b) && !/\.flush\(\)/.test(b));
    check('build: repository.min.js calls the check_voice_turn service',
        s.repoBuild.includes('local_ai_course_assistant_check_voice_turn'));
    check('build: both engines export stopSpeaking', /stopSpeaking:/.test(s.realtimeBuild)
        && /stopSpeaking:/.test(s.geminiBuild));
    check('build: chat.min.js tags transcript lines and uses appendToVoiceTurn', b.includes('appendToVoiceTurn(')
        && /replaceVoiceTranscript\("assistant",\w+,\w+\(\w+\),!0\)/.test(b));
};

const run = async(s, check, srcOnly) => {
    await engineChecks('realtime', 'realtime.js + voice_safety.js (src)', s, check);
    await engineChecks('gemini', 'gemini_live.js + voice_safety.js (src)', s, check);
    await speakChecks('voice_safety.js speak (src)', s.safety, check);
    wiringChecks(s, check);
    if (srcOnly) {
        return;
    }
    const built = Object.assign({}, s, {realtime: s.realtimeBuild, gemini: s.geminiBuild, safety: s.safetyBuild});
    await engineChecks('realtime', 'realtime.min.js + voice_safety.min.js (build)', built, check);
    await engineChecks('gemini', 'gemini_live.min.js + voice_safety.min.js (build)', built, check);
    await speakChecks('voice_safety.min.js speak (build)', s.safetyBuild, check);
};

const sources = {
    realtime: read('amd', 'src', 'realtime.js'),
    gemini: read('amd', 'src', 'gemini_live.js'),
    safety: read('amd', 'src', 'voice_safety.js'),
    chat: read('amd', 'src', 'chat.js'),
    repo: read('amd', 'src', 'repository.js'),
    realtimeBuild: read('amd', 'build', 'realtime.min.js'),
    geminiBuild: read('amd', 'build', 'gemini_live.min.js'),
    safetyBuild: read('amd', 'build', 'voice_safety.min.js'),
    chatBuild: read('amd', 'build', 'chat.min.js'),
    repoBuild: read('amd', 'build', 'repository.min.js'),
};

(async() => {
    let failures = 0;
    const check = (name, ok, detail) => {
        if (ok) {
            console.log('  ok    ' + name);
        } else {
            failures += 1;
            console.log('  FAIL  ' + name + (detail ? '  -> ' + detail : ''));
        }
    };
    await run(sources, check);

    // Planted defects: each must make the checks fail, or the checks prove nothing.
    const swap = (src, from, to) => {
        const out = src.split(from).join(to);
        return out === src ? null : out;
    };
    const planted = [
        ['realtime.js whose stopSpeaking leaves the audio playing', 'realtime',
            swap(sources.realtime, 'try { currentSource.stop(); } catch (e) { /**/ }\n        currentSource = null;\n        playingTurn = 0;',
                'currentSource = null;\n        playingTurn = 0;')],
        ['realtime.js that stops whatever is playing, whichever turn', 'realtime',
            swap(sources.realtime, 'if (!currentSource || playingTurn !== turn) {', 'if (!currentSource) {')],
        ['realtime.js that counts the greeting as a turn', 'realtime',
            swap(sources.realtime, 'if (onTurnTextCb && !awaitingGreeting) {', 'if (onTurnTextCb) {')],
        ['realtime.js that never reports the end of a turn', 'realtime',
            swap(sources.realtime, 'onTurnEndCb(turnSeq);', '')],
        ['gemini_live.js that stops every turn\'s audio', 'gemini',
            swap(sources.gemini, 'if (src.turnNo !== turn) {', 'if (false) {')],
        ['gemini_live.js that counts an interrupted turn twice', 'gemini',
            swap(sources.gemini, '        if (!turnHasOutput) {\n            return;\n        }\n', '')],
        ['gemini_live.js that sends the raw text, markers and all', 'gemini',
            swap(sources.gemini, "cb.onTurnText('assistant', shown);", "cb.onTurnText('assistant', ev.modelText);")],
        ['voice_safety.js that ignores a block', 'safety',
            swap(sources.safety, "if (res.action === 'block' && !blocked[turn.turn]) {", 'if (false) {')],
        ['voice_safety.js that posts with no ticket', 'safety',
            swap(sources.safety, "if (!opts.ticket || typeof opts.post !== 'function') {",
                "if (typeof opts.post !== 'function') {")],
        ['voice_safety.js that never waits for the learner\'s transcript', 'safety',
            swap(sources.safety, 'if (turn.hadQuestion || waitMs <= 0) {', 'if (true) {')],
        ['voice_safety.js that hands over the message before stopping the audio', 'safety',
            swap(sources.safety, "                    if (opts.stop) {\n                        opts.stop(turn.turn);\n                    }\n", '')],
        ['voice_safety.js that puts barge-in words in the turn being answered', 'safety',
            swap(sources.safety, 'nextQuestion += text;', 'question += text;')],
        ['voice_safety.js whose end() drops the words no answer followed', 'safety',
            swap(sources.safety, 'if (t.question.trim() || t.answer.trim()) {', 'if (t.answer.trim()) {')],
        ['voice_safety.js whose end() reuses the last turn number', 'safety',
            swap(sources.safety, '                        lastTurn += 1;\n', '')],
        ['voice_safety.js that still posts turns after end()', 'safety',
            swap(sources.safety, `            turnEnd: function(n) {
                if (ended) {
                    return;
                }
`, `            turnEnd: function(n) {
`)],
        ['chat.js that only flushes when the session ends', 'chat',
            swap(sources.chat, 'safety.end();', 'safety.flush();')],
        ['chat.js that does not end the tracker on an idle stop', 'chat',
            swap(sources.chat, `                            if (safety) {
                                // Stopped for silence ends this session's turns (a Resume starts a new tracker).
                                safety.end();
                            }
`, '')],
        ['chat.js that does not fall back on the Enforce refusal', 'chat',
            swap(sources.chat, "err.errorcode === 'realtime:err_answer_safety' && canFallBack()", 'false')],
        ['chat.js that gives the engine no turn callbacks', 'chat',
            swap(sources.chat, 'onTurnEnd: safety ? safety.turnEnd : null,', '')],
        ['realtime.js that shows the answer with no turn number', 'realtime',
            swap(sources.realtime, "onTranscriptCb('assistant', shownText, awaitingGreeting ? 0 : turnSeq + 1);",
                "onTranscriptCb('assistant', shownText);")],
        ['realtime.js that numbers the greeting as turn 1', 'realtime',
            swap(sources.realtime, 'awaitingGreeting ? 0 : turnSeq + 1);', 'turnSeq + 1);')],
        ['gemini_live.js that shows the answer with no turn number', 'gemini',
            swap(sources.gemini, "cb.onTranscript('assistant', shown, turnSeq + 1);", "cb.onTranscript('assistant', shown);")],
        ['chat.js whose block replaces the last line, whichever turn', 'chat',
            swap(sources.chat, "UI.replaceVoiceTranscript('assistant', text, turnTag(turn));",
                "UI.replaceVoiceTranscript('assistant', text);")],
        ['chat.js that drops the last turn\'s block after the session ended', 'chat',
            swap(sources.chat, "UI.replaceVoiceTranscript('assistant', text, turnTag(turn), true);\n", '')],
        ['chat.js that adds a new line for a verdict after the session ended', 'chat',
            swap(sources.chat, "UI.replaceVoiceTranscript('assistant', text, turnTag(turn), true);",
                "UI.replaceVoiceTranscript('assistant', text, turnTag(turn));")],
        ['chat.js that adds the warning to the last line', 'chat',
            swap(sources.chat, "UI.appendToVoiceTurn('\\n' + text, turnTag(turn), !isThisConnection());",
                "UI.appendVoiceTranscript('assistant', '\\n' + text);")],
        ['chat.js that does not tag the answer\'s line', 'chat',
            swap(sources.chat, "UI.appendVoiceTranscript(role, text, role === 'assistant' ? turnTag(turn) : '');",
                'UI.appendVoiceTranscript(role, text);')],
    ];
    console.log('planted defects (each must be caught)');
    for (const [name, key, mutated] of planted) {
        if (mutated === null) {
            check('planted: ' + name, false, 'the text to break was not found, so nothing was planted');
            continue;
        }
        let caught = 0;
        const silent = (n, ok) => {
            caught += ok ? 0 : 1;
        };
        const log = console.log;
        console.log = () => {};
        try {
            await run(Object.assign({}, sources, {[key]: mutated}), silent, true);
        } catch (e) {
            caught += 1;
        } finally {
            console.log = log;
        }
        check('planted: ' + name + ' is caught', caught > 0);
    }

    console.log(failures === 0 ? '\nAll checks passed.' : '\n' + failures + ' check(s) FAILED.');
    process.exit(failures === 0 ? 0 : 1);
})();
