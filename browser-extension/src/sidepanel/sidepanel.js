import { GeoFlowApiClient, GeoFlowApiError } from '../lib/api-client.js';
import { hasConflictingActiveTask, resumeClaimedTask } from '../lib/task-state.js';
import { normalizeGeoflowBaseUrl, originPermissionPattern } from '../lib/url-policy.js';
import {
    clearConnection, clearCurrentTask, clearPendingAuthorization, configureTrustedStorage,
    getConnection, getCurrentTask, getPendingAuthorization, setConnection, setCurrentTask,
    setPendingAuthorization,
} from '../lib/storage.js';
import { adapterForAction, supportedAdapterActions } from '../adapters/registry.js';

const VERSION = chrome.runtime.getManifest().version;
const byId = (id) => document.getElementById(id);
const elements = Object.fromEntries([
    'connect-view', 'workspace-view', 'connect-form', 'base-url', 'pending-card', 'user-code',
    'open-approval', 'cancel-pairing', 'admin-name', 'connection-dot', 'refresh-tasks', 'disconnect',
    'queue-view', 'task-view', 'task-list', 'empty-state', 'queue-meta', 'back-to-queue',
    'task-platform', 'task-status', 'task-title', 'task-account', 'task-content', 'task-media', 'task-media-list', 'claim-task',
    'open-target', 'copy-content', 'fill-draft', 'release-task', 'result-panel', 'completion-url',
    'result-note', 'observe-result', 'complete-task', 'unknown-task', 'cancel-task', 'fail-task', 'notice',
].map((id) => [id.replaceAll('-', '_'), byId(id)]));

let connection = null;
let client = null;
let selectedTask = null;
let currentTask = null;
let heartbeatTimer = null;
let pollTimer = null;

function message(key, fallback = '') {
    return chrome.i18n.getMessage(key) || fallback || key;
}

function localize() {
    document.documentElement.lang = chrome.i18n.getUILanguage().replace('_', '-');
    for (const node of document.querySelectorAll('[data-i18n]')) {
        node.textContent = message(node.dataset.i18n, node.textContent);
    }
    for (const node of document.querySelectorAll('[data-i18n-aria]')) {
        node.setAttribute('aria-label', message(node.dataset.i18nAria, node.getAttribute('aria-label') || ''));
    }
}

function showNotice(text, error = false) {
    elements.notice.textContent = text;
    elements.notice.classList.toggle('error', error);
    elements.notice.classList.remove('hidden');
    window.setTimeout(() => elements.notice.classList.add('hidden'), 4500);
}

function showConnected(connected) {
    elements.connect_view.classList.toggle('hidden', connected);
    elements.workspace_view.classList.toggle('hidden', ! connected);
    elements.connection_dot.classList.toggle('connected', connected);
}

function renderMediaManifest(task) {
    const media = Array.isArray(task.publication_payload?.media_manifest)
        ? [...task.publication_payload.media_manifest]
        : [];
    media.sort((left, right) => {
        const leftPosition = Number.isFinite(Number(left?.position)) ? Number(left.position) : Number.MAX_SAFE_INTEGER;
        const rightPosition = Number.isFinite(Number(right?.position)) ? Number(right.position) : Number.MAX_SAFE_INTEGER;
        return leftPosition - rightPosition;
    });
    elements.task_media_list.replaceChildren();
    elements.task_media.classList.toggle('hidden', media.length === 0);

    for (const [index, item] of media.entries()) {
        const card = document.createElement('div');
        card.className = 'media-item';
        const position = Number.isFinite(Number(item?.position)) ? Number(item.position) : index;
        const isCover = item?.role === 'cover' || position === 0;
        const preview = String(item?.preview_url ?? '').trim();
        if (preview) {
            try {
                const url = new URL(preview, connection?.baseUrl);
                if (['https:', 'http:'].includes(url.protocol) && ! url.username && ! url.password) {
                    const image = document.createElement('img');
                    image.src = url.toString();
                    image.alt = String(item?.name ?? `#${item?.image_id}`);
                    image.loading = 'lazy';
                    image.referrerPolicy = 'no-referrer';
                    card.append(image);
                }
            } catch {}
        }
        // 醒目徽标告诉运营这张图传到哪里：封面 → 平台封面位；正文图 → 正文【图片N】占位处。
        const badge = document.createElement('span');
        badge.className = 'media-item__placeholder';
        badge.textContent = isCover ? message('coverImage') : `【图片${position}】`;
        card.append(badge);
        const label = document.createElement('span');
        const role = isCover ? message('coverImage') : message('bodyImage');
        label.textContent = `#${position} · ${role} · ${String(item?.name ?? `#${item?.image_id}`)}`;
        card.append(label);
        elements.task_media_list.append(card);
    }
}

