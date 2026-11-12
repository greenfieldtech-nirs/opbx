import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createDocker } from '../src/docker.js';
import { detectPlatform, isPortFree } from '../src/platform.js';

const fake = (info, code = 0) =>
  createDocker({ exec: async () => ({ code, stdout: JSON.stringify(info), stderr: '' }) });

test('Docker Desktop', async () => {
  delete process.env.OPBX_CLIENT_OS;
  const p = await detectPlatform(fake({
    OSType: 'linux', Architecture: 'aarch64', OperatingSystem: 'Docker Desktop',
    DockerRootDir: '/var/lib/docker', SecurityOptions: ['name=seccomp,profile=builtin'],
    ClientInfo: { Os: 'linux' },
  }));
  assert.deepEqual(p, { runtime: 'docker', podmanMachine: false, selinux: false, os: 'linux', arch: 'arm64' });
});

test('Linux Docker with SELinux', async () => {
  delete process.env.OPBX_CLIENT_OS;
  const p = await detectPlatform(fake({
    OSType: 'linux', Architecture: 'x86_64', DockerRootDir: '/var/lib/docker',
    SecurityOptions: ['name=seccomp,profile=builtin', 'name=selinux'], ClientInfo: { Os: 'linux' },
  }));
  assert.deepEqual(p, { runtime: 'docker', podmanMachine: false, selinux: true, os: 'linux', arch: 'amd64' });
});

test('Podman machine (compat info, client OS from wrapper)', async () => {
  process.env.OPBX_CLIENT_OS = 'darwin';
  const p = await detectPlatform(fake({
    OSType: 'linux', Architecture: 'arm64', DockerRootDir: '/var/lib/containers/storage',
    SecurityOptions: ['name=seccomp,profile=default', 'name=selinux'], ClientInfo: { Os: 'linux' },
  }));
  delete process.env.OPBX_CLIENT_OS;
  assert.deepEqual(p, { runtime: 'podman', podmanMachine: true, selinux: true, os: 'linux', arch: 'arm64' });
});

test('Podman-native info JSON', async () => {
  process.env.OPBX_CLIENT_OS = 'darwin';
  const p = await detectPlatform(fake({ host: { os: 'linux', arch: 'amd64', security: { selinuxEnabled: true } } }));
  delete process.env.OPBX_CLIENT_OS;
  assert.deepEqual(p, { runtime: 'podman', podmanMachine: true, selinux: true, os: 'linux', arch: 'amd64' });
});

test('isPortFree: true on exit 0, false on in-use, throws otherwise', async () => {
  const f = (code, stderr) => createDocker({ exec: async () => ({ code, stdout: '', stderr }) });
  assert.equal(await isPortFree(f(0, ''), 80), true);
  assert.equal(await isPortFree(f(126, 'Error: rootlessport listen tcp 0.0.0.0:80: bind: Address already in use'), 80), false);
  assert.equal(await isPortFree(f(125, 'port is already allocated'), 80), false);
  await assert.rejects(isPortFree(f(125, 'pull access denied'), 80), /pull access denied/);
});

test('detectPlatform throws docker info stderr on failure', async () => {
  const d = createDocker({ exec: async () => ({ code: 1, stdout: '', stderr: 'Cannot connect to the daemon' }) });
  await assert.rejects(detectPlatform(d), /Cannot connect to the daemon/);
});
