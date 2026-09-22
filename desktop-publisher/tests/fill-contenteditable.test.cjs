'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { platforms } = require('../src/platforms.cjs');
const { WindowExecutor } = require('../src/window-manager.cjs');

test('title selectors keep platform entries first and append contenteditable fallbacks', () => {
  const netease = platforms.netease_media;
  assert.equal(netease.titleSelectors[0], 'input[placeholder*="标题"]');
  assert.ok(netease.titleSelectors.includes('[contenteditable][placeholder*="标题"]'));
  assert.ok(netease.titleSelectors.includes('[contenteditable][data-placeholder*="标题"]'));
});

test('body selectors try placeholder-scoped editors before bare contenteditable', () => {
  for (const [name, adapter] of Object.entries(platforms)) {
    const bare = adapter.bodySelectors.indexOf('[contenteditable="true"]');
    const scoped = adapter.bodySelectors.findIndex((selector) => selector.includes('正文'));
    if (bare !== -1 && scoped !== -1) {
      assert.ok(scoped < bare, `${name} should try body-scoped selectors before bare contenteditable`);
    }
  }
});

test('fillTitle script handles contenteditable via innerText, not value assignment', async () => {
  let script = '';
  const win = { webContents: { executeJavaScript: async (source) => { script = source; return true; } } };
  const executor = new WindowExecutor(win);
  await executor.fillTitle({ titleSelectors: ['div[contenteditable]'] }, '标题文本');
  assert.ok(script.includes('element.isContentEditable'));
  assert.ok(script.includes('element.innerText'));
  assert.ok(!script.includes('if (true) element.innerHTML'), 'title fill must not use innerHTML');
});

test('fillBody script keeps innerHTML for rich text', async () => {
  let script = '';
  const win = { webContents: { executeJavaScript: async (source) => { script = source; return true; } } };
  const executor = new WindowExecutor(win);
  await executor.fillBody({ bodySelectors: ['div[contenteditable]'] }, '<p>正文</p>');
  assert.ok(script.includes('if (true) element.innerHTML'));
});

test('waitForEditorReady polls until an editor element appears', async () => {
  let calls = 0;
  const win = {
    webContents: {
      executeJavaScript: async () => {
        calls += 1;
        return calls >= 3;
      },
    },
  };
  const executor = new WindowExecutor(win);
  const ready = await executor.waitForEditorReady({ titleSelectors: ['input'], bodySelectors: [] }, 5000, 10);
  assert.equal(ready, true);
  assert.equal(calls, 3);
});

test('waitForEditorReady times out when the editor never renders', async () => {
  const win = { webContents: { executeJavaScript: async () => false } };
  const executor = new WindowExecutor(win);
  const ready = await executor.waitForEditorReady({ titleSelectors: ['input'], bodySelectors: [] }, 50, 10);
  assert.equal(ready, false);
});
