'use strict';

// 各平台登录入口与独立维护的 DOM 探针配置。
// 选择器只描述公开页面的头像、昵称和账号标识，不读取密码、Cookie 或 Token。
// loginUrl 用平台首页/工作台（登录态下会出现头像/昵称），不用编辑器地址。
const LOGIN_PROBES = Object.freeze({
  baijiahao: {
    label: '百家号',
    loginUrl: 'https://baijiahao.baidu.com/',
    avatarSelectors: ['img.UjPPKm89R4RrZTKhwG5H', '.user-pic img', '.avatar img', 'img[class*="avatar" i]'],
    nameSelectors: ['.user-name', '.p7Psc5P3uJ5lyxeI0ETR'],
    uidSelectors: [],
  },
  sohu_media: {
    label: '搜狐号',
    loginUrl: 'https://mp.sohu.com/',
    avatarSelectors: ['.user-pic img', '.user-pic'],
    nameSelectors: ['.user-name'],
    uidSelectors: [],
  },
  netease_media: {
    label: '网易号',
    loginUrl: 'https://mp.163.com/',
    avatarSelectors: ['.topBar__user>span>img', '.topBar__user img'],
    nameSelectors: ['.topBar__user span', '.user-name'],
    uidSelectors: [],
  },
  toutiao: {
    label: '头条号',
    loginUrl: 'https://mp.toutiao.com/',
    avatarSelectors: ['.auth-avator-img', 'img.auth-avator-img'],
    nameSelectors: ['.auth-avator-name'],
    uidSelectors: [],
  },
  qq_penguin: {
    label: '企鹅号',
    loginUrl: 'https://om.qq.com/',
    avatarSelectors: ['div.omui-avatar img', '.omui-avatar img'],
    nameSelectors: ['span.usernameText-cls2j9OE', '[class*="usernameText" i]'],
    uidSelectors: [],
  },
  zhihu_column: {
    label: '知乎专栏',
    loginUrl: 'https://www.zhihu.com/creator',
    avatarSelectors: ['img.AppHeader-profileAvatar', '.AppHeader-profileAvatar'],
    nameSelectors: ['.AppHeader-profile .Popover button', '[class*="AppHeader-profile"] [class*="name" i]'],
    uidSelectors: [],
    // 官方 API 兜底：DOM 标记全部失效时探测登录态；只判断登录与否，不读取/存储任何用户信息
    apiProbe: Object.freeze({
      url: 'https://www.zhihu.com/api/v4/me',
      userFields: ['id', 'url_token', 'name'],
    }),
  },
  jianshu: {
    label: '简书',
    loginUrl: 'https://www.jianshu.com/',
    avatarSelectors: ['.user img', '.user .avatar img', 'a.user img'],
    nameSelectors: ['.main-top .name', '.user .name', '.nickname'],
    uidSelectors: [],
  },
  csdn: {
    label: 'CSDN',
    loginUrl: 'https://mp.csdn.net/',
    avatarSelectors: ['.hasAvatar img', '.hasAvatar', '.avatar img'],
    nameSelectors: ['.nick-name', '.user-name', '[class*="nickName" i]'],
    uidSelectors: [],
    // 官方 API 兜底（待实测验证 g-api 用户接口路径）；只判断登录态，不读取/存储任何用户信息
    apiProbe: Object.freeze({
      url: 'https://g-api.csdn.net/v1/user/info',
      userFields: ['username', 'nickname', 'code'],
    }),
  },
  dayu: {
    label: '大鱼号',
    loginUrl: 'https://mp.dayu.com/',
    avatarSelectors: ['img[class*="avatar" i]', '.avatar img', '.user-avatar img'],
    nameSelectors: ['.user-name', '[class*="nickname" i]', '.nick-name'],
    uidSelectors: [],
  },
  douyin: {
    label: '抖音文章',
    loginUrl: 'https://creator.douyin.com/',
    avatarSelectors: ['.img-PeynF_ img', '.img-PeynF_', 'img[class*="avatar" i]'],
    nameSelectors: ['.name-_lSSDc', '[class*="name-" i]'],
    uidSelectors: ['.unique_id-EuH8eA', '[class*="unique_id" i]'],
  },
  weibo: {
    label: '微博',
    loginUrl: 'https://weibo.com/',
    // 待实测验证：微博首页登录态头像/昵称区域
    avatarSelectors: ['.woo-avatar-img', 'img[class*="avatar" i]'],
    nameSelectors: ['[class*="nickname" i]', '.woo-ellipsis'],
    uidSelectors: [],
  },
});

const LOGIN_HOST_SUFFIXES = Object.freeze([
  'baidu.com',
  'sohu.com',
  '163.com',
  'toutiao.com',
  'bytedance.com',
  'qq.com',
  'zhihu.com',
  'jianshu.com',
  'csdn.net',
  'dayu.com',
  'uc.cn',
  'douyin.com',
  'weibo.com',
]);

function isAllowedLoginUrl(candidate) {
  if (candidate === 'about:blank') return true;
  try {
    const url = new URL(candidate);
    if (url.protocol !== 'https:') return false;
    const host = url.hostname.toLowerCase().replace(/\.$/, '');
    return LOGIN_HOST_SUFFIXES.some((suffix) => host === suffix || host.endsWith(`.${suffix}`));
  } catch {
    return false;
  }
}

// 解释官方 API 探测结果。只判断登录态，不读取/存储响应体中的任何用户信息：
// HTTP 200 且存在任一用户字段 = 已登录；401/403 = 未登录；其余一律按未知处理。
function interpretApiProbe(status, body, userFields = []) {
  if (status === 401 || status === 403) return 'logged_out';
  if (status !== 200) return 'unknown';
  const fields = Array.isArray(userFields) ? userFields : [];
  const hit = fields.some((field) => {
    const value = body && typeof body === 'object' ? body[field] : undefined;
    return value !== undefined && value !== null && value !== '';
  });
  return hit ? 'logged_in' : 'unknown';
}

// 双模登录检测的 API 侧：fetch credentials:'include' 带会话 Cookie 请求官方接口。
// 返回值只含登录态结论与状态码，绝不回传响应体内容。
async function probeApiLogin(apiProbe, fetchImpl) {
  if (!apiProbe?.url) return { loggedIn: null, verdict: 'unknown', status: 0 };
  const doFetch = typeof fetchImpl === 'function' ? fetchImpl : globalThis.fetch?.bind(globalThis);
  if (!doFetch) return { loggedIn: null, verdict: 'unknown', status: 0 };
  let response;
  try {
    response = await doFetch(apiProbe.url, { credentials: 'include', headers: { accept: 'application/json' } });
  } catch {
    return { loggedIn: null, verdict: 'unknown', status: 0 };
  }
  let body = null;
  if (response.status === 200) {
    try { body = await response.json(); } catch { body = null; }
  }
  const verdict = interpretApiProbe(response.status, body, apiProbe.userFields);
  return {
    loggedIn: verdict === 'logged_in' ? true : verdict === 'logged_out' ? false : null,
    verdict,
    status: response.status,
  };
}

module.exports = { LOGIN_PROBES, isAllowedLoginUrl, interpretApiProbe, probeApiLogin };
