import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { api, bytes } from '../depot-api';
import { t, Cover } from './bits';

const OS = {
  mac: (f) => `shasum -a 256 "${f}"`,
  windows: (f) => `certutil -hashfile "${f}" SHA256`,
  linux: (f) => `sha256sum "${f}"`,
};

function guessOs() {
  const p = (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '';
  if (/win/i.test(p)) return 'windows';
  if (/linux|x11/i.test(p)) return 'linux';
  return 'mac';
}

/**
 * Start the download, from inside the click that asked for it.
 *
 * An uploaded file goes through a hidden frame, so an error page can never
 * replace the forum; a linked one opens in a new tab, which a browser only
 * allows during the click itself.
 */
export function startDownload(version) {
  if (version.external) {
    window.open(version.url, '_blank', 'noopener');
    return;
  }
  const frame = document.createElement('iframe');
  frame.hidden = true;
  frame.src = version.url;
  document.body.appendChild(frame);
  setTimeout(() => frame.remove(), 60000);
}

/**
 * What happens after the click: is the file I got the one they published,
 * what do I do with it, and how do I hear about the next version.
 */
export default class DownloadModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    this.os = guessOs();
    this.copied = null;
    this.file = this.attrs.file && this.attrs.file.versions ? this.attrs.file : null;

    // The spotlight and cards only carry a summary; the sheet needs the rest.
    if (!this.file) {
      api.get('file', { slug: this.attrs.file.slug }).then((file) => {
        this.file = file;
        m.redraw();
      });
    }
  }

  className() {
    return 'DepotDownloadModal Modal--medium';
  }

  title() {
    return null;
  }

  version() {
    const id = this.attrs.version.id;
    return (this.file && this.file.versions.find((v) => v.id === id)) || this.attrs.version;
  }

  content() {
    const file = this.file || this.attrs.file;
    const v = this.version();
    const name = v.external ? null : v.filename || `${file.slug}-${v.version}`;

    return (
      <div className="Modal-body">
        <div className="DepotDownload-head">
          {Cover({
            file,
            className: 'DepotDownload-art',
            children: (
              <span className="DepotDownload-check">
                <Icon name="fas fa-check" />
              </span>
            ),
          })}
          <div>
            <h3>{v.external ? t('download.leaving', { host: v.host || file.host || '' }) : t('download.started')}</h3>
            <p className="Depot-dim">{name ? [<code>{name}</code>, v.size ? ` · ${bytes(v.size)}` : ''] : t('download.link_note')}</p>
            <p className="Depot-dim DepotDownload-retry">
              {t('download.retry')}{' '}
              <a href={v.url} target={v.external ? '_blank' : null} rel="noopener">
                {t('download.retry_link')}
              </a>
            </p>
          </div>
        </div>

        {!this.file ? <LoadingIndicator /> : null}

        {this.file && v.sha256 ? (
          <section className="DepotDownload-section">
            <h4>
              <Icon name="fas fa-shield-alt" /> {t('download.verify_title')}
            </h4>
            <p className="Depot-dim">{t('download.verify_body')}</p>
            <div className="Depot-segmented" role="tablist">
              {Object.keys(OS).map((os) => (
                <button
                  type="button"
                  role="tab"
                  aria-selected={this.os === os ? 'true' : 'false'}
                  className={this.os === os ? 'is-active' : ''}
                  onclick={() => (this.os = os)}
                >
                  {t(`download.os_${os}`)}
                </button>
              ))}
            </div>
            {this.copyRow('cmd', OS[this.os](name || 'download'))}
            <div className="DepotDownload-expect">
              <span className="Depot-dim">{t('download.expect')}</span>
              {this.copyRow('sha', v.sha256, true)}
            </div>
          </section>
        ) : null}

        {this.file && this.file.install.length ? (
          <section className="DepotDownload-section">
            <h4>
              <Icon name="fas fa-list-ol" /> {t('download.install_title')}
            </h4>
            <ol className="Depot-steps">
              {this.file.install.map((step) => (
                <li>{step}</li>
              ))}
            </ol>
          </section>
        ) : null}

        {this.file && app.session.user ? (
          <div className="DepotDownload-watch">
            <div>
              <b>{t('download.watch_title')}</b>
              <span className="Depot-dim">{t('download.watch_body', { count: this.file.watchers })}</span>
            </div>
            <Button
              className={`Button ${this.file.watching ? '' : 'Button--primary'}`}
              icon={this.file.watching ? 'fas fa-bell' : 'far fa-bell'}
              onclick={() => this.watch()}
            >
              {this.file.watching ? t('download.watching') : t('download.watch')}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }

  watch() {
    const file = this.file;
    api.post(`files/${file.id}/watch`, { watch: !file.watching }).then((r) => {
      file.watching = r.watching;
      file.watchers = r.watchers;
      this.attrs.onwatch && this.attrs.onwatch(r);
      m.redraw();
    });
  }

  copyRow(id, text, small) {
    return (
      <div className={`Depot-copy ${small ? 'Depot-copy--small' : ''}`}>
        <code>{text}</code>
        <Button
          className="Button Button--icon"
          icon={this.copied === id ? 'fas fa-check' : 'far fa-copy'}
          aria-label={t('download.copy')}
          onclick={() => {
            if (!navigator.clipboard) return;
            navigator.clipboard.writeText(text).then(
              () => {
                this.copied = id;
                m.redraw();
                setTimeout(() => ((this.copied = null), m.redraw()), 1800);
              },
              () => {}
            );
          }}
        />
      </div>
    );
  }
}
