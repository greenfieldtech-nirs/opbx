import { test } from 'node:test';
import assert from 'node:assert/strict';
import { askAnswers, reviewAnswers } from '../src/questions.js';
import { deriveEnv } from '../src/derive.js';

// Scripted ui: each prompt pops the next answer; a text answer rejected by validate() is
// recorded and the following answer is used instead (like re-prompting).
function fakeUi(script) {
  const q = [...script];
  const log = { messages: [], rejected: [], notes: [] };
  const pop = (o) => {
    for (;;) {
      assert.ok(q.length, `script exhausted at: ${o.message}`);
      const v = q.shift();
      const err = o.validate?.(v);
      if (!err) { log.messages.push(o.message); return v; }
      log.rejected.push([v, err]);
    }
  };
  const ui = {
    text: async (o) => pop(o), password: async (o) => pop(o), select: async (o) => pop(o),
    confirm: async (o) => pop(o), multiselect: async (o) => pop(o),
    note: (t, b) => log.notes.push(`${t}\n${b}`),
  };
  return { ui, log, left: () => q.length };
}

const FULL = [
  'production', 'https://pbx.example.com', '80', '10.0.0.0/8', 'Acme', 'Ann Lee', 'ann@example.com', 'multi',
  'mailgun', 'mg.example.com', 'key-1', 'api.mailgun.net', 'us', 'noreply@example.com', 'Acme PBX',
  true, 't.auth0.com', 'cid', 'sec', ['google'],
  true, 'sitekey', 'secretkey', '0.7',
  true, 'tok', ['disposable', 'spam'],
  true, 'block', '/var/lib/safeline', '198.51.100.0/24',
  false,
];

test('full production run produces the Answers shape', async () => {
  const { ui, left } = fakeUi(FULL);
  const a = await askAnswers(ui, { platform: {}, portCheck: async () => true });
  assert.equal(left(), 0);
  assert.deepEqual(a, {
    mode: 'production', publicUrl: 'https://pbx.example.com', httpPort: 80, trustedProxyCidr: '10.0.0.0/8',
    orgName: 'Acme', ownerName: 'Ann Lee', ownerEmail: 'ann@example.com', tenancy: 'multi',
    email: { provider: 'mailgun', fields: { MAILGUN_DOMAIN: 'mg.example.com', MAILGUN_SECRET: 'key-1', MAILGUN_ENDPOINT: 'api.mailgun.net', MAILGUN_REGION: 'us' }, fromAddress: 'noreply@example.com', fromName: 'Acme PBX' },
    auth0: { domain: 't.auth0.com', clientId: 'cid', clientSecret: 'sec', providers: ['google'] },
    recaptcha: { siteKey: 'sitekey', secretKey: 'secretkey', minScore: 0.7 },
    usercheck: { token: 'tok', block: { disposable: true, blocklisted: false, spam: true, role_accounts: false, relay_domains: false, public_domains: false } },
    logs: true, waf: { mode: 'block', stateDir: '/var/lib/safeline', consoleAllow: '198.51.100.0/24' }, ngrokToken: '', advanced: {},
  });
  deriveEnv(a); // derive accepts it
});

test('port is re-asked while portCheck says false; throw shows message and re-asks', async () => {
  const script = ['production', 'pbx.example.com', '80', '81', '82', '', 'Acme', 'A', 'a@b.co', 'single', 'none', false, false, false, false, 'off', false];
  const seen = [];
  const { ui, log } = fakeUi(script);
  const checks = [false, 'throw', true];
  const a = await askAnswers(ui, { platform: {}, portCheck: async (p) => { seen.push(p); const r = checks.shift(); if (r === 'throw') throw new Error('docker not reachable'); return r; } });
  assert.deepEqual(seen, [80, 81, 82]);
  assert.equal(a.httpPort, 82);
  assert.ok(log.notes.some((n) => n.includes('docker not reachable')));
});

test('portCheck null skips the check', async () => {
  const { ui } = fakeUi(['development', '8080', 'Acme', 'A', 'a@b.co', 'single', 'none', false, false, false, false, 'off', '', false]);
  const a = await askAnswers(ui, { platform: {}, portCheck: null });
  assert.equal(a.httpPort, 8080);
  assert.equal(a.publicUrl, 'http://localhost');
});

test('console allowlist not asked without a proxy; the login-only note is shown', async () => {
  const { ui, log, left } = fakeUi(['production', 'pbx.example.com', '80', '', 'Acme', 'A', 'a@b.co', 'single', 'none', false, false, false, false, 'monitor', '/var/lib/safeline', false]);
  const a = await askAnswers(ui, { platform: {}, portCheck: null });
  assert.equal(left(), 0);
  assert.deepEqual(a.waf, { mode: 'monitor', stateDir: '/var/lib/safeline', consoleAllow: '' });
  assert.ok(!log.messages.some((m) => m.includes('firewall console')));
  assert.ok(log.notes.some((n) => /login only/.test(n)));
});

