'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { AccountTaskQueue } = require('../src/account-queue.cjs');
const {
  DEFAULT_AUTO_SYNC_INTERVAL_MINUTES,
  normalizeAutoSyncSettings,
  createAutoSyncScheduler,
} = require('../src/auto-sync.cjs');
const { CredentialStore } = require('../src/credential-store.cjs');
const { createSyncRunner } = require('../src/sync-all.cjs');

test('auto sync settings default to enabled with a five minute interval', () => {
  assert.equal(DEFAULT_AUTO_SYNC_INTERVAL_MINUTES, 5);
  assert.deepEqual(normalizeAutoSyncSettings({}), { autoSyncEnabled: true, autoSyncIntervalMinutes: 5 });
  assert.deepEqual(
    normalizeAutoSyncSettings({ autoSyncEnabled: false, autoSyncIntervalMinutes: 30 }),
    { autoSyncEnabled: false, autoSyncIntervalMinutes: 30 },
  );
  assert.equal(normalizeAutoSyncSettings({ autoSyncIntervalMinutes: 0 }).autoSyncIntervalMinutes, 1);
  assert.equal(normalizeAutoSyncSettings({ autoSyncIntervalMinutes: 99999 }).autoSyncIntervalMinutes, 1440);
  assert.equal(normalizeAutoSyncSettings({ autoSyncIntervalMinutes: 'not-a-number' }).autoSyncIntervalMinutes, 5);
});

test('auto sync scheduler runs on the configured interval and reschedules cleanly', async () => {
  const timers = [];
  const cleared = [];
  let runs = 0;
  const scheduler = createAutoSyncScheduler({
    run: async () => { runs += 1; },
    setIntervalImpl: (_callback, interval) => {
      const timer = { interval, unref: () => {} };
      timers.push(timer);
      return timer;
    },
    clearIntervalImpl: (timer) => { cleared.push(timer); },
  });

  scheduler.start(10);
  assert.equal(timers[0].interval, 10 * 60_000);
  assert.equal(scheduler.isScheduled(), true);

  await scheduler.tick();
  assert.equal(runs, 1);

  scheduler.start(2);
  assert.deepEqual(cleared, [timers[0]]);
  assert.equal(timers[1].interval, 2 * 60_000);

  scheduler.stop();
  assert.deepEqual(cleared, [timers[0], timers[1]]);
  assert.equal(scheduler.isScheduled(), false);
});

test('auto sync scheduler reports round failures through onError instead of throwing', async () => {
  const errors = [];
  const scheduler = createAutoSyncScheduler({
    run: async () => { throw new Error('no_bound_accounts'); },
    onError: (error) => errors.push(error.message),
    setIntervalImpl: () => ({ unref: () => {} }),
    clearIntervalImpl: () => {},
  });

  await scheduler.tick();
  assert.deepEqual(errors, ['no_bound_accounts']);
});

function fakeSyncDeps({ accounts, items, detectLogin, runs = [] }) {
  return {
    api: {
      listAccounts: async () => ({ accounts }),
      listQueue: async () => ({ items }),
      reportAccount: async () => undefined,
    },
    registry: { get: () => ({}) },
    windows: {
      forAccount: async (account) => ({
        detectLogin: async () => {
          runs.push(account.id);
          return detectLogin(account);
        },
        show: async () => undefined,
      }),
    },
    queue: new AccountTaskQueue(),
    adapterVersion: 'test',
  };
}

test('sync runner rejects a second round while one is in progress', async () => {
  let release;
  const gate = new Promise((resolve) => { release = resolve; });
  const runner = createSyncRunner(fakeSyncDeps({
    accounts: [{ id: 1, session: { status: 'authorized' } }],
    items: [{ id: 11, account: { id: 1 }, platform: 'mock', status: 'ready' }],
    detectLogin: async () => {
      await gate;
      return { loggedIn: false, captcha: false };
    },
  }));

  const first = runner.runSyncAll();
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(runner.isRunning(), true);
  await assert.rejects(() => runner.runSyncAll(), /sync_in_progress/);

  release();
  const results = await first;
  assert.equal(results[0].status, 'action_required');
  assert.equal(results[0].accountId, 1);
  assert.equal(results[0].publicationId, 11);
  assert.equal(runner.isRunning(), false);
});

