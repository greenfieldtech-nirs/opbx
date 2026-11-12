import { test } from 'node:test';
import assert from 'node:assert/strict';
import { deriveEnv, OWNED_KEYS } from '../src/derive.js';

const base = (o = {}) => ({
  mode: 'production', publicUrl: 'https://pbx.example.com', httpPort: 80, trustedProxyCidr: '',
  orgName: 'Acme', ownerName: 'Ann', ownerEmail: 'ann@example.com', tenancy: 'single',
  email: { provider: 'none', fields: {}, fromAddress: '', fromName: '' },
  auth0: null, recaptcha: null, usercheck: null, logs: false, waf: null, ngrokToken: '', advanced: {}, ...o,
});
const has = (env, exp) => { for (const [k, v] of Object.entries(exp)) assert.equal(env[k], v, k); };

test('production + single', () => {
  const e = deriveEnv(base());
  has(e, {
    APP_ENV: 'production', APP_DEBUG: 'false', APP_URL: 'https://pbx.example.com', FRONTEND_URL: 'https://pbx.example.com',
    APP_PORT: '80', SANCTUM_STATEFUL_DOMAINS: 'pbx.example.com', SESSION_DOMAIN: 'pbx.example.com',
    VITE_API_BASE_URL: '/api/v1', VITE_WS_HOST: 'pbx.example.com', VITE_WS_PORT: '443', VITE_WS_SCHEME: 'https',
    VITE_ALLOWED_HOSTS: 'pbx.example.com', GRAFANA_ROOT_URL: 'https://pbx.example.com/logs/',
    OPCACHE_VALIDATE_TIMESTAMPS: '0', OPBX_INSTALLER_MANAGED: 'true',
    COMPOSE_FILE: 'docker-compose.yml:docker-compose.prod.yml', COMPOSE_PROFILES: '', COMPOSE_PARALLEL_LIMIT: '2',
    LOG_STACK_ENABLED: 'false', TRUSTED_PROXY_CIDR: '', WAF_MODE: 'off', WAF_CONSOLE_ENABLED: 'false', WAF_CONSOLE_ALLOW: '',
    OPBX_APPLICATION_MODE: 'development', OPBX_SAAS_ENABLED: 'false', RECAPTCHA_ENABLED: 'false', USERCHECK_ENABLED: 'false',
    MAILGUN_ENABLED: 'false', MAILJET_ENABLED: 'false', MAILERLITE_ENABLED: 'false', SENDINBLUE_ENABLED: 'false',
  });
  assert.equal(e.OPBX_APPLICATION_WEBHOOK_BASEURL, '');
  assert.ok(!('WAF_CONSOLE_PORT' in e));
  assert.ok(!('NGROK_AUTHTOKEN' in e));
});

test('production + non-default port in public URL', () => {
  const e = deriveEnv(base({ publicUrl: 'https://pbx.example.com:8443', httpPort: 8080 }));
  has(e, { APP_PORT: '8080', VITE_WS_PORT: '8443', SESSION_DOMAIN: 'pbx.example.com', SANCTUM_STATEFUL_DOMAINS: 'pbx.example.com,pbx.example.com:8443', VITE_ALLOWED_HOSTS: 'pbx.example.com' });
});

test('production + multi + auth0 + WAF block + logs + proxy + mailgun + advanced', () => {
  const e = deriveEnv(base({
    tenancy: 'multi', trustedProxyCidr: '10.0.0.0/8', logs: true,
    auth0: { domain: 't.auth0.com', clientId: 'cid', clientSecret: 's3c', providers: ['google', 'github'] },
    waf: { mode: 'block', stateDir: '/var/lib/safeline', consoleAllow: '198.51.100.0/24' },
    recaptcha: { siteKey: 'sk', secretKey: 'sec', minScore: 0.7 },
    usercheck: { token: 'tok', block: { disposable: true, public_domains: true } },
    email: { provider: 'mailgun', fields: { MAILGUN_DOMAIN: 'mg.example.com', MAILGUN_SECRET: 'k', MAILGUN_REGION: '' }, fromAddress: 'noreply@example.com', fromName: 'Acme PBX' },
    advanced: { LOG_LEVEL: 'debug', APP_ENV: 'staging' },
  }));
  has(e, {
    OPBX_APPLICATION_MODE: 'production', OPBX_APPLICATION_WEBHOOK_BASEURL: 'https://pbx.example.com',
    OPBX_SAAS_ENABLED: 'true', AUTH0_DOMAIN: 't.auth0.com', AUTH0_CLIENT_ID: 'cid', AUTH0_CLIENT_SECRET: 's3c',
    AUTH0_REDIRECT_URI: 'https://pbx.example.com/ui/auth/callback', AUTH0_PROVIDERS: 'google,github',
    COMPOSE_FILE: 'docker-compose.yml:docker-compose.prod.yml:docker-compose.waf.yml', COMPOSE_PROFILES: 'logs', LOG_STACK_ENABLED: 'true',
    TRUSTED_PROXY_CIDR: '10.0.0.0/8', WAF_MODE: 'block', WAF_CONSOLE_ENABLED: 'true', WAF_CONSOLE_PORT: '9443', WAF_CONSOLE_ALLOW: '198.51.100.0/24',
    RECAPTCHA_ENABLED: 'true', RECAPTCHA_SITE_KEY: 'sk', RECAPTCHA_SECRET_KEY: 'sec', RECAPTCHA_MIN_SCORE: '0.7',
    USERCHECK_ENABLED: 'true', USERCHECK_API_TOKEN: 'tok', USERCHECK_BLOCK_DISPOSABLE: 'true', USERCHECK_BLOCK_PUBLIC_DOMAINS: 'true',
    EMAIL_PROVIDER: 'mailgun', MAILGUN_ENABLED: 'true', MAILGUN_DOMAIN: 'mg.example.com', MAILGUN_SECRET: 'k',
    MAILJET_ENABLED: 'false', MAILERLITE_ENABLED: 'false', SENDINBLUE_ENABLED: 'false',
    MAIL_FROM_ADDRESS: 'noreply@example.com', MAIL_FROM_NAME: 'Acme PBX',
    LOG_LEVEL: 'debug', APP_ENV: 'staging', // advanced merged last
  });
  assert.ok(!('MAILGUN_REGION' in e), 'empty provider fields are not emitted');
});

