import { useState } from 'react';
import { Check, Copy, Download, RefreshCw, Settings2, Terminal, Wrench } from 'lucide-react';
import { HomeHeader } from './Home/HomeHeader';
import { Footer } from './Home/Footer';

const REPO = 'https://github.com/greenfieldtech-nirs/opbx.git';
const DOCS = 'https://developers.cloudonix.com/opbx/installation';

// One terminal line, colored like a shell: a green prompt before commands, dimmed comments, and the installer's
// own symbols (◇ step, │ rail) as they appear in a real run.
function TerminalLine({ line, prompt }: { line: string; prompt: boolean }) {
  if (line.startsWith('#')) return <span className="text-gray-500">{line}</span>;
  if (line.startsWith('$ ')) return <><span className="text-emerald-400 select-none">$ </span><span className="text-gray-50">{line.slice(2)}</span></>;
  if (line.startsWith('◇')) return <><span className="text-emerald-400">◇</span><span className="text-gray-50">{line.slice(1)}</span></>;
  if (line.startsWith('├')) return <span className="text-gray-600">{line}</span>;
  if (line.startsWith('│')) return <><span className="text-gray-600">│</span><span className="text-sky-300">{line.slice(1)}</span></>;
  if (prompt && line && !/^\s/.test(line)) return <><span className="text-emerald-400 select-none">$ </span><span className="text-gray-50">{line}</span></>;
  return <span className="text-gray-300">{line || '\u00a0'}</span>;
}

/**
 * A shell snippet drawn as a terminal window with a copy button. Command lines get a `$` prompt (not copied);
 * `prompt={false}` for a recorded session that carries its own prompts. `copy` overrides what is copied.
 */
export function CodeBlock({ code, copy, label, prompt = true }: { code: string; copy?: string; label?: string; prompt?: boolean }) {
  const [copied, setCopied] = useState(false);
  const onCopy = async () => {
    try {
      await navigator.clipboard.writeText(copy ?? code);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // clipboard blocked (http page, permissions): the text stays selectable
    }
  };
  return (
    <div className="mx-auto my-4 w-full max-w-[52rem] overflow-hidden rounded-xl border border-white/10 bg-[#0d1117] shadow-2xl shadow-black/50">
      <div className="flex items-center gap-3 border-b border-white/10 bg-[#1c2128] px-4 py-2">
        <div className="flex gap-1.5" aria-hidden="true">
          <span className="h-3 w-3 rounded-full bg-[#ff5f57]" />
          <span className="h-3 w-3 rounded-full bg-[#febc2e]" />
          <span className="h-3 w-3 rounded-full bg-[#28c840]" />
        </div>
        <span className="flex-1 truncate text-center font-mono text-xs text-gray-400">{label ?? 'opbx — bash'}</span>
        <button
          type="button"
          onClick={onCopy}
          aria-label={copied ? 'Copied' : 'Copy to clipboard'}
          className="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs text-gray-400 hover:bg-white/10 hover:text-gray-100"
        >
          {copied ? <Check className="h-3.5 w-3.5 text-emerald-400" aria-hidden="true" /> : <Copy className="h-3.5 w-3.5" aria-hidden="true" />}
          <span aria-live="polite">{copied ? 'Copied' : 'Copy'}</span>
        </button>
      </div>
      <pre className="overflow-x-auto p-4 text-sm leading-relaxed font-mono [color-scheme:dark]">
        <code>
          {code.split('\n').map((line, i) => (
            <div key={i}><TerminalLine line={line} prompt={prompt} /></div>
          ))}
        </code>
      </pre>
    </div>
  );
}

const SECTIONS = [
  { id: 'requirements', label: 'Requirements' },
  { id: 'install', label: 'Install' },
  { id: 'everyday', label: 'Start & stop' },
  { id: 'upgrade', label: 'Upgrade' },
  { id: 'change', label: 'Change settings' },
  { id: 'troubleshooting', label: 'Troubleshooting' },
];

const REQUIREMENTS = [
  ['A Linux server or a Mac', '4 GB RAM (8 GB recommended) and 20 GB of disk (50 GB recommended).'],
  ['Docker 20.10+ with Compose 2.24.4+', 'Or Podman with its Docker-compatible CLI. Nothing else: Node.js, PHP and Composer run inside containers.'],
  ['Git', 'To download OPBX and, later, its updates.'],
  ['For production: a domain and your own HTTPS proxy', 'OPBX does not terminate TLS. Your reverse proxy or HTTPS accelerator forwards one domain to OPBX; the installer prints the proxy configuration.'],
  ['A Cloudonix account', 'For calls. You connect it in the OPBX web app after installing.'],
];

