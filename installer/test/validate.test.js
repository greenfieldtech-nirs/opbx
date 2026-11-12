import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalizePublicUrl, isEmail, parseCidrList, isPortNumber } from '../src/validate.js';

test('normalizePublicUrl: loose production input', () => {
  assert.deepEqual(normalizePublicUrl('PBX.Example.com/', 'production'), { ok: true, value: 'https://pbx.example.com' });
  assert.deepEqual(normalizePublicUrl('pbx.example.com', 'production'), { ok: true, value: 'https://pbx.example.com' });
  assert.deepEqual(normalizePublicUrl('https://pbx.example.com:8443', 'production'), { ok: true, value: 'https://pbx.example.com:8443' });
});

test('normalizePublicUrl: refusals', () => {
  const path = normalizePublicUrl('https://pbx.example.com/ui', 'production');
  assert.equal(path.ok, false);
  assert.match(path.reason, /path/i);
  const http = normalizePublicUrl('http://pbx.example.com', 'production');
  assert.equal(http.ok, false);
  assert.match(http.reason, /HTTPS/);
  assert.equal(normalizePublicUrl('https://203.0.113.4', 'production').ok, false);
  assert.equal(normalizePublicUrl('https://pbx.example.com?x=1', 'production').ok, false);
  assert.equal(normalizePublicUrl('', 'production').ok, false);
  assert.equal(normalizePublicUrl('https://exa mple.com', 'production').ok, false);
});

test('normalizePublicUrl: production refuses localhost and single-label hosts', () => {
  for (const s of ['https://localhost', 'intranet', 'https://pbx:8443']) {
    const r = normalizePublicUrl(s, 'production');
    assert.equal(r.ok, false, s);
    assert.match(r.reason, /real domain name/);
  }
});

test('normalizePublicUrl: development', () => {
  assert.deepEqual(normalizePublicUrl('http://localhost', 'development'), { ok: true, value: 'http://localhost' });
  assert.deepEqual(normalizePublicUrl('http://localhost:8080/', 'development'), { ok: true, value: 'http://localhost:8080' });
  assert.equal(normalizePublicUrl('http://localhost/x', 'development').ok, false);
});

test('isEmail / isPortNumber', () => {
  assert.equal(isEmail('a@b.co'), true);
  assert.equal(isEmail('a@b'), false);
  assert.equal(isEmail('a b@c.de'), false);
  assert.equal(isPortNumber(80), true);
  assert.equal(isPortNumber(0), false);
  assert.equal(isPortNumber(65536), false);
  assert.equal(isPortNumber(80.5), false);
  assert.equal(isPortNumber('80'), false);
});

test('parseCidrList accepts', () => {
  assert.deepEqual(parseCidrList('10.0.0.0/8, 192.168.1.5'), { ok: true, value: ['10.0.0.0/8', '192.168.1.5'] });
  assert.deepEqual(parseCidrList(''), { ok: true, value: [] });
  for (const s of ['::1', 'fd00::/8', '::', '2001:db8::1/128', '1:2:3:4:5:6:7:8']) {
    assert.equal(parseCidrList(s).ok, true, s);
  }
});

test('parseCidrList rejects', () => {
  for (const s of ['10.0.0/8', '999.1.1.1', '1.2.3.4/99', '::::', 'abc:', 'dead:beef', ':', '1.2.3.4,', 'evil;x', '::1/129', '1::2::3', '1:2:3:4::5:6:7:8']) {
    const r = parseCidrList(s);
    assert.equal(r.ok, false, s);
    assert.ok(r.reason, s);
  }
});
