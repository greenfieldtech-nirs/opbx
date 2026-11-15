import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, existsSync, rmSync, statSync, readFileSync, readdirSync } from 'node:fs';
import { gunzipSync } from 'node:zlib';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { runReset, confirmPhrase } from '../src/modes/reset.js';
import { state as uiState, cancelMessage } from '../src/ui.js';

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

function harness({ phrases = [], mysqlUp = false, backupYes = false, reuse = true, safeline = 'gone', fail = {} } = {}) {
  const calls = [];
  const texts = [...phrases];
  const ui = {
    note: (t, b) => calls.push(['note', t, b]),
    text: async () => { calls.push(['text']); return texts.shift() ?? ''; },
    confirm: async ({ message }) => { calls.push(['confirm', message]); return /Back up/.test(message) ? backupYes : reuse; },
  };
  const docker = {
    compose: async (a, opts = {}) => {
      calls.push(['compose', ...a]);
      if (a[0] === 'config') return fail.config ? { code: 1, stdout: '', stderr: 'bad' } : { code: 0, stdout: JSON.stringify({ name: 'opbx' }), stderr: '' };
      if (a[0] === 'ps') return { code: 0, stdout: mysqlUp ? 'abc\n' : '', stderr: '' };
      if (a[0] === 'exec' && /SHOW DATABASES/.test(a[5])) return fail.queryFails ? { code: 1, stdout: '', stderr: 'denied' } : { code: 0, stdout: fail.noArchive ? '' : fail.lookalike ? `${a.at(-1).replace('_', 'X')}\n${a.at(-1)}\n` : `${a.at(-1)}\n`, stderr: '' };
      if (a[0] === 'exec') {
        if (fail.exec) { opts.stdoutTo.end('partial'); return { code: 1, stdout: '', stderr: 'boom' }; }
        opts.stdoutTo.write('-- dump\nCREATE '); opts.stdoutTo.end('TABLE t (id int);');
        return { code: 0, stdout: '', stderr: '' };
      }
      if (a.includes('down') && fail.down === (a.includes('safeline') ? 'safeline' : 'opbx')) return { code: 1, stdout: '', stderr: 'daemon error' };
      return { code: 0, stdout: '', stderr: '' };
    },
    run: async (a) => {
      calls.push(['run', ...a]);
      if (a[0] === 'volume') return fail.volume ? { code: 1, stdout: '', stderr: fail.volume } : { code: 0, stdout: '', stderr: '' };
      const script = a.at(-1);
      if (script.startsWith('rm -rf')) {
        if (fail.rm) return { code: 1, stdout: '', stderr: 'rm failed' };
        for (const d of script.split(' ').slice(2)) rmSync(join(a[3].match(/src=(.*?),/)[1], d.slice(3)), { recursive: true, force: true });
        return { code: 0, stdout: '', stderr: '' };
      }
      // SafeLine "is it gone?" probe
      return {
        gone: { code: 0, stdout: '', stderr: '' },
        present: { code: 0, stdout: 'PRESENT resources\n', stderr: '' },
        nodir: { code: 125, stdout: '', stderr: 'invalid mount config: bind source path does not exist' },
        error: { code: 1, stdout: '', stderr: 'Cannot connect to the Docker daemon' },
      }[safeline];
    },
  };
  const out = { installed: null };
  const steps = {
    hostStep: async (_u, o) => { calls.push(['hostStep', o.commands.join('|')]); calls.push(['hostCheck', (await o.check()).ok]); },
    runInstall: async (o) => { calls.push(['runInstall']); out.installed = o; },
  };
  return { ui, docker, steps, calls, out };
}
const require_backup = (dir) => join(dir, 'backups', readdirSync(join(dir, 'backups'))[0]);
const go = (h, dir) => runReset({ ui: h.ui, docker: h.docker, repoDir: dir, platform: {}, steps: h.steps });
const destructive = (calls) => calls.filter((c) => (c[0] === 'compose' && c.includes('down')) || (c[0] === 'run' && (c[1] === 'volume' || c.includes('alpine'))) || c[0] === 'hostStep' || c[0] === 'runInstall');

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
  assert.ok(h.calls.some((c) => c[0] === 'hostStep' && c[1] === 'sudo rm -rf /var/lib/safeline/resources /var/lib/safeline/logs'));
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

