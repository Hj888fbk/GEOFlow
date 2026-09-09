import { observeSelfMediaArticleResult, runSelfMediaArticleAdapter } from './self-media-article.js';
import { runSelfMediaApiDraft } from './self-media-api.js';
import { observeZhihuAnswerResult, runZhihuAnswerAdapter } from './zhihu-answer.js';
import { observeSelfMediaDomResult, runSelfMediaDomDraft } from './self-media-dom-draft.js';

const adapters = new Map([
    ['zhihu_answer', { execute: runZhihuAnswerAdapter, observe: observeZhihuAnswerResult, kind: 'legacy_zhihu' }],
    // 百家号/搜狐号走平台官方草稿 API（格式零损耗、图片走上传接口）；DOM 填充仅作遗留参考。
    ['baijiahao_article', { execute: runSelfMediaApiDraft, observe: observeSelfMediaArticleResult, kind: 'self_media_article' }],
    ['sohu_media_article', { execute: runSelfMediaApiDraft, observe: observeSelfMediaArticleResult, kind: 'self_media_article' }],
    ['zhihu_column_article', { execute: runSelfMediaDomDraft, observe: observeSelfMediaDomResult, kind: 'self_media_article' }],
    ['csdn_article', { execute: runSelfMediaApiDraft, observe: observeSelfMediaDomResult, kind: 'self_media_article' }],
    ['toutiao_article', { execute: runSelfMediaDomDraft, observe: observeSelfMediaDomResult, kind: 'self_media_article' }],
    ['netease_media_article', { execute: runSelfMediaDomDraft, observe: observeSelfMediaDomResult, kind: 'self_media_article' }],
    ['qq_penguin_article', { execute: runSelfMediaDomDraft, observe: observeSelfMediaDomResult, kind: 'self_media_article' }],
    ['dayu_article', { execute: runSelfMediaDomDraft, observe: observeSelfMediaDomResult, kind: 'self_media_article' }],
    ['jianshu_article', { execute: runSelfMediaApiDraft, observe: observeSelfMediaDomResult, kind: 'self_media_article' }],
    ['douyin_article', { execute: runSelfMediaDomDraft, observe: observeSelfMediaDomResult, kind: 'self_media_article' }],
]);

export function adapterForAction(action) {
    return adapters.get(String(action)) ?? null;
}

export function supportedAdapterActions() {
    return [...adapters.keys()];
}
