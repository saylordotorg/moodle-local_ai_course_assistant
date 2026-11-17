/**
 * Hardcoded brand name check (v8.0.0).
 *
 * After an operator rebrands the plugin in Essentials > Branding, learners
 * still saw "SOLA": the floating button's hover tooltip was rewritten in the
 * browser from a bundled translation that hardcoded the name in all 46
 * languages, and a dozen other JS-built strings named it literally. This
 * check pins the fix:
 *
 *   1. no language's `open` value in amd/src/i18n_strings.js contains the
 *      literal 'SOLA', and each carries the {$a} brand placeholder;
 *   2. the REAL resolveBrandText() lifted from amd/src/chat.js resolves EVERY
 *      {$a} and every [[token]] from the widget root's data attributes, and
 *      applyI18n and applyHelpPanelI18n both use it;
 *   3. a static scan of amd/src/*.js fails on any new learner-facing string
 *      literal holding 'SOLA' outside the allowlist (fallbacks used only when
 *      the root carries no name, protocol tags, console logs, comments);
 *   4. the built bundles Moodle serves (amd/build) carry the fix.
 *
 * Run: node tests/js/branding-literal-check.js
 * Exits non-zero on failure so it can gate a release.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');

let failures = 0;
const check = (name, ok, detail) => {
    if (ok) {
        console.log('  ok    ' + name);
    } else {
        failures += 1;
        console.log('  FAIL  ' + name + (detail ? '  -> ' + detail : ''));
    }
};

// ---- 1. the bundled translations ------------------------------------------------
const loadI18n = (src) => {
    let mod = null;
    // eslint-disable-next-line no-new-func
    new Function('define', src)(function(deps, factory) {
        mod = factory();
    });
    return mod;
};
const i18nSrc = read('amd/src/i18n_strings.js');
const I18n = loadI18n(i18nSrc);
check('i18n_strings.js loads and exposes get()', I18n && typeof I18n.get === 'function');

const langs = (i18nSrc.match(/^ {8}([a-z]{2,3}(?:_[a-z]{2})?): \{$/gm) || [])
    .map((l) => l.trim().replace(/: \{$/, ''));
check('the table holds 45 language packs besides English', langs.length === 45, String(langs.length));
const offenders = [];
const noPlaceholder = [];
['en'].concat(langs).forEach((code) => {
    const v = I18n.get(code, 'open');
    if (/SOLA/.test(v)) {
        offenders.push(code + '=' + v);
    }
    if (v.indexOf('{$a}') === -1) {
        noPlaceholder.push(code + '=' + v);
    }
});
check('no language\'s open label contains the literal SOLA', offenders.length === 0, offenders.join(', '));
check('every language\'s open label carries the {$a} brand placeholder', noPlaceholder.length === 0,
    noPlaceholder.join(', '));

// ---- 2. the resolver in chat.js -------------------------------------------------
const chatSrc = read('amd/src/chat.js');
const lift = (src, name) => {
    const re = new RegExp('\\n( {4})const ' + name + ' = function\\([\\s\\S]*?\\n\\1\\};\\n');
    const m = src.match(re);
    return m ? m[0] : null;
};
const parts = ['brandTokensOf', 'brandShortName', 'resolveBrandText'].map((n) => lift(chatSrc, n));
check('chat.js defines brandTokensOf, brandShortName and resolveBrandText', parts.every(Boolean));
if (parts.every(Boolean)) {
    // eslint-disable-next-line no-new-func
    const resolveBrandText = new Function(parts.join('\n') + '\nreturn resolveBrandText;')();
    const rootEl = {dataset: {
        shortname: 'Atlas',
        brandtokens: JSON.stringify({tutorshort: 'Atlas', tutorname: 'Atlas Tutor', uniname: 'Example University', unishort: 'EXU'}),
    }};
    check('the English open label resolves to the configured name',
        resolveBrandText(I18n.get('en', 'open'), rootEl) === 'Open Atlas', resolveBrandText(I18n.get('en', 'open'), rootEl));
    check('the Japanese open label keeps its word order',
        resolveBrandText(I18n.get('ja', 'open'), rootEl) === 'Atlasを開く', resolveBrandText(I18n.get('ja', 'open'), rootEl));
    check('every {$a}, not just the first, is replaced',
        resolveBrandText('{$a} and {$a}', rootEl) === 'Atlas and Atlas');
    check('[[token]] brand tokens are replaced',
        resolveBrandText('[[tutorname]] at [[uniname]] ([[tutorshort]], [[unishort]])', rootEl)
            === 'Atlas Tutor at Example University (Atlas, EXU)');
    check('the token map alone is enough when data-shortname is missing',
        resolveBrandText('Open {$a}', {dataset: {brandtokens: '{"tutorshort":"Atlas"}'}}) === 'Open Atlas');
    check('a broken token map does not throw', resolveBrandText('Open {$a}', {dataset: {brandtokens: '{'}}) === 'Open SOLA');
    check('empty input passes through', resolveBrandText('', rootEl) === '');
}
const applyI18nBody = (chatSrc.match(/const applyI18n = function\([\s\S]*?\n {4}\};\n/) || [''])[0];
const helpBody = (chatSrc.match(/const applyHelpPanelI18n = function\([\s\S]*?\n {4}\};\n/) || [''])[0];
check('applyI18n resolves through resolveBrandText', /resolveBrandText\(I18nStrings\.get\(lang, key\), root\)/.test(applyI18nBody));
check('applyI18n no longer replaces only the first {$a}', applyI18nBody.indexOf(".replace('{$a}'") === -1);
check('applyHelpPanelI18n resolves through resolveBrandText', /resolveBrandText\(str, root\)/.test(helpBody));

// ---- 3. static scan for new literal brand names ---------------------------------
// A line may name SOLA only for one of these reasons.
const ALLOWED = [
    [/SOLA_[A-Z]/, 'protocol tag or identifier (SOLA_NEXT, SOLA_I18N, ...)'],
    [/\|\|\s*'SOLA'/, 'fallback used only when the widget root carries no name'],
    [/return 'SOLA';/, 'fallback used only when the widget root carries no name'],
    [/: 'SOLA'\)/, 'fallback used only when UI.brandShortName is missing'],
    [/console\.(log|warn|error|info|debug)\(/, 'console log'],
    [/'\[SOLA[\] ]/, 'console log prefix'],
];
// learning_radar.js: the admin-only Redash setup guide, where "SOLA" names the
// Redash data source and the API key setting the admin creates; not learner-facing.
const ALLOWED_FILES = ['learning_radar.js'];

const stripComments = (src) => src
    .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '))
    .replace(/(^|[^:'"\\])\/\/[^\n]*/g, '$1');

