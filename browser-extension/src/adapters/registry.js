import { observeSelfMediaArticleResult, runSelfMediaArticleAdapter } from './self-media-article.js';
import { observeZhihuAnswerResult, runZhihuAnswerAdapter } from './zhihu-answer.js';

const adapters = new Map([
    ['zhihu_answer', { execute: runZhihuAnswerAdapter, observe: observeZhihuAnswerResult, kind: 'legacy_zhihu' }],
    ['baijiahao_article', { execute: runSelfMediaArticleAdapter, observe: observeSelfMediaArticleResult, kind: 'self_media_article' }],
    ['sohu_media_article', { execute: runSelfMediaArticleAdapter, observe: observeSelfMediaArticleResult, kind: 'self_media_article' }],
]);

export function adapterForAction(action) {
    return adapters.get(String(action)) ?? null;
}

export function supportedAdapterActions() {
    return [...adapters.keys()];
}
