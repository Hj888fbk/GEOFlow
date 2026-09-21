'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { normalizeAutoSyncSettings } = require('./auto-sync.cjs');

class CredentialStore {
  constructor(safeStorage, userDataPath) {
    this.safeStorage = safeStorage;
    this.path = path.join(userDataPath, 'publisher-settings.json');
  }

  load() {
    const stored = this.readRaw();
    try {
      return {
        instance: stored.instance || 'http://127.0.0.1:28080',
        token: stored.token && this.safeStorage.isEncryptionAvailable()
          ? this.safeStorage.decryptString(Buffer.from(stored.token, 'base64'))
          : null,
        ...normalizeAutoSyncSettings(stored),
      };
    } catch {
      return { instance: 'http://127.0.0.1:28080', token: null, ...normalizeAutoSyncSettings({}) };
    }
  }

  save(instance, token) {
    if (!this.safeStorage.isEncryptionAvailable()) throw new Error('system_credential_protection_unavailable');
    const stored = this.readRaw();
    this.writeRaw({
      ...stored,
      instance,
      token: token ? this.safeStorage.encryptString(token).toString('base64') : null,
    });
  }

  saveAutoSync(settings) {
    const stored = this.readRaw();
    const normalized = normalizeAutoSyncSettings(settings);
    this.writeRaw({ ...stored, ...normalized });
    return normalized;
  }

  readRaw() {
    try {
      return JSON.parse(fs.readFileSync(this.path, 'utf8'));
    } catch {
      return {};
    }
  }

  writeRaw(stored) {
    fs.writeFileSync(this.path, JSON.stringify(stored), { encoding: 'utf8', mode: 0o600 });
  }
}

module.exports = { CredentialStore };
