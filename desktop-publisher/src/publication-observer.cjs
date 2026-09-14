'use strict';

class PublicationResultObserver {
  constructor(api, registry, adapterVersion = '0.1.0') {
    this.api = api;
    this.registry = registry;
    this.adapterVersion = adapterVersion;
    this.watchers = new Map();
  }

  watch(publication, account, adapter, executor, observedAccountHash) {
    const key = String(publication.id);
    if (this.watchers.has(key)) return;

    const state = { busy: false, stopped: false, unsubscribe: null };
    const inspect = async () => {
      if (state.busy || state.stopped) return;
      state.busy = true;
      try {
        const draftUrl = publication.draft?.draft_url || publication.target_url || adapter.editorUrl;
        const outcome = await executor.inspectPublicationOutcome(adapter, draftUrl);
        if (!outcome?.attempted) return;
        const receipt = await this.receiptForOutcome(publication, account, outcome, observedAccountHash);
        await this.api.finalReceipt(publication.id, receipt);
        this.stop(key);
      } catch (error) {
        if (['revision_conflict', 'claim_owned_by_another_client', 'publication_not_found'].includes(error.code)) this.stop(key);
      } finally {
        state.busy = false;
      }
    };

    state.unsubscribe = executor.onNavigation(inspect);
    this.watchers.set(key, state);
    void inspect();
  }

  stop(publicationId) {
    const key = String(publicationId);
    const state = this.watchers.get(key);
    if (!state) return;
    state.stopped = true;
    state.unsubscribe?.();
    this.watchers.delete(key);
  }

  async receiptForOutcome(publication, account, outcome, observedAccountHash) {
    const base = {
      revision: publication.revision,
      adapter_version: this.adapterVersion,
      target_origin: new URL(account.editor_url).origin,
      observed_account_hash: observedAccountHash,
      finished_at: new Date().toISOString(),
    };
    if (!outcome.publicUrl) {
      return { ...base, outcome: 'outcome_unknown', error_code: 'public_url_not_detected', result_note: '平台页面显示发布完成，但未能确定公开 URL，请人工核验。' };
    }

    let candidate;
    try {
      candidate = this.registry.assertAllowedUrl(publication.platform, outcome.publicUrl);
    } catch {
      return { ...base, outcome: 'outcome_unknown', error_code: 'public_url_host_mismatch', result_note: '检测到的结果 URL 不属于当前平台，请人工核验。' };
    }
    let readback;
    try {
      readback = await this.api.readPublicUrl(
        candidate,
        (url) => this.registry.assertAllowedUrl(publication.platform, url),
      );
    } catch (error) {
      return {
        ...base,
        outcome: 'outcome_unknown',
        completion_url: candidate,
        error_code: error.code || 'public_url_readback_failed',
        result_note: '检测到公开 URL，但安全回读失败，请人工核验。',
        public_url_readback_succeeded: false,
        public_url_readback_url: candidate,
      };
    }
    let finalUrl = candidate;
    try { finalUrl = this.registry.assertAllowedUrl(publication.platform, readback.url || candidate); } catch { /* 保留已校验的候选 URL */ }
    if (readback.status !== 200 || !readback.ok) {
      return {
        ...base,
        outcome: 'outcome_unknown',
        completion_url: finalUrl,
        error_code: 'public_url_readback_failed',
        result_note: `检测到公开 URL，但匿名回读返回 HTTP ${readback.status}，请人工核验。`,
        public_url_readback_status: readback.status,
        public_url_readback_succeeded: false,
        public_url_readback_url: finalUrl,
      };
    }

    return {
      ...base,
      outcome: 'completed',
      completion_url: finalUrl,
      result_note: '桌面发布助手检测到人工发布结果，公开 URL 匿名回读为 HTTP 200。',
      public_url_readback_status: 200,
      public_url_readback_succeeded: true,
      public_url_readback_url: finalUrl,
    };
  }
}

module.exports = { PublicationResultObserver };