test('production + WAF without proxy: console allow-list stays empty', () => {
  const e = deriveEnv(base({ waf: { mode: 'monitor', stateDir: '/x', consoleAllow: '1.2.3.4' } }));
  has(e, { WAF_MODE: 'monitor', WAF_CONSOLE_ENABLED: 'true', WAF_CONSOLE_PORT: '9443', WAF_CONSOLE_ALLOW: '', TRUSTED_PROXY_CIDR: '' });
});

test('email provider sendinblue enables only its flag', () => {
  const e = deriveEnv(base({ email: { provider: 'sendinblue', fields: { SENDINBLUE_API_KEY: 'k' }, fromAddress: '', fromName: '' } }));
  has(e, { EMAIL_PROVIDER: 'sendinblue', SENDINBLUE_ENABLED: 'true', SENDINBLUE_API_KEY: 'k', MAILGUN_ENABLED: 'false' });
  assert.ok(!('MAIL_FROM_ADDRESS' in e));
});

test('development + ngrok', () => {
  const e = deriveEnv(base({ mode: 'development', publicUrl: 'http://localhost', ngrokToken: 'ngtok', logs: true }));
  has(e, {
    APP_ENV: 'local', APP_DEBUG: 'true', APP_URL: 'http://localhost', FRONTEND_URL: 'http://localhost:3000',
    VITE_WS_HOST: 'localhost', VITE_WS_PORT: '6001', VITE_WS_SCHEME: 'http', SANCTUM_STATEFUL_DOMAINS: 'localhost,localhost:3000',
    VITE_ALLOWED_HOSTS: 'localhost', OPCACHE_VALIDATE_TIMESTAMPS: '1', COMPOSE_FILE: 'docker-compose.yml:docker-compose.ports.yml',
    COMPOSE_PROFILES: 'dev,logs', NGROK_AUTHTOKEN: 'ngtok', OPBX_INSTALLER_MANAGED: 'true', SESSION_DOMAIN: 'null',
    GRAFANA_ROOT_URL: 'http://localhost/logs/',
  });
});

test('development without ngrok has no dev profile', () => {
  const e = deriveEnv(base({ mode: 'development', publicUrl: 'http://localhost' }));
  assert.equal(e.COMPOSE_PROFILES, '');
  assert.ok(!('NGROK_AUTHTOKEN' in e));
});

test('OWNED_KEYS', () => {
  assert.ok(OWNED_KEYS.domain.includes('APP_URL') && OWNED_KEYS.domain.includes('VITE_WS_HOST'));
  assert.ok(OWNED_KEYS.waf.includes('WAF_MODE') && OWNED_KEYS.waf.includes('WAF_CONSOLE_PORT') && OWNED_KEYS.waf.includes('COMPOSE_FILE'));
  // every key deriveEnv emits for a domain change is owned
  const e = deriveEnv(base());
  for (const k of ['APP_URL', 'FRONTEND_URL', 'SANCTUM_STATEFUL_DOMAINS', 'SESSION_DOMAIN', 'VITE_WS_HOST', 'GRAFANA_ROOT_URL']) assert.ok(k in e && OWNED_KEYS.domain.includes(k), k);
});

import { answersFromEnv } from '../src/derive.js';
import { parseEnv, renderEnv } from '../src/envfile.js';

test('answersFromEnv round-trips deriveEnv', () => {
  const a = base({
    tenancy: 'multi', trustedProxyCidr: '10.0.0.0/8', logs: true,
    auth0: { domain: 't.auth0.com', clientId: 'cid', clientSecret: 's3c', providers: ['google'] },
    recaptcha: { siteKey: 'sk', secretKey: 'rs', minScore: 0.7 },
    usercheck: { token: 'tok', block: { disposable: true, spam: false } },
    email: { provider: 'mailgun', fields: { MAILGUN_DOMAIN: 'mg.example.com', MAILGUN_SECRET: 'key' }, fromAddress: 'a@example.com', fromName: 'Acme' },
    waf: { mode: 'block', stateDir: '/var/lib/safeline', consoleAllow: '198.51.100.0/24' },
  });
  const env = new Map(Object.entries(deriveEnv(a)));
  const back = answersFromEnv(env, new Map([['SAFELINE_DIR', '/var/lib/safeline']]), { includeSecrets: true });
  const { orgName, ownerName, ownerEmail, advanced, ...want } = a;
  assert.deepEqual(back, want);
  const bare = answersFromEnv(env, new Map(), { includeSecrets: false });
  assert.equal(bare.auth0.clientSecret, undefined);
  assert.equal(bare.email.fields.MAILGUN_SECRET, undefined);
  assert.equal(bare.email.fields.MAILGUN_DOMAIN, 'mg.example.com');
});

test('answersFromEnv round-trips development with ngrok', () => {
  const a = base({ mode: 'development', publicUrl: 'http://localhost', ngrokToken: 'ng' });
  const back = answersFromEnv(new Map(Object.entries(deriveEnv(a))), new Map(), { includeSecrets: true });
  assert.equal(back.mode, 'development');
  assert.equal(back.ngrokToken, 'ng');
  assert.equal(back.publicUrl, 'http://localhost');
});
