'use strict';

const path = require('node:path');
const fs = require('node:fs');
const { app, BrowserWindow, ipcMain, net, Notification, safeStorage, session, shell } = require('electron');
const { AdapterRegistry } = require('./adapter-registry.cjs');
const { AccountTaskQueue } = require('./account-queue.cjs');
const { GeoFlowApiClient, normalizeInstance } = require('./api-client.cjs');
const { createAutoSyncScheduler } = require('./auto-sync.cjs');
const { CredentialStore } = require('./credential-store.cjs');
const { findPublisherDeepLink, parsePublisherDeepLink } = require('./deep-link.cjs');
const { observedAccountHash } = require('./draft-runner.cjs');
const { PublicationResultObserver } = require('./publication-observer.cjs');
const { createSyncRunner } = require('./sync-all.cjs');
const { AccountWindowManager } = require('./window-manager.cjs');
const { verifyUpdatePackage } = require('./updater.cjs');

let mainWindow;
let credentials;
let api;
let registry;
let windows;
let queue;
let observer;
let syncRunner;
let autoSyncScheduler;
let autoSyncSettings;

const hasSingleInstanceLock = app.requestSingleInstanceLock();
if (!hasSingleInstanceLock) {
  app.quit();
} else {
  app.on('second-instance', (_event, argv) => { void openDeepLink(findPublisherDeepLink(argv)); });
  app.on('open-url', (event, url) => { event.preventDefault(); void openDeepLink(url); });
  app.whenReady().then(() => {
    registerProtocolHandler();
    registry = new AdapterRegistry();
    credentials = new CredentialStore(safeStorage, app.getPath('userData'));
    const saved = credentials.load();
    api = new GeoFlowApiClient(net.fetch, saved.instance, saved.token, app.getVersion());
    windows = new AccountWindowManager(BrowserWindow, session, registry, { diagnosticsDir: path.join(app.getPath('userData'), 'diagnostics') });
    queue = new AccountTaskQueue();
    observer = new PublicationResultObserver(api, registry, app.getVersion());
    autoSyncSettings = { autoSyncEnabled: saved.autoSyncEnabled, autoSyncIntervalMinutes: saved.autoSyncIntervalMinutes };
    syncRunner = createSyncRunner({
      api,
      registry,
      windows,
      observer,
      queue,
      adapterVersion: app.getVersion(),
      onRoundComplete: notifySyncResults,
    });
    createMainWindow();
    registerIpc();
    applyAutoSyncConfig();
    void openDeepLink(findPublisherDeepLink(process.argv));
  });
}

app.on('window-all-closed', () => app.quit());
app.on('before-quit', () => {
  autoSyncScheduler?.stop();
});

