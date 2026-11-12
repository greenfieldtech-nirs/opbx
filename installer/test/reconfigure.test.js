import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, copyFileSync, readFileSync, writeFileSync, existsSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import { runReconfigure } from '../src/modes/reconfigure.js';
import { state as uiState, cancelMessage } from '../src/ui.js';
import { resolveAction } from '../src/main.js';
import { parseEnv, renderEnv } from '../src/envfile.js';
import { deriveEnv, OWNED_KEYS } from '../src/derive.js';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
const base = (o = {}) => ({
  mode: 'production', publicUrl: 'https://pbx.example.com', httpPort: 80, trustedProxyCidr: '10.0.0.5', orgName: 'Acme', ownerName: 'Ann',
  ownerEmail: 'ann@example.com', tenancy: 'single', email: { provider: 'none', fields: {}, fromAddress: '', fromName: '' },
  auth0: null, recaptcha: null, usercheck: null, logs: false, waf: null, ngrokToken: '', advanced: {}, ...o,
});
const SECRETS = { APP_KEY: 'base64:SECRETKEY', DB_PASSWORD: 'dbpw', DB_ROOT_PASSWORD: 'rootpw', REDIS_PASSWORD: 'redispw' };

function setup(answers = base(), state = 'complete') {
  const dir = mkdtempSync(join(tmpdir(), 'opbx-reconf-'));
  mkdirSync(join(dir, 'docker/safeline'), { recursive: true });
  copyFileSync(join(ROOT, 'docker/safeline/.env.example'), join(dir, 'docker/safeline/.env.example'));
  const text = renderEnv(readFileSync(join(ROOT, '.env.example'), 'utf8'), { ...deriveEnv(answers), ...SECRETS, OPBX_INSTALL_STATE: state });
  writeFileSync(join(dir, '.env'), text);
  return { dir, text };
}

function harness({ select = [], confirm = [], over = {}, composeCode = () => 0 } = {}) {
  const log = { notes: [], compose: [], run: [], steps: [], cleanup: [] };
  const sel = [...select];
  const conf = [...confirm];
  const ui = {
    note: (t, b) => log.notes.push(`${t}\n${b}`),
    select: async () => sel.shift(),
    confirm: async () => conf.shift() ?? false,
    text: async () => '',
    spinner: () => ({ start() {}, stop() {}, message() {} }),
  };
  const docker = {
    run: async (a) => { log.run.push(a); return { code: 0, stdout: a[0] === 'inspect' ? 'running healthy' : '', stderr: '' }; },
    compose: async (a) => { log.compose.push(a); const code = composeCode(a); return { code, stdout: '', stderr: code ? 'boom' : '' }; },
  };
  const steps = {
    hostStep: async () => log.steps.push('hoststep'),
    checkSafelineState: async () => ({ ok: true, missing: [] }),
    runChecks: async (_d, a) => { log.steps.push('checks'); log.checkedWaf = a.waf; return over.checks ?? [{ name: 'services', ok: true }, { name: 'waf detection', ok: true }]; },
    resetSafelineAdmin: async () => { log.steps.push('wafadmin'); return { username: 'admin', password: 'WafPw' }; },
    isPortFree: async () => over.portFree ?? true,
    askPublicUrl: async () => 'https://new.example.org',
    askWaf: async () => ({ mode: 'monitor', stateDir: '/var/lib/safeline', consoleAllow: '10.1.0.0/24' }),
    ...over.steps,
  };
  return { ui, docker, steps, log };
}
const run = (h, dir, platform = { arch: 'amd64' }) => runReconfigure({ ui: h.ui, docker: h.docker, repoDir: dir, platform, steps: h.steps });
const changed = (before, after) => {
  const a = parseEnv(before); const b = parseEnv(after);
  return [...new Set([...a.keys(), ...b.keys()])].filter((k) => a.get(k) !== b.get(k)).sort();
};
const lines = (t) => t.split('\n');

