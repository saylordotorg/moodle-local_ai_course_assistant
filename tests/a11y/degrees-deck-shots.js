// Screenshots for the "Meet SOLA" deck (slides 5, 8, 9, 10).
// Runs against dev.sylr.org and an MBA course, which is real Saylor Degrees
// material, so the study plan and quiz name genuine units rather than fixtures.
// Saves 1..4 to ~/Desktop in the order the deck needs them.
const fs = require('fs');
const os = require('os');
const path = require('path');
const puppeteer = require('puppeteer');

const BASE = process.env.SOLA_BASE || 'https://dev.sylr.org';
const USER = process.env.SOLA_USER;
const PASS = process.env.SOLA_PASS;
const COURSE = parseInt(process.env.SOLA_COURSE || '116', 10);
const OUT = process.env.SOLA_OUT || path.join(os.homedir(), 'Desktop', 'sola-deck-screenshots');
const QUESTION = process.env.SOLA_Q
  || 'What makes a team effective, and how does that differ from a work group?';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function pickChrome() {
  const c = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
  if (fs.existsSync(c)) return c;
  try { return puppeteer.executablePath(); } catch (e) { return undefined; }
}

async function login(page) {
  await page.goto(`${BASE}/login/index.php`, { waitUntil: 'networkidle2' });
  await page.waitForSelector('#username', { timeout: 20000 });
  await page.type('#username', USER);
  await page.type('#password', PASS);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.click('#loginbtn'),
  ]);
  const stillOnLogin = page.url().includes('/login/');
  if (stillOnLogin) { throw new Error('login failed (still on /login/)'); }
}

// The first-run consent gate disables Accept until the notice is scrolled to the
// bottom, so clicking it blind does nothing and every later step then fails on a
// covered drawer.
async function clearConsent(page) {
  const banner = await page.$('.aica-consent-banner');
  if (!banner) { return 'none'; }
  await page.evaluate(() => {
    const s = document.querySelector('.aica-consent-scroll');
    if (s) { s.scrollTop = s.scrollHeight; s.dispatchEvent(new Event('scroll')); }
  });
  await sleep(600);
  const btn = await page.$('.aica-consent-accept:not([disabled])');
  if (!btn) { return 'accept still disabled'; }
  await btn.click();
  await sleep(900);
  return 'accepted';
}

async function openDrawer(page) {
  // The rendered toggle carries the id ...-close-toggle, not ...-toggle; match on
  // the class instead so a future id change does not silently break capture.
  await page.waitForSelector('.local-ai-course-assistant__toggle', { timeout: 25000 });
  const open = await page.$eval('#local-ai-course-assistant-drawer',
    (d) => d.classList.contains('local-ai-course-assistant__drawer--open')).catch(() => false);
  if (!open) { await page.click('.local-ai-course-assistant__toggle'); }
  await sleep(1800);
}

// An answer is finished when the message count has stopped growing and the
// streaming cursor is gone. Waiting a fixed time instead produces half-rendered
// answers in the screenshot.
async function waitForAnswer(page, timeoutms = 180000) {
  const start = Date.now();
  // Wait for streaming to actually START, or a fast error is mistaken for a
  // finished answer.
  await sleep(2500);
  while (Date.now() - start < timeoutms) {
    const state = await page.evaluate(() => {
      const stop = [...document.querySelectorAll('button')]
        .find((b) => /^\s*(Stop|Cancel)\s*$/i.test(b.textContent));
      const m = document.querySelector('.local-ai-course-assistant__messages');
      return { streaming: !!stop, text: m ? m.textContent : '' };
    });
    if (!state.streaming) {
      if (/something went wrong|Please try again/i.test(state.text)) { return 'ERROR IN REPLY'; }
      return true;
    }
    await sleep(1200);
  }
  return 'TIMEOUT';
}

async function hideAdminChrome(page) {
  await page.evaluate(() => {
    document.querySelectorAll('.local-ai-course-assistant__composer-select')
      .forEach((el) => { el.style.display = 'none'; });
  });
  await sleep(250);
}

async function shot(page, n, name) {
  await hideAdminChrome(page);
  const file = path.join(OUT, `${n}-${name}.png`);
  const drawer = await page.$('#local-ai-course-assistant-drawer');
  if (drawer) { await drawer.screenshot({ path: file }); } else { await page.screenshot({ path: file }); }
  console.log(`  saved ${path.basename(file)}`);
}

