import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';

import { normalizeGeoflowBaseUrl, originPermissionPattern } from '../../browser-extension/src/lib/url-policy.js';
import { configureTrustedStorage } from '../../browser-extension/src/lib/storage.js';
import { hasConflictingActiveTask, resumeClaimedTask } from '../../browser-extension/src/lib/task-state.js';
import { runZhihuAnswerAdapter } from '../../browser-extension/src/adapters/zhihu-answer.js';
import { observeSelfMediaArticleResult, runSelfMediaArticleAdapter } from '../../browser-extension/src/adapters/self-media-article.js';
import { adapterForAction, supportedAdapterActions } from '../../browser-extension/src/adapters/registry.js';

function installDraftApiDom(pageUrl, profileUrl, profileText = '') {
    globalThis.location = new URL(pageUrl);
    globalThis.document = {
        querySelector() { return null; },
        querySelectorAll(selector) {
            return selector.includes('href') || selector.includes('data-user') || selector.includes('data-uid')
                ? [{ href: profileUrl, textContent: profileText, dataset: {} }]
                : [];
        },
    };
    globalThis.DOMParser = class {
        parseFromString(html) {
            const source = String(html);
            const textOf = (value) => String(value).replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').trim();
            const nodes = (pattern, mapper) => [...source.matchAll(pattern)].map(mapper);
            return {
                body: { textContent: textOf(source) },
                querySelectorAll(selector) {
                    if (selector === 'img') return nodes(/<img\b[^>]*>/gi, (match) => {
                        const src = match[0].match(/\bsrc=["']([^"']+)["']/i)?.[1] ?? '';
                        return { src, getAttribute(name) { return name === 'src' ? src : null; } };
                    });
                    if (selector === 'h1,h2,h3,h4,h5,h6') {
                        return nodes(/<h([1-6])\b[^>]*>([\s\S]*?)<\/h\1>/gi, (match) => ({ tagName: `H${match[1]}`, textContent: textOf(match[2]) }));
                    }
                    if (selector === 'h1,h2,h3,h4,h5,h6,p,li,blockquote,pre,tr') {
                        return nodes(/<(h[1-6]|p|li|blockquote|pre|tr)\b[^>]*>([\s\S]*?)<\/\1>/gi, (match) => ({ tagName: match[1].toUpperCase(), textContent: textOf(match[2]) }));
                    }
                    return [];
                },
            };
        }
    };
}

function textSha256(value) {
    return createHash('sha256').update(value).digest('hex');
}

test('GEOFlow base URL accepts HTTPS and local HTTP while rejecting unsafe shapes', () => {
    assert.equal(normalizeGeoflowBaseUrl('https://geo.example.com/'), 'https://geo.example.com');
    assert.equal(normalizeGeoflowBaseUrl('http://localhost:8000/'), 'http://localhost:8000');
    assert.throws(() => normalizeGeoflowBaseUrl('http://geo.example.com'), /HTTPS/);
    assert.throws(() => normalizeGeoflowBaseUrl('https://user:secret@geo.example.com'), /credentials/);
    assert.throws(() => normalizeGeoflowBaseUrl('https://geo.example.com/?tenant=1'), /query/);
});

test('target permission is reduced to its HTTPS origin', () => {
    assert.equal(
        originPermissionPattern('https://www.zhihu.com/question/123?utm_source=geoflow'),
        'https://www.zhihu.com/*',
    );
});

test('extension storage is restricted to trusted extension contexts', async () => {
    const levels = [];
    const storage = {
        local: { setAccessLevel: async (value) => levels.push(['local', value.accessLevel]) },
        session: { setAccessLevel: async (value) => levels.push(['session', value.accessLevel]) },
    };

    await configureTrustedStorage(storage);

    assert.deepEqual(levels, [
        ['local', 'TRUSTED_CONTEXTS'],
        ['session', 'TRUSTED_CONTEXTS'],
    ]);
});

test('claimed work can be resumed after Chrome session storage is cleared', () => {
    const publication = {
        id: 42,
        status: 'in_progress',
        claim: { claimed_at: '2026-08-24T08:00:00Z' },
    };

    assert.deepEqual(resumeClaimedTask(null, publication, '2026-08-24T08:01:00Z'), {
        publication,
        tabId: null,
        startedAt: '2026-08-24T08:00:00Z',
        accountVerified: false,
    });
});

