import { checkDetector } from './safeline.js';

const tail = (r, n = 40) => `${r.stdout}\n${r.stderr}`.split('\n').filter(Boolean).slice(-n).join('\n');
const real = { now: () => Date.now(), sleep: (ms) => new Promise((r) => setTimeout(r, ms)) };

export async function buildImages(docker, ui) {
  for (;;) {
    let r;
    for (let i = 0; i < 3; i++) {
      r = await docker.compose(['build']);
      if (r.code === 0) return;
    }
    ui.note('Build failed (last 40 lines)', tail(r));
    const choice = await ui.select({ message: 'Image build failed three times.', options: [{ value: 'retry', label: 'Retry' }, { value: 'abort', label: 'Abort' }] });
    if (choice !== 'retry') throw new Error('Image build failed; aborted by user.');
  }
}

export async function composerInstall(docker, answers) {
  const { HOST_UID, HOST_GID } = process.env;
  const args = ['run', '--rm', '--no-deps', ...(HOST_UID && HOST_GID ? ['--user', `${HOST_UID}:${HOST_GID}`] : []),
    '-e', 'COMPOSER_HOME=/tmp/composer', '--entrypoint', 'composer', 'app', 'install', '--no-interaction', '--prefer-dist',
    ...(answers.mode === 'production' ? ['--no-dev'] : []), '--optimize-autoloader'];
  const r = await docker.compose(args);
  if (r.code !== 0) throw new Error(`composer install failed:\n${tail(r)}`);
}

async function waitHealthy(docker, name, { now, sleep, timeoutMs, intervalMs, onTick }) {
  const deadline = now() + timeoutMs;
  for (;;) {
    const r = await docker.run(['inspect', '--format', '{{.State.Health.Status}}', name]);
    if (r.stdout.trim() === 'healthy') return;
    if (now() >= deadline) throw new Error(`${name} did not become healthy within ${Math.round(timeoutMs / 60000)} minutes.`);
    if (onTick) await onTick();
    await sleep(intervalMs);
  }
}

export async function startStack(docker, ui, answers, { now = real.now, sleep = real.sleep, timeoutMs = 20 * 60_000, intervalMs = 5000 } = {}) {
  const s = ui.spinner();
  if (answers.waf) {
    s.start('Starting SafeLine');
    const r = await docker.compose(['--project-directory', 'docker/safeline', '-p', 'safeline', 'up', '-d']);
    if (r.code !== 0) { s.stop('SafeLine failed to start'); throw new Error(`SafeLine start failed:\n${tail(r)}`); }
    await waitHealthy(docker, 'safeline-detector', { now, sleep, timeoutMs: 5 * 60_000 > timeoutMs ? timeoutMs : 5 * 60_000, intervalMs });
    if (!(await checkDetector(docker))) throw new Error('SafeLine detector is healthy but not reachable on the safeline-ce network.');
    s.stop('SafeLine is up');
  }
  s.start('Starting OPBX');
  const up = await docker.compose(['up', '-d']);
  if (up.code !== 0) { s.stop('Start failed'); throw new Error(`docker compose up failed:\n${tail(up)}`); }
  try {
    await waitHealthy(docker, 'opbx_app', {
      now, sleep, timeoutMs, intervalMs,
      onTick: async () => { const l = (await docker.run(['logs', '--tail', '1', 'opbx_app'])); const line = tail(l, 1); if (line) s.message(`Waiting for app (first boot migrates): ${line.slice(0, 100)}`); },
    });
  } catch (e) { s.stop('App not healthy'); throw e; }
  s.stop('OPBX is up');
}

// exit 0 created, 2 a user already exists, anything else is an error (never echo the password)
export async function createOwner(docker, answers, password) {
  const r = await docker.compose(['exec', '-T', 'app', 'php', 'artisan', 'opbx:install-owner', '--no-interaction',
    `--org-name=${answers.orgName}`, `--name=${answers.ownerName}`, `--email=${answers.ownerEmail}`], { input: `${password}\n` });
  if (r.code === 0) return 'created';
  if (r.code === 2) return 'exists';
  throw new Error(`Creating the owner failed:\n${tail(r).replaceAll(password, '***')}`);
}

// ANSI stripped; accepts ASCII or full-width colon, on stdout or stderr
export async function resetSafelineAdmin(docker) {
  const r = await docker.run(['exec', 'safeline-mgt', '/app/mgt', 'reset-admin']);
  const text = `${r.stdout}\n${r.stderr}`.replace(/\x1b\[[0-9;]*m/g, '');
  const username = /username\s*[:：]\s*(\S+)/i.exec(text)?.[1];
  const password = /password\s*[:：]\s*(\S+)/i.exec(text)?.[1];
  if (r.code !== 0 || !username || !password) throw new Error('Could not parse the SafeLine reset-admin output.');
  return { username, password };
}
