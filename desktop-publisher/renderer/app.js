'use strict';

const api = window.geoflowPublisher;

const ui = {
  version: document.getElementById('version'),
  instance: document.getElementById('instance'),
  connection: document.getElementById('connection'),
  pairing: document.getElementById('pairing'),
  userCode: document.getElementById('user-code'),
  groups: document.getElementById('groups'),
  sync: document.getElementById('sync'),
  autosyncEnabled: document.getElementById('autosync-enabled'),
  autosyncInterval: document.getElementById('autosync-interval'),
  tabs: document.getElementById('tabs'),
  host: document.getElementById('webview-host'),
  emptyTip: document.getElementById('empty-tip'),
  log: document.getElementById('log'),
  toast: document.getElementById('toast'),
  modalMask: document.getElementById('modal-mask'),
  modalTitle: document.getElementById('modal-title'),
  modalBody: document.getElementById('modal-body'),
  modalActions: document.getElementById('modal-actions'),
};

const ERROR_MAP = {
  login_required: '还没有检测到登录：请先在右侧页面里完成扫码或密码登录',
  captcha_required: '平台弹出了安全验证，请在页面里手动完成后再检测',
  account_identity_not_detected: '页面已打开，但没识别到头像或昵称——可以点「确认绑定」手动输入昵称完成绑定',
  account_mismatch: '检测到的登录身份与账号中心登记的不一致',
  account_identity_required: '服务端要求先确认账号的公开身份',
  account_payload_invalid: '账号信息不完整：身份、平台、账号名都要填',
  publisher_authorization_required: '请先完成与本机 GEOFlow 的配对连接',
  publisher_account_not_found: '该账号在发布中心不存在或已停用',
  publisher_account_invalid: '账号编号无效',
  no_bound_accounts: '还没有可同步的已绑定账号，请先登录并确认绑定',
  sync_in_progress: '上一轮同步还在进行中，请等它完成',
  no_ready_publications: '目前没有已审核、可同步的稿件。请先在发布中心选择文章和账号，生成后批量审核一次',
  local_instance_unavailable: '连不上本机 GEOFlow（http://127.0.0.1:28080），请确认服务在运行',
  insecure_geoflow_instance: '实例地址只允许 https 或本机 http 地址',
  update_unavailable: '暂时没有可用的更新',
  update_platform_mismatch: '更新包与本机系统不匹配',
  navigation_blocked: '页面地址不在平台允许范围内',
  media_download_url_blocked: '图片下载地址不合法',
};

const SYNC_STATUS_MAP = {
  draft_saved: '草稿已保存',
  awaiting_manual_publish: '等待人工到平台点发布',
  action_required: '需要人工处理',
  account_mismatch: '登录身份不一致',
};

const PLATFORM_COLORS = {
  baijiahao: '#306eff', sohu_media: '#f59e0b', netease_media: '#e11d48', toutiao: '#0f172a',
  qq_penguin: '#12b7f5', zhihu_column: '#0066ff', jianshu: '#ea6f5a', csdn: '#c8161e',
  dayu: '#7c3aed', douyin: '#111827',
};

const state = {
  authorization: null,
  authorizationTimer: null,
  accounts: [],
  personas: [],
  probes: {},
  preloadUrl: '',
  detected: {},       // accountId -> { loggedIn, name, uid, avatar }
  hintShown: {},      // accountId -> true（"可确认绑定"提示只记一次）
  bindBarFor: null,   // 当前展开确认栏的 accountId
  collapsed: {},      // platform -> true
  tabs: new Map(),    // accountId -> { tabEl, webview, timer }
  activeTab: null,
  updateMetadata: null,
};

api.onDeepLinkError((code) => toast(cnError(code), true));
api.onConnectionChanged((connection) => {
  setConnectionState(connection?.connectionStatus || (connection?.connected ? 'connected' : 'offline'), false);
});
api.onOpenAccountTab((accountId) => {
  const account = state.accounts.find((item) => item.id === accountId);
  if (account) openTab(account);
  else log(`收到后台跳转请求，但账号 #${accountId} 不在列表里`, true);
});

void initialize();