test('backup streams a gzip (mode 0600), includes the archive DB; notice when mysql is down', async () => {
  const dir = setup({ env: ENV + 'DB_CDR_ARCHIVE_DATABASE=opbx_cdr\n' });
  const h = harness({ phrases: ['pbx.example.com'], mysqlUp: true, backupYes: true });
  await go(h, dir);
  const exec = h.calls.find((c) => c[0] === 'compose' && c[1] === 'exec' && /mysqldump/.test(c[6]));
  assert.ok(!exec.join(' ').includes('SuperSecretRoot'));
  assert.deepEqual(exec.slice(-3), ['sh', 'opbx', 'opbx_cdr']);
  const f = require_backup(dir);
  assert.equal(statSync(f).mode & 0o777, 0o600);
  assert.equal(gunzipSync(readFileSync(f)).toString(), '-- dump\nCREATE TABLE t (id int);');
  const h2 = harness({ phrases: ['pbx.example.com'] });
  await go(h2, setup());
  assert.ok(!h2.calls.some((c) => c[0] === 'confirm' && /Back up/.test(c[1])));
  const ni = h2.calls.findIndex((c) => c[0] === 'note' && /isn't running/.test(c[2]));
  assert.ok(ni >= 0 && ni < h2.calls.findIndex((c) => c[0] === 'text'));
});

test('failed backup aborts before anything destructive', async () => {
  const dir = setup();
  const h = harness({ phrases: ['pbx.example.com'], mysqlUp: true, backupYes: true });
  const orig = h.docker.compose;
  h.docker.compose = async (a, o) => (a[0] === 'exec' ? (o.stdoutTo.end('CREATE TABLE x'), { code: 1, stdout: '', stderr: 'boom' }) : orig(a, o));
  await assert.rejects(go(h, dir), /Backup failed/);
  assert.deepEqual(readdirSync(join(dir, 'backups')), []);
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

const intact = (dir) => existsSync(join(dir, '.env')) && existsSync(join(dir, 'volumes/mysql/f'));

test('failed OPBX down: nothing deleted, .env intact, no reinstall', async () => {
  const dir = setup();
  const h = harness({ phrases: ['pbx.example.com'], fail: { down: 'opbx' } });
  await assert.rejects(go(h, dir), /could not stop OPBX; containers may be partly stopped, but your data was not deleted/);
  assert.ok(!h.calls.some((c) => c[0] === 'run' || c[0] === 'runInstall'));
  assert.ok(intact(dir));
});

test('failed SafeLine down: nothing deleted', async () => {
  const dir = setup();
  const h = harness({ phrases: ['pbx.example.com'], fail: { down: 'safeline' } });
  await assert.rejects(go(h, dir), /could not stop SafeLine/);
  assert.ok(!h.calls.some((c) => c[0] === 'run'));
  assert.ok(intact(dir));
});

test('volume rm: "no such volume" tolerated, other errors stop the reset', async () => {
  await go(harness({ phrases: ['pbx.example.com'], fail: { volume: 'Error: No such volume: x' } }), setup());
  const dir = setup();
  const h = harness({ phrases: ['pbx.example.com'], fail: { volume: 'permission denied' } });
  await assert.rejects(go(h, dir), /could not remove the log volumes/);
  assert.ok(intact(dir));
});

test('failed data deletion: .env kept, no reinstall', async () => {
  const dir = setup();
  const h = harness({ phrases: ['pbx.example.com'], fail: { rm: true } });
  await assert.rejects(go(h, dir), /Data not fully deleted; your \.env was kept/);
  assert.ok(intact(dir));
  assert.ok(!h.calls.some((c) => c[0] === 'runInstall' || c[0] === 'hostStep'));
});

test('data deletion reporting success but dirs remain: .env kept', async () => {
  const dir = setup();
  const h = harness({ phrases: ['pbx.example.com'] });
  const orig = h.docker.run;
  h.docker.run = async (a) => (a.at(-1).startsWith('rm -rf') ? { code: 0, stdout: '', stderr: '' } : orig(a));
  await assert.rejects(go(h, dir), /Data not fully deleted/);
  assert.ok(intact(dir));
});

test('no volumes/ directory: the alpine step is skipped', async () => {
  const dir = setup();
  rmSync(join(dir, 'volumes'), { recursive: true });
  const h = harness({ phrases: ['pbx.example.com'] });
  await go(h, dir);
  assert.ok(!h.calls.some((c) => c[0] === 'run' && c.includes('--mount') && c.includes('rm -rf /v/mysql /v/redis /v/seaweedfs /v/amd-dumps')));
});

test('SafeLine removal targets only resources and logs; check handles all docker outcomes', async () => {
  for (const [safeline, ok] of [['gone', true], ['nodir', true], ['present', false], ['error', false]]) {
    const dir = setup();
    const h = harness({ phrases: ['pbx.example.com'], safeline });
    await go(h, dir);
    assert.ok(h.calls.some((c) => c[0] === 'hostCheck' && c[1] === ok), safeline);
  }
  const h = harness({ phrases: ['pbx.example.com'] });
  await go(h, setup());
  const list = h.calls.find((c) => c[0] === 'note' && /destroy/.test(c[1]))[2];
  assert.match(list, /\/var\/lib\/safeline\/resources and \/var\/lib\/safeline\/logs/);
});

test('interrupt after destruction starts says the reset was interrupted', async () => {
  uiState.resetStarted = false;
  const dir = setup();
  const h = harness({ phrases: ['pbx.example.com'] });
  h.ui.confirm = async () => { throw new Error('cancelled'); };
  h.steps.hostStep = async () => { throw new Error('cancelled'); };
  try {
    await assert.rejects(go(h, dir), /cancelled/);
    assert.match(cancelMessage(), /Reset was interrupted part-way; run \.\/install\.sh reset again\./);
  } finally { uiState.resetStarted = false; }
});

test('wrong phrases never set the interrupted flag', async () => {
  uiState.resetStarted = false;
  await assert.rejects(go(harness({ phrases: ['a', 'b', 'c'] }), setup()));
  assert.equal(uiState.resetStarted, false);
});

test('failed compose config: no destructive calls', async () => {
  const dir = setup();
  const h = harness({ phrases: ['pbx.example.com'], fail: { config: true } });
  await assert.rejects(go(h, dir), /compose project name/);
  assert.deepEqual(destructive(h.calls), []);
  assert.ok(intact(dir));
});

test('archive DB is left out of the dump when it does not exist', async () => {
  const h = harness({ phrases: ['pbx.example.com'], mysqlUp: true, backupYes: true, fail: { noArchive: true } });
  await go(h, setup({ env: ENV + 'DB_CDR_ARCHIVE_DATABASE=opbx_cdr\n' }));
  const dump = h.calls.find((c) => c[0] === 'compose' && c[1] === 'exec' && /mysqldump/.test(c[6]));
  assert.deepEqual(dump.slice(-2), ['sh', 'opbx']);
});

test('unwritable backups/ fails cleanly with nothing destructive', async () => {
  const dir = setup();
  writeFileSync(join(dir, 'backups'), 'i am a file');
  const h = harness({ phrases: ['pbx.example.com'], mysqlUp: true, backupYes: true });
  await assert.rejects(go(h, dir), /Backup failed, nothing was changed/);
  assert.deepEqual(destructive(h.calls), []);
});

test('after the wipe, cancel messages follow install state, not reset state', async () => {
  uiState.resetStarted = false; uiState.configWritten = false; uiState.resetDone = false;
  const seen = [];
  const h = harness({ phrases: ['pbx.example.com'] });
  h.steps.runInstall = async () => {
    seen.push(cancelMessage());
    uiState.configWritten = true;
    seen.push(cancelMessage());
  };
  try { await go(h, setup()); } finally { uiState.configWritten = false; uiState.resetStarted = false; uiState.resetDone = false; }
  assert.match(seen[0], /reset and all its data deleted/);
  assert.match(seen[1], /run \.\/install\.sh again to resume/);
});

test('archive DB check: look-alike name does not hide the real one; failed check is reported', async () => {
  const env = ENV + 'DB_CDR_ARCHIVE_DATABASE=opbx_cdr\n';
  const h = harness({ phrases: ['pbx.example.com'], mysqlUp: true, backupYes: true, fail: { lookalike: true } });
  await go(h, setup({ env }));
  assert.equal(h.calls.find((c) => c[0] === 'compose' && /mysqldump/.test(c[6])).at(-1), 'opbx_cdr');
  const h2 = harness({ phrases: ['pbx.example.com'], mysqlUp: true, backupYes: true, fail: { queryFails: true } });
  await go(h2, setup({ env }));
  assert.ok(h2.calls.some((c) => c[0] === 'note' && /NOT in this backup/.test(c[2])));
});