test('draft-filled account verification survives Chrome session recovery', () => {
    const publication = {
        id: 43,
        status: 'draft_filled',
        account_verified: true,
        claim: { claimed_at: '2026-08-24T08:00:00Z' },
    };

    assert.equal(resumeClaimedTask(null, publication).accountVerified, true);
});

test('one side panel does not replace a different active work order', () => {
    const currentTask = { publication: { id: 42, status: 'in_progress' } };

    assert.equal(hasConflictingActiveTask(currentTask, { id: 42 }), false);
    assert.equal(hasConflictingActiveTask(currentTask, { id: 84 }), true);
});

test('draft-filled work remains the only active browser work order until final receipt', () => {
    const currentTask = { publication: { id: 42, status: 'draft_filled' } };

    assert.equal(hasConflictingActiveTask(currentTask, { id: 84 }), true);
    assert.equal(hasConflictingActiveTask(currentTask, { id: 42 }), false);
});

test('Zhihu adapter blocks a mismatched account before touching the editor', () => {
    let editorQueried = false;
    globalThis.window = { location: new URL('https://www.zhihu.com/question/123456') };
    globalThis.document = {
        querySelector(selector) {
            if (selector.includes('captcha')) return null;
            if (selector.includes('/people/')) {
                return { href: 'https://www.zhihu.com/people/another-user' };
            }
            editorQueried = true;
            return null;
        },
    };

    const result = runZhihuAnswerAdapter(
        { body_plain: '回答正文' },
        'https://www.zhihu.com/people/geoflow',
    );

    assert.equal(result.ok, false);
    assert.equal(result.code, 'account_mismatch');
    assert.equal(editorQueried, false);
});

test('Zhihu adapter ignores unrelated profile links outside the account header', () => {
    globalThis.window = { location: new URL('https://www.zhihu.com/question/123456') };
    globalThis.document = {
        querySelector(selector) {
            if (selector.includes('captcha')) return null;
            if (selector === 'a[href*="//www.zhihu.com/people/"]') {
                return { href: 'https://www.zhihu.com/people/geoflow' };
            }
            return null;
        },
    };

    const result = runZhihuAnswerAdapter(
        { body_plain: '回答正文' },
        'https://www.zhihu.com/people/geoflow',
    );

    assert.equal(result.ok, false);
    assert.equal(result.code, 'login_required');
});

test('Zhihu adapter stops when human verification is present', () => {
    let editorQueried = false;
    globalThis.window = { location: new URL('https://www.zhihu.com/question/123456') };
    globalThis.document = {
        querySelector(selector) {
            if (selector.includes('captcha')) return {};
            editorQueried = true;
            return null;
        },
    };

    const result = runZhihuAnswerAdapter(
        { body_plain: '回答正文' },
        'https://www.zhihu.com/people/geoflow',
    );

    assert.equal(result.ok, false);
    assert.equal(result.code, 'human_verification_required');
    assert.equal(editorQueried, false);
});

test('Zhihu adapter fills an empty answer editor for the expected account', () => {
    const commands = [];
    const editor = {
        textContent: '',
        focus() {},
        dispatchEvent() {},
    };
    globalThis.window = { location: new URL('https://www.zhihu.com/question/123456') };
    globalThis.document = {
        querySelector(selector) {
            if (selector.includes('captcha')) return null;
            return selector.includes('/people/')
                ? { href: 'https://www.zhihu.com/people/geoflow/' }
                : editor;
        },
        execCommand(command, _ui, value) {
            commands.push([command, value]);
            return true;
        },
    };

    const result = runZhihuAnswerAdapter(
        { body_plain: '回答正文' },
        'https://www.zhihu.com/people/geoflow',
    );

    assert.equal(result.ok, true);
    assert.equal(result.code, 'draft_filled');
    assert.deepEqual(commands.at(-1), ['insertText', '回答正文']);
});

test('Baijiahao adapter verifies account before reading or changing the editor', () => {
    let editorQueried = false;
    globalThis.window = { location: new URL('https://baijiahao.baidu.com/builder/rc/edit') };
    globalThis.document = {
        querySelector(selector) {
            if (selector.includes('captcha') || selector.includes('verify')) return null;
            if (selector.includes('/bjournal/profile')) return { href: 'https://baijiahao.baidu.com/bjournal/profile/wrong' };
            editorQueried = true;
            return null;
        },
    };

    const result = runSelfMediaArticleAdapter('baijiahao_article', { title: '标题', body_plain: '正文' }, {
        profile_url: 'https://baijiahao.baidu.com/bjournal/profile/geoflow',
    });

    assert.equal(result.ok, false);
    assert.equal(result.code, 'account_mismatch');
    assert.equal(editorQueried, false);
});

