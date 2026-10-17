import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import Button from 'flarum/common/components/Button';
import SelectDropdown from 'flarum/common/components/SelectDropdown';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import { api, state, category, rootOf, childrenOf, roots } from '../depot-api';
import { t, coverStyle } from './bits';
import FileCard, { LockedCard } from './FileCard';
import Spotlight from './Spotlight';
import DepotSidebar from './DepotSidebar';

const SORTS = ['updated', 'downloads', 'rating', 'newest'];

function stored(key, fallback) {
  try {
    return localStorage.getItem(key) || fallback;
  } catch (e) {
    return fallback;
  }
}

/**
 * Browse: every file, one category's, or one of the member's own views
 * (watching, my files, the approval queue).
 *
 * Flarum's PageStructure and a subclass of core's IndexSidebar, so the
 * mobile drop-down, the sidebar and the spacing are core's own and every
 * theme that styles the discussion list styles this too.
 */
export default class DepotPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--index';
    this.sort = 'updated';
    this.mode = stored('depot.view', 'grid');
    this.compat = '';
    this.query = '';
    this.data = null;
    this.files = [];
    this.loading = true;
    this.load();
  }

  onremove(vnode) {
    super.onremove(vnode);
    clearTimeout(this.debounce);
  }

  slug() {
    return m.route.param('slug') || '';
  }

  filter() {
    return m.route.param('filter') || '';
  }

  load(page = 0) {
    this.loading = true;
    const params = { category: this.slug(), sort: this.sort, q: this.query, compat: this.compat, filter: this.filter(), page };
    const ask = (this.request = api.get('browse', params));

    return ask.then(
      (data) => {
        // A slower, older answer must not overwrite a newer one.
        if (ask !== this.request) return;
        state.categories = data.categories;
        this.files = page ? [...this.files, ...data.files] : data.files;
        this.data = data;
        this.loading = false;
        m.redraw();
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }

  view() {
    const c = category(this.slug());
    const filter = this.filter();
    app.setTitle(c ? c.name : filter ? extractText(t(`nav.${filter}`)) : extractText(t('title')));
    app.setTitleCount(0);

    return (
      <PageStructure className="IndexPage DepotPage" hero={() => this.hero(c)} sidebar={() => <DepotSidebar />}>
        {this.content(c)}
      </PageStructure>
    );
  }

  hero(c) {
    if (!this.data) return null;

    if (!c) {
      const featured = this.data.featured || [];
      return featured.length && !this.filter() ? <Spotlight files={featured} /> : this.plainHero();
    }

    const root = rootOf(c);
    const count = c.parent ? c.count : childrenOf(c.slug).reduce((n, s) => n + s.count, c.count);
    return (
      <header className="DepotHero" style={{ '--hero-tint': root.color || 'var(--primary-color)' }}>
        <div className="Page-container container DepotHero-inner">
          {c.parent ? (
            <a className="DepotHero-up" href={app.route('depot.category', { slug: root.slug })}>
              <Icon name="fas fa-chevron-left" /> {root.name}
            </a>
          ) : null}
          <h1 className="DepotHero-title">
            {root.icon && !c.parent ? <Icon name={root.icon} /> : null} {c.name}
          </h1>
          {c.description || root.description ? <p className="DepotHero-desc">{c.description || root.description}</p> : null}
          <span className="DepotHero-count">{t('hero.count', { count })}</span>
        </div>
      </header>
    );
  }

  plainHero() {
    const filter = this.filter();
    return (
      <header className="DepotHero" style={{ '--hero-tint': 'var(--primary-color)' }}>
        <div className="Page-container container DepotHero-inner">
          <h1 className="DepotHero-title">
            <Icon name="fas fa-box-open" /> {filter ? t(`nav.${filter}`) : t('title')}
          </h1>
          <p className="DepotHero-desc">{t(`hero.${filter || 'all'}_desc`)}</p>
        </div>
      </header>
    );
  }

  content(c) {
    if (!this.data) return <LoadingIndicator display="block" />;
    const d = this.data;
    const filter = this.filter();

    return (
      <div className="IndexPage-results sideNavOffset DepotPage-results">
        {d.pending && filter !== 'queue' ? (
          <div className="DepotNotice">
            <Icon name="fas fa-hourglass-half" />
            <span>{t('notice.pending', { count: d.pending })}</span>
            <a className="Button Button--small" href={app.route('depot.index', { filter: 'queue' })}>
              {t('notice.review')}
            </a>
          </div>
        ) : null}

        {!c && !filter ? this.categoryTiles() : c ? this.subnav(c) : null}

        <div className="DepotToolbar">
          <SelectDropdown buttonClassName="Button" className="DepotToolbar-sort" accessibleToggleLabel={t('toolbar.sort')}>
            {SORTS.map((key) => (
              <Button icon={this.sort === key ? 'fas fa-check' : true} active={this.sort === key} onclick={() => this.set({ sort: key })}>
                {t(`toolbar.sort_${key}`)}
              </Button>
            ))}
          </SelectDropdown>

          {d.compat.length ? (
            <div className="DepotToolbar-chips" role="group" aria-label={t('toolbar.compat')}>
              {d.compat.map((v) => (
                <button
                  type="button"
                  className={`Depot-chip ${this.compat === v ? 'is-active' : ''}`}
                  aria-pressed={this.compat === v ? 'true' : 'false'}
                  onclick={() => this.set({ compat: this.compat === v ? '' : v })}
                >
                  {v}
                </button>
              ))}
            </div>
          ) : null}

          <div className="DepotToolbar-search">
            <Icon name="fas fa-search" />
            <input
              className="FormControl"
              type="search"
              placeholder={extractText(t('toolbar.search'))}
              aria-label={extractText(t('toolbar.search'))}
              value={this.query}
              oninput={(e) => {
                this.query = e.target.value;
                clearTimeout(this.debounce);
                this.debounce = setTimeout(() => this.load(), 300);
              }}
            />
          </div>

          <div className="DepotToolbar-view" role="group" aria-label={t('toolbar.view')}>
            {['grid', 'list'].map((mode) => (
              <Button
                className={`Button Button--icon ${this.mode === mode ? 'active' : ''}`}
                icon={mode === 'grid' ? 'fas fa-th-large' : 'fas fa-list'}
                aria-label={t(`toolbar.view_${mode}`)}
                aria-pressed={this.mode === mode ? 'true' : 'false'}
                onclick={() => this.setMode(mode)}
              />
            ))}
          </div>
        </div>

        <div className="DepotToolbar-summary Depot-dim">
          {t('toolbar.showing', { count: this.files.length })}
          {this.compat ? (
            <button type="button" className="Depot-clear" onclick={() => this.set({ compat: '' })}>
              {t('toolbar.clear_filter', { version: this.compat })} <Icon name="fas fa-times" />
            </button>
          ) : null}
        </div>

        {this.files.length || d.locked.length ? (
          <div className={this.mode === 'grid' ? 'DepotGrid' : 'DepotList'}>
            {this.files.map((file) => (
              <FileCard file={file} mode={this.mode} key={file.id} />
            ))}
            {d.locked.map((locked) => LockedCard({ locked, mode: this.mode }))}
          </div>
        ) : this.loading ? (
          <LoadingIndicator display="block" />
        ) : (
          this.empty()
        )}

        {d.hasMore ? (
          <div className="DepotMoreButton">
            <Button className="Button" loading={this.loading} onclick={() => this.load(d.page + 1)}>
              {t('toolbar.load_more')}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }

  empty() {
    const filtered = this.query || this.compat;
    const filter = this.filter();
    return (
      <div className="DepotEmpty">
        <Icon name="fas fa-box-open" />
        <h3>{t(filtered ? 'empty.title' : 'empty.none_title')}</h3>
        <p>{t(filtered ? 'empty.body' : filter ? `empty.${filter}` : 'empty.none_body')}</p>
        {filtered ? (
          <Button className="Button" onclick={() => ((this.query = ''), this.set({ compat: '' }))}>
            {t('empty.reset')}
          </Button>
        ) : null}
      </div>
    );
  }

  set(changes) {
    Object.assign(this, changes);
    this.load();
  }

  /** Subcategories as chips above the files — one tap down, one tap back. */
  subnav(c) {
    const root = rootOf(c);
    const subs = childrenOf(root.slug);
    if (!subs.length) return null;
    const chip = (cat, label) => (
      <a className={`Depot-chip ${cat.slug === c.slug ? 'is-active' : ''}`} href={app.route('depot.category', { slug: cat.slug })}>
        {label || cat.name}
      </a>
    );
    return (
      <nav className="DepotSubnav" aria-label={t('nav.subcategories')}>
        {chip(root, t('nav.everything_in', { name: root.name }))}
        {subs.map((sub) => chip(sub))}
      </nav>
    );
  }

  categoryTiles() {
    const list = roots();
    if (!list.length) return null;
    return (
      <div className="DepotTiles">
        {list.map((c) => {
          const count = childrenOf(c.slug).reduce((n, s) => n + s.count, c.count);
          return (
            <a
              className={`DepotTile ${c.locked ? 'DepotTile--locked' : ''}`}
              href={app.route('depot.category', { slug: c.slug })}
              style={{ '--label-color': c.color || 'var(--primary-color)' }}
            >
              <span className="DepotTile-art" style={this.tileArt(c)} />
              <span className="DepotTile-icon">
                <Icon name={c.locked ? 'fas fa-lock' : c.icon || 'fas fa-folder'} />
              </span>
              <span className="DepotTile-name">{c.name}</span>
              <span className="DepotTile-count">{c.locked ? t('tiles.locked', { group: c.groups.join(', ') }) : t('tiles.count', { count })}</span>
            </a>
          );
        })}
      </div>
    );
  }

  /** A tile shows a screenshot from one of its own files, when this page has one. */
  tileArt(c) {
    const ids = [c.slug, ...childrenOf(c.slug).map((s) => s.slug)];
    const file = [...(this.data.featured || []), ...this.files].find((f) => f.cover && ids.includes(f.category));
    return file ? coverStyle(file) : {};
  }

  setMode(mode) {
    this.mode = mode;
    try {
      localStorage.setItem('depot.view', mode);
    } catch (e) {
      // Private windows refuse storage; the choice simply is not remembered.
    }
  }
}