async function initialize() {
  try {
    const [appState, probes] = await Promise.all([api.state(), api.probeConfigs()]);
    state.probes = probes || {};
    state.preloadUrl = appState.webviewPreloadPath
      ? 'file:///' + String(appState.webviewPreloadPath).replace(/\\/g, '/')
      : '';
    ui.version.textContent = 'v' + appState.version;
    ui.instance.textContent = appState.instance;
    setConnectionState(appState.connectionStatus || (appState.connected ? 'connected' : 'unpaired'));
    const autoSync = await api.autoSyncConfig().catch(() => null);
    if (autoSync) {
      ui.autosyncEnabled.checked = autoSync.autoSyncEnabled;
      ui.autosyncInterval.value = autoSync.autoSyncIntervalMinutes;
    }
    if (appState.connected) {
      await loadAccounts();
      await checkUpdate(appState.version);
    }
  } catch (error) {
    toast(cnError(error), true);
  }
}

/* ---------------- 配对连接 ---------------- */

document.getElementById('discover').addEventListener('click', () => run(async () => {
  const found = await api.discover();
  await api.setInstance(found.instance);
  ui.instance.textContent = found.instance;
  log('已找到本机 GEOFlow：' + found.instance, false, true);
  const connection = await api.connectionState();
  setConnectionState(connection.connectionStatus);
  if (connection.connected) toast('已连接，无需重新配对');
  else await beginAuthorization();
}));

document.getElementById('approve').addEventListener('click', () => run(async () => {
  if (!state.authorization) throw new Error('请先点「连接本机 GEOFlow」获取配对码');
  await api.openVerification(state.authorization.verification_uri_complete);
  toast('审批完成后会自动连接，无需再回来确认');
}));

async function beginAuthorization() {
  if (state.authorization) return;
  try {
    state.authorization = await api.authorize();
    ui.userCode.textContent = state.authorization.user_code;
    ui.pairing.classList.remove('hidden');
    scheduleAuthorizationPoll();
  } catch (error) {
    toast(cnError(error), true);
  }
}

function scheduleAuthorizationPoll() {
  clearTimeout(state.authorizationTimer);
  const delay = Math.max(2, Number(state.authorization?.interval || 5)) * 1000;
  state.authorizationTimer = setTimeout(() => { void pollAuthorization(); }, delay);
}

async function pollAuthorization() {
  if (!state.authorization) return;
  try {
    const token = await api.exchange(state.authorization.device_code);
    if (token?.pending) {
      scheduleAuthorizationPoll();
      return;
    }
    if (!token?.token) throw new Error('配对结果无效，请重新连接');
    clearTimeout(state.authorizationTimer);
    state.authorization = null;
    setConnectionState('connected');
    ui.pairing.classList.add('hidden');
    log('配对完成，已连接 GEOFlow', false, true);
    await loadAccounts();
    await checkUpdate((await api.state()).version);
  } catch (error) {
    clearTimeout(state.authorizationTimer);
    state.authorization = null;
    ui.pairing.classList.add('hidden');
    setConnectionState('unpaired', false);
    toast(cnError(error), true);
  }
}

function setConnectionState(status, autoAuthorize = true) {
  const connected = status === 'connected';
  const offline = status === 'offline';
  ui.connection.textContent = connected ? '已连接' : (offline ? 'GEOFlow 未启动' : '未连接');
  ui.connection.className = 'badge ' + (connected ? 'badge-green' : (offline ? 'badge-amber' : 'badge-gray'));
  if (connected) ui.connection.classList.add('online');
  if (!connected && !offline && autoAuthorize) void beginAuthorization();
}

/* ---------------- 账号列表（按平台分组） ---------------- */

document.getElementById('refresh').addEventListener('click', () => run(async () => {
  await loadAccounts();
  toast('账号状态已刷新');
}));

async function loadAccounts() {
  const [accountData, personaData] = await Promise.all([
    api.accounts(),
    api.personas().catch(() => ({ personas: [] })),
  ]);
  state.accounts = accountData.accounts || [];
  state.personas = personaData.personas || [];
  renderGroups();
}

function platformLabel(platform) {
  return state.probes[platform]?.label || platform;
}