test('self-media adapter stops for a human verification challenge', () => {
    let editorQueried = false;
    globalThis.window = { location: new URL('https://baijiahao.baidu.com/builder/rc/edit') };
    globalThis.document = {
        querySelector(selector) {
            if (selector.includes('captcha')) return {};
            editorQueried = true;
            return null;
        },
    };

    const result = runSelfMediaArticleAdapter('baijiahao_article', { title: '标题', body_plain: '正文' }, {
        profile_url: 'https://baijiahao.baidu.com/bjournal/profile/geoflow',
    });

    assert.equal(result.code, 'human_verification_required');
    assert.equal(editorQueried, false);
});

test('Baijiahao adapter fills title summary and body but never invokes a publish control', () => {
    const events = [];
    let publishClicked = false;
    const field = () => ({ value: '', focus() {}, dispatchEvent(event) { events.push(event.type); } });
    const title = field();
    const summary = field();
    const tags = field();
    const body = { textContent: '', focus() {}, dispatchEvent(event) { events.push(event.type); } };
    globalThis.window = { location: new URL('https://baijiahao.baidu.com/builder/rc/edit'), Event, InputEvent: Event };
    globalThis.document = {
        body: {},
        querySelector(selector) {
            if (selector.includes('captcha') || selector.includes('verify')) return null;
            if (selector.includes('/bjournal/profile')) return { href: 'https://baijiahao.baidu.com/bjournal/profile/geoflow' };
            if (selector.includes('placeholder*="标题"')) return title;
            if (selector.includes('placeholder*="摘要"')) return summary;
            if (selector.includes('placeholder*="标签"')) return tags;
            if (selector.includes('contenteditable')) return body;
            if (selector.includes('publish')) return { click() { publishClicked = true; } };
            return null;
        },
    };

    const result = runSelfMediaArticleAdapter('baijiahao_article', {
        title: '百家号标题', summary: '百家号摘要', body_plain: '百家号正文', body_html: '<p>不能作为纯文本填入</p>', tags: ['恒佳', '橡胶软接头'],
    }, { profile_url: 'https://baijiahao.baidu.com/bjournal/profile/geoflow' });

    assert.equal(result.ok, true);
    assert.deepEqual(result.filledFields, ['title', 'body', 'summary', 'tags']);
    assert.equal(title.value, '百家号标题');
    assert.equal(summary.value, '百家号摘要');
    assert.equal(tags.value, '恒佳、橡胶软接头');
    assert.equal(body.textContent, '百家号正文');
    assert.equal(publishClicked, false);
    assert.ok(events.includes('input'));
});

test('self-media adapter checks every existing field before changing the page', () => {
    const title = { value: '', focus() {}, dispatchEvent() {} };
    const summary = { value: '平台已有摘要', focus() {}, dispatchEvent() {} };
    const body = { textContent: '', focus() {}, dispatchEvent() {} };
    globalThis.window = { location: new URL('https://mp.sohu.com/mpfe/v4/contentManagement/news/add'), Event, InputEvent: Event };
    globalThis.document = {
        querySelector(selector) {
            if (selector.includes('captcha') || selector.includes('verify')) return null;
            if (selector.includes('/media/')) return { href: 'https://mp.sohu.com/media/geoflow' };
            if (selector.includes('placeholder*="标题"')) return title;
            if (selector.includes('placeholder*="摘要"')) return summary;
            if (selector.includes('contenteditable')) return body;
            return null;
        },
    };

    const result = runSelfMediaArticleAdapter('sohu_media_article', {
        title: '新标题', summary: '新摘要', body_plain: '新正文',
    }, { profile_url: 'https://mp.sohu.com/media/geoflow' });

    assert.equal(result.code, 'editor_not_empty');
    assert.equal(title.value, '');
    assert.equal(body.textContent, '');
});

