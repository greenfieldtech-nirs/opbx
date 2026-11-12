import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, copyFileSync, readFileSync, writeFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import { runInstall } from '../src/modes/install.js';
import { parseEnv } from '../src/envfile.js';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
const answers = (o = {}) => ({
  mode: 'production', publicUrl: 'https://pbx.example.com', httpPort: 80, trustedProxyCidr: '', orgName: 'Acme', ownerName: 'Ann',
  ownerEmail: 'ann@example.com', tenancy: 'single', email: { provider: 'none', fields: {}, fromAddress: '', fromName: '' },
  auth0: null, recaptcha: null, usercheck: null, logs: false, waf: null, ngrokToken: '', advanced: {}, ...o,
});

function setup(envText) {
  const dir = mkdtempSync(join(tmpdir(), 'opbx-inst-'));
  copyFileSync(join(ROOT, '.env.example'), join(dir, '.env.example'));
  copyFileSync(join(ROOT, 'docker-compose.yml.example'), join(dir, 'docker-compose.yml.example'));
  mkdirSync(join(dir, 'docker/safeline'), { recursive: true });
  copyFileSync(join(ROOT, 'docker/safeline/.env.example'), join(dir, 'docker/safeline/.env.example'));
  if (envText) writeFileSync(join(dir, '.env'), envText);
  return dir;
}

function harness(over = {}) {
  const log = { notes: [], asked: [], composeCalls: [], steps: [] };
  const ui = {
    note: (t, b) => log.notes.push(`${t}\n${b}`),
    select: async () => over.selectAnswer ?? 'retry',
    confirm: async () => true,
    text: async () => '',
    spinner: () => ({ start() {}, stop() {}, message() {} }),
  };
  const docker = { run: async () => ({ code: 0, stdout: '', stderr: '' }), compose: async (a) => { log.composeCalls.push(a); return { code: 0, stdout: '', stderr: '' }; } };
  const reviews = over.reviews ?? ['confirm'];
  const steps = {
    freeBytes: async () => over.free ?? 50e9,
    askAnswers: async (_u, o) => { log.asked.push(o); return over.answers ?? answers(); },
    reviewAnswers: async () => reviews.shift() ?? 'confirm',
    isPortFree: async () => true,
    generateSharedSecrets: () => log.steps.push('secrets'),
    buildImages: async () => { log.steps.push('build'); log.stateAtBuild = parseEnv(readFileSync(join(log.dir, '.env'), 'utf8')).get('OPBX_INSTALL_STATE'); },
    composerInstall: async () => log.steps.push('composer'),
    startStack: async () => log.steps.push('start'),
    createOwner: async (_d, _a, pw) => { log.pw = pw; log.steps.push('owner'); return over.owner ?? 'created'; },
    resetSafelineAdmin: async () => { log.steps.push('wafadmin'); return ({ username: 'admin', password: 'WafPw' }); },
    runChecks: async () => { log.steps.push('checks'); return over.checks ?? [{ name: 'up', ok: true, detail: 'ok' }]; },
    ...over.steps,
  };
  return { ui, docker, steps, log };
}
const run = (h, dir, o = {}) => (h.log.dir = dir, runInstall({ ui: h.ui, docker: h.docker, repoDir: dir, platform: { arch: 'amd64' }, resume: false, steps: h.steps, ...o }));

test('fresh install writes a complete .env; owner password only in the summary', async () => {
  const dir = setup();
  const h = harness();
  await run(h, dir);
  const envText = readFileSync(join(dir, '.env'), 'utf8');
  const env = parseEnv(envText);
  assert.equal(env.get('OPBX_INSTALL_STATE'), 'complete');
  assert.equal(env.get('OPBX_INSTALLER_MANAGED'), 'true');
  // www-data (gid 82) in the app container reads the bind-mounted .env
  assert.equal(statSync(join(dir, '.env')).mode & 0o777, 0o640);
  if (process.getuid() === 0) assert.equal(statSync(join(dir, '.env')).gid, 82);
  for (const k of ['APP_KEY', 'DB_PASSWORD', 'DB_ROOT_PASSWORD', 'REDIS_PASSWORD']) assert.ok(env.get(k), k);
  assert.ok(existsSync(join(dir, 'docker-compose.yml')));
  assert.deepEqual(h.log.steps, ['secrets', 'build', 'composer', 'start', 'owner', 'checks']);
  assert.equal(h.log.stateAtBuild, 'incomplete');
  assert.ok(h.log.pw);
  assert.ok(!envText.includes(h.log.pw));
  assert.ok(readFileSync(join(dir, 'opbx-install-summary.txt'), 'utf8').includes(h.log.pw));
  assert.ok(h.log.notes.some((n) => n.includes(h.log.pw)));
  assert.equal(typeof h.log.asked[0].portCheck, 'function');
});

