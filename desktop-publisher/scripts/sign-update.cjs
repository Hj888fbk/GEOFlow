'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { PINNED_TARGETS_PUBLIC_KEY } = require('../src/updater.cjs');

function signUpdatePackage(installerPath, privateKeyPath, signaturePath = `${installerPath}.sig`, expectedPublicKeyHex = PINNED_TARGETS_PUBLIC_KEY) {
  const resolvedInstaller = path.resolve(installerPath);
  const resolvedPrivateKey = path.resolve(privateKeyPath);
  const resolvedSignature = path.resolve(signaturePath);
  const repositoryRoot = path.resolve(__dirname, '..', '..');
  const privateKeyRelativePath = path.relative(repositoryRoot, resolvedPrivateKey);
  if (privateKeyRelativePath === '' || (!privateKeyRelativePath.startsWith('..') && !path.isAbsolute(privateKeyRelativePath))) {
    throw new Error('private_key_must_be_external');
  }
  if (!/^[a-f0-9]{64}$/.test(String(expectedPublicKeyHex))) throw new Error('pinned_public_key_invalid');

  const installer = fs.readFileSync(resolvedInstaller);
  const privateKey = crypto.createPrivateKey(fs.readFileSync(resolvedPrivateKey));
  if (privateKey.asymmetricKeyType !== 'ed25519') throw new Error('private_key_must_be_ed25519');
  const publicKey = crypto.createPublicKey(privateKey);
  const publicKeyDer = publicKey.export({ format: 'der', type: 'spki' });
  const publicKeyHex = publicKeyDer.subarray(-32).toString('hex');
  const expected = Buffer.from(String(expectedPublicKeyHex), 'hex');
  if (!crypto.timingSafeEqual(Buffer.from(publicKeyHex, 'hex'), expected)) throw new Error('private_key_does_not_match_pinned_public_key');

  const signature = crypto.sign(null, installer, privateKey).toString('hex');
  fs.writeFileSync(resolvedSignature, `${signature}\n`, { encoding: 'utf8', mode: 0o600 });

  return {
    installer: resolvedInstaller,
    signature: resolvedSignature,
    sha256: crypto.createHash('sha256').update(installer).digest('hex'),
    publicKey: publicKeyHex,
  };
}

if (require.main === module) {
  const [installerPath, privateKeyPath, signaturePath] = process.argv.slice(2);
  if (!installerPath || !privateKeyPath) {
    process.stderr.write('Usage: node scripts/sign-update.cjs <installer.exe> <external-ed25519-private-key.pem> [output.sig]\n');
    process.exitCode = 2;
  } else {
    try {
      const result = signUpdatePackage(installerPath, privateKeyPath, signaturePath);
      process.stdout.write(`${JSON.stringify(result)}\n`);
    } catch (error) {
      process.stderr.write(`${error.message}\n`);
      process.exitCode = 1;
    }
  }
}

module.exports = { signUpdatePackage };
