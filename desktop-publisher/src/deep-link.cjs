'use strict';

function parsePublisherDeepLink(candidate, currentInstance) {
  const url = new URL(String(candidate));
  if (url.protocol !== 'geoflow-publisher:' || url.hostname !== 'login') throw new Error('publisher_deep_link_blocked');
  const instance = new URL(url.searchParams.get('instance') || '');
  const current = new URL(currentInstance);
  if (instance.origin !== current.origin) throw new Error('publisher_instance_mismatch');
  const accountId = Number(url.searchParams.get('account'));
  if (!Number.isSafeInteger(accountId) || accountId < 1) throw new Error('publisher_account_invalid');
  return { instance: instance.origin, accountId };
}

function findPublisherDeepLink(argv) {
  return argv.find((value) => String(value).startsWith('geoflow-publisher://')) || null;
}

module.exports = { findPublisherDeepLink, parsePublisherDeepLink };
