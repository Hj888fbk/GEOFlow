'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { isAllowedLoginUrl } = require('../src/login-probes.cjs');

test('embedded login permits known platform and SSO hosts only', () => {
  assert.equal(isAllowedLoginUrl('https://mp.csdn.net/'), true);
  assert.equal(isAllowedLoginUrl('https://passport.csdn.net/login'), true);
  assert.equal(isAllowedLoginUrl('https://xui.ptlogin2.qq.com/cgi-bin/xlogin'), true);
  assert.equal(isAllowedLoginUrl('about:blank'), true);
  assert.equal(isAllowedLoginUrl('http://mp.csdn.net/'), false);
  assert.equal(isAllowedLoginUrl('https://csdn.net.evil.example/'), false);
  assert.equal(isAllowedLoginUrl('javascript:alert(1)'), false);
});
