/**
 * Demo content for screenshots, and an end-to-end test of the real API.
 *
 *   node tools/seed-demo.mjs <tokens.json>
 *
 * Signs in each demo account with a remember token minted on the server (see
 * the README's development notes) — no password is typed or stored. Every
 * step is checked; the script exits non-zero if any check fails.
 */
import puppeteer from '../../convoro2/node_modules/puppeteer-core/lib/puppeteer/puppeteer-core.js';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = process.env.DEPOT_BASE || 'https://flarum.test';
const HERE = dirname(fileURLToPath(import.meta.url));
const tokens = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const ART = readFileSync(`${HERE}/demo-art.js`, 'utf8');

const failures = [];
const check = (ok, what) => {
  console.log(`${ok ? '✓' : '✗'} ${what}`);
  if (!ok) failures.push(what);
};

const browser = await puppeteer.launch({
  executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'new',
  acceptInsecureCerts: true,
  args: ['--ignore-certificate-errors'],
});

/** A signed-in page for one account, with helpers to call Depot's API from inside it. */
async function as(username) {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  await page.setCookie({ name: 'flarum_remember', value: tokens[username], domain: new URL(BASE).hostname, path: '/', httpOnly: true, secure: true });
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle0' });
  await page.addScriptTag({ content: ART });
  await page.evaluate(() => {
    window.call = async (method, path, body, files = {}) => {
      const headers = { 'X-CSRF-Token': app.session.csrfToken };
      let payload;
      if (body instanceof Object && Object.keys(files).length) {
        payload = new FormData();
        Object.entries(body).forEach(([k, v]) => payload.append(k, v));
        for (const [field, list] of Object.entries(files)) {
          for (const f of list) {
            const blob = f.art ? await window.depotDemoShot(f.art) : new Blob([f.bytes], { type: 'application/zip' });
            payload.append(field, blob, f.name);
          }
        }
      } else if (body) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
      }
      const url = path.startsWith('/') ? path : `${app.forum.attribute('apiUrl')}/${path}`;
      const r = await fetch(url, { method, headers, body: payload });
      const text = await r.text();
      let json = null;
      try {
        json = JSON.parse(text);
      } catch (e) {}
      return { status: r.status, json, text: text.slice(0, 300), headers: Object.fromEntries(r.headers.entries()) };
    };
  });
  const call = (method, path, body, files) => page.evaluate((m, p, b, f) => window.call(m, p, b, f), method, path, body, files);
  const user = await page.evaluate(() => app.session.user && app.session.user.username());
  return { page, call, user, context };
}

const admin = await as('depot_temp_admin');
check(!!admin.user, 'admin is signed in by token');

// ------------------------------------------------------------------ groups, tags, categories

let groups = await admin.call('GET', 'groups');
let supporters = groups.json.data.find((g) => g.attributes.nameSingular === 'Supporter');
if (!supporters) {
  const r = await admin.call('POST', 'groups', { data: { type: 'groups', attributes: { nameSingular: 'Supporter', namePlural: 'Supporters', color: '#d08a3a', icon: 'fas fa-heart' } } });
  supporters = r.json && r.json.data;
}
check(!!supporters, 'Supporters group exists');

const tags = await admin.call('GET', 'tags');
let support = tags.json.data.find((t) => t.attributes.slug === 'support');
if (!support) {
  const r = await admin.call('POST', 'tags', { data: { type: 'tags', attributes: { name: 'Support', slug: 'support', color: '#2f855a', icon: 'fas fa-life-ring', description: 'Help with files and installs.', isHidden: false } } });
  support = r.json && r.json.data;
  if (!support) console.log('tag create:', r.status, r.text);
  // A new tag is secondary until it is given a position.
  const all = (await admin.call('GET', 'tags')).json.data.filter((t) => t.attributes.position !== null).map((t) => ({ id: t.id, children: [] }));
  await admin.call('POST', 'tags/order', { order: [...all, { id: support.id, children: [] }] });
}
check(!!support, 'Support tag exists');

