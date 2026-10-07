/**
 * Truncated-answer note check (v7.7.6).
 *
 * When the provider stops because it ran out of output tokens, sse.php stores
 * the turn as 'truncated' and puts `truncated: true` plus a localized
 * `truncatednote` on the done event. Before this, the cut-off text was shown
 * as a finished answer. This check pins the client half:
 *
 *   1. the REAL markMessageTruncated() from amd/src/ui.js, lifted and run
 *      against a minimal DOM stand-in: the note lands between the answer and
 *      its footer, is set as text (never HTML), is added once, and nothing is
 *      added without a note;
 *   2. chat.js calls it from onDone when the done event says truncated, and
 *      falls back to a plain assistant message when no answer text arrived;
 *   3. both changes are present in amd/build, because Moodle serves the built
 *      bundle and a source-only fix changes nothing (CLAUDE.md, Build Process).
 *
 * Run: node tests/js/truncated-note-check.js
 * Exits non-zero on failure so it can gate a release.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const uiSrc = fs.readFileSync(path.join(root, 'amd', 'src', 'ui.js'), 'utf8');
const uiBuild = fs.readFileSync(path.join(root, 'amd', 'build', 'ui.min.js'), 'utf8');
const chatSrc = fs.readFileSync(path.join(root, 'amd', 'src', 'chat.js'), 'utf8');
const chatBuild = fs.readFileSync(path.join(root, 'amd', 'build', 'chat.min.js'), 'utf8');

let failures = 0;
const check = (name, ok, detail) => {
    if (ok) {
        console.log('  ok    ' + name);
    } else {
        failures += 1;
        console.log('  FAIL  ' + name + (detail ? '  -> ' + detail : ''));
    }
};

// ---- a minimal DOM: just what markMessageTruncated touches --------------------
class El {
    constructor(className) {
        this.className = className || '';
        this.children = [];
        this.attrs = {};
        this.textContent = '';
        this.htmlWrites = 0;
    }
    set innerHTML(v) {
        this.htmlWrites += 1;
        this.textContent = String(v);
    }
    setAttribute(k, v) {
        this.attrs[k] = v;
    }
    appendChild(c) {
        this.children.push(c);
        return c;
    }
    insertBefore(c, ref) {
        const i = this.children.indexOf(ref);
        if (i < 0) {
            throw new Error('insertBefore: reference is not a child');
        }
        this.children.splice(i, 0, c);
        return c;
    }
    querySelector(sel) {
        // Supports '.cls' (any depth) and ':scope > .cls' (direct child).
        const direct = sel.startsWith(':scope > ');
        const cls = sel.replace(':scope > ', '').replace(/^\./, '');
        const walk = (node, depth) => {
            for (const c of node.children) {
                if (c.className.split(/\s+/).includes(cls)) {
                    return c;
                }
                if (!direct) {
                    const hit = walk(c, depth + 1);
                    if (hit) {
                        return hit;
                    }
                }
            }
            return null;
        };
        return walk(this, 0);
    }
}
const document = {createElement: () => new El()};

// ---- 1. lift the REAL function out of amd/src/ui.js ---------------------------
const m = uiSrc.match(/\n( {4})const markMessageTruncated = function\([\s\S]*?\n\1\};\n/);
check('markMessageTruncated is defined in amd/src/ui.js', !!m);
if (m) {
    // eslint-disable-next-line no-new-func
    const markMessageTruncated = new Function('document', m[0] + '\nreturn markMessageTruncated;')(document);

    const msg = new El('local-ai-course-assistant__message--assistant');
    const content = msg.appendChild(new El('local-ai-course-assistant__message-content'));
    const footer = msg.appendChild(new El('local-ai-course-assistant__msg-footer'));
    const note = markMessageTruncated(msg, 'This answer was cut short. Ask me to continue.');

    check('a note is returned', !!note);
    check('the note sits between the answer and its footer',
        msg.children[0] === content && msg.children[1] === note && msg.children[2] === footer,
        msg.children.map((c) => c.className).join(' | '));
    check('the note carries the server text', note && note.textContent === 'This answer was cut short. Ask me to continue.');
    check('the note is set as text, never as HTML', note && note.htmlWrites === 0);
    check('the note is announced as a note', note && note.attrs.role === 'note');

    markMessageTruncated(msg, 'This answer was cut short. Ask me to continue.');
    check('a repeated done event adds one note, not two', msg.children.length === 3);

    const bare = new El('local-ai-course-assistant__message--assistant');
    check('no note without text', markMessageTruncated(bare, '') === null && bare.children.length === 0);
    check('no crash without a message element', markMessageTruncated(null, 'x') === null);

    const nofooter = new El('local-ai-course-assistant__message--assistant');
    nofooter.appendChild(new El('local-ai-course-assistant__message-content'));
    markMessageTruncated(nofooter, 'cut');
    check('without a footer the note is appended', nofooter.children.length === 2);
}

// ---- 2. chat.js wiring ---------------------------------------------------------
check('onDone marks a truncated answer',
    /if \(doneData && doneData\.truncated\) \{\s*UI\.markMessageTruncated\(getLastAssistantMessageEl\(\), doneData\.truncatednote \|\| ''\);/
        .test(chatSrc));
check('onDone shows the note on its own when no answer text arrived',
    /\} else if \(doneData && doneData\.truncated && doneData\.truncatednote\) \{[\s\S]{0,300}?addAssistantMsg\(doneData\.truncatednote\);/
        .test(chatSrc));
check('ui.js exports markMessageTruncated', /markMessageTruncated: markMessageTruncated,/.test(uiSrc));

// ---- 3. the built bundles Moodle actually serves -------------------------------
check('amd/build/ui.min.js carries the note class', uiBuild.includes('local-ai-course-assistant__msg-truncated'));
check('amd/build/ui.min.js exports markMessageTruncated', uiBuild.includes('markMessageTruncated'));
check('amd/build/chat.min.js reads truncatednote', chatBuild.includes('truncatednote'));

if (failures) {
    console.log('\n' + failures + ' check(s) failed');
    process.exit(1);
}
console.log('\nall truncated-note checks passed');
