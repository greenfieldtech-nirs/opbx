import { fileURLToPath } from 'node:url';
import { createDocker } from './docker.js';
import { detectPlatform } from './platform.js';
import { detectInstall } from './detect.js';
import { runInstall } from './modes/install.js';
import { runReset } from './modes/reset.js';
import { runReconfigure } from './modes/reconfigure.js';
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

// Plain ./install.sh on a complete installation asks what to do (§7.1).
export async function resolveAction(action, ui) {
  if (action !== 'offer-reset-or-reconfigure') return action;
  return ui.select({
    message: 'OPBX is already installed. What would you like to do?',
    options: [{ value: 'reconfigure', label: 'Change settings', hint: 'public address or firewall' },
      { value: 'reset', label: 'Start over', hint: 'deletes all data' }, { value: 'exit', label: 'Exit' }],
  });
}

async function main() {
  const mode = process.argv[2] || 'install';
  const repo = process.env.OPBX_REPO || process.cwd();
  let action = chooseAction(mode, detectInstall(repo));
  if (action === 'nothing-to-reconfigure') { console.log('Nothing to reconfigure: no installation found.'); return; }
  process.on('SIGINT', () => handleSigint());
  action = await resolveAction(action, ui);
  if (action === 'exit') return;
  const docker = createDocker();
  const platform = await detectPlatform(docker);
  if (action === 'reset') return runReset({ ui, docker, repoDir: repo, platform });
  if (action === 'reconfigure') return runReconfigure({ ui, docker, repoDir: repo, platform });
  await runInstall({ ui, docker, repoDir: repo, platform, resume: action === 'resume' });
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  main().catch((e) => { console.error(process.env.OPBX_DEBUG === '1' ? e.stack : e.message); process.exit(1); });
}
