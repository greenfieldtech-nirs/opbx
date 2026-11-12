// Reads either Docker-format `docker info` JSON (what the installer container sees, also via
// Podman's compat API) or Podman-native JSON (`host` key).
export async function detectPlatform(docker) {
  const r = await docker.run(['info', '--format', 'json']);
  if (r.code !== 0) throw new Error(`docker info failed: ${r.stderr.trim()}`);
  const info = JSON.parse(r.stdout);
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

// shortcut: only sees ports bound inside the daemon's network namespace; a host process outside the
// VM may go undetected on Podman machine / Docker Desktop.
export async function isPortFree(docker, port, host) {
  const r = await docker.run(['run', '--rm', '-p', `${host ? `${host}:` : ''}${port}:80`, 'alpine', 'true']);
  if (r.code === 0) return true;
  if (/address already in use|port is already allocated/i.test(r.stderr)) return false;
  throw new Error(`could not probe port ${port}: ${r.stderr.trim() || `exit ${r.code}`}`);
}
