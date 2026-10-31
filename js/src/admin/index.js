import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import DepotSettingsPage from './components/DepotSettingsPage';

/*
 * Permissions go into Flarum's own Permissions page. Each is a callback: the
 * translator is not ready when this module is evaluated.
 */
const tr = (key) => app.translator.trans(`ernestdefoe-depot.admin.${key}`);

const perm = (permission, icon, section) => [
  () => ({ icon, permission, label: app.translator.trans(`ernestdefoe-depot.admin.permissions.${permission.split('.')[1]}`) }),
  section,
];

export const extend = [
  new Extend.Admin()
    .page(DepotSettingsPage)
    .setting(() => ({
      setting: 'ernestdefoe-depot.count_window',
      type: 'select',
      options: { 1: tr('settings.window_1'), 24: tr('settings.window_24'), 168: tr('settings.window_168') },
      default: '24',
      label: tr('settings.count_window'),
      help: tr('settings.count_window_help'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-depot.max_upload_mb',
      type: 'number',
      min: 1,
      label: tr('settings.max_upload'),
      help: tr('settings.max_upload_help'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-depot.uploads_per_hour',
      type: 'number',
      min: 1,
      label: tr('settings.uploads_per_hour'),
      help: tr('settings.uploads_per_hour_help'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-depot.support_thread',
      type: 'boolean',
      label: tr('settings.support_thread'),
      help: tr('settings.support_thread_help'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-depot.github_sync',
      type: 'boolean',
      label: tr('settings.github_sync'),
      help: tr('settings.github_sync_help'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-depot.github_token',
      type: 'password',
      label: tr('settings.github_token'),
      help: tr('settings.github_token_help'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-depot.accel_prefix',
      type: 'text',
      placeholder: '/depot-internal',
      label: tr('settings.accel_prefix'),
      help: tr('settings.accel_prefix_help'),
    }))
    .permission(...perm('depot.view', 'fas fa-box-open', 'view'))
    .permission(...perm('depot.download', 'fas fa-download', 'view'))
    .permission(...perm('depot.upload', 'fas fa-upload', 'start'))
    .permission(...perm('depot.skipApproval', 'fas fa-check', 'start'))
    .permission(...perm('depot.review', 'far fa-star', 'reply'))
    .permission(...perm('depot.moderate', 'fas fa-gavel', 'moderate')),
];

app.initializers.add('ernestdefoe-depot', () => {});