test('self-media adapter honors field limits before changing the page', () => {
    const title = { value: '', maxLength: 3, focus() {}, dispatchEvent() {} };
    const body = { textContent: '', focus() {}, dispatchEvent() {} };
    globalThis.window = { location: new URL('https://mp.sohu.com/mpfe/v4/contentManagement/news/add'), Event, InputEvent: Event };
    globalThis.document = {
        querySelector(selector) {
            if (selector.includes('captcha') || selector.includes('verify')) return null;
            if (selector.includes('/media/')) return { href: 'https://mp.sohu.com/media/geoflow' };
            if (selector.includes('placeholder*="标题"')) return title;
            if (selector.includes('contenteditable')) return body;
            return null;
        },
    };

    const result = runSelfMediaArticleAdapter('sohu_media_article', {
        title: '超过三字', body_plain: '新正文',
    }, { profile_url: 'https://mp.sohu.com/media/geoflow' });

    assert.equal(result.code, 'field_limit_exceeded');
    assert.equal(title.value, '');
    assert.equal(body.textContent, '');
});

test('Baijiahao adapter can prove the configured account by UID', () => {
    const field = () => ({ value: '', focus() {}, dispatchEvent() {} });
    const title = field();
    const body = { textContent: '', focus() {}, dispatchEvent() {} };
    globalThis.window = { location: new URL('https://baijiahao.baidu.com/builder/rc/edit'), Event, InputEvent: Event };
    globalThis.document = {
        querySelector(selector) {
            if (selector.includes('captcha') || selector.includes('verify')) return null;
            if (selector.includes('/bjournal/profile')) return { href: '', textContent: '账号 UID 778899' };
            if (selector.includes('placeholder*="标题"')) return title;
            if (selector.includes('contenteditable')) return body;
            return null;
        },
    };

    const result = runSelfMediaArticleAdapter('baijiahao_article', {
        title: '百家号标题', body_plain: '百家号正文',
    }, { account_uid: '778899' });

    assert.equal(result.ok, true);
    assert.equal(result.accountProof, 'uid:778899');
});

test('Sohu adapter stops safely when either title or body already contains a draft', () => {
    const title = { value: '已有标题', focus() {}, dispatchEvent() {} };
    const body = { textContent: '', focus() {}, dispatchEvent() {} };
    globalThis.window = { location: new URL('https://mp.sohu.com/mpfe/v4/contentManagement/news/add'), Event, InputEvent: Event };
    globalThis.document = {
        querySelector(selector) {
            if (selector.includes('captcha') || selector.includes('verify')) return null;
            if (selector.includes('/media/')) return { href: 'https://mp.sohu.com/media/geoflow' };
            if (selector.includes('placeholder*="标题"')) return title;
            if (selector.includes('contenteditable')) return body;
            return null;
        },
    };

    const result = runSelfMediaArticleAdapter('sohu_media_article', {
        title: '新标题', body_plain: '新正文',
    }, { profile_url: 'https://mp.sohu.com/media/geoflow' });

    assert.equal(result.ok, false);
    assert.equal(result.code, 'editor_not_empty');
    assert.equal(title.value, '已有标题');
    assert.equal(body.textContent, '');
});

test('self-media result observer requires a real HTTP 200 readback from a public URL', async () => {
    globalThis.document = { body: {} };
    globalThis.window = { location: new URL('https://www.sohu.com/a/123456_100001') };
    let fetchOptions = null;
    globalThis.fetch = async (_url, options) => {
        fetchOptions = options;
        return ({
        status: 200,
        url: 'https://www.sohu.com/a/123456_100001',
        });
    };
    assert.deepEqual(await observeSelfMediaArticleResult('sohu_media_article'), {
        outcome: 'completed',
        completionUrl: 'https://www.sohu.com/a/123456_100001',
        readbackStatus: 200,
        readbackSucceeded: true,
    });
    assert.equal(fetchOptions.credentials, 'omit');

    globalThis.window = { location: new URL('https://baijiahao.baidu.com/builder/rc/edit') };
    assert.equal((await observeSelfMediaArticleResult('baijiahao_article')).outcome, 'outcome_unknown');

    globalThis.window = { location: new URL('https://baijiahao.baidu.com/s?id=123456') };
    globalThis.fetch = async () => ({ status: 403, url: 'https://baijiahao.baidu.com/s?id=123456' });
    assert.deepEqual(await observeSelfMediaArticleResult('baijiahao_article'), {
        outcome: 'outcome_unknown',
        completionUrl: null,
        readbackStatus: 403,
        readbackSucceeded: false,
    });
});

