import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import extractText from 'flarum/common/utils/extractText';
import { api, state, category, rootOf, bytes } from '../depot-api';
import { t } from './bits';

const STEPS = ['source', 'details', 'media', 'support', 'review'];
const SOURCES = [
  ['upload', 'fas fa-file-upload'],
  ['link', 'fas fa-link'],
  ['github', 'fab fa-github'],
];

/**
 * Upload a file, in five short steps instead of one long form.
 *
 * A dropped file is hashed in the browser while the member fills in the
 * details, so the checksum is on screen before anything is sent; the server
 * works it out again from the bytes it receives, and that is the one kept.
 */
export default class UploadModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);
    this.step = 0;
    this.origin = 'upload';
    this.file = null;
    this.hash = null;
    this.hashing = false;
    this.shots = [];
    this.fileTitle = '';
    this.version = '1.0.0';
    this.category = (this.leaves()[0] || {}).slug || '';
    this.tagline = '';
    this.description = '';
    this.game = '';
    this.compat = '';
    this.licence = '';
    this.install = '';
    this.requires = '';
    this.link = '';
    this.repo = '';
    this.dragging = false;
    this.progress = 0;
    this.thread = { on: true, tag: null, opening: '' };
  }

  className() {
    return 'DepotUploadModal Modal--medium';
  }

  title() {
    return t('upload.title');
  }

  onremove(vnode) {
    super.onremove(vnode);
    this.shots.forEach((s) => URL.revokeObjectURL(s.url));
  }

  /** Categories a file can go in: open ones without subcategories of their own. */
  leaves() {
    return state.categories.filter((c) => !c.locked && !state.categories.some((s) => s.parent === c.slug));
  }

  steps() {
    // When the forum has switched support discussions off, there is nothing to ask.
    return app.forum.attribute('depotSupportThread') === false ? STEPS.filter((s) => s !== 'support') : STEPS;
  }

  content() {
    const steps = this.steps();
    return (
      <div className="Modal-body">
        <ol className="DepotSteps">
          {steps.map((s, i) => (
            <li className={i === this.step ? 'is-current' : i < this.step ? 'is-done' : ''}>
              <button type="button" disabled={i > this.step || this.loading} onclick={() => (this.step = i)}>
                <span className="DepotSteps-dot">{i < this.step ? <Icon name="fas fa-check" /> : i + 1}</span>
                <span className="DepotSteps-label">{t(`upload.step_${s}`)}</span>
              </button>
            </li>
          ))}
        </ol>

        <div className="DepotUpload-panel">{this[steps[this.step]]()}</div>

        <div className="DepotUpload-foot">
          {this.step ? (
            <Button className="Button" disabled={this.loading} onclick={() => this.step--}>
              {t('upload.back')}
            </Button>
          ) : (
            <span />
          )}
          {this.step < steps.length - 1 ? (
            <Button className="Button Button--primary" disabled={!this.ready()} onclick={() => this.step++}>
              {t('upload.next')}
            </Button>
          ) : (
            <Button className="Button Button--primary" type="submit" icon="fas fa-paper-plane" loading={this.loading}>
              {this.loading && this.progress ? t('upload.sending', { percent: Math.round(this.progress * 100) }) : t('upload.submit')}
            </Button>
          )}
        </div>
      </div>
    );
  }

  ready() {
    const step = this.steps()[this.step];
    if (step === 'source') {
      if (this.origin === 'upload') return this.file && this.hash && !this.tooLarge();
      if (this.origin === 'link') return /^https:\/\/\S+\.\S+/.test(this.link);
      return /^[\w.-]+\/[\w.-]+$/.test(this.repo);
    }
    if (step === 'details') return this.fileTitle.trim() && (this.origin === 'github' || this.version.trim()) && this.category;
    if (step === 'support') return !this.thread.on || !this.tags().length || this.thread.tag;
    return true;
  }

  maxBytes() {
    return (app.forum.attribute('depotMaxUploadMb') || 250) * 1048576;
  }

  tooLarge() {
    return this.file && this.file.size > this.maxBytes();
  }

  source() {
    return (
      <div className="DepotUpload-source">
        <div className="DepotChoice" role="radiogroup">
          {SOURCES.map(([key, icon]) => (
            <button
              type="button"
              role="radio"
              aria-checked={this.origin === key ? 'true' : 'false'}
              className={this.origin === key ? 'is-active' : ''}
              onclick={() => (this.origin = key)}
            >
              <Icon name={icon} />
              <b>{t(`upload.source_${key}`)}</b>
              <span>{t(`upload.source_${key}_help`)}</span>
            </button>
          ))}
        </div>

        {this.origin === 'upload' ? this.dropzone() : null}
        {this.origin === 'link'
          ? this.field(
              'depot-link',
              t('upload.link_label'),
              this.link,
              (v) => (this.link = v.trim()),
              { placeholder: 'https://…' },
              t('upload.link_help')
            )
          : null}
        {this.origin === 'github' ? (
          <div className="Form-group">
            <label for="depot-repo">{t('upload.repo_label')}</label>
            <div className="Depot-inputPrefix">
              <span>github.com/</span>
              <input
                id="depot-repo"
                className="FormControl"
                placeholder="owner/repository"
                value={this.repo}
                oninput={(e) => (this.repo = e.target.value.trim())}
              />
            </div>
            <p className="helpText">{t('upload.repo_help')}</p>
          </div>
        ) : null}
      </div>
    );
  }

  dropzone() {
    const pick = (list) => list && list[0] && this.take(list[0]);
    const mb = this.maxBytes() / 1048576;
    return (
      <label
        className={`DepotDrop ${this.dragging ? 'is-over' : ''} ${this.file ? 'has-file' : ''}`}
        ondragover={(e) => (e.preventDefault(), (this.dragging = true))}
        ondragleave={() => (this.dragging = false)}
        ondrop={(e) => (e.preventDefault(), (this.dragging = false), pick(e.dataTransfer.files))}
      >
        <input type="file" className="sr-only" onchange={(e) => pick(e.target.files)} />
        {this.file ? (
          <div className="DepotDrop-file">
            <Icon name="fas fa-file-archive" />
            <div>
              <b>{this.file.name}</b>
              <span className="Depot-dim">{bytes(this.file.size)}</span>
              {this.tooLarge() ? (
                <span className="DepotDrop-hash is-error">{t('upload.too_large', { mb })}</span>
              ) : this.hashing ? (
                <span className="DepotDrop-hash is-working">
                  <Icon name="fas fa-circle-notch fa-spin" /> {t('upload.hashing')}
                </span>
              ) : this.hash && this.hash !== 'server' ? (
                <span className="DepotDrop-hash">
                  <Icon name="fas fa-shield-alt" /> SHA-256 <code>{this.hash}</code>
                </span>
              ) : null}
            </div>
            <span className="Button Button--small">{t('upload.replace')}</span>
          </div>
        ) : (
          <div className="DepotDrop-empty">
            <Icon name="fas fa-cloud-upload-alt" />
            <b>{t('upload.drop')}</b>
            <span className="Depot-dim">{t('upload.drop_help', { mb })}</span>
          </div>
        )}
      </label>
    );
  }

  async take(file) {
    this.file = file;
    this.hash = null;
    if (!this.fileTitle)
      this.fileTitle = file.name
        .replace(/\.[^.]+$/, '')
        .replace(/[-_]+/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
    if (this.tooLarge()) return m.redraw();

    this.hashing = true;
    m.redraw();
    try {
      const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
      this.hash = Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, '0')).join('');
    } catch (e) {
      // A very large file can be more than a browser will hash in memory;
      // the server works the checksum out on arrival either way.
      this.hash = 'server';
    }
    this.hashing = false;
    m.redraw();
  }

  field(id, label, value, set, attrs = {}, help) {
    const control = attrs.rows ? (
      <textarea id={id} className="FormControl" value={value} oninput={(e) => set(e.target.value)} {...attrs} />
    ) : (
      <input id={id} className="FormControl" value={value} oninput={(e) => set(e.target.value)} {...attrs} />
    );
    return (
      <div className="Form-group">
        <label for={id}>{label}</label>
        {control}
        {help ? <p className="helpText">{help}</p> : null}
      </div>
    );
  }

  details() {
    return (
      <div className="DepotUpload-details">
        {this.field('depot-title', t('upload.name_label'), this.fileTitle, (v) => (this.fileTitle = v), { maxlength: 120 })}
        <div className="Depot-row2">
          <div className="Form-group">
            <label for="depot-cat">{t('upload.category_label')}</label>
            <select id="depot-cat" className="FormControl" value={this.category} onchange={(e) => (this.category = e.target.value)}>
              {this.leaves().map((c) => (
                <option value={c.slug}>{c.parent ? `${rootOf(c).name} › ${c.name}` : c.name}</option>
              ))}
            </select>
          </div>
          {this.origin !== 'github' ? (
            this.field('depot-version', t('upload.version_label'), this.version, (v) => (this.version = v), { maxlength: 40 })
          ) : (
            <span />
          )}
        </div>
        {this.field(
          'depot-tagline',
          t('upload.tagline_label'),
          this.tagline,
          (v) => (this.tagline = v),
          { maxlength: 160, placeholder: extractText(t('upload.tagline_placeholder')) },
          t('upload.tagline_help', { left: 160 - this.tagline.length })
        )}
        {this.field('depot-description', t('upload.description_label'), this.description, (v) => (this.description = v), { rows: 4 })}
        <div className="Depot-row2">
          {this.field('depot-game', t('upload.game_label'), this.game, (v) => (this.game = v), {
            maxlength: 60,
            placeholder: extractText(t('upload.game_placeholder')),
          })}
          {this.field(
            'depot-compat',
            t('upload.compat_label'),
            this.compat,
            (v) => (this.compat = v),
            { placeholder: '1.21.4, 1.21.3' },
            t('upload.compat_help')
          )}
        </div>
        {this.field('depot-licence', t('upload.licence_label'), this.licence, (v) => (this.licence = v), {
          maxlength: 60,
          placeholder: 'MIT, CC BY 4.0…',
        })}
        {this.field('depot-install', t('upload.install_label'), this.install, (v) => (this.install = v), { rows: 3 }, t('upload.lines_help'))}
        {this.field(
          'depot-requires',
          t('upload.requires_label'),
          this.requires,
          (v) => (this.requires = v),
          { rows: 2, placeholder: extractText(t('upload.requires_placeholder')) },
          t('upload.requires_help')
        )}
      </div>
    );
  }

  media() {
    const add = (list) => {
      Array.from(list || [])
        .filter((f) => f.type.startsWith('image/'))
        .slice(0, 8 - this.shots.length)
        .forEach((f) => this.shots.push({ file: f, url: URL.createObjectURL(f) }));
    };
    return (
      <div className="DepotUpload-media">
        <div className="DepotShots">
          {this.shots.map((s, i) => (
            <div className="DepotShots-item" style={{ backgroundImage: `url(${s.url})` }}>
              {i === 0 ? <span className="Depot-pill">{t('upload.cover')}</span> : null}
              <button type="button" aria-label={t('upload.remove')} onclick={() => (URL.revokeObjectURL(s.url), this.shots.splice(i, 1))}>
                <Icon name="fas fa-times" />
              </button>
            </div>
          ))}
          {this.shots.length < 8 ? (
            <label className="DepotShots-add" ondragover={(e) => e.preventDefault()} ondrop={(e) => (e.preventDefault(), add(e.dataTransfer.files))}>
              <input type="file" accept="image/*" multiple className="sr-only" onchange={(e) => add(e.target.files)} />
              <Icon name="far fa-images" />
              <span>{t('upload.add_shots')}</span>
            </label>
          ) : null}
        </div>
        <p className="helpText">{t('upload.shots_help')}</p>
      </div>
    );
  }

  /** The forum's primary tags the member may start a discussion in, from the boot payload. */
  tags() {
    return (app.store.all('tags') || [])
      .filter((tag) => tag.isPrimary() && !tag.parent() && tag.canStartDiscussion())
      .map((tag) => ({
        id: tag.id(),
        name: tag.name(),
        color: tag.color() || 'var(--control-color)',
        icon: tag.icon(),
        description: tag.description(),
      }));
  }

  support() {
    const tags = this.tags();
    const th = this.thread;
    if (th.tag === null && tags.length) th.tag = (tags.find((x) => /support|help/i.test(x.name)) || tags[0]).id;
    const chosen = tags.find((x) => x.id === th.tag);
    const user = app.session.user;

    return (
      <div className="DepotUpload-support">
        <label className="DepotToggle">
          <input type="checkbox" checked={th.on} onchange={(e) => (th.on = e.target.checked)} />
          <span className="DepotToggle-track" />
          <span>
            <b>{t('upload.thread_toggle')}</b>
            <span className="Depot-dim">{t('upload.thread_toggle_help')}</span>
          </span>
        </label>

        {th.on ? (
          <>
            <div className="Form-group">
              <label>{t('upload.thread_tag')}</label>
              {tags.length ? (
                <div className="DepotTagPick" role="radiogroup">
                  {tags.map((tag) => (
                    <button
                      type="button"
                      role="radio"
                      aria-checked={th.tag === tag.id ? 'true' : 'false'}
                      className={th.tag === tag.id ? 'is-active' : ''}
                      style={{ '--label-color': tag.color }}
                      onclick={() => (th.tag = tag.id)}
                    >
                      <span className="DepotTagPick-icon">{tag.icon ? <Icon name={tag.icon} /> : null}</span>
                      <span>
                        <b>{tag.name}</b>
                        {tag.description ? <span className="Depot-dim">{tag.description}</span> : null}
                      </span>
                    </button>
                  ))}
                </div>
              ) : (
                <p className="helpText">{t('upload.thread_no_tags')}</p>
              )}
            </div>

            {this.field(
              'depot-opening',
              t('upload.thread_opening'),
              th.opening,
              (v) => (th.opening = v),
              { rows: 3, placeholder: extractText(t('upload.thread_opening_placeholder')) },
              t('upload.thread_opening_help')
            )}

            <div className="DepotThreadPreview">
              <span className="Depot-dim">{t('upload.thread_preview')}</span>
              <div className="DepotThreadPreview-item">
                <span className="Avatar Avatar--mini" style={{ '--avatar-bg': user ? user.color() : 'var(--control-bg)' }}>
                  {user ? user.displayName().charAt(0).toUpperCase() : '?'}
                </span>
                <div>
                  <b>{t('upload.thread_title', { title: this.fileTitle || '…' })}</b>
                  <div className="DepotThreadPreview-meta">
                    {chosen ? (
                      <span className="Depot-label" style={{ '--label-color': chosen.color }}>
                        {chosen.name}
                      </span>
                    ) : null}
                    <span className="Depot-badge">
                      <Icon name="fas fa-box-open" /> {t('upload.thread_linked')}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </>
        ) : (
          <div className="DepotNotice DepotNotice--info">
            <Icon name="fas fa-info-circle" />
            <span>{t('upload.thread_off')}</span>
          </div>
        )}
      </div>
    );
  }

  review() {
    const c = category(this.category);
    const root = rootOf(c) || {};
    const where = c ? (c.parent ? `${root.name} › ${c.name}` : c.name) : '';
    const tag = this.tags().find((x) => x.id === this.thread.tag);
    return (
      <div className="DepotUpload-review">
        <div className="DepotReviewCard">
          <div className="DepotReviewCard-art" style={this.shots[0] ? { backgroundImage: `url(${this.shots[0].url})` } : {}}>
            {!this.shots[0] ? <Icon name="far fa-image" /> : null}
          </div>
          <div>
            {c ? (
              <span className="Depot-label" style={{ '--label-color': root.color }}>
                {where}
              </span>
            ) : null}
            <h3>
              {this.fileTitle} {this.origin !== 'github' ? <span className="Depot-dim">v{this.version}</span> : null}
            </h3>
            <p className="Depot-dim">{this.tagline || t('upload.no_tagline')}</p>
            <p className="Depot-dim">
              {this.origin === 'upload' && this.file
                ? `${this.file.name} · ${bytes(this.file.size)}`
                : this.origin === 'link'
                  ? this.link
                  : `github.com/${this.repo}`}
            </p>
          </div>
        </div>
        {this.thread.on && this.steps().includes('support') ? (
          <div className="DepotNotice DepotNotice--info">
            <Icon name="far fa-comments" />
            <span>{tag ? t('upload.review_thread', { tag: tag.name }) : t('upload.review_thread_untagged')}</span>
          </div>
        ) : null}
        <div className="DepotNotice DepotNotice--info">
          <Icon name="fas fa-user-shield" />
          <span>{t('upload.approval', { category: where })}</span>
        </div>
      </div>
    );
  }

  onsubmit(e) {
    e.preventDefault();
    if (this.loading) return;

    const form = new FormData();
    const fields = {
      source: this.origin,
      title: this.fileTitle,
      category: this.category,
      version: this.version,
      tagline: this.tagline,
      description: this.description,
      game: this.game,
      compat: this.compat,
      licence: this.licence,
      install: this.install,
      requires: this.requires,
      link: this.link,
      repo: this.repo,
      thread: this.thread.on && this.steps().includes('support') ? '1' : '0',
      threadTag: this.thread.tag || '',
      threadOpening: this.thread.opening,
    };
    Object.entries(fields).forEach(([k, v]) => form.append(k, v));
    if (this.origin === 'upload' && this.file) form.append('file', this.file);
    this.shots.forEach((s) => form.append('screenshots[]', s.file));

    this.loading = true;
    api
      .upload('files', form, (p) => ((this.progress = p), m.redraw()))
      .then(
        (r) => {
          this.hide();
          app.alerts.show(
            { type: 'success' },
            r.approved ? t('upload.published', { title: this.fileTitle }) : t('upload.submitted', { title: this.fileTitle })
          );
          m.route.set(app.route('depot.file', { slug: r.slug }));
        },
        (error) => {
          this.loading = false;
          this.progress = 0;
          this.onerror(error);
        }
      );
  }
}
