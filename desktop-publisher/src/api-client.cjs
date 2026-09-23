'use strict';

const crypto = require('node:crypto');

class GeoFlowApiClient {
  constructor(fetchImpl, instance = 'http://127.0.0.1:28080', token = null, version = '0.1.0') {
    this.fetch = fetchImpl;
    this.instance = normalizeInstance(instance);
    this.token = token;
    this.version = version;
  }

  async request(path, options = {}) {
    const {
      idempotent = false,
      retryAttempts = 2,
      headers: suppliedHeaders = {},
      body,
      ...fetchOptions
    } = options;
    const method = String(fetchOptions.method || 'GET').toUpperCase();
    const canRetry = idempotent || method === 'GET' || method === 'HEAD';
    const idempotencyKey = idempotent
      ? (suppliedHeaders['X-Idempotency-Key'] || crypto.randomUUID())
      : null;
    const requestBody = body && typeof body !== 'string' ? JSON.stringify(body) : body;
    const attempts = canRetry ? Math.max(0, Number(retryAttempts) || 0) : 0;

    for (let attempt = 0; attempt <= attempts; attempt += 1) {
      try {
        const response = await this.fetch(`${this.instance}/api/v1/${path.replace(/^\//, '')}`, {
          ...fetchOptions,
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-GEOFlow-Browser-Protocol': '2',
            'X-GEOFlow-Client-Version': this.version,
            ...(this.token ? { Authorization: `Bearer ${this.token}` } : {}),
            ...(idempotencyKey ? { 'X-Idempotency-Key': idempotencyKey } : {}),
            ...suppliedHeaders,
          },
          body: requestBody,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
          const error = new Error(payload?.error?.message || `HTTP ${response.status}`);
          error.code = payload?.error?.code || `http_${response.status}`;
          error.status = response.status;
          if (attempt < attempts && isTransientStatus(response.status)) {
            await retryDelay(attempt);
            continue;
          }
          throw error;
        }
        return payload.data;
      } catch (error) {
        if (attempt < attempts && isTransientError(error)) {
          await retryDelay(attempt);
          continue;
        }
        throw error;
      }
    }

    throw coded('request_retry_exhausted');
  }

  createDeviceAuthorization() {
    return this.request('browser-operations/device-authorizations', {
      method: 'POST',
      body: {
        client_name: 'GEOFlow Desktop Publisher',
        client_type: 'desktop',
        capabilities: ['accounts:v1', 'draft-sync:v2', 'session-isolation', 'public-url-readback'],
      },
    });
  }

  exchangeDeviceCode(deviceCode) {
    return this.request('browser-operations/device-token', { method: 'POST', body: { device_code: deviceCode } });
  }

