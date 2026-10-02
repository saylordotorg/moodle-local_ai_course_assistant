'use strict';
/* Functional check for the migrated usability-testing task editor. */
const puppeteer = require('puppeteer');
const fs = require('fs');
const BASE = 'http://localhost:8080';
function pickChrome() {
  const c = ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
             '/Applications/Chromium.app/Contents/MacOS/Chromium'];
  for (const p of c) { if (fs.existsSync(p)) return p; }
  try { return puppeteer.executablePath(); } catch (e) { return undefined; }
}
(async () => {
  const browser = await puppeteer.launch({headless: 'new', executablePath: pickChrome(),
    args: ['--no-sandbox']});
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') { errors.push('console: ' + m.text()); } });
  await page.goto(BASE + '/login/index.php', {waitUntil: 'networkidle2'});
  await page.type('#username', 'admin');
  await page.type('#password', 'Admin1234!');
  await Promise.all([page.waitForNavigation({waitUntil: 'networkidle2'}), page.click('#loginbtn')]);
  await page.goto(BASE + '/local/ai_course_assistant/usertesting_admin.php', {waitUntil: 'networkidle2'});
  await page.waitForSelector('.aica-ut-card', {timeout: 15000});
  const n = await page.$$eval('.aica-ut-card', (e) => e.length);
  console.log('cards rendered:', n);
  const types = await page.$$eval('.aica-ut-type', (e) => e.map((x) => x.textContent.trim()));
  console.log('type badges:', JSON.stringify(types));
  // Add a task.
  await page.click('#aica-add-task-btn');
  await page.waitForFunction((k) => document.querySelectorAll('.aica-ut-card').length === k, {}, n + 1);
  console.log('after add:', await page.$$eval('.aica-ut-card', (e) => e.length));
  // Switch the new card to multiple choice and confirm option rows appear.
  await page.select('.aica-ut-card:last-child .aica-ut-type-select', 'multiple_choice');
  await page.waitForSelector('.aica-ut-card:last-child .aica-ut-opt-input', {timeout: 5000});
  const opts = await page.$$eval('.aica-ut-card:last-child .aica-ut-opt-input', (e) => e.map((x) => x.value));
  console.log('default MC options:', JSON.stringify(opts));
  const arias = await page.$$eval('.aica-ut-card:last-child .aica-ut-opt-input',
    (e) => e.map((x) => x.getAttribute('aria-label')));
  console.log('option aria labels:', JSON.stringify(arias));
  // Move the last card up and confirm the numbering repaints.
  await page.click('.aica-ut-card:last-child .aica-ut-up');
  await page.waitForFunction(() => document.querySelectorAll('.aica-ut-card').length > 0);
  const nums = await page.$$eval('.aica-ut-num', (e) => e.map((x) => x.textContent.trim()));
  console.log('numbers after move up:', JSON.stringify(nums));
  // Preview.
  await page.click('#aica-ut-preview-btn');
  await page.waitForSelector('.sola-ut-preview-overlay', {timeout: 5000});
  const previewTasks = await page.$$eval('.sola-ut-preview-task', (e) => e.length);
  const firstLabel = await page.$eval('.sola-ut-preview-tasknum', (e) => e.textContent.trim());
  const firstRange = await page.$eval('.sola-ut-preview-raterange', (e) => e.textContent.trim());
  console.log('preview tasks:', previewTasks, '| first label:', JSON.stringify(firstLabel),
    '| range:', JSON.stringify(firstRange));
  await page.click('.sola-ut-preview-close');
  await page.waitForFunction(() => !document.querySelector('.sola-ut-preview-overlay'));
  console.log('preview closed: true');
  // Serialise on submit without actually posting.
  const json = await page.evaluate(() => {
    const f = document.getElementById('aica-ut-form');
    f.addEventListener('submit', (e) => e.preventDefault(), {once: true});
    f.dispatchEvent(new Event('submit', {cancelable: true, bubbles: true}));
    return document.getElementById('aica-tasks-json').value;
  });
  const parsed = JSON.parse(json);
  console.log('serialised tasks:', parsed.length, '| last type:', parsed[parsed.length - 1].type,
    '| types:', JSON.stringify(parsed.map((t) => t.type)));
  console.log('js errors:', JSON.stringify(errors));
  await browser.close();
  if (errors.length) { process.exit(1); }
})().catch((e) => { console.error('FAILED', e); process.exit(1); });