test('single-company shows the open sign-up warning', async () => {
  const { ui, log } = fakeUi(['production', 'pbx.example.com', '80', '', 'Acme', 'A', 'a@b.co', 'single', 'none', false, false, false, false, 'off', false]);
  await askAnswers(ui, { platform: {}, portCheck: null });
  assert.ok(log.notes.some((n) => /create their own organization/.test(n)));
});

test('free text with newline or both $ and apostrophe is rejected at the prompt', async () => {
  const { ui, log } = fakeUi(['production', 'pbx.example.com', '80', '', "a$'b", 'a\nb', 'Acme', 'A', 'a@b.co', 'single', 'none', false, false, false, false, 'off', false]);
  const a = await askAnswers(ui, { platform: {}, portCheck: null });
  assert.equal(a.orgName, 'Acme');
  assert.equal(log.rejected.length, 2);
});

test('invalid address, http in production, bad email are rejected', async () => {
  const { ui, log } = fakeUi(['production', 'http://pbx.example.com', 'localhost', 'pbx.example.com', '80', '', 'Acme', 'A', 'nope', 'a@b.co', 'single', 'none', false, false, false, false, 'off', false]);
  const a = await askAnswers(ui, { platform: {}, portCheck: null });
  assert.equal(a.publicUrl, 'https://pbx.example.com');
  assert.equal(log.rejected.length, 3);
});

test('defaults pre-fill every non-secret answer', async () => {
  const defaults = JSON.parse(JSON.stringify({
    mode: 'production', publicUrl: 'https://pbx.example.com', httpPort: 8080, trustedProxyCidr: '10.0.0.0/8',
    orgName: 'Acme', ownerName: 'Ann', ownerEmail: 'ann@example.com', tenancy: 'multi',
    email: { provider: 'mailjet', fields: { MAILJET_APIKEY: 'pub' }, fromAddress: 'n@example.com', fromName: 'Acme' },
    auth0: { domain: 't.auth0.com', clientId: 'cid', providers: ['github'] }, recaptcha: { siteKey: 'sk', minScore: 0.9 },
    usercheck: { block: { spam: true } }, logs: true, waf: { mode: 'block', stateDir: '/srv/sl', consoleAllow: '1.2.3.4' },
  }));
  const initial = [];
  const ui = {
    select: async (o) => { initial.push(o.initialValue); return o.initialValue; },
    text: async (o) => { initial.push(o.initialValue); return o.initialValue; },
    password: async () => 's', confirm: async (o) => { initial.push(o.initialValue); return o.initialValue; },
    multiselect: async (o) => o.initialValues, note() {},
  };
  const a = await askAnswers(ui, { platform: {}, defaults, portCheck: null });
  assert.deepEqual(initial.slice(0, 9), ['production', 'https://pbx.example.com', '8080', '10.0.0.0/8', 'Acme', 'Ann', 'ann@example.com', 'multi', 'mailjet']);
  assert.equal(a.email.fields.MAILJET_APIKEY, 'pub');
  assert.equal(a.email.fromAddress, 'n@example.com');
  assert.equal(a.auth0.domain, 't.auth0.com');
  assert.deepEqual(a.auth0.providers, ['github']);
  assert.equal(a.recaptcha.minScore, 0.9);
  assert.deepEqual(a.waf, { mode: 'block', stateDir: '/srv/sl', consoleAllow: '1.2.3.4' });
  assert.equal(a.logs, true);
});

test('advanced settings keep only changed values', async () => {
  const items = 4 + 9 + 2 + 3;
  const answers = ['60', '10', '60', '5', '30', '90', '90', '90', '183', '183', '183', '30', '365', '1800', '900', '5', '30', '60'];
  answers[0] = '120'; // API limit changed
  assert.equal(answers.length, items);
  const { ui } = fakeUi(['production', 'pbx.example.com', '80', '', 'Acme', 'A', 'a@b.co', 'single', 'none', false, false, false, false, 'off', true, ...answers, 'debug', 'info', 'info']);
  const a = await askAnswers(ui, { platform: {}, portCheck: null });
  assert.deepEqual(a.advanced, { RATE_LIMIT_API: '120', LOG_LEVEL: 'debug' });
});

test('review hides secrets and returns the choice', async () => {
  const { ui, log } = fakeUi(['edit']);
  const a = { ...(await askAnswers(fakeUi(FULL).ui, { platform: {}, portCheck: null })) };
  const r = await reviewAnswers(ui, a);
  assert.equal(r, 'edit');
  const shown = log.notes.join('\n');
  for (const s of ['key-1', 'secretkey', 'tok', '"sec"']) assert.ok(!shown.includes(s), s);
  assert.match(shown, /pbx\.example\.com/);
});
