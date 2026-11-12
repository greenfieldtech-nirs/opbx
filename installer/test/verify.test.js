import { test } from 'node:test';
import assert from 'node:assert/strict';
import { runChecks, waitServicesReady } from '../src/verify.js';

const ok = (stdout = '') => ({ code: 0, stdout, stderr: '' });
const ps = (rows) => rows.map(([Service, State, Health]) => JSON.stringify({ Service, State, Health })).join('\n');
const NET = JSON.stringify({ proj_opbx: {}, other_net: {} });
const ENV = new Map([['PUSHER_APP_KEY', 'k123']]);

function fake(over = {}) {
  const calls = [];
  const h = async (args) => {
    const s = args.join(' ');
    for (const [pat, res] of Object.entries(over)) if (s.includes(pat)) return typeof res === 'function' ? res(args) : res;
    if (s.includes('inspect opbx_nginx')) return ok(NET);
    if (s.includes('config --services')) return ok('app\nnginx\n');
    if (s.includes('ps --all')) return ok(ps([['app', 'running', 'healthy'], ['nginx', 'running', ''], ['ngrok', 'exited', '']]));
    if (s.includes('logs/api/health')) return ok('{"database":"ok"}');
    if (s.includes('/up')) return ok('<html><body>Application up</body></html>');
    if (s.includes('/api/v1/')) return ok('  HTTP/1.1 401 Unauthorized');
    if (s.includes('wget') && s.includes('127.0.0.1/')) return ok('<!doctype html><html>');
    if (s.includes('node:26-alpine')) return ok('HTTP/1.1 101 Switching Protocols');
    if (s.includes('config:validate')) return ok('ok');
    if (s.startsWith('port opbx_nginx')) return ok('0.0.0.0:9443\n');
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

test('SPA html on /up fails; inactive-profile exited container does not fail services', async () => {
  const d = fake({ '/up': ok('<!doctype html><html><div id="root"></div>') });
  const r = await runChecks(d, { waf: null, logs: false }, { env: ENV });
  assert.equal(byName(r, 'nginx /up').ok, false);
  assert.equal(byName(r, 'services').ok, true); // ngrok exited but not in config --services
});

test('not-running or unhealthy services fail', async () => {
  const d = fake({ 'ps --all': ok(ps([['app', 'running', 'starting'], ['nginx', 'exited', '']])) });
  const c = byName(await runChecks(d, { waf: null, logs: false }, { env: ENV }), 'services');
  assert.equal(c.ok, false);
  assert.match(c.detail, /app.*nginx/);
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

  let n = 0;
  const mon = fake({ 'grep -c': () => ok(String(n++ === 0 ? 3 : 4)) });
  const rm = await runChecks(mon, { waf: { mode: 'monitor' }, logs: false }, { env: new Map([...ENV, ['WAF_CONSOLE_ENABLED', 'false']]) });
  assert.equal(byName(rm, 'waf detection').ok, true);
  assert.ok(!byName(rm, 'waf console'));

  const stale = fake({ 'grep -c': ok('3') });
  assert.equal(byName(await runChecks(stale, { waf: { mode: 'monitor' }, logs: false }, { env: ENV }), 'waf detection').ok, false);

  const bad = fake({ '%27': { code: 0, stdout: 'hi', stderr: '  HTTP/1.1 200 OK' } });
  assert.equal(byName(await runChecks(bad, { waf: { mode: 'block' }, logs: false }, { env: ENV }), 'waf detection').ok, false);
});

test('logs: Grafana health check only when logs on', async () => {
  const r = await runChecks(fake(), { waf: null, logs: true }, { env: ENV });
  assert.equal(byName(r, 'grafana').ok, true);
});

test('waf console is checked from 127.0.0.1 inside nginx also with an allowlist (render.sh always allows 127.0.0.1)', async () => {
  const d = fake();
  const r = await runChecks(d, { waf: { mode: 'block' }, logs: false }, { env: new Map([...ENV, ['WAF_CONSOLE_ALLOW', '198.51.100.0/24']]) });
  assert.equal(byName(r, 'waf console').ok, true);
  assert.ok(d.calls.some((a) => a[0] === 'exec' && a[1] === 'opbx_nginx' && a.join(' ').includes('http://127.0.0.1:8443/')));
});

test('waf console: the published host port must be WAF_CONSOLE_PORT', async () => {
  const good = fake({ 'port opbx_nginx': ok('127.0.0.1:9555\n') });
  assert.equal(byName(await runChecks(good, { waf: { mode: 'block', consolePort: 9555 }, logs: false }, { env: ENV }), 'waf console').ok, true);
  const bad = fake({ 'port opbx_nginx': ok('0.0.0.0:9443\n') });
  const c = byName(await runChecks(bad, { waf: { mode: 'block', consolePort: 9555 }, logs: false }, { env: ENV }), 'waf console');
  assert.equal(c.ok, false);
  assert.match(c.detail, /9555/);
});

test('storage: buckets checked with storage:initialize (idempotent); a failure is reported', async () => {
  const d = fake();
  const r = await runChecks(d, { waf: null, logs: false }, { env: ENV });
  assert.equal(byName(r, 'storage').ok, true);
  assert.ok(d.calls.some((a) => a.join(' ') === 'compose exec -T app php artisan storage:initialize --wait=10'));
  const bad = fake({ 'storage:initialize': { code: 1, stdout: '', stderr: 'Cannot reach the S3 endpoint' } });
  const c = byName(await runChecks(bad, { waf: null, logs: false }, { env: ENV }), 'storage');
  assert.equal(c.ok, false);
  assert.match(c.detail, /S3 endpoint/);
});

const spin = () => ({ spinner: () => ({ start() {}, message() {}, stop() {} }) });
const clk = () => { let t = 0; return { now: () => t, sleep: async (ms) => { t += ms; } }; };

test('waitServicesReady: polls until a starting service turns healthy', async () => {
  let n = 0;
  const d = fake({ 'ps --all': () => ok(ps([['app', 'running', 'healthy'], ['nginx', 'running', ++n < 3 ? 'starting' : 'healthy']])) });
  assert.deepEqual(await waitServicesReady(d, spin(), clk()), []);
  assert.equal(n, 3);
});

test('waitServicesReady: times out with the services still not ready', async () => {
  const d = fake({ 'ps --all': ok(ps([['app', 'running', 'healthy'], ['nginx', 'running', 'starting']])) });
  const bad = await waitServicesReady(d, spin(), { ...clk(), timeoutMs: 30_000 });
  assert.deepEqual(bad, ['nginx (running/starting)']);
});
