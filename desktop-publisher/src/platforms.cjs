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
});

function definition(label, hosts, editorUrl, titleSelectors, bodySelectors, draftSelectors) {
  return Object.freeze({
    label,
    hosts: Object.freeze(hosts),
    editorUrl,
    loginMarkers: Object.freeze(['input[type="password"]', 'text=登录', 'text=扫码登录']),
    captchaMarkers: Object.freeze(['iframe[src*="captcha"]', '[class*="captcha"]', 'text=安全验证']),
    identitySelectors: Object.freeze(['[data-account-id]', '[data-user-id]', '[data-uid]', 'a[href*="/profile/"]', 'a[href*="/user/"]', 'a[href*="/u/"]', 'a[href*="/people/"]']),
    titleSelectors: Object.freeze(titleSelectors),
    bodySelectors: Object.freeze(bodySelectors),
    imageInputSelectors: Object.freeze(['input[type="file"][accept*="image"]']),
    draftSelectors: Object.freeze(draftSelectors),
    draftListSelectors: Object.freeze(['a:has-text("草稿")', 'text=草稿箱']),
  });
}

module.exports = { platforms };
