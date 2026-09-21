'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { DraftRunner, assertDraftReadback } = require('../src/draft-runner.cjs');

test('titleless platform skips fillTitle and title readback enforcement', async () => {
  const calls = [];
  const adapter = { editorUrl: 'https://weibo.com', titleSelectors: [], bodySelectors: ['textarea'] };
  const registry = {
    get: () => adapter,
    assertAllowedUrl: (_platform, url) => url,
  };
  const payload = {
    title: '微博不需要标题字段',
    body_plain: '微博正文',
    media_manifest: [],
  };
  const executor = {
    detectLogin: async () => ({ loggedIn: true, captcha: false, observedIdentity: 'homepage:mock-account' }),
    openEditor: async () => calls.push('open'),
    fillTitle: async () => calls.push('title'),
    fillBody: async () => calls.push('body'),
    uploadImages: async () => calls.push('images'),
    saveDraft: async () => { calls.push('save'); return { id: 'draft-1', url: 'https://weibo.com/drafts/draft-1' }; },
    reopenDraft: async () => calls.push('reopen'),
    readDraft: async () => ({ title: '', textHash: '', headings: [], imageCount: 0, mediaReceipts: [] }),
    show: async () => calls.push('show'),
  };
  let draftReceipt;
  const api = {
    claim: async () => ({ publication: { id: 41, revision: 2, publication_payload: payload } }),
    draftReceipt: async (_id, receipt) => { draftReceipt = receipt; return { publication: { id: 41, revision: 3, status: 'draft_filled' } }; },
    reportAccount: async () => undefined,
  };
  const runner = new DraftRunner(registry, api, { forAccount: async () => executor });

  const result = await runner.run({ id: 41, revision: 1, platform: 'weibo', status: 'ready' }, {
    id: 9,
    editor_url: adapter.editorUrl,
    homepage_identifier: 'mock-account',
  });

  assert.equal(result.status, 'draft_saved');
  assert.deepEqual(calls, ['open', 'body', 'images', 'save', 'reopen', 'show']);
  assert.deepEqual(draftReceipt.filled_fields, ['body']);
});

test('assertDraftReadback skips the title check only for titleless adapters', () => {
  const payload = { title: '期望标题', media_manifest: [] };
  const readback = { title: '', mediaReceipts: [] };
  assert.doesNotThrow(() => assertDraftReadback(payload, readback, { titleSelectors: [] }));
  assert.throws(() => assertDraftReadback(payload, readback, { titleSelectors: ['#title'] }), /draft_title_mismatch/);
  // 不传 adapter 时保持原有行为：标题必须一致
  assert.throws(() => assertDraftReadback(payload, readback), /draft_title_mismatch/);
  assert.doesNotThrow(() => assertDraftReadback(payload, { title: '期望标题', mediaReceipts: [] }));
});
