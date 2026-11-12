import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import { parseEnv, renderEnv, writeFileAtomic, envFileOpts } from '../envfile.js';
import { deriveEnv, answersFromEnv } from '../derive.js';
import { generateOpbxSecrets, generateOwnerPassword } from '../secrets.js';
import { askAnswers, reviewAnswers } from '../questions.js';
import { hostStep } from '../hoststep.js';
import { safelineHostCommands, checkSafelineState, writeSafelineEnv } from '../safeline.js';
import { buildImages, composerInstall, startStack, createOwner, resetSafelineAdmin } from '../stack.js';
import { runChecks } from '../verify.js';
import { renderSummary, saveSummary } from '../summary.js';
import { isPortFree } from '../platform.js';
import { state as uiState } from '../ui.js';

const MIN_FREE_BYTES = 10e9;

function freeBytes(dir) {
  const r = spawnSync('df', ['-Pk', dir], { encoding: 'utf8' });
  const kb = Number(r.stdout?.split('\n')[1]?.trim().split(/\s+/)[3]);
  return r.status === 0 && Number.isFinite(kb) ? kb * 1024 : null; // unknown: do not block
}

function generateSharedSecrets(repoDir) {
  const r = spawnSync('bash', ['docker/scripts/generate-secrets.sh', '.env'], { cwd: repoDir, encoding: 'utf8' });
  if (r.status !== 0) throw new Error(`generate-secrets.sh failed: ${r.stderr || r.error?.message || ''}`.trim());
}

// `steps` replaces collaborators in unit tests.
export async function runInstall(opts) {
  try { return await runInstallInner(opts); } catch (e) {
    if (uiState.configWritten) e.message += '\nRun ./install.sh again to resume.';
    throw e;
  }
}

