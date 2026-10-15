import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import Button from 'flarum/common/components/Button';
import SplitDropdown from 'flarum/common/components/SplitDropdown';
import Dropdown from 'flarum/common/components/Dropdown';
import Icon from 'flarum/common/components/Icon';
import Tooltip from 'flarum/common/components/Tooltip';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import { api, state, bytes, number, compact } from '../depot-api';
import { t, Sparkline, Stars, Avatar, UserChip, CategoryLabels, SourceBadge, Cover, coverStyle } from './bits';
import DownloadModal, { startDownload } from './DownloadModal';
import LightboxModal from './LightboxModal';
import ReviewModal from './ReviewModal';
import VersionModal from './VersionModal';
import EditFileModal from './EditFileModal';

const shortDate = (d) => dayjs(d).format('ll');
const TABS = ['overview', 'versions', 'reviews', 'discussion'];
const KINDS = [
  ['added', 'fas fa-plus'],
  ['changed', 'fas fa-pen'],
  ['fixed', 'fas fa-wrench'],
];

/**
 * One file. Laid out like a discussion — hero across the top, content on the
 * left, a sidebar on the right — so it reads as part of the forum. The sidebar
 * holds everything needed to decide and download without scrolling.
 */
export default class FilePage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.tab = 'overview';
    this.starFilter = 0;
    this.open = { 0: true };
    this.file = null;
    this.missing = false;
    this.replying = null;
    this.load();
  }

  load() {
    return api.get('file', { slug: m.route.param('slug') }).then(
      (file) => {
        state.categories = file.categories;
        this.file = file;
        m.redraw();
      },
      () => {
        this.missing = true;
        m.redraw();
      }
    );
  }

  view() {
    const file = this.file;
    if (!file) {
      return <PageStructure className="DepotFilePage">{this.missing ? this.notFound() : <LoadingIndicator display="block" />}</PageStructure>;
    }
    app.setTitle(file.title);
    app.setTitleCount(0);

    return (
      <PageStructure className="DepotFilePage" hero={() => this.hero(file)}>
        {!file.approved ? (
          <div className="DepotNotice">
            <Icon name="fas fa-hourglass-half" />
            <span>{t('file.pending')}</span>
            {file.canModerate ? (
              <Button className="Button Button--small Button--primary" onclick={() => this.act(`files/${file.id}/approve`)}>
                {t('file.approve')}
              </Button>
            ) : null}
          </div>
        ) : null}
        <div className="DepotFile">
          <div className="DepotFile-main">
            <nav className="DepotTabs" role="tablist">
              {TABS.map((tab) => (
                <button
                  type="button"
                  role="tab"
                  aria-selected={this.tab === tab ? 'true' : 'false'}
                  className={this.tab === tab ? 'is-active' : ''}
                  onclick={() => (this.tab = tab)}
                >
                  {t(`file.tab_${tab}`)}
                  {this.tabCount(file, tab)}
                </button>
              ))}
            </nav>
            <div className="DepotFile-panel" role="tabpanel">
              {this[this.tab](file)}
            </div>
          </div>
          <aside className="DepotFile-side">{this.side(file)}</aside>
        </div>
      </PageStructure>
    );
  }

  act(path, body) {
    return api.post(path, body).then(() => this.load());
  }

  tabCount(file, tab) {
    const n = { versions: file.versions.length, reviews: file.ratings, discussion: file.discussion && file.discussion.replies }[tab];
    return n ? <span className="DepotTabs-count">{compact(n)}</span> : null;
  }

  notFound() {
    return (
      <div className="container DepotEmpty">
        <Icon name="fas fa-box-open" />
        <h3>{t('file.missing_title')}</h3>
        <a className="Button" href={app.route('depot.index')}>
          {t('file.missing_back')}
        </a>
      </div>
    );
  }

  hero(file) {
    const v = file.version || {};
    return (
      <header className="DepotFileHero">
        <div className={`DepotFileHero-backdrop ${file.cover ? '' : 'Depot-cover--empty'}`} style={coverStyle(file, 'thumb')} />
        <div className="Page-container container DepotFileHero-inner">
          {Cover({ file, className: 'DepotFileHero-cover' })}
          <div className="DepotFileHero-text">
            <div className="DepotFileHero-labels">
              {CategoryLabels({ slug: file.category })}
              {SourceBadge({ file })}
              {file.featured ? (
                <span className="Depot-badge">
                  <Icon name="fas fa-star" /> {t('file.featured')}
                </span>
              ) : null}
            </div>
            <h1 className="DepotFileHero-title">{file.title}</h1>
            {file.tagline ? <p className="DepotFileHero-tagline">{file.tagline}</p> : null}
            <div className="DepotFileHero-meta">
              {UserChip({ user: file.user })}
              {v.version ? (
                <span>
                  v{v.version} · {t('file.updated', { time: humanTime(v.date) })}
                </span>
              ) : null}
              {file.ratings ? (
                <span className="DepotFileHero-rating">
                  {Stars({ value: file.rating })} {file.rating.toFixed(1)} <span className="Depot-dim">({number(file.ratings)})</span>
                </span>
              ) : null}
            </div>
          </div>
          {file.canEdit ? <div className="DepotFileHero-controls">{this.controls(file)}</div> : null}
        </div>
      </header>
    );
  }

  controls(file) {
    return (
      <Dropdown
        buttonClassName="Button"
        icon="fas fa-ellipsis-h"
        label={t('file.controls')}
        accessibleToggleLabel={t('file.controls')}
        menuClassName="Dropdown-menu--right"
      >
        <Button icon="fas fa-plus" onclick={() => app.modal.show(VersionModal, { file, onsave: () => this.load() })}>
          {t('file.new_version')}
        </Button>
        <Button
          icon="fas fa-pen"
          onclick={() =>
            app.modal.show(EditFileModal, {
              file,
              onsave: (slug) => (slug !== file.slug ? m.route.set(app.route('depot.file', { slug })) : this.load()),
            })
          }
        >
          {t('file.edit')}
        </Button>
        {file.canModerate ? (
          <Button icon={file.featured ? 'far fa-star' : 'fas fa-star'} onclick={() => this.act(`files/${file.id}/feature`)}>
            {file.featured ? t('file.unfeature') : t('file.feature')}
          </Button>
        ) : null}
        <Button icon="far fa-trash-alt" onclick={() => this.remove(file)}>
          {t('file.delete')}
        </Button>
      </Dropdown>
    );
  }

  remove(file) {
    // The same confirm Flarum itself uses before deleting a discussion.
    if (!confirm(t('file.delete_confirm', { title: file.title }).toString())) return;
    api.del(`files/${file.id}`).then(() => m.route.set(app.route('depot.index')));
  }

  // ---------------------------------------------------------------- overview

  overview(file) {
    const v = file.versions[0];
    const shots = file.screenshots;
    return (
      <div className="DepotOverview">
        {shots.length ? (
          <div className={`DepotGallery DepotGallery--${Math.min(shots.length, 5)}`}>
            {shots.slice(0, 5).map((shot, i) => (
              <button
                type="button"
                className="DepotGallery-item"
                style={{ backgroundImage: `url(${i === 0 ? shot.full : shot.thumb})` }}
                aria-label={t('file.screenshot', { n: i + 1 })}
                onclick={() => app.modal.show(LightboxModal, { shots, index: i, title: file.title })}
              >
                {i === 4 && shots.length > 5 ? <span>+{shots.length - 5}</span> : null}
              </button>
            ))}
          </div>
        ) : null}

        {file.description ? (
          <div className="DepotProse">
            {file.description.split(/(?:\r?\n){2,}/).map((p) => (
              <p>{p}</p>
            ))}
          </div>
        ) : null}

        {file.compat.length || file.game || file.fields.length || file.licence ? (
          <section className="DepotFacts">
            <h3>{t('file.at_a_glance')}</h3>
            <dl>
              {file.compat.length || file.game
                ? [
                    <dt>{t('file.works_with')}</dt>,
                    <dd>
                      {file.game ? <span className="Depot-dim">{file.game}</span> : null}
                      <span className="DepotFacts-chips">
                        {file.compat.map((c, i) => (
                          <span className={`Depot-chip Depot-chip--static ${i === 0 ? 'is-active' : ''}`}>{c}</span>
                        ))}
                      </span>
                    </dd>,
                  ]
                : null}
              {file.fields.map(([k, val]) => [<dt>{k}</dt>, <dd>{val}</dd>])}
              {file.licence ? [<dt>{t('file.licence')}</dt>, <dd>{file.licence}</dd>] : null}
            </dl>
          </section>
        ) : null}

        {file.requires.length ? (
          <section className="DepotRequires">
            <h3>{t('file.requires')}</h3>
            {file.requires.map((r) => (
              <div className="DepotRequires-item">
                <Icon name={r.optional ? 'fas fa-puzzle-piece' : 'fas fa-link'} />
                <div>
                  <b>{r.name}</b>
                  {r.note ? <span className="Depot-dim">{r.note}</span> : null}
                </div>
                <span className={`Depot-pill ${r.optional ? '' : 'Depot-pill--warn'}`}>{r.optional ? t('file.optional') : t('file.required')}</span>
              </div>
            ))}
          </section>
        ) : null}

        {file.install.length ? (
          <section className="DepotInstall">
            <h3>{t('file.install')}</h3>
            <ol className="Depot-steps">
              {file.install.map((s) => (
                <li>{s}</li>
              ))}
            </ol>
          </section>
        ) : null}

        {v && this.hasChanges(v) ? (
          <section className="DepotLatest">
            <div className="DepotLatest-head">
              <h3>{t('file.whats_new', { version: v.version })}</h3>
              <button type="button" className="Button Button--link" onclick={() => (this.tab = 'versions')}>
                {t('file.all_versions')} <Icon name="fas fa-arrow-right" />
              </button>
            </div>
            {this.changes(v)}
          </section>
        ) : null}

        {this.more(file)}
      </div>
    );
  }

  hasChanges(v) {
    return KINDS.some(([k]) => v.changelog[k].length);
  }

  more(file) {
    if (!file.related.length) return null;
    return (
      <section className="DepotMore">
        <h3>{t('file.more_like_this')}</h3>
        <div className="DepotMore-grid">
          {file.related.map((f) => (
            <a className="DepotMore-item" href={app.route('depot.file', { slug: f.slug })}>
              {Cover({ file: f, className: 'DepotMore-art' })}
              <span>
                <b>{f.title}</b>
                <span className="Depot-dim">
                  <Icon name="fas fa-download" /> {compact(f.downloads)}
                  {f.ratings ? [' · ', f.rating.toFixed(1), ' ', <Icon name="fas fa-star" />] : null}
                </span>
              </span>
            </a>
          ))}
        </div>
      </section>
    );
  }

  changes(v) {
    if (!this.hasChanges(v)) return <p className="Depot-dim">{t('file.no_changelog')}</p>;
    return (
      <div className="DepotChanges">
        {KINDS.filter(([k]) => v.changelog[k].length).map(([k, icon]) => (
          <div className={`DepotChanges-group DepotChanges-group--${k}`}>
            <span className="DepotChanges-kind">
              <Icon name={icon} /> {t(`file.change_${k}`)}
            </span>
            <ul>
              {v.changelog[k].map((c) => (
                <li>{c}</li>
              ))}
            </ul>
          </div>
        ))}
      </div>
    );
  }

  // ---------------------------------------------------------------- versions

  versions(file) {
    return (
      <ol className="DepotTimeline">
        {file.versions.map((v, i) => (
          <li className={`DepotTimeline-item ${i === 0 ? 'is-latest' : ''} ${this.open[i] ? 'is-open' : ''}`}>
            <span className="DepotTimeline-dot" />
            <div className="DepotTimeline-card">
              <button
                type="button"
                className="DepotTimeline-head"
                aria-expanded={this.open[i] ? 'true' : 'false'}
                onclick={() => (this.open[i] = !this.open[i])}
              >
                <span className="DepotTimeline-version">v{v.version}</span>
                {i === 0 ? <span className="Depot-pill Depot-pill--fresh">{t('file.latest')}</span> : null}
                <span className="Depot-dim">
                  {shortDate(v.date)} · {v.external ? v.host || t('file.external') : bytes(v.size)} ·{' '}
                  {t('file.version_downloads', { count: number(v.downloads) })}
                </span>
                <Icon name={this.open[i] ? 'fas fa-chevron-up' : 'fas fa-chevron-down'} className="DepotTimeline-caret" />
              </button>
              {this.open[i] ? (
                <div className="DepotTimeline-body">
                  {this.changes(v)}
                  <div className="DepotTimeline-foot">
                    {v.sha256 ? (
                      <code className="DepotTimeline-sha" title="SHA-256">
                        <Icon name="fas fa-shield-alt" /> {v.sha256.slice(0, 16)}…
                      </code>
                    ) : (
                      <span />
                    )}
                    <span className="DepotTimeline-actions">
                      {file.canEdit && file.versions.length > 1 ? (
                        <Button
                          className="Button Button--icon Button--link"
                          icon="far fa-trash-alt"
                          aria-label={t('file.delete_version')}
                          onclick={() => this.removeVersion(v)}
                        />
                      ) : null}
                      {file.canDownload ? (
                        <Button
                          className={`Button ${i === 0 ? 'Button--primary' : ''}`}
                          icon="fas fa-download"
                          onclick={() => this.download(file, v)}
                        >
                          {t('file.download_version', { version: v.version })}
                        </Button>
                      ) : null}
                    </span>
                  </div>
                </div>
              ) : null}
            </div>
          </li>
        ))}
      </ol>
    );
  }

  removeVersion(v) {
    if (!confirm(t('file.delete_version_confirm', { version: v.version }).toString())) return;
    api.del(`versions/${v.id}`).then(() => this.load());
  }

  download(file, v) {
    startDownload(v);
    app.modal.show(DownloadModal, { file, version: v, onwatch: (r) => Object.assign(file, r) });
  }

  // ---------------------------------------------------------------- reviews

  reviews(file) {
    const total = file.distribution.reduce((a, b) => a + b, 0) || 1;
    const shown = file.reviews.filter((r) => !this.starFilter || r.rating === this.starFilter);
    const write = () => app.modal.show(ReviewModal, { file, review: file.myReview, onsave: () => this.load() });

    return (
      <div className="DepotReviews">
        <div className="DepotReviews-summary">
          <div className="DepotReviews-score">
            <b>{file.ratings ? file.rating.toFixed(1) : '–'}</b>
            {Stars({ value: file.rating, size: 'Depot-stars--large' })}
            <span className="Depot-dim">{t('file.ratings', { count: number(file.ratings) })}</span>
          </div>
          <div className="DepotReviews-bars">
            {file.distribution.map((n, i) => {
              const star = 5 - i;
              return (
                <button
                  type="button"
                  className={`DepotReviews-bar ${this.starFilter === star ? 'is-active' : ''}`}
                  onclick={() => (this.starFilter = this.starFilter === star ? 0 : star)}
                  aria-pressed={this.starFilter === star ? 'true' : 'false'}
                >
                  <span>
                    {star} <Icon name="fas fa-star" />
                  </span>
                  <span className="DepotReviews-track">
                    <i style={{ width: `${(n / total) * 100}%` }} />
                  </span>
                  <span className="Depot-dim">{n}</span>
                </button>
              );
            })}
          </div>
          {file.canReview ? (
            <Button className="Button Button--primary" icon={file.myReview ? 'fas fa-pen' : 'far fa-star'} onclick={write}>
              {file.myReview ? t('file.edit_review') : t('file.write_review')}
            </Button>
          ) : !app.session.user ? (
            <Button className="Button" onclick={() => app.modal.show(() => import('flarum/forum/components/LogInModal'))}>
              {t('file.login_to_review')}
            </Button>
          ) : (
            <span />
          )}
        </div>

        {shown.length ? (
          shown.map((r) => this.reviewItem(file, r))
        ) : (
          <p className="Depot-dim DepotReviews-none">{file.reviews.length ? t('file.no_reviews_filtered') : t('file.no_reviews')}</p>
        )}
      </div>
    );
  }

  reviewItem(file, r) {
    const mine = app.session.user && r.user && String(r.user.id) === app.session.user.id();
    return (
      <article className="DepotReview">
        {Avatar({ user: r.user })}
        <div className="DepotReview-body">
          <header>
            <b>{r.user ? r.user.displayName : '?'}</b>
            {Stars({ value: r.rating })}
            <span className="Depot-dim">
              {humanTime(r.date)}
              {r.version ? [' · ', t('file.reviewed_version', { version: r.version })] : null}
            </span>
          </header>
          {r.body ? <p>{r.body}</p> : null}
          {this.replying === r.id ? (
            this.replyEditor(r)
          ) : r.reply ? (
            <div className="DepotReview-reply">
              {Avatar({ user: file.user, className: 'Avatar--mini' })}
              <div>
                <b>{file.user ? file.user.displayName : ''}</b> <span className="Depot-pill">{t('file.author')}</span>
                <p>{r.reply}</p>
              </div>
            </div>
          ) : null}
          <div className="DepotReview-actions">
            {app.session.user && !mine ? (
              <button
                type="button"
                className={`DepotReview-helpful ${r.helpfulByMe ? 'is-active' : ''}`}
                aria-pressed={r.helpfulByMe ? 'true' : 'false'}
                onclick={() => this.helpful(r)}
              >
                <Icon name={r.helpfulByMe ? 'fas fa-thumbs-up' : 'far fa-thumbs-up'} /> {t('file.helpful', { count: r.helpful })}
              </button>
            ) : r.helpful ? (
              <span className="Depot-dim">{t('file.helpful', { count: r.helpful })}</span>
            ) : null}
            {file.canEdit && !mine && this.replying !== r.id ? (
              <button
                type="button"
                className="Button Button--link Button--small"
                onclick={() => ((this.replying = r.id), (this.replyText = r.reply || ''))}
              >
                {r.reply ? t('file.edit_reply') : t('file.reply')}
              </button>
            ) : null}
            {mine || file.canModerate ? (
              <button type="button" className="Button Button--link Button--small" onclick={() => this.removeReview(r)}>
                {t('file.delete_review')}
              </button>
            ) : null}
          </div>
        </div>
      </article>
    );
  }

  replyEditor(r) {
    const save = () =>
      api.post(`reviews/${r.id}/reply`, { reply: this.replyText }).then((res) => {
        r.reply = res.reply;
        this.replying = null;
        m.redraw();
      });
    return (
      <div className="DepotReview-replyEditor">
        <textarea
          className="FormControl"
          rows="3"
          aria-label={t('file.reply')}
          placeholder={t('file.reply_placeholder').toString()}
          value={this.replyText}
          oninput={(e) => (this.replyText = e.target.value)}
          oncreate={(vnode) => vnode.dom.focus()}
        />
        <div>
          <Button className="Button Button--primary Button--small" onclick={save}>
            {t('file.reply_save')}
          </Button>
          <Button className="Button Button--link Button--small" onclick={() => (this.replying = null)}>
            {t('file.cancel')}
          </Button>
        </div>
      </div>
    );
  }

  helpful(r) {
    api.post(`reviews/${r.id}/helpful`, { helpful: !r.helpfulByMe }).then((res) => {
      Object.assign(r, res);
      m.redraw();
    });
  }

  removeReview(r) {
    if (!confirm(t('file.delete_review_confirm').toString())) return;
    api.del(`reviews/${r.id}`).then(() => this.load());
  }

  // ---------------------------------------------------------------- discussion

  discussion(file) {
    const d = file.discussion;
    if (!d) return <p className="Depot-dim DepotReviews-none">{file.approved ? t('file.no_thread') : t('file.thread_after_approval')}</p>;
    if (!this.posts) this.loadPosts(d);

    return (
      <div className="DepotDiscussion">
        <div className="DepotThread">
          <Icon name="far fa-comments" />
          <div>
            <b>{d.title}</b>
            <span className="Depot-dim">
              {t('file.thread_meta', { count: d.replies })}
              {d.tag
                ? [
                    ' · ',
                    <span className="Depot-label" style={{ '--label-color': d.tag.color || 'var(--control-color)' }}>
                      {d.tag.name}
                    </span>,
                  ]
                : null}
            </span>
          </div>
          <a className="Button" href={d.url}>
            {t('file.thread_open')}
          </a>
        </div>
        {this.posts === 'loading' ? <LoadingIndicator /> : null}
        {Array.isArray(this.posts)
          ? this.posts.map((p) => {
              const user = p.user();
              return (
                <article className="DepotPost">
                  <header>
                    {user ? <img className="Avatar Avatar--mini" src={user.avatarUrl() || ''} hidden={!user.avatarUrl()} alt="" /> : null}
                    <b>{user ? user.displayName() : ''}</b>
                    <span className="Depot-dim">{humanTime(p.createdAt())}</span>
                  </header>
                  {/* Flarum's own rendered post, exactly as the discussion shows it. */}
                  <div className="Post-body">{m.trust(p.contentHtml())}</div>
                </article>
              );
            })
          : null}
        <a className="DepotReply" href={d.url}>
          <Icon name="fas fa-reply" />
          <span>{t('file.reply_in_thread')}</span>
        </a>
      </div>
    );
  }

  /** The latest few posts through Flarum's own API, so permissions, hidden posts and formatting are core's. */
  loadPosts(d) {
    this.posts = 'loading';
    app.store.find('posts', { filter: { discussion: String(d.id), type: 'comment' }, sort: '-createdAt', page: { limit: 4 } }).then(
      (posts) => {
        this.posts = [...posts].reverse();
        m.redraw();
      },
      () => (this.posts = [])
    );
  }

  // ---------------------------------------------------------------- sidebar

  side(file) {
    const v = file.versions[0];
    const total30 = file.trend.reduce((a, b) => a + b, 0);
    const u = file.user;

    return [
      v ? (
        <div className="DepotSideCard DepotSideCard--download">
          {file.canDownload ? (
            <SplitDropdown
              className="DepotDownloadSplit"
              buttonClassName="Button Button--primary Button--large"
              icon="fas fa-download"
              accessibleToggleLabel={t('file.older_versions')}
            >
              <Button icon="fas fa-download" onclick={() => this.download(file, v)}>
                {t('file.download', { version: v.version })}
              </Button>
              {file.versions.slice(1, 8).map((old) => (
                <Button icon="fas fa-history" onclick={() => this.download(file, old)}>
                  {t('file.download_old', { version: old.version, time: humanTime(old.date) })}
                </Button>
              ))}
            </SplitDropdown>
          ) : (
            <Button className="Button Button--large" disabled>
              {app.session.user ? t('file.no_download_permission') : t('file.login_to_download')}
            </Button>
          )}
          <div className="DepotSideCard-sub">
            {[
              v.external ? v.host : v.filename && v.filename.includes('.') ? v.filename.split('.').pop().toUpperCase() : null,
              v.size ? bytes(v.size) : null,
              t('file.released', { time: humanTime(v.date) }),
            ]
              .filter(Boolean)
              .reduce((out, part, i) => (i ? [...out, ' · ', part] : [part]), [])}
          </div>
          {app.session.user ? (
            <Button
              className={`Button DepotWatch ${file.watching ? 'is-active' : ''}`}
              icon={file.watching ? 'fas fa-bell' : 'far fa-bell'}
              onclick={() => this.watch(file)}
            >
              {file.watching ? t('file.watching') : t('file.watch')}
            </Button>
          ) : null}
          <div className="DepotSideCard-trust">
            {v.sha256 ? (
              <Tooltip text={t('file.verified_tooltip')}>
                <span>
                  <Icon name="fas fa-shield-alt" /> {t('file.verified')}
                </span>
              </Tooltip>
            ) : (
              <span />
            )}
            <span>
              <Icon name="far fa-eye" /> {t('file.watchers', { count: number(file.watchers) })}
            </span>
          </div>
        </div>
      ) : null,

      <div className="DepotSideCard">
        <div className="DepotSideCard-row">
          <div>
            <b className="DepotSideCard-big">{number(file.downloads)}</b>
            <span className="Depot-dim">{t('file.total_downloads')}</span>
          </div>
          <div className="DepotSideCard-trend">
            {Sparkline({ values: file.trend, width: 120, height: 36 })}
            <span className="Depot-dim">{t('file.last_30', { count: number(total30) })}</span>
          </div>
        </div>
        <dl className="DepotStats">
          {v ? [<dt>{t('file.this_version')}</dt>, <dd>{number(v.downloads)}</dd>] : null}
          <dt>{t('file.first_released')}</dt>
          <dd>{shortDate(file.created)}</dd>
          <dt>{t('file.versions')}</dt>
          <dd>{file.versions.length}</dd>
        </dl>
      </div>,

      v && v.sha256 ? (
        <div className="DepotSideCard">
          <h4 className="DepotSideCard-label">SHA-256</h4>
          <div className="Depot-copy Depot-copy--small">
            <code>{v.sha256}</code>
            <Button
              className="Button Button--icon"
              icon={this.copied ? 'fas fa-check' : 'far fa-copy'}
              aria-label={t('download.copy')}
              onclick={() => this.copy(v.sha256)}
            />
          </div>
        </div>
      ) : null,

      file.source === 'github' ? (
        <div className="DepotSideCard DepotSideCard--source">
          <Icon name="fab fa-github" />
          <div>
            <a href={`https://github.com/${file.repo}`} target="_blank" rel="noopener noreferrer">
              <b>{file.repo}</b>
            </a>
            <span className="Depot-dim">{t('file.github_sync')}</span>
          </div>
        </div>
      ) : null,

      u ? (
        <div className="DepotSideCard DepotSideCard--author">
          {Avatar({ user: u, className: 'Avatar--large' })}
          <div>
            <b>{u.displayName}</b>
            {file.author ? (
              <span className="Depot-dim">{t('file.author_stats', { files: file.author.files, downloads: compact(file.author.downloads) })}</span>
            ) : null}
          </div>
          <a className="Button Button--small" href={app.route('user', { username: u.slug })}>
            {t('file.view_profile')}
          </a>
        </div>
      ) : null,

      <details className="DepotSideCard DepotApi">
        <summary>
          <Icon name="fas fa-code" /> {t('file.api')}
        </summary>
        <p className="Depot-dim">{t('file.api_help')}</p>
        <pre>{`GET ${file.updateUrl}`}</pre>
      </details>,
    ];
  }

  watch(file) {
    api.post(`files/${file.id}/watch`, { watch: !file.watching }).then((r) => {
      file.watching = r.watching;
      file.watchers = r.watchers;
      m.redraw();
    });
  }

  copy(text) {
    if (!navigator.clipboard) return;
    navigator.clipboard.writeText(text).then(
      () => {
        this.copied = true;
        m.redraw();
        setTimeout(() => ((this.copied = false), m.redraw()), 1800);
      },
      () => {}
    );
  }
}
