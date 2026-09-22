'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

class AccountWindowManager {
  constructor(BrowserWindow, session, registry, options = {}) {
    this.BrowserWindow = BrowserWindow;
    this.session = session;
    this.registry = registry;
    this.diagnosticsDir = options.diagnosticsDir || null;
    this.windows = new Map();
  }

  accountKey(account) {
    return `${account.instance || 'local'}:${account.id}`;
  }

  async forAccount(account, adapter) {
    const key = this.accountKey(account);
    let win = this.windows.get(key);
    if (!win || win.isDestroyed()) {
      const digest = crypto.createHash('sha256').update(key).digest('hex').slice(0, 20);
      const partition = `persist:geoflow-${digest}`;
      win = new this.BrowserWindow({
        show: false,
        width: 1280,
        height: 900,
        webPreferences: { partition, contextIsolation: true, nodeIntegration: false, sandbox: true, webSecurity: true },
      });
      win.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
      win.webContents.on('will-navigate', (event, url) => {
        try { this.registry.assertAllowedUrl(account.platform, url); } catch { event.preventDefault(); }
      });
      this.windows.set(key, win);
    }
    const target = account.editor_url || adapter.editorUrl;
    if (!win.webContents.getURL()) await win.loadURL(this.registry.assertAllowedUrl(account.platform, target));
    return new WindowExecutor(win, { diagnosticsDir: this.diagnosticsDir });
  }

  // 工单结束后释放编辑器窗口。登录态保存在 persist: partition，销毁窗口不影响登录，
  // 也不影响 renderer 登录标签页的 webview（独立 webContents，共享同一 partition 会话）。
  release(account) {
    const key = this.accountKey(account);
    const win = this.windows.get(key);
    this.windows.delete(key);
    if (win && !win.isDestroyed()) {
      try { win.destroy(); } catch { /* 窗口可能已在关闭流程中 */ }
    }
  }
}

class WindowExecutor {
  constructor(win, options = {}) {
    this.win = win;
    this.diagnosticsDir = options.diagnosticsDir || null;
    this.mediaReceipts = [];
    this.temporaryMediaDirectory = null;
  }

  async show() { this.win.show(); this.win.focus(); }
  async openEditor(url) { if (this.win.webContents.getURL() !== url) await this.win.loadURL(url); }
  currentUrl() { return this.win.webContents.getURL(); }

  async detectLogin(adapter) {
    return this.exec(`(() => {
      const text = document.body?.innerText || '';
      const has = (selectors) => selectors.some((selector) => selector.startsWith('text=') ? text.includes(selector.slice(5)) : Boolean(document.querySelector(selector)));
      const captcha = has(${JSON.stringify(adapter.captchaMarkers)});
      const login = has(${JSON.stringify(adapter.loginMarkers)});
      const identityNode = ${JSON.stringify(adapter.identitySelectors)}.map((selector) => document.querySelector(selector)).find(Boolean);
      let observedAccount = null;
      if (identityNode) {
        const uid = identityNode.dataset?.accountId || identityNode.dataset?.userId || identityNode.dataset?.uid || '';
        if (uid) observedAccount = { type: 'account_uid', value: String(uid).trim() };
        else if (identityNode.href && /^https:\\/\\//i.test(identityNode.href)) observedAccount = { type: 'profile_url', value: identityNode.href };
        else if (identityNode.textContent?.trim()) observedAccount = { type: 'homepage_identifier', value: identityNode.textContent.trim() };
      }
      let observedIdentity = '';
      if (observedAccount?.type === 'profile_url') {
        const url = new URL(observedAccount.value);
        observedAccount.value = url.protocol.toLowerCase() + '//' + url.host.toLowerCase() + url.pathname.replace(/\\/$/, '').toLowerCase();
        observedIdentity = observedAccount.value;
      } else if (observedAccount?.type === 'account_uid') observedIdentity = 'uid:' + observedAccount.value.toLowerCase();
      else if (observedAccount?.type === 'homepage_identifier') observedIdentity = 'homepage:' + observedAccount.value.toLowerCase();
      return { loggedIn: !login && !captcha, captcha, observedIdentity, observedAccount };
    })()`);
  }

