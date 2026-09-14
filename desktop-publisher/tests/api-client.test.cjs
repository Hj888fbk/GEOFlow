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
