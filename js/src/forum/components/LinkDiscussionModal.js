import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import extractText from 'flarum/common/utils/extractText';
import { api } from '../depot-api';
import { t } from './bits';

/** Point a file at an existing discussion as its support discussion, or unlink it. */
export default class LinkDiscussionModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);
    const d = this.attrs.file.discussion;
    this.value = d ? d.url : '';
  }

  className() {
    return 'DepotLinkModal Modal--small';
  }

  title() {
    return t('link.title');
  }

  content() {
    const linked = !!this.attrs.file.discussion;
    return (
      <div className="Modal-body">
        <div className="Form-group">
          <label for="depot-link">{t('link.label')}</label>
          <input
            id="depot-link"
            className="FormControl"
            value={this.value}
            oninput={(e) => (this.value = e.target.value)}
            placeholder={extractText(t('link.placeholder'))}
          />
          <p className="helpText">{t('link.help')}</p>
        </div>
        <Button className="Button Button--primary Button--block" type="submit" loading={this.loading} disabled={!this.value.trim()}>
          {t('link.save')}
        </Button>
        {linked ? (
          <Button className="Button Button--block" type="button" onclick={() => this.save('')} disabled={this.loading}>
            {t('link.unlink')}
          </Button>
        ) : null}
      </div>
    );
  }

  onsubmit(e) {
    e.preventDefault();
    this.save(this.value);
  }

  save(value) {
    this.loading = true;
    api
      .post(`files/${this.attrs.file.id}/discussion`, { discussion: value.trim() })
      .then(() => {
        this.hide();
        this.attrs.onsave();
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
