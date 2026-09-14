'use strict';

const fs = require('node:fs');
const path = require('node:path');

class CredentialStore {
  constructor(safeStorage, userDataPath) {
    this.safeStorage = safeStorage;
    this.path = path.join(userDataPath, 'publisher-settings.json');
  }

  load() {
    try {
      const stored = JSON.parse(fs.readFileSync(this.path, 'utf8'));
      return {
        instance: stored.instance || 'http://127.0.0.1:28080',
        token: stored.token && this.safeStorage.isEncryptionAvailable()
          ? this.safeStorage.decryptString(Buffer.from(stored.token, 'base64'))
          : null,
      };
    } catch {
      return { instance: 'http://127.0.0.1:28080', token: null };
    }
  }

  save(instance, token) {
    if (!this.safeStorage.isEncryptionAvailable()) throw new Error('system_credential_protection_unavailable');
    fs.writeFileSync(this.path, JSON.stringify({
      instance,
      token: token ? this.safeStorage.encryptString(token).toString('base64') : null,
    }), { encoding: 'utf8', mode: 0o600 });
  }
}

module.exports = { CredentialStore };
