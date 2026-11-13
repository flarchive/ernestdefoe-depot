/* Depot's pages, in the one chunk that loads the first time someone opens Downloads. */
import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import { state, category } from './depot-api';
import { t } from './components/bits';

export { default as DepotPage } from './components/DepotPage';
export { default as FilePage } from './components/FilePage';

/*
 * Breadcrumbs for Waymark, when it is installed: Home › Downloads › each
 * category down to this one › the file. Without these, Waymark could only fall
 * back to the page title, so a file showed as "Home › Depot" with its
 * categories missing (ClaudiusH, 2026-10-15).
 *
 * Registered here, in the pages' chunk, not the every-page bundle: these routes
 * are the only ones that need them, and they always load this chunk first.
 */

const downloads = (linked) => ({ label: extractText(t('title')), href: linked ? app.route('depot.index') : undefined });

/** A category and every category above it, top first, each linked. */
function lineage(slug) {
  const crumbs = [];
  const seen = new Set();

  // `seen` stops a parent loop from hanging the page; the data should never have one.
  for (let c = category(slug); c && !seen.has(c.slug); c = c.parent ? category(c.parent) : null) {
    seen.add(c.slug);
    crumbs.unshift({ label: c.name, href: app.route('depot.category', { slug: c.slug }) });
  }

  return crumbs;
}

const trails = {
  'depot.index': () => {
    const filter = m.route.param('filter');
    return filter ? [downloads(true), { label: extractText(t(`nav.${filter}`)) }] : [downloads(false)];
  },
  'depot.category': () => {
    const crumbs = lineage(m.route.param('slug'));
    // The page's own category is where you are, so it is not a link.
    if (crumbs.length) delete crumbs[crumbs.length - 1].href;
    return [downloads(true), ...crumbs];
  },
  'depot.file': () => {
    const file = state.file;
    if (!file || file.slug !== m.route.param('slug')) return [downloads(true)];
    return [downloads(true), ...lineage(file.category), { label: file.title }];
  },
};

if (app.waymark) Object.entries(trails).forEach(([route, crumbs]) => app.waymark.register(route, crumbs));
