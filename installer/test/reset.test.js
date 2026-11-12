import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { runReset, confirmPhrase } from '../src/modes/reset.js';

const ENV = 'APP_ENV=production\nAPP_URL=https://pbx.example.com\nDB_DATABASE=opbx\nDB_ROOT_PASSWORD=SuperSecretRoot\nAUTH0_CLIENT_SECRET=Auth0Secret\nAUTH0_DOMAIN=x.auth0.com\n';

function setup({ env = ENV, waf = true } = {}) {
  const dir = mkdtempSync(join(tmpdir(), 'opbx-reset-'));
  writeFileSync(join(dir, '.env'), env);
  writeFileSync(join(dir, 'docker-compose.yml'), 'x');
  writeFileSync(join(dir, 'opbx-install-summary.txt'), 'x');
  mkdirSync(join(dir, 'volumes/mysql'), { recursive: true });
  writeFileSync(join(dir, 'volumes/mysql/f'), 'x');
  if (waf) { mkdirSync(join(dir, 'docker/safeline'), { recursive: true }); writeFileSync(join(dir, 'docker/safeline/.env'), 'SAFELINE_DIR=/var/lib/safeline\n'); }
  return dir;
}

function harness({ phrases = [], mysqlUp = false, backupYes = false, reuse = true, safelineGone = true } = {}) {
  const calls = [];
  const texts = [...phrases];
  const ui = {
    note: (t, b) => calls.push(['note', t, b]),
    text: async () => { calls.push(['text']); return texts.shift() ?? ''; },
    confirm: async ({ message }) => { calls.push(['confirm', message]); return /Back up/.test(message) ? backupYes : reuse; },
  };
  const docker = {
    compose: async (a) => {
      calls.push(['compose', ...a]);
      if (a[0] === 'config') return { code: 0, stdout: JSON.stringify({ name: 'opbx' }), stderr: '' };
      if (a[0] === 'ps') return { code: 0, stdout: mysqlUp ? 'abc\n' : '', stderr: '' };
      if (a[0] === 'exec') return { code: 0, stdout: 'CREATE TABLE t (id int);', stderr: '' };
      return { code: 0, stdout: '', stderr: '' };
    },
    run: async (a) => { calls.push(['run', ...a]); return { code: a.includes('true') ? (safelineGone ? 1 : 0) : 0, stdout: '', stderr: '' }; },
  };
  const out = { installed: null };
  const steps = {
    hostStep: async (_u, o) => { calls.push(['hostStep', o.commands.join('|')]); calls.push(['hostCheck', (await o.check()).ok]); },
    runInstall: async (o) => { calls.push(['runInstall']); out.installed = o; },
  };
  return { ui, docker, steps, calls, out };
}
const go = (h, dir) => runReset({ ui: h.ui, docker: h.docker, repoDir: dir, platform: {}, steps: h.steps });
const destructive = (calls) => calls.filter((c) => (c[0] === 'compose' && c.includes('down')) || (c[0] === 'run' && (c.includes('rm') || c.includes('volume'))) || c[0] === 'hostStep' || c[0] === 'runInstall');

test('confirmPhrase is exact and case-sensitive, three attempts', async () => {
  const mk = (xs) => ({ text: async () => xs.shift() });
  assert.equal(await confirmPhrase(mk(['reset', 'RESET ', 'RESET']), 'RESET'), true);
  assert.equal(await confirmPhrase(mk(['a', 'b', 'c', 'RESET']), 'RESET'), false);
});

test('three wrong phrases: zero destructive calls, files untouched', async () => {
  const dir = setup();
  const h = harness({ phrases: ['PBX.example.com', 'RESET', 'pbx'] });
  await assert.rejects(go(h, dir), /Nothing was changed/);
  assert.deepEqual(destructive(h.calls), []);
  assert.ok(existsSync(join(dir, '.env')) && existsSync(join(dir, 'volumes/mysql/f')));
});

