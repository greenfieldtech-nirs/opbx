import { fileURLToPath } from 'node:url';
import { createDocker } from './docker.js';
import { detectPlatform } from './platform.js';
import { detectInstall } from './detect.js';
import { runInstall } from './modes/install.js';
import { runReset } from './modes/reset.js';
import { ui, handleSigint } from './ui.js';

export function chooseAction(mode, state) {
  switch (mode) {
    case 'install':
      return { none: 'install', incomplete: 'resume', complete: 'offer-reset-or-reconfigure' }[state];
    case 'reset':
      return 'reset';
    case 'reconfigure':
      return state === 'none' ? 'nothing-to-reconfigure' : 'reconfigure';
    default:
      throw new Error(`Unknown mode "${mode}". Use install, reset or reconfigure.`);
  }
}

const MESSAGES = {
  install: 'No installation found: would run a fresh install.',
  resume: 'Incomplete installation found: would resume it.',
  'offer-reset-or-reconfigure': 'Existing installation found. Reset and reconfigure are not available yet.',
  reconfigure: 'Reconfigure is not available yet.',
  'nothing-to-reconfigure': 'Nothing to reconfigure: no installation found.',
};

async function main() {
  const mode = process.argv[2] || 'install';
  const repo = process.env.OPBX_REPO || process.cwd();
  const action = chooseAction(mode, detectInstall(repo));
  if (action !== 'install' && action !== 'resume' && action !== 'reset') { console.log(MESSAGES[action]); return; }
  process.on('SIGINT', () => handleSigint());
  const docker = createDocker();
  const platform = await detectPlatform(docker);
  if (action === 'reset') return runReset({ ui, docker, repoDir: repo, platform });
  await runInstall({ ui, docker, repoDir: repo, platform, resume: action === 'resume' });
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  main().catch((e) => { console.error(process.env.OPBX_DEBUG === '1' ? e.stack : e.message); process.exit(1); });
}