test('failed check stops before the summary and leaves state incomplete', async () => {
  const dir = setup();
  const h = harness({ checks: [{ name: 'ws', ok: false, detail: 'down' }], selectAnswer: 'abort' });
  await assert.rejects(run(h, dir), /Checks failed.*\n.*run \.\/install\.sh again|Checks failed[\s\S]*resume/i);
  const saved = readFileSync(join(dir, 'opbx-install-summary.txt'), 'utf8');
  assert.ok(saved.includes(h.log.pw));
  assert.equal(h.log.notes.filter((n) => n.includes(h.log.pw)).length, 2); // shown after creation and again on failure
  assert.equal(parseEnv(readFileSync(join(dir, '.env'), 'utf8')).get('OPBX_INSTALL_STATE'), 'incomplete');
  assert.ok(!saved.includes('installation summary'));
});

test('resume keeps DB_PASSWORD, skips port check, tolerates existing owner', async () => {
  const dir = setup('APP_ENV=production\nAPP_URL=https://pbx.example.com\nDB_PASSWORD=keepme\nOPBX_INSTALL_STATE=incomplete\nPUSHER_APP_SECRET=sharedkeep\n');
  const h = harness({ owner: 'exists' });
  await run(h, dir, { resume: true });
  assert.ok(readFileSync(join(dir, 'opbx-install-summary.txt'), 'utf8').includes('user:reset-password ann@example.com'));
  const env = parseEnv(readFileSync(join(dir, '.env'), 'utf8'));
  assert.equal(env.get('DB_PASSWORD'), 'keepme');
  assert.equal(env.get('PUSHER_APP_SECRET'), 'sharedkeep');
  assert.ok(env.get('DB_ROOT_PASSWORD'));
  assert.equal(h.log.asked[0].portCheck, null);
  assert.equal(h.log.asked[0].defaults.publicUrl, 'https://pbx.example.com');
  assert.equal(parseEnv(readFileSync(join(dir, '.env'), 'utf8')).get('OPBX_OWNER_EMAIL'), 'ann@example.com');
  assert.ok(readFileSync(join(dir, 'opbx-install-summary.txt'), 'utf8').includes('Owner account already existed; password unchanged'));
});

test('existing owner on a fresh install is an error', async () => {
  const dir = setup();
  const h = harness({ owner: 'exists' });
  await assert.rejects(run(h, dir), /already exists/);
  assert.equal(parseEnv(readFileSync(join(dir, '.env'), 'utf8')).get('OPBX_INSTALL_STATE'), 'incomplete');
  assert.ok(!existsSync(join(dir, 'opbx-install-summary.txt')));
});

test('edit loops back with previous answers as defaults', async () => {
  const h = harness({ reviews: ['edit', 'confirm'] });
  await run(h, setup());
  assert.equal(h.log.asked.length, 2);
  assert.equal(h.log.asked[1].defaults.orgName, 'Acme');
});

test('low disk space stops before asking anything', async () => {
  const h = harness({ free: 1e9 });
  await assert.rejects(run(h, setup()), /free disk/);
  assert.equal(h.log.asked.length, 0);
});

