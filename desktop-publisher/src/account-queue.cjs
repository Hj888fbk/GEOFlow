'use strict';

class AccountTaskQueue {
  constructor() {
    this.chains = new Map();
  }

  enqueue(accountId, operation) {
    const key = String(accountId);
    const previous = this.chains.get(key) || Promise.resolve();
    const current = previous.catch(() => undefined).then(operation);
    const settled = current.finally(() => {
      if (this.chains.get(key) === settled) this.chains.delete(key);
    });
    this.chains.set(key, settled);
    return current;
  }
}

module.exports = { AccountTaskQueue };
