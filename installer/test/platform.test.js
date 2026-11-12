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

test('isPortFree follows exit code', async () => {
  assert.equal(await isPortFree(fake({}, 0), 80), true);
  assert.equal(await isPortFree(fake({}, 126), 80), false);
});