test('email provider triggers the test email, failure does not block', async () => {
  const dir = setup();
  const h = harness({ answers: answers({ email: { provider: 'mailgun', fields: {}, fromAddress: 'a@example.com', fromName: 'A' } }) });
  h.docker.compose = async (a) => { h.log.composeCalls.push(a); return { code: 1, stdout: '', stderr: 'boom' }; };
  await run(h, dir);
  assert.ok(h.log.composeCalls.some((a) => a.includes('email:send-test') && a.includes('--to=ann@example.com')));
  assert.equal(parseEnv(readFileSync(join(dir, '.env'), 'utf8')).get('OPBX_INSTALL_STATE'), 'complete');
});

test('WAF runs the host step and writes the SafeLine env', async () => {
  const dir = setup();
  const waf = { mode: 'monitor', stateDir: '/var/lib/safeline', consoleAllow: '' };
  const h = harness({ answers: answers({ waf }), steps: { hostStep: async (_u, o) => h.log.steps.push(`host:${o.commands.length > 0}`) } });
  await run(h, dir);
  assert.deepEqual(h.log.steps.slice(0, 3), ['secrets', 'host:true', 'build']);
  assert.ok(h.log.steps.indexOf('wafadmin') > h.log.steps.indexOf('owner') && h.log.steps.indexOf('wafadmin') < h.log.steps.indexOf('checks'));
  assert.ok(existsSync(join(dir, 'docker/safeline/.env')));
  assert.ok(readFileSync(join(dir, 'opbx-install-summary.txt'), 'utf8').includes('WafPw'));
});

import { handleSigint, state as uiState } from '../src/ui.js';
test('handleSigint prints the config-aware message and exits 130', () => {
  for (const [written, re] of [[false, /Nothing was changed/], [true, /run \.\/install\.sh again/]]) {
    uiState.configWritten = written;
    let msg; let code;
    handleSigint({ out: (m) => { msg = m; }, exit: (c) => { code = c; } });
    assert.match(msg, re);
    assert.equal(code, 130);
  }
});

test('resume pre-fills the owner email saved by the first run', async () => {
  const dir = setup('APP_ENV=production\nAPP_URL=https://pbx.example.com\nOPBX_OWNER_EMAIL=first@example.com\nOPBX_INSTALL_STATE=incomplete\n');
  const h = harness({ owner: 'exists' });
  await run(h, dir, { resume: true });
  assert.equal(h.log.asked[0].defaults.ownerEmail, 'first@example.com');
});

test('fresh install: a docker-compose.yml that differs from the example is backed up and replaced on yes', async () => {
  const dir = setup();
  writeFileSync(join(dir, 'docker-compose.yml'), 'services: {} # old\n');
  const h = harness();
  await run(h, dir);
  const example = readFileSync(join(dir, 'docker-compose.yml.example'), 'utf8');
  assert.equal(readFileSync(join(dir, 'docker-compose.yml'), 'utf8'), example);
  const bak = readdirSync(dir).filter((f) => f.startsWith('docker-compose.yml.bak-'));
  assert.equal(bak.length, 1);
  assert.equal(readFileSync(join(dir, bak[0]), 'utf8'), 'services: {} # old\n');
  assert.ok(h.log.notes.some((n) => /differs/.test(n)));
});

test('fresh install: declining keeps the old docker-compose.yml; an identical one is not touched; resume does not ask', async () => {
  const dir = setup();
  writeFileSync(join(dir, 'docker-compose.yml'), 'services: {} # old\n');
  const h = harness();
  h.ui.confirm = async () => false;
  await run(h, dir);
  assert.equal(readFileSync(join(dir, 'docker-compose.yml'), 'utf8'), 'services: {} # old\n');
  assert.ok(!readdirSync(dir).some((f) => f.includes('.bak-')));
  const same = setup();
  copyFileSync(join(same, 'docker-compose.yml.example'), join(same, 'docker-compose.yml'));
  const h2 = harness();
  await run(h2, same);
  assert.ok(!h2.log.notes.some((n) => /differs/.test(n)));
  const res = setup('APP_ENV=production\nAPP_URL=https://pbx.example.com\nOPBX_INSTALL_STATE=incomplete\n');
  writeFileSync(join(res, 'docker-compose.yml'), 'services: {} # old\n');
  const h3 = harness({ owner: 'exists' });
  await run(h3, res, { resume: true });
  assert.ok(!h3.log.notes.some((n) => /differs/.test(n)));
});
