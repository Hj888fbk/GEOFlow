/**
 * 自媒体 API 直发适配器（百家号 / 搜狐号）。
 *
 * 与平台自家编辑器使用同一套 Web API 存草稿：不做 DOM 填充，格式零损耗，图片走平台上传接口。
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

        // 上传全部正文图片，返回 { 占位编号N: 平台图床URL }
        const uploadAll = async (uploadOne) => {
            const uploaded = {};
            const bodyImages = manifest
                .filter((item) => item && item.role === 'body')
                .sort((a, b) => Number(a.position ?? 0) - Number(b.position ?? 0));
            for (const [index, item] of bodyImages.entries()) {
                const data = mediaData.find((entry) => Number(entry?.image_id) === Number(item.image_id));
                if (! data?.dataBase64) continue;
                const url = await uploadOne(data);
                if (url) uploaded[index + 1] = url;
            }
            return uploaded;
        };

        // 用上传后的图床 URL 替换正文中的【图片N】占位段落
        const applyImages = (html, uploaded) => {
            let result = html;
            for (const [n, url] of Object.entries(uploaded)) {
                const pattern = new RegExp(`<p>\\s*【图片${n}】\\s*</p>|【图片${n}】`, 'g');
                result = result.replace(pattern, `<p><img src="${url}" /></p>`);
            }
            return result;
        };

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
            const uploaded = await uploadAll(async (data) => {
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
            const content = applyImages(bodyHtml, uploaded);

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
            return {
                ok: true,
                code: 'draft_filled',
                draftUrl: `https://baijiahao.baidu.com/builder/rc/edit?type=news&article_id=${articleId}`,
                observedIdentity: String(user.userid),
                observedProfileUrl: String(user.name ?? ''),
                accountProof: `uid:${String(user.userid).toLowerCase()}`,
                filledFields: ['title', 'body', 'images'],
                imageCount: Object.keys(uploaded).length,
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
            const uploaded = await uploadAll(async (data) => {
                const form = new FormData();
                form.append('file', base64ToBlob(data.dataBase64, data.mimeType), 'image.jpg');
                form.append('accountId', accountId);
                const res = await fetchJson(`https://mp.sohu.com/commons/front/outerUpload/image/file?accountId=${accountId}`, { method: 'POST', body: form });
                return res.data?.url || '';
            });
            const content = applyImages(bodyHtml, uploaded);

            // 4. 封面上传（有则设置）
            let cover = '';
            const coverItem = manifest.find((item) => item && item.role === 'cover');
            const coverData = coverItem
                ? mediaData.find((entry) => Number(entry?.image_id) === Number(coverItem.image_id))
                : null;
            if (coverData?.dataBase64) {
                const form = new FormData();
                form.append('file', base64ToBlob(coverData.dataBase64, coverData.mimeType), 'cover.jpg');
                form.append('accountId', accountId);
                const res = await fetchJson(`https://mp.sohu.com/commons/front/outerUpload/image/file?accountId=${accountId}`, { method: 'POST', body: form });
                cover = res.data?.url || '';
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
            return {
                ok: true,
                code: 'draft_filled',
                draftUrl: `https://mp.sohu.com/mpfe/v4/contentManagement/news/addarticle?spm=smmp.articlelist.0.0&contentStatus=2&id=${postId}`,
                observedIdentity: accountId,
                observedProfileUrl: String(current.nickName ?? ''),
                accountProof: `uid:${accountId.toLowerCase()}`,
                filledFields: ['title', 'body', 'images', 'cover'],
                imageCount: Object.keys(uploaded).length,
            };
        }

        return { ok: false, code: 'adapter_not_implemented' };
    })().catch((error) => ({ ok: false, code: 'adapter_exception', error: String(error?.message ?? error) }));
}
