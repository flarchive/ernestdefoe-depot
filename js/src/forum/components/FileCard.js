import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import humanTime from 'flarum/common/helpers/humanTime';
import { compact, number, daysAgo } from '../depot-api';
import { t, Sparkline, Stars, UserChip, CategoryLabels, SourceBadge, Cover } from './bits';

/**
 * One file in a list, as a card (grid) or a row (list). The same facts in
 * both, in the same order: what it is, who made it, how current it is, and
 * how much people use and like it.
 */
export default class FileCard extends Component {
  view() {
    const { file, mode } = this.attrs;
    const v = file.version || {};
    const fresh = daysAgo(v.date) <= 7;
    const href = app.route('depot.file', { slug: file.slug });
    const pending = !file.approved ? <span className="Depot-pill Depot-pill--warn">{t('card.pending')}</span> : null;

    if (mode === 'grid') {
      return (
        <Link className="DepotCard" href={href}>
          {Cover({
            file,
            className: 'DepotCard-art',
            children: [
              v.version ? <span className="DepotCard-version">v{v.version}</span> : null,
              fresh ? <span className="DepotCard-fresh">{t('card.updated_badge')}</span> : null,
            ],
          })}
          <div className="DepotCard-body">
            <span className="DepotCard-labels">
              {CategoryLabels({ slug: file.category, link: false })}
              {pending}
            </span>
            <h3 className="DepotCard-title">{file.title}</h3>
            {file.tagline ? <p className="DepotCard-tagline">{file.tagline}</p> : null}
            <div className="DepotCard-meta">
              {UserChip({ user: file.user })}
              {v.date ? <span className="Depot-dim">{humanTime(v.date)}</span> : null}
            </div>
          </div>
          <div className="DepotCard-foot">
            <span className="Depot-metric">
              <Icon name="fas fa-download" /> <b>{compact(file.downloads)}</b>
            </span>
            {Sparkline({ values: file.trend, width: 64, height: 20 })}
            <span className="Depot-metric Depot-metric--end">
              {file.ratings ? (
                [Stars({ value: file.rating }), <b>{file.rating.toFixed(1)}</b>]
              ) : (
                <span className="Depot-dim">{t('card.no_ratings')}</span>
              )}
            </span>
          </div>
        </Link>
      );
    }

    return (
      <Link className="DepotRow" href={href}>
        {Cover({ file, className: 'DepotRow-thumb' })}
        <div className="DepotRow-main">
          <h3 className="DepotRow-title">
            {file.title} {v.version ? <span className="DepotRow-version">v{v.version}</span> : null}
            {fresh ? <span className="Depot-pill Depot-pill--fresh">{t('card.updated_badge')}</span> : null}
            {pending}
            {SourceBadge({ file })}
          </h3>
          {file.tagline ? <p className="DepotRow-tagline">{file.tagline}</p> : null}
          <div className="DepotRow-meta">
            {CategoryLabels({ slug: file.category, link: false })}
            {UserChip({ user: file.user })}
            {v.date ? (
              <span className="Depot-dim">
                {t('card.updated', { time: humanTime(v.date) })}
                {file.compat.length ? ` · ${file.compat[0]}` : ''}
              </span>
            ) : null}
          </div>
        </div>
        <div className="DepotRow-trend">
          {Sparkline({ values: file.trend, width: 88, height: 26 })}
          <span className="Depot-dim">{t('card.last_30_days')}</span>
        </div>
        <div className="DepotRow-stats">
          <span className="DepotRow-downloads">
            <Icon name="fas fa-download" /> {number(file.downloads)}
          </span>
          {file.ratings ? (
            <span className="Depot-metric">
              {Stars({ value: file.rating })} <span className="Depot-dim">{file.rating.toFixed(1)}</span>
            </span>
          ) : null}
        </div>
      </Link>
    );
  }
}

/**
 * A locked category, advertised: its name, how many files, and who it is for.
 * The server sends nothing else about what is inside.
 */
export function LockedCard({ locked, mode }) {
  return (
    <div className={`DepotLocked DepotLocked--${mode}`} style={{ '--label-color': 'var(--muted-color)' }}>
      <div className="DepotLocked-text">
        <Icon name="fas fa-lock" />
        <b>{locked.name}</b>
        <span className="Depot-dim">{t('card.locked', { count: locked.count, group: locked.groups.join(', ') })}</span>
        <a className="Button Button--primary Button--small" href={app.route('depot.category', { slug: locked.slug })}>
          {t('card.see_category')}
        </a>
      </div>
    </div>
  );
}