async function handleOperationalError(error) {
    if (error instanceof GeoFlowApiError && ['unauthorized', 'upgrade_required'].includes(error.code)) {
        await clearConnection();
        connection = client = selectedTask = currentTask = null;
        stopHeartbeat();
        showConnected(false);
    }
    showNotice(error.message, true);
}

async function requestOriginPermission(url) {
    const origins = [originPermissionPattern(url)];
    const existing = await chrome.permissions.contains({ origins });
    return existing || chrome.permissions.request({ origins });
}

async function connect(event) {
    event.preventDefault();
    try {
        const baseUrl = normalizeGeoflowBaseUrl(elements.base_url.value);
        if (! await requestOriginPermission(baseUrl)) throw new Error(message('permissionDenied'));
        const publicClient = new GeoFlowApiClient({ baseUrl, version: VERSION });
        const authorization = await publicClient.request('/api/v1/browser-operations/device-authorizations', {
            method: 'POST', body: { client_name: `Chrome ${VERSION}` },
        });
        const pending = { ...authorization, baseUrl, expiresAt: Date.now() + authorization.expires_in * 1000 };
        await setPendingAuthorization(pending);
        renderPending(pending);
        schedulePoll(pending, authorization.interval);
    } catch (error) {
        showNotice(error.message, true);
    }
}

function renderPending(pending) {
    elements.pending_card.classList.remove('hidden');
    elements.user_code.textContent = pending.user_code;
    elements.open_approval.onclick = () => chrome.tabs.create({ url: pending.verification_uri_complete });
}

function schedulePoll(pending, interval) {
    window.clearTimeout(pollTimer);
    pollTimer = window.setTimeout(() => pollAuthorization(pending), Math.max(5, Number(interval)) * 1000);
}

async function pollAuthorization(pending) {
    if (Date.now() >= pending.expiresAt) {
        await clearPendingAuthorization();
        elements.pending_card.classList.add('hidden');
        return showNotice(message('pairingExpired'), true);
    }
    try {
        const publicClient = new GeoFlowApiClient({ baseUrl: pending.baseUrl, version: VERSION });
        const token = await publicClient.request('/api/v1/browser-operations/device-token', {
            method: 'POST', body: { device_code: pending.device_code },
        });
        connection = { baseUrl: pending.baseUrl, token: token.token, expiresAt: token.expires_at };
        await setConnection(connection);
        await clearPendingAuthorization();
        elements.pending_card.classList.add('hidden');
        await loadSession();
    } catch (error) {
        if (error instanceof GeoFlowApiError && ['authorization_pending', 'slow_down'].includes(error.code)) {
            return schedulePoll(pending, error.details.interval ?? pending.interval);
        }
        await clearPendingAuthorization();
        elements.pending_card.classList.add('hidden');
        showNotice(error.message, true);
    }
}

async function loadSession() {
    connection = await getConnection();
    if (! connection) return showConnected(false);
    client = new GeoFlowApiClient({ ...connection, version: VERSION });
    try {
        const session = await client.request('/api/v1/browser-operations/session');
        elements.admin_name.textContent = session.admin.display_name;
        showConnected(true);
        currentTask = await getCurrentTask();
        await reconcileTasks();
    } catch (error) {
        await handleOperationalError(error);
        showConnected(false);
    }
}

async function loadTasks() {
    try {
        const data = await client.request('/api/v1/manual-publications?per_page=20');
        renderTasks(data.items);
        elements.queue_meta.textContent = message('queueCount', `${data.pagination.total} items`).replace('{count}', data.pagination.total);
        return data.items;
    } catch (error) {
        await handleOperationalError(error);
        return null;
    }
}

async function reconcileTasks() {
    await loadTasks();
    if (! currentTask?.publication) return;
    try {
        const data = await client.request(`/api/v1/manual-publications/${currentTask.publication.id}`);
        const current = data.publication;
        if (current?.status === 'in_progress') {
            currentTask.publication = current;
            await setCurrentTask(currentTask);
            await selectTask(current);
            return;
        }
    } catch (error) {
        await handleOperationalError(error);
    }

    await clearCurrentTask();
    currentTask = selectedTask = null;
    stopHeartbeat();
    elements.task_view.classList.add('hidden');
    elements.queue_view.classList.remove('hidden');
}

