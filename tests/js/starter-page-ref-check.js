/**
 * Translated Explain-This page-title check.
 *
 * The defect: amd/src/chat.js takes the translated branch first whenever the
 * learner's UI language is not English, and RETURNS from it. The English
 * branch below it is the only place {page} is interpolated, so a non-English
 * learner's "Explain This" click has never carried the page title at all.
 * Nothing looked broken: the Redash report's "which pages students asked SOLA
 * to explain" list simply described the English-speaking subset of the cohort,
 * and did it silently.
 *
 * The fix appends `: "<title>"` rather than interpolating, because the
 * translated prompts in speech.js carry no {page} placeholder and adding one
 * to 45 languages would require the grammatical position to be right in each.
 * Pulse Check's reports/sola_report.py parses that exact shape back out,
 * gated on a known translated prefix.
 *
 * This check lifts the real dispatch block out of amd/src/chat.js and
 * evaluates it, so the assertions exercise the shipped code rather than a
 * paraphrase of it. The last section asserts the behaviour reached
 * amd/build/chat.min.js, because Moodle serves the built bundle and a
 * source-only change does nothing (see CLAUDE.md, Build Process).
 *
 * Run: node tests/js/starter-page-ref-check.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'amd/src/chat.js'), 'utf8');

let failures = 0;
function check(name, actual, expected) {
    const ok = actual === expected;
    if (!ok) {
        failures++;
        console.error(`FAIL  ${name}\n        expected: ${JSON.stringify(expected)}\n        actual:   ${JSON.stringify(actual)}`);
    } else {
        console.log(`ok    ${name}`);
    }
}

// --- lift the real branch out of source -------------------------------------
// Anchored on the comment that marks the block, through the closing brace of
// the `if (translatedPrompt)` body. If chat.js is restructured this throws
// rather than silently testing nothing.
const START = SRC.indexOf("// Try translated prompt first when UI is set to a non-English language.");
if (START < 0) {
    console.error("FAIL  could not locate the translated-starter branch in amd/src/chat.js");
    process.exit(1);
}
const END = SRC.indexOf("if (starterPrompt) {", START);
if (END < 0) {
    console.error("FAIL  could not locate the end of the translated-starter branch");
    process.exit(1);
}
const BLOCK = SRC.slice(START, END);

// The one line under test, lifted verbatim.
const APPEND = BLOCK.match(/if \(promptKey === 'helpPage' && currentPageTitle\) \{[\s\S]*?\}/);
if (!APPEND) {
    console.error("FAIL  the page-title append is missing from the translated branch");
    process.exit(1);
}

function send(promptKey, translated, currentPageTitle) {
    let translatedPrompt = translated;
    // eslint-disable-next-line no-eval
    eval(APPEND[0]);
    return translatedPrompt;
}

// --- behaviour ---------------------------------------------------------------

// Spanish, verbatim from amd/src/speech.js STARTER_PROMPTS['es'].helpPage.
check('a Spanish Explain-This click carries the page title',
    send('helpPage', 'Ayúdame a entender esta página', 'Unit 3: Supply Curves'),
    'Ayúdame a entender esta página: "Unit 3: Supply Curves"');

// Arabic, verbatim from STARTER_PROMPTS['ar'].helpPage. RTL text must be
// appended to, not reordered.
check('an Arabic Explain-This click carries the page title',
    send('helpPage', 'ساعدني في فهم هذه الصفحة', 'SWOT Analysis'),
    'ساعدني في فهم هذه الصفحة: "SWOT Analysis"');

// Widening past helpPage would break the reporting side's EXACT-match arm for
// the other four starter families, sending every translated Study Plan click
// back into the typed-questions bucket -- the precise bug the generated
// starter list exists to fix.
check('a Quiz Me click is left alone even on a titled page',
    send('quiz', 'أعطني اختباراً تدريبياً', 'SWOT Analysis'),
    'أعطني اختباراً تدريبياً');

check('a Study Plan click is left alone even on a titled page',
    send('studyPlan', 'ساعدني في إنشاء خطة دراسية', 'SWOT Analysis'),
    'ساعدني في إنشاء خطة دراسية');

// An empty title must append nothing. `: ""` parses as a topic with an empty
// name, which the report drops -- losing the click from BOTH buckets.
check('an empty page title appends nothing',
    send('helpPage', 'ساعدني في فهم هذه الصفحة', ''),
    'ساعدني في فهم هذه الصفحة');

check('a null page title appends nothing',
    send('helpPage', 'ساعدني في فهم هذه الصفحة', null),
    'ساعدني في فهم هذه الصفحة');

// Appending, not interpolating: grep -c '{page}' amd/src/speech.js is 0, so an
// interpolation would be a silent no-op that loses the title again.
check('speech.js carries no {page} placeholder to interpolate into',
    fs.readFileSync(path.join(ROOT, 'amd/src/speech.js'), 'utf8').includes('{page}'),
    false);

// --- the built bundle --------------------------------------------------------
// Moodle serves amd/build/*.min.js. A source-only change ships nothing, which
// is how amd/build/sse_client.min.js sat two months behind its source.
const BUILT = path.join(ROOT, 'amd/build/chat.min.js');
if (!fs.existsSync(BUILT)) {
    failures++;
    console.error('FAIL  amd/build/chat.min.js does not exist');
} else {
    const min = fs.readFileSync(BUILT, 'utf8');
    // The exact minified shape, tolerant of mangled identifiers but NOT of the
    // behaviour being absent:  "helpPage"===g&&E&&(m+=': "'+E+'"')
    // A loose check for the word "helpPage" passes against the OLD bundle,
    // which is the stale-build failure this section exists to catch.
    const APPENDED = /"helpPage"===\w+&&\w+&&\(\w+\+=': "'\+\w+\+'"'\)/;
    check('the built bundle contains the helpPage page-title append',
        APPENDED.test(min), true);
}

console.log(failures === 0 ? '\nAll checks passed.' : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
