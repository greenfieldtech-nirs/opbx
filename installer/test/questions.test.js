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
  true, 'block', '/var/lib/safeline', '9443', '198.51.100.0/24',
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
    logs: true, waf: { mode: 'block', stateDir: '/var/lib/safeline', consolePort: 9443, consoleAllow: '198.51.100.0/24' }, ngrokToken: '', advanced: {},
  });
  deriveEnv(a); // derive accepts it
});

test('port is re-asked while portCheck says false; throw shows message, offers to keep it, re-asks on no', async () => {
  const script = ['production', 'pbx.example.com', '80', '81', false, '82', '', 'Acme', 'A', 'a@b.co', 'single', 'none', false, false, false, false, 'off', false];
  const seen = [];
  const { ui, log } = fakeUi(script);
  const checks = [false, 'throw', true];
  const a = await askAnswers(ui, { platform: {}, portCheck: async (p) => { seen.push(p); const r = checks.shift(); if (r === 'throw') throw new Error('docker not reachable'); return r; } });
  assert.deepEqual(seen, [80, 81, 82]);
  assert.equal(a.httpPort, 82);
  assert.ok(log.notes.some((n) => n.includes('docker not reachable')));
  assert.ok(log.messages.includes('Use port 81 anyway?'));
});

test('a probe that keeps failing does not loop: the user can keep the port', async () => {
  const { ui, left } = fakeUi(['production', 'pbx.example.com', '8080', true, '', 'Acme', 'A', 'a@b.co', 'single', 'none', false, false, false, false, 'off', false]);
  let probes = 0;
  const a = await askAnswers(ui, { platform: {}, portCheck: async () => { probes++; throw new Error('docker not reachable'); } });
  assert.equal(a.httpPort, 8080);
  assert.equal(probes, 1);
  assert.equal(left(), 0);
});

test('WAF console port: asked only with the WAF, re-asked while busy, cannot be the OPBX port', async () => {
  const { ui, log, left } = fakeUi(['production', 'pbx.example.com', '8080', '', 'Acme', 'A', 'a@b.co', 'single', 'none', false, false, false, false,
    'monitor', '/var/lib/safeline', '8080', '9443', '9555', false]);
  const seen = [];
  const a = await askAnswers(ui, { platform: {}, portCheck: async (p) => { seen.push(p); return p !== 9443; } });
  assert.equal(left(), 0);
  assert.equal(a.waf.consolePort, 9555);
  assert.deepEqual(seen, [8080, 9443, 9555]);
  assert.match(log.rejected[0][1], /OPBX/);
  const off = fakeUi(['production', 'pbx.example.com', '80', '', 'Acme', 'A', 'a@b.co', 'single', 'none', false, false, false, false, 'off', false]);
  await askAnswers(off.ui, { platform: {}, portCheck: null });
  assert.ok(!off.log.messages.some((m) => /console use/.test(m)));
});

test('portCheck null skips the check', async () => {
  const { ui } = fakeUi(['development', '8080', 'Acme', 'A', 'a@b.co', 'single', 'none', false, false, false, false, 'off', '', false]);
  const a = await askAnswers(ui, { platform: {}, portCheck: null });
  assert.equal(a.httpPort, 8080);
  assert.equal(a.publicUrl, 'http://localhost:8080'); // development on another port: the address carries it
});

