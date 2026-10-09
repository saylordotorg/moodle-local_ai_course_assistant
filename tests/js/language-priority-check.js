/**
 * Reply-language priority check.
 *
 * SOLA answers in 46 languages, chosen in this order:
 *   1. the language the question is written in -- but a language the learner
 *      SAVED is only changed after SOLA asks in chat, and the setting is updated
 *      only if they agree;
 *   2. the language saved in SOLA's language picker;
 *   3. the browser's language.
 *
 * The first rule is applied by the model (see language_support.php); the other
 * two, the marker that carries the question, and the buttons are browser code,
 * checked here against the real source, not a copy. The last section asserts the
 * built bundles carry the change, because Moodle serves amd/build.
 *
 * Run: node tests/js/language-priority-check.js
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

// ---- speech.js: saved language, then browser, then Moodle -----------------------
const speechSrc = read('amd', 'src', 'speech.js');
const loadSpeech = ({stored, languages, moodle, choice}) => {
    let mod = null;
    const store = stored ? {aica_lang: stored} : {};
    if (choice) {
        store.aica_lang_choice = '1';
    }
    const localStorage = {
        getItem: (k) => (k in store ? store[k] : null),
        setItem: (k, v) => { store[k] = String(v); },
        removeItem: (k) => { delete store[k]; },
    };
    const navigator = {languages: languages || [], language: (languages || [])[0]};
    const M = {cfg: {language: moodle || 'en'}};
    // eslint-disable-next-line no-new-func
    new Function('define', 'navigator', 'localStorage', 'M', speechSrc)((d, f) => { mod = f(); }, navigator, localStorage, M);
    return mod;
};

console.log('priority');
{
    // Before 7.9.0 the browser's language was written to aica_lang on first visit.
    const guess = loadSpeech({stored: 'es', languages: ['es-MX']});
    check('an old stored value equal to the browser language is not a choice',
        guess.getSavedLang() === null && guess.getLangSource() === 'default');
    const old = loadSpeech({stored: 'fr', languages: ['es-MX']});
    check('an old stored value that differs from the browser must have been a choice',
        old.getSavedLang() === 'fr' && old.getLangSource() === 'saved');
    const picked = loadSpeech({stored: 'es', languages: ['es-MX'], choice: true});
    check('a value the picker wrote is a choice even when it equals the browser',
        picked.getSavedLang() === 'es');
    const fresh = loadSpeech({languages: ['es-MX']});
    fresh.setLang('es');
    check('setLang records a choice', fresh.getSavedLang() === 'es' && fresh.getLangSource() === 'saved');
    fresh.clearLang();
    check('clearLang forgets the choice', fresh.getSavedLang() === null);
    const lock = loadSpeech({languages: ['es-MX']});
    lock.setForcedLang('en');
    check('an English lock forces English without saving anything',
        lock.getLang() === 'en' && lock.getSavedLang() === null && lock.getLangSource() === 'default');
}
{
    const s = loadSpeech({stored: 'fr', languages: ['es-MX']});
    check('a saved language beats the browser', s.getLang() === 'fr' && s.getLangSource() === 'saved');
}
{
    const s = loadSpeech({languages: ['es-MX', 'en-US']});
    check('no saved language: the browser decides', s.getLang() === 'es' && s.getLangSource() === 'default');
    check('a browser default is not a saved setting', s.getSavedLang() === null);
}
{
    const s = loadSpeech({languages: ['xx-YY', 'de-AT']});
    check('an unsupported first browser language falls through to the next', s.getLang() === 'de');
}
{
    const s = loadSpeech({languages: ['iw-IL']});
    check('legacy Hebrew code maps to he', s.getLang() === 'he');
    const n = loadSpeech({languages: ['no-NO']});
    check('legacy Norwegian code maps to nb', n.getLang() === 'nb');
}
{
    const s = loadSpeech({languages: ['xx'], moodle: 'pt_br'});
    check('no supported browser language: the Moodle language is the last resort', s.getLang() === 'pt');
}
{
    const s = loadSpeech({languages: ['xx'], moodle: 'xx'});
    check('nothing usable gives null, never a guess', s.getLang() === null);
}
{
    const s = loadSpeech({stored: 'zz', languages: ['it']});
    check('a corrupted saved value is ignored', s.getLang() === 'it' && s.getSavedLang() === null);
}
{
    const s = loadSpeech({});
    const codes = Object.keys(s.SUPPORTED_LANGS);
    check('46 languages', codes.length === 46, String(codes.length));
    check('every language has its own name for the buttons',
        codes.every((c) => typeof s.SUPPORTED_LANGS[c].native === 'string' && s.SUPPORTED_LANGS[c].native.length > 0));
    check('browser list matches the server list',
        ['bg', 'he', 'wo', 'yo', 'om', 'bm'].every((c) => s.SUPPORTED_LANGS[c]));
}

// ---- chat.js: the marker the model sends ------------------------------------------
const chatSrc = read('amd', 'src', 'chat.js');
const constLines = chatSrc.split('\n')
    .filter((l) => /^ {4}const [A-Z0-9_]+ = (\/.*\/[gimsuy]*|-?\d+(\.\d+)?);\s*$/.test(l))
    .join('\n');
const lift = (name) => {
    const start = chatSrc.indexOf('    const ' + name + ' = function(');
    if (start === -1) {
        console.log('  FAIL  could not locate ' + name);
        process.exit(1);
    }
    const rest = chatSrc.slice(start);
    const body = rest.slice(0, rest.indexOf('\n    };\n') + '\n    };'.length);
    // eslint-disable-next-line no-new-func
    return new Function('Speech', constLines + '\n' + body + '\nreturn ' + name + ';');
};
const speech = loadSpeech({});
const parse = lift('parseAssistantDecorators')(speech);
const stripStreaming = lift('stripStreamingDecorators')(speech);

console.log('marker');
{
    const raw = '[SOLA_LANG_SWITCH]es[/SOLA_LANG_SWITCH]\nNoté que escribes en español. ¿Cambio SOLA a español?';
    const got = parse(raw);
    check('the switch code is read', got.langSwitch === 'es', String(got.langSwitch));
    check('the tag is removed and the question kept',
        got.text === 'Noté que escribes en español. ¿Cambio SOLA a español?', JSON.stringify(got.text));
}
{
    const got = parse('[SOLA_LANG_SWITCH]fr\nJ\'ai remarqué que vous écrivez en français.');
    check('a missing closer still reads and removes the tag',
        got.langSwitch === 'fr' && got.text.indexOf('SOLA') === -1, JSON.stringify(got));
}
{
    const got = parse('[SOLA_LANG_SWITCH]xx[/SOLA_LANG_SWITCH]\nHello there.');
    check('an unsupported code asks nothing but still hides the tag',
        got.langSwitch === null && got.text === 'Hello there.', JSON.stringify(got));
}
{
    const got = parse('Normal answer.\n[SOLA_NEXT]a||b[/SOLA_NEXT]');
    check('an ordinary answer asks nothing', got.langSwitch === null && got.suggestions.length === 2);
}
{
    const got = parse('[SOLA_LANG_SWITCH]jav[/SOLA_LANG_SWITCH]\nSugeng enjang.');
    check('an unsupported code is still "asked" so the question gets answered, with no buttons',
        got.langAsked === true && got.langSwitch === null && got.text === 'Sugeng enjang.', JSON.stringify(got));
    const fil = parse('[SOLA_LANG_SWITCH]fil[/SOLA_LANG_SWITCH]\nMagandang araw!');
    check('a three-letter Filipino code maps to tl', fil.langSwitch === 'tl' && fil.text === 'Magandang araw!');
    check('an ordinary answer is not "asked"', parse('Hello.').langAsked === false);
}
console.log('streaming');
{
    const stages = ['[SOLA_LA', '[SOLA_LANG_SWITCH', '[SOLA_LANG_SWITCH]', '[SOLA_LANG_SWITCH]e', '[SOLA_LANG_SWITCH]es',
        '[SOLA_LANG_SWITCH]es[/SOLA_LANG', '[SOLA_LANG_SWITCH]es[/SOLA_LANG_SWITCH]'];
    check('no part of the tag shows while it streams',
        stages.every((t) => stripStreaming(t).indexOf('SOLA') === -1 && stripStreaming(t).indexOf('es') === -1),
        JSON.stringify(stages.map(stripStreaming)));
    const full = stripStreaming('[SOLA_LANG_SWITCH]es[/SOLA_LANG_SWITCH]\nNoté que escribes en español.');
    check('the question after the tag does show', full === 'Noté que escribes en español.', JSON.stringify(full));
}

// ---- the buttons and the setting -----------------------------------------------------
console.log('flow');
check('buttons carry each language\'s own name',
    /UI\.showSuggestions\(\[wrote\.native, saved\.native\]/.test(chatSrc));
check('agreeing saves the language, then asks the question again in it',
    /applyLanguageChoice\(code\);\s*handleSend\(\{text: originalText, lang: code\}\)/.test(chatSrc));
check('declining remembers the refusal and answers the question in the saved language',
    /addLangHold\(code\);\s*handleSend\(\{text: originalText, pinned: true\}\)/.test(chatSrc));
check('the browser language is no longer written into the saved setting',
    !/const detected = Speech\.detectBrowserLang\(\);\s*if \(detected\) \{[\s\S]{0,200}Speech\.setLang\(detected\)/.test(chatSrc));
check('requests say where the language came from', /postData\.langsource = /.test(chatSrc));
check('declined languages are sent', /postData\.langhold = /.test(chatSrc));

check('the lock no longer saves English', !/Speech\.setLang\('en'\)/.test(chatSrc));
check('a question with nothing to click is answered in the saved language',
    /askAgainPinned\(originalText\)/.test(chatSrc) && /resend\.pinned/.test(chatSrc));
check('voice sends the language as pinned', /langsource: Speech\.getLangSource\(\) === 'saved' \? 'pinned' : ''/.test(chatSrc));

// ---- the built bundles ----------------------------------------------------------------
console.log('build');
const chatBuild = read('amd', 'build', 'chat.min.js');
const speechBuild = read('amd', 'build', 'speech.min.js');
check('chat.min.js has the language question', chatBuild.includes('SOLA_LANG_SWITCH') && chatBuild.includes('langsource'),
    'rebuild with terser; Moodle serves amd/build');
check('speech.min.js has the new priority', speechBuild.includes('getSavedLang') && speechBuild.includes('Afaan Oromoo'));

if (failures) {
    console.log('\n' + failures + ' check(s) failed');
    process.exit(1);
}
console.log('\nall language checks passed');
