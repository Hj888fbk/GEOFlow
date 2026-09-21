'use strict';

const DEFAULT_AUTO_SYNC_ENABLED = true;
const DEFAULT_AUTO_SYNC_INTERVAL_MINUTES = 5;
const MIN_AUTO_SYNC_INTERVAL_MINUTES = 1;
const MAX_AUTO_SYNC_INTERVAL_MINUTES = 24 * 60;

function normalizeAutoSyncInterval(value) {
  const minutes = Number(value);
  if (!Number.isFinite(minutes)) return DEFAULT_AUTO_SYNC_INTERVAL_MINUTES;
  return Math.min(MAX_AUTO_SYNC_INTERVAL_MINUTES, Math.max(MIN_AUTO_SYNC_INTERVAL_MINUTES, Math.round(minutes)));
}

function normalizeAutoSyncSettings(value = {}) {
  return {
    autoSyncEnabled: value.autoSyncEnabled !== false,
    autoSyncIntervalMinutes: normalizeAutoSyncInterval(value.autoSyncIntervalMinutes),
  };
}

function createAutoSyncScheduler({
  run,
  onError = () => {},
  setIntervalImpl = setInterval,
  clearIntervalImpl = clearInterval,
}) {
  if (typeof run !== 'function') throw new TypeError('auto_sync_runner_required');

  let timer = null;

  const tick = async () => {
    try {
      await run();
    } catch (error) {
      onError(error);
    }
  };

  function start(intervalMinutes) {
    stop();
    timer = setIntervalImpl(() => { void tick(); }, normalizeAutoSyncInterval(intervalMinutes) * 60_000);
    if (typeof timer?.unref === 'function') timer.unref();
  }

  function stop() {
    if (timer) clearIntervalImpl(timer);
    timer = null;
  }

  return {
    tick,
    start,
    stop,
    isScheduled: () => timer !== null,
  };
}

module.exports = {
  DEFAULT_AUTO_SYNC_ENABLED,
  DEFAULT_AUTO_SYNC_INTERVAL_MINUTES,
  normalizeAutoSyncSettings,
  createAutoSyncScheduler,
};