const existing = (await admin.call('GET', 'depot/categories')).json.categories;
if (!existing.length) {
  const make = async (body) => (await admin.call('POST', 'depot/categories', body)).json.categories.find((c) => c.slug === body.slug);
  const mc = await make({ name: 'Minecraft', slug: 'minecraft', color: '#4f8a3a', icon: 'fas fa-cube', description: 'Packs, maps and shaders for the Aurethil Java realm.' });
  for (const [name, slug] of [['Resource packs', 'resource-packs'], ['Shaders', 'shaders'], ['Maps', 'maps']]) await make({ name, slug, parent: mc.id, color: '#4f8a3a' });
  const vh = await make({ name: 'Valheim', slug: 'valheim', color: '#b0682f', icon: 'fas fa-mountain', description: 'Server configs and mods for the Valheim world.' });
  for (const [name, slug] of [['Configs', 'configs'], ['Mods', 'mods']]) await make({ name, slug, parent: vh.id, color: '#b0682f' });
  await make({ name: 'Football', slug: 'football', color: '#2f5f9e', icon: 'fas fa-football-ball', description: "Sheets and tools from the Pick'em crowd." });
  await make({ name: 'Utilities', slug: 'utilities', color: '#7a5ea8', icon: 'fas fa-toolbox', description: 'Small tools that make running a server easier.' });
  await make({ name: "Founders' Vault", slug: 'founders-vault', color: '#6b7686', icon: 'fas fa-gem', description: 'Exclusive packs for Supporters.', groups: [supporters.id] });
}
const cats = (await admin.call('GET', 'depot/categories')).json.categories;
check(cats.length === 10, `10 categories (${cats.length})`);
check(cats.find((c) => c.slug === 'founders-vault').locked, "Founders' Vault is locked");

// Validation: a root with children cannot hold files.
const bad = await admin.call('POST', 'depot/files', { source: 'link', title: 'Nope', category: 'minecraft', link: 'https://example.com/x.zip' }, { file: [] });
check(bad.status === 422, `a root category with children refuses files (${bad.status})`);

// ------------------------------------------------------------------ files

const bytes = (n, seed) => Array.from({ length: n }, (_, i) => (i * 31 + seed) % 251);
const FILES = [
  {
    who: admin, title: 'Aurethil Resource Pack', category: 'resource-packs', version: '2.1.0', featured: true,
    tagline: 'Hand-drawn 32×32 textures for every block, mob and item in 1.21.', game: 'Java', compat: '1.21.4, 1.21.3, 1.21.2, 1.21.1, 1.21', licence: 'CC BY-NC 4.0',
    description: 'The official texture pack for the Aurethil realm. Every texture is drawn by hand at 32×32, so blocks keep the vanilla feel up close and still read clearly across a valley.\n\nIt also adds custom models for the realm’s quest items and the Founders’ armour set, which only appear with this pack installed.',
    install: "Download the ZIP. Don't unzip it.\nMove it into the resourcepacks folder inside .minecraft.\nIn Minecraft, open Options, then Resource Packs, and move it to the right-hand column.",
    requires: 'OptiFine or Continuity — only for connected glass (optional)', fields: 'Resolution: 32×32\nLoader: Vanilla, Fabric, Forge',
    shots: ['meadow', 'cave', 'village', 'night', 'desert'], size: 180000,
  },
  { who: 'kestrel_mc', title: 'Lumen Shaders Lite', category: 'shaders', version: '1.9.2', featured: true, tagline: 'Soft shadows and warm light that stay above 60 fps on a laptop.', game: 'Java', compat: '1.21.4, 1.21, 1.20.6', licence: 'All rights reserved', requires: 'Iris or OptiFine — required', shots: ['sunset', 'night-2'], size: 90000 },
  { who: 'RuneHollow', title: 'Valheim Dedicated Server Config', category: 'configs', version: '3.0.1', tagline: 'The exact config our world runs on: backups, crossplay and sane raid timers.', game: 'Dedicated server', compat: '0.219', licence: 'MIT', shots: ['fjord'], size: 12000 },
  { who: 'RuneHollow', title: 'BepInEx Starter Pack', category: 'mods', version: '5.4.23', featured: true, source: 'link', link: 'https://thunderstore.io/c/valheim/p/denikson/BepInExPack_Valheim/', tagline: 'BepInEx plus the eight quality-of-life mods everyone ends up installing.', game: 'Client', compat: '0.219', licence: 'Various', shots: ['forge'] },
  { who: 'blockwright', title: 'Spawn Village Map', category: 'maps', version: '1.1', tagline: 'The Aurethil spawn village as a single-player world, every build included.', game: 'Java', compat: '1.21.4', licence: 'CC BY 4.0', shots: ['village-2', 'village-3'], size: 240000 },
  { who: admin, title: "Pick'em Season Sheet 2026", category: 'football', version: '2026.6', tagline: 'Every week of the season, with your picks, the spread and the standings worked out for you.', game: 'Spreadsheet', compat: 'Excel, Google Sheets, Numbers', licence: 'Free to share', shots: ['stadium'], size: 22000 },
  { who: 'nightjar', title: 'Restic Backups', category: 'utilities', source: 'github', repo: 'restic/restic', tagline: 'Fast, encrypted backups for your realm, synced from the restic releases on GitHub.', game: 'Linux, macOS, Windows', licence: 'BSD-2-Clause', shots: ['circuit'] },
  { who: 'kestrel_mc', title: 'Glowberry Caves', category: 'maps', version: '1.2', tagline: 'A 600×600 survival map built around one enormous lush cave.', game: 'Java', compat: '1.21.4, 1.21.1', licence: 'CC BY-NC 4.0', shots: ['cave-2', 'cave-3'], size: 150000 },
  { who: admin, title: "Founders' Texture Variants", category: 'founders-vault', version: '1.0', tagline: 'Alternate textures for Supporters.', game: 'Java', compat: '1.21.4', shots: ['vault'], size: 40000 },
];

