'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { LOGIN_PROBES, isAllowedLoginUrl, interpretApiProbe, probeApiLogin } = require('../src/login-probes.cjs');

test('zhihu and csdn probes declare official API fallbacks', () => {
  assert.equal(LOGIN_PROBES.zhihu_column.apiProbe.url, 'https://www.zhihu.com/api/v4/me');
  assert.ok(LOGIN_PROBES.zhihu_column.apiProbe.userFields.length > 0);
  assert.match(LOGIN_PROBES.csdn.apiProbe.url, /^https:\/\/g-api\.csdn\.net\//);
  assert.ok(LOGIN_PROBES.csdn.apiProbe.userFields.length > 0);
});

test('weibo login probe exists and its host is allowlisted', () => {
  assert.equal(LOGIN_PROBES.weibo.loginUrl, 'https://weibo.com/');
  assert.equal(isAllowedLoginUrl('https://weibo.com/'), true);
  assert.equal(isAllowedLoginUrl('https://s.weibo.com/'), true);
});

test('interpretApiProbe maps status and user fields to a verdict only', () => {
  assert.equal(interpretApiProbe(200, { id: 123, name: 'someone' }, ['id', 'name']), 'logged_in');
  assert.equal(interpretApiProbe(200, { url_token: 'abc' }, ['id', 'url_token']), 'logged_in');
  assert.equal(interpretApiProbe(401, null, ['id']), 'logged_out');
  assert.equal(interpretApiProbe(403, { error: 'forbidden' }, ['id']), 'logged_out');
  assert.equal(interpretApiProbe(200, {}, ['id']), 'unknown');
  assert.equal(interpretApiProbe(200, { id: '' }, ['id']), 'unknown');
  assert.equal(interpretApiProbe(200, null, ['id']), 'unknown');
  assert.equal(interpretApiProbe(500, null, ['id']), 'unknown');
  assert.equal(interpretApiProbe(302, null, ['id']), 'unknown');
});

test('probeApiLogin requests with cookies included and never leaks user data', async () => {
  let seenOptions;
  const fetchImpl = async (url, options) => {
    seenOptions = options;
    assert.equal(url, 'https://www.zhihu.com/api/v4/me');
    return { status: 200, json: async () => ({ id: 987654321, name: 'secret-nickname' }) };
  };
  const result = await probeApiLogin(LOGIN_PROBES.zhihu_column.apiProbe, fetchImpl);
  assert.equal(seenOptions.credentials, 'include');
  assert.equal(result.loggedIn, true);
  assert.equal(result.verdict, 'logged_in');
  assert.equal(result.status, 200);
  // 只回传登录态结论，绝不携带响应体中的用户信息
  const serialized = JSON.stringify(result);
  assert.ok(!serialized.includes('987654321'));
  assert.ok(!serialized.includes('secret-nickname'));
  assert.equal(Object.hasOwn(result, 'body'), false);
});

test('probeApiLogin treats 401/403 as logged out and failures as unknown', async () => {
  const unauthorized = await probeApiLogin(LOGIN_PROBES.csdn.apiProbe, async () => ({ status: 401, json: async () => null }));
  assert.equal(unauthorized.loggedIn, false);
  const forbidden = await probeApiLogin(LOGIN_PROBES.csdn.apiProbe, async () => ({ status: 403, json: async () => null }));
  assert.equal(forbidden.loggedIn, false);
  const networkDown = await probeApiLogin(LOGIN_PROBES.csdn.apiProbe, async () => { throw new Error('offline'); });
  assert.equal(networkDown.loggedIn, null);
  const noProbe = await probeApiLogin(null, async () => ({ status: 200, json: async () => ({}) }));
  assert.equal(noProbe.loggedIn, null);
});