test('adapter registry exposes the ten-platform draft actions while preserving legacy Zhihu answer', () => {
    assert.deepEqual(supportedAdapterActions(), [
        'zhihu_answer', 'baijiahao_article', 'sohu_media_article', 'zhihu_column_article',
        'csdn_article', 'toutiao_article', 'netease_media_article', 'qq_penguin_article',
        'dayu_article', 'jianshu_article', 'douyin_article',
    ]);
    assert.equal(adapterForAction('baijiahao_article').kind, 'self_media_article');
    assert.equal(adapterForAction('csdn_article').kind, 'self_media_article');
});

test('self-media body html sanitizer keeps structure and strips script/img/unknown tags', async () => {
    const { sanitizeSelfMediaBodyHtml } = await import('../../browser-extension/src/adapters/self-media-article.js');
    const out = sanitizeSelfMediaBodyHtml(
        '<h2>标题</h2><p>正文<strong>加粗</strong></p><script>alert(1)</script><img src="x.jpg"><div>保留文本</div><table><tr><td>单元格</td></tr></table><p>【图片1】</p>',
    );
    assert.ok(out.includes('<h2>标题</h2>'));
    assert.ok(out.includes('<strong>加粗</strong>'));
    assert.ok(out.includes('<table><tr><td>单元格</td></tr></table>'));
    assert.ok(out.includes('【图片1】'));
    assert.ok(! out.includes('script'));
    assert.ok(! out.includes('<img'));
    assert.ok(! out.includes('<div'));
    assert.ok(out.includes('保留文本'));
});

test('self-media body html sanitizer tolerates empty and non-string input', async () => {
    const { sanitizeSelfMediaBodyHtml } = await import('../../browser-extension/src/adapters/self-media-article.js');
    assert.equal(sanitizeSelfMediaBodyHtml(''), '');
    assert.equal(sanitizeSelfMediaBodyHtml(null), '');
    assert.equal(sanitizeSelfMediaBodyHtml(undefined), '');
});

test('baijiahao api draft: auth check, token, image upload, placeholder replace, draft save', async () => {
    const { runSelfMediaApiDraft } = await import('../../browser-extension/src/adapters/self-media-api.js');
    const calls = [];
    globalThis.fetch = async (url, options = {}) => {
        calls.push({ url: String(url), method: options.method ?? 'GET', body: options.body });
        const u = String(url);
        if (u.includes('/builder/app/appinfo')) {
            return new Response(JSON.stringify({ errno: 0, errmsg: 'success', data: { user: { userid: '1788421461433111', name: '恒佳' } } }));
        }
        if (u.includes('/builder/rc/edit') && ! options.method) {
            return new Response('<script>window.__BJH__INIT__AUTH__="tok123"</script>');
        }
        if (u.includes('/pcui/picture/uploadproxy')) {
            return new Response(JSON.stringify({ errno: 0, errmsg: 'success', ret: { https_url: 'https://bcebos.com/img1.jpg' } }));
        }
        if (u.includes('/pcui/article/save')) {
            return new Response('bjhdraft({"errno":0,"errmsg":"success","ret":{"article_id":"999888"}})');
        }
        throw new Error('unexpected url: ' + u);
    };
    const payload = {
        title: '测试标题',
        body_html: '<h2>小节</h2><p>正文</p><p>【图片1】</p>',
        summary: '摘要',
        media_manifest: [{ role: 'body', position: 1, image_id: 77 }],
        _mediaData: [{ image_id: 77, mimeType: 'image/jpeg', dataBase64: Buffer.from('fakeimg').toString('base64') }],
    };
    const result = await runSelfMediaApiDraft('baijiahao_article', payload, { account_uid: '1788421461433111' });
    assert.equal(result.ok, true, JSON.stringify(result));
    assert.equal(result.draftUrl, 'https://baijiahao.baidu.com/builder/rc/edit?type=news&article_id=999888');
    const saveCall = calls.find((c) => c.url.includes('/pcui/article/save'));
    const bodyText = String(saveCall.body);
    assert.ok(bodyText.includes('bcebos.com%2Fimg1.jpg') || bodyText.includes('bcebos.com/img1.jpg'));
    assert.ok(! bodyText.includes('%E5%9B%BE%E7%89%871')); // 【图片1】已被替换
});

