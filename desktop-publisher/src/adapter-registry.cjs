'use strict';

const crypto = require('node:crypto');
const { platforms } = require('./platforms.cjs');

const REQUIRED_PLATFORMS = Object.freeze([
  'sohu_media', 'netease_media', 'toutiao', 'baijiahao', 'dayu',
  'qq_penguin', 'zhihu_column', 'jianshu', 'csdn', 'douyin',
]);

class AdapterRegistry {
  constructor(definitions = platforms) {
    this.definitions = definitions;
    this.assertContract();
    this.digest = crypto.createHash('sha256').update(stableJson(definitions)).digest('hex');
  }

  get(platform) {
    const adapter = this.definitions[platform];
    if (!adapter) throw new Error(`unsupported_platform:${platform}`);
    return adapter;
  }

  assertAllowedUrl(platform, candidate) {
    const adapter = this.get(platform);
    const url = new URL(candidate);
    if (url.protocol !== 'https:' || !adapter.hosts.some((host) => url.hostname === host || url.hostname.endsWith(`.${host}`))) {
      throw new Error('navigation_blocked');
    }
    return url.toString();
  }

  assertContract() {
    for (const platform of REQUIRED_PLATFORMS) {
      const adapter = this.definitions[platform];
      if (!adapter) throw new Error(`missing_adapter:${platform}`);
      for (const field of ['hosts', 'editorUrl', 'loginMarkers', 'captchaMarkers', 'identitySelectors', 'titleSelectors', 'bodySelectors', 'imageInputSelectors', 'draftSelectors', 'draftListSelectors']) {
        if (!adapter[field] || (Array.isArray(adapter[field]) && adapter[field].length === 0)) throw new Error(`invalid_adapter:${platform}:${field}`);
      }
      for (const forbidden of ['publishSelector', 'publishSelectors', 'submitPublish', 'autoPublish']) {
        if (Object.hasOwn(adapter, forbidden)) throw new Error(`automatic_publish_forbidden:${platform}`);
      }
      this.assertAllowedUrl(platform, adapter.editorUrl);
    }
  }
}

function stableJson(value) {
  if (Array.isArray(value)) return `[${value.map(stableJson).join(',')}]`;
  if (value && typeof value === 'object') return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${stableJson(value[key])}`).join(',')}}`;
  return JSON.stringify(value);
}

module.exports = { AdapterRegistry, REQUIRED_PLATFORMS, stableJson };
