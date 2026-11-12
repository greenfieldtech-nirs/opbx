import { existsSync, readFileSync, rmSync, mkdirSync, writeFileSync, chownSync } from 'node:fs';
import { join } from 'node:path';
import { gzipSync } from 'node:zlib';
import { parseEnv } from '../envfile.js';
import { answersFromEnv } from '../derive.js';
import { isSafeAbsolutePath } from '../validate.js';
import { detectInstall } from '../detect.js';
import { hostStep } from '../hoststep.js';
import { runInstall } from './install.js';

const DATA_DIRS = ['mysql', 'redis', 'seaweedfs', 'amd-dumps'];
const CONFIG_FILES = ['.env', 'docker-compose.yml', 'docker/safeline/.env', 'opbx-install-summary.txt'];

export async function confirmPhrase(ui, expected) {
  for (let i = 0; i < 3; i++) {
    const v = await ui.text({ message: `Type "${expected}" to confirm (case-sensitive)` });
    if (v === expected) return true;
  }
  return false;
}

async function backup(docker, repoDir, dbName) {
  // The password stays inside the container (MYSQL_ROOT_PASSWORD), never on a command line.
  const r = await docker.compose(['exec', '-T', 'mysql', 'sh', '-c',
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -u root --single-transaction --routines --triggers --databases "$1"', 'sh', dbName]);
  if (r.code !== 0 || !r.stdout.includes('CREATE TABLE')) throw new Error(`Backup failed, nothing was changed:\n${(r.stderr || '').trim()}`);
  const dir = join(repoDir, 'backups');
  mkdirSync(dir, { recursive: true });
  const file = join(dir, `opbx-backup-${new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14)}.sql.gz`);
  writeFileSync(file, gzipSync(r.stdout), { mode: 0o600 });
  const { HOST_UID, HOST_GID } = process.env;
  if (HOST_UID && HOST_GID) { chownSync(dir, Number(HOST_UID), Number(HOST_GID)); chownSync(file, Number(HOST_UID), Number(HOST_GID)); }
  return file;
}

// `steps` replaces collaborators in unit tests.
export async function runReset({ ui, docker, repoDir, platform, steps = {} }) {
  const S = { hostStep, runInstall, ...steps };
  if (detectInstall(repoDir) === 'none') throw new Error('Nothing to reset: no installation found here.');

  const readEnv = (p) => (existsSync(p) ? parseEnv(readFileSync(p, 'utf8')) : new Map());
  const env = readEnv(join(repoDir, '.env'));
  const safelineEnv = readEnv(join(repoDir, 'docker/safeline/.env'));
  const hasSafeline = existsSync(join(repoDir, 'docker/safeline/.env'));
  const stateDir = safelineEnv.get('SAFELINE_DIR');
  if (hasSafeline && !isSafeAbsolutePath(stateDir)) throw new Error(`Unsafe or missing SAFELINE_DIR in docker/safeline/.env: ${stateDir}`);
  const previousAnswers = answersFromEnv(env, safelineEnv, { includeSecrets: false });

  const cfg = await docker.compose(['config', '--format', 'json']);
  let project;
  try { project = JSON.parse(cfg.stdout).name; } catch { /* handled below */ }
  if (cfg.code !== 0 || !/^[a-z0-9][a-z0-9_-]*$/.test(project ?? '')) throw new Error(`Cannot determine the compose project name, nothing was changed:\n${(cfg.stderr || '').trim()}`);

  const production = env.get('APP_ENV') === 'production';
  let expected = 'RESET';
  if (production) {
    try { expected = new URL(env.get('APP_URL')).hostname; } catch { throw new Error('APP_URL in .env is not a valid URL, cannot build the confirmation phrase.'); }
  }

  ui.note('This will permanently destroy', [
    '- all OPBX containers (every profile) and their anonymous volumes',
    ...DATA_DIRS.map((d) => `- ${repoDir}/volumes/${d}`),
    `- Docker volumes ${project}_loki_data and ${project}_grafana_data`,
    ...(hasSafeline ? ['- the SafeLine firewall project', `- the SafeLine data folder ${stateDir} (you will be asked to remove it)`] : []),
    '- .env, docker-compose.yml, docker/safeline/.env, opbx-install-summary.txt',
  ].join('\n'));

  const ps = await docker.compose(['ps', '-q', 'mysql']);
  if (ps.code === 0 && ps.stdout.trim()) {
    if (await ui.confirm({ message: 'Back up the database to ./backups/ first?', initialValue: true })) {
      ui.note('Backup', `Saved ${await backup(docker, repoDir, env.get('DB_DATABASE') || 'opbx')}`);
    }
  }

  if (!(await confirmPhrase(ui, expected))) throw new Error('Confirmation failed three times. Nothing was changed.');

  // ---- destructive from here on ----
  await docker.compose(['--profile', '*', 'down', '--remove-orphans', '-v']);
  if (hasSafeline) await docker.compose(['--project-directory', 'docker/safeline', '-p', 'safeline', 'down']);
  await docker.run(['volume', 'rm', '-f', `${project}_loki_data`, `${project}_grafana_data`]);
  await docker.run(['run', '--rm', '--mount', `type=bind,src=${repoDir}/volumes,dst=/v`, 'alpine', 'sh', '-c',
    `rm -rf ${DATA_DIRS.map((d) => `/v/${d}`).join(' ')}`]);

  if (hasSafeline) {
    await S.hostStep(ui, {
      title: 'Remove the firewall data folder',
      explain: 'Run this on this machine (it needs administrator rights), then come back.',
      commands: [...(platform?.podmanMachine ? ['# Open a shell in the Podman VM first with "podman machine ssh", then run this there:'] : []), `sudo rm -rf ${stateDir}`],
      // --mount fails on a missing source: failing to mount means it is gone.
      check: async () => {
        const r = await docker.run(['run', '--rm', '--mount', `type=bind,src=${stateDir},dst=/s`, 'alpine', 'true']);
        return r.code !== 0 ? { ok: true, missing: [] } : { ok: false, missing: [`${stateDir} still exists`] };
      },
    });
  }

  for (const f of CONFIG_FILES) rmSync(join(repoDir, f), { force: true });

  const reuse = await ui.confirm({ message: 'Reuse my previous answers?', initialValue: true });
  await S.runInstall({ ui, docker, repoDir, platform, resume: false, defaults: reuse ? previousAnswers : undefined });
}