test('baijiahao api draft: account mismatch blocks saving', async () => {
    const { runSelfMediaApiDraft } = await import('../../browser-extension/src/adapters/self-media-api.js');
    globalThis.fetch = async () => new Response(JSON.stringify({ errno: 0, errmsg: 'success', data: { user: { userid: '999', name: '别的号' } } }));
    const result = await runSelfMediaApiDraft('baijiahao_article', { title: 't', body_html: '<p>x</p>' }, { account_uid: '1788421461433111' });
    assert.equal(result.ok, false);
    assert.equal(result.code, 'account_mismatch');
});

test('sohu api draft: account match, image + cover upload, draft save', async () => {
    const { runSelfMediaApiDraft } = await import('../../browser-extension/src/adapters/self-media-api.js');
    const calls = [];
    globalThis.fetch = async (url, options = {}) => {
        calls.push(String(url));
        const u = String(url);
        if (u.includes('/mpbp/bp/account/list')) {
            return new Response(JSON.stringify({ code: 2000000, data: { data: [{ accounts: [{ id: '121896784', nickName: '恒佳' }] }] } }));
        }
        if (u.includes('outerUpload/image/file')) {
            return new Response(JSON.stringify({ url: 'https://sohu.com/up/1.jpg' }));
        }
        if (u.includes('/news/draft/v2')) {
            return new Response(JSON.stringify({ success: true, data: 555666 }));
        }
        throw new Error('unexpected url: ' + u);
    };
    const payload = {
        title: '搜狐标题',
        body_html: '<p>正文</p><p>【图片1】</p>',
        summary: '摘要',
        media_manifest: [
            { role: 'cover', position: 0, image_id: 88 },
            { role: 'body', position: 1, image_id: 77 },
        ],
        _mediaData: [
            { image_id: 88, mimeType: 'image/jpeg', dataBase64: Buffer.from('cover').toString('base64') },
            { image_id: 77, mimeType: 'image/jpeg', dataBase64: Buffer.from('body').toString('base64') },
        ],
    };
    const result = await runSelfMediaApiDraft('sohu_media_article', payload, { account_uid: '121896784' });
    assert.equal(result.ok, true, JSON.stringify(result));
    assert.ok(result.draftUrl.includes('id=555666'));
    assert.equal(result.imageCount, 1);
});

test('v3 API draft fails atomically before save when one required image is unavailable', async () => {
    const { runSelfMediaApiDraft } = await import('../../browser-extension/src/adapters/self-media-api.js');
    const calls = [];
    globalThis.fetch = async (url) => {
        calls.push(String(url));
        if (String(url).includes('/builder/rc/edit')) {
            return new Response('<script>window.__BJH__INIT__AUTH__="tok123"</script>');
        }
        return new Response(JSON.stringify({ errno: 0, errmsg: 'success', data: { user: { userid: '1788421461433111', name: '恒佳' } } }));
    };
    const result = await runSelfMediaApiDraft('baijiahao_article', {
        schema_version: 3,
        title: '测试标题',
        body_html: '<h2>小节</h2><p>正文</p><img data-geoflow-media-key="m_aaaaaaaaaaaaaaaaaaaaaaaa">',
        media_manifest: [{ media_key: 'm_aaaaaaaaaaaaaaaaaaaaaaaa', sha256: 'a'.repeat(64), role: 'body', required: true, position: 1 }],
        _mediaData: [],
    }, { account_uid: '1788421461433111' });

    assert.equal(result.ok, false);
    assert.equal(result.code, 'adapter_exception');
    assert.ok(! calls.some((url) => url.includes('/pcui/article/save')));
});

