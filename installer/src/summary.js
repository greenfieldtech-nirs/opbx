import { join } from 'node:path';
import { writeFileAtomic } from './envfile.js';

const FILE = 'opbx-install-summary.txt';
const HEADERS = `proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";`;

export const consolePort = (a) => a.waf?.consolePort ?? 9443;
export const consoleUrl = (a) => (a.mode === 'production' ? `https://${new URL(a.publicUrl).hostname}:${consolePort(a)}` : `http://localhost:${consolePort(a)}`);

export function proxySnippet(a) {
  const host = new URL(a.publicUrl).hostname;
  let t = `# Inside your existing server { } block for ${host} (the one with its TLS certificate):
    client_max_body_size 50m;
    location / {
        proxy_pass http://<this-server>:${a.httpPort};
        ${HEADERS}
        proxy_read_timeout 3600s;
    }`;
  if (a.waf) {
    const port = consolePort(a);
    t += `

# WAF console: a separate server { } block, with the same certificate as the site above.
# If this proxy runs on the OPBX server itself, OPBX already uses port ${port}: run
# ./install.sh reconfigure and pick a console port the proxy does not listen on.
server {
    listen ${port} ssl;
    server_name ${host};
    location / {
        proxy_pass http://<this-server>:${port};
        ${HEADERS}
        proxy_read_timeout 3600s;
    }
}`;
  }
  return t;
}

export function renderSummary(a, { ownerPassword, wafAdmin, checks = [] }) {
  const prod = a.mode === 'production';
  const L = [];
  const add = (...x) => L.push(...x);
  add('OPBX installation summary', '');
  if (checks.length) add('Checks', ...checks.map((c) => `  ${c.name}: ${c.ok ? 'ok' : 'FAIL'} - ${c.detail ?? ''}`), '');
  add('URLs', `  Web app:  ${a.publicUrl}`, `  API:      ${a.publicUrl}/api/v1`);
  if (a.waf) add(`  WAF console: ${consoleUrl(a)}`);
  if (a.logs) add(`  Grafana:  ${a.publicUrl}/logs/`);
  if (!prod && a.ngrokToken) add('  ngrok dashboard: http://localhost:4040');
  add('', 'Owner login', `  Email:    ${a.ownerEmail}`,
    ...(ownerPassword ? [`  Password: ${ownerPassword}`, '  This password will not be shown again.'] : ['  Owner account already existed; password unchanged.']), '');
  if (a.waf) {
    add('WAF admin login');
    add(...(wafAdmin
      ? [`  Username: ${wafAdmin.username}`, `  Password: ${wafAdmin.password}`]
      : ['  Run `docker exec safeline-mgt /app/mgt reset-admin` to set the WAF admin password']));
    if (!a.trustedProxyCidr) add('  No proxy IP was given, so the WAF console is protected only by SafeLine\'s login.');
    add('');
  }
  if (prod) {
    add('Reverse proxy (nginx example; replace <this-server> with the address of this machine)', '', proxySnippet(a), '',
      `Make port ${a.httpPort}${a.waf ? ` and ${consolePort(a)}` : ''} reachable only from this proxy.`, '');
    if (!a.trustedProxyCidr) add('No proxy IP was given: rate limits, logs and firewall events will see the proxy address, not the real client address.', '');
  }
  add('Next steps');
  if (a.tenancy === 'single') add('  - In OPBX go to Settings -> Cloudonix and set up the Cloudonix connection and webhooks.');
  if (prod) add('  - Terminate TLS on the proxy.');
  if (a.waf && a.waf.mode === 'monitor') add('  - WAF is in monitor mode. Once traffic looks clean, switch to block with `./install.sh reconfigure`.');
  add('  - Take a first backup: ./scripts/backup-database.sh', '  - Everyday start command: docker compose up -d', '');
  if (a.tenancy === 'single') add('Warning: single-company mode has open sign-up; anyone who can reach the app can register.', '');
  if (a.email.provider === 'none') add('Email is set to None: invitations and password-reset emails will not be sent.', '');
  add(`This summary is saved in ${FILE} (mode 600). Delete it once the password is stored safely.`);
  return L.join('\n');
}

export function saveSummary(repoDir, text, { uid, gid } = {}) {
  const path = join(repoDir, FILE);
  writeFileAtomic(path, text, { mode: 0o600, uid, gid });
  return path;
}
