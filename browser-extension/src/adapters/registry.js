import { observeSelfMediaArticleResult, runSelfMediaArticleAdapter } from './self-media-article.js';
import { runSelfMediaApiDraft } from './self-media-api.js';
import { observeZhihuAnswerResult, runZhihuAnswerAdapter } from './zhihu-answer.js';
import { observeSelfMediaDomResult, runSelfMediaDomDraft } from './self-media-dom-draft.js';

const adapters = new Map([
    ['zhihu_answer', { execute: runZhihuAnswerAdapter, observe: observeZhihuAnswerResult, kind: 'legacy_zhihu', mode: 'dom' }],
    // 百家号/搜狐号走平台官方草稿 API（格式零损耗、图片走上传接口）；DOM 填充仅作遗留参考。
    // mode=api 的可被「一键同步」批量自动执行；mode=dom 的仍需人工逐条操作。
    ['baijiahao_article', { execute: runSelfMediaApiDraft, observe: observeSelfMediaArticleResult, kind: 'self_media_article', mode: 'api' }],
    ['sohu_media_article', { execute: runSelfMediaApiDraft, observe: observeSelfMediaArticleResult, kind: 'self_media_article', mode: 'api' }],
    ['zhihu_column_article', { execute: runSelfMediaDomDraft, observe: observeSelfMediaDomResult, kind: 'self_media_article', mode: 'dom' }],
    ['csdn_article', { execute: runSelfMediaApiDraft, observe: observeSelfMediaDomResult, kind: 'self_media_article', mode: 'api' }],
    ['toutiao_article', { execute: runSelfMediaDomDraft, observe: observeSelfMediaDomResult, kind: 'self_media_article', mode: 'dom' }],
    ['netease_media_article', { execute: runSelfMediaDomDraft, observe: observeSelfMediaDomResult, kind: 'self_media_article', mode: 'dom' }],
    ['qq_penguin_article', { execute: runSelfMediaDomDraft, observe: observeSelfMediaDomResult, kind: 'self_media_article', mode: 'dom' }],
    ['dayu_article', { execute: runSelfMediaDomDraft, observe: observeSelfMediaDomResult, kind: 'self_media_article', mode: 'dom' }],
    ['jianshu_article', { execute: runSelfMediaApiDraft, observe: observeSelfMediaDomResult, kind: 'self_media_article', mode: 'api' }],
    ['douyin_article', { execute: runSelfMediaDomDraft, observe: observeSelfMediaDomResult, kind: 'self_media_article', mode: 'dom' }],
]);

export function adapterForAction(action) {
    return adapters.get(String(action)) ?? null;
}

export function supportedAdapterActions() {
    return [...adapters.keys()];
}

/** 该工作单是否支持「一键同步」自动执行（API 直发适配器 + ready 状态）。 */
export function isBatchSyncable(task) {
    if (String(task?.status ?? '') !== 'ready') return false;
    const action = task?.publication_payload?.target_action;

    return adapterForAction(action)?.mode === 'api';
}