  session() { return this.request('browser-operations/session'); }
  listAccounts() { return this.request('browser-operations/accounts'); }
  listPersonas() { return this.request('browser-operations/personas'); }
  createAccount(body) { return this.request('browser-operations/accounts', { method: 'POST', idempotent: true, body }); }
  deleteAccount(accountId) { return this.request(`browser-operations/accounts/${Number(accountId)}`, { method: 'DELETE', idempotent: true }); }
  desktopUpdate() { return this.request('browser-operations/desktop-update'); }
  async download(url) {
    const response = await this.fetch(url, { headers: {
      Authorization: `Bearer ${this.token}`,
      'X-GEOFlow-Browser-Protocol': '2',
      'X-GEOFlow-Client-Version': this.version,
    } });
    if (!response.ok) throw new Error(`update_download_http_${response.status}`);
    return Buffer.from(await response.arrayBuffer());
  }
  async downloadMedia(path) {
    const url = new URL(path, this.instance);
    if (url.origin !== this.instance || !/^\/api\/v1\/manual-publications\/\d+\/media\/[a-zA-Z0-9_-]+$/.test(url.pathname)) {
      throw coded('media_download_url_blocked');
    }
    const response = await this.fetch(url.toString(), { headers: {
      Accept: 'application/octet-stream',
      Authorization: `Bearer ${this.token}`,
      'X-GEOFlow-Browser-Protocol': '2',
      'X-GEOFlow-Client-Version': this.version,
    } });
    if (!response.ok) throw coded(`media_download_http_${response.status}`);
    return {
      buffer: Buffer.from(await response.arrayBuffer()),
      mimeType: response.headers.get('content-type') || 'application/octet-stream',
      sha256: String(response.headers.get('x-content-sha256') || '').toLowerCase(),
    };
  }
  async readPublicUrl(candidate, validateUrl = null) {
    let url = new URL(candidate);
    for (let redirectCount = 0; redirectCount <= 5; redirectCount += 1) {
      if (url.protocol !== 'https:') throw coded('public_url_blocked');
      if (validateUrl) url = new URL(validateUrl(url.toString()));
      const response = await this.fetch(url.toString(), {
        method: 'GET',
        redirect: 'manual',
        headers: { Accept: 'text/html,application/xhtml+xml' },
      });
      const location = response.headers?.get?.('location');
      if ([301, 302, 303, 307, 308].includes(response.status) && location) {
        if (redirectCount === 5) throw coded('public_url_redirect_limit');
        url = new URL(location, url);
        continue;
      }
      return { status: response.status, ok: response.status === 200, url: response.url || url.toString() };
    }
    throw coded('public_url_redirect_limit');
  }
  async listQueue(accountIds = []) {
    const result = await this.request(`manual-publications?account_ids=${accountIds.join(',')}&per_page=50`);
    if (!Array.isArray(result.items) || result.items.length === 0) {
      const error = new Error('no_ready_publications');
      error.code = 'no_ready_publications';
      throw error;
    }
    return result;
  }
  claim(item) { return this.request(`manual-publications/${item.id}/claim`, { method: 'POST', idempotent: true, body: { revision: item.revision } }); }
  release(itemId, revision) { return this.request(`manual-publications/${itemId}/release`, { method: 'POST', idempotent: true, body: { revision } }); }
  draftReceipt(itemId, receipt) { return this.request(`manual-publications/${itemId}/draft-receipt`, { method: 'POST', idempotent: true, body: receipt }); }
  finalReceipt(itemId, receipt) { return this.request(`manual-publications/${itemId}/receipt`, { method: 'POST', idempotent: true, body: receipt }); }
  adapterFailure(itemId, receipt) { return this.request(`manual-publications/${itemId}/adapter-failure`, { method: 'POST', idempotent: true, body: receipt }); }
  reportAccount(accountId, body) { return this.request(`browser-operations/accounts/${accountId}/status`, { method: 'POST', idempotent: true, body }); }
  bindAccount(accountId, observedAccountHash, observedAccount = null, replaceExisting = false) {
    return this.request(`browser-operations/accounts/${accountId}/bind`, {
      method: 'POST',
      idempotent: true,
      body: {
        confirmed: true,
        observed_account_hash: observedAccountHash,
        replace_existing: Boolean(replaceExisting),
        ...(observedAccount ? { observed_account: observedAccount } : {}),
      },
    });
  }
}

function normalizeInstance(value) {
  const url = new URL(value || 'http://127.0.0.1:28080');
  if (url.protocol !== 'https:' && !(url.protocol === 'http:' && ['127.0.0.1', 'localhost', '::1'].includes(url.hostname))) throw new Error('insecure_geoflow_instance');
  return url.origin;
}

function isTransientStatus(status) {
  // 429：接口限流是瞬时的，退避后重试（幂等端点可安全重放）。
  return [429, 502, 503, 504].includes(Number(status));
}

function isTransientError(error) {
  return !error?.status || isTransientStatus(error.status);
}

function retryDelay(attempt) {
  return new Promise((resolve) => setTimeout(resolve, 250 * (attempt + 1)));
}

function coded(code) { const error = new Error(code); error.code = code; return error; }

module.exports = { GeoFlowApiClient, normalizeInstance };