const hits = [];
fs.readdirSync(path.join(root, 'amd', 'src')).filter((f) => f.endsWith('.js')).forEach((f) => {
    if (ALLOWED_FILES.includes(f)) {
        return;
    }
    const lines = stripComments(read('amd/src/' + f)).split('\n');
    lines.forEach((line, i) => {
        if (!/SOLA/.test(line)) {
            return;
        }
        // Drop allowed spans, then see whether SOLA is still there.
        let rest = line.replace(/SOLA_[A-Z_]*/g, '');
        if (!/SOLA/.test(rest)) {
            return;
        }
        if (ALLOWED.some(([re]) => re.test(line))) {
            return;
        }
        hits.push(f + ':' + (i + 1) + ': ' + line.trim());
    });
});
check('no learner-facing string literal in amd/src names SOLA', hits.length === 0, '\n        ' + hits.join('\n        '));

// ---- 4. the built bundles -------------------------------------------------------
const built = (f) => read('amd/build/' + f + '.min.js');
const i18nBuilt = loadI18n(built('i18n_strings'));
check('amd/build/i18n_strings.min.js has no SOLA in any open label',
    i18nBuilt && ['en'].concat(langs).every((c) => !/SOLA/.test(i18nBuilt.get(c, 'open'))));
check('amd/build/chat.min.js carries the shared resolver', built('chat').includes('brandtokens')
    && !built('chat').includes('SOLA is paused'));
check('amd/build/ui.min.js names no SOLA in the rating panel', !built('ui').includes('How would you rate SOLA')
    && built('ui').includes('brandShortName'));
check('amd/build/quiz.min.js names no SOLA in the intro card', !built('quiz').includes('SOLA will'));
check('amd/build/realtime.min.js no longer says You are SOLA', !built('realtime').includes('You are SOLA')
    && built('realtime').includes('ellInstructions'));

if (failures) {
    console.log('\n' + failures + ' check(s) failed.');
    process.exit(1);
}
console.log('\nAll brand-name checks passed.');