function groupedAccounts() {
  const order = Object.keys(state.probes);
  const groups = new Map(order.map((platform) => [platform, []]));
  for (const account of state.accounts) {
    if (!groups.has(account.platform)) groups.set(account.platform, []);
    groups.get(account.platform).push(account);
  }
  return [...groups.entries()].sort((a, b) => {
    const ia = order.indexOf(a[0]);
    const ib = order.indexOf(b[0]);
    return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib);
  });
}

function renderGroups() {
  const groups = groupedAccounts();
  ui.groups.replaceChildren(...groups.map(([platform, accounts]) => groupNode(platform, accounts)));
  if (!groups.length) {
    const empty = document.createElement('div');
    empty.className = 'empty-note';
    empty.textContent = '暂时没有可用的平台配置。';
    ui.groups.appendChild(empty);
  }
  const boundCount = state.accounts.filter((account) => account.session?.status === 'authorized').length;
  ui.sync.disabled = boundCount === 0;
  ui.sync.textContent = boundCount > 0
    ? `同步全部已审核草稿（${boundCount} 个账号）`
    : '请先登录并绑定账号';
}

function groupNode(platform, accounts) {
  const group = document.createElement('div');
  const collapsed = state.collapsed[platform] ?? accounts.length === 0;
  state.collapsed[platform] = collapsed;
  group.className = 'group' + (collapsed ? ' collapsed' : '');

  const head = document.createElement('div');
  head.className = 'group-head';
  const dot = document.createElement('span');
  dot.className = 'platform-dot';
  dot.style.background = PLATFORM_COLORS[platform] || '#2563eb';
  const title = document.createElement('span');
  title.className = 'group-title';
  title.textContent = platformLabel(platform);
  const count = document.createElement('span');
  count.className = 'group-count';
  count.textContent = `${accounts.length} 个账号`;
  const add = document.createElement('button');
  add.className = 'group-add';
  add.textContent = '+ 添加';
  add.title = `新增一个${platformLabel(platform)}账号`;
  add.addEventListener('click', (event) => {
    event.stopPropagation();
    openAddModal(platform);
  });
  head.append(dot, title, count, add);
  head.addEventListener('click', () => {
    state.collapsed[platform] = !state.collapsed[platform];
    group.classList.toggle('collapsed');
  });

  const body = document.createElement('div');
  body.className = 'group-body';
  if (!accounts.length) {
    const none = document.createElement('div');
    none.className = 'group-empty';
    none.textContent = '暂无账号，点右上角「+ 添加」。';
    body.appendChild(none);
  } else {
    for (const account of accounts) body.appendChild(accountNode(account));
  }

  group.append(head, body);
  return group;
}

function accountNode(account) {
  const detected = state.detected[account.id] || {};
  const bound = account.session?.status === 'authorized';

  const card = document.createElement('div');
  card.className = 'account';

  const top = document.createElement('div');
  top.className = 'account-top';
  const name = document.createElement('span');
  name.className = 'account-name';
  name.textContent = account.account_name;
  const del = document.createElement('button');
  del.className = 'account-del';
  del.textContent = '×';
  del.title = '删除这个账号';
  del.addEventListener('click', () => openDeleteModal(account));
  top.append(name, del);

  const meta = document.createElement('div');
  meta.className = 'account-meta';
  meta.textContent = `身份：${account.persona?.name || '未分配'}`;

  const statusRow = document.createElement('div');
  statusRow.className = 'account-status';
  const badge = document.createElement('span');
  if (bound) {
    badge.className = 'badge badge-green';
    badge.textContent = '已绑定';
  } else if (detected.loggedIn) {
    badge.className = 'badge badge-blue';
    badge.textContent = '已登录·待确认';
  } else {
    badge.className = 'badge badge-gray';
    badge.textContent = '未登录';
  }
  statusRow.appendChild(badge);
  if (detected.name || detected.uid) {
    const who = document.createElement('span');
    who.className = 'account-detected';
    who.textContent = '识别到：' + (detected.name || detected.uid);
    statusRow.appendChild(who);
  }

  const actions = document.createElement('div');
  actions.className = 'account-actions';
  const addBindButton = (label, primary = false) => {
    const bindBtn = document.createElement('button');
    bindBtn.className = primary ? 'btn btn-small btn-primary' : 'btn btn-small';
    bindBtn.textContent = label;
    bindBtn.addEventListener('click', () => {
      state.bindBarFor = state.bindBarFor === account.id ? null : account.id;
      renderGroups();
    });
    actions.appendChild(bindBtn);
  };
  if (bound) {
    const openBtn = document.createElement('button');
    openBtn.className = 'btn btn-small';
    openBtn.textContent = state.tabs.has(account.id) ? '切换到平台' : '打开平台';
    openBtn.addEventListener('click', () => openTab(account));
    actions.appendChild(openBtn);
    const detectedObserved = detected.uid
      ? { type: 'account_uid', value: detected.uid }
      : (detected.name ? { type: 'homepage_identifier', value: detected.name } : null);
    if (detected.loggedIn && detectedObserved && needsIdentityReplacement(account, detectedObserved)) {
      addBindButton('更新绑定', true);
    }
  } else if (detected.loggedIn) {
    addBindButton('确认绑定', true);
  } else {
    const loginBtn = document.createElement('button');
    loginBtn.className = 'btn btn-small btn-primary';
    loginBtn.textContent = state.tabs.has(account.id) ? '切换到登录页' : '登录并绑定';
    loginBtn.addEventListener('click', () => openTab(account));
    actions.appendChild(loginBtn);
    if (state.tabs.has(account.id)) addBindButton('手动确认');
  }

  card.append(top, meta, statusRow, actions);
  if (state.bindBarFor === account.id) card.appendChild(bindBarNode(account, detected));
  return card;
}

