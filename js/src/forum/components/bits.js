import app from 'flarum/forum/app';
import Icon from 'flarum/common/components/Icon';
import Tooltip from 'flarum/common/components/Tooltip';
import stringToColor from 'flarum/common/utils/stringToColor';
import { category, rootOf, number } from '../depot-api';

export const t = (key, params) => app.translator.trans(`ernestdefoe-depot.forum.${key}`, params);

/*
 * Small pieces drawn as plain functions, called — not used as JSX tags. A
 * function used as a tag is a Mithril closure component and crashes.
 */

/** A 30-day trend line. Values only: it shows direction; the number beside it shows size. */
export function Sparkline({ values, width = 96, height = 28 }) {
  const max = Math.max(...values, 1);
  const step = width / (values.length - 1);
  const pts = values.map((v, i) => [i * step, height - 2 - (v / max) * (height - 4)]);
  const line = pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join('');
  const [lx, ly] = pts[pts.length - 1];

  return (
    <svg className="Depot-spark" viewBox={`0 0 ${width} ${height}`} width={width} height={height} aria-hidden="true">
      <path className="Depot-spark-area" d={`${line}L${width},${height}L0,${height}Z`} />
      <path className="Depot-spark-line" d={line} />
      <circle className="Depot-spark-dot" cx={lx} cy={ly} r="2.5" />
    </svg>
  );
}

export function Stars({ value, size = '' }) {
  const full = Math.round(value * 2) / 2;
  return (
    <span className={`Depot-stars ${size}`} aria-label={t('rating_label', { rating: Number(value).toFixed(1) })}>
      {[1, 2, 3, 4, 5].map((i) => (
        <Icon name={i <= full ? 'fas fa-star' : i - 0.5 === full ? 'fas fa-star-half-alt' : 'far fa-star'} />
      ))}
    </span>
  );
}

/** Flarum's own avatar markup for a user as the Depot API sends one. */
export function Avatar({ user, className = '' }) {
  if (!user) return <span className={`Avatar ${className}`}>?</span>;
  const name = user.displayName || user.username;
  return user.avatarUrl ? (
    <img className={`Avatar ${className}`} src={user.avatarUrl} alt="" loading="lazy" title={name} />
  ) : (
    <span className={`Avatar ${className}`} style={{ '--avatar-bg': `#${stringToColor(name)}` }} title={name}>
      {name.charAt(0).toUpperCase()}
    </span>
  );
}

export function UserChip({ user }) {
  if (!user) return null;
  return (
    <a className="Depot-user" href={app.route('user', { username: user.slug })} onclick={(e) => e.stopPropagation()}>
      {Avatar({ user, className: 'Avatar--mini' })}
      <span className="username">{user.displayName || user.username}</span>
    </a>
  );
}

/** Root category filled, subcategory beside it outlined — Flarum's tag-label language. */
export function CategoryLabels({ slug, link = true }) {
  const c = category(slug);
  if (!c) return null;
  const root = rootOf(c);
  const label = (cat, outline) => {
    const attrs = {
      className: `Depot-label ${outline ? 'Depot-label--outline' : ''}`,
      style: { '--label-color': cat.color || 'var(--control-color)' },
    };
    return link ? (
      <a {...attrs} href={app.route('depot.category', { slug: cat.slug })} onclick={(e) => e.stopPropagation()}>
        {cat.name}
      </a>
    ) : (
      <span {...attrs}>{cat.name}</span>
    );
  };

  return (
    <span className="Depot-labels">
      {label(root)}
      {c.parent ? label(c, true) : null}
    </span>
  );
}

/**
 * Inline style for a file's cover: its first screenshot, or — for a file with
 * none — its category's colour, with the category icon drawn over it by CSS.
 */
export function coverStyle(file, size = 'thumb') {
  if (file.cover) return { backgroundImage: `url(${file.cover[size] || file.cover.thumb})` };
  const c = rootOf(category(file.category));
  return { '--cover-color': (c && c.color) || 'var(--primary-color)' };
}

export function Cover({ file, className = '', size = 'thumb', children }) {
  const c = rootOf(category(file.category));
  return (
    <div className={`${className} ${file.cover ? '' : 'Depot-cover--empty'}`} style={coverStyle(file, size)}>
      {!file.cover ? <Icon name={(c && c.icon) || 'fas fa-box-open'} className="Depot-cover-icon" /> : null}
      {children}
    </div>
  );
}

export function SourceBadge({ file }) {
  if (file.source === 'github')
    return (
      <Tooltip text={t('source.github_tooltip', { repo: file.repo })}>
        <span className="Depot-badge Depot-badge--github">
          <Icon name="fab fa-github" /> GitHub
        </span>
      </Tooltip>
    );
  if (file.source === 'link' && file.host)
    return (
      <Tooltip text={t('source.link_tooltip', { host: file.host })}>
        <span className="Depot-badge">
          <Icon name="fas fa-external-link-alt" /> {file.host}
        </span>
      </Tooltip>
    );
  return null;
}

export const Count = ({ icon, value, label }) => (
  <span className="Depot-count" title={label}>
    <Icon name={icon} /> {number(value)}
  </span>
);