test('domain change rewrites only domain keys, keeps secrets and every other line', async () => {
  const { dir, text } = setup();
  const h = harness({ select: ['domain'] });
  await run(h, dir);
  const after = readFileSync(join(dir, '.env'), 'utf8');
  const diff = changed(text, after);
  assert.ok(diff.length > 0);
  for (const k of diff) assert.ok(OWNED_KEYS.domain.includes(k), `${k} is not a domain key`);
  assert.equal(parseEnv(after).get('APP_URL'), 'https://new.example.org');
  for (const [k, v] of Object.entries(SECRETS)) assert.equal(parseEnv(after).get(k), v);
  const touched = lines(text).filter((l, i) => l !== lines(after)[i]);
  assert.ok(touched.every((l) => OWNED_KEYS.domain.some((k) => l.startsWith(`${k}=`))), 'only owned lines differ');
  assert.equal(lines(text).length, lines(after).length);
  assert.equal(statSync(join(dir, '.env')).mode & 0o777, 0o640);
  if (process.getuid() === 0) assert.equal(statSync(join(dir, '.env')).gid, 82);
  assert.deepEqual(h.log.compose, [['build', 'frontend'], ['up', '-d'], ['restart', 'queue-worker', 'queue-worker-long']]);
  assert.deepEqual(h.log.steps, ['checks']);
  const out = h.log.notes.join('\n');
  assert.match(out, /new\.example\.org/);
  assert.match(out, /proxy_pass/);
  assert.match(out, /Settings -> Cloudonix/);
});

test('domain change with the WAF on shows both proxy blocks; multi tenancy says the webhook is updated', async () => {
  const { dir } = setup(base({ tenancy: 'multi', waf: { mode: 'monitor', stateDir: '/var/lib/safeline', consoleAllow: '10.1.0.0/24' } }));
  const h = harness({ select: ['domain'] });
  await run(h, dir);
  const out = h.log.notes.join('\n');
  assert.match(out, /listen 9443 ssl/);
  assert.match(out, /already updated/);
  assert.doesNotMatch(out, /Settings -> Cloudonix/);
  assert.equal(parseEnv(readFileSync(join(dir, '.env'), 'utf8')).get('OPBX_APPLICATION_WEBHOOK_BASEURL'), 'https://new.example.org');
});

test('domain change is refused in development', async () => {
  const { dir, text } = setup(base({ mode: 'development', publicUrl: 'http://localhost', trustedProxyCidr: '' }));
  const h = harness({ select: ['domain'] });
  await run(h, dir);
  assert.equal(readFileSync(join(dir, '.env'), 'utf8'), text);
  assert.deepEqual(h.log.compose, []);
  assert.match(h.log.notes.join('\n'), /http:\/\/localhost/);
});

test('refuses without an installation or when it is incomplete', async () => {
  const empty = mkdtempSync(join(tmpdir(), 'opbx-reconf-'));
  const h1 = harness();
  await run(h1, empty);
  assert.deepEqual(h1.log.compose, []);
  assert.match(h1.log.notes.join('\n'), /not.*(installed|complete)/i);
  const { dir } = setup(base(), 'incomplete');
  const h2 = harness();
  await run(h2, dir);
  assert.deepEqual(h2.log.compose, []);
  assert.match(h2.log.notes.join('\n'), /not.*(installed|complete)/i);
});

test('WAF mode change rewrites WAF_MODE only and recreates nginx only', async () => {
  const { dir, text } = setup(base({ waf: { mode: 'monitor', stateDir: '/var/lib/safeline', consoleAllow: '10.1.0.0/24' } }));
  const h = harness({ select: ['wafmode', 'block'] });
  await run(h, dir);
  const after = readFileSync(join(dir, '.env'), 'utf8');
  assert.deepEqual(changed(text, after), ['WAF_MODE']);
  assert.equal(parseEnv(after).get('WAF_MODE'), 'block');
  assert.deepEqual(h.log.compose, [['up', '-d', 'nginx']]);
  assert.deepEqual(h.log.steps, ['checks']);
  assert.equal(h.log.checkedWaf.mode, 'block');
});

