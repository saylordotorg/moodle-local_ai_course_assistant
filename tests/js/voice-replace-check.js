/**
 * Voice mode and practice scoring honour the answer replace event (8.4.0 red team, RT2).
 *
 * When a guidance rule blocks or warns on an answer that was already streamed, sse.php sends
 * `{replace, messageid, reason}` before the done event. The chat panel swapped its text (answer-replace-check.js),
 * but two other callers of sse.php ignored the event: the listen / think / speak voice mode (voice.js) kept
 * reading the blocked answer aloud and left it in the transcript, and the practice-scoring stream in chat.js kept
 * the blocked text. This check pins both, with no browser, no audio and no network:
 *
 *   1. voice.js, src AND build, loaded whole with a fake stream, a fake text-to-speech endpoint and a fake audio
 *      context: on replace the audio that is playing stops, the sentences still queued are dropped, the transcript
 *      callback gets the replacement text, the replacement is spoken exactly once, and a token that arrives after
 *      the replace is neither shown nor spoken;
 *   2. the REAL replaceVoiceTranscript() lifted from amd/src/ui.js, run on a minimal DOM: it swaps the last
 *      assistant line as text (never HTML) and never touches the learner's own line;
 *   3. the REAL practice-scoring onReplace handler lifted from amd/src/chat.js;
 *   4. chat.js wires Voice's onReplace to the transcript, in source and in the built bundle (Moodle serves
 *      amd/build).
 *
 * A planted-defect run at the end removes each handler from a copy of the source and requires the checks to fail.
 *
 * What it can't check: real audio, a real microphone, a real provider. The realtime voice modes (Gemini Live,
 * OpenAI and xAI realtime) and the talking avatar never pass through sse.php at all, so no rule sees them; that is
 * a known limit of 8.4.0, not something this check covers.
 *
 * Run: node tests/js/voice-replace-check.js
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

const tick = () => new Promise((resolve) => setImmediate(resolve));
const settle = async() => {
    for (let i = 0; i < 12; i++) {
        await tick();
    }
};

// ---- voice.js with everything around it faked ------------------------------------------------------------------
const makeVoice = (src) => {
    const env = {spoken: [], sources: [], aborted: 0, stream: null, streams: 0, timers: [], maxPlaying: 0};
    class FakeSource {
        constructor() {
            this.playbackRate = {value: 1};
            this.started = false;
            this.stopped = false;
            env.sources.push(this);
        }
        connect() {
            return undefined;
        }
        start() {
            this.started = true;
            const playing = env.sources.filter((s) => s.started && !s.stopped && !s.done).length;
            env.maxPlaying = Math.max(env.maxPlaying, playing);
        }
        stop() {
            this.stopped = true;
            // A real source fires "ended" after stop(): the handler, if still attached, must not restart anything.
            const h = this.onended;
            if (h) {
                setImmediate(h);
            }
        }
        finish() {
            if (this.onended) {
                this.onended();
            }
        }
    }
    class FakeCtx {
        constructor() {
            this.state = 'running';
            this.destination = {};
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
        createBufferSource() {
            return new FakeSource();
        }
        decodeAudioData(buffer, ok) {
            ok({duration: 1});
        }
    }
    class FakeRec {
        start() {
            if (this.onstart) {
                this.onstart();
            }
        }
        stop() {}
        abort() {}
    }
    class FakeForm {
        constructor() {
            this.fields = {};
        }
        append(k, v) {
            this.fields[k] = v;
        }
    }
    const fetch = (url, opts) => new Promise((resolve, reject) => {
        const text = opts.body.fields.text;
        if (opts.signal) {
            opts.signal.addEventListener('abort', () => {
                env.aborted += 1;
                const e = new Error('aborted');
                e.name = 'AbortError';
                reject(e);
            });
        }
        env.spoken.push(text);
        setImmediate(() => resolve({json: () => Promise.resolve({audio: 'AAAA'})}));
    });
    const globals = {
        window: {SpeechRecognition: FakeRec, AudioContext: FakeCtx},
        navigator: {mediaDevices: {getUserMedia: () => Promise.resolve({getTracks: () => []})}},
        console: console,
        document: {querySelector: () => null},
        localStorage: {getItem: () => null},
        setTimeout: (fn) => {
            env.timers.push(fn);
            return env.timers.length;
        },
        clearTimeout() {},
        setInterval: () => 1,
        clearInterval() {},
        requestAnimationFrame: () => 1,
        cancelAnimationFrame() {},
        fetch: fetch,
        FormData: FakeForm,
        atob: (s) => Buffer.from(s, 'base64').toString('binary'),
        Uint8Array: Uint8Array,
        AbortController: AbortController,
    };
    const SSE = {
        startStream: (url, body, callbacks) => {
            env.streams += 1;
            env.stream = callbacks;
            return {abort() {}};
        },
    };
    env.V = loadAmd(src, globals, [SSE]);
    return env;
};

const BLOCKED = 'I guarantee you will pass. You do not even need to study for it. Just relax this week.';
const SAFE = 'I can\'t promise a grade. Your instructor decides that. Please ask them.';

const voiceChecks = async(label, src, check) => {
    const env = makeVoice(src);
    const transcript = [];
    const replaced = [];
    const states = [];
    env.V.connect('', 'shimmer', {
        onTranscript: (role, text) => transcript.push(role + ':' + text),
        onReplace: (text, data) => replaced.push([text, data]),
        onStateChange: (s) => states.push(s),
    }, {courseId: 1, sessKey: 'k', sseUrl: 'https://x.example/local/ai_course_assistant/sse.php'});
    await settle();
    env.V.sendText('Will I pass?');
    check(label + ': the question is streamed through sse.php', env.streams === 1 && !!env.stream);
    if (!env.stream) {
        return;
    }
    check(label + ': the stream is given an onReplace handler', typeof env.stream.onReplace === 'function');
    BLOCKED.match(/\S+\s*/g).forEach((t) => env.stream.onToken(t));
    await settle();
    const playing = env.sources.filter((s) => s.started && !s.stopped);
    check(label + ': the answer starts being read aloud as it streams', env.spoken[0] === 'I guarantee you will pass.'
        && playing.length === 1, JSON.stringify(env.spoken));
    const before = env.spoken.length;
    const sourcesBefore = env.sources.length;
    if (typeof env.stream.onReplace !== 'function') {
        return;
    }
    env.stream.onReplace({replace: SAFE, messageid: 41, reason: 'grades.guarantee'});
    check(label + ': the audio that was playing is stopped', playing.every((s) => s.stopped));
    check(label + ': the transcript is told to swap to the replacement, once',
        replaced.length === 1 && replaced[0][0] === SAFE, JSON.stringify(replaced));
    env.stream.onToken(' Seriously, you will pass.');
    await settle();
    // The stopped source fired "ended" by now; let the next sentence play out, then finish the stream.
    for (let i = 0; i < 6; i++) {
        env.sources.filter((s) => s.started && !s.stopped && !s.done).forEach((s) => {
            s.done = true;
            s.finish();
        });
        await settle();
    }
    env.stream.onDone({done: true, messageid: 41});
    await settle();
    for (let i = 0; i < 6; i++) {
        env.sources.filter((s) => s.started && !s.stopped && !s.done).forEach((s) => {
            s.done = true;
            s.finish();
        });
        await settle();
    }
    const after = env.spoken.slice(before);
    check(label + ': nothing more of the blocked answer is spoken after the replace',
        after.every((t) => BLOCKED.indexOf(t) === -1 && t.indexOf('Seriously') === -1), JSON.stringify(after));
    check(label + ': the replacement is spoken exactly once',
        after.join(' ') === SAFE, JSON.stringify(after));
    check(label + ': every sentence of the replacement is really played (none is cut off by the stopped audio)',
        env.sources.slice(sourcesBefore).filter((s) => s.started).length === after.length && after.length === 3,
        env.sources.slice(sourcesBefore).filter((s) => s.started).length + ' played of ' + after.length);
    check(label + ': a token after the replace never reaches the transcript',
        transcript.every((t) => t.indexOf('Seriously') === -1), JSON.stringify(transcript));
    check(label + ': only one thing plays at a time (the stopped audio does not start the next sentence)',
        env.maxPlaying === 1, 'max playing ' + env.maxPlaying);
    check(label + ': the turn ends idle, ready to listen', states[states.length - 1] === 'idle'
        || states[states.length - 1] === 'listening', states.join(','));

    // A check line added to an answer (warn): the markdown underscores round the line are not read out or shown.
    env.V.sendText('When is it due?');
    env.stream.onToken('It is due on March 14.');
    const n = env.spoken.length;
    env.stream.onReplace({replace: 'It is due on March 14.\n\n_Please check this with your instructor._', messageid: 42});
    env.stream.onDone({done: true});
    await settle();
    for (let i = 0; i < 6; i++) {
        env.sources.filter((s) => s.started && !s.stopped && !s.done).forEach((s) => {
            s.done = true;
            s.finish();
        });
        await settle();
    }
    const warned = env.spoken.slice(n).join(' ');
    check(label + ': a warned answer is read once with its check line and no underscores',
        warned === 'It is due on March 14. Please check this with your instructor.', JSON.stringify(warned));
    check(label + ': and shown the same way', replaced.length === 2 && replaced[1][0].indexOf('_') === -1
        && replaced[1][0].indexOf('Please check this') !== -1, JSON.stringify(replaced[1]));

    // Frames that are not a replace do nothing.
    const m = env.spoken.length;
    env.stream.onReplace(null);
    env.stream.onReplace({messageid: 9});
    check(label + ': a frame without replace text does nothing', env.spoken.length === m && replaced.length === 2);

    // A caller that gave no onReplace still gets the text, through the transcript.
    env.V.disconnect();
    const env2 = makeVoice(src);
    const t2 = [];
    env2.V.connect('', 'shimmer', {onTranscript: (role, text) => t2.push(role + ':' + text)},
        {courseId: 1, sessKey: 'k', sseUrl: 'https://x.example/local/ai_course_assistant/sse.php'});
    await settle();
    env2.V.sendText('Will I pass?');
    env2.stream.onToken('I guarantee you will pass.');
    if (typeof env2.stream.onReplace === 'function') {
        env2.stream.onReplace({replace: SAFE, messageid: 41});
    }
    check(label + ': with no onReplace callback the replacement still reaches the transcript',
        t2.some((t) => t.indexOf(SAFE) !== -1), JSON.stringify(t2));
    env2.V.disconnect();
};

