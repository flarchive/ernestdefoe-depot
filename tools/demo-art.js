/*
 * Demo screenshots for tools/seed-demo.mjs, injected into a page — never
 * shipped in the extension's bundle. Cover art for the prototype: small pixel landscapes drawn once on a canvas
 * and cached as data URLs. The real extension shows uploaded screenshots;
 * this only stands in for them so the layout can be judged with pictures in
 * it rather than grey boxes.
 */

const W = 96;
const H = 54;
const cache = {};

const SCENES = {
  meadow: { sky: ['#7cc4f2', '#b9e3fb'], sun: '#fff6c9', far: '#7fae6b', near: '#4f8a3a', soil: '#7a5530', trees: '#2f6b2a' },
  cave: { sky: ['#0f1a17', '#1d2f29'], far: '#25403a', near: '#3b5f44', soil: '#1a2622', glow: '#b6f46c', drips: true },
  village: { sky: ['#8fd0f5', '#d8f0fb'], sun: '#fff3b8', far: '#8db878', near: '#5a9a44', soil: '#7a5530', trees: '#356f2c', houses: true },
  night: { sky: ['#0b1430', '#23315e'], moon: '#e9ecf8', far: '#22324f', near: '#2f4a3a', soil: '#1c2620', stars: true, trees: '#1d3324' },
  desert: { sky: ['#f2b56b', '#fbe2b0'], sun: '#fff1cf', far: '#d9a35f', near: '#e8c27f', soil: '#c99a58' },
  sunset: { sky: ['#3a2a6b', '#f0875a'], sun: '#ffd27a', far: '#5b3f6e', near: '#2d2546', soil: '#1d1830', trees: '#1b1530' },
  fjord: { sky: ['#9fc3d9', '#dfeef5'], far: '#56687a', near: '#3a4a3c', soil: '#2c3a30', water: '#3e6f8f', snow: true },
  forge: { sky: ['#140d0b', '#3a1d12'], far: '#2a1a14', near: '#3b2419', soil: '#1c120d', embers: '#ffa04a', glow: '#ff7a2f' },
  stadium: { sky: ['#0e2340', '#2f5f9e'], far: '#1a2f50', near: '#2f7d3a', soil: '#25642e', field: true, lights: '#fff7d6' },
  circuit: { sky: ['#1d1636', '#3a2b6a'], far: '#2b2150', near: '#3a2b6a', soil: '#241a44', circuit: '#c7b8ee' },
  vault: { sky: ['#20242b', '#3b424d'], far: '#2c323b', near: '#4a525e', soil: '#2a2f37', glow: '#e0b14a', drips: true },
};

function rng(seed) {
  let s = seed >>> 0 || 1;
  return () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296);
}

function hash(str) {
  return Array.from(str).reduce((h, c) => ((h * 31) ^ c.charCodeAt(0)) >>> 0, 2166136261);
}

function mix(a, b, t) {
  const p = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
  const [x, y] = [p(a), p(b)];
  return `rgb(${x.map((v, i) => Math.round(v + (y[i] - v) * t)).join(',')})`;
}

function ridge(r, base, rough) {
  const pts = [];
  let y = base;
  for (let x = 0; x < W; x++) {
    y += (r() - 0.5) * rough;
    y += (base - y) * 0.08;
    pts.push(Math.round(y));
  }
  return pts;
}

