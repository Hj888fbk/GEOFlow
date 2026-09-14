'use strict';

const ui = {
  connection: document.getElementById('connection'), instance: document.getElementById('instance'), pairing: document.getElementById('pairing'),
  userCode: document.getElementById('user-code'), accounts: document.getElementById('accounts'), sync: document.getElementById('sync'), message: document.getElementById('message'),
};
let authorization = null;
let accounts = [];
let updateMetadata = null;

window.geoflowPublisher.onDeepLinkError((code) => setMessage(`无法打开账号登录：${code}`, true));
void initialize();

async function initialize() {
  const state = await window.geoflowPublisher.state();
  ui.instance.textContent = state.instance;
  setConnected(state.connected);
  if (state.connected) {
    await loadAccounts();
    await checkUpdate(state.version);
  }
}

document.getElementById('discover').addEventListener('click', async () => run(async () => {
  const found = await window.geoflowPublisher.discover();
  await window.geoflowPublisher.setInstance(found.instance);
  ui.instance.textContent = found.instance;
  if (!ui.connection.classList.contains('online')) await beginAuthorization();
}, '已找到本机 GEOFlow'));

document.getElementById('refresh').addEventListener('click', () => run(loadAccounts, '账号状态已刷新'));
document.getElementById('approve').addEventListener('click', () => authorization && window.geoflowPublisher.openVerification(authorization.verification_uri_complete));
document.getElementById('poll').addEventListener('click', () => run(async () => {
  if (!authorization) throw new Error('请先检测本机并申请设备码');
  const token = await window.geoflowPublisher.exchange(authorization.device_code);
  setConnected(Boolean(token.token));
  ui.pairing.classList.add('hidden');
  await loadAccounts();
}, '连接已完成'));

ui.sync.addEventListener('click', () => run(async () => {
  const selected = [...document.querySelectorAll('.account-select:checked')].map((input) => Number(input.value));
  if (!selected.length) throw new Error('请至少选择一个账号');
  const results = await window.geoflowPublisher.syncAll(selected);
  await loadAccounts();
  return `已处理 ${results.length} 条草稿任务`;
}));
document.getElementById('install-update').addEventListener('click', () => run(
  () => window.geoflowPublisher.installUpdate(updateMetadata),
  '安装程序已通过校验并启动',
));

async function beginAuthorization() {
  authorization = await window.geoflowPublisher.authorize();
  ui.userCode.textContent = authorization.user_code;
  ui.pairing.classList.remove('hidden');
}

async function loadAccounts() {
  const data = await window.geoflowPublisher.accounts();
  accounts = data.accounts || [];
  ui.accounts.replaceChildren(...accounts.map(accountNode));
  if (!accounts.length) ui.accounts.innerHTML = '<p class="empty">请先在发布中心创建平台账号。</p>';
  ui.sync.disabled = !accounts.length;
}

async function checkUpdate(currentVersion) {
  const result = await window.geoflowPublisher.checkUpdate();
  updateMetadata = result.desktop_update;
  if (updateMetadata?.available && compareVersions(updateMetadata.version, currentVersion) > 0) {
    document.getElementById('update-card').classList.remove('hidden');
    document.getElementById('update-version').textContent = `当前 ${currentVersion}，可更新到 ${updateMetadata.version}。启动安装前会校验签名和 SHA-256。`;
  }
}

function compareVersions(left, right) {
  const a = String(left).split('.').map(Number);
  const b = String(right).split('.').map(Number);
  for (let index = 0; index < Math.max(a.length, b.length); index += 1) {
    const delta = (a[index] || 0) - (b[index] || 0);
    if (delta) return delta;
  }
  return 0;
}

function accountNode(account) {
  const row = document.createElement('div'); row.className = 'account';
  const select = document.createElement('input'); select.type = 'checkbox'; select.value = account.id; select.className = 'account-select';
  const label = document.createElement('div');
  const name = document.createElement('strong'); name.textContent = account.account_name;
  const platform = document.createElement('small'); platform.textContent = `${account.platform} · ${account.persona?.name || ''}`;
  label.append(name, platform);
  const status = document.createElement('span'); status.className = `status ${account.session?.status || ''}`; status.textContent = account.session?.status === 'authorized' ? '已登录' : '需要登录';
  const login = document.createElement('button'); login.className = 'secondary'; login.textContent = '打开登录'; login.addEventListener('click', () => window.geoflowPublisher.openLogin(account));
  const bind = document.createElement('button'); bind.className = 'secondary'; bind.textContent = account.session?.status === 'authorized' ? '重新检测' : '检测并绑定'; bind.addEventListener('click', () => run(async () => { await window.geoflowPublisher.bindAccount(account); await loadAccounts(); }, '账号已确认绑定'));
  row.append(select, label, status, login, bind); return row;
}

function setConnected(connected) {
  ui.connection.textContent = connected ? '已连接' : '未连接';
  ui.connection.classList.toggle('online', connected);
  if (!connected) void beginAuthorization().catch((error) => setMessage(error.message, true));
}

async function run(operation, success = null) {
  try { setMessage('处理中…'); const result = await operation(); setMessage(typeof result === 'string' ? result : (success || '已完成')); return result; }
  catch (error) { setMessage(error.message || String(error), true); throw error; }
}

function setMessage(message, failed = false) { ui.message.textContent = message; ui.message.style.color = failed ? '#b42318' : '#667085'; }