test('jianshu API adapter uploads every image, saves a draft, and validates the remote readback', async () => {
    const { runSelfMediaApiDraft } = await import('../../browser-extension/src/adapters/self-media-api.js');
    const profileUrl = 'https://www.jianshu.com/u/hengjia';
    installDraftApiDom('https://www.jianshu.com/writer', profileUrl, '恒佳');
    const keys = ['m_aaaaaaaaaaaaaaaaaaaaaaaa', 'm_bbbbbbbbbbbbbbbbbbbbbbbb'];
    const sourceBytes = [Buffer.from('jianshu-image-a'), Buffer.from('jianshu-image-b')];
    const sourceHashes = sourceBytes.map(textSha256);
    const remoteHtml = '<h2>安装步骤</h2><p>先检查法兰。</p><img src="https://upload.jianshu.io/a.jpg"><img src="https://upload.jianshu.io/b.jpg">';
    const calls = [];
    let uploadIndex = 0;
    let reverseReadback = false;
    globalThis.fetch = async (url, options = {}) => {
        const href = String(url);
        calls.push({ href, method: options.method ?? 'GET', body: options.body });
        if (href.includes('/upload_images/token.json')) return new Response(JSON.stringify({ token: 'qiniu-token', key: 'qiniu-key' }));
        if (href === 'https://upload.qiniup.com/') return new Response(JSON.stringify({ url: `https://upload.jianshu.io/${uploadIndex++ % 2 === 0 ? 'a' : 'b'}.jpg` }));
        if (href.endsWith('/author/notebooks')) return new Response(JSON.stringify([{ id: 42 }]));
        if (href.endsWith('/author/notes') && options.method === 'POST') return new Response(JSON.stringify({ id: 99 }));
        if (href.includes('/author/notes/99') && options.method === 'PUT') return new Response(JSON.stringify({ id: 99 }));
        if (href.includes('/author/notes/99?')) return new Response(JSON.stringify({
            id: 99,
            title: '简书标题',
            content: reverseReadback
                ? remoteHtml.replace(/<img src="([^"]+a\.jpg)"><img src="([^"]+b\.jpg)">/, '<img src="$2"><img src="$1">')
                : remoteHtml,
        }));
        throw new Error(`unexpected url: ${href}`);
    };
    const result = await runSelfMediaApiDraft('jianshu_article', {
        schema_version: 3,
        title: '简书标题',
        body_html: `<h2>安装步骤</h2><p>先检查法兰。</p><img data-geoflow-media-key="${keys[0]}"><img data-geoflow-media-key="${keys[1]}">`,
        media_manifest: keys.map((key, index) => ({ media_key: key, sha256: sourceHashes[index], role: 'body', is_cover: index === 0, required: true, position: index + 1, mime_type: 'image/png' })),
        _mediaData: keys.map((key, index) => ({ media_key: key, sha256: sourceHashes[index], mimeType: 'image/png', dataBase64: sourceBytes[index].toString('base64') })),
        render_fingerprint: {
            text_sha256: textSha256('安装步骤 先检查法兰。'),
            heading_outline: [{ level: 2, text: '安装步骤' }],
            image_order: keys,
        },
    }, { profile_url: profileUrl });

    assert.equal(result.ok, true, JSON.stringify(result));
    assert.equal(result.persistence, 'remote_saved');
    assert.equal(result.draftId, '99');
    assert.equal(result.expectedImageCount, 2);
    assert.equal(result.observedImageCount, 2);
    assert.deepEqual(result.mediaUploadReceipts.map((item) => item.source_sha256), sourceHashes);
    const update = calls.find((call) => call.href.includes('/author/notes/99') && call.method === 'PUT');
    assert.ok(String(update.body).includes('https://upload.jianshu.io/a.jpg'));

    uploadIndex = 0;
    reverseReadback = true;
    const reversed = await runSelfMediaApiDraft('jianshu_article', {
        schema_version: 3,
        title: '简书标题',
        body_html: `<h2>安装步骤</h2><p>先检查法兰。</p><img data-geoflow-media-key="${keys[0]}"><img data-geoflow-media-key="${keys[1]}">`,
        media_manifest: keys.map((key, index) => ({ media_key: key, sha256: sourceHashes[index], role: 'body', required: true, position: index + 1, mime_type: 'image/png' })),
        _mediaData: keys.map((key, index) => ({ media_key: key, sha256: sourceHashes[index], mimeType: 'image/png', dataBase64: sourceBytes[index].toString('base64') })),
        render_fingerprint: { text_sha256: textSha256('安装步骤 先检查法兰。'), heading_outline: [{ level: 2, text: '安装步骤' }], image_order: keys },
    }, { profile_url: profileUrl });
    assert.equal(reversed.ok, false);
    assert.equal(reversed.code, 'adapter_exception');
    assert.equal(reversed.error, 'draft_image_order_mismatch');
});