function bindBarNode(account, detected) {
  const bar = document.createElement('div');
  bar.className = 'bindbar';

  const tip = document.createElement('div');
  tip.className = 'bindbar-tip';
  const row = document.createElement('div');
  row.className = 'bindbar-row';

  let input = null;
  const detectedObserved = detected.uid
    ? { type: 'account_uid', value: detected.uid }
    : (detected.name ? { type: 'homepage_identifier', value: detected.name } : null);
  const replacing = detectedObserved ? needsIdentityReplacement(account, detectedObserved) : false;
  if (detected.uid) {
    tip.textContent = `已识别到账号 UID：${detected.uid}，${replacing ? '确认后会替换旧绑定。' : '将以此完成绑定。'}`;
  } else {
    tip.textContent = detected.loggedIn
      ? `确认下面这个昵称与页面里登录的账号一致，再点「${replacing ? '更新绑定' : '确认'}」。`
      : '没自动识别到昵称也没关系：在页面登录后，把平台里显示的昵称填进来即可绑定。';
    input = document.createElement('input');
    input.value = detected.name || '';
    input.placeholder = '平台里显示的昵称，例如：恒佳供水';
    input.maxLength = 120;
    row.appendChild(input);
  }

  const ok = document.createElement('button');
  ok.className = 'btn btn-small btn-primary';
  ok.textContent = '确认';
  ok.addEventListener('click', () => run(async () => {
    const observed = detected.uid
      ? { type: 'account_uid', value: detected.uid }
      : { type: 'homepage_identifier', value: String(input?.value || '').trim() };
    if (!observed.value) throw new Error('请先填写平台里显示的昵称');
    log(`正在${replacing ? '更新' : ''}绑定 ${platformLabel(account.platform)}·${account.account_name}（身份：${observed.value}）…`);
    const replaceExisting = needsIdentityReplacement(account, observed);
    await api.bindAccount(account, observed, replaceExisting);
    state.bindBarFor = null;
    log(`${replacing ? '更新绑定' : '绑定'}成功：${platformLabel(account.platform)}·${account.account_name}`, false, true);
    toast(`「${account.account_name}」${replacing ? '更新绑定' : '绑定'}成功`);
    await loadAccounts();
  }));
  const cancel = document.createElement('button');
  cancel.className = 'btn btn-small';
  cancel.textContent = '取消';
  cancel.addEventListener('click', () => { state.bindBarFor = null; renderGroups(); });
  row.append(ok, cancel);

  bar.append(tip, row);
  if (input) setTimeout(() => input.focus(), 0);
  return bar;
}

/* ---------------- 新增 / 删除账号 ---------------- */

