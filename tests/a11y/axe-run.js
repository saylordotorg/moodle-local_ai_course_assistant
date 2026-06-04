#!/usr/bin/env node
/*
 * SOLA accessibility runner (puppeteer + axe-core).
 *
 * Why this exists instead of plain pa11y-ci:
 * pa11y's action DSL ("set field" / "click element") does not reliably drive
 * Moodle's login form in a local php -S environment, so every page times out
 * waiting for /my/. This runner logs in once with a real puppeteer session,
 * reuses the cookie jar for every page, injects axe-core, and runs the WCAG2AA
 * standard. The pa11y-ci fallback was retired in v5.8.1; this is the only engine
 * and the audited URL list lives inline below.
 *
 * Prereqs (see run.sh): local Moodle at http://localhost:8080, admin/Admin1234!,
 * course id=2, server started with PHP_CLI_SERVER_WORKERS>=4 (puppeteer opens
 * several concurrent connections; a single-worker php -S deadlocks), and
 * admin/cli/upgrade.php run after any version bump (else Moodle 302s every page
 * to the upgrade screen).
 */
'use strict';

const fs = require('fs');
const puppeteer = require('puppeteer');

const BASE = process.env.SOLA_A11Y_BASE || 'http://localhost:8080';
const USER = process.env.SOLA_A11Y_USER || 'admin';
const PASS = process.env.SOLA_A11Y_PASS || 'Admin1234!';
const AXE = require.resolve('axe-core/axe.min.js');
const VIEWPORT = { width: 1280, height: 900 };

// The plugin admin/report pages we audit, relative to the plugin web root.
// Keep this list current as pages are added or removed.
const PAGES = [
  'course_settings.php?courseid=2',
  'sandbox.php?courseid=2',
  'flashcards.php?courseid=2',
  'objectives_admin.php?courseid=2',
  'instructor_dashboard.php?courseid=2',
  'rag_admin.php',
  'analytics.php?courseid=2',
  'starter_settings.php?courseid=2',
  'token_analytics.php?courseid=2',
  'essay_feedback.php?courseid=2',
  'integrity_admin.php',
  'usertesting_admin.php',
  'update_admin.php',
  'vendor_dpa.php',
  'survey_admin.php',
  'rubric_admin.php',
  'admin_user_data.php',
  'privacy.php',
  'settings_user.php',
];
const URLS = PAGES.map((p) => `${BASE}/local/ai_course_assistant/${p}`);
// Colour-contrast (WCAG 1.4.3) is excluded: it depends on the active Moodle
// theme's palette, not on plugin markup, so it is out of scope for this harness.
const DISABLED_RULES = ['color-contrast'];

function pickChrome() {
  const candidates = [
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/Applications/Chromium.app/Contents/MacOS/Chromium',
  ];
  for (const c of candidates) {
    if (fs.existsSync(c)) return c;
  }
  // Fall back to puppeteer's bundled Chromium (x86 under Rosetta on Apple Silicon).
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
  const url = page.url();
  if (/\/login\/index\.php/.test(url)) {
    throw new Error(`Login failed (still on login page: ${url}). Check credentials / pending upgrade.`);
  }
}

async function auditPage(page, url) {
  await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });
  await page.addScriptTag({ path: AXE });
  const result = await page.evaluate(async (disabled) => {
    const rules = {};
    disabled.forEach((id) => { rules[id] = { enabled: false }; });
    // eslint-disable-next-line no-undef
    const r = await axe.run(document, {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] },
      rules,
    });
    return r.violations.map((v) => ({
      id: v.id,
      impact: v.impact,
      help: v.help,
      nodes: v.nodes.map((n) => n.target.join(' ')),
    }));
  }, DISABLED_RULES);
  return result;
}

(async () => {
  const browser = await puppeteer.launch({
    executablePath: pickChrome(),
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });
  let failures = 0;
  let pages = 0;
  try {
    const page = await browser.newPage();
    await page.setViewport(VIEWPORT);
    await login(page);
    console.log(`Logged in as ${USER}. Auditing ${URLS.length} pages (WCAG2AA, axe-core)...\n`);

    for (const url of URLS) {
      pages += 1;
      let violations;
      try {
        violations = await auditPage(page, url);
      } catch (e) {
        failures += 1;
        console.log(`  ERROR  ${url}\n         ${e.message}`);
        continue;
      }
      const short = url.replace(`${BASE}/local/ai_course_assistant/`, '');
      if (violations.length === 0) {
        console.log(`  PASS   ${short}`);
      } else {
        failures += violations.length;
        console.log(`  FAIL   ${short}  (${violations.length} violation(s))`);
        for (const v of violations) {
          console.log(`           [${v.impact}] ${v.id}: ${v.help}`);
          for (const n of v.nodes) console.log(`             -> ${n}`);
        }
      }
    }
  } finally {
    await browser.close();
  }

  console.log(`\n${pages} pages audited, ${failures} issue(s).`);
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
  console.error('Fatal:', e.message);
  process.exit(2);
});