test('csdn API adapter uses status 2, reuses the first body image as cover, and validates readback', async () => {
    const { runSelfMediaApiDraft } = await import('../../browser-extension/src/adapters/self-media-api.js');
    const profileUrl = 'https://blog.csdn.net/hengjia';
    installDraftApiDom('https://editor.csdn.net/md/', profileUrl, 'hengjia');
    const key = 'm_bbbbbbbbbbbbbbbbbbbbbbbb';
    const sourceBytes = Buffer.from('csdn-image');
    const sourceHash = textSha256(sourceBytes);
    const remoteHtml = '<h2>检测追溯</h2><p>记录批次信息。</p><img src="https://csdnimg.cn/b.jpg">';
    const calls = [];
    globalThis.fetch = async (url, options = {}) => {
        const href = String(url);
        calls.push({ href, method: options.method ?? 'GET', body: options.body, headers: options.headers });
        if (href.includes('imgservice.csdn.net/direct/v1.0/image/obs/upload')) {
            return new Response(JSON.stringify({ data: {
                filePath: 'direct/b.png', policy: 'policy', accessId: 'access', signature: 'signature',
                callbackUrl: 'https://imgservice.csdn.net/callback', callbackBody: 'body', callbackBodyType: 'application/json',
                customParam: { rtype: 'blog_picture', watermark: 'false', templateName: 'standard', filePath: 'direct/b.png', isAudit: false, 'x-image-app': 'direct_blog', type: 'blog', 'x-image-suffix': 'png', username: 'hengjia' },
            } }));
        }
        if (href.includes('csdn-img-blog.obs.cn-north-4.myhuaweicloud.com')) return new Response(JSON.stringify({ data: { imageUrl: 'https://csdnimg.cn/b.jpg' } }));
        if (href.endsWith('/blog-console-api/v1/postedit/saveArticle')) return new Response(JSON.stringify({ code: 200, data: { article_id: 12345 } }));
        if (href.includes('/blog-console-api/v1/editor/getArticle?id=12345')) return new Response(JSON.stringify({ code: 200, data: { title: 'CSDN标题', content: remoteHtml } }));
        throw new Error(`unexpected url: ${href}`);
    };
    const result = await runSelfMediaApiDraft('csdn_article', {
        schema_version: 3,
        title: 'CSDN标题',
        summary: '摘要',
        tags: ['检测追溯', '橡胶软接头'],
        body_html: `<h2>检测追溯</h2><p>记录批次信息。</p><img data-geoflow-media-key="${key}">`,
        media_manifest: [{ media_key: key, sha256: sourceHash, role: 'body', is_cover: true, required: true, position: 1, mime_type: 'image/png' }],
        _mediaData: [{ media_key: key, sha256: sourceHash, mimeType: 'image/png', dataBase64: sourceBytes.toString('base64') }],
        render_fingerprint: {
            text_sha256: textSha256('检测追溯 记录批次信息。'),
            heading_outline: [{ level: 2, text: '检测追溯' }],
            image_order: [key],
        },
    }, { profile_url: profileUrl });

    assert.equal(result.ok, true, JSON.stringify(result));
    assert.equal(result.persistence, 'remote_saved');
    assert.equal(result.draftId, '12345');
    const save = calls.find((call) => call.href.endsWith('/postedit/saveArticle'));
    const savedBody = JSON.parse(String(save.body));
    assert.equal(savedBody.status, 2);
    assert.deepEqual(savedBody.cover_images, ['https://csdnimg.cn/b.jpg']);
    assert.ok(savedBody.content.includes('https://csdnimg.cn/b.jpg'));
    assert.ok(! calls.some((call) => /publish/i.test(call.href)));
});

test('jianshu and csdn API adapters reject a wrong account before any network write', async () => {
    const { runSelfMediaApiDraft } = await import('../../browser-extension/src/adapters/self-media-api.js');
    installDraftApiDom('https://www.jianshu.com/writer', 'https://www.jianshu.com/u/another', '另一个账号');
    let fetchCount = 0;
    globalThis.fetch = async () => { fetchCount += 1; return new Response('{}'); };
    const result = await runSelfMediaApiDraft('jianshu_article', {
        schema_version: 3, title: '标题', body_html: '<p>正文</p>', media_manifest: [],
    }, { profile_url: 'https://www.jianshu.com/u/hengjia' });
    assert.equal(result.ok, false);
    assert.equal(result.code, 'account_mismatch');
    assert.equal(fetchCount, 0);
});

test('draft adapters contain no programmatic final-publish click', async () => {
    for (const file of ['self-media-api.js', 'self-media-dom-draft.js']) {
        const source = await readFile(new URL(`../../browser-extension/src/adapters/${file}`, import.meta.url), 'utf8');
        assert.ok(!/\.click\s*\(/.test(source), `${file} must not click any control`);
        assert.ok(!/querySelector\([^)]*(?:确认发布|预览并发布|立即发布)/.test(source), `${file} must not search final publish controls`);
    }
});
