import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { parseEnv } from './envfile.js';

const SQLI = 'http://127.0.0.1/?id=1%27%20OR%20%271%27=%271';

// Upgrade request against nginx on the stack network; prints the status line.
const wsScript = (key) => `const s=require('net').connect(80,'opbx_nginx');s.setTimeout(8000,()=>{console.log('timeout');process.exit(1)});s.on('error',e=>{console.log(e.message);process.exit(1)});s.on('data',d=>{console.log(String(d).split('\\r\\n')[0]);process.exit(0)});s.write('GET /app/${key}?protocol=7 HTTP/1.1\\r\\nHost: localhost\\r\\nConnection: Upgrade\\r\\nUpgrade: websocket\\r\\nSec-WebSocket-Version: 13\\r\\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\\r\\n\\r\\n')`;

function loadEnv() {
  try { return parseEnv(readFileSync(join(process.env.OPBX_REPO || process.cwd(), '.env'), 'utf8')); } catch { return new Map(); }
}

export async function runChecks(docker, answers, { env = loadEnv() } = {}) {
  const out = [];
  const check = async (name, fn) => {
    try { out.push({ name, ...(await fn()) }); } catch (e) { out.push({ name, ok: false, detail: String(e.message || e) }); }
  };
  const detail = (r) => `${r.stdout}\n${r.stderr}`.trim().slice(-400);
  const inNginx = (cmd) => docker.run(['exec', 'opbx_nginx', 'sh', '-c', cmd]);
  const status = (r) => Number(/HTTP\/\d(?:\.\d)?\s+(\d{3})/.exec(`${r.stderr}\n${r.stdout}`)?.[1]) || 0;

  await check('services', async () => { // only services of the active COMPOSE_FILE/PROFILES; leftovers of inactive profiles are ignored
    const [cfg, ps] = await Promise.all([docker.compose(['config', '--services']), docker.compose(['ps', '--all', '--format', 'json'])]);
    if (cfg.code !== 0 || ps.code !== 0) return { ok: false, detail: detail(cfg.code !== 0 ? cfg : ps) };
    const t = ps.stdout.trim();
    const rows = t.startsWith('[') ? JSON.parse(t) : t.split('\n').filter(Boolean).map((l) => JSON.parse(l));
    const bad = [];
    for (const svc of cfg.stdout.split('\n').map((x) => x.trim()).filter(Boolean)) {
      const mine = rows.filter((r) => r.Service === svc);
      if (!mine.some((r) => r.State === 'running' && (!r.Health || r.Health === 'healthy'))) bad.push(`${svc} (${mine.map((r) => `${r.State}${r.Health ? `/${r.Health}` : ''}`).join(',') || 'no container'})`);
    }
    return bad.length ? { ok: false, detail: `not running/healthy: ${bad.join(', ')}` } : { ok: true, detail: '' };
  });
  await check('nginx /up', async () => { // Laravel's health page; the SPA must not answer here
    const r = await inNginx('wget -qO- http://127.0.0.1/up');
    return { ok: r.code === 0 && r.stdout.includes('Application up'), detail: detail(r).slice(-200) };
  });
  await check('SPA /', async () => { const r = await inNginx('wget -qO- http://127.0.0.1/'); return { ok: r.code === 0 && /<html|<!doctype/i.test(r.stdout), detail: detail(r).slice(0, 200) }; });
  await check('API /api/v1/', async () => { // any HTTP answer below 500 counts (wget exits non-zero on 401/404)
    const r = await inNginx('wget -S -qO /dev/null http://127.0.0.1/api/v1/ 2>&1');
    const st = status(r); return { ok: st > 0 && st < 500, detail: detail(r) };
  });
  await check('websocket', async () => {
    const key = env.get('PUSHER_APP_KEY');
    if (!key) return { ok: false, detail: 'PUSHER_APP_KEY missing from .env' };
    const n = await docker.run(['inspect', 'opbx_nginx', '--format', '{{json .NetworkSettings.Networks}}']);
    const net = Object.keys(JSON.parse(n.stdout || '{}')).find((k) => k.endsWith('_opbx'));
    if (!net) return { ok: false, detail: `no *_opbx network on opbx_nginx: ${detail(n)}` };
    const r = await docker.run(['run', '--rm', '--network', net, 'node:26-alpine', 'node', '-e', wsScript(key)]);
    return { ok: / 101 /.test(r.stdout), detail: detail(r) };
  });
  await check('storage', async () => { // creates missing buckets, then a write/read/delete probe; safe to repeat
    const r = await docker.compose(['exec', '-T', 'app', 'php', 'artisan', 'storage:initialize', '--wait=10']);
    return { ok: r.code === 0, detail: detail(r) };
  });
  await check('config:validate', async () => { const r = await docker.compose(['exec', '-T', 'app', 'php', 'artisan', 'config:validate']); return { ok: r.code === 0, detail: detail(r) }; });

  if (answers.waf) {
    await check('waf detection', async () => {
      if (answers.waf.mode === 'block') {
        const r = await inNginx(`wget -S -qO /dev/null '${SQLI}' 2>&1`);
        return { ok: status(r) === 403, detail: detail(r) };
      }
      const count = async () => Number((await inNginx('grep -c safeline-waf /var/log/nginx/opbx_error.log')).stdout.trim()) || 0;
      const before = await count();
      await inNginx(`wget -qO /dev/null '${SQLI}'`);
      const after = await count();
      return { ok: after > before, detail: after > before ? `${after - before} new safeline-waf line(s)` : 'no new safeline-waf line in opbx_error.log' };
    });
    if (env.get('WAF_CONSOLE_ENABLED') !== 'false') {
      await check('waf console', async () => {
        const r = await inNginx('wget -qO- http://127.0.0.1:8443/');
        if (r.code !== 0 || !r.stdout.length) return { ok: false, detail: detail(r).slice(0, 200) };
        const port = String(answers.waf.consolePort ?? (env.get('WAF_CONSOLE_PORT') || 9443));
        const p = await docker.run(['port', 'opbx_nginx', '8443/tcp']); // published host side, e.g. 0.0.0.0:9443
        const ok = p.stdout.split('\n').some((l) => l.trim().endsWith(`:${port}`));
        return { ok, detail: ok ? `published on ${port}` : `expected host port ${port}, got: ${detail(p) || 'not published'}` };
      });
    }
  }
  if (answers.logs) {
    await check('grafana', async () => { const r = await inNginx('wget -qO- http://127.0.0.1/logs/api/health'); return { ok: r.code === 0 && /ok/i.test(r.stdout), detail: detail(r) }; });
  }
  return out;
}
