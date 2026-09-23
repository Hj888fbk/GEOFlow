'use strict';

// 在账号登录 webview 内运行的探针脚本（借鉴 auth helper 的 checkLogin 模式）：
// 宿主页面发来 'geoflow-probe' 即做一次即时检测，结果经 sendToHost 回传。
// 只读 DOM，不触碰任何输入/提交；安全围栏（编辑器 URL 校验）留在草稿执行阶段。
const { ipcRenderer } = require('electron');

const GENERIC_AVATAR = ['img[class*="avatar" i]', '[class*="avatar" i] img', 'img[src*="avatar" i]', '[class*="user" i] img'];
const GENERIC_NAME = ['.user-name', '[class*="nickname" i]', '[class*="user-name" i]', '[class*="userName" i]', '[class*="user_name" i]'];
const LOGIN_HINTS = ['扫码登录', '密码登录', '验证码登录', '登录后', '注册/登录'];
// 昵称黑名单：页面栏目标题/按钮文案，不是账号名（实测曾把「抖音创作者中心·创作者」当成昵称）
const NAME_REJECT = /创作者中心|创作中心|工作台|媒体平台|扫码|登录|注册|首页|个人中心|管理中心|创作者服务平台/;
const NAME_REJECT_EXACT = /^(百家号|搜狐号|网易号|头条号|企鹅号|知乎|简书|CSDN|大鱼号|抖音|微信|微博)$/;

function firstNode(selectors) {
  for (const selector of selectors || []) {
    try {
      const node = document.querySelector(selector);
      if (node) return node;
    } catch { /* 忽略非法选择器 */ }
  }
  return null;
}

function cleanName(raw) {
  const text = String(raw || '').replace(/\s+/g, ' ').trim();
  if (!text || text.length > 30) return '';
  if (!/[\p{L}\p{N}]/u.test(text)) return '';
  if (NAME_REJECT.test(text) || NAME_REJECT_EXACT.test(text)) return '';
  return text;
}

function firstText(selectors) {
  for (const selector of selectors || []) {
    try {
      for (const node of document.querySelectorAll(selector)) {
        const text = cleanName(node.textContent);
        if (text) return text;
      }
    } catch { /* 忽略非法选择器 */ }
  }
  return '';
}

function extractUid(raw) {
  const text = String(raw || '').replace(/\s+/g, ' ').trim();
  if (!text) return '';
  // 「抖音号：abc123」→ abc123；避免把中文前缀带进去
  const match = text.match(/[A-Za-z0-9][A-Za-z0-9_.-]{2,63}/);
  return match ? match[0] : '';
}

function probe(config) {
  const text = document.body ? document.body.innerText || '' : '';
  const avatarNode = firstNode([...(config.avatarSelectors || []), ...GENERIC_AVATAR]);
  const avatar = avatarNode ? String(avatarNode.currentSrc || avatarNode.src || '').trim() : '';
  let name = firstText([...(config.nameSelectors || []), ...GENERIC_NAME]);
  let uid = '';
  for (const selector of config.uidSelectors || []) {
    try {
      for (const node of document.querySelectorAll(selector)) {
        uid = extractUid(node.textContent);
        if (uid) break;
      }
    } catch { /* 忽略非法选择器 */ }
    if (uid) break;
  }
  // 登录页特征：没有任何头像/名字/UID，且页面在引导登录
  const onLoginPage = !avatar && !name && !uid && LOGIN_HINTS.some((hint) => text.includes(hint));
  const loggedIn = Boolean((avatar || name || uid) && !onLoginPage);
  return {
    loggedIn,
    name,
    uid,
    avatar: avatar.slice(0, 500),
    url: String(location.href),
    title: String(document.title || '').slice(0, 120),
  };
}

// 官方 API 兜底（与 login-probes.cjs 的 interpretApiProbe 同一口径；preload 沙箱无法 require 宿主模块，
// 这里内联最小实现）：DOM 判定未登录且配置了 apiProbe 时，带会话 Cookie 请求官方接口，
// 只判断登录态，不读取/存储/回传任何用户信息。
async function probeWithApiFallback(config) {
  const result = probe(config);
  const apiProbe = config.apiProbe;
  if (result.loggedIn || !apiProbe?.url) return result;
  try {
    const response = await fetch(apiProbe.url, { credentials: 'include', headers: { accept: 'application/json' } });
    if (response.status === 200) {
      const body = await response.json().catch(() => null);
      const fields = Array.isArray(apiProbe.userFields) ? apiProbe.userFields : [];
      const hit = body && typeof body === 'object' && fields.some((field) => body[field] !== undefined && body[field] !== null && body[field] !== '');
      if (hit) result.loggedIn = true;
    }
  } catch { /* 网络失败时保持 DOM 结论 */ }
  return result;
}

ipcRenderer.on('geoflow-probe', (_event, rawConfig) => {
  let config = {};
  try { config = typeof rawConfig === 'string' ? JSON.parse(rawConfig) : (rawConfig || {}); } catch { config = {}; }
  probeWithApiFallback(config).then((result) => {
    ipcRenderer.sendToHost('geoflow-probe-result', result);
  }).catch((error) => {
    ipcRenderer.sendToHost('geoflow-probe-result', { loggedIn: false, name: '', uid: '', avatar: '', url: '', title: '', error: String(error && error.message || error) });
  });
});
