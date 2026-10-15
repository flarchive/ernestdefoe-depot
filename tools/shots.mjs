/**
 * Screenshots for the README and the store listing.
 *
 *   node tools/shots.mjs <tokens.json>
 *
 * Signed in with remember tokens minted on the server (tokens.json maps a
 * username to a token, as tools/seed-demo.mjs uses): a member for the forum
 * pages, an admin for the admin page. No password is typed or stored.
 * Reduced motion is emulated so the spotlight holds still.
 *
 * Take them on Flarum's default theme: switch any theme extension off first.
 */
import puppeteer from '../../convoro2/node_modules/puppeteer-core/lib/puppeteer/puppeteer-core.js';
import { mkdir } from 'node:fs/promises';
import { readFileSync } from 'node:fs';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = process.env.DEPOT_BASE || 'https://flarum.test';
const OUT = `${dirname(fileURLToPath(import.meta.url))}/../screenshots`;
const FILE = '/downloads/f/aurethil-resource-pack';
const TOKENS = JSON.parse(readFileSync(process.argv[2], 'utf8'));

// Floating chrome that belongs to the theme, not to Depot.
const HIDE = `
  .Bespoke-scrollTop, .ScrollToTop, [class*="scrollTop"], [class*="BackToTop"], .CookieConsent, #cookie-consent, [class*="cookie"] { display: none !important; }
`;

const DESKTOP = { width: 1440, height: 1000, deviceScaleFactor: 2 };
const PHONE = { width: 390, height: 844, deviceScaleFactor: 3, isMobile: true, hasTouch: true };

const SHOTS = [
  { name: 'browse', path: '/downloads', max: 1750 },
  { name: 'category-list', path: '/downloads/c/minecraft', max: 1250, view: 'list' },
  { name: 'file', path: FILE, max: 1700 },
  { name: 'versions', path: FILE, max: 1250, tab: 'Versions' },
  { name: 'reviews', path: FILE, max: 1250, tab: 'Reviews' },
  { name: 'discussion', path: FILE, max: 1150, tab: 'Discussion' },
  { name: 'download', path: FILE, modal: 'download', max: 1000 },
  { name: 'upload-file', path: '/downloads', modal: 'upload', step: 0, max: 1000 },
  { name: 'upload-support', path: '/downloads', modal: 'upload', step: 3, max: 1000 },
  { name: 'admin', path: '/admin#/extension/ernestdefoe-depot', admin: true, max: 1800 },
  { name: 'browse-dark', path: '/downloads', max: 1750, dark: true },
  { name: 'file-dark', path: FILE, max: 1500, dark: true },
  { name: 'phone-browse', path: '/downloads', viewport: PHONE, max: 2200 },
  { name: 'phone-file', path: FILE, viewport: PHONE, max: 2200 },
];

await mkdir(OUT, { recursive: true });

const browser = await puppeteer.launch({
  executablePath: CHROME,
  headless: 'new',
  acceptInsecureCerts: true,
  args: ['--hide-scrollbars', '--force-color-profile=srgb', '--ignore-certificate-errors'],
});

async function signedIn(username) {
  const context = await browser.createBrowserContext();
  const p = await context.newPage();
  await p.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
  await p.setCookie({ name: 'flarum_remember', value: TOKENS[username], domain: new URL(BASE).hostname, path: '/', httpOnly: true, secure: true });
  return p;
}
const member = await signedIn('kestrel_mc');
const adminPage = await signedIn('depot_temp_admin');

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

for (const shot of SHOTS) {
  const page = shot.admin ? adminPage : member;
  await page.setViewport(shot.viewport || DESKTOP);
  await page.goto(BASE + shot.path, { waitUntil: 'networkidle0' });
  await page.evaluate((view) => {
    try {
      localStorage.setItem('depot.view', view);
    } catch (e) {}
  }, shot.view || 'grid');
  await page.goto(BASE + shot.path, { waitUntil: 'networkidle0' });
  await page.waitForSelector(shot.admin ? '.DepotCategories-row' : '.DepotCard, .DepotRow, .DepotFile');
  await page.evaluate(() => Promise.all([...document.images].filter((i) => !i.complete).map((i) => new Promise((r) => (i.onload = i.onerror = r)))));
  await page.addStyleTag({ content: HIDE });
  await page.evaluate((dark) => document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light'), !!shot.dark);

  if (shot.tab) {
    await page.evaluate((label) => [...document.querySelectorAll('.DepotTabs button')].find((b) => b.textContent.trim().startsWith(label)).click(), shot.tab);
  }

  if (shot.modal === 'download') {
    await page.evaluate(() => document.querySelector('.DepotDownloadSplit .Button--primary').click());
    await page.waitForSelector('.DepotDownloadModal');
  }

  if (shot.modal === 'upload') {
    await page.evaluate(() => document.querySelector('.IndexPage-newDiscussion').click());
    await page.waitForSelector('.DepotDrop');
    // A real file, hashed by the page itself — the checksum in the shot is genuine.
    await page.evaluate(() => {
      const f = new File([new TextEncoder().encode('Crystal Hollow 1.0 — sample payload for the screenshot')], 'crystal-hollow-1.0.zip');
      const dt = new DataTransfer();
      dt.items.add(f);
      document.querySelector('.DepotDrop').dispatchEvent(new DragEvent('drop', { dataTransfer: dt, bubbles: true, cancelable: true }));
    });
    await page.waitForSelector('.DepotDrop-hash:not(.is-working)');
    for (let i = 0; i < shot.step; i++) {
      await page.evaluate(() => [...document.querySelectorAll('.DepotUpload-foot .Button--primary')].pop().click());
      await sleep(150);
      if (i === 0) {
        await page.evaluate(() => {
          const set = (sel, v) => {
            const el = document.querySelector(sel);
            el.value = v;
            el.dispatchEvent(new Event('input', { bubbles: true }));
          };
          set('#depot-tagline', 'An amethyst geode turned into a survival base, with a working minecart loop.');
        });
      }
    }
  }

  await page.evaluate(() => document.fonts.ready);
  await sleep(400);

  const height = await page.evaluate(() => {
    const modal = document.querySelector('.Modal');
    if (modal) return Math.ceil(modal.getBoundingClientRect().bottom + 40);
    return document.documentElement.scrollHeight;
  });

  await page.screenshot({
    path: `${OUT}/${shot.name}.png`,
    clip: { x: 0, y: 0, width: (shot.viewport || DESKTOP).width, height: Math.min(height, shot.max) },
    captureBeyondViewport: true,
  });
  console.log(`✓ ${shot.name}`);
}

await browser.close();
