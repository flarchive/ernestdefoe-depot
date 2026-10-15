import app from 'flarum/forum/app';

/*
 * Depot's API, and the little state the pages share.
 *
 * Plain JSON endpoints rather than JSON:API resources: Depot's shapes are
 * built for its pages, so the pages ask for exactly what they draw.
 */
const url = (path) => `${app.forum.attribute('apiUrl')}/depot/${path}`;

export const api = {
  get: (path, params) => app.request({ method: 'GET', url: url(path), params }),
  post: (path, body) => app.request({ method: 'POST', url: url(path), body }),
  del: (path) => app.request({ method: 'DELETE', url: url(path) }),
  // Multipart: Flarum must not try to JSON-encode the body.
  upload: (path, form, onprogress) =>
    app.request({
      method: 'POST',
      url: url(path),
      body: form,
      serialize: (raw) => raw,
      config: (xhr) => onprogress && xhr.upload.addEventListener('progress', (e) => e.lengthComputable && onprogress(e.loaded / e.total)),
    }),
};

/** Categories, as last sent by the server. Every page response carries them. */
export const state = { categories: [], file: null };

export const category = (slug) => state.categories.find((c) => c.slug === slug);
export const rootOf = (c) => (c && c.parent ? category(c.parent) : c);
export const childrenOf = (slug) => state.categories.filter((c) => c.parent === slug);
export const roots = () => state.categories.filter((c) => !c.parent);

// --- formatting -------------------------------------------------------------

export function bytes(n) {
  if (!n) return '';
  const u = ['B', 'KB', 'MB', 'GB'];
  let i = 0;
  while (n >= 1024 && i < u.length - 1) {
    n /= 1024;
    i++;
  }
  return `${n >= 10 || i === 0 ? Math.round(n) : n.toFixed(1)} ${u[i]}`;
}

export const number = (n) => Number(n || 0).toLocaleString();

export function compact(n) {
  n = Number(n || 0);
  if (n < 1000) return String(n);
  return (n / 1000).toFixed(n < 10000 ? 1 : 0).replace(/\.0$/, '') + 'k';
}

export const daysAgo = (iso) => (iso ? Math.floor((Date.now() - new Date(iso)) / 86400000) : Infinity);