// ---- ui.js replaceVoiceTranscript on a minimal DOM ---------------------------------------------------------------
class El {
    constructor(className) {
        this.className = className || '';
        this.children = [];
        this.textContent = '';
        this.htmlWrites = 0;
        this.dataset = {};
        this.scrollTop = 0;
        this.scrollHeight = 10;
        const self = this;
        this.classList = {contains: (c) => self.className.split(/\s+/).includes(c)};
    }
    set innerHTML(v) {
        this.htmlWrites += 1;
    }
    appendChild(c) {
        this.children.push(c);
        return c;
    }
    all(cls, out) {
        this.children.forEach((c) => {
            if (c.className.split(/\s+/).includes(cls)) {
                out.push(c);
            }
            c.all(cls, out);
        });
        return out;
    }
    querySelectorAll(sel) {
        return this.all(sel.replace(/^\./, ''), []);
    }
    querySelector(sel) {
        return this.querySelectorAll(sel)[0] || null;
    }
}

const liftUi = (uiSrc) => {
    const a = uiSrc.match(/\n( {4})const appendVoiceTranscript = function\([\s\S]*?\n\1\};\n/);
    const r = uiSrc.match(/\n( {4})const replaceVoiceTranscript = function\([\s\S]*?\n\1\};\n/);
    if (!a || !r) {
        return null;
    }
    return (container) => {
        // eslint-disable-next-line no-new-func
        return new Function('messagesContainer', 'document', 'appendMessageNode', a[0] + r[0]
            + '\nreturn {append: appendVoiceTranscript, replace: replaceVoiceTranscript};')(container,
            {createElement: () => new El('')}, (n) => container.appendChild(n));
    };
};

