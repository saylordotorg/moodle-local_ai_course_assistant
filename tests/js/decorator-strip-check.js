/**
 * Assistant-decorator strip check (D1 + D2).
 *
 * Two protocol tokens were reaching learners in production (10-prompt run on
 * CS101/BUS101, 43 captured responses):
 *
 *   D1  an UNTERMINATED "[SOLA_NEXT]Tell me more" survived to the committed
 *       bubble. The streaming path hid it with a partial-tag guard, but
 *       onDone re-parses the RAW fullText through parseAssistantDecorators(),
 *       which only knew the well-formed [SOLA_NEXT]…[/SOLA_NEXT] form. Hidden
 *       while streaming, visible once finished.
 *
 *   D2  "[SOURCE:…]" tags leaked three ways: the stripper had no /g flag so
 *       only the FIRST of several inline tags was removed; the vocabulary was
 *       closed (page|course|general|activity) so free-form labels such as
 *       "[SOURCE:Unit 1: Computer Programming]" never matched; and the model
 *       emits a DOUBLE-bracketed inline form "[[SOURCE:activity:86467]]" that
 *       the single-bracket pattern would have half-eaten.
 *
 * Every fixture below marked "verbatim" is a literal production string.
 *
 * The behavioural half of this check does NOT re-implement the parser. It
 * lifts the real regex constants and the real parseAssistantDecorators() body
 * out of amd/src/chat.js and evaluates them, so the assertions exercise the
 * shipped code and cannot drift from it. The last section then asserts the
 * new pattern is present in amd/build/chat.min.js, because Moodle serves the
 * built bundle and a source-only fix changes nothing (see CLAUDE.md, Build
 * Process).
 *
 * Run: node tests/js/decorator-strip-check.js
 * Exits non-zero on failure so it can gate a release.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const chatSrcPath = path.join(root, 'amd', 'src', 'chat.js');
const chatBuildPath = path.join(root, 'amd', 'build', 'chat.min.js');
const radarSrcPath = path.join(root, 'amd', 'src', 'learning_radar.js');
const radarBuildPath = path.join(root, 'amd', 'build', 'learning_radar.min.js');

let failures = 0;
const check = (name, ok, detail) => {
    if (ok) {
        console.log('  ok    ' + name);
    } else {
        failures += 1;
        console.log('  FAIL  ' + name + (detail ? '  -> ' + detail : ''));
    }
};

const chatSrc = fs.readFileSync(chatSrcPath, 'utf8');
const chatBuild = fs.readFileSync(chatBuildPath, 'utf8');
const radarSrc = fs.readFileSync(radarSrcPath, 'utf8');
const radarBuild = fs.readFileSync(radarBuildPath, 'utf8');

// ---- load the REAL parser out of amd/src/chat.js -----------------------------
// Module-level constants the lifted functions close over: regex literals
// (`    const FOO_RE = /…/flags;`) and plain numeric tunables
// (`    const FOO_MAX = 200;`). The numeric form matters — omitting it made the
// lifted parser throw ReferenceError the moment a threshold was introduced.
const constLines = chatSrc.split('\n')
    .filter((l) => /^ {4}const [A-Z0-9_]+ = (\/.*\/[gimsuy]*|-?\d+(\.\d+)?);\s*$/.test(l))
    .join('\n');

// Lift a 4-space-indented `const NAME = function(...) {…};` out of the source.
// Every nested closer is indented deeper, so the first line that is exactly
// "    };" terminates the declaration.
const lift = (name) => {
    const marker = '    const ' + name + ' = function(';
    const start = chatSrc.indexOf(marker);
    if (start === -1) {
        console.log('  FAIL  could not locate ' + name + ' in amd/src/chat.js');
        process.exit(1);
    }
    const rest = chatSrc.slice(start);
    const end = rest.indexOf('\n    };\n');
    if (end === -1) {
        console.log('  FAIL  could not locate the end of ' + name);
        process.exit(1);
    }
    const body = rest.slice(0, end + '\n    };'.length);
    try {
        // eslint-disable-next-line no-new-func
        return new Function(constLines + '\n' + body + '\nreturn ' + name + ';')();
    } catch (e) {
        console.log('  FAIL  could not evaluate ' + name + '  -> ' + e.message);
        return process.exit(1);
    }
};

const parse = lift('parseAssistantDecorators');
const stripStreaming = lift('stripStreamingDecorators');

const noTagsLeft = (s) => s.indexOf('SOLA_NEXT') === -1 && s.indexOf('SOURCE') === -1;

// ---- D1: unterminated [SOLA_NEXT] -------------------------------------------
{
    // verbatim: BUS101, gemini-2.5-flash, prompt 5.
    const input = '...their long-term health depends on maintaining that balance.\n'
        + '[SOLA_NEXT]Tell me more';
    const got = parse(input);
    check('D1 unterminated [SOLA_NEXT] is stripped from the committed text',
        got.text.indexOf('SOLA_NEXT') === -1, JSON.stringify(got.text));
    check('D1 unterminated [SOLA_NEXT] leaves the prose intact',
        got.text === '...their long-term health depends on maintaining that balance.',
        JSON.stringify(got.text));
    check('D1 chips are still harvested from an unterminated block',
        got.suggestions.length === 1 && got.suggestions[0] === 'Tell me more',
        JSON.stringify(got.suggestions));
}
{
    const got = parse('Answer.\n[SOLA_NEXT]What is a variable?||Show me an example');
    check('D1 unterminated block splits on || like the closed form',
        got.suggestions.join('|') === 'What is a variable?|Show me an example',
        JSON.stringify(got.suggestions));
}
{
    const got = parse('Answer.\n[SOLA_NEXT]suggestion 1||chip 2||Real chip');
    check('D1 placeholder filter still applies to an unterminated block',
        got.suggestions.join('|') === 'Real chip', JSON.stringify(got.suggestions));
}

// ---- D1 regression: the well-formed block still works -------------------------
{
    const got = parse('Body text.\n[SOLA_NEXT]a||b[/SOLA_NEXT]');
    check('well-formed [SOLA_NEXT]a||b[/SOLA_NEXT] still yields both chips',
        got.suggestions.join('|') === 'a|b', JSON.stringify(got.suggestions));
    check('well-formed block is stripped, prose preserved',
        got.text === 'Body text.', JSON.stringify(got.text));
}

// ---- D2: several inline double-bracketed [[SOURCE:activity:N]] ---------------
{
    // verbatim fragments: CS101, several in ONE answer.
    const input = 'Unit 1 sets the stage for everything else you\'ll learn about programming '
        + '[[SOURCE:activity:86465]]. Variables come next [[SOURCE:activity:86467]], then more '
        + '[[SOURCE:activity:86467]], input and output [[SOURCE:activity:86474]], and finally '
        + 'recursion [[SOURCE:activity:103979]].';
    const got = parse(input);
    check('D2 every inline [[SOURCE:activity:N]] is removed (not just the first)',
        got.text.indexOf('SOURCE') === -1, JSON.stringify(got.text));
    check('D2 double brackets leave no stray [ or ] behind',
        got.text.indexOf('[') === -1 && got.text.indexOf(']') === -1, JSON.stringify(got.text));
    check('D2 prose around the stripped tags is intact',
        got.text === 'Unit 1 sets the stage for everything else you\'ll learn about programming. '
            + 'Variables come next, then more, input and output, and finally recursion.',
        JSON.stringify(got.text));
    check('D2 the first recognised tag still drives the source pill',
        got.sourceType === 'activity' && got.sourceCmid === '86465',
        got.sourceType + '/' + got.sourceCmid);
}

// ---- D2: free-form labels ----------------------------------------------------
{
    // verbatim.
    const got = parse('Start with the basics. [SOURCE:1.1: Fundamentals of Computers and Their Use]');
    check('D2 free-form [SOURCE:1.1: …] is stripped',
        got.text === 'Start with the basics.', JSON.stringify(got.text));
    check('D2 free-form label does NOT become a sourceType',
        got.sourceType === null, String(got.sourceType));
}
{
    // verbatim.
    const got = parse('CS101 has eight units.\n[SOURCE:Unit 1: Computer Programming]');
    check('D2 free-form [SOURCE:Unit 1: …] is stripped',
        got.text === 'CS101 has eight units.', JSON.stringify(got.text));
    check('D2 free-form unit label does NOT become a sourceType',
        got.sourceType === null, String(got.sourceType));
}

// ---- D2 regression: recognised vocabulary still resolves ----------------------
{
    const got = parse('Answer body.\n[SOURCE:course]');
    check('recognised [SOURCE:course] still yields sourceType "course"',
        got.sourceType === 'course' && got.sourceCmid === null,
        got.sourceType + '/' + got.sourceCmid);
    check('recognised [SOURCE:course] is stripped from the text',
        got.text === 'Answer body.', JSON.stringify(got.text));
}
{
    const got = parse('Answer body.\n[SOURCE:activity:4242]');
    check('recognised [SOURCE:activity:4242] still yields the cmid',
        got.sourceType === 'activity' && got.sourceCmid === '4242',
        got.sourceType + '/' + got.sourceCmid);
}

// ---- neighbouring features must survive --------------------------------------
{
    const input = 'CS101 runs 26 hours [[c:1]] across eight units [[c:2]]. [SOURCE:course]';
    const got = parse(input);
    check('[[c:N]] inline citation markers survive untouched',
        got.text === 'CS101 runs 26 hours [[c:1]] across eight units [[c:2]].',
        JSON.stringify(got.text));
}
{
    const input = 'Use array[0], see [note] for details (compare [1] and [2]).';
    const got = parse(input);
    check('ordinary bracketed prose is not damaged',
        got.text === input, JSON.stringify(got.text));
}
{
    const input = 'Answer.\n[SOLA_SCORE]{"score":4}[/SOLA_SCORE]';
    const got = parse(input);
    check('[SOLA_SCORE] parsing is unaffected',
        got.text === 'Answer.' && got.scoreData && got.scoreData.score === 4,
        JSON.stringify(got.text) + ' ' + JSON.stringify(got.scoreData));
}

// ---- both defects in one response --------------------------------------------
{
    const input = 'Recursion calls itself [[SOURCE:activity:103979]] until a base case is hit '
        + '[SOURCE:Unit 8: Recursive Methods].\n[SOLA_NEXT]Show me a base case||Tell me more';
    const got = parse(input);
    check('D1+D2 combined: nothing tag-like reaches the learner',
        noTagsLeft(got.text), JSON.stringify(got.text));
    check('D1+D2 combined: chips harvested and cmid resolved',
        got.suggestions.length === 2 && got.sourceType === 'activity' && got.sourceCmid === '103979',
        JSON.stringify(got.suggestions) + ' ' + got.sourceType + '/' + got.sourceCmid);
}

// ---- streaming frames: the asymmetry that caused D1 -------------------------
// D1 was "hidden while streaming, visible once finished". Assert the two paths
// agree on the finished text AND that no intermediate frame ever shows a tag.
{
    const full = 'Recursion calls itself [[SOURCE:activity:103979]] until a base case is hit.\n'
        + '[SOLA_NEXT]Show me a base case';
    const leaking = [];
    for (let i = 1; i <= full.length; i++) {
        const frame = stripStreaming(full.slice(0, i));
        if (/SOURCE|SOLA_NEXT|SOLA_SCORE/.test(frame) || /\[\[?[A-Z_]*$/.test(frame)) {
            leaking.push(i + ':' + JSON.stringify(frame));
        }
    }
    check('no streaming frame ever shows a whole or partial control tag',
        leaking.length === 0, leaking.slice(0, 3).join('  '));
    check('the last streaming frame matches the committed text (no reappearing tag)',
        stripStreaming(full) === parse(full).text,
        JSON.stringify(stripStreaming(full)) + ' vs ' + JSON.stringify(parse(full).text));
}
{
    const full = 'See array[0] and [note] plus [[c:1]] here.';
    check('streaming leaves ordinary bracketed prose and [[c:1]] intact once complete',
        stripStreaming(full) === full, JSON.stringify(stripStreaming(full)));
}

// ---- source + built-bundle pins ----------------------------------------------
const SOURCE_STRIP_PATTERN = '\\[{1,3}SOURCE:';
const NEXT_OPEN_PATTERN = '\\[SOLA_NEXT\\]([\\s\\S]*)$';

check('amd/src/chat.js declares a global source-tag stripper (/g)',
    /const SOURCE_STRIP_RE = \/.*\[\{1,3\}SOURCE:.*\/g;/.test(chatSrc),
    'SOURCE_STRIP_RE with the variable bracket-depth class and a /g flag not found');
check('amd/src/chat.js declares an unterminated [SOLA_NEXT] pattern',
    chatSrc.indexOf(NEXT_OPEN_PATTERN) !== -1,
    'NEXT_OPEN_RE not found in source');
check('amd/build/chat.min.js contains the source-tag stripper (stale-build guard)',
    chatBuild.indexOf(SOURCE_STRIP_PATTERN) !== -1,
    'built bundle is STALE — run the terser rebuild for chat.js');
check('amd/src/chat.js routes the streaming path through stripStreamingDecorators()',
    /UI\.updateStreamContent\(stripStreamingDecorators\(fullText\)\)/.test(chatSrc),
    'onToken no longer calls stripStreamingDecorators(fullText)');
check('amd/build/chat.min.js contains the unterminated [SOLA_NEXT] pattern',
    chatBuild.indexOf(NEXT_OPEN_PATTERN) !== -1,
    'built bundle is STALE — run the terser rebuild for chat.js');

// ---- learning_radar.js has the same closing-tag assumption --------------------
// Pinned as literal source text: the radar module is a standalone (non-AMD-core)
// script whose stream handler cannot be evaluated in isolation here.
const RADAR_OPEN_HARVEST = '\\[SOLA_NEXT\\]([\\s\\S]*)$';
const RADAR_OPEN_STRIP = "replace(/\\[SOLA_NEXT\\][\\s\\S]*/g, '')";
check('learning_radar renderResponse() also strips an unterminated [SOLA_NEXT]',
    radarSrc.indexOf(RADAR_OPEN_STRIP) !== -1,
    'renderResponse still requires the closing tag');
