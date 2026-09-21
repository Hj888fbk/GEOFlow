'use strict';

const crypto = require('node:crypto');

const STRUCTURAL_FAILURES = new Set([
  'editor_dom_changed', 'draft_readback_empty', 'draft_title_mismatch', 'draft_text_mismatch', 'draft_heading_mismatch',
  'draft_image_mismatch', 'draft_image_order_mismatch', 'article_permission_required',
]);

class DraftRunner {
  constructor(registry, api, windows, observer = null, adapterVersion = '0.1.0', options = {}) {
    this.registry = registry;
    this.api = api;
    this.windows = windows;
    this.observer = observer;
    this.adapterVersion = adapterVersion;
    this.retryDelaysMs = Array.isArray(options.retryDelaysMs) ? options.retryDelaysMs : [1000, 2000];
    this.sleep = typeof options.sleep === 'function' ? options.sleep : defaultSleep;
  }

  async run(publication, account) {
    const adapter = this.registry.get(publication.platform);
    const executor = await this.windows.forAccount(account, adapter);
    const login = await executor.detectLogin(adapter);
    if (login.captcha || !login.loggedIn) {
      await executor.show();
      await this.api.reportAccount(account.id, { status: 'action_required', last_error_code: login.captcha ? 'captcha_required' : 'login_required' });
      return { status: 'action_required' };
    }

    const observedHash = accountIdentityHash(account, login.observedIdentity);
    if (!observedHash) {
      await executor.show();
      await this.api.reportAccount(account.id, { status: 'action_required', last_error_code: 'account_mismatch' });
      return { status: 'account_mismatch' };
    }

    if (publication.status === 'draft_filled') {
      this.observer?.watch(publication, account, adapter, executor, observedHash);
      await executor.show();
      return { status: 'awaiting_manual_publish' };
    }

    const claimed = (await this.api.claim(publication)).publication;
    const payload = claimed.publication_payload;
    let draftSaved = false;
    try {
      const media = await this.downloadMedia(payload.media_manifest || []);
      await executor.openEditor(this.registry.assertAllowedUrl(publication.platform, account.editor_url || adapter.editorUrl));
      await executor.fillTitle(adapter, payload.title || '');
      await executor.fillBody(adapter, payload.body_html || payload.body_markdown || payload.body_plain || '');
      await executor.uploadImages(adapter, media);
      const draft = await executor.saveDraft(adapter);
      draftSaved = true;
      await executor.reopenDraft(adapter, draft);
      const readback = await executor.readDraft(adapter);
      assertDraftReadback(payload, readback);
      const receipt = {
        revision: claimed.revision,
        adapter_version: this.adapterVersion,
        target_origin: new URL(account.editor_url || adapter.editorUrl).origin,
        observed_account_hash: observedHash,
        filled_fields: ['title', 'body', ...(readback.imageCount ? ['images'] : [])],
        persistence: 'remote_saved',
        draft_id: draft.id,
        draft_url: draft.url,
        rendered_text_hash: readback.textHash,
        heading_outline: readback.headings || [],
        expected_image_count: (payload.media_manifest || []).filter((item) => (item.role || 'body') === 'body').length,
        observed_image_count: readback.imageCount,
        media_upload_receipts: readback.mediaReceipts || [],
        finished_at: new Date().toISOString(),
      };
      const stored = (await this.api.draftReceipt(claimed.id, receipt)).publication;
      await this.api.reportAccount(account.id, { status: 'authorized', observed_account_hash: observedHash });
      this.observer?.watch({ ...stored, draft: { draft_url: draft.url } }, account, adapter, executor, observedHash);
      await executor.show();
      return { status: 'draft_saved', draftUrl: draft.url };
    } catch (error) {
      await executor.show();
      await this.api.reportAccount(account.id, { status: 'action_required', observed_account_hash: observedHash, last_error_code: error.code || 'adapter_failure' });
      if (STRUCTURAL_FAILURES.has(error.code)) {
        await this.api.adapterFailure(claimed.id, {
          revision: claimed.revision,
          adapter_version: this.adapterVersion,
          error_code: error.code,
          target_origin: new URL(account.editor_url || adapter.editorUrl).origin,
          finished_at: new Date().toISOString(),
        }).catch(() => undefined);
      } else if (!draftSaved) {
        await this.api.release(claimed.id, claimed.revision).catch(() => undefined);
      }
      throw error;
    }
  }