  async fillTitle(adapter, value) {
    // 无标题平台（如微博）：titleSelectors 为空时跳过填标题
    if (!adapter.titleSelectors?.length) return;
    return this.fillFirst(adapter.titleSelectors, value, false, 'fill_title', adapter);
  }
  async fillBody(adapter, value) { return this.fillFirst(adapter.bodySelectors, value, true, 'fill_body', adapter); }

  async uploadImages(adapter, media) {
    this.mediaReceipts = [];
    if (!media.length) return [];

    this.cleanupMediaFiles();
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'geoflow-publisher-media-'));
    this.temporaryMediaDirectory = directory;
    const filePaths = media.map((item, index) => {
      const extension = extensionForMime(item.mimeType || item.mime_type);
      const name = `${String(index + 1).padStart(3, '0')}-${safeFilePart(item.media_key)}.${extension}`;
      const filePath = path.join(directory, name);
      fs.writeFileSync(filePath, item.buffer, { mode: 0o600 });
      return filePath;
    });

    const mode = adapter.upload?.mode || 'input';
    let accepted = false;
    if (mode === 'button-then-input') {
      const clicked = await this.exec(selectorClickScript(adapter.upload.buttonSelectors || []));
      if (!clicked) await this.failWithDiagnostics('editor_dom_changed', 'upload_button', adapter);
      await delay(400);
      accepted = await this.setFileInputPaths(adapter.imageInputSelectors, filePaths);
    } else if (mode === 'filechooser') {
      accepted = await this.setFilesViaFileChooser(adapter, filePaths);
    } else {
      accepted = await this.setFileInputPaths(adapter.imageInputSelectors, filePaths);
    }
    if (!accepted) await this.failWithDiagnostics('editor_dom_changed', `upload_${mode}`, adapter);

    const uploadedUrls = await this.waitForRemoteImages(adapter, media.length);
    this.mediaReceipts = media.map((item, index) => ({
      media_key: String(item.media_key),
      source_sha256: String(item.sha256).toLowerCase(),
      platform_url: uploadedUrls[index],
    }));
    this.cleanupMediaFiles();
    return this.mediaReceipts;
  }

  async saveDraft(adapter) {
    const result = await this.exec(selectorClickScript(adapter.draftSelectors));
    if (!result) throw coded('editor_dom_changed');
    await delay(1200);
    const draft = await this.exec(`(() => {
      const value = (selector) => {
        const node = document.querySelector(selector);
        return String(node?.dataset?.draftId || node?.dataset?.articleId || node?.value || node?.getAttribute?.('content') || '').trim();
      };
      const url = new URL(location.href);
      const candidates = [
        value('[data-draft-id]'), value('[data-article-id]'), value('input[name="draftId"]'),
        value('input[name="articleId"]'), value('meta[name="draft-id"]'),
        url.searchParams.get('draftId'), url.searchParams.get('articleId'), url.searchParams.get('id'),
      ].filter(Boolean);
      if (!candidates.length) {
        const segment = url.pathname.split('/').filter(Boolean).at(-1) || '';
        if (/^[a-zA-Z0-9_-]{4,}$/.test(segment) && !/^(publish|write|edit|new|article)$/i.test(segment)) candidates.push(segment);
      }
      const canonical = document.querySelector('link[rel="canonical"]')?.href || '';
      return { id: candidates[0] || null, url: canonical || url.toString() };
    })()`);
    if (!draft?.id || !draft?.url) throw coded('draft_readback_empty');
    return draft;
  }

  async reopenDraft(adapter, draft) {
    if (draft?.url && draft.url !== this.currentUrl()) {
      await this.openEditor(draft.url);
      return;
    }
    const opened = await this.exec(`(() => {
      const selectors = ${JSON.stringify(adapter.draftListSelectors)};
      const expected = ${JSON.stringify(String(draft?.id || ''))};
      for (const selector of selectors) {
        let nodes = [];
        if (selector.startsWith('text=')) nodes = [...document.querySelectorAll('a,button')].filter((node) => node.textContent.includes(selector.slice(5)));
        else nodes = [...document.querySelectorAll(selector)];
        const node = nodes.find((candidate) => !expected || candidate.href?.includes(expected) || candidate.dataset?.draftId === expected) || nodes[0];
        if (node) { node.click(); return true; }
      }
      return false;
    })()`);
    if (!opened) throw coded('draft_readback_empty');
    await delay(800);
  }

  async readDraft(adapter) {
    const readback = await this.exec(`(() => {
      const first = (selectors) => selectors.map((selector) => document.querySelector(selector)).find(Boolean);
      const title = first(${JSON.stringify(adapter.titleSelectors)})?.value || '';
      const body = first(${JSON.stringify(adapter.bodySelectors)});
      const text = body?.innerText || body?.value || '';
      const headings = body && !('value' in body) ? [...body.querySelectorAll('h1,h2,h3,h4,h5,h6')].map((node) => ({
        level: Number(node.tagName.slice(1)), text: String(node.textContent || '').replace(/\\s+/g, ' ').trim(),
      })) : [];
      const imageUrls = body && !('value' in body) ? [...body.querySelectorAll('img')].map((node) => node.currentSrc || node.src).filter(Boolean) : [];
      return { title, text, headings, imageUrls };
    })()`);
    const canonicalText = String(readback?.text || '').replace(/\s+/gu, ' ').trim();
    return {
      title: readback?.title || '',
      textHash: crypto.createHash('sha256').update(canonicalText).digest('hex'),
      headings: readback?.headings || [],
      imageCount: (readback?.imageUrls || []).length,
      mediaReceipts: this.mediaReceipts,
    };
  }

  onNavigation(listener) {
    const wrapped = () => { void listener(); };
    this.win.webContents.on('did-navigate', wrapped);
    this.win.webContents.on('did-navigate-in-page', wrapped);
    return () => {
      this.win.webContents.removeListener('did-navigate', wrapped);
      this.win.webContents.removeListener('did-navigate-in-page', wrapped);
    };
  }

  async inspectPublicationOutcome(adapter, draftUrl) {
    return this.exec(`(() => {
      const current = location.href;
      const text = String(document.body?.innerText || '').replace(/\\s+/g, ' ');
      const success = /发布成功|发布完成|已成功发布|查看文章|查看作品/.test(text);
      const candidates = [
        document.querySelector('link[rel="canonical"]')?.href,
        document.querySelector('meta[property="og:url"]')?.content,
        ...[...document.querySelectorAll('a[href]')].filter((node) => /查看文章|查看作品|公开链接/.test(node.textContent || '')).map((node) => node.href),
        current,
      ].filter(Boolean);
      const editor = ${JSON.stringify(adapter.editorUrl)};
      const draft = ${JSON.stringify(String(draftUrl || ''))};
      const publicUrl = candidates.find((candidate) => {
        try {
          const url = new URL(candidate, location.href);
          if (url.toString() === editor || url.toString() === draft) return false;
          return !/(?:^|\\/)(?:publish|write|editor|edit|draft)(?:\\/|$)/i.test(url.pathname);
        } catch { return false; }
      }) || null;
      return { attempted: success || Boolean(publicUrl && current !== editor && current !== draft), publicUrl, success };
    })()`);
  }

  async fillFirst(selectors, value, html, stage = 'fill', adapter = null) {
    const result = await this.exec(`(() => {
      const element = ${JSON.stringify(selectors)}.map((selector) => document.querySelector(selector)).find(Boolean);
      if (!element) return false;
      element.focus();
      // 富文本编辑器（网易号标题框等）没有 .value：contenteditable 一律走节点内容赋值，
      // title 用 innerText（纯文本），body 用 innerHTML（保留排版）。
      if (element.isContentEditable) {
        if (${html ? 'true' : 'false'}) element.innerHTML = ${JSON.stringify(value)};
        else element.innerText = ${JSON.stringify(value)};
      } else {
        element.value = ${JSON.stringify(value)};
      }
      element.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText' }));
      element.dispatchEvent(new Event('change', { bubbles: true }));
      return true;
    })()`);
    if (!result) await this.failWithDiagnostics('editor_dom_changed', stage, adapter);
  }

  // 结构性失败前采集诊断：只收集 DOM 结构信息（文件输入框、按钮文本、URL、标题），
  // 不读取 cookie/token/页面正文；截图只存本地 diagnostics 目录，摘要随错误上报。
  async failWithDiagnostics(code, stage, adapter) {
    const error = coded(code);
    try {
      error.diagnostics = await this.collectDiagnostics(stage, adapter);
    } catch { /* 诊断采集失败不掩盖原始错误 */ }
    throw error;
  }

  async collectDiagnostics(stage, adapter) {
    const info = await this.exec(`(() => {
      const fileInputs = [...document.querySelectorAll('input[type="file"]')].map((node) => ({
        accept: String(node.getAttribute('accept') || '').slice(0, 80),
        visible: Boolean(node.offsetWidth || node.offsetHeight || node.getClientRects().length),
      }));
      const buttons = [...document.querySelectorAll('button')]
        .map((node) => String(node.textContent || '').replace(/\\s+/g, ' ').trim())
        .filter((text) => text && text.length <= 30 && /图片|上传|插入/.test(text))
        .slice(0, 10);
      return { url: String(location.href), title: String(document.title || '').slice(0, 120), fileInputs, buttons };
    })()`);
    let screenshotSaved = false;
    if (this.diagnosticsDir && typeof this.win.webContents.capturePage === 'function') {
      try {
        const image = await this.win.webContents.capturePage();
        const buffer = image?.toPNG ? image.toPNG() : image;
        if (buffer?.length) {
          this.saveDiagnosticScreenshot(stage, adapter, buffer);
          screenshotSaved = true;
        }
      } catch { /* 截图失败不影响诊断摘要 */ }
    }
    return buildDiagnosticsSummary(stage, info, screenshotSaved);
  }

  saveDiagnosticScreenshot(stage, adapter, buffer) {
    fs.mkdirSync(this.diagnosticsDir, { recursive: true });
    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    const platform = safeFileLabel(adapter?.label || 'unknown');
    const filePath = path.join(this.diagnosticsDir, `${stamp}-${platform}-${safeFileLabel(stage)}.png`);
    fs.writeFileSync(filePath, buffer);
    pruneDiagnosticScreenshots(this.diagnosticsDir, 20);
    return filePath;
  }

  async setFileInputPaths(selectors, filePaths) {
    const debug = this.win.webContents.debugger;
    if (!debug.isAttached()) debug.attach('1.3');
    const { root } = await debug.sendCommand('DOM.getDocument', { depth: 2, pierce: true });
    for (const selector of selectors) {
      const { nodeId } = await debug.sendCommand('DOM.querySelector', { nodeId: root.nodeId, selector });
      if (!nodeId) continue;
      await debug.sendCommand('DOM.setFileInputFiles', { files: filePaths, nodeId });
      return true;
    }
    return false;
  }

  // filechooser 模式：拦截文件选择框，等平台弹窗后用 backendNodeId 回填文件。
  async setFilesViaFileChooser(adapter, filePaths) {
    const triggerSelectors = adapter.upload?.triggerSelectors?.length ? adapter.upload.triggerSelectors : adapter.imageInputSelectors;
    const debug = this.win.webContents.debugger;
    if (!debug.isAttached()) debug.attach('1.3');
    await debug.sendCommand('Page.enable');
    await debug.sendCommand('Page.setInterceptFileChooserDialog', { enabled: true });
    try {
      const opened = new Promise((resolve) => {
        const cleanup = () => { clearTimeout(timer); debug.removeListener('message', listener); };
        const timer = setTimeout(() => { cleanup(); resolve(null); }, 8000);
        const listener = (_event, method, params) => {
          if (method !== 'Page.fileChooserOpened') return;
          cleanup();
          resolve(params || null);
        };
        debug.on('message', listener);
      });
      const clicked = await this.exec(selectorClickScript(triggerSelectors));
      if (!clicked) return false;
      const chooser = await opened;
      if (!chooser?.backendNodeId) return false;
      await debug.sendCommand('DOM.setFileInputFiles', { files: filePaths, backendNodeId: chooser.backendNodeId });
      return true;
    } finally {
      try { await debug.sendCommand('Page.setInterceptFileChooserDialog', { enabled: false }); } catch { /* 调试器可能已断开 */ }
    }
  }

  async waitForRemoteImages(adapter, expectedCount) {
    const ready = adapter.uploadReady || null;
    const positiveText = ready?.positiveText || [];
    const seenThenGone = ready?.seenThenGone || [];
    const maxAttempts = ready?.maxAttempts || 40;
    const intervalMs = ready?.intervalMs || 250;
    let markerSeen = false;
    for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
      const state = await this.exec(`(() => {
        const body = ${JSON.stringify(adapter.bodySelectors)}.map((selector) => document.querySelector(selector)).find(Boolean);
        const urls = body && !('value' in body) ? [...body.querySelectorAll('img')].map((node) => node.currentSrc || node.src).filter((url) => /^https:\\/\\//i.test(url)) : [];
        const text = String(document.body?.innerText || '');
        return {
          urls,
          positive: ${JSON.stringify(positiveText)}.some((marker) => text.includes(marker)),
          marker: ${JSON.stringify(seenThenGone)}.some((marker) => text.includes(marker)),
        };
      })()`);
      const urls = state?.urls || [];
      if (state?.marker) markerSeen = true;
      let readyOk = true;
      if (positiveText.length) readyOk = readyOk && Boolean(state?.positive);
      if (seenThenGone.length) readyOk = readyOk && markerSeen && !state?.marker;
      if (urls.length >= expectedCount && readyOk) return urls.slice(-expectedCount);
      await delay(intervalMs);
    }
    throw coded('draft_image_mismatch');
  }

  cleanupMediaFiles() {
    if (!this.temporaryMediaDirectory) return;
    fs.rmSync(this.temporaryMediaDirectory, { recursive: true, force: true });
    this.temporaryMediaDirectory = null;
  }

  exec(script) { return this.win.webContents.executeJavaScript(script, true); }
}

