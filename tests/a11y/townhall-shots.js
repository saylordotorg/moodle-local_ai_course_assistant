// One-off screenshot harness for the 2026-06-10 faculty town hall deck.
// Reuses the axe-run.js login pattern. Saves PNGs to /tmp/sola-townhall/.
// Run: node tests/a11y/townhall-shots.js   (local Moodle on :8080, course id=5)
const fs = require('fs');
const puppeteer = require('puppeteer');

const BASE = process.env.SOLA_A11Y_BASE || 'http://localhost:8080';
const USER = process.env.SOLA_A11Y_USER || 'admin';
const PASS = process.env.SOLA_A11Y_PASS || 'Admin1234!';
const COURSE = 5;
const OUT = '/tmp/sola-townhall';

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
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await puppeteer.launch({
    executablePath: pickChrome(),
    headless: true,
    args: ['--no-sandbox', '--window-size=1440,900'],
    defaultViewport: { width: 1440, height: 900 },
  });
  const page = await browser.newPage();
  await login(page);
  console.log('logged in');

  // ── 1. Course page with the SOLA side tab (drawer closed) ──────────────
  await page.goto(`${BASE}/course/view.php?id=${COURSE}`, { waitUntil: 'networkidle2' });
  await page.waitForSelector('.local-ai-course-assistant__toggle', { timeout: 15000 });
  await sleep(800);
  await page.screenshot({ path: `${OUT}/01-course-page.png` });
  console.log('01-course-page');

  // ── 2. Drawer open with conversation starters ───────────────────────────
  await page.click('.local-ai-course-assistant__toggle');
  await sleep(1500);
  // Hide voice-flavoured surfaces for the deck: prod messaging treats voice
  // as future direction only. Cosmetic, screenshot-session only.
  await page.evaluate(() => {
    document.querySelectorAll('.local-ai-course-assistant__starter').forEach((el) => {
      if (/Conversation Practice|Pronunciation/i.test(el.textContent)) {
        el.style.display = 'none';
      }
    });
    document.querySelectorAll('[class*="btn-mic"], [class*="mic-btn"], [aria-label*="oice"], [title*="peak"]').forEach((el) => {
      el.style.display = 'none';
    });
  });
  await sleep(400);
  await page.screenshot({ path: `${OUT}/02-starters.png` });
  console.log('02-starters');

  // ── 3. Chat history (hide starters overlay; seeded Socratic exchange) ──
  await page.evaluate(() => {
    const s = document.querySelector('.local-ai-course-assistant__starters');
    if (s) s.style.display = 'none';
    const w = document.querySelector('.local-ai-course-assistant__welcome');
    if (w) w.style.display = 'none';
    // Scroll messages to bottom so the full exchange is in frame.
    const m = document.querySelector('.local-ai-course-assistant__messages');
    if (m) m.scrollTop = m.scrollHeight;
  });
  await sleep(800);
  await page.screenshot({ path: `${OUT}/03-chat.png` });
  console.log('03-chat');

  // ── 4. In-drawer settings panel (language selector = 46-language story) ─
  const settingsBtn = await page.$('.local-ai-course-assistant__btn-settings-panel');
  if (settingsBtn) {
    await settingsBtn.click();
    await sleep(1200);
    await page.screenshot({ path: `${OUT}/04-settings-panel.png` });
    console.log('04-settings-panel');
  } else {
    console.log('04-settings-panel SKIPPED (button not found)');
  }

  // ── 5/6. Analytics dashboard, course view — clipped regions ────────────
  await page.goto(`${BASE}/local/ai_course_assistant/analytics.php?courseid=${COURSE}`,
    { waitUntil: 'networkidle2' });
  await sleep(1200);
  // Clip from the per-course Overview heading: cards + usage trend.
  const clipAt = async (headingtext, file, height, x = 0, width = 1440) => {
    const y = await page.evaluate((txt) => {
      const h = [...document.querySelectorAll('h2,h3')]
        .find((el) => el.textContent.trim().startsWith(txt));
      return h ? Math.max(0, h.getBoundingClientRect().top + window.scrollY - 16) : null;
    }, headingtext);
    if (y === null) { console.log(file + ' SKIPPED (heading not found)'); return; }
    await page.screenshot({ path: `${OUT}/${file}`, clip: { x, y, width, height } });
    console.log(file.replace('.png', ''));
  };
  await clipAt('Overview', '05-analytics.png', 560);
  await clipAt('Common Prompts', '06-analytics-hotspots.png', 640, 720, 720);

  // ── 7. Instructor dashboard (course-developer view) ────────────────────
  await page.goto(`${BASE}/local/ai_course_assistant/instructor_dashboard.php?courseid=${COURSE}`,
    { waitUntil: 'networkidle2' });
  await sleep(1200);
  await page.screenshot({ path: `${OUT}/07-instructor.png` });
  console.log('07-instructor');

  // ── 8. Per-course settings page (what course developers configure) ─────
  await page.goto(`${BASE}/local/ai_course_assistant/course_settings.php?courseid=${COURSE}`,
    { waitUntil: 'networkidle2' });
  await sleep(1000);
  await page.screenshot({ path: `${OUT}/08-course-settings.png` });
  console.log('08-course-settings');

  await browser.close();
  console.log('DONE -> ' + OUT);
})().catch((e) => { console.error('ERROR:', e.message); process.exit(1); });
