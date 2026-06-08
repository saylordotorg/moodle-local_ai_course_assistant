// Live verification of the first-run consent scroll-to-agree gate (v5.10.1).
// Logs in, opens the SOLA widget on course id=2, and asserts the Accept button
// is disabled until the consent notice is scrolled to the bottom.
//
// Prereqs: local Moodle at http://localhost:8080 (admin/Admin1234!), course
// id=2, and the current user's aica_sola_consent_given preference cleared so
// the banner shows. Run: node tests/a11y/consent-gate-check.js
const fs = require('fs');
const puppeteer = require('puppeteer');

const BASE = process.env.SOLA_A11Y_BASE || 'http://localhost:8080';
const USER = process.env.SOLA_A11Y_USER || 'admin';
const PASS = process.env.SOLA_A11Y_PASS || 'Admin1234!';
const SHOT = '/tmp/sola-consent';

function pickChrome() {
  const c = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
  if (fs.existsSync(c)) return c;
  try { return puppeteer.executablePath(); } catch (e) { return undefined; }
}

async function login(page) {
  await page.goto(`${BASE}/login/index.php`, { waitUntil: 'networkidle2' });
  await page.waitForSelector('#username', { timeout: 15000 });
  await page.type('#username', USER);
  await page.type('#password', PASS);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.click('#loginbtn'),
  ]);
  if (/\/login\/index\.php/.test(page.url())) {
    throw new Error('Login failed; check credentials / pending upgrade.');
  }
}

const acceptState = () => {
  const btn = document.querySelector('.aica-consent-accept');
  const sc = document.querySelector('.aica-consent-scroll');
  const hint = document.querySelector('.aica-consent-scrollhint');
  return {
    found: !!btn,
    disabled: btn ? btn.disabled : null,
    overflows: sc ? (sc.scrollHeight > sc.clientHeight + 4) : null,
    scrollTop: sc ? Math.round(sc.scrollTop) : null,
    clientH: sc ? Math.round(sc.clientHeight) : null,
    scrollH: sc ? Math.round(sc.scrollHeight) : null,
    hintHidden: hint ? (hint.style.display === 'none') : null,
  };
};

(async () => {
  const browser = await puppeteer.launch({
    executablePath: pickChrome(),
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });
  const fail = (m) => { console.log('FAIL: ' + m); process.exitCode = 1; };
  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1100, height: 800 });
    await login(page);

    await page.goto(`${BASE}/course/view.php?id=2`, { waitUntil: 'networkidle2', timeout: 30000 });
    // Force a guaranteed overflow so the scroll gate is actually exercised: cap
    // the notice region well below its content height. Added before the drawer
    // is opened, so the gate's open-time re-check sees the overflow. (At a tall
    // drawer the short notice fits and correctly unlocks; this proves the
    // engaged path where scrolling is required.)
    await page.addStyleTag({ content: '.aica-consent-scroll{max-height:180px !important;}' });
    await page.waitForSelector('#local-ai-course-assistant-toggle', { timeout: 15000 });
    await page.click('#local-ai-course-assistant-toggle');
    await page.waitForSelector('.aica-consent-banner', { visible: true, timeout: 15000 });
    await new Promise((r) => setTimeout(r, 600)); // let ResizeObserver settle.

    const before = await page.evaluate(acceptState);
    await page.screenshot({ path: SHOT + '-1-initial.png' });
    console.log('INITIAL:', JSON.stringify(before));
    if (!before.found) { fail('consent Accept button not found'); throw new Error('stop'); }
    if (before.disabled !== true) fail('Accept button is NOT disabled before scrolling');
    if (before.overflows !== true) fail('notice did not overflow; the scroll gate was not exercised');

    // Scroll the notice region to the bottom.
    await page.$eval('.aica-consent-scroll', (el) => { el.scrollTop = el.scrollHeight; });
    await new Promise((r) => setTimeout(r, 500));

    const after = await page.evaluate(acceptState);
    await page.screenshot({ path: SHOT + '-2-scrolled.png' });
    console.log('AFTER SCROLL:', JSON.stringify(after));
    if (after.disabled !== false) fail('Accept button is STILL disabled after scrolling to the bottom');
    if (after.hintHidden !== true) fail('scroll hint did not hide after reaching the bottom');

    // Confirm clicking dismisses the banner and records consent.
    if (after.disabled === false) {
      await page.click('.aica-consent-accept');
      await new Promise((r) => setTimeout(r, 800));
      const gone = await page.evaluate(() => {
        const b = document.querySelector('.aica-consent-banner');
        return !b || b.style.display === 'none';
      });
      console.log('AFTER ACCEPT, banner hidden:', gone);
      if (!gone) fail('banner did not dismiss after clicking Accept');
    }

    if (!process.exitCode) console.log('PASS: consent gate disabled until scrolled, then enabled and dismissable.');
  } catch (e) {
    if (e.message !== 'stop') { console.log('ERROR: ' + e.message); process.exitCode = 1; }
  } finally {
    await browser.close();
  }
})();
