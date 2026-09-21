'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { platforms } = require('../src/platforms.cjs');

test('weibo adapter targets the weibo.com composer and has no title field', () => {
  const weibo = platforms.weibo;
  assert.equal(weibo.label, '微博');
  assert.ok(weibo.hosts.includes('weibo.com'));
  assert.ok(weibo.hosts.includes('www.weibo.com'));
  assert.equal(weibo.editorUrl, 'https://weibo.com');
  assert.deepEqual([...weibo.titleSelectors], []);
  assert.ok(weibo.bodySelectors.some((selector) => selector.includes('分享新鲜事')));
  assert.ok(weibo.draftSelectors.length > 0);
});

test('every platform defaults to the input upload strategy', () => {
  for (const [name, adapter] of Object.entries(platforms)) {
    assert.equal(adapter.upload.mode, 'input', `${name} should default to input upload`);
  }
});

test('captcha markers cover the reinforced verification prompts', () => {
  const weibo = platforms.weibo;
  for (const marker of ['text=安全验证', 'text=百度安全验证', 'text=请完成验证', 'text=实名验证']) {
    assert.ok(weibo.captchaMarkers.includes(marker), `missing captcha marker ${marker}`);
  }
});

test('uploadReady is opt-in and absent by default', () => {
  for (const adapter of Object.values(platforms)) {
    assert.equal(Object.hasOwn(adapter, 'uploadReady'), false);
  }
});