function openModal(title, bodyNodes, actions) {
  ui.modalTitle.textContent = title;
  ui.modalBody.replaceChildren(...bodyNodes);
  ui.modalActions.replaceChildren(...actions);
  ui.modalMask.classList.remove('hidden');
}

function closeModal() {
  ui.modalMask.classList.add('hidden');
}

ui.modalMask.addEventListener('click', (event) => {
  if (event.target === ui.modalMask) closeModal();
});

function openAddModal(platform) {
  if (!state.personas.length) {
    toast('还没有发布身份，请先到后台「账号中心」创建身份', true);
    return;
  }
  const nameLabel = document.createElement('label');
  nameLabel.textContent = '账号名称（自己看得懂就行，如：主号 / 范）';
  const nameInput = document.createElement('input');
  nameInput.maxLength = 160;
  nameInput.placeholder = '输入账号名称';
  nameLabel.appendChild(nameInput);

  const personaLabel = document.createElement('label');
  personaLabel.textContent = '发布身份';
  const personaSelect = document.createElement('select');
  for (const persona of state.personas) {
    const option = document.createElement('option');
    option.value = persona.id;
    option.textContent = persona.name;
    personaSelect.appendChild(option);
  }
  personaLabel.appendChild(personaSelect);

  const cancel = document.createElement('button');
  cancel.className = 'btn';
  cancel.textContent = '取消';
  cancel.addEventListener('click', closeModal);
  const ok = document.createElement('button');
  ok.className = 'btn btn-primary';
  ok.textContent = '创建';
  ok.addEventListener('click', () => run(async () => {
    const accountName = nameInput.value.trim();
    if (!accountName) throw new Error('请填写账号名称');
    await api.createAccount({
      persona_id: Number(personaSelect.value),
      platform,
      account_name: accountName,
    });
    closeModal();
    log(`已创建 ${platformLabel(platform)} 账号「${accountName}」，点「登录」完成授权绑定`, false, true);
    toast('账号已创建');
    state.collapsed[platform] = false;
    await loadAccounts();
  }));

  openModal(`新增${platformLabel(platform)}账号`, [nameLabel, personaLabel], [cancel, ok]);
  setTimeout(() => nameInput.focus(), 0);
}

function openDeleteModal(account) {
  const text = document.createElement('div');
  text.className = 'modal-text';
  text.textContent = `确定删除 ${platformLabel(account.platform)} 的账号「${account.account_name}」吗？账号会停用并解除本机绑定，历史发布记录保留。`;

  const cancel = document.createElement('button');
  cancel.className = 'btn';
  cancel.textContent = '取消';
  cancel.addEventListener('click', closeModal);
  const ok = document.createElement('button');
  ok.className = 'btn btn-primary';
  ok.style.background = '#b42318';
  ok.style.borderColor = '#b42318';
  ok.textContent = '删除';
  ok.addEventListener('click', () => run(async () => {
    await api.deleteAccount(account.id);
    closeModal();
    closeTab(account.id);
    delete state.detected[account.id];
    log(`已删除账号：${platformLabel(account.platform)}·${account.account_name}`, false, true);
    toast('账号已删除');
    await loadAccounts();
  }));

  openModal('删除账号', [text], [cancel, ok]);
}

/* ---------------- 登录标签页（内嵌 webview） ---------------- */