const uiChecks = (uiSrc, check) => {
    const lift = liftUi(uiSrc);
    check('ui.js defines replaceVoiceTranscript', !!lift);
    if (!lift) {
        return;
    }
    const box = new El('messages');
    const ui = lift(box);
    ui.append('user', 'Will I pass?');
    ui.append('assistant', 'I guarantee ');
    ui.append('assistant', 'you will pass.');
    ui.replace('assistant', 'Safe <b>text</b>.');
    const lines = box.querySelectorAll('aica-voice-msg');
    const text = (el) => el.querySelector('.aica-voice-msg__text').textContent;
    check('the last assistant line is swapped, not added to', lines.length === 2 && text(lines[1]) === 'Safe <b>text</b>.',
        lines.map(text).join(' | '));
    check('the text is set as text, never as HTML', lines.every((l) => l.htmlWrites === 0
        && l.querySelector('.aica-voice-msg__text').htmlWrites === 0));
    check('the line is marked data-replaced="1"', lines[1].dataset && lines[1].dataset.replaced === '1');
    check('the learner\'s own line is untouched', text(lines[0]) === 'Will I pass?');
    const box2 = new El('messages');
    const ui2 = lift(box2);
    ui2.append('user', 'Will I pass?');
    ui2.replace('assistant', 'Safe.');
    const l2 = box2.querySelectorAll('aica-voice-msg');
    check('with no assistant line yet, the replacement becomes one (the question is not overwritten)',
        l2.length === 2 && text(l2[0]) === 'Will I pass?' && text(l2[1]) === 'Safe.', l2.map(text).join(' | '));
    check('ui.js exports replaceVoiceTranscript', /replaceVoiceTranscript: replaceVoiceTranscript,/.test(uiSrc));
};

