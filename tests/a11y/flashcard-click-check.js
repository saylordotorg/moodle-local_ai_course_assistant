// Live regression check for the flashcard review page (v6.2.x).
//
// Guards the fix where security::send_security_headers() applied a strict CSP
// (no 'unsafe-eval') to flashcards.php — a full Moodle page — which broke
// Moodle's own YUI/requireJS and blocked the MathJax CDN, leaving the page
// JS-broken. This harness logs in, opens the flashcard page, and asserts:
//   1. the page loads with NO uncaught JS / CSP errors,
//   2. clicking "Reveal" shows the answer,
//   3. clicking a grade button advances to the next card.
//
// Prereq: local Moodle at http://localhost:8080 (admin/Admin1234!), course
// id=2 with flashcards enabled and at least 2 cards due for the admin user.
// Seed with a CLI that calls flashcard_manager::save_batch() and
// set_config('flashcards_enabled_course_2', 1, 'local_ai_course_assistant').
// Run: node tests/a11y/flashcard-click-check.js
const fs = require('fs');
const puppeteer = require('puppeteer');

const BASE = process.env.SOLA_A11Y_BASE || 'http://localhost:8080';
const USER = process.env.SOLA_A11Y_USER || 'admin';
const PASS = process.env.SOLA_A11Y_PASS || 'Admin1234!';
const COURSEID = process.env.SOLA_A11Y_COURSEID || '2';

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

(async () => {
  const browser = await puppeteer.launch({
    executablePath: pickChrome(),
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });
  const fail = (m) => { console.log('FAIL: ' + m); process.exitCode = 1; };
  const jsErrors = [];
  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1100, height: 900 });
    page.on('pageerror', (e) => jsErrors.push('PAGEERROR: ' + e.message));
    page.on('console', (m) => { if (m.type() === 'error') jsErrors.push('CONSOLE.ERROR: ' + m.text()); });

    await login(page);
    await page.goto(`${BASE}/local/ai_course_assistant/flashcards.php?courseid=${COURSEID}`,
      { waitUntil: 'networkidle2', timeout: 30000 });

    const init = await page.evaluate(() => {
      const cards = Array.from(document.querySelectorAll('.aica-fc-card'));
      const vis = cards.filter((c) => getComputedStyle(c).display !== 'none');
      return { numCards: cards.length, visibleCards: vis.length };
    });
    console.log('INITIAL:', JSON.stringify(init));
    if (init.numCards < 2) { fail('need >= 2 due cards seeded for course ' + COURSEID); throw new Error('stop'); }
    if (init.visibleCards !== 1) fail('exactly one card should be visible at a time');

    // The fix: no CSP-induced JS errors on this full Moodle page.
    if (jsErrors.length) fail('page had JS/CSP errors on load: ' + JSON.stringify(jsErrors));

    // Reveal the answer.
    const revealed = await page.evaluate(() => {
      const card = Array.from(document.querySelectorAll('.aica-fc-card')).find((c) => getComputedStyle(c).display !== 'none');
      const btn = card && card.querySelector('.aica-fc-reveal');
      if (!btn) return false;
      btn.click();
      const back = card.querySelector('.aica-fc-back');
      return back ? getComputedStyle(back).display !== 'none' : false;
    });
    if (!revealed) fail('clicking Reveal did not show the answer');

    // Grade "Easy" and confirm advance to the next card.
    const firstQ = await page.evaluate(() => {
      const card = Array.from(document.querySelectorAll('.aica-fc-card')).find((c) => getComputedStyle(c).display !== 'none');
      return card.querySelector('.aica-fc-q').textContent.trim();
    });
    await page.evaluate(() => {
      const card = Array.from(document.querySelectorAll('.aica-fc-card')).find((c) => getComputedStyle(c).display !== 'none');
      card.querySelector('.aica-fc-grade[data-q="5"]').click();
    });
    await new Promise((r) => setTimeout(r, 900));
    const afterGrade = await page.evaluate(() => {
      const card = Array.from(document.querySelectorAll('.aica-fc-card')).find((c) => getComputedStyle(c).display !== 'none');
      return card ? card.querySelector('.aica-fc-q').textContent.trim() : null;
    });
    if (afterGrade === firstQ) fail('grading did not advance to the next card');

    if (jsErrors.length) fail('JS/CSP errors after interaction: ' + JSON.stringify(jsErrors));
    if (!process.exitCode) console.log('PASS: flashcard page loads clean, reveal + grade work.');
  } catch (e) {
    if (e.message !== 'stop') { console.log('ERROR: ' + e.message); process.exitCode = 1; }
  } finally {
    await browser.close();
  }
})();
