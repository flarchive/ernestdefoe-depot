import extractText from 'flarum/common/utils/extractText';
import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import CategoryModal from './CategoryModal';

const tr = (key, params) => app.translator.trans(`ernestdefoe-depot.admin.${key}`, params);
const api = (method, path, body) => app.request({ method, url: `${app.forum.attribute('apiUrl')}/depot/${path}`, body });

/**
 * Depot's admin page: core's settings and permissions sections, plus the
 * categories, which are drag-and-drop ordered — a root and its children move
 * together, a child dropped on a root joins it.
 */
export default class DepotSettingsPage extends ExtensionPage {
  oninit(vnode) {
    super.oninit(vnode);
    this.categories = null;
    this.dragging = null;
    api('GET', 'categories').then((r) => this.setCategories(r));
  }

  setCategories(r) {
    this.categories = r.categories;
    m.redraw();
  }

  sections(vnode) {
    const items = super.sections(vnode);
    items.add('categories', this.categoriesSection(), 90);
    return items;
  }

  categoriesSection() {
    return (
      <div className="ExtensionPage-settings DepotCategories">
        <div className="container">
          <h3 className="ExtensionTitle">{tr('categories.title')}</h3>
          <p className="helpText">{tr('categories.help')}</p>
          {this.categories === null ? <LoadingIndicator /> : this.tree()}
          <Button className="Button" icon="fas fa-plus" onclick={() => this.edit(null)}>
            {tr('categories.add')}
          </Button>
        </div>
      </div>
    );
  }

  tree() {
    const roots = this.categories.filter((c) => !c.parent);
    if (!roots.length) return <p className="DepotCategories-empty">{tr('categories.empty')}</p>;
    return (
      <ol className="DepotCategories-list">
        {roots.map((root) => (
          <li key={root.id}>
            {this.row(root)}
            <ol className="DepotCategories-children">
              {this.categories
                .filter((c) => c.parent === root.slug)
                .map((child) => (
                  <li key={child.id}>{this.row(child)}</li>
                ))}
            </ol>
          </li>
        ))}
      </ol>
    );
  }

  row(c) {
    return (
      <div
        className={`DepotCategories-row ${this.dragging === c.id ? 'is-dragging' : ''}`}
        draggable="true"
        ondragstart={(e) => {
          this.dragging = c.id;
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/plain', String(c.id));
        }}
        ondragend={() => (this.dragging = null)}
        ondragover={(e) => e.preventDefault()}
        ondrop={(e) => {
          e.preventDefault();
          this.drop(this.dragging, c);
        }}
      >
        <Icon name="fas fa-grip-vertical" className="DepotCategories-grip" />
        <span className="DepotCategories-icon" style={{ background: c.color || 'var(--control-bg)' }}>
          <Icon name={c.icon || 'fas fa-folder'} />
        </span>
        <span className="DepotCategories-name">
          <b>{c.name}</b>
          <span className="helpText">
            /downloads/c/{c.slug} ·{' '}
            {tr('categories.files', {
              count: c.parent ? c.count : this.categories.filter((s) => s.parent === c.slug).reduce((n, s) => n + s.count, c.count),
            })}
            {/* The translator returns an array once it interpolates: render it as children, never inside a template string. */}
            {c.locked ? [' · ', tr('categories.locked_to', { groups: c.groups.join(', ') })] : null}
          </span>
        </span>
        <Button className="Button Button--icon" icon="fas fa-pen" aria-label={tr('categories.edit')} onclick={() => this.edit(c)} />
        <Button className="Button Button--icon" icon="far fa-trash-alt" aria-label={tr('categories.delete')} onclick={() => this.remove(c)} />
      </div>
    );
  }

  /**
   * Dropping A on B: a root dropped on a root moves before it; a child dropped
   * on a root joins that root at the top; anything dropped on a child moves
   * beside it, under the same parent. Roots with children stay roots.
   */
  drop(id, target) {
    const list = this.categories;
    const moving = list.find((c) => c.id === id);
    if (!moving || moving.id === target.id) return;
    const hasChildren = list.some((c) => c.parent === moving.slug);

    let parent = target.parent || null;
    if (!target.parent && moving.parent) parent = target.slug;
    if (hasChildren) parent = null;
    moving.parent = parent;

    const rest = list.filter((c) => c.id !== moving.id);
    const at = rest.findIndex((c) => c.id === target.id);
    rest.splice(parent && !target.parent ? at + 1 : at, 0, moving);
    this.categories = rest;

    const bySlug = Object.fromEntries(rest.map((c) => [c.slug, c.id]));
    api('POST', 'categories/order', { order: rest.map((c) => ({ id: c.id, parent: c.parent ? bySlug[c.parent] : null })) }).then((r) =>
      this.setCategories(r)
    );
  }

  edit(category) {
    app.modal.show(CategoryModal, { category, categories: this.categories, onsave: (r) => this.setCategories(r) });
  }

  remove(c) {
    if (!confirm(extractText(tr('categories.delete_confirm', { name: c.name })))) return;
    api('DELETE', `categories/${c.id}`).then(() => api('GET', 'categories').then((r) => this.setCategories(r)));
  }
}