const ASKS = [
  'Production server or development machine, and the port OPBX listens on',
  'Production: the public https:// address and, optionally, your proxy\'s IP (for real visitor IPs)',
  'Development: other host names allowed to open it (a VPN or Tailscale name, the machine\'s IP, an ngrok domain)',
  'Your organization, your name and email (you become the platform owner)',
  'One company or many customer organizations on this server',
  'What the site root shows: the sign-in page (default) or the OPBX homepage',
  'The outgoing email service: Mailgun, Mailjet, MailerLite, Brevo, or none for now',
  'Optional: Auth0 sign-in (Google, GitHub), reCAPTCHA, UserCheck, log collection in Grafana',
  'Optional: the SafeLine web application firewall (monitor or block), its console port and who may open it',
];

const TRANSCRIPT = `$ ./install.sh
◇  Is this a production server or a development machine?
│  Production
│
◇  What is your server's public address?
│  https://pbx.example.com
│
◇  Which port on this machine should OPBX listen on?
│  80
│
◇  What should people see when they open https://pbx.example.com?
│  The sign-in page
│
◇  Put a web application firewall in front of OPBX?
│  Yes, monitor only
│  ...
◇  Is this correct?
│  Yes, continue
│
│  ... building and starting (10-30 minutes the first time)
◇  OPBX is up
│
◇  Write this down now ───────────────────────╮
│  Owner login                                │
│    Email:    you@example.com                │
│    Password: XXXX-XXXX-XXXX-XXXX-XXXX       │
├─────────────────────────────────────────────╯
│
◇  All services are up`;

const EVERYDAY: [string, string][] = [
  ['./opbx.sh start', 'Start everything in the right order and wait until every service is up. The WAF starts first when it is on.'],
  ['./opbx.sh stop', 'Stop OPBX, then the WAF. Containers, network and data are kept.'],
  ['./opbx.sh restart', 'Stop, then start.'],
  ['./opbx.sh status', 'The state and health of every service, the WAF, and the URLs.'],
  ['./opbx.sh logs app', 'Follow the logs of one service (or all, without a name).'],
  ['./opbx.sh start --no-waf', 'Start once without the WAF. Your settings are not changed; the next start brings it back.'],
  ['./opbx.sh start --nofork', 'Start, then stay in the foreground showing every service\'s log. Ctrl-C stops the stack.'],
];

const UPGRADE_STEPS = [
  'Copies old MinIO recordings to SeaweedFS (once, older installations only)',
  'Rebuilds every image with fresh base images, so security releases are picked up',
  'Installs the PHP packages inside the app image (no PHP on your server)',
  'Starts the WAF first when it is on, then the database, cache, storage and app, showing the app\'s log while the database is updated',
  'Starts the rest and waits until every service is up',
];

// Development-only loopback ports (docker-compose.ports.yml), for local tools on the same computer.
export const DEV_PORTS: [port: number, service: string, purpose: string, variable: string][] = [
  [3306, 'MySQL', 'The database, for a database client such as TablePlus or DBeaver', 'DB_EXPOSE_PORT'],
  [6379, 'Redis', 'Cache, queues and live call state, for redis-cli', 'REDIS_EXPOSE_PORT'],
  [8080, 'MCP server', 'Lets AI assistants (MCP clients) work with OPBX', 'MCP_PORT'],
  [8181, 'Dialer worker', 'Health endpoint of the auto-dialer service', 'DIALER_WEBHOOK_HOST_PORT'],
  [8333, 'SeaweedFS S3 API', 'Storage for recordings and archived call records, for any S3 client', 'MINIO_EXPOSE_PORT'],
  [9333, 'SeaweedFS master UI', 'Web page showing the storage cluster\'s state', 'MINIO_CONSOLE_EXPOSE_PORT'],
];

