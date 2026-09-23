'use strict';

const DEFAULT_CONNECTION_HEARTBEAT_INTERVAL_MS = 15_000;

function createConnectionHeartbeat({
  check,
  onState,
  onError = () => {},
  intervalMs = DEFAULT_CONNECTION_HEARTBEAT_INTERVAL_MS,
  setIntervalImpl = setInterval,
  clearIntervalImpl = clearInterval,
}) {
  if (typeof check !== 'function' || typeof onState !== 'function') {
    throw new TypeError('connection_heartbeat_callbacks_required');
  }

  let stopped = false;
  let inFlight = false;

  const tick = async () => {
    if (stopped || inFlight) return;
    inFlight = true;

    try {
      const state = await check();
      if (!stopped) onState(state);
    } catch (error) {
      if (!stopped) onError(error);
    } finally {
      inFlight = false;
    }
  };

  const timer = setIntervalImpl(() => { void tick(); }, intervalMs);
  if (typeof timer?.unref === 'function') timer.unref();

  return {
    tick,
    stop() {
      if (stopped) return;
      stopped = true;
      clearIntervalImpl(timer);
    },
  };
}

module.exports = {
  DEFAULT_CONNECTION_HEARTBEAT_INTERVAL_MS,
  createConnectionHeartbeat,
};