function draw(key) {
  const [name, variant = '1'] = key.split('-');
  const s = SCENES[name] || SCENES.meadow;
  const r = rng(hash(key) + Number(variant) * 7919);
  const c = document.createElement('canvas');
  c.width = W;
  c.height = H;
  const g = c.getContext('2d');
  const px = (x, y, col, w = 1, h = 1) => {
    g.fillStyle = col;
    g.fillRect(x, y, w, h);
  };

  // Sky, banded rather than smooth so it reads as pixel art.
  for (let y = 0; y < H; y++) px(0, y, mix(s.sky[0], s.sky[1], Math.round((y / H) * 8) / 8), W);

  if (s.stars) for (let i = 0; i < 40; i++) px(Math.floor(r() * W), Math.floor(r() * 28), r() > 0.8 ? '#ffffff' : '#9fb0e0');
  if (s.sun) px(Math.floor(60 + r() * 24), 7, s.sun, 8, 8);
  if (s.moon) {
    const mx = Math.floor(66 + r() * 16);
    px(mx, 6, s.moon, 7, 7);
    px(mx + 4, 6, s.sky[0], 3, 3);
  }
  if (s.lights) [10, 30, 64, 84].forEach((x) => { px(x, 4, s.lights, 4, 2); px(x + 1, 6, '#8aa0c0', 2, 10); });

  // Far ridge, with snow on the peaks for the fjord.
  const far = ridge(r, s.field ? 26 : 24, s.snow ? 5 : 2.5);
  far.forEach((y, x) => {
    px(x, y, s.far, 1, H - y);
    if (s.snow && y < 22) px(x, y, '#eef4f8', 1, 2);
  });

  // Water fills the bottom of the fjord before the near shore is drawn.
  if (s.water) {
    for (let y = 36; y < H; y++) px(0, y, mix(s.water, '#1f3a4d', (y - 36) / 18), W);
    for (let i = 0; i < 26; i++) px(Math.floor(r() * W), 37 + Math.floor(r() * 16), '#8fb8cf', 2 + Math.floor(r() * 3), 1);
  }

  if (s.field) {
    for (let y = 30; y < H; y++) px(0, y, (Math.floor(y / 2) % 2 ? s.near : s.soil), W);
    for (let x = 6; x < W; x += 10) px(x, 30, '#e8f2e8', 1, H - 30);
    px(0, 26, '#30405c', W, 4);
    for (let i = 0; i < 60; i++) px(Math.floor(r() * W), 26 + Math.floor(r() * 4), ['#c8102e', '#ffffff', '#ffcc33'][i % 3]);
  } else {
    const nearBase = s.water ? 40 : 36;
    const near = ridge(r, nearBase, 1.6);
    near.forEach((y, x) => {
      px(x, y, s.near, 1, 2);
      px(x, y + 2, s.soil, 1, H - y);
      if (r() > 0.85) px(x, y + 4 + Math.floor(r() * 10), mix(s.soil, '#000000', 0.25));
    });

    if (s.trees) {
      for (let i = 0; i < 7; i++) {
        const x = Math.floor(r() * (W - 6));
        const y = near[x + 2];
        px(x + 2, y - 5, '#5a3d22', 1, 5);
        px(x, y - 9, s.trees, 5, 4);
        px(x + 1, y - 10, s.trees, 3, 1);
      }
    }

    if (s.houses) {
      for (let i = 0; i < 4; i++) {
        const x = 8 + i * 22 + Math.floor(r() * 6);
        const y = near[x + 4];
        px(x, y - 7, '#d9c7a1', 9, 7);
        px(x - 1, y - 9, '#9b4a32', 11, 2);
        px(x + 1, y - 10, '#9b4a32', 7, 1);
        px(x + 3, y - 4, '#5a3d22', 2, 4);
        px(x + 6, y - 6, '#ffe28a', 2, 2);
      }
    }

    if (s.circuit) {
      for (let i = 0; i < 14; i++) {
        let x = Math.floor(r() * W);
        let y = Math.floor(r() * 30) + 4;
        for (let k = 0; k < 18; k++) {
          px(x, y, mix(s.circuit, s.sky[0], 0.4));
          if (r() > 0.5) x += 1; else y += r() > 0.5 ? 1 : -1;
        }
        px(x - 1, y - 1, s.circuit, 3, 3);
      }
    }
  }

  if (s.drips) {
    for (let x = 0; x < W; x += 3 + Math.floor(r() * 4)) px(x, 0, s.far, 2, 3 + Math.floor(r() * 9));
  }
  if (s.glow) for (let i = 0; i < 26; i++) px(Math.floor(r() * W), 10 + Math.floor(r() * 40), s.glow);
  if (s.embers) for (let i = 0; i < 30; i++) px(Math.floor(r() * W), Math.floor(r() * 44), r() > 0.5 ? s.embers : '#ffd28a');

  return c.toDataURL();
}

/**
 * A PNG Blob of a scene at screenshot size, scaled up without smoothing so
 * it stays crisp pixel art.
 */
window.depotDemoShot = async (key, width = 1600) => {
  const img = new Image();
  img.src = cache[key] || (cache[key] = draw(key));
  await img.decode();
  const c = document.createElement('canvas');
  c.width = width;
  c.height = Math.round((width * H) / W);
  const g = c.getContext('2d');
  g.imageSmoothingEnabled = false;
  g.drawImage(img, 0, 0, c.width, c.height);
  return new Promise((resolve) => c.toBlob(resolve, 'image/png'));
};
