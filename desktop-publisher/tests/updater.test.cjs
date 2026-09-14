'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { verifyUpdatePackage } = require('../src/updater.cjs');

test('an update starts only after sha256 and signature verification', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'geoflow-update-'));
  const installer = path.join(directory, 'publisher.exe');
  const bytes = Buffer.from('signed test installer');
  fs.writeFileSync(installer, bytes);
  const { publicKey, privateKey } = crypto.generateKeyPairSync('ed25519');
  const publicKeyHex = publicKey.export({ format: 'der', type: 'spki' }).subarray(-32).toString('hex');
  const signature = crypto.sign(null, bytes, privateKey).toString('hex');
  const sha256 = crypto.createHash('sha256').update(bytes).digest('hex');
  assert.equal(verifyUpdatePackage(installer, sha256, signature, publicKeyHex).verified, true);
  assert.throws(() => verifyUpdatePackage(installer, '0'.repeat(64), signature, publicKeyHex), /update_sha256_mismatch/);
  fs.rmSync(directory, { recursive: true, force: true });
});