function renderTasks(tasks) {
    elements.task_list.replaceChildren();
    elements.empty_state.classList.toggle('hidden', tasks.length > 0);
    for (const task of tasks) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'task-item';
        const content = task.publication_payload?.body_plain ?? '';
        button.innerHTML = `<span class="task-item__top"><span class="platform"></span><span class="status"></span></span><strong></strong>`;
        button.querySelector('.platform').textContent = task.platform;
        button.querySelector('.status').textContent = task.status;
        button.querySelector('strong').textContent = task.publication_payload?.title || content.slice(0, 52) || `#${task.id}`;
        button.addEventListener('click', () => void selectTask(task));
        elements.task_list.append(button);
    }
}

async function selectTask(task) {
    if (hasConflictingActiveTask(currentTask, task)) {
        showNotice(message('activeTaskExists'), true);

        return;
    }

    selectedTask = task;
    if (['in_progress', 'draft_filled'].includes(task.status)) {
        currentTask = resumeClaimedTask(currentTask, task);
        await setCurrentTask(currentTask);
    }
    elements.queue_view.classList.add('hidden');
    elements.task_view.classList.remove('hidden');
    elements.task_platform.textContent = task.platform;
    elements.task_status.textContent = task.status;
    elements.task_title.textContent = task.publication_payload?.title || `${task.platform} #${task.id}`;
    const accountIdentifier = task.account?.profile_url || task.account?.account_uid || task.account?.homepage_identifier || '';
    elements.task_account.textContent = task.account ? `${task.account.name}${accountIdentifier ? ` · ${accountIdentifier}` : ''}` : message('accountMissing');
    elements.task_content.textContent = task.publication_payload?.body_plain ?? '';
    renderMediaManifest(task);
    const claimed = ['in_progress', 'draft_filled'].includes(task.status);
    elements.claim_task.classList.toggle('hidden', claimed);
    const action = task.publication_payload?.target_action;
    elements.fill_draft.classList.toggle('hidden', ! claimed || task.status === 'draft_filled' || ! supportedAdapterActions().includes(action));
    elements.release_task.classList.toggle('hidden', ! claimed || task.status === 'draft_filled');
    elements.result_panel.classList.toggle('hidden', ! claimed);
    const requiresReadback = Number(task.publication_payload?.schema_version ?? 1) >= 2;
    elements.complete_task.disabled = requiresReadback && ! currentTask?.publicUrlReadback?.succeeded;
    if (claimed) startHeartbeat(task.id);
}

async function claimTask() {
    try {
        const storedTask = await getCurrentTask();
        if (hasConflictingActiveTask(storedTask, selectedTask)) {
            currentTask = storedTask;
            throw new Error(message('activeTaskExists'));
        }
        const data = await client.request(`/api/v1/manual-publications/${selectedTask.id}/claim`, {
            method: 'POST', body: { revision: selectedTask.revision }, idempotencyKey: crypto.randomUUID(),
        });
        selectedTask = data.publication;
        currentTask = { publication: selectedTask, tabId: null, startedAt: new Date().toISOString() };
        await setCurrentTask(currentTask);
        await selectTask(selectedTask);
        showNotice(message('claimed'));
    } catch (error) {
        await handleOperationalError(error);
    }
}

async function openTarget() {
    try {
        if (! selectedTask.target_url) throw new Error(message('targetMissing'));
        if (! await requestOriginPermission(selectedTask.target_url)) throw new Error(message('permissionDenied'));
        const tab = await chrome.tabs.create({ url: selectedTask.target_url, active: true });
        if (currentTask) {
            currentTask.tabId = tab.id;
            await setCurrentTask(currentTask);
        }
        return tab;
    } catch (error) {
        await handleOperationalError(error);
        return null;
    }
}

async function waitForTab(tabId) {
    for (let attempt = 0; attempt < 40; attempt += 1) {
        const tab = await chrome.tabs.get(tabId);
        if (tab.status === 'complete') return tab;
        await new Promise((resolve) => window.setTimeout(resolve, 250));
    }
    throw new Error(message('pageLoadTimeout'));
}

