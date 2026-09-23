'use strict';

const path = require('node:path');
const fs = require('node:fs');
const crypto = require('node:crypto');
const { app, BrowserWindow, ipcMain, net, Notification, safeStorage, session, shell } = require('electron');
const { AdapterRegistry } = require('./adapter-registry.cjs');
const { AccountTaskQueue } = require('./account-queue.cjs');
const { GeoFlowApiClient, normalizeInstance } = require('./api-client.cjs');
const { createAutoSyncScheduler } = require('./auto-sync.cjs');
const { createConnectionHeartbeat } = require('./connection-heartbeat.cjs');
const { CredentialStore } = require('./credential-store.cjs');
const { findPublisherDeepLink, parsePublisherDeepLink } = require('./deep-link.cjs');
const { observedAccountHash } = require('./draft-runner.cjs');
const { LOGIN_PROBES, isAllowedLoginUrl } = require('./login-probes.cjs');
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
let connectionHeartbeat;
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
    registerWebviewGuestHandlers();
    createMainWindow();
    registerIpc();
    startConnectionHeartbeat();
    applyAutoSyncConfig();
    void openDeepLink(findPublisherDeepLink(process.argv));
  });
}

app.on('window-all-closed', () => app.quit());
app.on('before-quit', () => {
  connectionHeartbeat?.stop();
  autoSyncScheduler?.stop();
});

function createMainWindow() {
  mainWindow = new BrowserWindow({
    width: 1280,
    height: 840,
    minWidth: 960,
    minHeight: 640,
    title: 'GEOFlow 发布助手',
    webPreferences: {
      preload: path.join(__dirname, 'preload.cjs'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      webSecurity: true,
      webviewTag: true,
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

function registerWebviewGuestHandlers() {
  app.on('web-contents-created', (_event, contents) => {
    if (contents.getType() !== 'webview') return;
    contents.setWindowOpenHandler(({ url }) => {
      if (isAllowedLoginUrl(url)) {
        try { contents.loadURL(url); } catch { /* 忽略加载失败 */ }
      } else {
        mainWindow?.webContents.send('publisher:deep-link-error', 'navigation_blocked');
      }
      return { action: 'deny' };
    });
    contents.on('will-navigate', (event, url) => {
      if (isAllowedLoginUrl(url)) return;
      event.preventDefault();
      mainWindow?.webContents.send('publisher:deep-link-error', 'navigation_blocked');
    });
  });
}

function accountPartition(accountId) {
  const key = `${api.instance}:${Number(accountId)}`;
  const digest = crypto.createHash('sha256').update(key).digest('hex').slice(0, 20);
  return `persist:geoflow-${digest}`;
}

async function currentConnectionState() {
  if (!api.token) return { connected: false, connectionStatus: 'unpaired', session: null };
  try {
    const sessionData = await api.session();
    return { connected: true, connectionStatus: 'connected', session: sessionData };
  } catch (error) {
    if (error?.status === 401 || error?.code === 'client_repair_required') {
      api.token = null;
      credentials.save(api.instance, null);
      return { connected: false, connectionStatus: 'unpaired', session: null };
    }
    return { connected: false, connectionStatus: 'offline', session: null };
  }
}

function startConnectionHeartbeat() {
  connectionHeartbeat?.stop();
  connectionHeartbeat = createConnectionHeartbeat({
    check: currentConnectionState,
    onState: (state) => {
      if (!mainWindow || mainWindow.isDestroyed()) return;
      mainWindow.webContents.send('publisher:connection-changed', state);
    },
  });
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
    mainWindow.webContents.send('publisher:open-account-tab', account.id);
  } catch (error) {
    mainWindow.webContents.send('publisher:deep-link-error', error.code || error.message || 'publisher_deep_link_failed');
  } finally {
    if (mainWindow.isMinimized()) mainWindow.restore();
    mainWindow.show();
    mainWindow.focus();
  }
}

function registerIpc() {
  ipcMain.handle('publisher:state', async () => ({
    instance: api.instance,
    ...(await currentConnectionState()),
    version: app.getVersion(),
    registryDigest: registry.digest,
    webviewPreloadPath: path.join(__dirname, 'webview-preload.cjs'),
  }));
  ipcMain.handle('publisher:connection-state', () => currentConnectionState());
  ipcMain.handle('publisher:discover', async () => {
    const instance = normalizeInstance('http://127.0.0.1:28080');
    const response = await net.fetch(`${instance}/up`);
    if (!response.ok) throw new Error('local_instance_unavailable');
    return { instance };
  });
  ipcMain.handle('publisher:set-instance', async (_event, instance) => {
    const nextInstance = normalizeInstance(instance);
    if (nextInstance !== api.instance) api.token = null;
    api.instance = nextInstance;
    credentials.save(api.instance, api.token);
    return { instance: api.instance };
  });
  ipcMain.handle('publisher:authorize', () => api.createDeviceAuthorization());
  ipcMain.handle('publisher:exchange', async (_event, deviceCode) => {
    let result;
    try {
      result = await api.exchangeDeviceCode(deviceCode);
    } catch (error) {
      if (error?.code === 'authorization_pending') return { pending: true };
      throw error;
    }
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
  ipcMain.handle('publisher:personas', () => api.listPersonas());
  ipcMain.handle('publisher:create-account', async (_event, payload) => {
    const body = {
      persona_id: Number(payload?.persona_id),
      platform: String(payload?.platform || ''),
      account_name: String(payload?.account_name || '').slice(0, 160),
    };
    if (!Number.isSafeInteger(body.persona_id) || body.persona_id < 1 || !body.platform || !body.account_name.trim()) {
      throw new Error('account_payload_invalid');
    }
    return api.createAccount(body);
  });
  ipcMain.handle('publisher:delete-account', async (_event, accountId) => {
    const id = Number(accountId);
    if (!Number.isSafeInteger(id) || id < 1) throw new Error('publisher_account_invalid');
    return api.deleteAccount(id);
  });
  ipcMain.handle('publisher:probe-configs', async () => LOGIN_PROBES);
  ipcMain.handle('publisher:account-partition', async (_event, accountId) => accountPartition(accountId));
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
  ipcMain.handle('publisher:bind-account', async (_event, account, observed, replaceExisting) => {
    const hash = observedAccountHash(observed);
    if (!hash) throw new Error('account_identity_not_detected');
    await api.bindAccount(Number(account.id), hash, observed, Boolean(replaceExisting));
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