type Trouble = { q: string; a: string; code?: string; ports?: boolean };
const TROUBLE: Trouble[] = [
  { q: 'A service is not up', a: 'See which one and why:', code: './opbx.sh status\n./opbx.sh logs <service>' },
  {
    q: 'The installer says a port is in use',
    a: 'Pick another port when asked, or stop the program that uses it. On a development machine OPBX also publishes these ports, on 127.0.0.1 only (this computer). If one is taken, the installer offers a free port instead and saves it in .env under the variable shown.',
    ports: true,
  },
  {
    q: 'The WebSocket check times out',
    a: 'Live updates cannot reach the WebSocket server. On Docker hosts this is often a firewall rule Docker left behind for an earlier network. The installer prints the exact commands to find and delete it; for example:',
    code: "sudo iptables-save -t raw | grep -- '-d 172.18.0.3/32'",
  },
  { q: 'I lost the owner password', a: 'Set a new one:', code: 'docker compose exec app \\\n  php artisan user:reset-password <owner email>' },
  { q: 'The development UI says "Blocked request" from another computer', a: 'Add the name you use (VPN, IP) to the allowed host names:', code: './install.sh reconfigure' },
];

function Section({ id, icon: Icon, title, children }: { id: string; icon: typeof Terminal; title: string; children: React.ReactNode }) {
  return (
    <section id={id} className="scroll-mt-24 space-y-4">
      <h2 className="flex items-center gap-3 text-2xl font-bold">
        <Icon className="h-6 w-6 text-primary" aria-hidden="true" />
        {title}
      </h2>
      {children}
    </section>
  );
}

