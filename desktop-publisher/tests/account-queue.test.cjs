'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { AccountTaskQueue } = require('../src/account-queue.cjs');

test('tasks for one account are processed serially', async () => {
  const queue = new AccountTaskQueue();
  const order = [];
  const first = queue.enqueue(7, async () => { order.push('first:start'); await new Promise((resolve) => setTimeout(resolve, 15)); order.push('first:end'); });
  const second = queue.enqueue(7, async () => { order.push('second:start'); order.push('second:end'); });
  await Promise.all([first, second]);
  assert.deepEqual(order, ['first:start', 'first:end', 'second:start', 'second:end']);
});

test('different accounts do not share a session queue', async () => {
  const queue = new AccountTaskQueue();
  let release;
  const gate = new Promise((resolve) => { release = resolve; });
  const first = queue.enqueue(1, () => gate);
  let secondStarted = false;
  const second = queue.enqueue(2, async () => { secondStarted = true; });
  await second;
  assert.equal(secondStarted, true);
  release();
  await first;
});

test('settled account chains are removed', async () => {
  const queue = new AccountTaskQueue();
  await queue.enqueue(9, async () => 'done');
  assert.equal(queue.chains.size, 0);
});