  async downloadMedia(manifest) {
    const required = [...manifest]
      .filter((item) => (item.role || 'body') === 'body' && item.required !== false)
      .sort((left, right) => Number(left.position || 0) - Number(right.position || 0));
    const media = [];
    for (const item of required) {
      if (!item.media_key || !item.download_path || !item.sha256) throw coded('media_manifest_invalid');
      media.push(await this.downloadMediaItem(item));
    }
    return media;
  }

  async downloadMediaItem(item) {
    const expected = String(item.sha256).toLowerCase();
    let lastError = null;
    for (let attempt = 0; attempt <= this.retryDelaysMs.length; attempt += 1) {
      try {
        const downloaded = await this.api.downloadMedia(item.download_path);
        const digest = crypto.createHash('sha256').update(downloaded.buffer).digest('hex');
        if (digest !== expected || (downloaded.sha256 && downloaded.sha256 !== expected)) throw coded('media_hash_mismatch');
        return { ...item, ...downloaded, sha256: digest };
      } catch (error) {
        lastError = error;
        if (attempt < this.retryDelaysMs.length) await this.sleep(this.retryDelaysMs[attempt]);
      }
    }
    lastError.attempts = this.retryDelaysMs.length + 1;
    throw lastError;
  }
}

function accountIdentityHash(account, observedIdentity) {
  const observed = String(observedIdentity || '').trim().toLowerCase();
  const candidates = [
    account.profile_url ? normalizeProfileUrl(account.profile_url) : null,
    account.account_uid ? `uid:${String(account.account_uid).trim().toLowerCase()}` : null,
    account.homepage_identifier ? `homepage:${String(account.homepage_identifier).trim().toLowerCase()}` : null,
  ].filter(Boolean);
  const matched = candidates.find((candidate) => candidate === observed || candidate.endsWith(observed));
  return matched ? crypto.createHash('sha256').update(matched).digest('hex') : null;
}

function observedAccountHash(observedAccount) {
  if (!observedAccount || !['profile_url', 'account_uid', 'homepage_identifier'].includes(observedAccount.type)) return null;
  let proof;
  if (observedAccount.type === 'profile_url') proof = normalizeProfileUrl(observedAccount.value);
  else if (observedAccount.type === 'account_uid') proof = `uid:${String(observedAccount.value).trim().toLowerCase()}`;
  else proof = `homepage:${String(observedAccount.value).trim().toLowerCase()}`;
  return proof.endsWith(':') ? null : crypto.createHash('sha256').update(proof).digest('hex');
}

function normalizeProfileUrl(value) {
  const url = new URL(value);
  return `${url.protocol.toLowerCase()}//${url.host.toLowerCase()}${url.pathname.replace(/\/$/, '').toLowerCase()}`;
}

function assertDraftReadback(payload, readback) {
  const expectedTitle = String(payload.title || '').trim();
  if (!readback || String(readback.title || '').trim() !== expectedTitle) throw coded('draft_title_mismatch');
  const expectedTextHash = String(payload.render_fingerprint?.text_sha256 || '').toLowerCase();
  if (expectedTextHash && String(readback.textHash || '').toLowerCase() !== expectedTextHash) throw coded('draft_text_mismatch');
  const expectedHeadings = payload.render_fingerprint?.heading_outline || [];
  if (JSON.stringify(readback.headings || []) !== JSON.stringify(expectedHeadings)) throw coded('draft_heading_mismatch');
  const expectedImages = (payload.media_manifest || []).filter((item) => (item.role || 'body') === 'body').map((item) => item.sha256);
  const observedImages = (readback.mediaReceipts || []).map((item) => item.source_sha256);
  if (expectedImages.join('|') !== observedImages.join('|')) throw coded('draft_image_order_mismatch');
}

function coded(code) { const error = new Error(code); error.code = code; return error; }

function defaultSleep(ms) { return new Promise((resolve) => setTimeout(resolve, ms)); }

module.exports = { DraftRunner, STRUCTURAL_FAILURES, accountIdentityHash, observedAccountHash, assertDraftReadback };
