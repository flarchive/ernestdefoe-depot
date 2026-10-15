import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import { compact, bytes } from '../depot-api';
import { t, Stars, CategoryLabels, coverStyle } from './bits';
import DownloadModal, { startDownload } from './DownloadModal';

/**
 * The front of Depot: the featured files, one at a time, as big as the page
 * allows. It moves on by itself every eight seconds, stops while the pointer
 * or keyboard focus is inside it, and never moves for people who have asked
 * their system to reduce motion.
 */
export default class Spotlight extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.index = 0;
    this.paused = false;
    this.still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    if (this.still) return;
    this.timer = setInterval(() => {
      if (this.paused || document.hidden) return;
      this.go(this.index + 1);
      m.redraw();
    }, 8000);
  }

  onremove(vnode) {
    super.onremove(vnode);
    clearInterval(this.timer);
  }

  /** The spotlight only has the card; the sheet needs the full file. */
  download(file) {
    startDownload(file.version);
    app.modal.show(DownloadModal, { file, version: file.version });
  }

  go(i) {
    const n = this.attrs.files.length;
    this.index = (i + n) % n;
  }

  view() {
    const files = this.attrs.files;
    const file = files[this.index];
    const v = file.version || {};

    return (
      <section
        className="DepotSpotlight"
        aria-roledescription="carousel"
        aria-label={t('spotlight.label')}
        onmouseenter={() => (this.paused = true)}
        onmouseleave={() => (this.paused = false)}
        onfocusin={() => (this.paused = true)}
        onfocusout={() => (this.paused = false)}
      >
        {files.map((f, i) => (
          <div
            className={`DepotSpotlight-art ${i === this.index ? 'is-active' : ''} ${f.cover ? '' : 'Depot-cover--empty'}`}
            style={coverStyle(f, 'full')}
            aria-hidden="true"
          />
        ))}
        <div className="DepotSpotlight-shade" />

        <div className="DepotSpotlight-content Page-container container">
          {[
            <div className="DepotSpotlight-swap" key={file.slug}>
              <div className="DepotSpotlight-text">
                <span className="DepotSpotlight-eyebrow">
                  <Icon name="fas fa-star" /> {t('spotlight.featured')}
                </span>
                <h1 className="DepotSpotlight-title">{file.title}</h1>
                <p className="DepotSpotlight-tagline">{file.tagline}</p>
                <div className="DepotSpotlight-facts">
                  {CategoryLabels({ slug: file.category, link: false })}
                  {v.version ? <span>v{v.version}</span> : null}
                  {file.game || file.compat.length ? <span>{[file.game, file.compat[0]].filter(Boolean).join(' ')}</span> : null}
                  {v.size ? <span>{bytes(v.size)}</span> : null}
                </div>
                <div className="DepotSpotlight-actions">
                  <Button className="Button Button--primary Button--large" icon="fas fa-download" onclick={() => this.download(file)}>
                    {t('spotlight.download', { version: v.version })}
                  </Button>
                  <a className="Button Button--large DepotSpotlight-ghost" href={app.route('depot.file', { slug: file.slug })}>
                    {t('spotlight.details')}
                  </a>
                </div>
              </div>

              <div className="DepotSpotlight-side">
                <div className="DepotSpotlight-stat">
                  <b>{compact(file.downloads)}</b>
                  <span>{t('spotlight.downloads')}</span>
                </div>
                {file.ratings ? (
                  <div className="DepotSpotlight-stat">
                    <b>{file.rating.toFixed(1)}</b>
                    {Stars({ value: file.rating })}
                  </div>
                ) : null}
              </div>
            </div>,
          ]}
        </div>

        <div className="DepotSpotlight-picker Page-container container">
          {files.map((f, i) => (
            <button
              type="button"
              className={`DepotSpotlight-thumb ${i === this.index ? 'is-active' : ''}`}
              style={coverStyle(f)}
              aria-label={f.title}
              aria-current={i === this.index ? 'true' : undefined}
              onclick={() => this.go(i)}
            >
              <span
                className="DepotSpotlight-progress"
                style={{ animationPlayState: this.paused || this.still ? 'paused' : 'running' }}
                key={`${this.index}-${i}`}
              />
            </button>
          ))}
        </div>
      </section>
    );
  }
}
