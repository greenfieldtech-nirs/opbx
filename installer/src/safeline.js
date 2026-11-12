import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { parseEnv, renderEnv, writeFileAtomic } from './envfile.js';
import { generateSafelineSecrets } from './secrets.js';

const DIRS = ['postgres/data', 'mgt', 'sock', 'detector', 'nginx', 'chaos', 'cache', 'luigi', 'run'].map((d) => `resources/${d}`)
  .concat(['logs/nginx', 'logs/detector']);

export function safelineHostCommands(platform, stateDir) {
  const cmds = [
    `sudo mkdir -p ${stateDir}/resources/{postgres/data,mgt,sock,detector,nginx,chaos,cache,luigi,run} ${stateDir}/logs/{nginx,detector}`,
    `printf 'bind_addr: 0.0.0.0\\nlisten_port: 8000\\n' | sudo tee ${stateDir}/resources/detector/detector.yml`,
  ];
  if (platform.selinux) cmds.push(`sudo chcon -R -t container_file_t ${stateDir}`);
  if (platform.podmanMachine) cmds.unshift('# Open a shell in the Podman VM first with "podman machine ssh", then run these there:');
  return cmds;
}

// --mount (not -v) fails on a missing source instead of creating it; no label opt-out, so SELinux problems show up.
export async function checkSafelineState(docker, stateDir) {
  const script = [
    ...DIRS.map((d) => `[ -d /s/${d} ] || echo "MISSING ${d}"`),
    `[ "$(cat /s/resources/detector/detector.yml 2>/dev/null)" = "$(printf 'bind_addr: 0.0.0.0\\nlisten_port: 8000')" ] || echo "MISSING resources/detector/detector.yml (absent or wrong content)"`,
    `touch /s/resources/run/.probe 2>/dev/null && rm /s/resources/run/.probe || echo "MISSING write access to resources/run (check ownership / SELinux label)"`,
  ].join('\n');
  const r = await docker.run(['run', '--rm', '--mount', `type=bind,src=${stateDir},dst=/s`, 'alpine', 'sh', '-c', script]);
  const missing = r.stdout.split('\n').filter((l) => l.startsWith('MISSING ')).map((l) => l.slice(8));
  if (r.code !== 0 && !missing.length) missing.push(`${stateDir} (cannot be mounted: does it exist?) ${r.stderr.trim()}`.trim());
  return { ok: missing.length === 0, missing };
}

const POSTGRES = { arm64: 'chaitin/safeline-postgres-arm:15.18', amd64: 'chaitin/safeline-postgres:15.18' };

export function writeSafelineEnv(repoDir, answers, secrets, platform) {
  const dir = join(repoDir, 'docker/safeline');
  const target = join(dir, '.env');
  const existing = existsSync(target) ? parseEnv(readFileSync(target, 'utf8')) : new Map();
  const merged = { ...generateSafelineSecrets(), ...secrets };
  for (const k of Object.keys(merged)) if (existing.get(k)) merged[k] = existing.get(k);
  const arm = platform.arch === 'arm64';
  const text = renderEnv(readFileSync(join(dir, '.env.example'), 'utf8'), {
    ...merged,
    SAFELINE_DIR: answers.waf.stateDir,
    ARCH_SUFFIX: arm ? '-arm' : '',
    POSTGRES_IMAGE: POSTGRES[arm ? 'arm64' : 'amd64'],
  });
  const { HOST_UID, HOST_GID } = process.env;
  writeFileAtomic(target, text, { mode: 0o600, ...(HOST_UID && HOST_GID ? { uid: Number(HOST_UID), gid: Number(HOST_GID) } : {}) });
}

export async function checkDetector(docker) {
  try {
    const r = await docker.run(['run', '--rm', '--network', 'safeline-ce', 'alpine', 'nc', '-z', '-w', '3', 'safeline-detector', '8000']);
    return r.code === 0;
  } catch { return false; }
}