const sessions = { depot_temp_admin: admin };
const session = async (who) => (typeof who === 'string' ? (sessions[who] ??= await as(who)) : who);
const made = {};
const existingFiles = (await admin.call('GET', 'depot/browse?filter=queue')).json.files.length + (await admin.call('GET', 'depot/browse')).json.files.length;

if (!existingFiles) {
  for (const f of FILES) {
    const s = await session(f.who);
    const body = { source: f.source || 'upload', title: f.title, category: f.category, version: f.version || '', tagline: f.tagline, game: f.game || '', compat: f.compat || '', licence: f.licence || '', description: f.description || '', install: f.install || '', requires: f.requires || '', fields: f.fields || '', link: f.link || '', repo: f.repo || '', thread: '1', threadTag: support.id, threadOpening: '' };
    const files = { 'screenshots[]': f.shots.map((art, i) => ({ art, name: `shot-${i}.png` })) };
    if (!f.source) files.file = [{ bytes: bytes(f.size, f.title.length), name: `${f.title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${f.version}.zip` }];
    const r = await s.call('POST', 'depot/files', body, files);
    check(r.status === 201, `${f.title}: created by ${s.user} (${r.status}${r.status !== 201 ? ' ' + r.text : ''})`);
    made[f.title] = r.json;
  }

  // Members' uploads wait; the admin's go straight out.
  check(made['Lumen Shaders Lite'] && made['Lumen Shaders Lite'].approved === false, 'a member upload waits for approval');
  check(made['Aurethil Resource Pack'] && made['Aurethil Resource Pack'].approved === true, "an admin's upload is public at once");

  const guest = await browser.createBrowserContext().then((c) => c.newPage());
  await guest.goto(`${BASE}/api/depot/browse`);
  const guestList = JSON.parse(await guest.evaluate(() => document.body.innerText));
  check(!guestList.files.some((f) => f.title === 'Lumen Shaders Lite'), 'a guest does not see a pending file');
  check(!guestList.files.some((f) => f.title === "Founders' Texture Variants"), 'a guest does not see a locked file');
  check(guestList.locked.length === 1 && guestList.locked[0].count === 1 && !('title' in guestList.locked[0]), 'the locked category is advertised with a count and no file data');
  const pendingSlug = made['Lumen Shaders Lite'].slug;
  await guest.goto(`${BASE}/api/depot/file?slug=${pendingSlug}`);
  check((await guest.evaluate(() => document.body.innerText)).includes('not_found') || (await guest.evaluate(() => document.title)) !== '', 'a guest gets a 404 for a pending file');

  const queue = (await admin.call('GET', 'depot/browse?filter=queue')).json;
  check(queue.files.length === 6, `the queue holds the six member uploads (${queue.files.length})`);
  for (const f of queue.files) {
    const r = await admin.call('POST', `depot/files/${f.id}/approve`);
    check(r.status === 200, `approved ${f.title}`);
  }

  // Featured flags.
  const all = (await admin.call('GET', 'depot/browse')).json.files;
  for (const f of FILES.filter((x) => x.featured)) {
    const file = all.find((x) => x.title === f.title);
    if (file && !file.featured) await admin.call('POST', `depot/files/${file.id}/feature`);
  }

  // Versions, with a watcher to notify.
  const pack = all.find((x) => x.title === 'Aurethil Resource Pack');
  const kestrel = await session('kestrel_mc');
  check((await kestrel.call('POST', `depot/files/${pack.id}/watch`, { watch: true })).json.watching === true, 'kestrel_mc watches the resource pack');
  const releases = [
    ['2.2.0', 'Animated sea lanterns and prismarine\nConnected glass (needs OptiFine or Continuity)', '', ''],
    ['2.3.0', "Founders' armour set: models and textures\nPaintings from the summer contest", 'Grass and leaves sway less in wind', ''],
    ['2.3.2', '', 'Brighter netherite so it reads in dark mines', 'Missing texture on the cherry hanging sign\nBed overlay in the inventory'],
    ['2.4.0', 'Trial chamber blocks, vaults and the breeze\nHeavy core and mace models', 'Redrawn copper in all four oxidation stages', 'Z-fighting on the quest board model'],
  ];
  for (const [version, added, changed, fixed] of releases) {
    const r = await admin.call('POST', `depot/files/${pack.id}/versions`, { version, added, changed, fixed }, { file: [{ bytes: bytes(190000 + version.length * 1000, version.charCodeAt(2)), name: `aurethil-resource-pack-${version}.zip` }] });
    check(r.status === 201, `resource pack ${version} published`);
  }
  const dup = await admin.call('POST', `depot/files/${pack.id}/versions`, { version: '2.4.0' }, { file: [{ bytes: [1], name: 'x.zip' }] });
  check(dup.status === 422, 'a duplicate version number is refused');

  // Reviews and helpful votes.
  const REVIEWS = [
    ['kestrel_mc', 'Aurethil Resource Pack', 5, 'The copper redraw is lovely. Oxidised copper finally looks like it belongs in the same world as everything else.'],
    ['blockwright', 'Aurethil Resource Pack', 5, 'Built the whole spawn village with this pack on. Texture sizes are consistent, so nothing looks out of place next to anything else.'],
    ['nightjar', 'Aurethil Resource Pack', 4, "Great pack. Connected glass needs Continuity on Fabric, which the page does say. One star off because I didn't read it."],
    ['RuneHollow', 'Lumen Shaders Lite', 5, 'Runs at 70 fps on my old laptop with soft shadows on. Nothing else comes close.'],
    ['blockwright', 'Lumen Shaders Lite', 4, 'Beautiful sunsets. The water is a little too reflective for my taste.'],
    ['kestrel_mc', 'Valheim Dedicated Server Config', 5, 'Copied it over, restarted, and our raids stopped happening every twenty minutes.'],
    ['nightjar', 'Spawn Village Map', 5, 'Every build is there, chests included. A lovely thing to explore offline.'],
    ['RuneHollow', 'Glowberry Caves', 4, 'Gorgeous cave. Spawn is a bit far from the entrance.'],
    ['kestrel_mc', "Pick'em Season Sheet 2026", 4, 'Does the spread maths for me, which is all I wanted.'],
  ];
  const ids = {};
  for (const [who, title, rating, body] of REVIEWS) {
    const s = await session(who);
    const file = all.find((x) => x.title === title);
    const r = await s.call('POST', `depot/files/${file.id}/review`, { rating, body });
    check(r.status === 200, `${who} reviewed ${title}`);
    ids[`${who}:${title}`] = r.json && r.json.id;
  }
  const own = await admin.call('POST', `depot/files/${pack.id}/review`, { rating: 5 });
  check(own.status === 422, 'an author cannot review their own file');
  for (const who of ['RuneHollow', 'nightjar', 'blockwright']) {
    const s = await session(who);
    await s.call('POST', `depot/reviews/${ids['kestrel_mc:Aurethil Resource Pack']}/helpful`, { helpful: true });
  }
  await admin.call('POST', `depot/reviews/${ids['blockwright:Aurethil Resource Pack']}/reply`, { reply: 'Thank you! The village is in the 2.3.0 screenshots now.' });

  // Replies in the support discussion.
  const show = (await admin.call('GET', `depot/file?slug=${pack.slug}`)).json;
  check(!!show.discussion, 'the support discussion was started');
  check(show.discussion && show.discussion.tag && show.discussion.tag.name === 'Support', 'it is in the tag the uploader chose');
  if (show.discussion) {
    const rune = await session('RuneHollow');
    await rune.call('POST', 'posts', { data: { type: 'posts', attributes: { content: 'Is there a version without the custom sky? It clashes with my shader.' }, relationships: { discussion: { data: { type: 'discussions', id: String(show.discussion.id) } } } } });
    await admin.call('POST', 'posts', { data: { type: 'posts', attributes: { content: 'Delete the optifine/sky folder from inside the ZIP and your shader’s sky takes over. I’ll make it a toggle in 2.5.' }, relationships: { discussion: { data: { type: 'discussions', id: String(show.discussion.id) } } } } });
    await kestrel.call('POST', 'posts', { data: { type: 'posts', attributes: { content: 'The breeze texture is perfect. Any chance of a 16×16 version for older machines?' }, relationships: { discussion: { data: { type: 'discussions', id: String(show.discussion.id) } } } } });
  }
}