test('an action_required account skips its remaining items but later accounts still run', async () => {
  const runs = [];
  const runner = createSyncRunner(fakeSyncDeps({
    accounts: [
      { id: 1, account_name: '主号', session: { status: 'authorized' } },
      { id: 2, account_name: '小号', session: { status: 'authorized' } },
    ],
    items: [
      { id: 11, account: { id: 1 }, platform: 'mock', status: 'ready' },
      { id: 12, account: { id: 1 }, platform: 'mock', status: 'ready' },
      { id: 21, account: { id: 2 }, platform: 'mock', status: 'ready' },
    ],
    detectLogin: async () => ({ loggedIn: false, captcha: false }),
    runs,
  }));

  const results = await runner.runSyncAll();

  assert.deepEqual(runs, [1, 2]);
  assert.deepEqual(results.map((result) => [result.accountId, result.publicationId, result.status]), [
    [1, 11, 'action_required'],
    [2, 21, 'action_required'],
  ]);
});

test('a thrown adapter error is recorded with its cause and later accounts still run', async () => {
  const runs = [];
  const completed = [];
  const crashed = new Error('editor_dom_changed');
  crashed.code = 'editor_dom_changed';
  const runner = createSyncRunner({
    ...fakeSyncDeps({
      accounts: [
        { id: 1, session: { status: 'authorized' } },
        { id: 2, session: { status: 'authorized' } },
      ],
      items: [
        { id: 11, account: { id: 1 }, platform: 'mock', status: 'ready' },
        { id: 12, account: { id: 1 }, platform: 'mock', status: 'ready' },
        { id: 21, account: { id: 2 }, platform: 'mock', status: 'ready' },
      ],
      detectLogin: async (account) => {
        if (account.id === 1) throw crashed;
        return { loggedIn: false, captcha: false };
      },
      runs,
    }),
    onRoundComplete: (results, accounts) => completed.push([results.length, accounts.length]),
  });

  const results = await runner.runSyncAll();

  assert.deepEqual(runs, [1, 2]);
  assert.deepEqual(results[0], { status: 'action_required', accountId: 1, publicationId: 11, error: 'editor_dom_changed' });
  assert.equal(results[1].accountId, 2);
  assert.deepEqual(completed, [[2, 2]]);
});

test('credential store persists auto sync settings alongside credentials', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'publisher-settings-'));
  try {
    const safeStorage = {
      isEncryptionAvailable: () => true,
      encryptString: (value) => Buffer.from(`enc:${value}`),
      decryptString: (buffer) => String(buffer).slice(4),
    };
    const store = new CredentialStore(safeStorage, dir);

    assert.deepEqual(store.load(), {
      instance: 'http://127.0.0.1:28080',
      token: null,
      autoSyncEnabled: true,
      autoSyncIntervalMinutes: 5,
    });

    store.save('https://geo.example', 'secret-token');
    const saved = store.saveAutoSync({ autoSyncEnabled: false, autoSyncIntervalMinutes: 15 });
    assert.deepEqual(saved, { autoSyncEnabled: false, autoSyncIntervalMinutes: 15 });

    const loaded = store.load();
    assert.equal(loaded.instance, 'https://geo.example');
    assert.equal(loaded.token, 'secret-token');
    assert.equal(loaded.autoSyncEnabled, false);
    assert.equal(loaded.autoSyncIntervalMinutes, 15);

    store.save('https://geo.example', 'next-token');
    const reloaded = store.load();
    assert.equal(reloaded.token, 'next-token');
    assert.equal(reloaded.autoSyncEnabled, false);
    assert.equal(reloaded.autoSyncIntervalMinutes, 15);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
