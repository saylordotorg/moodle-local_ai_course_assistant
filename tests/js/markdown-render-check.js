/**
 * Markdown rendering check: tables, block quotes, rules, strikethrough, h4-h6.
 *
 * Models answer comparison questions with GitHub-style pipe tables, and the
 * renderer used to have no table support, so learners saw rows of raw pipes
 * and dashes. This check loads the real module and asserts:
 *   1. tables render, with alignment, ragged rows, escaped pipes and pipes
 *      inside inline code handled;
 *   2. nothing that isn't a table is mistaken for one;
 *   3. every cell is escaped and link-checked exactly like a paragraph, so the
 *      new syntax adds no way for model output to inject markup;
 *   4. the other block syntax models emit (quotes, rules, h4-h6, ~~strike~~);
 *   5. amd/build/markdown.min.js carries the change, because Moodle serves the
 *      built bundle and a source-only fix changes nothing.
 *
 * Run: node tests/js/markdown-render-check.js
 * Exits non-zero on failure so it can gate a release.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const srcPath = path.join(root, 'amd', 'src', 'markdown.js');
const buildPath = path.join(root, 'amd', 'build', 'markdown.min.js');

let failures = 0;
const check = (name, ok, detail) => {
    if (ok) {
        console.log('  ok    ' + name);
    } else {
        failures += 1;
        console.log('  FAIL  ' + name + (detail ? '  -> ' + detail : ''));
    }
};

// Load the AMD module with a stand-in define().
let Markdown = null;
const define = (deps, factory) => {
    Markdown = factory();
};
// eslint-disable-next-line no-new-func
new Function('define', fs.readFileSync(srcPath, 'utf8'))(define);
const render = (md) => Markdown.sanitize(Markdown.render(md));
const count = (html, needle) => html.split(needle).length - 1;

// ---- 1. tables ---------------------------------------------------------------
console.log('tables');
const basic = render([
    'Here is a comparison:',
    '',
    '| Term | Meaning |',
    '| --- | --- |',
    '| **Asset** | Something owned |',
    '| Liability | Something owed |',
    '',
    'That is all.',
].join('\n'));
check('renders a table', basic.includes('<table>') && basic.includes('</table>'), basic);
check('two header cells', count(basic, '<th>') === 2, basic);
check('four body cells', count(basic, '<td') === 4, basic);
check('inline markdown inside a cell', basic.includes('<td><strong>Asset</strong></td>'), basic);
check('no raw pipes left', !basic.includes('|'), basic);
check('paragraphs around the table survive',
    basic.includes('<p>Here is a comparison:</p>') && basic.includes('<p>That is all.</p>'), basic);
check('wrapped for horizontal scroll', basic.includes('<div class="aica-md-table"><table>'), basic);

const aligned = render('| L | C | R | N |\n|:---|:---:|---:|---|\n| a | b | c | d |');
check('left alignment', aligned.includes('<td class="aica-md-align-left">a</td>'), aligned);
check('center alignment', aligned.includes('<td class="aica-md-align-center">b</td>'), aligned);
check('right alignment', aligned.includes('<td class="aica-md-align-right">c</td>'), aligned);
check('no class when unaligned', aligned.includes('<td>d</td>'), aligned);

const noOuter = render('A | B\n--- | ---\n1 | 2');
check('outer pipes are optional', count(noOuter, '<td') === 2 && noOuter.includes('<th>A</th>'), noOuter);

const ragged = render('| a | b | c |\n|---|---|---|\n| 1 |\n| 1 | 2 | 3 | 4 |');
check('short row padded, long row cut',
    count(ragged, '<td') === 6 && !ragged.includes('>4<'), ragged);

const piped = render('| Code | Note |\n|---|---|\n| `a | b` | x \\| y |');
check('pipe inside inline code stays in its cell', piped.includes('<td><code>a | b</code></td>'), piped);
check('escaped pipe stays in its cell', piped.includes('<td>x | y</td>'), piped);

const headerOnly = render('| a | b |\n|---|---|');
check('header-only table has no tbody', headerOnly.includes('<table>') && !headerOnly.includes('<tbody>'), headerOnly);

const afterList = render('- one\n- two\n| a | b |\n|---|---|\n| 1 | 2 |');
check('a list closes before a table', afterList.indexOf('</ul>') < afterList.indexOf('<div class="aica-md-table">'),
    afterList);

// ---- 2. things that are not tables ---------------------------------------------
console.log('not tables');
const loosePipe = render('Use a | b to pipe output.');
check('a lone pipe in prose stays prose', loosePipe === '<p>Use a | b to pipe output.</p>', loosePipe);
const mismatch = render('| a | b |\n|---|---|---|\n| 1 | 2 |');
check('divider with the wrong cell count is not a table', !mismatch.includes('<table>'), mismatch);
const ruleAfterText = render('Some text\n---\nMore text');
check('--- after prose is a rule, not a table', ruleAfterText.includes('<hr>') && !ruleAfterText.includes('<table>'),
    ruleAfterText);
const fenced = render('```\n| a | b |\n|---|---|\n| 1 | 2 |\n```');
check('a table inside a code block stays code', fenced.startsWith('<pre><code>') && !fenced.includes('<table>'), fenced);

// ---- 3. escaping ----------------------------------------------------------------
console.log('escaping');
const hostile = render([
    '| Name | Link |',
    '|---|---|',
    '| <img src=x onerror=alert(1)> | [click](javascript:alert(1)) |',
    '| <script>alert(2)</script> | [ok](https://example.com/a) |',
].join('\n'));
check('HTML in a cell is escaped', !hostile.includes('<img') && hostile.includes('&lt;img'), hostile);
check('script in a cell is escaped', !hostile.includes('<script') && hostile.includes('&lt;script&gt;'), hostile);
check('javascript: link in a cell is neutralised', !/href="javascript/i.test(hostile) && hostile.includes('about:blank'),
    hostile);
check('https link in a cell survives', hostile.includes('href="https://example.com/a"'), hostile);
const hostileHeader = render('| <b onmouseover=x>h</b> | b |\n|---|---|\n| 1 | 2 |');
check('HTML in a header cell is escaped', !hostileHeader.includes('<b ') && hostileHeader.includes('&lt;b'),
    hostileHeader);
const hostileQuote = render('> <img src=x onerror=alert(1)>');
check('HTML in a block quote is escaped', !hostileQuote.includes('<img') && hostileQuote.includes('&lt;img'),
    hostileQuote);

// ---- 4. other block syntax -----------------------------------------------------
console.log('other syntax');
const quote = render('> First line\n> second line\n>\n> New paragraph\n\nAfter.');
check('block quote', quote.includes('<blockquote><p>First line<br>second line</p><p>New paragraph</p></blockquote>'),
    quote);
check('text after a quote is a paragraph', quote.includes('<p>After.</p>'), quote);
check('*** is a rule', render('***') === '<hr>', render('***'));
check('* * * is a rule, not a list', render('* * *') === '<hr>', render('* * *'));
check('h4 renders', render('#### Fourth') === '<h4>Fourth</h4>', render('#### Fourth'));
check('h6 renders', render('###### Sixth') === '<h6>Sixth</h6>', render('###### Sixth'));
check('seven hashes is not a heading', !render('####### x').startsWith('<h'), render('####### x'));
check('strikethrough', render('~~old~~ new') === '<p><del>old</del> new</p>', render('~~old~~ new'));
check('existing lists unchanged', render('1. a\n\n2. b') === '<ol>\n<li>a</li>\n<li>b</li>\n</ol>', render('1. a\n\n2. b'));

// ---- 5. the built bundle --------------------------------------------------------
console.log('build');
const built = fs.readFileSync(buildPath, 'utf8');
check('amd/build/markdown.min.js has table support', built.includes('aica-md-table'),
    'rebuild with terser; Moodle serves amd/build, not amd/src');
check('built bundle has no lookbehind (older Safari cannot parse it)', !built.includes('(?<'),
    'lookbehind found in markdown.min.js');

if (failures) {
    console.log('\n' + failures + ' check(s) failed');
    process.exit(1);
}
console.log('\nall markdown checks passed');
