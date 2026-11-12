// Reads either Docker-format `docker info` JSON (what the installer container sees, also via
// Podman's compat API) or Podman-native JSON (`host` key).
export async function detectPlatform(docker) {
  const { stdout } = await docker.run(['info', '--format', 'json']);
  const info = JSON.parse(stdout);
  const host = info.host;
  const os = (host ? host.os : info.OSType) || 'linux';
  const archRaw = (host ? host.arch : info.Architecture) || '';
  const runtime = host || /containers\/storage/.test(info.DockerRootDir || '') ? 'podman' : 'docker';
  const selinux = host
    ? Boolean(host.security?.selinuxEnabled)
    : (info.SecurityOptions || []).some((o) => o.includes('selinux'));
  // The installer runs in a Linux container, so the wrapper passes the real client OS.
  const clientOs = process.env.OPBX_CLIENT_OS || info.ClientInfo?.Os || os;
  return {
    runtime,
    podmanMachine: runtime === 'podman' && clientOs !== os,
    selinux,
    os,
    arch: /^(aarch64|arm64)$/.test(archRaw) ? 'arm64' : 'amd64',
  };
}

export async function isPortFree(docker, port) {
  const r = await docker.run(['run', '--rm', '-p', `${port}:80`, 'alpine', 'true']);
  return r.code === 0;
}