test('WAF off removes the overlay, keeps the state dir and secrets, down has no -v', async () => {
  const { dir, text } = setup(base({ waf: { mode: 'monitor', stateDir: '/var/lib/safeline', consoleAllow: '10.1.0.0/24' } }));
  const state = mkdtempSync(join(tmpdir(), 'opbx-state-'));
  writeFileSync(join(state, 'keep'), 'x');
  const h = harness({ select: ['wafoff'], confirm: [true] });
  await run(h, dir);
  const after = readFileSync(join(dir, '.env'), 'utf8');
  const env = parseEnv(after);
  assert.ok(!env.get('COMPOSE_FILE').includes('docker-compose.waf.yml'));
  assert.equal(env.get('COMPOSE_FILE'), parseEnv(text).get('COMPOSE_FILE').replace(':docker-compose.waf.yml', ''));
  assert.equal(env.get('WAF_MODE'), 'off');
  assert.equal(env.get('WAF_CONSOLE_ENABLED'), 'false');
  for (const k of changed(text, after)) assert.ok(OWNED_KEYS.waf.includes(k), k);
  for (const [k, v] of Object.entries(SECRETS)) assert.equal(env.get(k), v);
  assert.deepEqual(h.log.compose, [['up', '-d', 'nginx'], ['--project-directory', 'docker/safeline', '-p', 'safeline', 'down']]);
  assert.ok(!h.log.compose.flat().includes('-v') && !h.log.compose.flat().includes('--volumes'));
  assert.ok(existsSync(join(state, 'keep')));
  const cmds = [...h.log.run, ...h.log.compose].map((a) => a.join(' '));
  assert.ok(!cmds.some((c) => /\brm\b|\bdelete\b|--volumes| -v\b/.test(c)), 'no delete command');
  assert.equal(uiState.reconfigure, 'wafoff');
});

test('WAF off leaves SafeLine running when the user declines', async () => {
  const { dir } = setup(base({ waf: { mode: 'monitor', stateDir: '/var/lib/safeline', consoleAllow: '' } }));
  const h = harness({ select: ['wafoff'], confirm: [false] });
  await run(h, dir);
  assert.deepEqual(h.log.compose, [['up', '-d', 'nginx']]);
});

test('WAF on: host step, SafeLine env + start, rewrite, nginx, checks, admin login', async () => {
  const { dir, text } = setup();
  const h = harness({ select: ['wafon'] });
  await run(h, dir);
  const after = readFileSync(join(dir, '.env'), 'utf8');
  const env = parseEnv(after);
  assert.ok(env.get('COMPOSE_FILE').endsWith(':docker-compose.waf.yml'));
  assert.equal(env.get('WAF_MODE'), 'monitor');
  assert.equal(env.get('WAF_CONSOLE_ENABLED'), 'true');
  assert.equal(env.get('WAF_CONSOLE_ALLOW'), '10.1.0.0/24');
  for (const k of changed(text, after)) assert.ok(OWNED_KEYS.waf.includes(k), k);
  for (const [k, v] of Object.entries(SECRETS)) assert.equal(env.get(k), v);
  const sl = parseEnv(readFileSync(join(dir, 'docker/safeline/.env'), 'utf8'));
  assert.equal(sl.get('SAFELINE_DIR'), '/var/lib/safeline');
  assert.deepEqual(h.log.compose, [['-f', 'docker-compose.yml', '-f', 'docker-compose.prod.yml', '-f', 'docker-compose.waf.yml', 'build', 'nginx'], ['--project-directory', 'docker/safeline', '-p', 'safeline', 'up', '-d'], ['up', '-d', 'nginx']]);
  assert.deepEqual(h.log.steps, ['hoststep', 'checks', 'wafadmin']);
  assert.match(h.log.notes.join('\n'), /WafPw/);
  assert.match(h.log.notes.join('\n'), /listen 9443 ssl/);
  uiState.reconfigure = 'wafoff';
  assert.match(cancelMessage(), /already off.*still running.*down/s);
  uiState.reconfigure = null;
});

for (const [name, over, code] of [['port busy', { portFree: false }, () => 0], ['image build fails', {}, (a) => (a.includes('build') ? 1 : 0)]]) {
  test(`WAF on stops before touching .env: ${name}`, async () => {
    const { dir, text } = setup();
    const h = harness({ select: ['wafon'], over, composeCode: code });
    await assert.rejects(run(h, dir), /not changed/);
    assert.equal(readFileSync(join(dir, '.env'), 'utf8'), text);
    assert.ok(!h.log.compose.some((a) => a.includes('safeline')));
  });
}

