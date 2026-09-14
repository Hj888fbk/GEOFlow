'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { findPublisherDeepLink, parsePublisherDeepLink } = require('../src/deep-link.cjs');

test('desktop login deep links are limited to the remembered instance and numeric account', () => {
  const link = 'geoflow-publisher://login?instance=http%3A%2F%2F127.0.0.1%3A28080&account=12';
  assert.deepEqual(parsePublisherDeepLink(link, 'http://127.0.0.1:28080'), { instance: 'http://127.0.0.1:28080', accountId: 12 });
  assert.equal(findPublisherDeepLink(['publisher.exe', link]), link);
  assert.throws(() => parsePublisherDeepLink(link, 'https://other.example'), /publisher_instance_mismatch/);
  assert.throws(() => parsePublisherDeepLink('geoflow-publisher://login?instance=http://127.0.0.1:28080&account=0', 'http://127.0.0.1:28080'), /publisher_account_invalid/);
  assert.throws(() => parsePublisherDeepLink('geoflow-publisher://publish?instance=http://127.0.0.1:28080&account=12', 'http://127.0.0.1:28080'), /publisher_deep_link_blocked/);
});