export default function InstallGuide() {
  return (
    <div className="min-h-screen bg-background text-foreground home-dark oranienbaum-regular flex flex-col">
      <HomeHeader />
      <main className="container mx-auto px-4 py-12 flex-1 space-y-14">
        <div>
          <h1 className="text-4xl font-bold mb-3">Install &amp; Upgrade OPBX</h1>
          <p className="text-lg text-muted-foreground">
            One command installs OPBX: a guided installer asks a few questions, generates every secret, starts the stack
            and checks it. Upgrades are one command too.
          </p>
          <nav aria-label="On this page" className="mt-6 flex flex-wrap gap-2">
            {SECTIONS.map((s) => (
              <a key={s.id} href={`#${s.id}`} className="rounded-full border border-border px-3 py-1 text-sm text-muted-foreground hover:text-foreground hover:bg-card">
                {s.label}
              </a>
            ))}
          </nav>
        </div>

        <Section id="requirements" icon={Settings2} title="Requirements">
          <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {REQUIREMENTS.map(([title, text]) => (
              <li key={title} className="rounded-lg border border-border bg-card p-4">
                <p className="font-semibold mb-1">{title}</p>
                <p className="text-sm text-muted-foreground">{text}</p>
              </li>
            ))}
          </ul>
        </Section>

        <Section id="install" icon={Download} title="Install">
          <ol className="space-y-6 list-decimal list-inside marker:font-bold">
            <li>
              <span className="font-semibold">Download OPBX</span>
              <CodeBlock code={`git clone ${REPO}\ncd opbx`} />
            </li>
            <li>
              <span className="font-semibold">Run the installer</span> in a terminal (it needs to ask you questions):
              <CodeBlock code="./install.sh" />
              <p className="text-muted-foreground">It asks, in plain language:</p>
              <ul className="mt-2 space-y-1 list-disc list-inside text-muted-foreground">
                {ASKS.map((a) => <li key={a}>{a}</li>)}
              </ul>
              <p className="mt-3 text-muted-foreground">
                If the firewall needs a command on your server (for example creating its data folder), the installer shows
                the exact command, waits for you to run it, and checks that it worked. You review every answer before
                anything is written.
              </p>
            </li>
            <li>
              <span className="font-semibold">Save the summary.</span> When it finishes, the installer shows the addresses,
              your owner login with its generated password (shown once), the Grafana and firewall logins when those are on,
              and, for production, a ready-to-paste configuration for your HTTPS proxy. It is also saved to
              <code className="mx-1 rounded bg-card px-1">opbx-install-summary.txt</code>
              (readable only by you). Store the password somewhere safe, then delete that file.
            </li>
          </ol>
          <CodeBlock label="opbx — ./install.sh (shortened)" code={TRANSCRIPT} copy="./install.sh" prompt={false} />
          <p className="text-muted-foreground">
            Interrupted? Run <code className="rounded bg-card px-1">./install.sh</code> again: it picks up where it stopped.
          </p>
        </Section>

        <Section id="everyday" icon={Terminal} title="Start & stop">
          <p className="text-muted-foreground">Use <code className="rounded bg-card px-1">./opbx.sh</code> from the OPBX folder. It always starts and stops the services in the right order.</p>
          <div className="overflow-x-auto rounded-lg border border-border">
            <table className="w-full text-sm">
              <tbody>
                {EVERYDAY.map(([cmd, what]) => (
                  <tr key={cmd} className="border-b border-border last:border-0">
                    <td className="p-3 align-top">
                      <code className="inline-block whitespace-nowrap rounded-md bg-[#0d1117] px-2 py-1 font-mono text-sm text-gray-50 ring-1 ring-white/10">
                        <span className="text-emerald-400 select-none">$ </span>{cmd}
                      </code>
                    </td>
                    <td className="p-3 text-muted-foreground">{what}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Section>

        <Section id="upgrade" icon={RefreshCw} title="Upgrade">
          <p className="text-muted-foreground">From the OPBX folder:</p>
          <CodeBlock
            code={[
              '# 1. Recommended: back up the database (to ./backups/)',
              './scripts/backup-database.sh',
              '# 2. Get the new version',
              'git pull',
              '# 3. Update your docker-compose.yml (asks before each change)',
              './scripts/update-compose.sh',
              '# 4. Rebuild and start the new version',
              './opbx.sh upgrade',
            ].join('\n')}
          />
          <p className="text-muted-foreground"><code className="rounded bg-card px-1">./opbx.sh upgrade</code> then:</p>
          <ol className="space-y-1 list-decimal list-inside text-muted-foreground">
            {UPGRADE_STEPS.map((s) => <li key={s}>{s}</li>)}
          </ol>
          <p className="text-muted-foreground">
            The first start after an upgrade can take several minutes while the database is updated; the app&apos;s log is
            shown meanwhile. Your settings, data and recordings are kept.
          </p>
        </Section>

        <Section id="change" icon={Wrench} title="Change settings or start over">
          <CodeBlock
            code={[
              '# Change the address, the front page, the allowed host names,',
              '# the firewall (on/off, monitor/block) or its console access',
              './install.sh reconfigure',
              '',
              '# Delete all data and settings, then install again',
              './install.sh reset',
            ].join('\n')}
          />
          <p className="text-muted-foreground">
            <span className="font-semibold text-foreground">reset deletes everything</span>: the database, recordings, logs
            and settings. It lists what it will delete, offers a database backup first, and asks you to type the domain
            (or <code className="rounded bg-card px-1">RESET</code> on a development machine) to confirm.
          </p>
        </Section>

        <Section id="troubleshooting" icon={Wrench} title="Troubleshooting">
          <div className="space-y-4">
            {TROUBLE.map(({ q, a, code, ports }) => (
              <div key={q} className="rounded-lg border border-border bg-card p-4">
                <p className="font-semibold mb-1">{q}</p>
                <p className="text-sm text-muted-foreground">{a}</p>
                {code && <CodeBlock code={code} />}
                {ports && (
                  <div className="mt-3 overflow-x-auto rounded-md border border-border">
                    <table className="w-full text-sm">
                      <caption className="sr-only">Local ports used by a development installation</caption>
                      <thead className="bg-background/60 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                          <th scope="col" className="p-2">Port</th>
                          <th scope="col" className="p-2">Service</th>
                          <th scope="col" className="p-2">Purpose</th>
                          <th scope="col" className="p-2">.env variable</th>
                        </tr>
                      </thead>
                      <tbody>
                        {DEV_PORTS.map(([port, service, purpose, variable]) => (
                          <tr key={port} className="border-t border-border">
                            <td className="p-2 font-mono">{port}</td>
                            <td className="p-2 whitespace-nowrap">{service}</td>
                            <td className="p-2 text-muted-foreground">{purpose}</td>
                            <td className="p-2 font-mono text-xs">{variable}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            ))}
          </div>
          <p className="text-muted-foreground">
            More detail, including a manual setup without the installer, is in the{' '}
            <a href={DOCS} target="_blank" rel="noopener noreferrer" className="underline hover:text-foreground">installation guide</a>.
          </p>
        </Section>
      </main>
      <Footer />
    </div>
  );
}
