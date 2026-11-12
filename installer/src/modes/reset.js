import { existsSync, readFileSync, rmSync, mkdirSync, chownSync, createWriteStream, chmodSync } from 'node:fs';
import { join } from 'node:path';
import { createGzip } from 'node:zlib';
import { Transform } from 'node:stream';
import { pipeline } from 'node:stream/promises';
import { parseEnv } from '../envfile.js';
import { answersFromEnv } from '../derive.js';
import { isSafeAbsolutePath } from '../validate.js';
import { detectInstall } from '../detect.js';
import { hostStep } from '../hoststep.js';
import { runInstall } from './install.js';
import { state as uiState } from '../ui.js';

const DATA_DIRS = ['mysql', 'redis', 'seaweedfs', 'amd-dumps'];
const CONFIG_FILES = ['.env', 'docker-compose.yml', 'docker/safeline/.env', 'opbx-install-summary.txt'];

export async function confirmPhrase(ui, expected) {
  for (let i = 0; i < 3; i++) {
    const v = await ui.text({ message: `Type "${expected}" to confirm (case-sensitive)` });
    if (v === expected) return true;
  }
  return false;
}

// Streams mysqldump -> gzip -> file (no in-memory buffering). Validity: exit code 0 AND "CREATE TABLE" seen in the stream.
// The archive DB is included only when it exists (checked inside the container, password never on a command line).
async function backup(docker, repoDir, dbNames) {
  const dir = join(repoDir, 'backups');
  const file = join(dir, `opbx-backup-${new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14)}.sql.gz`);
  try {
    mkdirSync(dir, { recursive: true });
    const dbs = [dbNames[0]];
    const extra = dbNames[1];
    if (extra && /^[A-Za-z0-9_]+$/.test(extra)) {
      const q = await docker.compose(['exec', '-T', 'mysql', 'sh', '-c',
        'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root -N -e "SHOW DATABASES LIKE \'$1\'"', 'sh', extra]);
      if (q.code === 0 && q.stdout.trim() === extra) dbs.push(extra);
    }
    let seen = false;
    let tail = '';
    const scan = new Transform({
      transform(chunk, _e, cb) {
        const t = tail + chunk.toString('latin1');
        if (t.includes('CREATE TABLE')) seen = true;
        tail = t.slice(-12);
        cb(null, chunk);
      },
    });
    const done = pipeline(scan, createGzip(), createWriteStream(file, { mode: 0o600 }));
    const failed = new Promise((_, rej) => { done.catch(rej); });
    const run = docker.compose(['exec', '-T', 'mysql', 'sh', '-c',
      'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -u root --single-transaction --routines --triggers --databases "$@"', 'sh', ...dbs], { stdoutTo: scan });
    const r = await Promise.race([run, failed]);
    scan.end();
    await done;
    if (r.code !== 0 || !seen) throw new Error((r.stderr || 'no table definitions in the dump').trim());
    chmodSync(file, 0o600);
    const { HOST_UID, HOST_GID } = process.env;
    if (HOST_UID && HOST_GID) { chownSync(dir, Number(HOST_UID), Number(HOST_GID)); chownSync(file, Number(HOST_UID), Number(HOST_GID)); }
    return file;
  } catch (e) {
    try { rmSync(file, { force: true }); } catch { /* nothing to clean up */ }
    throw new Error(`Backup failed, nothing was changed:\n${e.message}`);
  }
}

const stderrOf = (r) => (r.stderr || '').trim();