async function fillDraft() {
    try {
        let tabId = currentTask?.tabId;
        if (! tabId) tabId = (await openTarget())?.id;
        if (! tabId) return;
        await waitForTab(tabId);
        const action = selectedTask.publication_payload?.target_action;
        const registered = adapterForAction(action);
        if (! registered) throw new Error(message('adapter_not_implemented'));
        const isLegacyZhihu = registered.kind === 'legacy_zhihu';
        const adapter = registered.execute;
        const args = isLegacyZhihu
            ? [selectedTask.publication_payload, selectedTask.account.profile_url, false]
            : [action, selectedTask.publication_payload, selectedTask.account, false];
        let [execution] = await chrome.scripting.executeScript({ target: { tabId }, func: adapter, args });
        if (isLegacyZhihu && execution.result?.code === 'editor_not_empty' && window.confirm(message('replaceDraftConfirm'))) {
            [execution] = await chrome.scripting.executeScript({
                target: { tabId }, func: registered.execute,
                args: [selectedTask.publication_payload, selectedTask.account.profile_url, true],
            });
        }
        if (! execution.result?.ok) throw new Error(message(execution.result?.code, execution.result?.code));
        currentTask.observedProfileUrl = execution.result.observedProfileUrl;
        currentTask.observedAccountProof = execution.result.accountProof || execution.result.observedProfileUrl;
        currentTask.accountVerified = Boolean(currentTask.observedAccountProof);
        if (! isLegacyZhihu) {
            const observedHash = await sha256AccountProof(currentTask.observedAccountProof);
            const data = await client.request(`/api/v1/manual-publications/${selectedTask.id}/draft-receipt`, {
                method: 'POST',
                body: {
                    revision: selectedTask.revision,
                    adapter_version: VERSION,
                    target_origin: new URL(selectedTask.target_url).origin,
                    observed_account_hash: observedHash,
                    filled_fields: execution.result.filledFields,
                    finished_at: new Date().toISOString(),
                },
                idempotencyKey: crypto.randomUUID(),
            });
            selectedTask = data.publication;
            currentTask.publication = selectedTask;
        }
        await setCurrentTask(currentTask);
        await selectTask(selectedTask);
        showNotice(message('draftFilled'));
    } catch (error) {
        await handleOperationalError(error);
    }
}

async function observeResult() {
    try {
        if (! currentTask?.tabId) throw new Error(message('targetMissing'));
        const action = selectedTask.publication_payload?.target_action;
        const registered = adapterForAction(action);
        if (! registered) throw new Error(message('adapter_not_implemented'));
        const [execution] = registered.kind === 'legacy_zhihu'
            ? await chrome.scripting.executeScript({ target: { tabId: currentTask.tabId }, func: registered.observe })
            : await chrome.scripting.executeScript({ target: { tabId: currentTask.tabId }, func: registered.observe, args: [action] });
        if (execution.result?.completionUrl) elements.completion_url.value = execution.result.completionUrl;
        currentTask.publicUrlReadback = {
            url: execution.result?.completionUrl ?? null,
            status: execution.result?.readbackStatus ?? null,
            succeeded: Boolean(execution.result?.readbackSucceeded),
        };
        await setCurrentTask(currentTask);
        await selectTask(selectedTask);
        showNotice(message(execution.result?.outcome === 'completed' ? 'resultDetected' : 'resultUnknown'));
    } catch (error) {
        showNotice(error.message, true);
    }
}

