'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { GeoFlowApiClient } = require('../src/api-client.cjs');

test('public readback validates every redirect before making the next request', async () => {
  const requested = [];
  const client = new GeoFlowApiClient(async (url, options) => {
    requested.push({ url, redirect: options.redirect });
    return {
      status: 302,
      url,
      headers: { get: (name) => name === 'location' ? 'https://untrusted.example/article/1' : null },
    };
  });
  const validateUrl = (url) => {
    if (new URL(url).hostname !== 'trusted.example') {
      const error = new Error('navigation_blocked');
      error.code = 'navigation_blocked';
      throw error;
    }
    return url;
  };

  await assert.rejects(
    () => client.readPublicUrl('https://trusted.example/article/1', validateUrl),
    /navigation_blocked/,
  );
  assert.deepEqual(requested, [{ url: 'https://trusted.example/article/1', redirect: 'manual' }]);
});

test('desktop session validation and explicit account replacement use authenticated v2 requests', async () => {
  const requested = [];
  const client = new GeoFlowApiClient(async (url, options) => {
    requested.push({ url, options });
    return {
      ok: true,
      json: async () => ({ success: true, data: { accepted: true } }),
    };
  }, 'http://127.0.0.1:28080', 'desktop-token', '0.3.0');

  await client.session();
  await client.bindAccount(8, 'a'.repeat(64), {
    type: 'homepage_identifier',
    value: 'hengjia-rubber',
  }, true);

  assert.equal(requested[0].url, 'http://127.0.0.1:28080/api/v1/browser-operations/session');
  assert.equal(requested[0].options.headers.Authorization, 'Bearer desktop-token');
  assert.equal(requested[0].options.headers['X-GEOFlow-Client-Version'], '0.3.0');
  assert.equal(requested[1].url, 'http://127.0.0.1:28080/api/v1/browser-operations/accounts/8/bind');
  assert.deepEqual(JSON.parse(requested[1].options.body), {
    confirmed: true,
    observed_account_hash: 'a'.repeat(64),
    replace_existing: true,
    observed_account: {
      type: 'homepage_identifier',
      value: 'hengjia-rubber',
    },
  });
});

test('an empty publication queue reports that no reviewed drafts are ready', async () => {
  const client = new GeoFlowApiClient(async () => ({
    ok: true,
    json: async () => ({ success: true, data: { items: [], pagination: { total: 0 } } }),
  }), 'http://127.0.0.1:28080', 'desktop-token', '0.3.0');

  await assert.rejects(client.listQueue([1, 5]), (error) => error.code === 'no_ready_publications');
});

test('an idempotent bind retries a transient gateway restart with the same key', async () => {
  const requests = [];
  const client = new GeoFlowApiClient(async (url, options) => {
    requests.push({ url, options });
    if (requests.length === 1) {
      return {
        ok: false,
        status: 502,
        json: async () => ({}),
      };
    }
    return {
      ok: true,
      status: 200,
      json: async () => ({ success: true, data: { session: { status: 'authorized' } } }),
    };
  }, 'http://127.0.0.1:28080', 'desktop-token', '0.3.1');

  await client.bindAccount(9, 'b'.repeat(64), {
    type: 'homepage_identifier',
    value: '恒佳供水',
  });

  assert.equal(requests.length, 2);
  assert.equal(
    requests[0].options.headers['X-Idempotency-Key'],
    requests[1].options.headers['X-Idempotency-Key'],
  );
});