function createMainWindow() {
  mainWindow = new BrowserWindow({
    width: 1080,
    height: 760,
    minWidth: 820,
    minHeight: 620,
    webPreferences: {
      preload: path.join(__dirname, 'preload.cjs'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      webSecurity: true,
    },
  });
  mainWindow.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
  mainWindow.webContents.on('will-navigate', (event) => event.preventDefault());
  void mainWindow.loadFile(path.join(__dirname, '..', 'renderer', 'index.html'));
}

function registerProtocolHandler() {
  if (process.defaultApp && process.argv[1]) app.setAsDefaultProtocolClient('geoflow-publisher', process.execPath, [path.resolve(process.argv[1])]);
  else app.setAsDefaultProtocolClient('geoflow-publisher');
}

function applyAutoSyncConfig() {
  if (!autoSyncScheduler) {
    autoSyncScheduler = createAutoSyncScheduler({
      run: () => syncRunner.runSyncAll(),
      // 定时轮静默失败：未绑定账号、上一轮进行中、离线等场景都不打扰用户
      onError: () => {},
    });
  }
  if (autoSyncSettings.autoSyncEnabled) autoSyncScheduler.start(autoSyncSettings.autoSyncIntervalMinutes);
  else autoSyncScheduler.stop();
}

function notifyUser(title, body) {
  if (!Notification.isSupported()) return;
  try {
    new Notification({ title, body }).show();
  } catch { /* 通知失败不影响同步结果 */ }
}

function notifySyncResults(results, accounts) {
  const accountName = (accountId) => accounts.find((account) => account.id === accountId)?.account_name || `账号#${accountId}`;
  const needsAction = [...new Set(results
    .filter((result) => result.status === 'action_required' || result.status === 'account_mismatch')
    .map((result) => accountName(result.accountId)))];
  if (needsAction.length) {
    notifyUser('账号需要人工处理', `${needsAction.join('、')} 需要重新登录或人工处理`);
  }
  const ready = results.filter((result) => result.status === 'draft_saved' || result.status === 'awaiting_manual_publish').length;
  if (ready > 0) {
    notifyUser('草稿已就绪', `${ready} 篇草稿已填好，请到各平台审核后发布`);
  }
}

async function openDeepLink(candidate) {
  if (!candidate || !api || !mainWindow) return;
  try {
    const link = parsePublisherDeepLink(candidate, api.instance);
    if (!api.token) throw new Error('publisher_authorization_required');
    const accountData = await api.listAccounts();
    const account = accountData.accounts.find((item) => item.id === link.accountId);
    if (!account) throw new Error('publisher_account_not_found');
    const adapter = registry.get(account.platform);
    const executor = await windows.forAccount({ ...account, instance: api.instance }, adapter);
    await executor.show();
  } catch (error) {
    mainWindow.webContents.send('publisher:deep-link-error', error.code || error.message || 'publisher_deep_link_failed');
  } finally {
    if (mainWindow.isMinimized()) mainWindow.restore();
    mainWindow.show();
    mainWindow.focus();
  }
}

function registerIpc() {
  ipcMain.handle('publisher:state', async () => ({ instance: api.instance, connected: Boolean(api.token), version: app.getVersion(), registryDigest: registry.digest }));
  ipcMain.handle('publisher:discover', async () => {
    const instance = normalizeInstance('http://127.0.0.1:28080');
    const response = await net.fetch(`${instance}/up`);
    if (!response.ok) throw new Error('local_instance_unavailable');
    return { instance };
  });
  ipcMain.handle('publisher:set-instance', async (_event, instance) => {
    api.instance = normalizeInstance(instance);
    credentials.save(api.instance, api.token);
    return { instance: api.instance };
  });
  ipcMain.handle('publisher:authorize', () => api.createDeviceAuthorization());
  ipcMain.handle('publisher:exchange', async (_event, deviceCode) => {
    const result = await api.exchangeDeviceCode(deviceCode);
    api.token = result.token;
    credentials.save(api.instance, api.token);
    return result;
  });
  ipcMain.handle('publisher:open-verification', async (_event, candidate) => {
    const url = new URL(String(candidate));
    if (url.origin !== api.instance || !url.pathname.includes('/manual-publications/browser-connect')) throw new Error('verification_url_blocked');
    await shell.openExternal(url.toString());
    return { opened: true };
  });
  ipcMain.handle('publisher:accounts', () => api.listAccounts());
  ipcMain.handle('publisher:check-update', () => api.desktopUpdate());
  ipcMain.handle('publisher:install-update', async (_event, metadata) => {
    if (!metadata?.available || !metadata.download_url || !metadata.sha256 || !metadata.signature) throw new Error('update_unavailable');
    if (metadata.platform !== 'win32-x64') throw new Error('update_platform_mismatch');
    const filePath = path.join(app.getPath('temp'), `GEOFlow-Desktop-Publisher-${metadata.version}-win-x64.exe`);
    fs.writeFileSync(filePath, await api.download(metadata.download_url), { mode: 0o700 });
    verifyUpdatePackage(filePath, metadata.sha256, metadata.signature);
    const openError = await shell.openPath(filePath);
    if (openError) throw new Error(`update_launch_failed:${openError}`);
    return { launched: true };
  });
  ipcMain.handle('publisher:open-login', async (_event, account) => {
    const adapter = registry.get(account.platform);
    const executor = await windows.forAccount({ ...account, instance: api.instance }, adapter);
    await executor.show();
    return { opened: true };
  });
  ipcMain.handle('publisher:bind-account', async (_event, account) => {
    const adapter = registry.get(account.platform);
    const executor = await windows.forAccount({ ...account, instance: api.instance }, adapter);
    const login = await executor.detectLogin(adapter);
    if (login.captcha || !login.loggedIn) {
      await executor.show();
      throw new Error(login.captcha ? 'captcha_required' : 'login_required');
    }
    const observedHash = observedAccountHash(login.observedAccount);
    if (!observedHash) throw new Error('account_identity_not_detected');
    await api.bindAccount(account.id, observedHash, login.observedAccount);
    return { bound: true };
  });
  ipcMain.handle('publisher:sync-all', (_event, accountIds) => syncRunner.runSyncAll(accountIds));
  ipcMain.handle('publisher:auto-sync-config', () => ({ ...autoSyncSettings }));
  ipcMain.handle('publisher:set-auto-sync', (_event, config) => {
    autoSyncSettings = credentials.saveAutoSync({
      autoSyncEnabled: Boolean(config?.autoSyncEnabled),
      autoSyncIntervalMinutes: config?.autoSyncIntervalMinutes,
    });
    applyAutoSyncConfig();
    return { ...autoSyncSettings };
  });
}