function selectorClickScript(selectors) {
  return `(() => { for (const selector of ${JSON.stringify(selectors)}) { let element; const match = selector.match(/^([^:]+):has-text\\("(.+)"\\)$/); if (match) element = [...document.querySelectorAll(match[1])].find((node) => node.textContent.includes(match[2])); else if (selector.startsWith('text=')) element = [...document.querySelectorAll('button,a')].find((node) => node.textContent.includes(selector.slice(5))); else element = document.querySelector(selector); if (element) { element.click(); return true; } } return false; })()`;
}

function extensionForMime(mimeType) {
  return ({ 'image/png': 'png', 'image/gif': 'gif', 'image/webp': 'webp', 'image/jpeg': 'jpg' })[String(mimeType).toLowerCase()] || 'bin';
}

function safeFilePart(value) { return String(value || 'image').replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 64) || 'image'; }

// 诊断截图文件名用：保留中日韩等 Unicode 字母（平台名是中文），去掉路径不安全字符
function safeFileLabel(value) { return String(value || 'unknown').replace(/[^\p{L}\p{N}_-]/gu, '').slice(0, 40) || 'unknown'; }

// 摘要限长 1000 字符，只含 stage/URL/标题/文件输入框结构/按钮文本，不含 cookie/token/正文
function buildDiagnosticsSummary(stage, info, screenshotSaved) {
  const fileInputs = Array.isArray(info?.fileInputs) ? info.fileInputs : [];
  const buttons = Array.isArray(info?.buttons) ? info.buttons : [];
  const inputsDetail = fileInputs.length
    ? `(${fileInputs.map((input) => `${input.visible ? 'visible' : 'hidden'}:${input.accept || 'any'}`).join(',')})`
    : '';
  return [
    `stage=${stage}`,
    `url=${String(info?.url || 'unknown')}`,
    `title=${String(info?.title || '')}`,
    `file_inputs=${fileInputs.length}${inputsDetail}`,
    `buttons=[${buttons.join('|')}]`,
    `screenshot=${screenshotSaved ? 'saved' : 'unavailable'}`,
  ].join(' ').slice(0, 1000);
}

// diagnostics 目录最多保留最近 keep 张截图，超出删最旧
function pruneDiagnosticScreenshots(directory, keep = 20) {
  let names;
  try { names = fs.readdirSync(directory).filter((name) => name.endsWith('.png')); } catch { return; }
  if (names.length <= keep) return;
  const entries = names.map((name) => {
    const filePath = path.join(directory, name);
    let mtime = 0;
    try { mtime = fs.statSync(filePath).mtimeMs; } catch { /* 读取失败按最旧处理 */ }
    return { filePath, mtime };
  }).sort((a, b) => a.mtime - b.mtime);
  for (const entry of entries.slice(0, entries.length - keep)) {
    try { fs.rmSync(entry.filePath, { force: true }); } catch { /* 删除失败下轮再清 */ }
  }
}

function delay(milliseconds) { return new Promise((resolve) => setTimeout(resolve, milliseconds)); }
function coded(code) { const error = new Error(code); error.code = code; return error; }

module.exports = { AccountWindowManager, WindowExecutor, extensionForMime, buildDiagnosticsSummary, pruneDiagnosticScreenshots };
