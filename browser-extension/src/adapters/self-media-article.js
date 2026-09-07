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
    const titleText = String(payload?.title ?? '').trim();
    const summaryText = String(payload?.summary ?? '').trim();
    const bodyText = String(payload?.body_plain ?? payload?.body_markdown ?? '').trim();
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
    fill(body, bodyText);
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
        characterCount: String(payload?.body_plain ?? '').length,
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