async function sha256AccountProof(value) {
    let canonical = String(value).trim().toLowerCase();
    if (! canonical.startsWith('uid:') && ! canonical.startsWith('homepage:')) {
        const url = new URL(canonical);
        url.search = '';
        url.hash = '';
        canonical = url.toString().replace(/\/$/, '').toLowerCase();
    }
    const bytes = new TextEncoder().encode(canonical);
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

async function copyTaskContent() {
    const payload = selectedTask.publication_payload ?? {};
    const content = Number(payload.schema_version ?? 1) >= 2
        ? [payload.title, payload.summary, payload.body_markdown || payload.body_plain, (payload.tags || []).join('、')].filter(Boolean).join('\n\n')
        : payload.body_plain ?? '';
    try {
        await navigator.clipboard.writeText(content);
    } catch {
        const textarea = document.createElement('textarea');
        textarea.value = content;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.append(textarea);
        textarea.select();
        const copied = document.execCommand('copy');
        textarea.remove();
        if (! copied) throw new Error(message('copyFailed'));
    }
    showNotice(message('copied'));
}

async function submitReceipt(outcome) {
    try {
        const completionUrl = elements.completion_url.value.trim() || null;
        if (outcome === 'completed' && ! completionUrl) throw new Error(message('completionRequired'));
        if (outcome === 'completed'
            && Number(selectedTask.publication_payload?.schema_version ?? 1) >= 2
            && currentTask?.publicUrlReadback?.url !== completionUrl) {
            throw new Error(message('publicUrlReadbackRequired'));
        }
        const requiresVerifiedAccount = (Number(selectedTask.publication_payload?.schema_version ?? 1) >= 2
            || selectedTask.publication_payload?.target_action === 'zhihu_answer')
            && ['completed', 'outcome_unknown'].includes(outcome);
        if (requiresVerifiedAccount && ! currentTask?.accountVerified) {
            throw new Error(message('accountNotVerified'));
        }
        const targetOrigin = new URL(selectedTask.target_url).origin;
        const body = {
            revision: selectedTask.revision,
            outcome,
            completion_url: completionUrl,
            adapter_version: VERSION,
            target_origin: targetOrigin,
            observed_account_hash: currentTask?.observedAccountProof
                ? await sha256AccountProof(currentTask.observedAccountProof)
                : null,
            started_at: currentTask?.startedAt ?? new Date().toISOString(),
            finished_at: new Date().toISOString(),
            result_note: elements.result_note.value.trim() || null,
            error_code: outcome === 'failed' ? 'operator_reported_failure' : null,
            public_url_readback_status: currentTask?.publicUrlReadback?.status ?? null,
            public_url_readback_succeeded: Boolean(currentTask?.publicUrlReadback?.succeeded),
            public_url_readback_url: currentTask?.publicUrlReadback?.url ?? null,
        };
        await client.request(`/api/v1/manual-publications/${selectedTask.id}/receipt`, {
            method: 'POST', body, idempotencyKey: crypto.randomUUID(),
        });
        await clearCurrentTask();
        currentTask = null;
        selectedTask = null;
        stopHeartbeat();
        elements.task_view.classList.add('hidden');
        elements.queue_view.classList.remove('hidden');
        await loadTasks();
        showNotice(message('receiptSaved'));
    } catch (error) {
        await handleOperationalError(error);
    }
}

async function releaseTask() {
    try {
        await client.request(`/api/v1/manual-publications/${selectedTask.id}/release`, {
            method: 'POST', body: { revision: selectedTask.revision }, idempotencyKey: crypto.randomUUID(),
        });
        await clearCurrentTask();
        currentTask = null;
        stopHeartbeat();
        elements.task_view.classList.add('hidden');
        elements.queue_view.classList.remove('hidden');
        await loadTasks();
    } catch (error) {
        await handleOperationalError(error);
    }
}

function startHeartbeat(publicationId) {
    stopHeartbeat();
    heartbeatTimer = window.setInterval(async () => {
        try {
            await client.request(`/api/v1/manual-publications/${publicationId}/heartbeat`, { method: 'POST', body: {} });
        } catch (error) {
            stopHeartbeat();
            await handleOperationalError(error);
        }
    }, 60000);
}
function stopHeartbeat() { window.clearInterval(heartbeatTimer); heartbeatTimer = null; }

elements.connect_form.addEventListener('submit', connect);
elements.cancel_pairing.addEventListener('click', async () => { window.clearTimeout(pollTimer); await clearPendingAuthorization(); elements.pending_card.classList.add('hidden'); });
elements.refresh_tasks.addEventListener('click', reconcileTasks);
elements.disconnect.addEventListener('click', async () => {
    try { await client?.request('/api/v1/browser-operations/session', { method: 'DELETE' }); } catch {}
    await clearConnection();
    connection = client = selectedTask = currentTask = null;
    stopHeartbeat();
    showConnected(false);
});
elements.back_to_queue.addEventListener('click', () => { elements.task_view.classList.add('hidden'); elements.queue_view.classList.remove('hidden'); });
elements.claim_task.addEventListener('click', claimTask);
elements.open_target.addEventListener('click', openTarget);
elements.copy_content.addEventListener('click', async () => {
    try { await copyTaskContent(); } catch (error) { showNotice(error.message, true); }
});
elements.fill_draft.addEventListener('click', fillDraft);
elements.release_task.addEventListener('click', releaseTask);
elements.observe_result.addEventListener('click', observeResult);
elements.complete_task.addEventListener('click', () => submitReceipt('completed'));
elements.unknown_task.addEventListener('click', () => submitReceipt('outcome_unknown'));
elements.cancel_task.addEventListener('click', () => submitReceipt('cancelled'));
elements.fail_task.addEventListener('click', () => submitReceipt('failed'));

localize();
await configureTrustedStorage();
const pending = await getPendingAuthorization();
if (pending && Date.now() < pending.expiresAt) {
    elements.base_url.value = pending.baseUrl;
    renderPending(pending);
    schedulePoll(pending, pending.interval);
}
await loadSession();
