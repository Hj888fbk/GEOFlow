'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const {
  DEFAULT_CONNECTION_HEARTBEAT_INTERVAL_MS,
  createConnectionHeartbeat,
} = require('../src/connection-heartbeat.cjs');

test('connection heartbeat refreshes the session on the expected interval', async () => {
  let configuredInterval = null;
  let timerUnrefCalled = false;
  let clearedTimer = null;
  const timer = { unref: () => { timerUnrefCalled = true; } };
  const states = [];
  const heartbeat = createConnectionHeartbeat({
    check: async () => ({ connected: true, connectionStatus: 'connected' }),
    onState: (state) => states.push(state),
    setIntervalImpl: (_callback, interval) => {
      configuredInterval = interval;
      return timer;
    },
    clearIntervalImpl: (value) => { clearedTimer = value; },
  });

  await heartbeat.tick();
  heartbeat.stop();

  assert.equal(configuredInterval, DEFAULT_CONNECTION_HEARTBEAT_INTERVAL_MS);
  assert.equal(timerUnrefCalled, true);
  assert.deepEqual(states, [{ connected: true, connectionStatus: 'connected' }]);
  assert.equal(clearedTimer, timer);
});

test('connection heartbeat does not overlap slow session checks', async () => {
  let finishCheck;
  let checks = 0;
  const pendingCheck = new Promise((resolve) => { finishCheck = resolve; });
  const heartbeat = createConnectionHeartbeat({
    check: async () => {
      checks += 1;
      await pendingCheck;
      return { connected: true };
    },
    onState: () => {},
    setIntervalImpl: () => ({ unref: () => {} }),
    clearIntervalImpl: () => {},
  });

  const first = heartbeat.tick();
  await heartbeat.tick();
  assert.equal(checks, 1);

  finishCheck();
  await first;
  heartbeat.stop();
});

test('stopped heartbeat ignores a session result that finishes later', async () => {
  let finishCheck;
  const states = [];
  const heartbeat = createConnectionHeartbeat({
    check: () => new Promise((resolve) => { finishCheck = resolve; }),
    onState: (state) => states.push(state),
    setIntervalImpl: () => ({ unref: () => {} }),
    clearIntervalImpl: () => {},
  });

  const pending = heartbeat.tick();
  heartbeat.stop();
  finishCheck({ connected: true });
  await pending;

  assert.deepEqual(states, []);
});
