const SELF_MEDIA_BODY_HTML_TAGS = new Set([
    'h1', 'h2', 'h3', 'h4', 'p', 'strong', 'em', 'b',
    'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'td', 'th',
    'blockquote', 'br', 'hr',
]);

// 只允许正文结构标签：剥离其余标签但保留文本；script/style 连同内容删除；img 一律删除
// （图片由运营人工上传，正文里的【图片N】占位文本段落会自然保留）。
export function sanitizeSelfMediaBodyHtml(html) {
    let result = String(html ?? '');
    result = result.replace(/<!--[\s\S]*?-->/g, '');
    result = result.replace(/<(script|style)\b[^>]*>[\s\S]*?<\/\1\s*>/gi, '');
    result = result.replace(/<(script|style)\b[^>]*>[\s\S]*$/gi, '');
    result = result.replace(/<img\b[^>]*>/gi, '');
    result = result.replace(/<(\/?)([a-zA-Z][a-zA-Z0-9]*)[^>]*>/g, (match, closingSlash, name) => {
        const tag = name.toLowerCase();
        if (! SELF_MEDIA_BODY_HTML_TAGS.has(tag)) return '';
        if (tag === 'br' || tag === 'hr') return closingSlash ? '' : `<${tag}>`;
        return `<${closingSlash ? '/' : ''}${tag}>`;
    });
    return result.trim();
}

