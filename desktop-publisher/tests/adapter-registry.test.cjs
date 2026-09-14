'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { AdapterRegistry, REQUIRED_PLATFORMS } = require('../src/adapter-registry.cjs');

test('all ten first-release adapters satisfy the draft-only contract', () => {
  const registry = new AdapterRegistry();
  assert.equal(REQUIRED_PLATFORMS.length, 10);
  for (const platform of REQUIRED_PLATFORMS) {
    const adapter = registry.get(platform);
    assert.ok(adapter.draftSelectors.length > 0);
    assert.equal(Object.hasOwn(adapter, 'publishSelector'), false);
    assert.doesNotThrow(() => registry.assertAllowedUrl(platform, adapter.editorUrl));
  }
  assert.match(registry.digest, /^[a-f0-9]{64}$/);
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
