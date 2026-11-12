import { test } from 'node:test';
import assert from 'node:assert/strict';
import { runChecks } from '../src/verify.js';

const ok = (stdout = '') => ({ code: 0, stdout, stderr: '' });
const NET = JSON.stringify({ proj_opbx: {}, other_net: {} });
const ENV = new Map([['PUSHER_APP_KEY', 'k123']]);

function fake(over = {}) {
  const calls = [];
  const h = async (args) => {
    const s = args.join(' ');
    for (const [pat, res] of Object.entries(over)) if (s.includes(pat)) return typeof res === 'function' ? res(args) : res;
    if (s.includes('inspect opbx_nginx')) return ok(NET);
    if (s.includes('ps --all')) return ok('opbx_app running healthy\nopbx_nginx running ');
    if (s.includes('logs/api/health')) return ok('{"database":"ok"}');
    if (s.includes('/up')) return ok('OK');
    if (s.includes('/api/v1/')) return ok('  HTTP/1.1 401 Unauthorized');
    if (s.includes('wget') && s.includes('127.0.0.1/')) return ok('<!doctype html><html>');
    if (s.includes('node:26-alpine')) return ok('HTTP/1.1 101 Switching Protocols');
    if (s.includes('config:validate')) return ok('ok');
    if (s.includes('8443')) return ok('<html>');
    return ok('');
  };
  return { run: async (a) => { calls.push(a); return h(a); }, compose: async (a) => { calls.push(['compose', ...a]); return h(a); }, calls };
}
const byName = (r, n) => r.find((c) => c.name === n);

test('all green without WAF/logs: every check has name/ok/detail, WAF/logs absent', async () => {
  const r = await runChecks(fake(), { waf: null, logs: false }, { env: ENV });
  assert.ok(r.length >= 6 && r.every((c) => c.name && typeof c.ok === 'boolean' && 'detail' in c));
  assert.ok(r.every((c) => c.ok), JSON.stringify(r.filter((c) => !c.ok)));
  assert.ok(!r.some((c) => /waf|grafana/i.test(c.name)));
});

test('failing /up is reported with output in detail, others still run, never throws', async () => {
  const d = fake({ '/up': { code: 1, stdout: '', stderr: 'wget: server returned error: 502' } });
  const r = await runChecks(d, { waf: null, logs: false }, { env: ENV });
  const up = byName(r, 'nginx /up');
  assert.equal(up.ok, false);
  assert.match(up.detail, /502/);
  assert.ok(r.filter((c) => c.ok).length >= 4);
});

test('a thrown error becomes a failed check', async () => {
  const d = fake({ 'config:validate': () => { throw new Error('spawn failed'); } });
  const r = await runChecks(d, { waf: null, logs: false }, { env: ENV });
  assert.equal(byName(r, 'config:validate').ok, false);
});

test('not-running or unhealthy services fail', async () => {
  const d = fake({ 'ps --all': ok('opbx_app running starting\nopbx_queue exited ') });
  const c = byName(await runChecks(d, { waf: null, logs: false }, { env: ENV }), 'services');
  assert.equal(c.ok, false);
  assert.match(c.detail, /opbx_queue|opbx_app/);
});

test('websocket check uses discovered network and Pusher key; non-101 fails', async () => {
  const d = fake({ 'node:26-alpine': ok('HTTP/1.1 400 Bad Request') });
  const r = await runChecks(d, { waf: null, logs: false }, { env: ENV });
  const ws = byName(r, 'websocket');
  assert.equal(ws.ok, false);
  const call = d.calls.find((a) => a.includes('node:26-alpine')).join(' ');
  assert.match(call, /--network proj_opbx/);
  assert.match(call, /k123/);
});

test('WAF block mode expects 403; monitor mode looks for safeline-waf in the error log; console check unless disabled', async () => {
  const block = fake({ '%27': { code: 1, stdout: '', stderr: '  HTTP/1.1 403 Forbidden' } });
  const rb = await runChecks(block, { waf: { mode: 'block' }, logs: false }, { env: ENV });
  assert.equal(byName(rb, 'waf detection').ok, true);
  assert.ok(byName(rb, 'waf console'));

  const mon = fake({ opbx_error: ok('safeline-waf: would block (monitor)') });
  const rm = await runChecks(mon, { waf: { mode: 'monitor' }, logs: false }, { env: new Map([...ENV, ['WAF_CONSOLE_ENABLED', 'false']]) });
  assert.equal(byName(rm, 'waf detection').ok, true);
  assert.ok(!byName(rm, 'waf console'));

  const bad = fake({ '%27': { code: 0, stdout: 'hi', stderr: '  HTTP/1.1 200 OK' } });
  assert.equal(byName(await runChecks(bad, { waf: { mode: 'block' }, logs: false }, { env: ENV }), 'waf detection').ok, false);
});

test('logs: Grafana health check only when logs on', async () => {
  const r = await runChecks(fake(), { waf: null, logs: true }, { env: ENV });
  assert.equal(byName(r, 'grafana').ok, true);
});