async function openTab(account) {
  const existing = state.tabs.get(account.id);
  if (existing) {
    activateTab(account.id);
    return;
  }
  const probe = state.probes[account.platform];
  if (!probe) {
    toast(`平台 ${platformLabel(account.platform)} 暂不支持内嵌登录`, true);
    return;
  }
  const partition = await api.accountPartition(account.id);

  const tabEl = document.createElement('div');
  tabEl.className = 'tab';
  tabEl.dataset.accountId = account.id;
  const label = document.createElement('span');
  label.textContent = `${platformLabel(account.platform)}·${account.account_name}`;
  const close = document.createElement('button');
  close.className = 'tab-close';
  close.textContent = '×';
  close.title = '关闭标签';
  close.addEventListener('click', (event) => {
    event.stopPropagation();
    closeTab(account.id);
  });
  tabEl.append(label, close);
  tabEl.addEventListener('click', () => activateTab(account.id));

  const webview = document.createElement('webview');
  webview.setAttribute('partition', partition);
  webview.setAttribute('preload', state.preloadUrl);
  webview.setAttribute('src', probe.loginUrl);
  webview.dataset.accountId = account.id;

  webview.addEventListener('ipc-message', (event) => {
    if (event.channel === 'geoflow-probe-result') {
      void handleProbeResult(account, event.args[0] || {});
    }
  });
  webview.addEventListener('did-navigate', () => {
    log(`${platformLabel(account.platform)}·${account.account_name} 页面跳转：${shortUrl(webview.getURL())}`);
  });
  webview.addEventListener('did-fail-load', (event) => {
    if (event.errorCode !== -3) log(`页面加载失败（${event.errorDescription || event.errorCode}）`, true);
  });
  webview.addEventListener('dom-ready', () => probeOnce(account.id, false));

  ui.tabs.appendChild(tabEl);
  ui.host.appendChild(webview);
  const timer = setInterval(() => probeOnce(account.id, false), 1500);
  state.tabs.set(account.id, { tabEl, webview, timer });
  activateTab(account.id);
  log(`已打开 ${platformLabel(account.platform)}·${account.account_name} 的登录页，请在右侧完成登录`);
  renderGroups();
}

function activateTab(accountId) {
  state.activeTab = accountId;
  for (const [id, entry] of state.tabs) {
    entry.tabEl.classList.toggle('active', id === accountId);
    entry.webview.classList.toggle('inactive', id !== accountId);
  }
  ui.emptyTip.classList.add('hidden');
}

function closeTab(accountId) {
  const entry = state.tabs.get(accountId);
  if (!entry) return;
  clearInterval(entry.timer);
  entry.tabEl.remove();
  entry.webview.remove();
  state.tabs.delete(accountId);
  if (state.activeTab === accountId) {
    const next = state.tabs.keys().next();
    if (next.done) {
      state.activeTab = null;
      ui.emptyTip.classList.remove('hidden');
    } else {
      activateTab(next.value);
    }
  }
  renderGroups();
}

function probeOnce(accountId, manual) {
  const entry = state.tabs.get(accountId);
  if (!entry) return;
  const account = state.accounts.find((item) => item.id === accountId);
  if (!account) return;
  const config = state.probes[account.platform] || {};
  try {
    entry.webview.send('geoflow-probe', config);
  } catch (error) {
    if (manual) toast('页面还没准备好，稍后再试', true);
  }
}

async function handleProbeResult(account, result) {
  const previous = state.detected[account.id] || {};
  const next = {
    loggedIn: Boolean(result.loggedIn),
    name: String(result.name || '').trim(),
    uid: String(result.uid || '').trim(),
    avatar: String(result.avatar || ''),
  };
  const changed = previous.loggedIn !== next.loggedIn || previous.name !== next.name || previous.uid !== next.uid;
  state.detected[account.id] = next;

  const bound = account.session?.status === 'authorized';
  if (next.loggedIn && !bound && !state.hintShown[account.id] && (next.name || next.uid)) {
    state.hintShown[account.id] = true;
    state.bindBarFor = account.id;
    log(`${platformLabel(account.platform)}·${account.account_name} 识别到登录身份「${next.name || next.uid}」，请核对后直接确认绑定`, false, true);
    toast(`已识别到「${next.name || next.uid}」，左侧已展开确认`);
  }
  if (!next.loggedIn) state.hintShown[account.id] = false;
  if (changed) renderGroups();
}

function needsIdentityReplacement(account, observed) {
  const existing = {
    profile_url: String(account.profile_url || '').trim(),
    account_uid: String(account.account_uid || '').trim(),
    homepage_identifier: String(account.homepage_identifier || '').trim(),
  };
  const hasExisting = Object.values(existing).some(Boolean);
  if (!hasExisting) return false;
  const observedValue = String(observed?.value || '').trim();
  return !observed?.type || !observedValue || existing[observed.type] !== observedValue;
}

/* ---------------- 草稿同步 ---------------- */

