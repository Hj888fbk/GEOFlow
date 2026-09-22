'use strict';

const platforms = Object.freeze({
  sohu_media: definition('搜狐号', ['mp.sohu.com'], 'https://mp.sohu.com/mpfe/v4/contentManagement/news/addarticle', ['input[placeholder*="标题"]'], ['[contenteditable="true"]'], ['button:has-text("存草稿")', 'button:has-text("保存草稿")']),
  netease_media: definition('网易号', ['mp.163.com'], 'https://mp.163.com/', ['input[placeholder*="标题"]'], ['[contenteditable="true"]'], ['button:has-text("存草稿")', 'button:has-text("保存")']),
  toutiao: definition('头条号', ['mp.toutiao.com'], 'https://mp.toutiao.com/profile_v4/graphic/publish', ['input[placeholder*="标题"]'], ['[contenteditable="true"]'], ['button:has-text("存草稿")']),
  baijiahao: definition('百家号', ['baijiahao.baidu.com'], 'https://baijiahao.baidu.com/builder/rc/edit', ['input[placeholder*="标题"]'], ['[contenteditable="true"]'], ['button:has-text("存草稿")', 'button:has-text("保存")']),
  dayu: definition('大鱼号', ['mp.dayu.com', 'dayu.com'], 'https://mp.dayu.com/', ['input[placeholder*="标题"]'], ['[contenteditable="true"]'], ['button:has-text("存草稿")']),
  qq_penguin: definition('企鹅号', ['om.qq.com'], 'https://om.qq.com/', ['input[placeholder*="标题"]'], ['[contenteditable="true"]'], ['button:has-text("存草稿")']),
  zhihu_column: definition('知乎专栏', ['zhihu.com', 'zhuanlan.zhihu.com'], 'https://zhuanlan.zhihu.com/write', ['textarea[placeholder*="标题"]', 'input[placeholder*="标题"]'], ['[contenteditable="true"]'], ['button:has-text("保存草稿")']),
  jianshu: definition('简书', ['jianshu.com'], 'https://www.jianshu.com/writer', ['input[placeholder*="标题"]'], ['textarea', '[contenteditable="true"]'], ['button:has-text("保存")']),
  csdn: definition('CSDN', ['csdn.net'], 'https://editor.csdn.net/md/', ['input[placeholder*="标题"]'], ['textarea', '[contenteditable="true"]'], ['button:has-text("保存草稿")']),
  douyin: definition('抖音文章', ['douyin.com', 'creator.douyin.com'], 'https://creator.douyin.com/creator-micro/content/publish', ['input[placeholder*="标题"]'], ['[contenteditable="true"]'], ['button:has-text("存草稿")', 'button:has-text("保存草稿")']),
  // 微博：走头条文章编辑器（card.weibo.com/article/v5/editor），支持标题+长文+图片+草稿箱，
  // 与生产账号 editor_url 一致；普通微博首页发布器无标题且字数受限，不采用。
  // 以下选择器按微博公开页面结构的常识编写，全部待实测验证。
  weibo: definition('微博', ['weibo.com', 'www.weibo.com', 'card.weibo.com'], 'https://card.weibo.com/article/v5/editor#/', [
    // 待实测验证：头条文章编辑器标题输入框
    'input[placeholder*="标题"]',
    'textarea[placeholder*="标题"]',
  ], [
    // 待实测验证：头条文章编辑器正文（富文本编辑区）
    '[contenteditable="true"]',
  ], [
    // 待实测验证：编辑器工具栏「存草稿」；草稿箱在 #/draft 路由
    'button:has-text("存草稿")',
    'text=存草稿',
  ], {
    // 待实测验证：微博发布器的图片 input 可能是隐藏节点且未必带 accept 属性
    imageInputSelectors: ['input[type="file"][accept*="image"]', 'input[type="file"]'],
    upload: { mode: 'input' },
  }),
});

function definition(label, hosts, editorUrl, titleSelectors, bodySelectors, draftSelectors, options = {}) {
  const descriptor = {
    label,
    hosts: Object.freeze(hosts),
    editorUrl,
    loginMarkers: Object.freeze(['input[type="password"]', 'text=登录', 'text=扫码登录']),
    captchaMarkers: Object.freeze(['iframe[src*="captcha"]', '[class*="captcha"]', 'text=安全验证', 'text=百度安全验证', 'text=请完成验证', 'text=实名验证']),
    identitySelectors: Object.freeze(['[data-account-id]', '[data-user-id]', '[data-uid]', 'a[href*="/profile/"]', 'a[href*="/user/"]', 'a[href*="/u/"]', 'a[href*="/people/"]']),
    titleSelectors: Object.freeze(titleSelectors),
    bodySelectors: Object.freeze(bodySelectors),
    imageInputSelectors: Object.freeze(options.imageInputSelectors || ['input[type="file"][accept*="image"]']),
    upload: normalizeUpload(options.upload),
    draftSelectors: Object.freeze(draftSelectors),
    draftListSelectors: Object.freeze(['a:has-text("草稿")', 'text=草稿箱']),
  };
  if (options.uploadReady) {
    const ready = {};
    if (options.uploadReady.positiveText) ready.positiveText = Object.freeze(options.uploadReady.positiveText);
    if (options.uploadReady.seenThenGone) ready.seenThenGone = Object.freeze(options.uploadReady.seenThenGone);
    if (options.uploadReady.maxAttempts) ready.maxAttempts = options.uploadReady.maxAttempts;
    if (options.uploadReady.intervalMs) ready.intervalMs = options.uploadReady.intervalMs;
    descriptor.uploadReady = Object.freeze(ready);
  }
  return Object.freeze(descriptor);
}

// 每平台上传策略：'input'（默认，CDP setFileInputFiles 直传）、
// 'button-then-input'（先点击「插入图片」类按钮再 setFileInputFiles）、
// 'filechooser'（拦截文件选择框后回填）。
function normalizeUpload(upload = {}) {
  const normalized = { mode: upload.mode || 'input' };
  if (upload.buttonSelectors) normalized.buttonSelectors = Object.freeze(upload.buttonSelectors);
  if (upload.triggerSelectors) normalized.triggerSelectors = Object.freeze(upload.triggerSelectors);
  return Object.freeze(normalized);
}

module.exports = { platforms };
