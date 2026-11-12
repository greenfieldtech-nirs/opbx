import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { parseEnv, renderEnv, writeFileAtomic } from '../envfile.js';
import { deriveEnv, answersFromEnv, OWNED_KEYS } from '../derive.js';
import { askPublicUrl, askWaf } from '../questions.js';
import { detectInstall } from '../detect.js';
import { hostStep } from '../hoststep.js';
import { safelineHostCommands, checkSafelineState, writeSafelineEnv, checkDetector } from '../safeline.js';
import { resetSafelineAdmin, waitHealthy } from '../stack.js';
import { runChecks } from '../verify.js';
import { isPortFree } from '../platform.js';
import { proxySnippet } from '../summary.js';
import { state as uiState } from '../ui.js';

const OVERLAY = 'docker-compose.waf.yml';
const SAFELINE = ['--project-directory', 'docker/safeline', '-p', 'safeline'];
const tail = (r) => `${r.stdout}\n${r.stderr}`.trim().split('\n').slice(-20).join('\n');
const pick = (obj, keys) => Object.fromEntries(keys.filter((k) => k in obj).map((k) => [k, obj[k]]));
const real = { now: () => Date.now(), sleep: (ms) => new Promise((r) => setTimeout(r, ms)) };

// `steps` replaces collaborators in unit tests.
export async function runReconfigure({ ui, docker, repoDir, platform, steps = {} }) {
  const S = { askPublicUrl, askWaf, hostStep, isPortFree, checkSafelineState, safelineHostCommands, writeSafelineEnv, resetSafelineAdmin, runChecks, ...steps };
  const envPath = join(repoDir, '.env');
  const readEnv = (p) => (existsSync(p) ? parseEnv(readFileSync(p, 'utf8')) : new Map());
  const { HOST_UID, HOST_GID } = process.env;
  const own = HOST_UID && HOST_GID ? { uid: Number(HOST_UID), gid: Number(HOST_GID) } : {};

  if (detectInstall(repoDir) !== 'complete' || !existsSync(envPath)) {
    ui.note('Nothing to reconfigure', 'OPBX is not installed (or the installation is not complete). Run ./install.sh first.');
    return;
  }
  const env = readEnv(envPath);
  const slEnv = () => readEnv(join(repoDir, 'docker/safeline/.env'));
  const current = answersFromEnv(env, slEnv());
  const prod = current.mode === 'production';
  const wafOn = !!current.waf;

  uiState.reconfigure = 'started';
  const choice = await ui.select({
    message: 'What would you like to change?',
    options: [
      { value: 'domain', label: 'Change the public address' },
      ...(wafOn
        ? [{ value: 'wafmode', label: 'Change the firewall mode (monitor / block)' }, { value: 'wafoff', label: 'Turn the firewall off' }]
        : [{ value: 'wafon', label: 'Turn the firewall on' }]),
      { value: 'exit', label: 'Exit' },
    ],
  });
  if (choice === 'exit') { uiState.reconfigure = null; return; }

  // Rewrites only the given owned keys over the CURRENT .env; every other byte stays.
  const apply = (values) => {
    writeFileAtomic(envPath, renderEnv(readFileSync(envPath, 'utf8'), values), { mode: 0o644, ...own }); // app container reads it as www-data
    uiState.reconfigure = 'written';
  };
  const must = (r, what) => {
    if (r.code === 0) return;
    const saved = uiState.reconfigure === 'written' ? '\nThe settings in .env were already updated: fix the problem, then run `docker compose up -d` to apply them.' : '\nThe settings in .env were not changed.';
    throw new Error(`${what} failed:\n${tail(r)}${saved}`);
  };
  const report = async (answers, only) => {
    const checks = (await S.runChecks(docker, answers)).filter((c) => !only || only(c));
    const bad = checks.filter((c) => !c.ok);
    ui.note(bad.length ? 'Some checks failed' : 'Checks passed', bad.length ? bad.map((c) => `- ${c.name}: ${c.detail ?? ''}`).join('\n') : checks.map((c) => `- ${c.name}: ok`).join('\n'));
    return !bad.length;
  };
  const fresh = () => answersFromEnv(readEnv(envPath), slEnv());
  const wafValues = (a) => {
    const v = pick(deriveEnv(a), OWNED_KEYS.waf);
    const files = (env.get('COMPOSE_FILE') || '').split(':').filter((f) => f && f !== OVERLAY);
    v.COMPOSE_FILE = (a.waf ? [...files, OVERLAY] : files).join(':');
    return v;
  };

  if (choice === 'domain') {
    if (!prod) {
      ui.note('Cannot change the address', 'A development installation always uses http://localhost. Nothing was changed.');
      uiState.reconfigure = null;
      return;
    }
    const publicUrl = await S.askPublicUrl(ui, { publicUrl: current.publicUrl }, 'production');
    if (publicUrl === current.publicUrl) { ui.note('Nothing changed', 'That is already the public address.'); uiState.reconfigure = null; return; }
    apply(pick(deriveEnv({ ...current, publicUrl }), OWNED_KEYS.domain));
    must(await docker.compose(['build', 'frontend']), 'Rebuilding the frontend');
    must(await docker.compose(['up', '-d']), 'Restarting the services');
    // long-running workers read .env once at start and `up -d` does not recreate them
    must(await docker.compose(['restart', 'queue-worker', 'queue-worker-long']), 'Restarting the queue workers');
    const a = fresh();
    await report(a);
    ui.note('New reverse proxy settings', `Point your proxy at OPBX like this and use a certificate for ${new URL(a.publicUrl).hostname}:\n\n${proxySnippet(a)}`);
    ui.note('Cloudonix webhooks', a.tenancy === 'multi'
      ? 'The app-wide webhook address was already updated to the new address.'
      : 'In OPBX go to Settings -> Cloudonix and save the connection again: that pushes the new webhook address to Cloudonix.');
    return;
  }

  if (choice === 'wafmode') {
    const mode = await ui.select({
      message: 'Which firewall mode?',
      options: [{ value: 'monitor', label: 'Monitor only', hint: 'log attacks, do not block' }, { value: 'block', label: 'Blocking' }],
      initialValue: current.waf.mode,
    });
    apply({ WAF_MODE: mode });
    must(await docker.compose(['up', '-d', 'nginx']), 'Recreating nginx');
    await report(fresh(), (c) => c.name.startsWith('waf'));
    return;
  }

  if (choice === 'wafoff') {
    apply(wafValues({ ...current, waf: null }));
    must(await docker.compose(['up', '-d', 'nginx']), 'Recreating nginx');
    uiState.reconfigure = 'wafoff';
    if (await ui.confirm({ message: 'Stop the SafeLine containers too?', initialValue: true })) {
      must(await docker.compose([...SAFELINE, 'down']), 'Stopping SafeLine');
    }
    ui.note('Firewall off', `The firewall data in ${current.waf.stateDir || 'its data folder'} was kept.`);
    return;
  }

  // wafon
  const waf = await S.askWaf(ui, { waf: { stateDir: slEnv().get('SAFELINE_DIR') || undefined } }, current.trustedProxyCidr);
  if (!waf) { ui.note('Nothing changed', 'The firewall stays off.'); uiState.reconfigure = null; return; }
  await S.hostStep(ui, {
    title: 'Prepare the firewall data folder',
    explain: 'Run these commands on this machine (they need administrator rights), then come back.',
    commands: S.safelineHostCommands(platform, waf.stateDir),
    check: () => S.checkSafelineState(docker, waf.stateDir),
  });
  const next = { ...current, waf };
  let free;
  try { free = await S.isPortFree(docker, 9443); } catch (e) { throw new Error(`Could not check port 9443 for the firewall console: ${e.message}\nThe settings in .env were not changed.`); }
  if (!free) throw new Error('Port 9443 (the firewall console) is already used by another program. Free it and run this again.\nThe settings in .env were not changed.');
  // build the WAF nginx image now so a build error happens before .env is rewritten
  const files = [...(env.get('COMPOSE_FILE') || '').split(':').filter((f) => f && f !== OVERLAY), OVERLAY];
  must(await docker.compose([...files.flatMap((f) => ['-f', f]), 'build', 'nginx']), 'Building the firewall nginx image');
  S.writeSafelineEnv(repoDir, next, {}, platform);
  const s = ui.spinner();
  s.start('Starting SafeLine');
  try {
    must(await docker.compose([...SAFELINE, 'up', '-d']), 'Starting SafeLine');
    await waitHealthy(docker, 'safeline-detector', { ...real, timeoutMs: 5 * 60_000, intervalMs: 5000 });
    if (!(await checkDetector(docker))) throw new Error('SafeLine detector is healthy but not reachable on the safeline-ce network.');
  } catch (e) { s.stop('SafeLine failed'); throw e; }
  s.stop('SafeLine is up');
  apply(wafValues(next));
  must(await docker.compose(['up', '-d', 'nginx']), 'Recreating nginx');
  const a = fresh();
  await report(a, (c) => c.name.startsWith('waf'));
  let login;
  try {
    const w = await S.resetSafelineAdmin(docker);
    login = `Username: ${w.username}\nPassword: ${w.password}`;
  } catch (e) { login = `Could not read the admin login (${e.message}).\nRun \`docker exec safeline-mgt /app/mgt reset-admin\` to set the firewall admin password.`; }
  ui.note('Firewall admin login', `Console: ${prod ? `https://${new URL(a.publicUrl).hostname}:9443` : 'http://localhost:9443'}\n${login}`);
  if (prod) ui.note('New reverse proxy settings', `Your proxy must also forward port 9443 with the same certificate:\n\n${proxySnippet(a)}`);
}