document.getElementById('autosync-save').addEventListener('click', () => run(async () => {
  const saved = await api.setAutoSync({
    autoSyncEnabled: ui.autosyncEnabled.checked,
    autoSyncIntervalMinutes: Number(ui.autosyncInterval.value),
  });
  ui.autosyncEnabled.checked = saved.autoSyncEnabled;
  ui.autosyncInterval.value = saved.autoSyncIntervalMinutes;
  log(saved.autoSyncEnabled
    ? `已开启自动同步：每 ${saved.autoSyncIntervalMinutes} 分钟自动同步一轮`
    : '已关闭自动同步', false, true);
  return saved.autoSyncEnabled ? `自动同步已开启（每 ${saved.autoSyncIntervalMinutes} 分钟）` : '自动同步已关闭';
}));

ui.sync.addEventListener('click', () => run(async () => {
  const selected = state.accounts
    .filter((account) => account.session?.status === 'authorized')
    .map((account) => account.id);
  if (!selected.length) throw new Error('no_bound_accounts');
  log(`开始同步 ${selected.length} 个账号的草稿任务…`);
  const results = await api.syncAll(selected);
  for (const item of results) {
    const account = state.accounts.find((a) => a.id === item.accountId);
    const label = account ? `${platformLabel(account.platform)}·${account.account_name}` : `账号#${item.accountId || '?'}`;
    const statusText = SYNC_STATUS_MAP[item.status] || item.status;
    const extra = item.error ? `（${cnError(item.error)}）` : (item.draftUrl ? `：${item.draftUrl}` : '');
    log(`  ${label} → ${statusText}${extra}`, item.status === 'action_required' || Boolean(item.error));
  }
  await loadAccounts();
  return `同步完成，共处理 ${results.length} 条任务`;
}));

/* ---------------- 更新 ---------------- */

async function checkUpdate(currentVersion) {
  try {
    const result = await api.checkUpdate();
    state.updateMetadata = result.desktop_update;
    if (state.updateMetadata?.available && compareVersions(state.updateMetadata.version, currentVersion) > 0) {
      document.getElementById('update-card').classList.remove('hidden');
      document.getElementById('update-version').textContent =
        `当前 v${currentVersion}，可更新到 v${state.updateMetadata.version}。安装前会校验签名和 SHA-256。`;
    }
  } catch { /* 更新检查失败不打扰 */ }
}

document.getElementById('install-update').addEventListener('click', () => run(
  () => api.installUpdate(state.updateMetadata),
  '安装程序已通过校验并启动',
));

function compareVersions(left, right) {
  const a = String(left).split('.').map(Number);
  const b = String(right).split('.').map(Number);
  for (let index = 0; index < Math.max(a.length, b.length); index += 1) {
    const delta = (a[index] || 0) - (b[index] || 0);
    if (delta) return delta;
  }
  return 0;
}

/* ---------------- 工具 ---------------- */

function cnError(error) {
  const raw = String(error?.message || error || '未知错误');
  if (ERROR_MAP[raw]) return ERROR_MAP[raw];
  if (/HTTP 50[234]/.test(raw)) return 'GEOFlow 刚刚在重启，自动重试后仍未恢复，请稍后再点一次';
  if (/[\u4e00-\u9fa5]/.test(raw)) return raw; // 服务端返回的中文直接展示
  return `操作失败：${raw}`;
}

function shortUrl(value) {
  try {
    const url = new URL(value);
    return url.host + url.pathname.slice(0, 40);
  } catch { return String(value || '').slice(0, 60); }
}

async function run(operation, success = null) {
  try {
    const result = await operation();
    if (success) toast(typeof result === 'string' ? result : success);
    return result;
  } catch (error) {
    toast(cnError(error), true);
    log(cnError(error), true);
    return null;
  }
}

function log(message, failed = false, ok = false) {
  const line = document.createElement('div');
  const time = new Date().toTimeString().slice(0, 8);
  line.textContent = `[${time}] ${message}`;
  if (failed) line.className = 'log-err';
  else if (ok) line.className = 'log-ok';
  ui.log.appendChild(line);
  ui.log.scrollTop = ui.log.scrollHeight;
}

document.getElementById('clear-log').addEventListener('click', () => { ui.log.textContent = ''; });

let toastTimer = null;
function toast(message, failed = false) {
  ui.toast.textContent = message;
  ui.toast.className = failed ? 'toast error' : 'toast';
  ui.toast.classList.remove('hidden');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => ui.toast.classList.add('hidden'), 3600);
}
