import { observeSelfMediaArticleResult, runSelfMediaArticleAdapter } from './self-media-article.js';
import { runSelfMediaApiDraft } from './self-media-api.js';
import { observeZhihuAnswerResult, runZhihuAnswerAdapter } from './zhihu-answer.js';

const adapters = new Map([
    ['zhihu_answer', { execute: runZhihuAnswerAdapter, observe: observeZhihuAnswerResult, kind: 'legacy_zhihu' }],
    // 百家号/搜狐号走平台官方草稿 API（格式零损耗、图片走上传接口）；DOM 填充仅作遗留参考。
    ['baijiahao_article', { execute: runSelfMediaApiDraft, observe: observeSelfMediaArticleResult, kind: 'self_media_article' }],
    ['sohu_media_article', { execute: runSelfMediaApiDraft, observe: observeSelfMediaArticleResult, kind: 'self_media_article' }],
]);

export function adapterForAction(action) {
    return adapters.get(String(action)) ?? null;
}

export function supportedAdapterActions() {
    return [...adapters.keys()];
}