// ---- chat.js: the practice-scoring stream and the Voice wiring -------------------------------------------------
const liftScoreHandlers = (chatSrc) => {
    const start = chatSrc.indexOf('var scoreFullText = \'\';');
    if (start === -1) {
        return null;
    }
    const block = chatSrc.slice(start, chatSrc.indexOf('\n    };\n', start));
    const m = block.match(/SSE\.startStream\(sseUrl, postData, (\{[\s\S]*\})\);\s*$/);
    if (!m) {
        return null;
    }
    return (env) => {
        // eslint-disable-next-line no-new-func
        return new Function('env', 'var scoreFullText = ""; var scoreReplaced = false; var type = "conversation";'
            + 'var practiceSessionType = "conversation"; var document = env.document;'
            + 'var parseAssistantDecorators = env.parse; var handleScoreData = env.score;'
            + 'var addAssistantMsg = env.add; var h = ' + m[1] + '; return h;')(env);
    };
};

const SCORE = '[SOLA_SCORE]{"criteria":[{"name":"Fluency","score":4,"feedback":"ok"}],"overall":4,"summary":"s"}[/SOLA_SCORE]';
const parse = (t) => {
    const m = t.match(/\[SOLA_SCORE\]([\s\S]*?)\[\/SOLA_SCORE\]/);
    return {text: t.replace(/\[SOLA_SCORE\][\s\S]*?\[\/SOLA_SCORE\]/, '').trim(), scoreData: m ? JSON.parse(m[1]) : null};
};