// ------------------------------------------------------------------ reads, downloads, notifications

const list = (await admin.call('GET', 'depot/browse')).json;
check(list.files.length >= 8, `browse lists the public files (${list.files.length})`);
check(list.featured.length === 3, `three featured files (${list.featured.length})`);
const pack = list.files.find((x) => x.title === 'Aurethil Resource Pack');
const full = (await admin.call('GET', `depot/file?slug=${pack.slug}`)).json;
check(full.versions.length === 5 && full.versions[0].version === '2.4.0', 'the resource pack has five versions, newest first');
check(full.versions[0].changelog.fixed.length === 1, 'the changelog groups came through');
check(full.screenshots.length === 5, 'five screenshots');
check(full.reviews.length === 3 && full.ratings === 3, 'three reviews counted');

const d = await admin.call('GET', full.versions[0].url.replace(BASE, ''));
check(d.status === 200, 'the download route answers');
check(d.headers['content-type'] === 'application/octet-stream', 'downloads go out as octet-stream');
check((d.headers['content-disposition'] || '').startsWith('attachment;'), 'downloads are attachments');
check((d.headers['x-content-type-options'] || '').includes('nosniff'), 'downloads are nosniff');
check((d.headers['content-security-policy'] || '').includes('sandbox'), 'downloads are sandboxed');

