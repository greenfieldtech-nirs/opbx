import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, statSync, existsSync, copyFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { safelineHostCommands, checkSafelineState, writeSafelineEnv, checkDetector } from '../src/safeline.js';

const FIXTURE = new URL('./fixtures/safeline.env.example', import.meta.url);
const REAL = new URL('../../docker/safeline/.env.example', import.meta.url);
const plain = { selinux: false, podmanMachine: false, arch: 'amd64' };

test('host commands: mkdir, detector.yml, chcon only on SELinux', () => {
  const a = safelineHostCommands(plain, '/var/lib/safeline');
  assert.ok(a[0].startsWith('sudo mkdir -p /var/lib/safeline/resources/{postgres/data,mgt,sock,detector,nginx,chaos,cache,luigi,run} /var/lib/safeline/logs/{nginx,detector}'));
  assert.ok(a.some((c) => c.includes('sudo tee /var/lib/safeline/resources/detector/detector.yml')));
  assert.ok(!a.some((c) => c.includes('chcon')));
  const b = safelineHostCommands({ ...plain, selinux: true }, '/s');
  assert.ok(b.includes('sudo chcon -R -t container_file_t /s'));
});

test('host commands: podman machine prefix line', () => {
  const c = safelineHostCommands({ ...plain, podmanMachine: true }, '/s');
  assert.match(c[0], /podman machine ssh/);
});

const fakeDocker = (r) => { const calls = []; return { calls, run: async (a) => { calls.push(a); return r; } }; };

test('checkSafelineState names a missing detector.yml, uses --mount not -v', async () => {
  const d = fakeDocker({ code: 0, stdout: 'MISSING resources/detector/detector.yml\n', stderr: '' });
  const r = await checkSafelineState(d, '/s');
  assert.equal(r.ok, false);
  assert.deepEqual(r.missing, ['resources/detector/detector.yml']);
  assert.ok(d.calls[0].includes('--mount') && !d.calls[0].includes('-v'));
  assert.ok(!d.calls[0].join(' ').includes('label=disable'));
});

test('checkSafelineState ok; mount failure reports the directory', async () => {
  assert.deepEqual(await checkSafelineState(fakeDocker({ code: 0, stdout: '', stderr: '' }), '/s'), { ok: true, missing: [] });
  const r = await checkSafelineState(fakeDocker({ code: 125, stdout: '', stderr: 'no such file' }), '/s');
  assert.equal(r.ok, false);
  assert.match(r.missing[0], /\/s/);
});

test('checkDetector true/false', async () => {
  assert.equal(await checkDetector(fakeDocker({ code: 0, stdout: '', stderr: '' })), true);
  assert.equal(await checkDetector(fakeDocker({ code: 1, stdout: '', stderr: '' })), false);
  assert.equal(await checkDetector({ run: async () => { throw new Error('x'); } }), false);
});

test('writeSafelineEnv: arch images, keeps existing secrets, mode 600', () => {
  const repo = mkdtempSync(join(tmpdir(), 'sl-'));
  mkdirSync(join(repo, 'docker/safeline'), { recursive: true });
  copyFileSync(FIXTURE, join(repo, 'docker/safeline/.env.example'));
  const answers = { waf: { stateDir: '/var/lib/safeline' } };
  const secrets = { POSTGRES_PASSWORD: 'p1', MGT_INTERNAL_TOKEN: 'm1', DETECTOR_MGMT_TOKEN: 'd1', DETECTOR_AES_KEY: 'k1' };
  const envPath = join(repo, 'docker/safeline/.env');
  writeSafelineEnv(repo, answers, secrets, { arch: 'amd64' });
  let t = readFileSync(envPath, 'utf8');
  assert.ok(!t.includes('# Added by installer'));
  assert.match(t, /^ARCH_SUFFIX=$/m);
  assert.match(t, /^POSTGRES_IMAGE=chaitin\/safeline-postgres:15.18$/m);
  assert.match(t, /^SAFELINE_DIR=\/var\/lib\/safeline$/m);
  assert.equal(statSync(envPath).mode & 0o777, 0o600);
  writeSafelineEnv(repo, answers, { POSTGRES_PASSWORD: 'NEW', MGT_INTERNAL_TOKEN: 'NEW', DETECTOR_MGMT_TOKEN: 'NEW', DETECTOR_AES_KEY: 'NEW' }, { arch: 'arm64' });
  t = readFileSync(envPath, 'utf8');
  assert.match(t, /^POSTGRES_PASSWORD=p1$/m);
  assert.match(t, /^ARCH_SUFFIX=-arm$/m);
  assert.match(t, /^POSTGRES_IMAGE=chaitin\/safeline-postgres-arm:15.18$/m);
});

test('fixture is a verbatim copy of docker/safeline/.env.example', (t) => {
  if (!existsSync(REAL)) return t.skip('repo not mounted');
  assert.equal(readFileSync(FIXTURE, 'utf8'), readFileSync(REAL, 'utf8'));
});

test('unsafe state dirs are refused everywhere', async () => {
  const bad = '/a;rm -rf /';
  assert.throws(() => safelineHostCommands(plain, bad));
  await assert.rejects(() => checkSafelineState(fakeDocker({ code: 0, stdout: '', stderr: '' }), bad));
  assert.throws(() => writeSafelineEnv('/nonexistent', { waf: { stateDir: bad } }, {}, plain));
});
