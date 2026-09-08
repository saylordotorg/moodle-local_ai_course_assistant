/**
 * Citation marker rendering check (S9).
 *
 * The model sometimes groups several sources into one bracket pair,
 * "[[c:2], [c:4]]", instead of writing consecutive "[[c:N]]" markers. The
 * single-marker regex in applyCitations() never matched that form, so the whole
 * group reached the learner as literal text in the message bubble while the
 * source chip for the same answer rendered correctly.
 *
 * This check does two things, and the second matters as much as the first:
 *   1. exercises the normaliser's behaviour, including the inputs it must NOT
 *      touch (ordinary bracketed prose, and the already-correct single form);
 *   2. asserts both regexes are present in amd/build/ui.min.js, because Moodle
 *      serves the built bundle and a source-only fix changes nothing. That
 *      exact staleness has bitten this repo before -- see the Build Process
 *      note in CLAUDE.md.
 *
 * Run: node tests/js/citation-render-check.js
 * Exits non-zero on failure so it can gate a release.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const srcPath = path.join(root, 'amd', 'src', 'ui.js');
const buildPath = path.join(root, 'amd', 'build', 'ui.min.js');

let failures = 0;
const check = (name, ok, detail) => {
    if (ok) {
        console.log('  ok    ' + name);
    } else {
        failures += 1;
        console.log('  FAIL  ' + name + (detail ? '  -> ' + detail : ''));
    }
};

// ---- 1. behaviour of the compressed-group normaliser -------------------------
// Mirrors the regex shipped in amd/src/ui.js applyCitations() (patch 0002).
// If you change it there, change it here; the assertions below pin both the
// source and the built bundle to the same literal so they cannot drift apart.
const GROUP_RE = /\[\[c:(\d+)((?:\]\s*,\s*\[c:\d+)+)\]\]/g;
const normalise = (html) => html.replace(GROUP_RE, (_m, first, rest) =>
    [first].concat(rest.match(/\d+/g) || [])
        .map((n) => '[[c:' + n + ']]')
        .join(''));

const cases = [
    ['...is a corporation [[c:2], [c:4]].', '...is a corporation [[c:2]][[c:4]].', 'two-source group'],
    ['three [[c:1], [c:2], [c:3]] here', 'three [[c:1]][[c:2]][[c:3]] here', 'three-source group'],
    ['no space [[c:5],[c:6]] ok', 'no space [[c:5]][[c:6]] ok', 'group without spaces'],
    ['single [[c:7]] untouched', 'single [[c:7]] untouched', 'already-correct single marker'],
    ['prose [[see note], [other]] untouched', 'prose [[see note], [other]] untouched', 'bracketed prose left alone'],
    ['empty string', 'empty string', 'text with no markers'],
    ['malformed [[c:2], [c:4] left', 'malformed [[c:2], [c:4] left', 'malformed group left verbatim'],
];
for (const [input, expected, label] of cases) {
    const got = normalise(input);
    check('normalise: ' + label, got === expected, JSON.stringify(got));
}

// ---- 2. the source actually contains the normaliser -------------------------
const src = fs.readFileSync(srcPath, 'utf8');
const SHIPPED = '\\[\\[c:(\\d+)((?:\\]\\s*,\\s*\\[c:\\d+)+)\\]\\]';
check(
    'amd/src/ui.js contains the compressed-group normaliser',
    src.indexOf(SHIPPED) !== -1,
    'normaliser regex not found in source'
);

// ---- 3. the BUILT bundle contains it too (stale-build guard) ----------------
const build = fs.readFileSync(buildPath, 'utf8');
check(
    'amd/build/ui.min.js contains the compressed-group regex',
    build.indexOf(SHIPPED) !== -1,
    'built bundle is STALE — run the terser rebuild for ui.js'
);
check(
    'amd/build/ui.min.js still contains the single-marker regex',
    build.indexOf('\\[\\[c:(\\d+)\\]\\]') !== -1,
    'single-marker replace missing from the build'
);

console.log(failures === 0
    ? '\ncitation-render-check: all checks pass'
    : '\ncitation-render-check: ' + failures + ' failure(s)');
process.exit(failures === 0 ? 0 : 1);
