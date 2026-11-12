import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, statSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { renderSummary, proxySnippet, saveSummary } from '../src/summary.js';

const base = {
  mode: 'production', publicUrl: 'https://pbx.example.com', httpPort: 8080, trustedProxyCidr: '10.0.0.5/32',
  tenancy: 'single', email: { provider: 'mailgun' }, waf: { mode: 'monitor', stateDir: '/opt/sl', consolePort: 9555, consoleAllow: '' },
  logs: true, ownerEmail: 'owner@example.com',
};
const res = { ownerPassword: 'Pw-12345', wafAdmin: { username: 'admin', password: 'WafPw-9' }, checks: [{ name: 'up', ok: true, detail: 'ok' }, { name: 'ws', ok: false, detail: 'down' }] };

test('production+WAF+single summary has the essentials', () => {
  const s = renderSummary(base, res);
  for (const x of ['https://pbx.example.com/api/v1', 'https://pbx.example.com/logs/', 'https://pbx.example.com:9555', 'owner@example.com', 'Pw-12345',
    'This password will not be shown again.', 'WafPw-9', 'proxy_pass http://<this-server>:8080', 'proxy_pass http://<this-server>:9555',
    'listen 9555 ssl', 'and 9555', 'client_max_body_size 50m', 'existing server { } block for pbx.example.com', 'separate server { } block', 'only from this proxy', 'sign-up', 'Settings -> Cloudonix', 'webhook', 'reconfigure', 'backup-database.sh', 'docker compose up -d',
    'opbx-install-summary.txt', 'Delete it once', 'ws: FAIL']) assert.ok(s.includes(x), x);
  assert.ok(!s.includes('\x1b['));
  assert.ok(!s.includes('protected only by'));
});

test('multi-org does not tell the user to set webhooks', () => {
  const s = renderSummary({ ...base, tenancy: 'multi' }, res);
  assert.ok(!s.includes('Settings -> Cloudonix'));
  assert.ok(!s.includes('open sign-up') && !s.includes('Anyone who can reach'));
});

test('development has no snippet, localhost WAF console and ngrok', () => {
  const a = { ...base, mode: 'development', publicUrl: 'http://localhost:3000', ngrokToken: 't' };
  const s = renderSummary(a, res);
  assert.ok(!s.includes('proxy_pass'));
  assert.ok(s.includes('http://localhost:9555') && s.includes('http://localhost:4040'));
});

test('no proxy IP + WAF, no WAF admin, email none', () => {
  const s = renderSummary({ ...base, trustedProxyCidr: '', email: { provider: 'none' } }, { ...res, wafAdmin: undefined });
  assert.ok(s.includes('protected only by SafeLine'));
  assert.ok(s.includes('docker exec safeline-mgt /app/mgt reset-admin'));
  assert.ok(s.includes('rate limit'));
  assert.ok(s.includes('invitations'));
});

test('proxySnippet without WAF has one block', () => {
  const t = proxySnippet({ ...base, waf: null });
  assert.ok(!t.includes('9555') && !t.includes('separate server'));
  assert.ok(t.includes('client_max_body_size 50m'));
  assert.ok(t.includes('proxy_read_timeout 3600s'));
});

test('saveSummary writes mode 600', () => {
  const dir = mkdtempSync(join(tmpdir(), 'sum-'));
  const p = saveSummary(dir, 'hello', {});
  assert.equal(p, join(dir, 'opbx-install-summary.txt'));
  assert.equal(statSync(p).mode & 0o777, 0o600);
  assert.equal(readFileSync(p, 'utf8'), 'hello');
});
