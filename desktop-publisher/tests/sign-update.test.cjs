'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { signUpdatePackage } = require('../scripts/sign-update.cjs');
const { verifyUpdatePackage } = require('../src/updater.cjs');

test('the offline signer accepts only the matching Ed25519 key and creates a verifiable sidecar', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'geoflow-update-signing-'));
  try {
    const installer = path.join(directory, 'publisher.exe');
    const privateKeyPath = path.join(directory, 'targets-private.pem');
    const signaturePath = `${installer}.sig`;
    const bytes = Buffer.from('offline signed desktop publisher');
    const { publicKey, privateKey } = crypto.generateKeyPairSync('ed25519');
    const publicKeyHex = publicKey.export({ format: 'der', type: 'spki' }).subarray(-32).toString('hex');
    fs.writeFileSync(installer, bytes);
    fs.writeFileSync(privateKeyPath, privateKey.export({ format: 'pem', type: 'pkcs8' }), { mode: 0o600 });

    const result = signUpdatePackage(installer, privateKeyPath, signaturePath, publicKeyHex);
    const signature = fs.readFileSync(signaturePath, 'utf8').trim();
    assert.equal(result.sha256, crypto.createHash('sha256').update(bytes).digest('hex'));
    assert.equal(verifyUpdatePackage(installer, result.sha256, signature, publicKeyHex).verified, true);
    assert.throws(
      () => signUpdatePackage(installer, privateKeyPath, signaturePath, '0'.repeat(64)),
      /private_key_does_not_match_pinned_public_key/,
    );
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});
