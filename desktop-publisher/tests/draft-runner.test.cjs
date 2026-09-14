'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const test = require('node:test');
const { accountIdentityHash, assertDraftReadback, observedAccountHash } = require('../src/draft-runner.cjs');

test('account validation hashes only a configured public identifier', () => {
  const hash = accountIdentityHash({ homepage_identifier: 'HengJia' }, 'hengjia');
  assert.match(hash, /^[a-f0-9]{64}$/);
  assert.equal(accountIdentityHash({ homepage_identifier: 'HengJia' }, 'another-account'), null);
});

test('a detected public account identifier is normalized before confirmation', () => {
  assert.equal(
    observedAccountHash({ type: 'profile_url', value: 'HTTPS://BLOG.CSDN.NET/Example/' }),
    crypto.createHash('sha256').update('https://blog.csdn.net/example').digest('hex'),
  );
  assert.equal(
    observedAccountHash({ type: 'account_uid', value: ' GEOFlow-100 ' }),
    crypto.createHash('sha256').update('uid:geoflow-100').digest('hex'),
  );
  assert.equal(observedAccountHash({ type: 'cookie', value: 'secret' }), null);
});

test('draft readback enforces title and image hash order', () => {
  const payload = { title: '测试标题', media_manifest: [{ role: 'body', sha256: 'a' }, { role: 'body', sha256: 'b' }] };
  assert.doesNotThrow(() => assertDraftReadback(payload, { title: '测试标题', mediaReceipts: [{ source_sha256: 'a' }, { source_sha256: 'b' }] }));
  assert.throws(() => assertDraftReadback(payload, { title: '测试标题', mediaReceipts: [{ source_sha256: 'b' }, { source_sha256: 'a' }] }), /draft_image_order_mismatch/);
});
