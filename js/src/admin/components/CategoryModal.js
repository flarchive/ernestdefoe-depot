import app from 'flarum/admin/app';
import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import ColorPreviewInput from 'flarum/common/components/ColorPreviewInput';
import Group from 'flarum/common/models/Group';

const tr = (key, params) => app.translator.trans(`ernestdefoe-depot.admin.${key}`, params);

/** Create or change a category: name, address, look, parent, and which groups may open it. */
export default class CategoryModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);
    const c = this.attrs.category || {};
    this.values = {
      name: c.name || '',
      slug: c.slug || '',
      description: c.description || '',
      color: c.color || '#4d698e',
      icon: c.icon || 'fas fa-folder',
      parent: c.parent ? (this.attrs.categories.find((x) => x.slug === c.parent) || {}).id || '' : '',
    };
    this.groups = new Set((c.groupIds || []).map(String));
  }

  className() {
    return 'DepotCategoryModal Modal--small';
  }

  title() {
    return this.attrs.category ? tr('categories.edit_title', { name: this.attrs.category.name }) : tr('categories.add');
  }

  content() {
    const v = this.values;
    const roots = this.attrs.categories.filter((c) => !c.parent && (!this.attrs.category || c.id !== this.attrs.category.id));
    const groups = app.store.all('groups').filter((g) => ![Group.GUEST_ID, Group.MEMBER_ID, Group.ADMINISTRATOR_ID].includes(g.id()));
    const set = (key) => (e) => (v[key] = e.target.value);

    return (
      <div className="Modal-body">
        <div className="Form-group">
          <label for="depot-cat-name">{tr('categories.name')}</label>
          <input id="depot-cat-name" className="FormControl" maxlength="80" value={v.name} oninput={set('name')} />
        </div>
        <div className="Form-group">
          <label for="depot-cat-slug">{tr('categories.slug')}</label>
          <input
            id="depot-cat-slug"
            className="FormControl"
            maxlength="100"
            value={v.slug}
            oninput={set('slug')}
            placeholder={tr('categories.slug_auto').toString()}
          />
        </div>
        <div className="Form-group">
          <label for="depot-cat-desc">{tr('categories.description')}</label>
          <input id="depot-cat-desc" className="FormControl" maxlength="255" value={v.description} oninput={set('description')} />
        </div>
        <div className="Form-group">
          <label>{tr('categories.color')}</label>
          <ColorPreviewInput className="FormControl" value={v.color} oninput={set('color')} />
        </div>
        <div className="Form-group">
          <label for="depot-cat-icon">{tr('categories.icon')}</label>
          <input id="depot-cat-icon" className="FormControl" value={v.icon} oninput={set('icon')} placeholder="fas fa-cube" />
          <p className="helpText">{tr('categories.icon_help')}</p>
        </div>
        <div className="Form-group">
          <label for="depot-cat-parent">{tr('categories.parent')}</label>
          <select id="depot-cat-parent" className="FormControl" value={v.parent} onchange={set('parent')}>
            <option value="">{tr('categories.no_parent')}</option>
            {roots.map((c) => (
              <option value={c.id}>{c.name}</option>
            ))}
          </select>
        </div>
        <div className="Form-group">
          <label>{tr('categories.lock')}</label>
          <p className="helpText">{tr('categories.lock_help')}</p>
          {groups.map((g) => (
            <label className="checkbox">
              <input
                type="checkbox"
                checked={this.groups.has(g.id())}
                onchange={(e) => (e.target.checked ? this.groups.add(g.id()) : this.groups.delete(g.id()))}
              />
              {g.namePlural()}
            </label>
          ))}
        </div>
        <Button className="Button Button--primary Button--block" type="submit" loading={this.loading}>
          {tr('categories.save')}
        </Button>
      </div>
    );
  }

  onsubmit(e) {
    e.preventDefault();
    const c = this.attrs.category;
    this.loading = true;
    app
      .request({
        method: c ? 'PATCH' : 'POST',
        url: `${app.forum.attribute('apiUrl')}/depot/categories${c ? `/${c.id}` : ''}`,
        body: { ...this.values, parent: this.values.parent || null, groups: [...this.groups] },
      })
      .then(
        (r) => {
          this.hide();
          this.attrs.onsave(r);
        },
        (error) => {
          this.loading = false;
          this.onerror(error);
        }
      );
  }
}
