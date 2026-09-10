/**
 * 只做账号核验、不碰编辑器的轻量注入函数。
 * 供「手动粘贴草稿→发布→回填 URL」的路径补验账号凭证用。
 * 同样会被 chrome.scripting.executeScript 序列化，必须完全自包含。
 */
export function verifySelfMediaAccount(action, account) {
    const configs = {
        zhihu_column_article: { hosts: ['zhihu.com'], profile: ['a[href*="/people/"]'] },
        csdn_article: { hosts: ['csdn.net'], profile: ['a[href*="blog.csdn.net/"]', 'a[href*="/user/"]'] },
        toutiao_article: { hosts: ['toutiao.com'], profile: ['a[href*="/profile_v4/"]', '[class*="user-name"]'] },
        netease_media_article: { hosts: ['mp.163.com'], profile: ['a[href*="account"]', '[class*="userName"]'] },
        qq_penguin_article: { hosts: ['om.qq.com', 'mp.qq.com'], profile: ['a[href*="account"]', '[class*="user-name"]'] },
        dayu_article: { hosts: ['mp.dayu.com'], profile: ['a[href*="account"]', '[class*="user-name"]'] },
        jianshu_article: { hosts: ['jianshu.com'], profile: ['a[href*="/u/"]'] },
        douyin_article: { hosts: ['douyin.com'], profile: ['a[href*="creator-micro"]', '[class*="user-name"]'] },
    };
    const config = configs[action];
    if (! config) return { ok: false, code: 'adapter_not_implemented' };
    const host = location.hostname.toLowerCase();
    if (! config.hosts.some((item) => host === item || host.endsWith(`.${item}`))) return { ok: false, code: 'wrong_platform' };
    if (document.querySelector('iframe[src*="captcha"], [class*="Captcha"], [class*="captcha"], [id*="captcha"], [class*="verify"]')) return { ok: false, code: 'human_verification_required' };
    let observedProfileUrl = '';
    let observedIdentity = '';
    for (const selector of config.profile) {
        const node = document.querySelector(selector);
        if (node) {
            observedProfileUrl = String(node.href ?? '').split(/[?#]/)[0].replace(/\/$/, '').toLowerCase();
            observedIdentity = String(node.dataset?.userId ?? node.textContent ?? '').trim();
            break;
        }
    }
    if (! observedProfileUrl && ! observedIdentity) return { ok: false, code: 'login_required' };
    const expectedProfile = String(account?.profile_url ?? '').split(/[?#]/)[0].replace(/\/$/, '').toLowerCase();
    const expectedUid = String(account?.account_uid ?? '').trim().toLowerCase();
    const expectedHomepage = String(account?.homepage_identifier ?? '').trim().toLowerCase();
    const proof = expectedProfile && observedProfileUrl === expectedProfile
        ? observedProfileUrl
        : expectedUid && observedIdentity.toLowerCase().includes(expectedUid)
            ? `uid:${expectedUid}`
            : expectedHomepage && `${observedProfileUrl} ${observedIdentity}`.toLowerCase().includes(expectedHomepage)
                ? `homepage:${expectedHomepage}`
                : '';
    if (! proof) return { ok: false, code: 'account_mismatch', observedProfileUrl, observedIdentity };

    return { ok: true, accountProof: proof, observedProfileUrl, observedIdentity };
}

/**
 * Experimental editor-only adapters for platforms without a verified stable draft API.
 * The function is deliberately self-contained because Chrome serializes it into the page.
 * It never searches for or clicks a save/publish control and only returns editor_filled.
 */
export function runSelfMediaDomDraft(action, payload, account, replaceExisting = false) {
    return (async () => {
        const configs = {
            zhihu_column_article: { hosts: ['zhihu.com'], title: ['textarea[placeholder*="标题"]', 'input[placeholder*="标题"]'], body: ['.ProseMirror[contenteditable="true"]', '[contenteditable="true"][role="textbox"]'], profile: ['a[href*="/people/"]'] },
            csdn_article: { hosts: ['csdn.net'], title: ['input[placeholder*="标题"]', 'textarea[placeholder*="标题"]'], body: ['.cledit-section[contenteditable="true"]', '.ProseMirror[contenteditable="true"]', 'textarea'], profile: ['a[href*="blog.csdn.net/"]', 'a[href*="/user/"]'] },
            toutiao_article: { hosts: ['toutiao.com'], title: ['textarea[placeholder*="标题"]', 'input[placeholder*="标题"]'], body: ['.ProseMirror[contenteditable="true"]', '[contenteditable="true"][role="textbox"]'], profile: ['a[href*="/profile_v4/"]', '[class*="user-name"]'] },
            netease_media_article: { hosts: ['mp.163.com'], title: ['input[placeholder*="标题"]', 'textarea[placeholder*="标题"]'], body: ['[data-contents="true"]', '.DraftEditor-editorContainer [contenteditable="true"]', '[contenteditable="true"][role="textbox"]'], profile: ['a[href*="account"]', '[class*="userName"]'] },
            qq_penguin_article: { hosts: ['om.qq.com', 'mp.qq.com'], title: ['input[placeholder*="标题"]', 'textarea[placeholder*="标题"]'], body: ['.ProseMirror[contenteditable="true"]', '[contenteditable="true"][role="textbox"]'], profile: ['a[href*="account"]', '[class*="user-name"]'] },
            dayu_article: { hosts: ['mp.dayu.com'], title: ['input[placeholder*="标题"]', 'textarea[placeholder*="标题"]'], body: ['iframe', '.edui-body-container[contenteditable="true"]', '[contenteditable="true"][role="textbox"]'], profile: ['a[href*="account"]', '[class*="user-name"]'] },
            jianshu_article: { hosts: ['jianshu.com'], title: ['textarea[placeholder*="标题"]', 'input[placeholder*="标题"]'], body: ['[contenteditable="true"][role="textbox"]', '.ProseMirror[contenteditable="true"]', 'textarea'], profile: ['a[href*="/u/"]'] },
            douyin_article: { hosts: ['douyin.com'], title: ['textarea[placeholder*="标题"]', 'input[placeholder*="标题"]'], body: ['.ProseMirror[contenteditable="true"]', '[contenteditable="true"][role="textbox"]'], profile: ['a[href*="creator-micro"]', '[class*="user-name"]'] },
        };
        const config = configs[action];
        if (! config) return { ok: false, code: 'adapter_not_implemented' };
        const host = location.hostname.toLowerCase();
        if (! config.hosts.some((item) => host === item || host.endsWith(`.${item}`))) return { ok: false, code: 'wrong_platform' };
        if (document.querySelector('iframe[src*="captcha"], [class*="Captcha"], [class*="captcha"], [id*="captcha"], [class*="verify"]')) return { ok: false, code: 'human_verification_required' };

        const first = (selectors, root = document) => {
            for (const selector of selectors) {
                const node = root.querySelector(selector);
                if (node) return node;
            }
            return null;
        };
        let observedProfileUrl = '';
        let observedIdentity = '';
        const profile = first(config.profile);
        if (profile) {
            observedProfileUrl = String(profile.href ?? '').split(/[?#]/)[0].replace(/\/$/, '').toLowerCase();
            observedIdentity = String(profile.dataset?.userId ?? profile.textContent ?? '').trim();
        }
        if (! observedProfileUrl && ! observedIdentity) return { ok: false, code: 'login_required' };
        const expectedProfile = String(account?.profile_url ?? '').split(/[?#]/)[0].replace(/\/$/, '').toLowerCase();
        const expectedUid = String(account?.account_uid ?? '').trim().toLowerCase();
        const expectedHomepage = String(account?.homepage_identifier ?? '').trim().toLowerCase();
        const proof = expectedProfile && observedProfileUrl === expectedProfile
            ? observedProfileUrl
            : expectedUid && observedIdentity.toLowerCase().includes(expectedUid)
                ? `uid:${expectedUid}`
                : expectedHomepage && `${observedProfileUrl} ${observedIdentity}`.toLowerCase().includes(expectedHomepage)
                    ? `homepage:${expectedHomepage}`
                    : '';
        if (! proof) return { ok: false, code: 'account_mismatch', observedProfileUrl, observedIdentity };

        const title = first(config.title);
        let body = first(config.body);
        if (body?.tagName === 'IFRAME') {
            try { body = body.contentDocument?.body ?? null; } catch { body = null; }
        }
        if (! title || ! body) return { ok: false, code: action === 'douyin_article' ? 'article_permission_required' : 'editor_dom_changed' };
        const existingTitle = String(title.value ?? title.textContent ?? '').trim();
        const existingBody = String(body.value ?? body.textContent ?? '').trim();
        if ((existingTitle || existingBody) && ! replaceExisting) return { ok: false, code: 'editor_not_empty' };

        const titleText = String(payload?.title ?? '').trim();
        let html = String(payload?.body_html ?? '').trim();
        const mediaData = Array.isArray(payload?._mediaData) ? payload._mediaData : [];
        const manifest = (Array.isArray(payload?.media_manifest) ? payload.media_manifest : [])
            .filter((item) => item && (item.role ?? 'body') === 'body')
            .sort((a, b) => Number(a.position ?? 0) - Number(b.position ?? 0));
        if (! titleText || ! html) return { ok: false, code: 'empty_content' };
        let markdownWithImages = String(payload?.body_markdown ?? payload?.body_plain ?? '');
        for (const item of manifest) {
            const key = String(item.media_key ?? '');
            const data = mediaData.find((entry) => String(entry?.media_key ?? '') === key);
            if (! key || ! data?.dataBase64 || String(data.sha256 ?? '').toLowerCase() !== String(item.sha256 ?? '').toLowerCase()) return { ok: false, code: 'media_download_incomplete' };
            const escaped = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const pattern = new RegExp(`<img\\b([^>]*?)data-geoflow-media-key=["']${escaped}["']([^>]*)>`, 'gi');
            if ((html.match(pattern) ?? []).length !== 1) return { ok: false, code: 'media_node_mismatch' };
            html = html.replace(pattern, `<img src="data:${data.mimeType};base64,${data.dataBase64}" data-geoflow-media-key="${key}" />`);
            markdownWithImages = markdownWithImages.replace(`{{media:${key}}}`, `![${String(item.name ?? '')}](data:${data.mimeType};base64,${data.dataBase64})`);
        }
        if ((html.match(/<img\b/gi) ?? []).length !== manifest.length) return { ok: false, code: 'media_node_mismatch' };

        const emit = (node) => {
            node.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText' }));
            node.dispatchEvent(new Event('change', { bubbles: true }));
        };
        title.focus();
        if ('value' in title) title.value = titleText; else title.textContent = titleText;
        emit(title);
        body.focus();
        if ('value' in body && action === 'csdn_article') {
            body.value = markdownWithImages;
        } else {
            const selection = window.getSelection();
            const range = document.createRange();
            range.selectNodeContents(body);
            selection.removeAllRanges();
            selection.addRange(range);
            const inserted = document.execCommand?.('insertHTML', false, html);
            if (! inserted) body.innerHTML = html;
        }
        emit(body);
        await new Promise((resolve) => setTimeout(resolve, 1200));
        const observedImages = 'value' in body
            ? (String(body.value).match(/!\[[^\]]*\]\(data:image\//g) ?? []).length
            : body.querySelectorAll('img').length;
        if (observedImages !== manifest.length) return { ok: false, code: 'draft_image_mismatch' };
        const headings = 'value' in body ? (payload?.render_fingerprint?.heading_outline ?? []) : [...body.querySelectorAll('h1,h2,h3,h4,h5,h6')].map((node) => ({ level: Number(node.tagName.slice(1)), text: String(node.textContent ?? '').replace(/\s+/g, ' ').trim() }));
        if (JSON.stringify(headings) !== JSON.stringify(payload?.render_fingerprint?.heading_outline ?? [])) return { ok: false, code: 'draft_heading_mismatch' };

        return {
            ok: true,
            code: 'editor_filled',
            persistence: 'editor_filled',
            observedProfileUrl,
            observedIdentity,
            accountProof: proof,
            filledFields: ['title', 'body', ...(manifest.length ? ['images'] : [])],
            renderedTextHash: null,
            headingOutline: headings,
            expectedImageCount: manifest.length,
            observedImageCount: observedImages,
            mediaUploadReceipts: [],
        };
    })().catch((error) => ({ ok: false, code: 'adapter_exception', error: String(error?.message ?? error) }));
}

export async function observeSelfMediaDomResult() {
    return { outcome: 'outcome_unknown', completionUrl: null, readbackStatus: null, readbackSucceeded: false };
}