async function runInstallInner({ ui, docker, repoDir, platform, resume, defaults, steps = {} }) {
  const S = {
    askAnswers, reviewAnswers, isPortFree, hostStep, checkSafelineState, safelineHostCommands, writeSafelineEnv,
    buildImages, composerInstall, startStack, createOwner, resetSafelineAdmin, runChecks, freeBytes, generateSharedSecrets,
    ...steps,
  };
  const envPath = join(repoDir, '.env');
  const readEnv = (p) => (existsSync(p) ? parseEnv(readFileSync(p, 'utf8')) : new Map());
  const { HOST_UID, HOST_GID } = process.env;
  const own = HOST_UID && HOST_GID ? { uid: Number(HOST_UID), gid: Number(HOST_GID) } : {};

  const free = await S.freeBytes(repoDir);
  if (free !== null && free < MIN_FREE_BYTES) throw new Error(`Not enough free disk space: ${(free / 1e9).toFixed(1)} GB available, 10 GB needed.`);

  const existing = readEnv(envPath);
  let base = defaults ?? {};
  if (resume) base = { ...answersFromEnv(existing, readEnv(join(repoDir, 'docker/safeline/.env')), { includeSecrets: true }), ...base };
  const portCheck = resume ? null : (p) => S.isPortFree(docker, p);

  let answers = await S.askAnswers(ui, { platform, defaults: base, portCheck });
  while ((await S.reviewAnswers(ui, answers)) === 'edit') {
    answers = await S.askAnswers(ui, { platform, defaults: answers, portCheck });
  }

  const composePath = join(repoDir, 'docker-compose.yml');
  const example = readFileSync(`${composePath}.example`, 'utf8');
  if (!existsSync(composePath)) writeFileAtomic(composePath, example, { mode: 0o644, ...own });
  else if (!resume && readFileSync(composePath, 'utf8') !== example) {
    ui.note('Your docker-compose.yml is out of date', 'docker-compose.yml differs from docker-compose.yml.example. An older copy misses settings this version needs (the real client IP and the firewall console).');
    if (await ui.confirm({ message: 'Back it up and replace it with docker-compose.yml.example?', initialValue: true })) {
      const backup = `${composePath}.bak-${new Date().toISOString().replace(/\D/g, '').slice(0, 14)}`;
      writeFileAtomic(backup, readFileSync(composePath, 'utf8'), { mode: 0o644, ...own });
      writeFileAtomic(composePath, example, { mode: 0o644, ...own });
      ui.note('docker-compose.yml replaced', `Your old file is saved as ${backup.slice(repoDir.length + 1)}.`);
    }
  }

  // Reusing a resumed .env as the template keeps every secret MySQL may already be initialised with.
  const template = resume && existsSync(envPath) ? readFileSync(envPath, 'utf8') : readFileSync(join(repoDir, '.env.example'), 'utf8');
  const secrets = Object.fromEntries(Object.entries(generateOpbxSecrets()).map(([k, v]) => [k, existing.get(k) || v]));
  const write = (values) => writeFileAtomic(envPath, renderEnv(template, values), envFileOpts());
  write({ ...deriveEnv(answers), ...secrets, OPBX_INSTALL_STATE: 'incomplete' });
  uiState.configWritten = true;
  S.generateSharedSecrets(repoDir);

  if (answers.waf) {
    const dir = answers.waf.stateDir;
    await S.hostStep(ui, {
      title: 'Prepare the firewall data folder',
      explain: 'Run these commands on this machine (they need administrator rights), then come back.',
      commands: S.safelineHostCommands(platform, dir),
      check: () => S.checkSafelineState(docker, dir),
    });
    S.writeSafelineEnv(repoDir, answers, {}, platform);
  }

  await S.buildImages(docker, ui);
  await S.composerInstall(docker, ui, answers);
  await S.startStack(docker, ui, answers);

  const summaryPath = join(repoDir, 'opbx-install-summary.txt');
  let ownerPassword = generateOwnerPassword();
  let shown = null;
  if ((await S.createOwner(docker, answers, ownerPassword)) === 'exists') {
    if (!resume) throw new Error('A user already exists in this fresh installation. Someone else created it: stopping so nothing is overwritten.');
    ownerPassword = null;
  } else {
    // The password is shown nowhere else: persist and display it before anything can fail.
    const creds = `Owner login\n  Email:    ${answers.ownerEmail}\n  Password: ${ownerPassword}`;
    saveSummary(repoDir, `OPBX installation still in progress.\n\n${creds}\n`, own);
    shown = () => ui.note('Write this down now', `${creds}\n\nIt is also saved in ${summaryPath}`);
    shown();
  }

  try {
    let wafAdmin = null;
    if (answers.waf) {
      try { wafAdmin = await S.resetSafelineAdmin(docker); } catch (e) { ui.note('Firewall admin', `Could not create the firewall admin login: ${e.message}`); }
    }

    let checks;
    for (;;) {
      checks = await S.runChecks(docker, answers);
      const bad = checks.filter((c) => !c.ok);
      if (!bad.length) break;
      ui.note('Some checks failed', bad.map((c) => `- ${c.name}: ${c.detail ?? ''}`).join('\n'));
      const choice = await ui.select({ message: 'What now?', options: [{ value: 'retry', label: 'Check again' }, { value: 'abort', label: 'Stop here' }] });
      if (choice !== 'retry') throw new Error('Checks failed.');
    }

    if (answers.email.provider !== 'none' && await ui.confirm({ message: `Send a test email to ${answers.ownerEmail}?`, initialValue: true })) {
      try {
        const r = await docker.compose(['exec', '-T', 'app', 'php', 'artisan', 'email:send-test', `--to=${answers.ownerEmail}`]);
        ui.note('Test email', r.code === 0 ? 'Sent. Check your inbox.' : `Failed:\n${`${r.stdout}\n${r.stderr}`.trim().slice(-400)}`);
      } catch (e) { ui.note('Test email', `Failed: ${e.message}`); }
    }

    const priorSummary = existsSync(summaryPath);
    let text = renderSummary(answers, { ownerPassword, wafAdmin, checks });
    if (!ownerPassword) {
      text += `\n\nTo set a new owner password: docker compose exec app php artisan user:reset-password ${answers.ownerEmail}`
        + (priorSummary ? `\nThe password from the earlier run may still be in ${summaryPath} (kept as it is).` : '');
    }
    if (ownerPassword || !priorSummary) saveSummary(repoDir, text, own);
    ui.note('Installation complete', text);
    writeFileAtomic(envPath, renderEnv(readFileSync(envPath, 'utf8'), { OPBX_INSTALL_STATE: 'complete' }), envFileOpts());
  } catch (e) {
    if (shown) shown();
    throw e;
  }
}