// Gone = the folder is absent, or mounts but holds neither child. Any other docker failure is NOT "gone".
async function checkSafelineGone(docker, dir) {
  const r = await docker.run(['run', '--rm', '--mount', `type=bind,src=${dir},dst=/s`, 'alpine', 'sh', '-c',
    '[ -e /s/resources ] && echo "PRESENT resources"; [ -e /s/logs ] && echo "PRESENT logs"; exit 0']);
  if (r.code === 0) {
    const left = r.stdout.split('\n').filter((l) => l.startsWith('PRESENT ')).map((l) => `${dir}/${l.slice(8)} still exists`);
    return { ok: left.length === 0, missing: left };
  }
  if (/does not exist|no such file/i.test(r.stderr || '')) return { ok: true, missing: [] };
  return { ok: false, missing: [`cannot check ${dir}: ${stderrOf(r)}`] };
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
    ...(hasSafeline ? ['- the SafeLine firewall project', `- ${stateDir}/resources and ${stateDir}/logs (SafeLine data; you will be asked to remove them)`] : []),
    '- .env, docker-compose.yml, docker/safeline/.env, opbx-install-summary.txt',
  ].join('\n'));

  const ps = await docker.compose(['ps', '-q', 'mysql']);
  if (ps.code === 0 && ps.stdout.trim()) {
    if (await ui.confirm({ message: 'Back up the database to ./backups/ first?', initialValue: true })) {
      const dbs = [env.get('DB_DATABASE') || 'opbx', env.get('DB_CDR_ARCHIVE_DATABASE')].filter(Boolean);
      ui.note('Backup', `Saved ${await backup(docker, repoDir, dbs)}`);
    }
  } else {
    ui.note('No backup', "MySQL isn't running, so no backup can be taken. To back up first: cancel, run `docker compose up -d mysql`, then `./install.sh reset` again.");
  }

  if (!(await confirmPhrase(ui, expected))) throw new Error('Confirmation failed three times. Nothing was changed.');

  // ---- destructive from here on ----
  uiState.resetStarted = true;
  const down = await docker.compose(['--profile', '*', 'down', '--remove-orphans', '-v']);
  if (down.code !== 0) throw new Error(`Reset stopped: could not stop OPBX; containers may be partly stopped, but your data was not deleted.\n${stderrOf(down)}`);
  if (hasSafeline) {
    const sd = await docker.compose(['--project-directory', 'docker/safeline', '-p', 'safeline', 'down']);
    if (sd.code !== 0) throw new Error(`Reset stopped: could not stop SafeLine; OPBX is stopped and SafeLine may be partly stopped, but your data was not deleted.\n${stderrOf(sd)}`);
  }
  const vr = await docker.run(['volume', 'rm', '-f', `${project}_loki_data`, `${project}_grafana_data`]);
  if (vr.code !== 0 && !/no such volume/i.test(vr.stderr || '')) throw new Error(`Reset stopped: could not remove the log volumes.\n${stderrOf(vr)}`);

  if (existsSync(join(repoDir, 'volumes'))) {
    const rm = await docker.run(['run', '--rm', '--mount', `type=bind,src=${repoDir}/volumes,dst=/v`, 'alpine', 'sh', '-c',
      `rm -rf ${DATA_DIRS.map((d) => `/v/${d}`).join(' ')}`]);
    if (rm.code !== 0 || DATA_DIRS.some((d) => existsSync(join(repoDir, 'volumes', d)))) {
      throw new Error(`Data not fully deleted; your .env was kept. Run ./install.sh reset again.\n${stderrOf(rm)}`);
    }
  }

  if (hasSafeline) {
    await S.hostStep(ui, {
      title: 'Remove the firewall data',
      explain: 'Run this on this machine (it needs administrator rights), then come back.',
      commands: [...(platform?.podmanMachine ? ['# Open a shell in the Podman VM first with "podman machine ssh", then run this there:'] : []), `sudo rm -rf ${stateDir}/resources ${stateDir}/logs`],
      check: () => checkSafelineGone(docker, stateDir),
    });
  }

  for (const f of CONFIG_FILES) rmSync(join(repoDir, f), { force: true });

  uiState.resetStarted = false; // from here on a cancel is an install cancel (reset would refuse: nothing left to reset)
  const reuse = await ui.confirm({ message: 'Reuse my previous answers?', initialValue: true });
  await S.runInstall({ ui, docker, repoDir, platform, resume: false, defaults: reuse ? previousAnswers : undefined });
}