test('WAF on: SafeLine start failure stops before .env is touched', async () => {
  const { dir, text } = setup();
  const h = harness({ select: ['wafon'], composeCode: (a) => (a.includes('safeline') ? 1 : 0) });
  await assert.rejects(run(h, dir), /SafeLine/);
  assert.equal(readFileSync(join(dir, '.env'), 'utf8'), text);
});

test('a failing nginx recreate stops and says .env was already updated', async () => {
  const { dir } = setup(base({ waf: { mode: 'monitor', stateDir: '/var/lib/safeline', consoleAllow: '' } }));
  const h = harness({ select: ['wafmode', 'block'], composeCode: () => 1 });
  await assert.rejects(run(h, dir), /already updated.*docker compose up -d/s);
  assert.equal(parseEnv(readFileSync(join(dir, '.env'), 'utf8')).get('WAF_MODE'), 'block');
  assert.deepEqual(h.log.steps, []);
});

test('failed checks are reported, not hidden', async () => {
  const { dir } = setup();
  const h = harness({ select: ['domain'], over: { checks: [{ name: 'nginx /up', ok: false, detail: 'refused' }] } });
  await run(h, dir);
  assert.match(h.log.notes.join('\n'), /nginx \/up.*refused/);
});

test('install on a complete installation offers change settings / start over / exit', async () => {
  const asked = [];
  const ui = { select: async (o) => { asked.push(o); return 'reset'; } };
  assert.equal(await resolveAction('offer-reset-or-reconfigure', ui), 'reset');
  assert.match(asked[0].message, /already installed/);
  assert.deepEqual(asked[0].options.map((o) => o.value), ['reconfigure', 'reset', 'exit']);
  assert.equal(await resolveAction('install', ui), 'install');
  assert.equal(await resolveAction('reconfigure', ui), 'reconfigure');
});

test('WAF console port: the chosen port is checked, written and shown; wafoff keeps it for next time', async () => {
  const { dir } = setup();
  const probed = [];
  const h = harness({ select: ['wafon'], over: { steps: {
    askWaf: async (_u, d) => { probed.push(['default', d.waf.consolePort]); return { mode: 'monitor', stateDir: '/var/lib/safeline', consolePort: 9555, consoleAllow: '' }; },
    isPortFree: async (_d, p) => { probed.push(['free', p]); return true; },
  } } });
  await run(h, dir);
  assert.deepEqual(probed, [['default', undefined], ['free', 9555]]);
  assert.equal(parseEnv(readFileSync(join(dir, '.env'), 'utf8')).get('WAF_CONSOLE_PORT'), '9555');
  const out = h.log.notes.join('\n');
  assert.match(out, /pbx\.example\.com:9555/);
  assert.match(out, /listen 9555 ssl/);
  const off = harness({ select: ['wafoff'], confirm: [false] });
  await run(off, dir);
  assert.equal(parseEnv(readFileSync(join(dir, '.env'), 'utf8')).get('WAF_CONSOLE_PORT'), '9555');
  let def;
  const on = harness({ select: ['wafon'], over: { steps: { askWaf: async (_u, d) => { def = d.waf.consolePort; return null; } } } });
  await run(on, dir);
  assert.equal(def, 9555);
});

for (const [name, edit] of [
  ['not installer-managed', (t) => t.replace(/^OPBX_INSTALLER_MANAGED=.*$/m, 'OPBX_INSTALLER_MANAGED=false')],
  ['no COMPOSE_FILE', (t) => t.replace(/^COMPOSE_FILE=.*$/m, 'COMPOSE_FILE=')],
]) {
  test(`refuses a hand-configured installation: ${name}`, async () => {
    const { dir, text } = setup();
    const edited = edit(text);
    assert.notEqual(edited, text);
    writeFileSync(join(dir, '.env'), edited);
    const h = harness({ select: ['wafon'] });
    await run(h, dir);
    assert.equal(readFileSync(join(dir, '.env'), 'utf8'), edited);
    assert.deepEqual(h.log.compose, []);
    assert.deepEqual(h.log.steps, []);
    assert.match(h.log.notes.join('\n'), /not set up by the installer[\s\S]*edit \.env/);
  });
}