test('correct phrase: destructive calls follow confirmation, in order', async () => {
  const dir = setup();
  const h = harness({ phrases: ['pbx.example.com'] });
  await go(h, dir);
  const seq = h.calls.map((c) => c[0] === 'compose' ? `compose ${c.slice(1).join(' ')}` : c[0] === 'run' ? `run ${c[1]}` : c[0]);
  const idx = (s) => seq.findIndex((x) => x.startsWith(s));
  const order = ['text', 'compose --profile * down --remove-orphans -v', 'compose --project-directory docker/safeline -p safeline down', 'run volume', 'run run', 'hostStep', 'runInstall'].map(idx);
  assert.ok(order.every((i, n) => i >= 0 && (n === 0 || i > order[n - 1])), seq.join('\n'));
  assert.ok(h.calls.findIndex((c) => c[0] === 'note' && /destroy/.test(c[1])) < order[0]);
  assert.deepEqual(h.calls.find((c) => c[0] === 'run' && c[1] === 'volume'), ['run', 'volume', 'rm', '-f', 'opbx_loki_data', 'opbx_grafana_data']);
  assert.ok(!existsSync(join(dir, '.env')) && !existsSync(join(dir, 'docker-compose.yml')) && !existsSync(join(dir, 'docker/safeline/.env')));
  assert.ok(h.calls.some((c) => c[0] === 'hostStep' && c[1] === 'sudo rm -rf /var/lib/safeline'));
  assert.ok(h.calls.some((c) => c[0] === 'hostCheck' && c[1] === true));
});

test('data deletion mounts volumes/ with --mount and targets exactly four dirs', async () => {
  const dir = setup();
  const h = harness({ phrases: ['pbx.example.com'] });
  await go(h, dir);
  const rm = h.calls.find((c) => c[0] === 'run' && c[1] === 'run');
  assert.deepEqual(rm.slice(1, 5), ['run', '--rm', '--mount', `type=bind,src=${dir}/volumes,dst=/v`]);
  assert.ok(!rm.includes('-v'));
  assert.equal(rm.at(-1), 'rm -rf /v/mysql /v/redis /v/seaweedfs /v/amd-dumps');
});

test('development uses RESET; previousAnswers carries no secrets', async () => {
  const dir = setup({ env: ENV.replace('production', 'development'), waf: false });
  const h = harness({ phrases: ['RESET'] });
  await go(h, dir);
  const json = JSON.stringify(h.out.installed.defaults);
  assert.ok(json.includes('auth0') || json.includes('x.auth0.com'));
  assert.ok(!/SuperSecretRoot|Auth0Secret/.test(json));
  assert.equal(h.out.installed.resume, false);
  assert.ok(!h.calls.some((c) => c[0] === 'compose' && c.includes('safeline')));
});

test('declining reuse installs with no defaults', async () => {
  const h = harness({ phrases: ['pbx.example.com'], reuse: false });
  await go(h, setup());
  assert.equal(h.out.installed.defaults, undefined);
});

test('backup offered only when mysql runs; accepted backup is gzipped into backups/', async () => {
  const dir = setup();
  const h = harness({ phrases: ['pbx.example.com'], mysqlUp: true, backupYes: true });
  await go(h, dir);
  const exec = h.calls.find((c) => c[0] === 'compose' && c[1] === 'exec');
  assert.ok(!exec.join(' ').includes('SuperSecretRoot'));
  assert.ok(existsSync(join(dir, 'backups')));
  const h2 = harness({ phrases: ['pbx.example.com'] });
  await go(h2, setup());
  assert.ok(!h2.calls.some((c) => c[0] === 'confirm' && /Back up/.test(c[1])));
});

test('failed backup aborts before anything destructive', async () => {
  const h = harness({ phrases: ['pbx.example.com'], mysqlUp: true, backupYes: true });
  const orig = h.docker.compose;
  h.docker.compose = async (a) => (a[0] === 'exec' ? { code: 1, stdout: '', stderr: 'boom' } : orig(a));
  await assert.rejects(go(h, setup()), /Backup failed/);
  assert.deepEqual(destructive(h.calls), []);
  assert.ok(!h.calls.some((c) => c[0] === 'text'));
});

test('refuses when nothing is installed', async () => {
  const dir = mkdtempSync(join(tmpdir(), 'opbx-reset-'));
  const h = harness();
  await assert.rejects(go(h, dir), /Nothing to reset/);
  assert.deepEqual(h.calls, []);
});

test('unsafe SAFELINE_DIR refuses before any command', async () => {
  const dir = setup();
  writeFileSync(join(dir, 'docker/safeline/.env'), 'SAFELINE_DIR=/x; rm -rf /\n');
  const h = harness({ phrases: ['pbx.example.com'] });
  await assert.rejects(go(h, dir), /Unsafe/);
  assert.deepEqual(h.calls, []);
});