const chatChecks = (chatSrc, check, label) => {
    const lift = liftScoreHandlers(chatSrc);
    check(label + ': the practice-scoring stream is found', !!lift);
    if (!lift) {
        return;
    }
    const mk = () => {
        const env = {scored: [], added: [], parse, document: {querySelectorAll: () => []}};
        env.score = (d) => env.scored.push(d);
        env.add = (t) => env.added.push(t);
        env.h = lift(env);
        return env;
    };
    let e = mk();
    check(label + ': the practice-scoring stream handles replace', typeof e.h.onReplace === 'function');
    if (typeof e.h.onReplace !== 'function') {
        return;
    }
    e.h.onToken(SCORE);
    e.h.onReplace({replace: SAFE, messageid: 5, reason: 'grades.guarantee'});
    e.h.onDone();
    check(label + ': a blocked scoring answer gives no score card', e.scored.length === 0);
    check(label + ': and the learner is shown the replacement text', e.added.length === 1 && e.added[0] === SAFE,
        JSON.stringify(e.added));
    e = mk();
    e.h.onToken(SCORE);
    e.h.onReplace({replace: SCORE + '\n\n_Please check this._', messageid: 5});
    e.h.onDone();
    check(label + ': a warned scoring answer still gives its score, from the replacement', e.scored.length === 1
        && e.scored[0].overall === 4);
    e = mk();
    e.h.onToken(SCORE);
    e.h.onReplace(null);
    e.h.onReplace({messageid: 5});
    e.h.onDone();
    check(label + ': a frame without replace text changes nothing', e.scored.length === 1 && e.added.length === 0);
    check(label + ': chat.js hands Voice an onReplace that swaps the transcript',
        /onReplace: function\(text\) \{[\s\S]{0,300}?UI\.replaceVoiceTranscript\('assistant', text\);/.test(chatSrc));
};

const buildChecks = (s, check) => {
    check('build: voice.min.js passes onReplace to the stream', /onReplace:function/.test(s.voiceBuild));
    check('build: chat.min.js calls replaceVoiceTranscript', s.chatBuild.includes('replaceVoiceTranscript("assistant"'));
    check('build: chat.min.js has three onReplace handlers (chat, scoring, voice)',
        (s.chatBuild.match(/onReplace:function/g) || []).length === 3,
        String((s.chatBuild.match(/onReplace:function/g) || []).length));
    check('build: ui.min.js exports replaceVoiceTranscript', /replaceVoiceTranscript:/.test(s.uiBuild));
};

const run = async(s, check) => {
    await voiceChecks('voice.js (src)', s.voiceSrc, check);
    await voiceChecks('voice.min.js (build)', s.voiceBuild, check);
    uiChecks(s.uiSrc, check);
    chatChecks(s.chatSrc, check, 'src');
    buildChecks(s, check);
};

const sources = {
    voiceSrc: read('amd', 'src', 'voice.js'),
    voiceBuild: read('amd', 'build', 'voice.min.js'),
    uiSrc: read('amd', 'src', 'ui.js'),
    uiBuild: read('amd', 'build', 'ui.min.js'),
    chatSrc: read('amd', 'src', 'chat.js'),
    chatBuild: read('amd', 'build', 'chat.min.js'),
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
    const cut = (src, re) => {
        const out = src.replace(re, '\n');
        return out === src ? null : out;
    };
    const planted = [
        ['voice.js without the onReplace handler', 'voiceSrc',
            cut(sources.voiceSrc, /\n( +)onReplace: function\(data\) \{[\s\S]*?\n\1\},\n/)],
        ['voice.js that does not stop the audio on replace', 'voiceSrc',
            sources.voiceSrc.replace(/(onReplace: function\(data\) \{[\s\S]*?)interruptTts\(\);/, '$1')],
        ['voice.js that keeps reading tokens after a replace', 'voiceSrc',
            sources.voiceSrc.replace(/if \(!connected \|\| streamReplaced\) \{/, 'if (!connected) {')],
        ['voice.js whose stopped audio still drives the queue', 'voiceSrc',
            sources.voiceSrc.replace(/currentSource\.onended = null;/, '')],
        ['chat.js scoring stream without the onReplace handler', 'chatSrc',
            cut(sources.chatSrc, /\n( +)onReplace: function\(data\) \{\n\s+\/\/ 8\.4\.0 \(RT2\)[\s\S]*?\n\1\},\n/)],
        ['ui.js that appends instead of swapping', 'uiSrc',
            sources.uiSrc.replace(/content\.textContent = text;\n(\s+)lastMsg\.dataset\.replaced/,
                'content.textContent += text;\n$1lastMsg.dataset.replaced')],
    ];
    for (const [name, key, mutated] of planted) {
        if (!mutated || mutated === sources[key]) {
            check('planted defect could be planted: ' + name, false);
            continue;
        }
        let caught = 0;
        await run(Object.assign({}, sources, {[key]: mutated}), (n, ok) => {
            caught += ok ? 0 : 1;
        });
        check('planted defect is caught: ' + name, caught > 0);
    }

    if (failures) {
        console.log('\n' + failures + ' check(s) failed');
        process.exit(1);
    }
    console.log('\nall voice-replace checks passed');
})();