check('learning_radar harvests chips from an unterminated [SOLA_NEXT]',
    radarSrc.indexOf(RADAR_OPEN_HARVEST) !== -1,
    'no open-form harvest in learning_radar.js');
check('amd/build/learning_radar.min.js contains the open-form harvest (stale-build guard)',
    radarBuild.indexOf(RADAR_OPEN_HARVEST) !== -1,
    'built bundle is STALE — run the terser rebuild for learning_radar.js');
check('amd/build/learning_radar.min.js contains the open-form strip (stale-build guard)',
    radarBuild.indexOf('\\[SOLA_NEXT\\][\\s\\S]*/g') !== -1,
    'built bundle is STALE — run the terser rebuild for learning_radar.js');


// ---- round-2: regressions the first fix introduced ---------------------------
// Each fixture below was REPRODUCED by adversarial verification against the
// round-1 fix. They are kept because every one of them was, at the time, a
// worse learner-facing failure than the defect being repaired.

// P1 — a mangled closing tag was treated as "unterminated", so the closing
// token was harvested INTO the last chip and became the visible label of a
// clickable button that re-sends itself to the model.
['[/SOLA_NEXT ]', '[/sola_next]', '[/ SOLA_NEXT]'].forEach((close) => {
    const got = parse('Compound interest grows exponentially.\n[SOURCE:activity:47]\n'
        + '[SOLA_NEXT]Show the formula||Give an example||Quiz me||What next?' + close);
    check('P1 mangled close ' + close + ' never reaches a chip label',
        !got.suggestions.some((c) => /SOLA_NEXT/i.test(c)), JSON.stringify(got.suggestions));
    check('P1 mangled close ' + close + ' still yields 4 clean chips',
        got.suggestions.length === 4 && got.suggestions[3] === 'What next?',
        JSON.stringify(got.suggestions));
    check('P1 mangled close ' + close + ' keeps the source pill',
        got.sourceType === 'activity' && got.sourceCmid === '47',
        got.sourceType + '/' + got.sourceCmid);
});

