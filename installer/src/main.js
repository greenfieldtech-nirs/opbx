import { fileURLToPath } from 'node:url';
import { createDocker } from './docker.js';
import { detectPlatform } from './platform.js';
import { detectInstall } from './detect.js';

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
  'offer-reset-or-reconfigure': 'Existing installation found: would offer reset or reconfigure.',
  reset: 'Would reset the installation.',
  reconfigure: 'Would reconfigure the installation.',
  'nothing-to-reconfigure': 'Nothing to reconfigure: no installation found.',
};

async function main() {
  const mode = process.argv[2] || 'install';
  const repo = process.env.OPBX_REPO || process.cwd();
  const docker = createDocker();
  const action = chooseAction(mode, detectInstall(repo));
  console.log(MESSAGES[action]);
  // Stub diagnostics, replaced by the real modes in later tasks.
  console.log('platform:', JSON.stringify(await detectPlatform(docker)));
  const v = await docker.run(['version', '--format', '{{.Server.Version}}']);
  const c = await docker.compose(['version', '--short']);
  console.log(`daemon ${v.stdout.trim()} (exit ${v.code}), compose ${c.stdout.trim()} (exit ${c.code})`);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  main().catch((e) => { console.error(e.message); process.exit(1); });
}
