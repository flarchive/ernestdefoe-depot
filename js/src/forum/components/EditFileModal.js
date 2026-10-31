import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import { api, state, rootOf } from '../depot-api';
import { t } from './bits';

/** Change a file's details and screenshots. Versions have their own form. */
export default class EditFileModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);
    const f = this.attrs.file;
    this.values = {
      title: f.title,
      category: f.category,
      tagline: f.tagline || '',
      description: f.description || '',
      game: f.game || '',
      compat: f.compat.join(', '),
      licence: f.licence || '',
      install: f.install.join('\n'),
      requires: f.requires.map((r) => [r.name, r.note].filter(Boolean).join(' — ') + (r.optional ? ' (optional)' : '')).join('\n'),
      fields: f.fields.map(([k, v]) => `${k}: ${v}`).join('\n'),
    };
    this.shots = [...f.screenshots];
    this.added = [];
  }

  className() {
    return 'DepotEditModal Modal--medium';
  }

  title() {
    return t('edit.title');
  }

  input(key, label, attrs = {}, help) {
    const set = (e) => (this.values[key] = e.target.value);
    return (
      <div className="Form-group">
        <label for={`depot-edit-${key}`}>{label}</label>
        {attrs.rows ? (
          <textarea id={`depot-edit-${key}`} className="FormControl" value={this.values[key]} oninput={set} {...attrs} />
        ) : (
          <input id={`depot-edit-${key}`} className="FormControl" value={this.values[key]} oninput={set} {...attrs} />
        )}
        {help ? <p className="helpText">{help}</p> : null}
      </div>
    );
  }

  content() {
    const leaves = state.categories.filter((c) => !c.locked && !state.categories.some((s) => s.parent === c.slug));
    return (
      <div className="Modal-body">
        {this.input('title', t('upload.name_label'), { maxlength: 120 })}
        <div className="Form-group">
          <label for="depot-edit-category">{t('upload.category_label')}</label>
          <select
            id="depot-edit-category"
            className="FormControl"
            value={this.values.category}
            onchange={(e) => (this.values.category = e.target.value)}
          >
            {leaves.map((c) => (
              <option value={c.slug}>{c.parent ? `${rootOf(c).name} › ${c.name}` : c.name}</option>
            ))}
          </select>
        </div>
        {this.input('tagline', t('upload.tagline_label'), { maxlength: 160 })}
        {this.input('description', t('upload.description_label'), { rows: 5 })}
        <div className="Depot-row2">
          {this.input('game', t('upload.game_label'), { maxlength: 60 })}
          {this.input('compat', t('upload.compat_label'), {}, t('upload.compat_help'))}
        </div>
        {this.input('licence', t('upload.licence_label'), { maxlength: 60 })}
        {this.input('install', t('upload.install_label'), { rows: 3 }, t('upload.lines_help'))}
        {this.input('requires', t('upload.requires_label'), { rows: 2 }, t('upload.requires_help'))}
        {this.input('fields', t('edit.fields_label'), { rows: 2 }, t('edit.fields_help'))}

        <div className="Form-group">
          <label>{t('upload.step_media')}</label>
          <div className="DepotShots">
            {this.shots.map((s) => (
              <div className="DepotShots-item" style={{ backgroundImage: `url(${s.thumb})` }}>
                <button type="button" aria-label={t('upload.remove')} onclick={() => this.removeShot(s)}>
                  <Icon name="fas fa-times" />
                </button>
              </div>
            ))}
            {this.added.map((s, i) => (
              <div className="DepotShots-item" style={{ backgroundImage: `url(${s.url})` }}>
                <button type="button" aria-label={t('upload.remove')} onclick={() => (URL.revokeObjectURL(s.url), this.added.splice(i, 1))}>
                  <Icon name="fas fa-times" />
                </button>
              </div>
            ))}
            {this.shots.length + this.added.length < 8 ? (
              <label className="DepotShots-add">
                <input
                  type="file"
                  accept="image/*"
                  multiple
                  className="sr-only"
                  onchange={(e) =>
                    Array.from(e.target.files)
                      .slice(0, 8 - this.shots.length - this.added.length)
                      .forEach((f) => this.added.push({ file: f, url: URL.createObjectURL(f) }))
                  }
                />
                <Icon name="far fa-images" />
                <span>{t('upload.add_shots')}</span>
              </label>
            ) : null}
          </div>
        </div>

        <Button className="Button Button--primary Button--block" type="submit" loading={this.loading}>
          {t('edit.save')}
        </Button>
      </div>
    );
  }

  removeShot(s) {
    api.del(`screenshots/${s.id}`).then(() => {
      this.shots = this.shots.filter((x) => x.id !== s.id);
      m.redraw();
    });
  }

  onsubmit(e) {
    e.preventDefault();
    const form = new FormData();
    Object.entries(this.values).forEach(([k, v]) => form.append(k, v));
    this.added.forEach((s) => form.append('screenshots[]', s.file));

    this.loading = true;
    api.upload(`files/${this.attrs.file.id}`, form).then(
      (r) => {
        this.hide();
        this.attrs.onsave && this.attrs.onsave(r.slug);
      },
      (error) => {
        this.loading = false;
        this.onerror(error);
      }
    );
  }
}