test('console allowlist not asked without a proxy; the login-only note is shown', async () => {
  const { ui, log, left } = fakeUi(['production', 'pbx.example.com', '80', '', 'Acme', 'A', 'a@b.co', 'single', 'none', false, false, false, false, 'monitor', '/var/lib/safeline', '9443', false]);
  const a = await askAnswers(ui, { platform: {}, portCheck: null });
  assert.equal(left(), 0);
  assert.deepEqual(a.waf, { mode: 'monitor', stateDir: '/var/lib/safeline', consolePort: 9443, consoleAllow: '' });
  assert.ok(!log.messages.some((m) => m.includes('may open the firewall console')));
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
    usercheck: { block: { spam: true } }, logs: true, waf: { mode: 'block', stateDir: '/srv/sl', consolePort: 9555, consoleAllow: '1.2.3.4' },
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
  assert.deepEqual(a.waf, { mode: 'block', stateDir: '/srv/sl', consolePort: 9555, consoleAllow: '1.2.3.4' });
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

test('development + email provider: no unusable From default, address accepted', async () => {
  const seen = [];
  const { ui } = fakeUi(['development', '8080', 'Acme', 'A', 'a@b.co', 'single', 'mailerlite', 'k', 'me@example.com', 'Acme', false, false, false, false, 'off', '', false]);
  const orig = ui.text; ui.text = (o) => { seen.push(o.initialValue); return orig(o); };
  const a = await askAnswers(ui, { platform: {}, portCheck: null });
  assert.equal(a.email.fromAddress, 'me@example.com');
  assert.ok(!seen.some((v) => /localhost/.test(v ?? '')));
});

test('blank secret keeps the default; blank without default stays invalid', async () => {
  const defaults = { mode: 'production', publicUrl: 'https://pbx.example.com', httpPort: 80, orgName: 'Acme', ownerName: 'A', ownerEmail: 'a@b.co',
    email: { provider: 'sendinblue', fields: { SENDINBLUE_API_KEY: 'brevo-key' }, fromAddress: 'n@example.com', fromName: 'Acme' },
    auth0: { domain: 'd', clientId: 'c', clientSecret: 'a0-secret', providers: ['google'] },
    recaptcha: { siteKey: 's', secretKey: 'rc-secret', minScore: 0.5 }, usercheck: { token: 'uc-token', block: {} } };
  const prompts = [];
  const ui = {
    select: async (o) => o.initialValue, text: async (o) => o.initialValue, confirm: async () => true, multiselect: async (o) => o.initialValues, note() {},
    password: async (o) => { prompts.push(o); assert.equal(o.validate(''), undefined); return ''; },
  };
  const a = await askAnswers(ui, { platform: {}, defaults, portCheck: null });
  assert.equal(a.email.fields.SENDINBLUE_API_KEY, 'brevo-key');
  assert.equal(a.auth0.clientSecret, 'a0-secret');
  assert.equal(a.recaptcha.secretKey, 'rc-secret');
  assert.equal(a.usercheck.token, 'uc-token');
  assert.ok(prompts.every((o) => /leave blank to keep/.test(o.message)));
  // no default: blank is refused
  const { ui: f, log } = fakeUi(['production', 'pbx.example.com', '80', '', 'Acme', 'A', 'a@b.co', 'single', 'sendinblue', '', 'real', 'n@example.com', 'Acme', false, false, false, false, 'off', false]);
  await askAnswers(f, { platform: {}, portCheck: null });
  assert.equal(log.rejected.length, 1);
});

test('defaults are not mutated; port re-ask defaults to the last entry', async () => {
  const defaults = Object.freeze({ httpPort: 80 });
  const initials = [];
  const { ui } = fakeUi(['production', 'pbx.example.com', '8081', '8082', '', 'Acme', 'A', 'a@b.co', 'single', 'none', false, false, false, false, 'off', false]);
  const orig = ui.text; ui.text = (o) => { initials.push(o.initialValue); return orig(o); };
  const checks = [false, true];
  await askAnswers(ui, { platform: {}, defaults, portCheck: async () => checks.shift() });
  assert.deepEqual(defaults, { httpPort: 80 });
  assert.equal(initials[1], '80');
  assert.equal(initials[2], '8081');
});

const review = async (answers) => {
  const { ui, log } = fakeUi(['edit']);
  const r = await reviewAnswers(ui, answers);
  return { r, text: log.notes.join('\n') };
};
const SECRETS = ['SEKRET-mailgun-9', 'SEKRET-auth0-9', 'SEKRET-recaptcha-9', 'SEKRET-usercheck-9', 'SEKRET-ngrok-9'];
const withSecrets = (a) => ({ ...a, email: { ...a.email, fields: { ...a.email.fields, MAILGUN_SECRET: SECRETS[0] } },
  auth0: { ...a.auth0, clientSecret: SECRETS[1] }, recaptcha: { ...a.recaptcha, secretKey: SECRETS[2] }, usercheck: { ...a.usercheck, token: SECRETS[3] }, ngrokToken: SECRETS[4] });

test('review shows no secret value, maps select to edit/confirm, covers development', async () => {
  const prodAns = withSecrets(await askAnswers(fakeUi(FULL).ui, { platform: {}, portCheck: null }));
  const p = await review(prodAns);
  assert.equal(p.r, 'edit');
  for (const s of SECRETS) assert.ok(!p.text.includes(s), s);
  assert.match(p.text, /pbx\.example\.com/);
  const { ui } = fakeUi(['confirm']);
  assert.equal(await reviewAnswers(ui, prodAns), 'confirm');

  const dev = withSecrets({ ...prodAns, mode: 'development', publicUrl: 'http://localhost', trustedProxyCidr: '' });
  const d = await review(dev);
  for (const s of SECRETS) assert.ok(!d.text.includes(s), s);
  assert.match(d.text, /Development/);
  assert.match(d.text, /ngrok: token set/);
  assert.doesNotMatch(d.text, /Proxy:/);
});

test('development on port 80 keeps http://localhost', async () => {
  const { ui } = fakeUi(['development', '80', 'Acme', 'A', 'a@b.co', 'single', 'none', false, false, false, false, 'off', '', false]);
  assert.equal((await askAnswers(ui, { platform: {}, portCheck: null })).publicUrl, 'http://localhost');
});
