import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { buildImages, composerInstall, startStack, createOwner, resetSafelineAdmin } from '../src/stack.js';

const ok = (stdout = '') => ({ code: 0, stdout, stderr: '' });
// fake docker: handler(kind, args, opts) -> result; records calls
function fake(handler) {
  const calls = [];
  const rec = (kind) => async (args, opts = {}) => { calls.push({ kind, args, opts }); return handler(kind, args, opts, calls); };
  return { run: rec('run'), compose: rec('compose'), calls };
}
const fakeUi = (choice = 'abort') => {
  const ui = { notes: [], selects: 0, spinner: () => ({ start() {}, message() {}, stop() {} }), note: (t, b) => ui.notes.push([t, b]), select: async () => { ui.selects++; return choice; } };
  return ui;
};

test('buildImages: fails twice then succeeds -> 3 builds', async () => {
  let n = 0;
  const d = fake(() => (++n < 3 ? { code: 1, stdout: '', stderr: 'boom' } : ok()));
  await buildImages(d, fakeUi());
  assert.equal(d.calls.filter((c) => c.args[0] === 'build').length, 3);
});

test('buildImages: 3 failures show last 40 lines and ask; abort rejects', async () => {
  const lines = Array.from({ length: 60 }, (_, i) => `line${i}`).join('\n');
  const d = fake(() => ({ code: 1, stdout: '', stderr: lines }));
  const ui = fakeUi('abort');
  await assert.rejects(buildImages(d, ui), /abort/i);
  assert.equal(d.calls.length, 3);
  assert.equal(ui.selects, 1);
  assert.ok(ui.notes[0][1].includes('line59') && !ui.notes[0][1].includes('line19\n'));
});

test('buildImages: retry choice builds again', async () => {
  let n = 0;
  const d = fake(() => (++n <= 3 ? { code: 1, stdout: '', stderr: 'x' } : ok()));
  await buildImages(d, fakeUi('retry'));
  assert.equal(n, 4);
});

test('composerInstall: production uses --no-dev, runs as host user', async () => {
  const d = fake(() => ok());
  process.env.HOST_UID = '1000'; process.env.HOST_GID = '1001';
  await composerInstall(d, { mode: 'production' });
  const a = d.calls[0].args;
  assert.deepEqual(a.slice(0, 4), ['run', '--rm', '--no-deps', '--user']);
  assert.equal(a[4], '1000:1001');
  assert.ok(a.includes('--no-dev') && a.includes('COMPOSER_HOME=/tmp/composer'));
  assert.equal(a[a.indexOf('--entrypoint') + 1], 'composer');
  await composerInstall(d, { mode: 'development' });
  assert.ok(!d.calls[1].args.includes('--no-dev'));
  delete process.env.HOST_UID; delete process.env.HOST_GID;
  await composerInstall(d, { mode: 'production' });
  assert.ok(!d.calls[2].args.includes('--user'));
});

test('composerInstall: non-zero throws with output', async () => {
  const d = fake(() => ({ code: 2, stdout: '', stderr: 'no network' }));
  await assert.rejects(composerInstall(d, { mode: 'production' }), /no network/);
});

const healthDocker = (statuses) => {
  let i = 0;
  return fake((kind, args) => {
    if (args[0] === 'inspect') return ok(statuses[Math.min(i++, statuses.length - 1)]);
    if (args[0] === 'logs') return ok('last log line');
    return ok();
  });
};
const clock = () => { let t = 0; return { now: () => t, sleep: async (ms) => { t += ms; } }; };

test('startStack: polls until healthy, no WAF calls without WAF', async () => {
  const d = healthDocker(['starting', 'starting', 'healthy']);
  await startStack(d, fakeUi(), { waf: null }, clock());
  assert.ok(d.calls.some((c) => c.args[0] === 'up'));
  assert.ok(!d.calls.some((c) => c.args.includes('safeline')));
});

test('startStack: never healthy rejects after the timeout', async () => {
  const d = healthDocker(['starting']);
  await assert.rejects(startStack(d, fakeUi(), { waf: null }, { ...clock(), timeoutMs: 60_000 }), /healthy/);
});

test('startStack: WAF starts safeline first, waits for detector, checks it', async () => {
  const d = fake((kind, args) => {
    if (args[0] === 'inspect') return ok('healthy');
    if (args.includes('nc')) return ok(); // checkDetector
    return ok('x');
  });
  await startStack(d, fakeUi(), { waf: { mode: 'monitor' } }, clock());
  const first = d.calls[0].args;
  assert.deepEqual(first.slice(0, 5), ['--project-directory', 'docker/safeline', '-p', 'safeline', 'up']);
  const idxDetector = d.calls.findIndex((c) => c.args.includes('nc'));
  const idxUp = d.calls.findIndex((c) => c.args[0] === 'up');
  assert.ok(idxDetector > 0 && idxUp > idxDetector);
});

test('startStack: WAF with unreachable detector throws', async () => {
  const d = fake((kind, args) => (args.includes('nc') ? { code: 1, stdout: '', stderr: '' } : ok('healthy')));
  await assert.rejects(startStack(d, fakeUi(), { waf: { mode: 'block' } }, clock()), /detector/i);
});

const A = { orgName: 'Acme', ownerName: 'Ann Owner', ownerEmail: 'ann@example.com' };

test('createOwner: password only via input', async () => {
  const d = fake(() => ok());
  assert.equal(await createOwner(d, A, 's3cret-Pw'), 'created');
  const c = d.calls[0];
  assert.equal(c.opts.input, 's3cret-Pw\n');
  assert.ok(!JSON.stringify(c.args).includes('s3cret-Pw'));
  assert.ok(c.args.includes('--org-name=Acme') && c.args.includes('--email=ann@example.com') && c.args.includes('--name=Ann Owner'));
});

test('createOwner: exit 2 -> exists; exit 1 throws without the password', async () => {
  assert.equal(await createOwner(fake(() => ({ code: 2, stdout: '', stderr: 'exists' })), A, 'pw'), 'exists');
  await assert.rejects(createOwner(fake(() => ({ code: 1, stdout: '', stderr: 'bad email' })), A, 'pw-secret'), (e) => /bad email/.test(e.message) && !e.message.includes('pw-secret'));
});

test('resetSafelineAdmin parses the fixture', async () => {
  const text = readFileSync(new URL('./fixtures/safeline-reset-admin.txt', import.meta.url), 'utf8');
  const d = fake(() => ok(text));
  assert.deepEqual(await resetSafelineAdmin(d), { username: 'admin', password: 'REDACTED-PASSWORD-1234' });
  assert.deepEqual(d.calls[0].args, ['exec', 'safeline-mgt', '/app/mgt', 'reset-admin']);
});

test('resetSafelineAdmin: ASCII colon, ANSI and stderr output also parse; garbage throws', async () => {
  const d = fake(() => ({ code: 0, stdout: '', stderr: '\x1b[32m[SafeLine] Initial username: admin\x1b[0m\n[SafeLine] Initial password: abc123XYZ\n' }));
  assert.deepEqual(await resetSafelineAdmin(d), { username: 'admin', password: 'abc123XYZ' });
  await assert.rejects(resetSafelineAdmin(fake(() => ok('nothing'))), /parse/i);
});