(async () => {
  if (!USER || !PASS) { throw new Error('set SOLA_USER and SOLA_PASS'); }
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await puppeteer.launch({
    executablePath: pickChrome(),
    headless: true,
    args: ['--no-sandbox', '--window-size=1500,1000'],
    defaultViewport: { width: 1500, height: 1000 },
  });
  const page = await browser.newPage();

  await login(page);
  console.log('logged in as', USER);

  await page.goto(`${BASE}/course/view.php?id=${COURSE}`, { waitUntil: 'networkidle2' });
  const coursename = await page.evaluate(() => {
    const h = document.querySelector('h1');
    return h ? h.textContent.trim() : '(unknown)';
  });
  console.log('course:', coursename);

  await openDrawer(page);
  console.log('consent:', await clearConsent(page));
  await openDrawer(page);

  // ── 1 (slide 5): the drawer open, starters visible ────────────────────
  await sleep(1000);
  await shot(page, 1, 'drawer-open');

  // ── 2 (slide 8): a real question, answered, with its source link ──────
  await page.waitForSelector('.local-ai-course-assistant__input', { timeout: 15000 });
  await page.click('.local-ai-course-assistant__input');
  await page.type('.local-ai-course-assistant__input', QUESTION, { delay: 12 });
  await page.click('.local-ai-course-assistant__btn-send');
  console.log('  asked:', QUESTION);
  console.log('  answered:', await waitForAnswer(page));
  await page.evaluate(() => {
    const m = document.querySelector('.local-ai-course-assistant__messages');
    if (m) { m.scrollTop = m.scrollHeight; }
  });
  await sleep(900);
  await shot(page, 2, 'question-and-answer');

  // ── 3 (slide 9): Quiz Me, one question answered, explanation + score ──
  await page.evaluate(() => {
    const b = document.querySelector('[data-starter="quiz"]');
    if (b) { b.click(); } else {
      const r = document.querySelector('.local-ai-course-assistant__btn-reset');
      if (r) { r.click(); }
    }
  });
  await sleep(1500);
  const startBtn = await page.$('.aica-quiz-setup__start');
  if (startBtn) { await startBtn.click(); console.log('  quiz: setup started'); }
  await page.waitForSelector('.aica-quiz__choice', { timeout: 120000 }).catch(() => null);
  await sleep(1200);
  const choices = await page.$$('.aica-quiz__choice');
  if (choices.length) {
    await choices[0].click();
    await sleep(2200);
    console.log(`  quiz: answered (${choices.length} choices)`);
  } else { console.log('  quiz: NO CHOICES FOUND'); }
  await page.evaluate(() => {
    const card = document.querySelector('.aica-quiz');
    if (card) { card.scrollIntoView({ block: 'start' }); }
  });
  await sleep(700);
  await shot(page, 3, 'quiz-with-explanation');

  // ── 4 (slide 10): a study plan naming real units ──────────────────────
  const exit = await page.$('.aica-quiz__exit');
  if (exit) { await exit.click(); await sleep(900); }
  const clear = await page.$('.local-ai-course-assistant__btn-clear');
  if (clear) { await clear.click(); await sleep(1200); }
  const reset = await page.$('.local-ai-course-assistant__btn-reset');
  if (reset) { await reset.click(); await sleep(1400); }
  await page.evaluate(() => {
    const b = document.querySelector('[data-starter="study-plan"]');
    if (b) { b.click(); }
  });
  await sleep(1800);
  const planStart = await page.$('.aica-quiz-setup__start');
  if (planStart) { await planStart.click(); console.log('  study plan: panel confirmed'); }
  console.log('  study plan answered:', await waitForAnswer(page));
  // Frame the PLAN, not whatever happens to be at the bottom of the thread.
  await page.evaluate(() => {
    const msgs = [...document.querySelectorAll('.local-ai-course-assistant__messages > *')];
    const last = msgs.reverse().find((el) => el.textContent.trim().length > 200);
    if (last) { last.scrollIntoView({ block: 'start' }); }
  });
  await sleep(900);
  await shot(page, 4, 'study-plan');

  await browser.close();
  console.log('DONE ->', OUT);
})().catch((e) => { console.error('ERROR:', e.message); process.exit(1); });
