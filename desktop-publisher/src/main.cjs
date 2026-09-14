'use strict';

const path = require('node:path');
const fs = require('node:fs');
const { app, BrowserWindow, ipcMain, net, safeStorage, session, shell } = require('electron');
const { AdapterRegistry } = require('./adapter-registry.cjs');
const { AccountTaskQueue } = require('./account-queue.cjs');
const { GeoFlowApiClient, normalizeInstance } = require('./api-client.cjs');
const { CredentialStore } = require('./credential-store.cjs');
const { findPublisherDeepLink, parsePublisherDeepLink } = require('./deep-link.cjs');
const { DraftRunner, observedAccountHash } = require('./draft-runner.cjs');
const { PublicationResultObserver } = require('./publication-observer.cjs');
const { AccountWindowManager } = require('./window-manager.cjs');
const { verifyUpdatePackage } = require('./updater.cjs');

let mainWindow;
let credentials;
let api;
let registry;
let windows;
let queue;
let observer;

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
    windows = new AccountWindowManager(BrowserWindow, session, registry);
    queue = new AccountTaskQueue();
    observer = new PublicationResultObserver(api, registry, app.getVersion());
    createMainWindow();
    registerIpc();
    void openDeepLink(findPublisherDeepLink(process.argv));
  });
}

app.on('window-all-closed', () => app.quit());

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
  ipcMain.handle('publisher:sync-all', async (_event, accountIds) => {
    const accountData = await api.listAccounts();
    const selected = accountData.accounts.filter((account) => accountIds.includes(account.id));
    const work = await api.listQueue(selected.map((account) => account.id));
    const runner = new DraftRunner(registry, api, windows, observer);
    const results = [];
    for (const account of selected) {
      const items = work.items.filter((item) => item.account?.id === account.id);
      for (const item of items) {
        try {
          results.push(await queue.enqueue(account.id, () => runner.run(item, { ...account, instance: api.instance })));
        } catch (error) {
          results.push({ status: 'action_required', accountId: account.id, publicationId: item.id, error: error.code || error.message });
          break;
        }
      }
    }
    return results;
  });
}
