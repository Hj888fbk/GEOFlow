'use strict';

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('geoflowPublisher', Object.freeze({
  state: () => ipcRenderer.invoke('publisher:state'),
  discover: () => ipcRenderer.invoke('publisher:discover'),
  setInstance: (instance) => ipcRenderer.invoke('publisher:set-instance', String(instance)),
  authorize: () => ipcRenderer.invoke('publisher:authorize'),
  exchange: (deviceCode) => ipcRenderer.invoke('publisher:exchange', String(deviceCode)),
  openVerification: (url) => ipcRenderer.invoke('publisher:open-verification', String(url)),
  accounts: () => ipcRenderer.invoke('publisher:accounts'),
  checkUpdate: () => ipcRenderer.invoke('publisher:check-update'),
  installUpdate: (metadata) => ipcRenderer.invoke('publisher:install-update', {
    available: Boolean(metadata?.available),
    version: String(metadata?.version || ''),
    platform: String(metadata?.platform || ''),
    sha256: String(metadata?.sha256 || ''),
    signature: String(metadata?.signature || ''),
    download_url: String(metadata?.download_url || ''),
  }),
  openLogin: (account) => ipcRenderer.invoke('publisher:open-login', sanitizeAccount(account)),
  bindAccount: (account) => ipcRenderer.invoke('publisher:bind-account', sanitizeAccount(account)),
  onDeepLinkError: (listener) => ipcRenderer.on('publisher:deep-link-error', (_event, code) => listener(String(code))),
  syncAll: (accountIds) => ipcRenderer.invoke('publisher:sync-all', accountIds.map(Number).filter(Number.isInteger)),
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
