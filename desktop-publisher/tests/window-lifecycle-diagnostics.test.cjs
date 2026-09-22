'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { DraftRunner } = require('../src/draft-runner.cjs');
const { AccountWindowManager, WindowExecutor, buildDiagnosticsSummary, pruneDiagnosticScreenshots } = require('../src/window-manager.cjs');

function makeTempDir() {
  return fs.mkdtempSync(path.join(os.tmpdir(), 'geoflow-diag-test-'));
}

function fakeBrowserWindowClass() {
  const created = [];
  class FakeBrowserWindow {
    constructor() {
      this.destroyed = false;
      this.webContents = {
        setWindowOpenHandler: () => undefined,
        on: () => undefined,
        getURL: () => 'https://editor.example/',
        executeJavaScript: async () => null,
      };
      created.push(this);
    }

    isDestroyed() { return this.destroyed; }

    destroy() { this.destroyed = true; }

    async loadURL() { return undefined; }
  }
  return { FakeBrowserWindow, created };
}

const DIAG_INFO = {
  url: 'https://mp.example.com/editor',
  title: '编辑器',
  fileInputs: [
    { accept: 'image/*', visible: false },
    { accept: '', visible: true },
  ],
  buttons: ['图片', '上传图片', '插入'],
};

function failingInputDebugger() {
  return {
    isAttached: () => true,
    attach: () => undefined,
    async sendCommand(method) {
      if (method === 'DOM.getDocument') return { root: { nodeId: 1 } };
      if (method === 'DOM.querySelector') return { nodeId: 0 };
      return {};
    },
  };
}

test('manager release destroys the window and drops it from the pool', async () => {
  const { FakeBrowserWindow, created } = fakeBrowserWindowClass();
  const registry = { assertAllowedUrl: (_platform, url) => url };
  const manager = new AccountWindowManager(FakeBrowserWindow, null, registry);
  const account = { id: 7, instance: 'local' };
  const adapter = { editorUrl: 'https://editor.example/' };

  await manager.forAccount(account, adapter);
  assert.equal(created.length, 1);
  assert.equal(manager.windows.size, 1);

  manager.release(account);
  assert.equal(created[0].destroyed, true);
  assert.equal(manager.windows.size, 0);

  // 下一个工单重新开窗口（登录态在 persist: partition，不在窗口对象上）
  await manager.forAccount(account, adapter);
  assert.equal(created.length, 2);
  manager.release(account);
  manager.release(account); // 重复释放安全
});

test('run keeps the window and observer watch after success', async () => {
  const adapter = { editorUrl: 'https://mock.example/editor', titleSelectors: ['#title'], bodySelectors: ['#body'] };
  const registry = { get: () => adapter, assertAllowedUrl: (_platform, url) => url };
  const payload = { title: '标题', body_html: '<p>正文</p>', media_manifest: [] };
  const calls = [];
  const executor = {
    detectLogin: async () => ({ loggedIn: true, captcha: false, observedIdentity: 'homepage:mock-account' }),
    openEditor: async () => undefined,
    fillTitle: async () => undefined,
    fillBody: async () => undefined,
    uploadImages: async () => [],
    saveDraft: async () => ({ id: 'd1', url: 'https://mock.example/drafts/d1' }),
    reopenDraft: async () => undefined,
    readDraft: async () => ({ title: payload.title, textHash: '', headings: [], imageCount: 0, mediaReceipts: [] }),
    show: async () => calls.push('show'),
  };
  const released = [];
  const windows = { forAccount: async () => executor, release: (account) => released.push(account.id) };
  const api = {
    claim: async () => ({ publication: { id: 41, revision: 2, publication_payload: payload } }),
    draftReceipt: async () => ({ publication: { id: 41, revision: 3, status: 'draft_filled' } }),
    reportAccount: async () => undefined,
  };
  const stopped = [];
  const observer = { watch: () => undefined, stop: (id) => stopped.push(id) };
  const runner = new DraftRunner(registry, api, windows, observer);
  const result = await runner.run({ id: 41, revision: 1, platform: 'mock', status: 'ready' }, { id: 9, homepage_identifier: 'mock-account' });
  assert.equal(result.status, 'draft_saved');
  // 成功态：窗口保留（用户在该窗口人工点发布），observer 监听不停止
  assert.deepEqual(released, []);
  assert.deepEqual(stopped, []);
  assert.deepEqual(calls, ['show']);
});

test('run releases the account window when login is required', async () => {
  const adapter = { editorUrl: 'https://mock.example/editor', titleSelectors: ['#title'], bodySelectors: ['#body'] };
  const registry = { get: () => adapter, assertAllowedUrl: (_platform, url) => url };
  const executor = {
    detectLogin: async () => ({ loggedIn: false, captcha: false, observedIdentity: '' }),
  };
  const released = [];
  const windows = { forAccount: async () => executor, release: (account) => released.push(account.id) };
  let report;
  const api = { reportAccount: async (_id, body) => { report = body; } };
  const runner = new DraftRunner(registry, api, windows);
  const result = await runner.run({ id: 41, revision: 1, platform: 'mock', status: 'ready' }, { id: 9, homepage_identifier: 'mock-account' });
  assert.equal(result.status, 'action_required');
  assert.equal(report.last_error_code, 'login_required');
  assert.deepEqual(released, [9]);
});

