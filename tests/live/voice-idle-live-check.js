/**
 * Live check of the 8.3.1 voice silence limit, in a real browser against a real site (dev.sylr.org).
 *
 * Complements tests/js/voice-idle-check.js (fake clock, no browser). This one runs the shipped bundle in Chrome with a
 * silent fake microphone and checks what a learner sees: the voice overlay opens, nobody speaks, the
 * "Stopped listening to save credits" message and a Resume chip appear within the limit, Resume reopens listening,
 * the silence limit trips a second time, End conversation closes the overlay, and no raw [[c:N]] / [SOURCE:...] marker
 * is on screen.
 *
 * It needs the demo-studio driver, signed in to the site (a person signs in once), started with a silent microphone:
 *   ffmpeg -f lavfi -i anullsrc=r=48000:cl=mono -t 120 /tmp/silence.wav
 *   node studio/drive.js projects/<name> --port 7331 --url https://dev.sylr.org/ --fake-audio /tmp/silence.wav
 *
 * Run: node tests/live/voice-idle-live-check.js [--port 7331] [--course 11] [--site https://dev.sylr.org] [--max 30]
 * --max is the most seconds the stop may take (connect time plus the silence limit; Live 5 s, other modes 10 s).
 * Not part of CI: it needs a signed-in browser and a live site.
 */
'use strict';

const arg = (k, d) => {
    const i = process.argv.indexOf(k);
    return i >= 0 ? process.argv[i + 1] : d;
};
const port = arg('--port', '7331');
const site = arg('--site', 'https://dev.sylr.org');
const course = arg('--course', '11');
const maxSeconds = Number(arg('--max', '30'));

let failures = 0;
const check = (name, ok, detail) => {
    if (!ok) {
        failures += 1;
    }
    console.log((ok ? '  ok    ' : '  FAIL  ') + name + (!ok && detail ? '  -> ' + detail : ''));
};

const run = async(js) => {
    // Use the browser's own open tab (or open one when the driver's first tab was closed), so the signed-in session is kept.
    const body0 = "const pg = page.context().pages().find((x) => !x.isClosed()) || await page.context().newPage();\n" +
        js.replace(/\bpage\./g, 'pg.');
    const res = await fetch('http://127.0.0.1:' + port + '/run', {method: 'POST', body: JSON.stringify({js: body0})});
    const body = await res.json();
    if (!body.ok) {
        throw new Error(body.error || 'driver error');
    }
    return body.out;
};

// Seconds until the stop message shows, or -1 when it never does within the budget.
const waitForStop = (budget) => run(`
  const t0 = Date.now();
  while (Date.now() - t0 < ${budget * 1000}) {
    const txt = await page.evaluate(() => document.body.innerText);
    if (/Stopped listening to save credits/.test(txt)) { return (Date.now() - t0) / 1000; }
    await page.waitForTimeout(250);
  }
  return -1;`);

const resumeChip = `page.locator('.aica-suggestion', {hasText: /^Resume$/})`;

(async() => {
    console.log('Voice silence limit, live on ' + site + ' (course ' + course + ')');
    await run(`await page.goto('${site}/course/view.php?id=${course}'); await page.waitForLoadState('load'); return 1;`);
    check('signed in (not on the login page)', !(await run('return page.url()')).includes('/login/'));

    await run(`await page.locator('.local-ai-course-assistant__toggle').first().click();
               await page.locator('[data-mode="voice"]').first().click();
               await page.locator('.aica-voice-panel__start').first().click(); return 1;`);
    const first = await waitForStop(maxSeconds);
    check('first stop: message appears within ' + maxSeconds + ' s (took ' + first + ' s)', first >= 0);
    check('first stop: Resume chip is shown', (await run(`return await ${resumeChip}.count()`)) === 1);
    check('first stop: End conversation chip is shown',
        (await run(`return await page.locator('.aica-suggestion', {hasText: /End conversation/}).count()`)) === 1);

    await run(`await ${resumeChip}.click(); return 1;`);
    await run('await page.waitForTimeout(800); return 1;');
    check('Resume: chip is gone while listening again',
        (await run(`return await ${resumeChip}.count()`)) === 0);

    const second = await waitForStop(maxSeconds);
    check('second stop: silence limit trips again (took ' + second + ' s)', second >= 0);
    check('second stop: Resume chip is shown again', (await run(`return await ${resumeChip}.count()`)) === 1);

    const markers = await run(`const t = await page.evaluate(() => document.body.innerText);
                               return /\\[\\[c:\\d+\\]\\]|\\[SOURCE:/.test(t);`);
    check('no raw [[c:N]] or [SOURCE:...] marker on screen', markers === false);

    await run(`await page.locator('.aica-suggestion', {hasText: /End conversation/}).click(); return 1;`);
    await run('await page.waitForTimeout(800); return 1;');
    check('End conversation: no Resume chip left',
        (await run(`return await ${resumeChip}.count()`)) === 0);

    console.log(failures ? failures + ' check(s) failed' : 'all checks passed');
    process.exit(failures ? 1 : 0);
})().catch((e) => {
    console.log('  FAIL  ' + e.message);
    process.exit(1);
});
