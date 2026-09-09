/**
 * 自媒体草稿 API 适配器（百家号 / 搜狐号 / 简书 / CSDN）。
 *
 * 与平台自家编辑器使用同一套 Web API 存草稿：不做 DOM 填充，图片走平台上传接口。
 * 安全边界不变：只调用「保存草稿」接口，绝不调用发布接口。
 *
 * ⚠️ 该函数经 chrome.scripting.executeScript 序列化注入平台页面执行，
 *    函数体必须完全自包含：禁止引用任何模块级变量/导入，只允许使用参数与函数内部定义。
 */

export function runSelfMediaApiDraft(action, payload, account) {
    return (async () => {
        // ===== 以下为注入页面后运行的全部逻辑，保持自包含 =====
        const platform = String(action || '').replace(/_article$/, '');
        const title = String(payload?.title ?? '').trim();
        const bodyHtml = String(payload?.body_html ?? '').trim();
        if (! title || ! bodyHtml) return { ok: false, code: 'empty_content' };

        const expectedUid = String(account?.account_uid ?? '').trim();
        const expectedProfile = String(account?.profile_url ?? '').split(/[?#]/)[0].replace(/\/$/, '').toLowerCase();
        const expectedHomepage = String(account?.homepage_identifier ?? '').trim().toLowerCase();
        const isV3 = Number(payload?.schema_version ?? 1) >= 3;
        const manifest = Array.isArray(payload?.media_manifest) ? payload.media_manifest : [];
        const mediaData = Array.isArray(payload?._mediaData) ? payload._mediaData : [];

        const fetchJson = async (url, options = {}) => {
            const response = await fetch(url, Object.assign({ credentials: 'include' }, options));
            const text = await response.text();
            let data = null;
            try { data = JSON.parse(text.replace(/^[a-zA-Z_$][\w$]*\(/, '').replace(/\)\s*;?\s*$/, '')); } catch { /* 保留 null */ }
            return { status: response.status, text, data };
        };

        const base64ToBlob = (b64, type) => {
            const binary = atob(b64);
            const bytes = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
            return new Blob([bytes], { type: type || 'image/jpeg' });
        };

        const sha256 = async (value) => {
            const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(String(value)));
            return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
        };
        const inspectHtml = async (html) => {
            const documentNode = new DOMParser().parseFromString(String(html), 'text/html');
            const blocks = [...documentNode.querySelectorAll('h1,h2,h3,h4,h5,h6,p,li,blockquote,pre,tr')];
            const text = (blocks.length
                ? blocks.map((node) => String(node.textContent ?? '').replace(/\s+/g, ' ').trim()).filter(Boolean).join(' ')
                : String(documentNode.body?.textContent ?? '')
            ).replace(/\s+/g, ' ').trim();
            return {
                textHash: await sha256(text),
                headings: [...documentNode.querySelectorAll('h1,h2,h3,h4,h5,h6')].map((node) => ({
                    level: Number(node.tagName.slice(1)),
                    text: String(node.textContent ?? '').replace(/\s+/g, ' ').trim(),
                })),
                imageCount: documentNode.querySelectorAll('img').length,
            };
        };

        // 上传全部正文图片。任何必需图片缺失/失败立即终止，禁止部分成功。
        const uploadAll = async (uploadOne) => {
            const uploaded = {};
            const receipts = [];
            const bodyImages = manifest
                .filter((item) => item && (item.role ?? 'body') === 'body')
                .sort((a, b) => Number(a.position ?? 0) - Number(b.position ?? 0));
            for (const [index, item] of bodyImages.entries()) {
                const key = String(item?.media_key ?? (isV3 ? '' : index + 1));
                const data = mediaData.find((entry) => isV3
                    ? String(entry?.media_key ?? '') === key
                    : Number(entry?.image_id) === Number(item?.image_id));
                if (! key || ! data?.dataBase64) throw new Error(`media_missing:${key || 'unknown'}`);
                if (isV3 && String(data.sha256 ?? '').toLowerCase() !== String(item.sha256 ?? '').toLowerCase()) {
                    throw new Error(`media_hash_mismatch:${key}`);
                }
                const url = await uploadOne(data, item);
                if (! url) throw new Error(`media_upload_failed:${key}`);
                uploaded[key] = url;
                receipts.push({ media_key: key, source_sha256: data.sha256, platform_url: url });
            }
            if (Object.keys(uploaded).length !== bodyImages.length) throw new Error('media_upload_incomplete');
            return {
                uploaded,
                receipts,
                expectedCount: bodyImages.length,
                orderedUrls: bodyImages.map((item, index) => uploaded[String(item?.media_key ?? (isV3 ? '' : index + 1))]),
            };
        };

        // 使用稳定 media_key 重写统一文档图片节点，不依赖易错位的“图片N”。
        const applyImages = (html, uploaded) => {
            let result = html;
            for (const [key, url] of Object.entries(uploaded)) {
                if (! isV3) {
                    const legacy = new RegExp(`<p>\\s*【图片${key}】\\s*</p>|【图片${key}】`, 'g');
                    result = result.replace(legacy, `<p><img src="${url}" /></p>`);
                    continue;
                }
                const escaped = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                const pattern = new RegExp(`<img\\b([^>]*?)data-geoflow-media-key=["']${escaped}["']([^>]*)>`, 'gi');
                const matches = result.match(pattern) ?? [];
                if (matches.length !== 1) throw new Error(`media_node_mismatch:${key}`);
                result = result.replace(pattern, `<img src="${url}" data-geoflow-media-key="${key}" />`);
            }
            return result;
        };

        const validateReadback = async (readbackHtml, expectedCount, expectedImageUrls = []) => {
            if (! readbackHtml) throw new Error('draft_readback_empty');
            const observed = await inspectHtml(readbackHtml);
            const expected = payload?.render_fingerprint ?? {};
            if (! expected.text_sha256 || observed.textHash !== String(expected.text_sha256).toLowerCase()) {
                throw new Error('draft_text_mismatch');
            }
            if (JSON.stringify(observed.headings) !== JSON.stringify(expected.heading_outline ?? [])) {
                throw new Error('draft_heading_mismatch');
            }
            if (observed.imageCount !== expectedCount) throw new Error('draft_image_mismatch');
            const canonicalMediaUrl = (value) => {
                try {
                    const url = new URL(String(value), location.href);
                    url.search = '';
                    url.hash = '';
                    return url.toString();
                } catch { return String(value).split(/[?#]/)[0]; }
            };
            const observedUrls = [...new DOMParser().parseFromString(String(readbackHtml), 'text/html').querySelectorAll('img')]
                .map((node) => canonicalMediaUrl(node.getAttribute?.('src') ?? node.src ?? ''));
            const expectedUrls = expectedImageUrls.map(canonicalMediaUrl);
            if (JSON.stringify(observedUrls) !== JSON.stringify(expectedUrls)) throw new Error('draft_image_order_mismatch');
            return observed;
        };

        const provePageAccount = (selectors) => {
            if (typeof document === 'undefined' || typeof document.querySelectorAll !== 'function') {
                return { ok: false, code: 'login_required', observedIdentity: '', observedProfileUrl: '' };
            }
            const evidence = [];
            for (const selector of selectors) {
                for (const node of document.querySelectorAll(selector)) {
                    const rawHref = String(node?.href ?? node?.getAttribute?.('href') ?? '').trim();
                    let profileUrl = '';
                    try {
                        profileUrl = rawHref ? new URL(rawHref, location.href).toString().split(/[?#]/)[0].replace(/\/$/, '').toLowerCase() : '';
                    } catch { /* 忽略无效页面链接 */ }
                    const identity = String(node?.dataset?.userId ?? node?.dataset?.uid ?? node?.textContent ?? '').replace(/\s+/g, ' ').trim();
                    if (profileUrl || identity) evidence.push({ profileUrl, identity });
                }
            }
            const matched = evidence.find((item) => (
                (expectedProfile && item.profileUrl === expectedProfile)
                || (expectedUid && `${item.profileUrl} ${item.identity}`.toLowerCase().includes(expectedUid.toLowerCase()))
                || (expectedHomepage && `${item.profileUrl} ${item.identity}`.toLowerCase().includes(expectedHomepage))
            ));
            if (! matched) {
                const first = evidence[0] ?? { profileUrl: '', identity: '' };
                return {
                    ok: false,
                    code: evidence.length === 0 ? 'login_required' : 'account_mismatch',
                    observedIdentity: first.identity,
                    observedProfileUrl: first.profileUrl,
                };
            }
            const proof = expectedProfile && matched.profileUrl === expectedProfile
                ? matched.profileUrl
                : expectedUid && `${matched.profileUrl} ${matched.identity}`.toLowerCase().includes(expectedUid.toLowerCase())
                    ? `uid:${expectedUid.toLowerCase()}`
                    : `homepage:${expectedHomepage}`;
            return { ok: true, proof, observedIdentity: matched.identity, observedProfileUrl: matched.profileUrl };
        };

        const challenge = typeof document !== 'undefined'
            ? document.querySelector?.('iframe[src*="captcha"], [class*="Captcha"], [class*="captcha"], [id*="captcha"], [class*="verify"]')
            : null;
        if (challenge) return { ok: false, code: 'human_verification_required' };

        if (platform === 'baijiahao') {
            // 1. 登录态 + 账号核验（API 级，比 DOM 可靠）
            const auth = await fetchJson(`https://baijiahao.baidu.com/builder/app/appinfo?_=${Date.now()}`);
            const user = auth.data?.data?.user;
            if (! user?.userid) return { ok: false, code: 'login_required' };
            if (expectedUid && String(user.userid) !== expectedUid) {
                return { ok: false, code: 'account_mismatch', observedIdentity: String(user.userid), observedProfileUrl: String(user.name ?? '') };
            }

            // 2. 编辑器页取签名 token
            const editPage = await fetch(`https://baijiahao.baidu.com/builder/rc/edit`, { credentials: 'include' });
            const html = await editPage.text();
            const tokenMatch = html.match(/window\.__BJH__INIT__AUTH__\s*=\s*['"]([^'"]+)['"]/);
            if (! tokenMatch) return { ok: false, code: 'login_required' };
            const token = tokenMatch[1];

            // 3. 图片上传
            const uploadResult = await uploadAll(async (data) => {
                const form = new FormData();
                form.append('media', base64ToBlob(data.dataBase64, data.mimeType), 'image.jpg');
                form.append('type', 'image');
                form.append('app_id', '1589639493090963');
                form.append('is_waterlog', '1');
                form.append('save_material', '1');
                form.append('no_compress', '0');
                form.append('is_events', '');
                form.append('article_type', 'news');
                const res = await fetchJson('https://baijiahao.baidu.com/pcui/picture/uploadproxy', { method: 'POST', body: form });
                return res.data?.ret?.https_url || '';
            });
            const content = applyImages(bodyHtml, uploadResult.uploaded);

            // 4. 保存草稿
            const save = await fetchJson('https://baijiahao.baidu.com/pcui/article/save?callback=bjhdraft', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'token': token },
                body: new URLSearchParams({
                    title,
                    content,
                    feed_cat: '1',
                    len: String(content.length),
                    activity_list: JSON.stringify([{ id: 408, is_checked: 0 }]),
                    source_reprinted_allow: '0',
                    original_status: '0',
                    original_handler_status: '1',
                    isBeautify: 'false',
                    subtitle: '',
                    bjhtopic_id: '',
                    bjhtopic_info: '',
                    type: 'news',
                }).toString(),
            });
            const articleId = save.data?.ret?.article_id;
            if (save.data?.errmsg !== 'success' || ! articleId) {
                return { ok: false, code: 'draft_save_failed', error: String(save.data?.errmsg || 'unknown') };
            }
            if (! isV3) return {
                ok: true, code: 'draft_filled', draftUrl: `https://baijiahao.baidu.com/builder/rc/edit?type=news&article_id=${articleId}`,
                observedIdentity: String(user.userid), observedProfileUrl: String(user.name ?? ''),
                accountProof: `uid:${String(user.userid).toLowerCase()}`, filledFields: ['title', 'body', 'images'],
                imageCount: uploadResult.expectedCount,
            };
            const readback = await fetchJson(`https://baijiahao.baidu.com/pcui/article/get?article_id=${encodeURIComponent(articleId)}&type=news&_=${Date.now()}`);
            const readbackHtml = readback.data?.ret?.content ?? readback.data?.data?.content ?? '';
            const observed = await validateReadback(readbackHtml, uploadResult.expectedCount, uploadResult.orderedUrls);
            return {
                ok: true,
                code: 'draft_saved',
                persistence: 'remote_saved',
                draftId: String(articleId),
                draftUrl: `https://baijiahao.baidu.com/builder/rc/edit?type=news&article_id=${articleId}`,
                observedIdentity: String(user.userid),
                observedProfileUrl: String(user.name ?? ''),
                accountProof: `uid:${String(user.userid).toLowerCase()}`,
                filledFields: ['title', 'body', 'images'],
                renderedTextHash: observed.textHash,
                headingOutline: observed.headings,
                expectedImageCount: uploadResult.expectedCount,
                observedImageCount: observed.imageCount,
                mediaUploadReceipts: uploadResult.receipts,
            };
        }

        if (platform === 'sohu_media') {
            // 1. 登录态 + 账号核验
            const auth = await fetchJson(`https://mp.sohu.com/mpbp/bp/account/list?_=${Date.now()}`);
            const groups = auth.data?.data?.data;
            const accounts = [];
            for (const group of Array.isArray(groups) ? groups : []) {
                for (const acc of Array.isArray(group?.accounts) ? group.accounts : []) accounts.push(acc);
            }
            if (accounts.length === 0) return { ok: false, code: 'login_required' };
            const current = expectedUid
                ? accounts.find((acc) => String(acc.id) === expectedUid)
                : accounts[0];
            if (! current) {
                return { ok: false, code: 'account_mismatch', observedIdentity: accounts.map((acc) => String(acc.id)).join(','), observedProfileUrl: '' };
            }
            const accountId = String(current.id);

            // 2. 设备头
            const deviceId = Array.from({ length: 32 }, () => '0123456789abcdef'[Math.floor(Math.random() * 16)]).join('');
            const spCm = `100-${Date.now()}-${deviceId}`;
            const headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'dv-id': deviceId, 'sp-cm': spCm };

            // 3. 图片上传
            const uploadResult = await uploadAll(async (data) => {
                const form = new FormData();
                form.append('file', base64ToBlob(data.dataBase64, data.mimeType), 'image.jpg');
                form.append('accountId', accountId);
                const res = await fetchJson(`https://mp.sohu.com/commons/front/outerUpload/image/file?accountId=${accountId}`, { method: 'POST', body: form });
                return res.data?.url || '';
            });
            const content = applyImages(bodyHtml, uploadResult.uploaded);

            // 4. 封面上传（有则设置）
            let cover = '';
            const coverItem = manifest.find((item) => item && (isV3 ? item.is_cover === true : item.role === 'cover'));
            if (coverItem && isV3) {
                cover = uploadResult.uploaded[String(coverItem.media_key)] || '';
            } else if (coverItem) {
                const coverData = mediaData.find((entry) => Number(entry?.image_id) === Number(coverItem.image_id));
                if (coverData?.dataBase64) {
                    const form = new FormData();
                    form.append('file', base64ToBlob(coverData.dataBase64, coverData.mimeType), 'cover.jpg');
                    form.append('accountId', accountId);
                    const res = await fetchJson(`https://mp.sohu.com/commons/front/outerUpload/image/file?accountId=${accountId}`, { method: 'POST', body: form });
                    cover = res.data?.url || '';
                }
            }

            // 5. 保存草稿
            const save = await fetchJson(`https://mp.sohu.com/mpbp/bp/news/v4/news/draft/v2?accountId=${accountId}`, {
                method: 'POST',
                headers,
                body: JSON.stringify({
                    title,
                    brief: String(payload?.summary ?? ''),
                    content,
                    channelId: 24,
                    categoryId: -1,
                    id: 0,
                    userColumnId: 0,
                    columnNewsIds: [],
                    businessCode: 0,
                    declareOriginal: false,
                    cover,
                    topicIds: [],
                    isAd: 0,
                    userLabels: '[]',
                    reprint: false,
                    customTags: '',
                    infoResource: 0,
                    sourceUrl: '',
                    visibleToLoginedUsers: 0,
                    attrIds: [],
                    auto: true,
                    accountId: Number(accountId),
                }),
            });
            const postId = save.data?.data;
            if (! save.data?.success || ! postId) {
                return { ok: false, code: 'draft_save_failed', error: String(save.data?.msg || 'unknown') };
            }
            if (! isV3) return {
                ok: true, code: 'draft_filled', draftUrl: `https://mp.sohu.com/mpfe/v4/contentManagement/news/addarticle?spm=smmp.articlelist.0.0&contentStatus=2&id=${postId}`,
                observedIdentity: accountId, observedProfileUrl: String(current.nickName ?? ''),
                accountProof: `uid:${accountId.toLowerCase()}`, filledFields: ['title', 'body', 'images', 'cover'],
                imageCount: uploadResult.expectedCount,
            };
            const readback = await fetchJson(`https://mp.sohu.com/mpbp/bp/news/v4/news/${encodeURIComponent(postId)}?accountId=${encodeURIComponent(accountId)}&_=${Date.now()}`, { headers });
            const readbackHtml = readback.data?.data?.content ?? readback.data?.content ?? '';
            const observed = await validateReadback(readbackHtml, uploadResult.expectedCount, uploadResult.orderedUrls);
            return {
                ok: true,
                code: 'draft_saved',
                persistence: 'remote_saved',
                draftId: String(postId),
                draftUrl: `https://mp.sohu.com/mpfe/v4/contentManagement/news/addarticle?spm=smmp.articlelist.0.0&contentStatus=2&id=${postId}`,
                observedIdentity: accountId,
                observedProfileUrl: String(current.nickName ?? ''),
                accountProof: `uid:${accountId.toLowerCase()}`,
                filledFields: ['title', 'body', 'images', 'cover'],
                renderedTextHash: observed.textHash,
                headingOutline: observed.headings,
                expectedImageCount: uploadResult.expectedCount,
                observedImageCount: observed.imageCount,
                mediaUploadReceipts: uploadResult.receipts,
            };
        }

        if (platform === 'jianshu') {
            const identity = provePageAccount(['a[href*="/u/"][href]', '[data-user-id]', '[data-uid]']);
            if (! identity.ok) return identity;

            const uploadResult = await uploadAll(async (data, item) => {
                const mime = String(data.mimeType || item?.mime_type || 'image/jpeg');
                const extension = mime === 'image/png' ? 'png' : mime === 'image/gif' ? 'gif' : mime === 'image/webp' ? 'webp' : 'jpg';
                const filename = `${String(item?.media_key || 'geoflow-image')}.${extension}`;
                const token = await fetchJson(`https://www.jianshu.com/upload_images/token.json?${new URLSearchParams({ filename })}`);
                if (! token.data?.token || ! token.data?.key) throw new Error('media_upload_token_failed');
                const form = new FormData();
                form.append('token', token.data.token);
                form.append('key', token.data.key);
                form.append('file', base64ToBlob(data.dataBase64, mime), filename);
                form.append('x:protocol', 'https');
                const response = await fetch('https://upload.qiniup.com/', { method: 'POST', body: form });
                if (! response.ok) throw new Error(`media_upload_failed:${response.status}`);
                const uploaded = await response.json();
                const url = String(uploaded?.url ?? '');
                return url.startsWith('//') ? `https:${url}` : url;
            });
            const content = applyImages(bodyHtml, uploadResult.uploaded);
            const notebooks = await fetchJson('https://www.jianshu.com/author/notebooks');
            const notebookList = Array.isArray(notebooks.data) ? notebooks.data : [];
            const notebookId = notebookList[0]?.id;
            if (! notebookId) return { ok: false, code: 'login_required' };
            const created = await fetchJson('https://www.jianshu.com/author/notes', {
                method: 'POST',
                headers: { accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ notebook_id: notebookId, title, at_bottom: false }),
            });
            const noteId = created.data?.id;
            if (! noteId) return { ok: false, code: 'draft_save_failed', error: String(created.data?.message ?? created.status) };
            const updated = await fetchJson(`https://www.jianshu.com/author/notes/${encodeURIComponent(noteId)}`, {
                method: 'PUT',
                headers: { accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: noteId, autosave_control: 1, title, content }),
            });
            if (updated.status < 200 || updated.status >= 300) {
                return { ok: false, code: 'draft_save_failed', error: String(updated.data?.message ?? updated.status) };
            }
            const readback = await fetchJson(`https://www.jianshu.com/author/notes/${encodeURIComponent(noteId)}?_=${Date.now()}`);
            const readbackTitle = String(readback.data?.title ?? readback.data?.note?.title ?? readback.data?.data?.title ?? '');
            const readbackHtml = readback.data?.content ?? readback.data?.note?.content ?? readback.data?.data?.content ?? '';
            if (readbackTitle !== title) throw new Error('draft_title_mismatch');
            const observed = await validateReadback(readbackHtml, uploadResult.expectedCount, uploadResult.orderedUrls);
            return {
                ok: true,
                code: 'draft_saved',
                persistence: 'remote_saved',
                draftId: String(noteId),
                draftUrl: `https://www.jianshu.com/writer#/notebooks/${notebookId}/notes/${noteId}/writing`,
                observedIdentity: identity.observedIdentity,
                observedProfileUrl: identity.observedProfileUrl,
                accountProof: identity.proof,
                filledFields: ['title', 'body', ...(uploadResult.expectedCount ? ['images'] : [])],
                renderedTextHash: observed.textHash,
                headingOutline: observed.headings,
                expectedImageCount: uploadResult.expectedCount,
                observedImageCount: observed.imageCount,
                mediaUploadReceipts: uploadResult.receipts,
            };
        }

        if (platform === 'csdn') {
            const identity = provePageAccount(['a[href*="blog.csdn.net/"][href]', 'a[href*="/user/"][href]', '[data-user-id]', '[data-uid]']);
            if (! identity.ok) return identity;

            // CSDN 编辑器公开 Web API 的签名流程；只写入 status=2 草稿。
            const caKey = '203803574';
            const hmacKey = '9znpamsyl2c7cdrr9sas0le9vbc3r6ba';
            const csdnHeaders = async (method, apiPath) => {
                const nonce = crypto.randomUUID();
                const accept = '*/*';
                const contentType = 'application/json';
                const signContent = `${method}\n${accept}\n\n${contentType}\n\nx-ca-key:${caKey}\nx-ca-nonce:${nonce}\n${apiPath}`;
                const encoder = new TextEncoder();
                const key = await crypto.subtle.importKey('raw', encoder.encode(hmacKey), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
                const signatureBytes = await crypto.subtle.sign('HMAC', key, encoder.encode(signContent));
                const signature = btoa(String.fromCharCode(...new Uint8Array(signatureBytes)));
                return {
                    accept,
                    'Content-Type': contentType,
                    'x-ca-key': caKey,
                    'x-ca-nonce': nonce,
                    'x-ca-signature': signature,
                    'x-ca-signature-headers': 'x-ca-key,x-ca-nonce',
                };
            };
            const uploadResult = await uploadAll(async (data, item) => {
                const params = new URLSearchParams({
                    type: 'blog', rtype: 'blog_picture', 'x-image-template': 'standard',
                    'x-image-app': 'direct_blog', 'x-image-dir': 'direct', 'x-image-suffix': 'png',
                });
                const configResponse = await fetchJson(`https://imgservice.csdn.net/direct/v1.0/image/obs/upload?${params}`);
                const config = configResponse.data?.data;
                if (! config?.filePath || ! config?.policy || ! config?.signature) throw new Error('media_upload_token_failed');
                const custom = config.customParam ?? {};
                const form = new FormData();
                form.append('key', config.filePath);
                form.append('policy', config.policy);
                form.append('AccessKeyId', config.accessId);
                form.append('signature', config.signature);
                form.append('callbackUrl', config.callbackUrl);
                form.append('callbackBody', config.callbackBody);
                form.append('callbackBodyType', config.callbackBodyType);
                for (const [name, value] of Object.entries({
                    'x:rtype': custom.rtype, 'x:watermark': custom.watermark, 'x:templateName': custom.templateName,
                    'x:filePath': custom.filePath, 'x:isAudit': custom.isAudit, 'x:x-image-app': custom['x-image-app'],
                    'x:type': custom.type, 'x:x-image-suffix': custom['x-image-suffix'], 'x:username': custom.username,
                })) form.append(name, String(value ?? ''));
                const mime = String(data.mimeType || item?.mime_type || 'image/png');
                form.append('file', base64ToBlob(data.dataBase64, mime), `${String(item?.media_key || 'geoflow-image')}.png`);
                const response = await fetch('https://csdn-img-blog.obs.cn-north-4.myhuaweicloud.com/', { method: 'POST', body: form });
                if (! response.ok) throw new Error(`media_upload_failed:${response.status}`);
                const uploaded = await response.json();
                return String(uploaded?.data?.imageUrl ?? '');
            });
            const content = applyImages(bodyHtml, uploadResult.uploaded);
            const coverItem = manifest.find((item) => item?.is_cover === true);
            const coverUrl = coverItem ? String(uploadResult.uploaded[String(coverItem.media_key)] ?? '') : '';
            const savePath = '/blog-console-api/v1/postedit/saveArticle';
            const save = await fetchJson(`https://bizapi.csdn.net${savePath}`, {
                method: 'POST',
                headers: await csdnHeaders('POST', savePath),
                body: JSON.stringify({
                    article_id: '', title: title.slice(0, 100), description: String(payload?.summary ?? '').slice(0, 256),
                    content, markdowncontent: '', tags: (Array.isArray(payload?.tags) && payload.tags.length ? payload.tags : ['经验分享']).join(','),
                    categories: String(payload?.category ?? ''), type: 'original', status: 2, read_type: 'public', reason: '',
                    resource_url: '', resource_id: '', original_link: '', authorized_status: false, check_original: false,
                    editor_type: 0, plan: [], vote_id: 0, scheduled_time: 0, level: '1', cover_type: coverUrl ? 1 : 0,
                    cover_images: coverUrl ? [coverUrl] : [], not_auto_saved: 0, is_new: 1,
                }),
            });
            const articleId = save.data?.data?.article_id ?? save.data?.data?.id;
            if (Number(save.data?.code) !== 200 || ! articleId) {
                return { ok: false, code: 'draft_save_failed', error: String(save.data?.message ?? save.data?.msg ?? save.status) };
            }
            const readPath = '/blog-console-api/v1/editor/getArticle';
            const readback = await fetchJson(`https://bizapi.csdn.net${readPath}?id=${encodeURIComponent(articleId)}`, {
                headers: await csdnHeaders('GET', readPath),
            });
            const readbackData = readback.data?.data ?? {};
            if (String(readbackData.title ?? '') !== title.slice(0, 100)) throw new Error('draft_title_mismatch');
            const readbackHtml = readbackData.content ?? '';
            const observed = await validateReadback(readbackHtml, uploadResult.expectedCount, uploadResult.orderedUrls);
            return {
                ok: true,
                code: 'draft_saved',
                persistence: 'remote_saved',
                draftId: String(articleId),
                draftUrl: `https://editor.csdn.net/md/?articleId=${articleId}`,
                observedIdentity: identity.observedIdentity,
                observedProfileUrl: identity.observedProfileUrl,
                accountProof: identity.proof,
                filledFields: ['title', 'body', 'tags', ...(uploadResult.expectedCount ? ['images', 'cover'] : [])],
                renderedTextHash: observed.textHash,
                headingOutline: observed.headings,
                expectedImageCount: uploadResult.expectedCount,
                observedImageCount: observed.imageCount,
                mediaUploadReceipts: uploadResult.receipts,
            };
        }

        return { ok: false, code: 'adapter_not_implemented' };
    })().catch((error) => ({ ok: false, code: 'adapter_exception', error: String(error?.message ?? error) }));
}