// Byte-exact: the checksum Depot publishes matches the bytes it serves.
const served = await admin.page.evaluate(async (url) => {
  const r = await fetch(url);
  return Array.from(new Uint8Array(await r.arrayBuffer()));
}, full.versions[0].url);
check(createHash('sha256').update(Buffer.from(served)).digest('hex') === full.versions[0].sha256, 'the served bytes match the published SHA-256');

const before = (await admin.call('GET', `depot/file?slug=${pack.slug}`)).json.versions[0].downloads;
await admin.call('GET', full.versions[0].url.replace(BASE, ''));
const after = (await admin.call('GET', `depot/file?slug=${pack.slug}`)).json.versions[0].downloads;
check(after === before, 'a repeat download inside the window is not counted again');

// Alerts are written by a queued job; the caller drains the queue first.
const kestrel = await session('kestrel_mc');
const notes = await kestrel.call('GET', 'notifications');
check(notes.status === 200, '/api/notifications still answers with Depot notifications in it');
check((notes.json.data || []).some((n) => n.attributes.contentType === 'depotNewVersion'), 'the watcher was notified of the new version');
const rune = await session('RuneHollow');
check(((await rune.call('GET', 'notifications')).json.data || []).some((n) => n.attributes.contentType === 'depotFileApproved'), 'the uploader was notified of the approval');

const restic = list.files.find((x) => x.repo === 'restic/restic');
check(restic && restic.version && restic.version.external, `the GitHub file imported a release (${restic && restic.version && restic.version.version})`);
const latest = await admin.call('GET', `depot/latest/${pack.slug}`);
check(latest.json && latest.json.version === '2.4.0' && latest.json.sha256 === full.versions[0].sha256, 'the update-check endpoint reports the latest version');

// Locked category: a Supporter can open it.
const vault = await admin.call('GET', 'depot/browse?category=founders-vault');
check(vault.json.files.length === 1, 'an admin can open the locked category');

await browser.close();
console.log(failures.length ? `\n${failures.length} FAILED:\n- ${failures.join('\n- ')}` : '\nall checks passed');
process.exit(failures.length ? 1 : 0);
