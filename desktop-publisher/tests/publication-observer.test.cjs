'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { PublicationResultObserver } = require('../src/publication-observer.cjs');

test('public result is completed only after an anonymous HTTP 200 readback', async () => {
  let receipt;
  const api = {
    readPublicUrl: async (url) => ({ status: 200, ok: true, url }),
    finalReceipt: async (_id, value) => { receipt = value; },
  };
  const registry = { assertAllowedUrl: (_platform, url) => url };
  const observer = new PublicationResultObserver(api, registry);
  const executor = {
    inspectPublicationOutcome: async () => ({ attempted: true, publicUrl: 'https://mock.example/posts/1' }),
    onNavigation: () => () => undefined,
  };
  observer.watch(
    { id: 1, revision: 3, platform: 'mock', target_url: 'https://mock.example/editor' },
    { editor_url: 'https://mock.example/editor' },
    { editorUrl: 'https://mock.example/editor' },
    executor,
    'a'.repeat(64),
  );

  await waitFor(() => receipt);
  assert.equal(receipt.outcome, 'completed');
  assert.equal(receipt.public_url_readback_status, 200);
  assert.equal(receipt.public_url_readback_succeeded, true);
  assert.equal(observer.watchers.size, 0);
});

test('ambiguous publish result is reported for manual verification', async () => {
  const observer = new PublicationResultObserver({}, { assertAllowedUrl: (_platform, url) => url });
  const receipt = await observer.receiptForOutcome(
    { revision: 8, platform: 'mock' },
    { editor_url: 'https://mock.example/editor' },
    { attempted: true, publicUrl: null },
    'b'.repeat(64),
  );

  assert.equal(receipt.outcome, 'outcome_unknown');
  assert.equal(receipt.error_code, 'public_url_not_detected');
  assert.equal(receipt.completion_url, undefined);
});

test('a blocked or failed anonymous readback becomes pending verification', async () => {
  const api = {
    readPublicUrl: async (_url, validateUrl) => {
      validateUrl('https://mock.example/posts/1');
      const error = new Error('navigation_blocked');
      error.code = 'navigation_blocked';
      throw error;
    },
  };
  const observer = new PublicationResultObserver(api, { assertAllowedUrl: (_platform, url) => url });
  const receipt = await observer.receiptForOutcome(
    { revision: 9, platform: 'mock' },
    { editor_url: 'https://mock.example/editor' },
    { attempted: true, publicUrl: 'https://mock.example/posts/1' },
    'c'.repeat(64),
  );

  assert.equal(receipt.outcome, 'outcome_unknown');
  assert.equal(receipt.error_code, 'navigation_blocked');
  assert.equal(receipt.public_url_readback_succeeded, false);
});

async function waitFor(predicate) {
  for (let attempt = 0; attempt < 20; attempt += 1) {
    if (predicate()) return;
    await new Promise((resolve) => setTimeout(resolve, 0));
  }
  throw new Error('condition_not_met');
}