test('run releases the account window and reports diagnostics after a structural failure', async () => {
  const adapter = { editorUrl: 'https://mock.example/editor', titleSelectors: ['#title'], bodySelectors: ['#body'] };
  const registry = { get: () => adapter, assertAllowedUrl: (_platform, url) => url };
  const payload = { title: '标题', body_html: '<p>正文</p>', media_manifest: [] };
  const failure = new Error('editor_dom_changed');
  failure.code = 'editor_dom_changed';
  failure.diagnostics = 'stage=upload_input url=https://mock.example/editor file_inputs=0 buttons=[]';
  const executor = {
    detectLogin: async () => ({ loggedIn: true, captcha: false, observedIdentity: 'homepage:mock-account' }),
    openEditor: async () => undefined,
    fillTitle: async () => undefined,
    fillBody: async () => undefined,
    uploadImages: async () => { throw failure; },
    show: async () => undefined,
  };
  const released = [];
  const windows = { forAccount: async () => executor, release: (account) => released.push(account.id) };
  let report;
  let adapterFailure;
  const api = {
    claim: async () => ({ publication: { id: 41, revision: 2, publication_payload: payload } }),
    reportAccount: async (_id, body) => { report = body; },
    adapterFailure: async (_id, body) => { adapterFailure = body; },
  };
  const runner = new DraftRunner(registry, api, windows);
  await assert.rejects(
    () => runner.run({ id: 41, revision: 1, platform: 'mock', status: 'ready' }, { id: 9, homepage_identifier: 'mock-account' }),
    /editor_dom_changed/,
  );
  assert.deepEqual(released, [9]);
  assert.equal(report.last_error_code, 'editor_dom_changed');
  assert.equal(report.last_error_diagnostics, failure.diagnostics);
  assert.equal(adapterFailure.error_code, 'editor_dom_changed');
});

test('upload failure carries a diagnostics summary and saves a screenshot', async () => {
  const diagnosticsDir = makeTempDir();
  const win = {
    webContents: {
      executeJavaScript: async () => null,
      debugger: failingInputDebugger(),
      capturePage: async () => ({ toPNG: () => Buffer.from('fake-png-bytes') }),
    },
  };
  const executor = new WindowExecutor(win, { diagnosticsDir });
  executor.exec = async (script) => (script.includes('input[type="file"]') ? DIAG_INFO : false);
  const adapter = { label: '微博', imageInputSelectors: ['input[type="file"][accept*="image"]'], bodySelectors: ['textarea'] };
  const media = [{ media_key: 'img1', sha256: 'a'.repeat(64), buffer: Buffer.from('x'), mimeType: 'image/png' }];

  let failure = null;
  try {
    await executor.uploadImages(adapter, media);
  } catch (error) {
    failure = error;
  }
  assert.equal(failure?.code, 'editor_dom_changed');
  assert.ok(failure.diagnostics.includes('stage=upload_input'));
  assert.ok(failure.diagnostics.includes('url=https://mp.example.com/editor'));
  assert.ok(failure.diagnostics.includes('file_inputs=2(hidden:image/*,visible:any)'));
  assert.ok(failure.diagnostics.includes('buttons=[图片|上传图片|插入]'));
  assert.ok(failure.diagnostics.includes('screenshot=saved'));
  assert.ok(failure.diagnostics.length <= 1000);

  const screenshots = fs.readdirSync(diagnosticsDir).filter((name) => name.endsWith('.png'));
  assert.equal(screenshots.length, 1);
  assert.ok(screenshots[0].includes('微博'));
  assert.ok(screenshots[0].includes('upload_input'));
});

test('fillTitle and fillBody failures carry diagnostics too', async () => {
  const win = { webContents: { executeJavaScript: async () => null } };
  const executor = new WindowExecutor(win, {});
  executor.exec = async (script) => (script.includes('input[type="file"]') ? DIAG_INFO : false);
  const adapter = { label: 'CSDN', titleSelectors: ['#title'], bodySelectors: ['#body'] };

  await assert.rejects(
    () => executor.fillTitle(adapter, '标题'),
    (error) => error.code === 'editor_dom_changed' && error.diagnostics.includes('stage=fill_title') && error.diagnostics.includes('screenshot=unavailable'),
  );
  await assert.rejects(
    () => executor.fillBody(adapter, '正文'),
    (error) => error.code === 'editor_dom_changed' && error.diagnostics.includes('stage=fill_body'),
  );
});

test('diagnostics summary is capped at 1000 characters', () => {
  const noisy = {
    url: `https://example.com/${'x'.repeat(900)}`,
    title: 't'.repeat(200),
    fileInputs: Array.from({ length: 30 }, () => ({ accept: 'image/*'.repeat(10), visible: true })),
    buttons: Array.from({ length: 10 }, (_unused, index) => `上传图片按钮${index}${'长'.repeat(20)}`),
  };
  const summary = buildDiagnosticsSummary('upload_input', noisy, false);
  assert.ok(summary.length <= 1000);
  assert.ok(summary.startsWith('stage=upload_input'));
});

test('diagnostics directory keeps only the newest 20 screenshots', () => {
  const dir = makeTempDir();
  const now = Date.now();
  for (let index = 0; index < 25; index += 1) {
    const name = `2026-01-01T00-00-${String(index).padStart(2, '0')}-test-upload_input.png`;
    const filePath = path.join(dir, name);
    fs.writeFileSync(filePath, 'x');
    const mtime = new Date(now - (25 - index) * 1000);
    fs.utimesSync(filePath, mtime, mtime);
  }
  pruneDiagnosticScreenshots(dir, 20);
  const remaining = fs.readdirSync(dir).filter((name) => name.endsWith('.png')).sort();
  assert.equal(remaining.length, 20);
  for (const index of [0, 1, 2, 3, 4]) {
    const pruned = `2026-01-01T00-00-${String(index).padStart(2, '0')}-test-upload_input.png`;
    assert.ok(!remaining.includes(pruned), `oldest screenshot ${pruned} should be pruned`);
  }
  assert.ok(remaining.includes('2026-01-01T00-00-24-test-upload_input.png'));
});