function plainTextFromBodyHtml(html) {
    return String(html ?? '')
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<\/(?:h[1-4]|p|li|tr|blockquote|table|ul|ol)>/gi, '\n')
        .replace(/<\/?[a-zA-Z][a-zA-Z0-9]*[^>]*>/g, '')
        .replace(/&nbsp;/gi, ' ')
        .replace(/&lt;/gi, '<')
        .replace(/&gt;/gi, '>')
        .replace(/&quot;/gi, '"')
        .replace(/&#0?39;|&apos;/gi, "'")
        .replace(/&amp;/gi, '&')
        .replace(/[ \t]*\n[ \t]*/g, '\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

export function runSelfMediaArticleAdapter(action, payload, account, replaceExisting = false) {
    const configs = {
        baijiahao_article: {
            hosts: ['baijiahao.baidu.com'],
            title: ['input[placeholder*="标题"]', 'textarea[placeholder*="标题"]', '[data-testid="article-title"] input'],
            summary: ['textarea[placeholder*="摘要"]', 'textarea[placeholder*="简介"]'],
            body: ['[contenteditable="true"][role="textbox"]', '.ProseMirror[contenteditable="true"]', '.editor-content[contenteditable="true"]'],
            tags: ['input[placeholder*="标签"]', 'input[placeholder*="关键词"]'],
            profile: ['a[href*="/bjournal/profile"]', 'a[href*="/user/home"]', '[data-user-profile]'],
            publicPath: /^\/s(?:\?|\/)|^\/s$/,
        },
        sohu_media_article: {
            hosts: ['mp.sohu.com'],
            title: ['input[placeholder*="标题"]', 'textarea[placeholder*="标题"]', '.article-title input'],
            summary: ['textarea[placeholder*="摘要"]', 'textarea[placeholder*="简介"]'],
            body: ['.ProseMirror[contenteditable="true"]', '[contenteditable="true"][role="textbox"]', '.ql-editor[contenteditable="true"]'],
            tags: ['input[placeholder*="标签"]', 'input[placeholder*="关键词"]'],
            profile: ['a[href*="/media/"]', 'a[href*="/profile"]', '[data-user-profile]'],
            publicPath: /^\/a\/\d+/,
        },
    };
    const config = configs[action];
    if (! config) return { ok: false, code: 'adapter_not_implemented' };

    const host = window.location.hostname.toLowerCase();
    if (! config.hosts.some((candidate) => host === candidate || host.endsWith(`.${candidate}`))) {
        return { ok: false, code: 'wrong_platform' };
    }
    if (document.querySelector('iframe[src*="captcha"], [class*="Captcha"], [class*="captcha"], [id*="captcha"], [class*="verify"]')) {
        return { ok: false, code: 'human_verification_required' };
    }

    const normalizeProfile = (value) => {
        try {
            if (! String(value ?? '').trim()) return '';
            const url = new URL(value, window.location.origin);
            url.search = '';
            url.hash = '';
            return url.toString().replace(/\/$/, '').toLowerCase();
        } catch { return ''; }
    };
    const expectedProfile = normalizeProfile(account?.profile_url ?? '');
    const expectedUid = String(account?.account_uid ?? '').trim();
    const expectedHomepage = String(account?.homepage_identifier ?? '').trim();
    let observedProfileUrl = '';
    let observedIdentity = '';
    for (const selector of config.profile) {
        const node = document.querySelector(selector);
        if (! node) continue;
        observedProfileUrl = normalizeProfile(node.href ?? node.dataset?.userProfile ?? '');
        observedIdentity = String(node.dataset?.userId ?? node.textContent ?? '').trim();
        break;
    }
    if (! observedProfileUrl && ! observedIdentity) return { ok: false, code: 'login_required' };
    const profileMatches = expectedProfile && observedProfileUrl === expectedProfile;
    const uidMatches = expectedUid && observedIdentity.includes(expectedUid);
    const homepageMatches = expectedHomepage && (observedProfileUrl.includes(expectedHomepage.toLowerCase()) || observedIdentity.includes(expectedHomepage));
    if (! profileMatches && ! uidMatches && ! homepageMatches) {
        return { ok: false, code: 'account_mismatch', observedProfileUrl, observedIdentity };
    }

    const first = (selectors) => {
        for (const selector of selectors) {
            const node = document.querySelector(selector);
            if (node) return node;
        }
        return null;
    };
    const title = first(config.title);
    const body = first(config.body);
    if (! title || ! body) return { ok: false, code: 'editor_dom_changed' };
    const summary = first(config.summary);
    const tags = first(config.tags);
    const existingTitle = String(title.value ?? title.textContent ?? '').trim();
    const existingBody = String(body.value ?? body.textContent ?? '').trim();
    const existingSummary = String(summary?.value ?? summary?.textContent ?? '').trim();
    const existingTags = String(tags?.value ?? tags?.textContent ?? '').trim();
    if ((existingTitle || existingBody || existingSummary || existingTags) && ! replaceExisting) {
        return { ok: false, code: 'editor_not_empty' };
    }

    const dispatch = (node, kind = 'input') => {
        const EventConstructor = window.InputEvent ?? window.Event;
        node.dispatchEvent(new EventConstructor(kind, { bubbles: true, inputType: 'insertText' }));
    };
    const fill = (node, value) => {
        node.focus?.();
        if ('value' in node) node.value = value;
        else {
            const inserted = typeof document.execCommand === 'function'
                && document.execCommand('insertText', false, value);
            if (! inserted) node.textContent = value;
        }
        dispatch(node);
        dispatch(node, 'change');
    };
    // 富文本正文走粘贴事件：ProseMirror/contenteditable 自行把 text/html 解析成文档结构，
    // 标题、表格、加粗等格式得以保留；编辑器未消费该事件时退化为 insertHTML / 纯文本。
    const fillBodyWithHtml = (node, html, plainText) => {
        node.focus?.();
        let handled = false;
        const DataTransferConstructor = globalThis.DataTransfer;
        const ClipboardEventConstructor = globalThis.ClipboardEvent;
        if (typeof DataTransferConstructor === 'function' && typeof ClipboardEventConstructor === 'function') {
            try {
                const clipboard = new DataTransferConstructor();
                clipboard.setData('text/html', html);
                clipboard.setData('text/plain', plainText);
                const pasteEvent = new ClipboardEventConstructor('paste', {
                    bubbles: true,
                    cancelable: true,
                    clipboardData: clipboard,
                });
                if (! pasteEvent.clipboardData) {
                    Object.defineProperty(pasteEvent, 'clipboardData', { value: clipboard });
                }
                node.dispatchEvent(pasteEvent);
                handled = Boolean(pasteEvent.defaultPrevented) || String(node.textContent ?? '').trim() !== '';
            } catch {
                handled = false;
            }
        }
        if (! handled) {
            const inserted = typeof document.execCommand === 'function'
                && document.execCommand('insertHTML', false, html);
            if (! inserted) node.textContent = plainText;
        }
        dispatch(node);
        dispatch(node, 'change');
    };
    const titleText = String(payload?.title ?? '').trim();
    const summaryText = String(payload?.summary ?? '').trim();
    // body_html 非空时走 HTML 粘贴路径；消毒后没有实际文本（例如只剩图片）则回退纯文本。
    const rawBodyHtml = typeof payload?.body_html === 'string' ? payload.body_html.trim() : '';
    let bodyHtml = rawBodyHtml ? sanitizeSelfMediaBodyHtml(rawBodyHtml) : '';
    if (bodyHtml && ! plainTextFromBodyHtml(bodyHtml)) bodyHtml = '';
    const bodyText = bodyHtml
        ? plainTextFromBodyHtml(bodyHtml)
        : String(payload?.body_plain ?? payload?.body_markdown ?? '').trim();
    const bodyPlainText = String(payload?.body_plain ?? '').trim() || bodyText;
    const tagsText = Array.isArray(payload?.tags) ? payload.tags.map(String).map((tag) => tag.trim()).filter(Boolean).join('、') : '';
    if (! titleText || ! bodyText) return { ok: false, code: 'empty_content' };

    const exceedsLimit = (node, value) => Number(node?.maxLength ?? -1) > -1
        && Number(node.maxLength) > 0
        && [...value].length > Number(node.maxLength);
    if (exceedsLimit(title, titleText)
        || exceedsLimit(body, bodyText)
        || (summary && summaryText && exceedsLimit(summary, summaryText))
        || (tags && tagsText && exceedsLimit(tags, tagsText))) {
        return { ok: false, code: 'field_limit_exceeded' };
    }

    fill(title, titleText);
    if (bodyHtml && ! ('value' in body)) fillBodyWithHtml(body, bodyHtml, bodyPlainText);
    else fill(body, bodyText);
    const filledFields = ['title', 'body'];
    if (summary && summaryText) {
        fill(summary, summaryText);
        filledFields.push('summary');
    }
    if (tags && tagsText) {
        fill(tags, tagsText);
        filledFields.push('tags');
    }

    return {
        ok: true,
        code: 'draft_filled',
        observedProfileUrl,
        observedIdentity,
        accountProof: profileMatches
            ? observedProfileUrl
            : uidMatches
                ? `uid:${expectedUid.toLowerCase()}`
                : `homepage:${expectedHomepage.toLowerCase()}`,
        filledFields,
        characterCount: bodyText.length,
    };
}

export async function observeSelfMediaArticleResult(action) {
    const url = new URL(window.location.href);
    const isPublicUrl = action === 'baijiahao_article'
        ? url.hostname.endsWith('baijiahao.baidu.com') && /^\/s(?:\?|\/)|^\/s$/.test(url.pathname + url.search)
        : action === 'sohu_media_article'
            ? url.hostname.endsWith('sohu.com') && /^\/a\/\d+/.test(url.pathname)
            : false;
    if (! isPublicUrl) {
        return { outcome: 'outcome_unknown', completionUrl: null, readbackStatus: null, readbackSucceeded: false };
    }

    try {
        const response = await fetch(url.toString(), {
            method: 'GET',
            credentials: 'omit',
            redirect: 'follow',
            cache: 'no-store',
        });
        const finalUrl = new URL(response.url || url.toString());
        const finalUrlIsPublic = action === 'baijiahao_article'
            ? finalUrl.hostname.endsWith('baijiahao.baidu.com') && /^\/s(?:\?|\/)|^\/s$/.test(finalUrl.pathname + finalUrl.search)
            : finalUrl.hostname.endsWith('sohu.com') && /^\/a\/\d+/.test(finalUrl.pathname);
        const readbackSucceeded = response.status === 200 && finalUrlIsPublic;

        return {
            outcome: readbackSucceeded ? 'completed' : 'outcome_unknown',
            completionUrl: readbackSucceeded ? finalUrl.toString() : null,
            readbackStatus: response.status,
            readbackSucceeded,
        };
    } catch {
        return { outcome: 'outcome_unknown', completionUrl: null, readbackStatus: null, readbackSucceeded: false };
    }
}
