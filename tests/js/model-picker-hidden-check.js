/**
 * Hidden model picker check (8.4.0).
 *
 * The chat's LLM provider and model pickers are rendered only while the
 * chat_show_model_picker setting is on (templates/chat_widget.mustache,
 * {{#showmodelpicker}}), so on most pages the composer has no picker markup
 * and data-llm-options is an empty list. This check pins the client half:
 *
 *   1. the template wraps the whole picker block in {{#showmodelpicker}};
 *   2. the REAL setComposerLlmOptions() from amd/src/ui.js, lifted and run with
 *      no picker elements, returns without throwing;
 *   3. chat.js adds provider and model to a request only when the server's
 *      options say the picker is enabled (two send paths), in src and build.
 *
 * Run: node tests/js/model-picker-hidden-check.js
 * Exits non-zero on failure so it can gate a release.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (...p) => fs.readFileSync(path.join(root, ...p), 'utf8');
const tpl = read('templates', 'chat_widget.mustache');
const uiSrc = read('amd', 'src', 'ui.js');
const chatSrc = read('amd', 'src', 'chat.js');
const chatBuild = read('amd', 'build', 'chat.min.js');

let failures = 0;
const check = (name, ok, detail) => {
    if (ok) {
        console.log('  ok    ' + name);
    } else {
        failures += 1;
        console.log('  FAIL  ' + name + (detail ? '  -> ' + detail : ''));
    }
};

// 1. Every picker element sits inside the section.
const open = tpl.indexOf('{{#showmodelpicker}}');
const close = tpl.indexOf('{{/showmodelpicker}}');
check('template has one showmodelpicker section', open > -1 && close > open
    && tpl.indexOf('{{#showmodelpicker}}', open + 1) === -1);
const outside = tpl.slice(0, open) + tpl.slice(close);
// The docblock and example context may name the variable; only markup matters.
check('no picker markup outside the section',
    !/class="[^"]*composer-(left|select)/.test(outside));
check('both selects are inside the section',
    /composer-select--provider/.test(tpl.slice(open, close)) && /composer-select--model/.test(tpl.slice(open, close)));

// 2. Lift setComposerLlmOptions and run it with no picker elements.
const start = uiSrc.indexOf('const setComposerLlmOptions = function(config) {');
let depth = 0;
let end = -1;
for (let i = uiSrc.indexOf('{', start); i < uiSrc.length; i++) {
    if (uiSrc[i] === '{') {
        depth++;
    } else if (uiSrc[i] === '}') {
        depth--;
        if (depth === 0) {
            end = i + 1;
            break;
        }
    }
}
check('setComposerLlmOptions found in ui.js', start > -1 && end > start);
try {
    const fn = new Function(
        'let llmControls = null, llmProviderSelect = null, llmModelSelect = null, llmProviderWrap = null,'
        + ' llmModelWrap = null, composerLlmState = {};'
        + uiSrc.slice(start, end) + '; setComposerLlmOptions({enabled: true, providers: [{id: "openai",'
        + ' models: [{id: "m"}]}]}); setComposerLlmOptions({}); return true;');
    check('setComposerLlmOptions is a no-op without picker elements', fn() === true);
} catch (e) {
    check('setComposerLlmOptions is a no-op without picker elements', false, String(e));
}

// 3. A request names a provider only when the server options enable the picker.
check('getResolvedLlmSelection is disabled without server options',
    /if \(!options\.enabled \|\| !providers\.length\) \{\s*return \{\s*enabled: false,/.test(chatSrc));
const sends = chatSrc.match(/postData\.provider = /g) || [];
const guarded = chatSrc.match(/\.enabled && \w+\.provider && \w+\.model\) \{\s*postData\.provider = /g) || [];
check('every send path that names a provider is guarded by enabled (src)',
    sends.length === 2 && guarded.length === 2, sends.length + ' sends, ' + guarded.length + ' guarded');
const builtSends = chatBuild.match(/\.provider=\w+\.provider/g) || [];
const builtGuarded = chatBuild.match(/\.enabled&&\w+\.provider&&\w+\.model&&\(\w+\.provider=\w+\.provider/g) || [];
check('every send path that names a provider is guarded by enabled (build)',
    builtSends.length === 2 && builtGuarded.length === 2, builtSends.length + ' sends, ' + builtGuarded.length + ' guarded');

if (failures) {
    console.log('\n' + failures + ' check(s) failed.');
    process.exit(1);
}
console.log('\nAll model picker checks passed.');
