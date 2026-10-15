import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LinkButton from 'flarum/common/components/LinkButton';
import Model from 'flarum/common/Model';
import Notification from 'flarum/forum/components/Notification';

/*
 * The pages load on demand, in one chunk with their own stylesheet, so the
 * forum's every-page bundle carries only this file — the routes, one sidebar
 * link and the notification wiring — and forum.css carries nothing.
 */
import stylesheet from './stylesheet';

let styles;
/** The page styles, once, resolved when they have arrived so nothing paints unstyled. */
function loadStyles() {
  return (styles ??= new Promise((resolve) => {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = `${app.forum.attribute('baseUrl')}/downloads/depot.css?v=${stylesheet}`;
    link.onload = link.onerror = resolve;
    document.head.appendChild(link);
  }));
}

const page = (name) => () => Promise.all([import('./depot'), loadStyles()]).then(([pages]) => ({ default: pages[name] }));

/*
 * The notification subject's model. Not optional: the store builds every
 * notification's subject with `new this.models[type]` and an unregistered
 * type kills the whole notification list, for everybody.
 */
class DepotFile extends Model {}
Object.assign(DepotFile.prototype, { title: Model.attribute('title'), slug: Model.attribute('slug') });

const t = (key, params) => app.translator.trans(`ernestdefoe-depot.forum.${key}`, params);

function notification(icon, key, data) {
  return class extends Notification {
    icon() {
      return icon;
    }
    href() {
      const subject = this.attrs.notification.subject();
      return subject ? app.route('depot.file', { slug: subject.slug() }) : app.route('depot.index');
    }
    content() {
      return t(key, data(this.attrs.notification));
    }
    excerpt() {
      return this.attrs.notification.subject()?.title() || null;
    }
  };
}

app.initializers.add('ernestdefoe-depot', () => {
  // Each route also exists in extend.php, so pasted links load.
  app.routes['depot.index'] = { path: '/downloads', component: page('DepotPage') };
  app.routes['depot.category'] = { path: '/downloads/c/:slug', component: page('DepotPage') };
  app.routes['depot.file'] = { path: '/downloads/f/:slug', component: page('FilePage') };

  app.store.models['depot-files'] = DepotFile;

  // `user` is a reserved translator placeholder, so the uploader is `username`.
  app.notificationComponents.depotNewVersion = notification('fas fa-box-open', 'notifications.new_version', (n) => ({
    version: n.content()?.version || '',
  }));
  app.notificationComponents.depotFileApproved = notification('fas fa-check', 'notifications.approved', () => ({}));

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items) {
    items.add('depotNewVersion', { name: 'depotNewVersion', icon: 'fas fa-box-open', label: t('settings.notify_new_version') });
    items.add('depotFileApproved', { name: 'depotFileApproved', icon: 'fas fa-check', label: t('settings.notify_approved') });
  });

  extend(IndexSidebar.prototype, 'navItems', function (items) {
    if (!app.forum.attribute('canViewDepot')) return;
    items.add('depot', LinkButton.component({ href: app.route('depot.index'), icon: 'fas fa-box-open' }, t('nav.link')), 6);
  });
});
