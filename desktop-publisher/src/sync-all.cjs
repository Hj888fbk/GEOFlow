'use strict';

const { DraftRunner } = require('./draft-runner.cjs');

function createSyncRunner({ api, registry, windows, observer, queue, adapterVersion, onRoundComplete = () => {} }) {
  let syncInProgress = false;

  async function runSyncAll(accountIds) {
    if (syncInProgress) throw coded('sync_in_progress');
    syncInProgress = true;
    try {
      const accountData = await api.listAccounts();
      const requestedIds = Array.isArray(accountIds) ? accountIds.map(Number).filter(Number.isSafeInteger) : [];
      const selected = accountData.accounts.filter((account) => requestedIds.length > 0
        ? requestedIds.includes(account.id)
        : account.session?.status === 'authorized');
      if (!selected.length) throw new Error('no_bound_accounts');
      const work = await api.listQueue(selected.map((account) => account.id));
      const runner = new DraftRunner(registry, api, windows, observer, adapterVersion);
      const results = [];
      for (const account of selected) {
        const items = work.items.filter((item) => item.account?.id === account.id);
        for (const item of items) {
          let result;
          try {
            const outcome = await queue.enqueue(account.id, () => runner.run(item, { ...account, instance: api.instance }));
            result = { accountId: account.id, publicationId: item.id, ...outcome };
          } catch (error) {
            result = {
              status: 'action_required',
              accountId: account.id,
              publicationId: item.id,
              error: error.code || error.message,
              // 诊断摘要（结构性失败时由 window-manager 采集）：限长 1000，不含 cookie/token/正文
              ...(error.diagnostics ? { diagnostics: String(error.diagnostics).slice(0, 1000) } : {}),
            };
          }
          results.push(result);
          // 登录态失效或身份不一致时同账号剩余工单必然同样失败：跳过该账号，继续处理其他账号
          if (result.status === 'action_required' || result.status === 'account_mismatch') break;
        }
      }
      onRoundComplete(results, selected);
      return results;
    } finally {
      syncInProgress = false;
    }
  }

  return { runSyncAll, isRunning: () => syncInProgress };
}

function coded(code) { const error = new Error(code); error.code = code; return error; }

module.exports = { createSyncRunner };