// P2 — an open tag mid-answer stripped to end-of-string, silently deleting 99
// characters of real answer text and rendering them as one giant button.
{
    const got = parse('Supply meets demand at equilibrium.\n[SOLA_NEXT]Show me a graph\n'
        + 'Actually, elasticity matters here, and you should read section 3.2 before the quiz.');
    check('P2 prose after a non-terminal [SOLA_NEXT] is NOT deleted',
        got.text.indexOf('elasticity matters here') !== -1, JSON.stringify(got.text));
    check('P2 the marker itself is still removed',
        got.text.indexOf('[SOLA_NEXT]') === -1, JSON.stringify(got.text));
    check('P2 nothing is harvested from a non-terminal payload',
        got.suggestions.length === 0, JSON.stringify(got.suggestions));
}

// P3 — the streaming guard used [^\[]*$, which aborted at the first bracket in
// the payload, so bracket-bearing chips (big-O, LaTeX, fenced code — the
// premium-router shapes) left the raw tag visible for every frame.
{
    const leakingFrames = (full) => {
        let n = 0;
        for (let i = 1; i <= full.length; i++) {
            if (/\[SOLA_NEXT|\[SOURCE|\[SOLA_SCORE/.test(stripStreaming(full.slice(0, i)))) {
                n += 1;
            }
        }
        return n;
    };
    check('P3 bracket-bearing chips never leak a frame',
        leakingFrames('Big-O measures growth.\n[SOLA_NEXT]Explain O(n) [big-O] notation||Show a table') === 0,
        'leaking frames > 0');
    check('P3 a well-formed block never leaks a frame',
        leakingFrames('Done.\n[SOLA_NEXT]Real one||Real two[/SOLA_NEXT]') === 0,
        'leaking frames > 0');
}

// P4 — the open-tag strip ran BEFORE SOURCE/SCORE recognition, so a tag that
// followed an unterminated [SOLA_NEXT] was destroyed before it could be read:
// the source pill vanished and the practice score card never rendered.
{
    const got = parse('Recursion needs a base case.\n[SOLA_NEXT]Give an example\n'
        + 'Here is one: factorial(0) = 1.\n[SOURCE:activity:9]');
    check('P4 a [SOURCE:…] after an open [SOLA_NEXT] still sets the pill',
        got.sourceType === 'activity' && got.sourceCmid === '9',
        got.sourceType + '/' + got.sourceCmid);
}
{
    const got = parse('Nice work.\n[SOLA_NEXT]Try again\n'
        + '[SOLA_SCORE]{"overall":4,"criteria":[]}[/SOLA_SCORE]');
    check('P4 a [SOLA_SCORE] after an open [SOLA_NEXT] is still parsed',
        got.scoreData !== null && got.scoreData.overall === 4, JSON.stringify(got.scoreData));
    check('P4 raw score JSON is never served as a chip',
        !got.suggestions.some((c) => c.indexOf('overall') !== -1),
        JSON.stringify(got.suggestions));
}

// P5 — triple brackets left a bare "[]" in the prose.
{
    const got = parse('Text here [[[SOURCE:page]]] tail.');
    check('P5 triple-bracketed [[[SOURCE:…]]] leaves no empty [] behind',
        got.text.indexOf('[]') === -1, JSON.stringify(got.text));
}

console.log(failures === 0
    ? '\ndecorator-strip-check: all checks pass'
    : '\ndecorator-strip-check: ' + failures + ' failure(s)');
process.exit(failures === 0 ? 0 : 1);
