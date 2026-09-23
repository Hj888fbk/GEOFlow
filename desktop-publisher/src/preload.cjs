'use strict';

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('geoflowPublisher', Object.freeze({
  state: () => ipcRenderer.invoke('publisher:state'),
  connectionState: () => ipcRenderer.invoke('publisher:connection-state'),
  onConnectionChanged: (callback) => ipcRenderer.on('publisher:connection-changed', (_event, state) => callback(state)),
  discover: () => ipcRenderer.invoke('publisher:discover'),
  setInstance: (instance) => ipcRenderer.invoke('publisher:set-instance', String(instance)),
  authorize: () => ipcRenderer.invoke('publisher:authorize'),
  exchange: (deviceCode) => ipcRenderer.invoke('publisher:exchange', String(deviceCode)),
  openVerification: (url) => ipcRenderer.invoke('publisher:open-verification', String(url)),
  accounts: () => ipcRenderer.invoke('publisher:accounts'),
  personas: () => ipcRenderer.invoke('publisher:personas'),
  createAccount: (payload) => ipcRenderer.invoke('publisher:create-account', {
    persona_id: Number(payload?.persona_id),
    platform: String(payload?.platform || ''),
    account_name: String(payload?.account_name || ''),
  }),
  deleteAccount: (accountId) => ipcRenderer.invoke('publisher:delete-account', Number(accountId)),
  probeConfigs: () => ipcRenderer.invoke('publisher:probe-configs'),
  accountPartition: (accountId) => ipcRenderer.invoke('publisher:account-partition', Number(accountId)),
  checkUpdate: () => ipcRenderer.invoke('publisher:check-update'),
  installUpdate: (metadata) => ipcRenderer.invoke('publisher:install-update', {
    available: Boolean(metadata?.available),
    version: String(metadata?.version || ''),
    platform: String(metadata?.platform || ''),
    sha256: String(metadata?.sha256 || ''),
    signature: String(metadata?.signature || ''),
    download_url: String(metadata?.download_url || ''),
  }),
  bindAccount: (account, observed, replaceExisting = false) => ipcRenderer.invoke(
    'publisher:bind-account',
    sanitizeAccount(account),
    sanitizeObserved(observed),
    Boolean(replaceExisting),
  ),
  onDeepLinkError: (listener) => ipcRenderer.on('publisher:deep-link-error', (_event, code) => listener(String(code))),
  onOpenAccountTab: (listener) => ipcRenderer.on('publisher:open-account-tab', (_event, accountId) => listener(Number(accountId))),
  syncAll: (accountIds) => ipcRenderer.invoke('publisher:sync-all', accountIds.map(Number).filter(Number.isInteger)),
  autoSyncConfig: () => ipcRenderer.invoke('publisher:auto-sync-config'),
  setAutoSync: (config) => ipcRenderer.invoke('publisher:set-auto-sync', {
    autoSyncEnabled: Boolean(config?.autoSyncEnabled),
    autoSyncIntervalMinutes: Number(config?.autoSyncIntervalMinutes),
  }),
}));

function sanitizeAccount(account) {
  return {
    id: Number(account.id),
    platform: String(account.platform || ''),
    account_name: String(account.account_name || ''),
    profile_url: account.profile_url ? String(account.profile_url) : null,
    editor_url: account.editor_url ? String(account.editor_url) : null,
    account_uid: account.account_uid ? String(account.account_uid) : null,
    homepage_identifier: account.homepage_identifier ? String(account.homepage_identifier) : null,
  };
}

function sanitizeObserved(observed) {
  const type = String(observed?.type || '');
  if (!['profile_url', 'account_uid', 'homepage_identifier'].includes(type)) return null;
  return { type, value: String(observed?.value || '').slice(0, 1000) };
}
