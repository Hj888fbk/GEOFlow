'use strict';

const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const test = require('node:test');
const { WindowExecutor } = require('../src/window-manager.cjs');

function makeExecutor(overrides = {}) {
  const win = { webContents: { executeJavaScript: async () => null, ...overrides } };
  return new WindowExecutor(win);
}

function inputDebugger(calls) {
  return {
    isAttached: () => true,
    attach: () => undefined,
    async sendCommand(method, params) {
      calls.push([method, params]);
      if (method === 'DOM.getDocument') return { root: { nodeId: 1 } };
      if (method === 'DOM.querySelector') return { nodeId: 42 };
      return {};
    },
  };
}

class ChooserDebugger extends EventEmitter {
  constructor(calls) {
    super();
    this.calls = calls;
  }

  isAttached() { return false; }

  attach() { this.calls.push(['attach']); }

  async sendCommand(method, params) {
    this.calls.push([method, params]);
    return {};
  }
}

const MEDIA = [{ media_key: 'img1', sha256: 'a'.repeat(64), buffer: Buffer.from('image-bytes'), mimeType: 'image/png' }];

test('fillTitle is a no-op for titleless adapters', async () => {
  const executor = makeExecutor();
  let execCount = 0;
  executor.exec = async () => { execCount += 1; return true; };
  await executor.fillTitle({ titleSelectors: [] }, '标题');
  assert.equal(execCount, 0);
  await executor.fillTitle({ titleSelectors: ['#title'] }, '标题');
  assert.equal(execCount, 1);
});

test('upload strategy defaults to input: CDP setFileInputFiles directly', async () => {
  const calls = [];
  const executor = makeExecutor({ debugger: inputDebugger(calls) });
  executor.exec = async () => ({ urls: ['https://cdn.example/1.png'], positive: false, marker: false });
  const adapter = { imageInputSelectors: ['input[type="file"]'], bodySelectors: ['#body'] };
  const receipts = await executor.uploadImages(adapter, MEDIA);
  const setFiles = calls.find(([method]) => method === 'DOM.setFileInputFiles');
  assert.ok(setFiles);
  assert.equal(setFiles[1].nodeId, 42);
  assert.equal(setFiles[1].files.length, 1);
  assert.equal(receipts.length, 1);
  assert.equal(receipts[0].platform_url, 'https://cdn.example/1.png');
  assert.equal(receipts[0].source_sha256, 'a'.repeat(64));
});

test('button-then-input clicks the insert button before setting files', async () => {
  const calls = [];
  const order = [];
  const executor = makeExecutor({ debugger: inputDebugger(calls) });
  executor.exec = async (script) => {
    if (script.includes('button.insert-pic')) { order.push('click'); return true; }
    return { urls: ['https://cdn.example/1.png'], positive: false, marker: false };
  };
  const adapter = {
    imageInputSelectors: ['input[type="file"]'],
    bodySelectors: ['#body'],
    upload: { mode: 'button-then-input', buttonSelectors: ['button.insert-pic'] },
  };
  const receipts = await executor.uploadImages(adapter, MEDIA);
  assert.equal(receipts.length, 1);
  assert.deepEqual(order, ['click']);
  assert.ok(calls.some(([method]) => method === 'DOM.setFileInputFiles'));
});

test('button-then-input fails structurally when the button is gone', async () => {
  const executor = makeExecutor({ debugger: inputDebugger([]) });
  executor.exec = async () => false;
  const adapter = {
    imageInputSelectors: ['input[type="file"]'],
    bodySelectors: ['#body'],
    upload: { mode: 'button-then-input', buttonSelectors: ['button.insert-pic'] },
  };
  await assert.rejects(() => executor.uploadImages(adapter, MEDIA), /editor_dom_changed/);
});

