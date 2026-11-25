/**
 * Answer replace event check (8.4.0).
 *
 * When a guidance rule blocks or warns on an answer that was already streamed, sse.php saves the new text and sends
 * `{replace, messageid, reason}` BEFORE the done event, so the browser swaps the text the learner is reading. This
 * check pins the client half, with no browser and no network:
 *
 *   1. sse_client.js, src AND build, loaded whole with a fake fetch: a replace frame reaches onReplace once, with
 *      its payload, between the tokens and the done event, and is never treated as a token; keepalive comment
 *      lines are skipped; a stream without onReplace still finishes;
 *   2. the REAL replaceStreamContent() lifted from amd/src/ui.js, run on a minimal DOM: it swaps the text through
 *      the sanitising render path (never raw HTML), marks the element data-replaced="1", ignores a replace for a
 *      different message id when the element knows its id, and does nothing with no streaming message;
 *   3. the REAL onReplace handler lifted from amd/src/chat.js: it swaps once, starts a message when no text arrived,
 *      and leaves fullText alone when the replace is ignored;
 *   4. the built chat and ui bundles carry the handler (Moodle serves amd/build).
 *
 * A planted-defect run at the end removes the chat.js handler and the sse_client dispatch from copies of the
 * source and requires the checks to fail.
 *
 * Run: node tests/js/answer-replace-check.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (...p) => fs.readFileSync(path.join(root, ...p), 'utf8');

const loadAmd = (src, globals) => {
    let mod = null;
    const names = Object.keys(globals || {});
    // eslint-disable-next-line no-new-func
    new Function('define', ...names, src)((a, b) => {
        mod = typeof a === 'function' ? a() : b();
    }, ...names.map((n) => globals[n]));
    return mod;
};

// ---- a fake fetch that streams the given SSE text in pieces ------------------------------------------------
const fakeFetch = (sse, pieceSize) => () => {
    const bytes = Buffer.from(sse, 'utf8');
    let pos = 0;
    return Promise.resolve({
        ok: true,
        body: {
            getReader: () => ({
                read: () => {
                    if (pos >= bytes.length) {
                        return Promise.resolve({done: true});
                    }
                    const value = bytes.subarray(pos, pos + pieceSize);
                    pos += pieceSize;
                    return Promise.resolve({done: false, value});
                },
                cancel: () => undefined,
            }),
        },
    });
};

const runStream = (src, sse, withReplace) => new Promise((resolve) => {
    const events = [];
    const globals = {
        fetch: fakeFetch(sse, 7),
        FormData: class {
            append() {
                return undefined;
            }
        },
        AbortController: class {
            constructor() {
                this.signal = {};
            }
            abort() {
                return undefined;
            }
        },
        TextDecoder: TextDecoder,
    };
    const SSE = loadAmd(src, globals);
    let finished = false;
    const callbacks = {
        onToken: (t) => events.push(['token', t]),
        onDone: (d) => {
            if (!finished) {
                finished = true;
                events.push(['done', d]);
                setTimeout(() => resolve(events), 5);
            }
        },
        onError: (e) => {
            events.push(['error', e]);
            resolve(events);
        },
        onMeta: (m) => events.push(['meta', m]),
    };
    if (withReplace) {
        callbacks.onReplace = (d) => events.push(['replace', d]);
    }
    SSE.startStream('/x', {a: 1}, callbacks);
});

const frame = (o) => 'data: ' + JSON.stringify(o) + '\n\n';
const STREAM = frame({type: 'meta', citations: []}) + ': keepalive\n\n' + frame({token: 'I guarantee '})
    + frame({token: 'you will pass.'}) + ': keepalive\n\n'
    + frame({replace: 'I can\'t promise grades.', messageid: 41, reason: 'grades.guarantee'})
    + frame({done: true, messageid: 41});

const sseChecks = async(label, src, check) => {
    const ev = await runStream(src, STREAM, true);
    const kinds = ev.map((e) => e[0]);
    const replaces = ev.filter((e) => e[0] === 'replace');
    check(label + ': onReplace is called once', replaces.length === 1, kinds.join(','));
    check(label + ': with the payload', replaces.length === 1 && replaces[0][1].replace === 'I can\'t promise grades.'
        && replaces[0][1].messageid === 41 && replaces[0][1].reason === 'grades.guarantee');
    check(label + ': after the tokens and before done',
        kinds.join(',') === 'meta,token,token,replace,done', kinds.join(','));
    check(label + ': a replace is never a token',
        ev.filter((e) => e[0] === 'token').every((e) => e[1].indexOf('promise') === -1));
    const plain = await runStream(src, STREAM, false);
    check(label + ': a caller without onReplace still gets done',
        plain.map((e) => e[0]).join(',') === 'meta,token,token,done', plain.map((e) => e[0]).join(','));
};

// ---- a minimal DOM for the ui.js helper ---------------------------------------------------------------------
class El {
    constructor(className) {
        this.className = className || '';
        this.children = [];
        this.dataset = {};
        this.htmlWrites = [];
    }
    set innerHTML(v) {
        this.htmlWrites.push(String(v));
    }
    get innerHTML() {
        return this.htmlWrites.length ? this.htmlWrites[this.htmlWrites.length - 1] : '';
    }
    appendChild(c) {
        this.children.push(c);
        return c;
    }
    querySelector(sel) {
        const cls = sel.replace(/^\./, '');
        return this.children.find((c) => c.className.split(/\s+/).includes(cls)) || null;
    }
}

const liftUiHelper = (uiSrc) => {
    const m = uiSrc.match(/\n( {4})const replaceStreamContent = function\([\s\S]*?\n\1\};\n/);
    if (!m) {
        return null;
    }
    return (env) => {
        // eslint-disable-next-line no-new-func
        return new Function('env', 'let streamingEl = env.el; let typewriterFull = ""; let typewriterPos = 0;'
            + 'const renderAssistantHtml = env.render;' + m[0]
            + '\nreturn {fn: replaceStreamContent, state: () => ({typewriterFull, typewriterPos})};')(env);
    };
};

const uiChecks = (uiSrc, check) => {
    const lift = liftUiHelper(uiSrc);
    check('ui.js defines replaceStreamContent', !!lift);
    if (!lift) {
        return;
    }
    const rendered = [];
    const render = (t) => {
        rendered.push(t);
        return '<p>' + t.replace(/</g, '&lt;') + '</p>';
    };
    const msg = new El('local-ai-course-assistant__message--assistant');
    const content = msg.appendChild(new El('local-ai-course-assistant__message-content'));
    const env = {el: msg, render};
    const h = lift(env);
    const out = h.fn('Safe <b>text</b>', 41);
    check('the streaming message is returned', out === msg);
    check('the text goes through the sanitising render path', rendered.length === 1 && rendered[0] === 'Safe <b>text</b>'
        && content.innerHTML === '<p>Safe &lt;b>text&lt;/b></p>', content.innerHTML);
    check('the element is marked data-replaced="1"', msg.dataset.replaced === '1');
    check('the typewriter does not replay the old text', h.state().typewriterFull === 'Safe <b>text</b>'
        && h.state().typewriterPos === 'Safe <b>text</b>'.length);

    const known = new El('local-ai-course-assistant__message--assistant');
    known.appendChild(new El('local-ai-course-assistant__message-content'));
    known.dataset.messageid = '40';
    const k = lift({el: known, render});
    check('a replace for another message id is ignored', k.fn('Other', 41) === null && !known.dataset.replaced
        && known.children[0].htmlWrites.length === 0);
    check('a replace for the same id applies', k.fn('Same', 40) === known && known.dataset.replaced === '1');
    check('nothing without a streaming message', lift({el: null, render}).fn('x', 1) === null);
};

// ---- the chat.js handler ------------------------------------------------------------------------------------
const CHAT_HANDLER = /\n( +)onReplace: function\(data\) \{[\s\S]*?\n\1\},\n/g;
const liftChatHandler = (chatSrc) => {
    // The chat stream's handler: the one that swaps the streamed message (the practice-scoring stream and the
    // voice mode have their own, pinned by voice-replace-check.js).
    const m = (chatSrc.match(CHAT_HANDLER) || []).filter((h) => h.includes('UI.replaceStreamContent'));
    if (m.length !== 1) {
        return null;
    }
    const body = m[0].trim().replace(/^onReplace: /, '').replace(/,$/, '');
    // A stand-in for chat.js's stripStreamingDecorators (decorator-strip-check.js pins the real one).
    const strip = (t) => String(t).replace(/\n*\[SOLA_NEXT\][\s\S]*?(\[\/SOLA_NEXT\]|$)/g, '')
        .replace(/\[{1,3}SOURCE:[^\]]*\]{1,3}/g, '');
    return (state, UI) => {
        // eslint-disable-next-line no-new-func
        return new Function('state', 'UI', 'stripStreamingDecorators',
            'return ' + body.replace(/\bfullText\b/g, 'state.fullText') + ';')(state, UI, strip);
    };
};

const chatChecks = (chatSrc, check, label) => {
    const lift = liftChatHandler(chatSrc);
    check(label + ': chat.js passes onReplace to the stream', !!lift);
    if (!lift) {
        return;
    }
    const calls = [];
    const UI = {
        startStreaming: () => calls.push('start'),
        replaceStreamContent: (t, id) => {
            calls.push('replace:' + t + ':' + id);
            return id === 99 ? null : {};
        },
    };
    let state = {fullText: 'I guarantee you will pass.'};
    lift(state, UI)({replace: 'Safe.', messageid: 41, reason: 'grades.guarantee'});
    check(label + ': swaps the streamed text once', calls.join('|') === 'replace:Safe.:41' && state.fullText === 'Safe.',
        calls.join('|'));
    calls.length = 0;
    state = {fullText: ''};
    lift(state, UI)({replace: 'Safe.', messageid: 41});
    check(label + ': starts a message when no text arrived', calls.join('|') === 'start|replace:Safe.:41'
        && state.fullText === 'Safe.', calls.join('|'));
    calls.length = 0;
    state = {fullText: 'kept'};
    lift(state, UI)({replace: 'Other.', messageid: 99});
    check(label + ': an ignored replace leaves the text alone', state.fullText === 'kept');
    calls.length = 0;
    state = {fullText: 'I guarantee you will pass.'};
    const raw = 'Safe. [SOURCE:course]\n[SOLA_NEXT]Ask again||Quiz me[/SOLA_NEXT]';
    lift(state, UI)({replace: raw, messageid: 41});
    check(label + ': the replacement is shown without its markers, and kept whole for onDone',
        calls.join('|') === 'replace:Safe. :41' && state.fullText === raw, calls.join('|'));
    calls.length = 0;
    state = {fullText: 'kept'};
    lift(state, UI)({messageid: 41});
    lift(state, UI)(null);
    check(label + ': a frame without replace text does nothing', calls.length === 0 && state.fullText === 'kept');
};

// ---- run ------------------------------------------------------------------------------------------------------
const run = async(sources, check) => {
    await sseChecks('sse_client.js (src)', sources.sseSrc, check);
    await sseChecks('sse_client.min.js (build)', sources.sseBuild, check);
    uiChecks(sources.uiSrc, check);
    chatChecks(sources.chatSrc, check, 'src');
    check('build: chat.min.js wires onReplace', /onReplace:function/.test(sources.chatBuild));
    check('build: chat.min.js calls replaceStreamContent', sources.chatBuild.includes('replaceStreamContent'));
    check('build: chat.min.js strips the replacement before it is shown',
        /replaceStreamContent\(\w+\(\w+\.replace\),\w+\.messageid\)/.test(sources.chatBuild));
    check('build: ui.min.js exports replaceStreamContent', /replaceStreamContent:/.test(sources.uiBuild));
    check('build: ui.min.js marks data-replaced', /\.replaced="1"/.test(sources.uiBuild));
    check('ui.js exports replaceStreamContent', /replaceStreamContent: replaceStreamContent,/.test(sources.uiSrc));
};

const sources = {
    sseSrc: read('amd', 'src', 'sse_client.js'),
    sseBuild: read('amd', 'build', 'sse_client.min.js'),
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
    const planted = [
        ['chat.js without the onReplace handler', Object.assign({}, sources, {
            chatSrc: sources.chatSrc.replace(CHAT_HANDLER, (h) => (h.includes('UI.replaceStreamContent') ? '\n' : h)),
        })],
        ['sse_client.js that never calls onReplace', Object.assign({}, sources, {
            sseSrc: sources.sseSrc.replace(/callbacks\.onReplace\(data\)/g, 'void 0'),
        })],
        ['chat.js that renders the replacement with its markers', Object.assign({}, sources, {
            chatSrc: sources.chatSrc.replace('UI.replaceStreamContent(stripStreamingDecorators(data.replace), data.messageid)',
                'UI.replaceStreamContent(data.replace, data.messageid)'),
        })],
        ['ui.js that does not mark the element', Object.assign({}, sources, {
            uiSrc: sources.uiSrc.replace(/streamingEl\.dataset\.replaced = '1';/, ''),
        })],
    ];
    for (const [name, srcs] of planted) {
        let caught = 0;
        await run(srcs, (n, ok) => {
            caught += ok ? 0 : 1;
        });
        check('planted defect is caught: ' + name, caught > 0);
    }

    if (failures) {
        console.log('\n' + failures + ' check(s) failed');
        process.exit(1);
    }
    console.log('\nall answer-replace checks passed');
})();
