import { test } from 'node:test';
import assert from 'node:assert/strict';
import { generateOpbxSecrets, generateSafelineSecrets, generateOwnerPassword } from '../src/secrets.js';

const WEAK = ['secret', 'password', 'rootsecret', 'minioadmin', 'admin', '123456', 'changeme', 'your-'];

test('opbx secrets', () => {
  const s = generateOpbxSecrets();
  assert.deepEqual(Object.keys(s).sort(), ['APP_KEY', 'DB_PASSWORD', 'DB_ROOT_PASSWORD', 'REDIS_PASSWORD']);
  assert.match(s.APP_KEY, /^base64:[A-Za-z0-9+/]{43}=$/);
  for (const k of ['DB_PASSWORD', 'DB_ROOT_PASSWORD', 'REDIS_PASSWORD']) assert.match(s[k], /^[0-9a-f]{64}$/);
  assert.equal(new Set(Object.values(s)).size, 4);
  for (const v of Object.values(s)) for (const w of WEAK) assert.ok(!v.includes(w));
  assert.notEqual(generateOpbxSecrets().APP_KEY, s.APP_KEY);
});

test('safeline secrets', () => {
  const s = generateSafelineSecrets();
  assert.deepEqual(Object.keys(s).sort(), ['DETECTOR_AES_KEY', 'DETECTOR_MGMT_TOKEN', 'MGT_INTERNAL_TOKEN', 'POSTGRES_PASSWORD']);
  assert.match(s.POSTGRES_PASSWORD, /^[0-9a-f]{32}$/);
  assert.match(s.MGT_INTERNAL_TOKEN, /^[0-9a-f]{64}$/);
  assert.match(s.DETECTOR_MGMT_TOKEN, /^[0-9a-f]{64}$/);
  assert.match(s.DETECTOR_AES_KEY, /^[A-Za-z0-9]{16}$/);
});

test('owner password', () => {
  const p = generateOwnerPassword();
  assert.match(p, /^([A-HJ-NP-Z2-9]{4}-){4}[A-HJ-NP-Z2-9]{4}$/);
  assert.notEqual(generateOwnerPassword(), p);
});
