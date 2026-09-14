'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');

const PINNED_TARGETS_PUBLIC_KEY = '55d4a459c2dccaf6fde024a6e81b55e428665549adee23c4cc8391a0462d5d29';

function verifyUpdatePackage(filePath, expectedSha256, signatureHex, publicKeyHex = PINNED_TARGETS_PUBLIC_KEY) {
  const bytes = fs.readFileSync(filePath);
  const digest = crypto.createHash('sha256').update(bytes).digest('hex');
  if (!/^[a-f0-9]{64}$/.test(String(expectedSha256)) || !crypto.timingSafeEqual(Buffer.from(digest), Buffer.from(String(expectedSha256).toLowerCase()))) {
    throw new Error('update_sha256_mismatch');
  }
  if (!/^[a-f0-9]{64}$/.test(publicKeyHex) || !/^[a-f0-9]{128}$/.test(String(signatureHex))) throw new Error('update_signature_invalid');
  const publicKey = crypto.createPublicKey({
    key: Buffer.concat([Buffer.from('302a300506032b6570032100', 'hex'), Buffer.from(publicKeyHex, 'hex')]),
    format: 'der',
    type: 'spki',
  });
  const verified = crypto.verify(null, bytes, publicKey, Buffer.from(signatureHex, 'hex'));
  if (!verified) throw new Error('update_signature_invalid');
  return { sha256: digest, verified: true };
}

module.exports = { PINNED_TARGETS_PUBLIC_KEY, verifyUpdatePackage };
