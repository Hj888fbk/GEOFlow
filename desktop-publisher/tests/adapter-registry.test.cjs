'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { AdapterRegistry, REQUIRED_PLATFORMS } = require('../src/adapter-registry.cjs');

test('all eleven first-release adapters satisfy the draft-only contract', () => {
  const registry = new AdapterRegistry();
  assert.equal(REQUIRED_PLATFORMS.length, 11);
  for (const platform of REQUIRED_PLATFORMS) {
    const adapter = registry.get(platform);
    assert.ok(adapter.draftSelectors.length > 0);
    assert.equal(Object.hasOwn(adapter, 'publishSelector'), false);
    assert.doesNotThrow(() => registry.assertAllowedUrl(platform, adapter.editorUrl));
  }
  assert.match(registry.digest, /^[a-f0-9]{64}$/);
});

test('weibo adapter targets the article editor and satisfies the contract', () => {
  const registry = new AdapterRegistry();
  const adapter = registry.get('weibo');
  assert.ok(adapter.titleSelectors.length > 0);
  assert.ok(adapter.bodySelectors.length > 0);
  assert.equal(adapter.upload.mode, 'input');
  assert.equal(Object.hasOwn(adapter, 'publishSelector'), false);
  assert.doesNotThrow(() => registry.assertAllowedUrl('weibo', 'https://weibo.com'));
  assert.doesNotThrow(() => registry.assertAllowedUrl('weibo', 'https://card.weibo.com/article/v5/editor#/'));
});

test('contract rejects invalid upload strategies', () => {
  const base = {
    label: 'x', hosts: ['example.com'], editorUrl: 'https://example.com/edit',
    loginMarkers: ['a'], captchaMarkers: ['a'], identitySelectors: ['a'],
    titleSelectors: [], bodySelectors: ['a'], imageInputSelectors: ['a'],
    draftSelectors: ['a'], draftListSelectors: ['a'],
  };
  const withUpload = (upload) => {
    const definitions = {};
    for (const platform of REQUIRED_PLATFORMS) definitions[platform] = platform === 'weibo' ? { ...base, upload } : new AdapterRegistry().get(platform);
    return new AdapterRegistry(definitions);
  };
  assert.doesNotThrow(() => withUpload({ mode: 'input' }));
  assert.doesNotThrow(() => withUpload({ mode: 'button-then-input', buttonSelectors: ['button.insert'] }));
  assert.doesNotThrow(() => withUpload({ mode: 'filechooser', triggerSelectors: ['button.pic'] }));
  assert.throws(() => withUpload({ mode: 'teleport' }), /invalid_adapter:weibo:upload\.mode/);
  assert.throws(() => withUpload({ mode: 'button-then-input' }), /invalid_adapter:weibo:upload\.buttonSelectors/);
});

test('contract rejects malformed uploadReady config', () => {
  const registry = new AdapterRegistry();
  const definitions = {};
  for (const platform of REQUIRED_PLATFORMS) definitions[platform] = registry.get(platform);
  definitions.weibo = { ...registry.get('weibo'), uploadReady: { positiveText: ['上传成功', ''] } };
  assert.throws(() => new AdapterRegistry(definitions), /invalid_adapter:weibo:uploadReady\.positiveText/);
});

test('navigation and new-window policy are default deny', () => {
  const registry = new AdapterRegistry();
  assert.throws(() => registry.assertAllowedUrl('csdn', 'https://evil.example/editor'), /navigation_blocked/);
  const source = fs.readFileSync(path.join(__dirname, '..', 'src', 'main.cjs'), 'utf8');
  assert.match(source, /contextIsolation:\s*true/);
  assert.match(source, /nodeIntegration:\s*false/);
  assert.match(source, /sandbox:\s*true/);
  assert.match(source, /setWindowOpenHandler/);
  assert.match(source, /action:\s*'deny'/);
  assert.doesNotMatch(source, /setWindowOpenHandler[\s\S]{0,200}shell\.openExternal/);
});
