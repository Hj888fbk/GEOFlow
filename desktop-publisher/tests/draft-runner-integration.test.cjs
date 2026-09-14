'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const test = require('node:test');
const { DraftRunner } = require('../src/draft-runner.cjs');

test('simulated platform completes login, fill, save, reopen and draft receipt without publishing', async () => {
  const calls = [];
  const adapter = { editorUrl: 'https://mock.example/editor', titleSelectors: ['#title'], bodySelectors: ['#body'] };
  const registry = {
    get: () => adapter,
    assertAllowedUrl: (_platform, url) => url,
  };
  const payload = {
    title: '模拟平台标题',
    body_html: '<h2>结构</h2><p>正文</p>',
    media_manifest: [],
    render_fingerprint: { text_sha256: crypto.createHash('sha256').update('结构 正文').digest('hex'), heading_outline: [{ level: 2, text: '结构' }] },
  };
  const executor = {
    detectLogin: async () => ({ loggedIn: true, captcha: false, observedIdentity: 'homepage:mock-account' }),
    openEditor: async () => calls.push('open'),
    fillTitle: async () => calls.push('title'),
    fillBody: async () => calls.push('body'),
    uploadImages: async () => calls.push('images'),
    saveDraft: async () => { calls.push('save'); return { id: 'draft-1', url: 'https://mock.example/drafts/draft-1' }; },
    reopenDraft: async () => calls.push('reopen'),
    readDraft: async () => ({
      title: payload.title,
      textHash: payload.render_fingerprint.text_sha256,
      headings: payload.render_fingerprint.heading_outline,
      imageCount: 0,
      mediaReceipts: [],
    }),
    show: async () => calls.push('show'),
  };
  let draftReceipt;
  const api = {
    claim: async () => ({ publication: { id: 41, revision: 2, publication_payload: payload } }),
    draftReceipt: async (_id, receipt) => { draftReceipt = receipt; return { publication: { id: 41, revision: 3, status: 'draft_filled', target_url: adapter.editorUrl } }; },
    reportAccount: async () => undefined,
  };
  let watched = false;
  const observer = { watch: () => { watched = true; } };
  const runner = new DraftRunner(registry, api, { forAccount: async () => executor }, observer);

  const result = await runner.run({ id: 41, revision: 1, platform: 'mock', status: 'ready' }, {
    id: 9,
    editor_url: adapter.editorUrl,
    homepage_identifier: 'mock-account',
  });

  assert.equal(result.status, 'draft_saved');
  assert.deepEqual(calls, ['open', 'title', 'body', 'images', 'save', 'reopen', 'show']);
  assert.equal(draftReceipt.persistence, 'remote_saved');
  assert.equal(draftReceipt.draft_id, 'draft-1');
  assert.equal(watched, true);
  assert.equal(Object.hasOwn(executor, 'publish'), false);
});

test('protected media is downloaded in body order and hash checked before upload', async () => {
  const first = Buffer.from('first-image');
  const second = Buffer.from('second-image');
  const items = [
    { media_key: 'second', position: 2, role: 'body', required: true, sha256: sha(second), download_path: '/api/v1/manual-publications/1/media/second' },
    { media_key: 'first', position: 1, role: 'body', required: true, sha256: sha(first), download_path: '/api/v1/manual-publications/1/media/first' },
  ];
  const api = {
    downloadMedia: async (path) => {
      const buffer = path.endsWith('/first') ? first : second;
      return { buffer, mimeType: 'image/png', sha256: sha(buffer) };
    },
  };
  const runner = new DraftRunner({}, api, {});

  const media = await runner.downloadMedia(items);
  assert.deepEqual(media.map((item) => item.media_key), ['first', 'second']);

  api.downloadMedia = async () => ({ buffer: Buffer.from('tampered'), mimeType: 'image/png', sha256: '' });
  await assert.rejects(() => runner.downloadMedia([items[0]]), /media_hash_mismatch/);
});

function sha(buffer) { return crypto.createHash('sha256').update(buffer).digest('hex'); }