test('filechooser mode intercepts the chooser and fills by backendNodeId', async () => {
  const calls = [];
  const debug = new ChooserDebugger(calls);
  const executor = makeExecutor({ debugger: debug });
  executor.exec = async (script) => {
    if (script.includes('a.upload-pic')) {
      process.nextTick(() => debug.emit('message', {}, 'Page.fileChooserOpened', { backendNodeId: 7 }));
      return true;
    }
    return { urls: ['https://cdn.example/1.png'], positive: false, marker: false };
  };
  const adapter = {
    imageInputSelectors: ['input[type="file"]'],
    bodySelectors: ['#body'],
    upload: { mode: 'filechooser', triggerSelectors: ['a.upload-pic'] },
  };
  const receipts = await executor.uploadImages(adapter, MEDIA);
  assert.equal(receipts.length, 1);
  assert.ok(calls.some(([method, params]) => method === 'Page.setInterceptFileChooserDialog' && params.enabled === true));
  const setFiles = calls.find(([method]) => method === 'DOM.setFileInputFiles');
  assert.equal(setFiles[1].backendNodeId, 7);
  assert.ok(calls.some(([method, params]) => method === 'Page.setInterceptFileChooserDialog' && params.enabled === false));
});

test('filechooser mode fails structurally when no chooser opens', async () => {
  const calls = [];
  const debug = new ChooserDebugger(calls);
  const executor = makeExecutor({ debugger: debug });
  executor.exec = async () => false;
  const adapter = {
    imageInputSelectors: ['input[type="file"]'],
    bodySelectors: ['#body'],
    upload: { mode: 'filechooser', triggerSelectors: ['a.upload-pic'] },
  };
  await assert.rejects(() => executor.uploadImages(adapter, MEDIA), /editor_dom_changed/);
  assert.ok(calls.some(([method, params]) => method === 'Page.setInterceptFileChooserDialog' && params.enabled === false));
});

test('waitForRemoteImages keeps the legacy behavior without uploadReady', async () => {
  const executor = makeExecutor();
  executor.exec = async () => ({ urls: ['https://cdn.example/1.png'], positive: false, marker: false });
  const urls = await executor.waitForRemoteImages({ bodySelectors: ['#body'] }, 1);
  assert.deepEqual(urls, ['https://cdn.example/1.png']);
});

test('waitForRemoteImages positiveText waits for the success hint', async () => {
  const executor = makeExecutor();
  const states = [
    { urls: ['https://cdn.example/1.png'], positive: false, marker: false },
    { urls: ['https://cdn.example/1.png'], positive: true, marker: false },
  ];
  executor.exec = async () => states.shift() || states[0];
  const adapter = { bodySelectors: ['#body'], uploadReady: { positiveText: ['上传成功'], maxAttempts: 5, intervalMs: 1 } };
  const urls = await executor.waitForRemoteImages(adapter, 1);
  assert.deepEqual(urls, ['https://cdn.example/1.png']);
});

test('waitForRemoteImages seenThenGone waits for the progress marker to disappear', async () => {
  const executor = makeExecutor();
  const states = [
    { urls: [], positive: false, marker: true },
    { urls: ['https://cdn.example/1.png'], positive: false, marker: true },
    { urls: ['https://cdn.example/1.png'], positive: false, marker: false },
  ];
  executor.exec = async () => states.shift() || states[0];
  const adapter = { bodySelectors: ['#body'], uploadReady: { seenThenGone: ['上传中'], maxAttempts: 5, intervalMs: 1 } };
  const urls = await executor.waitForRemoteImages(adapter, 1);
  assert.deepEqual(urls, ['https://cdn.example/1.png']);
});

test('waitForRemoteImages times out when the ready marker never clears', async () => {
  const executor = makeExecutor();
  executor.exec = async () => ({ urls: ['https://cdn.example/1.png'], positive: false, marker: true });
  const adapter = { bodySelectors: ['#body'], uploadReady: { seenThenGone: ['上传中'], maxAttempts: 3, intervalMs: 1 } };
  await assert.rejects(() => executor.waitForRemoteImages(adapter, 1), /draft_image_mismatch/);
});
