const PROVIDERS = { mailgun: 'MAILGUN', mailjet: 'MAILJET', mailerlite: 'MAILERLITE', sendinblue: 'SENDINBLUE' };

// Keys reconfigure may rewrite (changing the domain / the WAF settings).
export const OWNED_KEYS = {
  domain: [
    'APP_URL', 'FRONTEND_URL', 'SANCTUM_STATEFUL_DOMAINS', 'SESSION_DOMAIN', 'VITE_WS_HOST', 'VITE_WS_PORT',
    'VITE_WS_SCHEME', 'VITE_ALLOWED_HOSTS', 'GRAFANA_ROOT_URL', 'AUTH0_REDIRECT_URI', 'OPBX_APPLICATION_WEBHOOK_BASEURL',
  ],
  waf: [
    'WAF_MODE', 'WAF_CONSOLE_ENABLED', 'WAF_CONSOLE_PORT', 'WAF_CONSOLE_BIND', 'WAF_CONSOLE_ALLOW', 'TRUSTED_PROXY_CIDR', 'COMPOSE_FILE',
  ],
};

const set = (env, obj) => { for (const [k, v] of Object.entries(obj)) if (v !== undefined && v !== '') env[k] = String(v); };

export function deriveEnv(a) {
  const u = new URL(a.publicUrl);
  const prod = a.mode === 'production';
  const host = u.hostname;
  const tls = u.protocol === 'https:';
  const files = ['docker-compose.yml', prod ? 'docker-compose.prod.yml' : 'docker-compose.ports.yml'];
  if (a.waf) files.push('docker-compose.waf.yml');
  const profiles = [!prod && a.ngrokToken ? 'dev' : null, a.logs ? 'logs' : null].filter(Boolean);

  const env = {
    APP_ENV: prod ? 'production' : 'local',
    APP_DEBUG: String(!prod),
    APP_URL: a.publicUrl,
    FRONTEND_URL: prod ? a.publicUrl : `${u.protocol}//${host}:3000`,
    APP_PORT: String(a.httpPort),
    SANCTUM_STATEFUL_DOMAINS: prod
      ? [host, u.port && `${host}:${u.port}`].filter(Boolean).join(',')
      : `${host},${host}:3000`,
    SESSION_DOMAIN: prod ? host : 'null',
    VITE_API_BASE_URL: '/api/v1',
    VITE_WS_HOST: host,
    VITE_WS_PORT: prod ? u.port || (tls ? '443' : '80') : '6001',
    VITE_WS_SCHEME: prod ? 'https' : 'http',
    VITE_ALLOWED_HOSTS: host,
    GRAFANA_ROOT_URL: `${a.publicUrl}/logs/`,
    OPCACHE_VALIDATE_TIMESTAMPS: prod ? '0' : '1',
    OPBX_INSTALLER_MANAGED: 'true',
    COMPOSE_FILE: files.join(':'),
    COMPOSE_PROFILES: profiles.join(','),
    COMPOSE_PARALLEL_LIMIT: '2',
    LOG_STACK_ENABLED: String(!!a.logs),
    TRUSTED_PROXY_CIDR: a.trustedProxyCidr || '',
    WAF_MODE: a.waf ? a.waf.mode : 'off',
    WAF_CONSOLE_ENABLED: String(!!a.waf),
    WAF_CONSOLE_ALLOW: a.waf && a.trustedProxyCidr ? a.waf.consoleAllow || '' : '',
    OPBX_APPLICATION_MODE: a.tenancy === 'multi' ? 'production' : 'development',
  };
  if (a.waf) {
    env.WAF_CONSOLE_PORT = String(a.waf.consolePort ?? 9443);
    env.WAF_CONSOLE_BIND = prod ? '0.0.0.0' : '127.0.0.1'; // development: this computer only
  }
  // empty (not absent) for single so a re-render clears a stale multi value
  env.OPBX_APPLICATION_WEBHOOK_BASEURL = a.tenancy === 'multi' ? a.publicUrl : '';
  if (!prod && a.ngrokToken) env.NGROK_AUTHTOKEN = a.ngrokToken;

  const au = a.auth0;
  env.OPBX_SAAS_ENABLED = String(!!au);
  if (au) {
    set(env, {
      AUTH0_DOMAIN: au.domain, AUTH0_CLIENT_ID: au.clientId, AUTH0_CLIENT_SECRET: au.clientSecret,
      AUTH0_REDIRECT_URI: `${a.publicUrl}/ui/auth/callback`, AUTH0_PROVIDERS: au.providers.join(','),
    });
  }

  env.RECAPTCHA_ENABLED = String(!!a.recaptcha);
  if (a.recaptcha) {
    set(env, { RECAPTCHA_SITE_KEY: a.recaptcha.siteKey, RECAPTCHA_SECRET_KEY: a.recaptcha.secretKey, RECAPTCHA_MIN_SCORE: a.recaptcha.minScore });
  }

  // the template ships USERCHECK_ENABLED=true, which blocks sign-up without a token
  env.USERCHECK_ENABLED = String(!!a.usercheck);
  if (a.usercheck) {
    set(env, { USERCHECK_API_TOKEN: a.usercheck.token });
    for (const [k, v] of Object.entries(a.usercheck.block || {})) env[`USERCHECK_BLOCK_${k.toUpperCase()}`] = String(v);
  }

  for (const [name, prefix] of Object.entries(PROVIDERS)) env[`${prefix}_ENABLED`] = String(a.email.provider === name);
  if (a.email.provider !== 'none') env.EMAIL_PROVIDER = a.email.provider;
  set(env, a.email.fields);
  set(env, { MAIL_FROM_ADDRESS: a.email.fromAddress, MAIL_FROM_NAME: a.email.fromName });

  return { ...env, ...a.advanced };
}

const EMAIL_FIELDS = {
  mailgun: [['MAILGUN_DOMAIN'], ['MAILGUN_SECRET', true], ['MAILGUN_ENDPOINT'], ['MAILGUN_REGION']],
  mailjet: [['MAILJET_APIKEY'], ['MAILJET_APISECRET', true]],
  mailerlite: [['MAILERLITE_API_KEY', true]],
  sendinblue: [['SENDINBLUE_API_KEY', true]],
};

// Inverse of deriveEnv for what the .env can tell; owner email/name/org and advanced limits are not recovered.
export function answersFromEnv(env, safelineEnv = new Map(), { includeSecrets = false } = {}) {
  const g = (k) => env.get(k) || '';
  const on = (k) => g(k) === 'true';
  const sec = (v) => (includeSecrets ? v : undefined);
  const a = {
    mode: g('APP_ENV') === 'production' ? 'production' : 'development',
    publicUrl: g('APP_URL'),
    httpPort: Number(g('APP_PORT')) || 80,
    trustedProxyCidr: g('TRUSTED_PROXY_CIDR'),
    tenancy: g('OPBX_APPLICATION_MODE') === 'production' ? 'multi' : 'single',
    logs: g('COMPOSE_PROFILES').split(',').includes('logs'),
    ngrokToken: sec(g('NGROK_AUTHTOKEN')) ?? '',
    auth0: null, recaptcha: null, usercheck: null, waf: null,
    email: { provider: 'none', fields: {}, fromAddress: '', fromName: '' },
  };
  const provider = g('EMAIL_PROVIDER');
  if (EMAIL_FIELDS[provider] && on(`${provider.toUpperCase()}_ENABLED`)) {
    a.email = { provider, fields: {}, fromAddress: g('MAIL_FROM_ADDRESS'), fromName: g('MAIL_FROM_NAME') };
    for (const [k, secret] of EMAIL_FIELDS[provider]) if (g(k) && (!secret || includeSecrets)) a.email.fields[k] = g(k);
  }
  if (on('OPBX_SAAS_ENABLED')) {
    a.auth0 = { domain: g('AUTH0_DOMAIN'), clientId: g('AUTH0_CLIENT_ID'), providers: g('AUTH0_PROVIDERS').split(',').filter(Boolean) };
    if (includeSecrets) a.auth0.clientSecret = g('AUTH0_CLIENT_SECRET');
  }
  if (on('RECAPTCHA_ENABLED')) {
    a.recaptcha = { siteKey: g('RECAPTCHA_SITE_KEY'), minScore: Number(g('RECAPTCHA_MIN_SCORE')) };
    if (includeSecrets) a.recaptcha.secretKey = g('RECAPTCHA_SECRET_KEY');
  }
  if (on('USERCHECK_ENABLED')) {
    a.usercheck = { block: {} };
    if (includeSecrets) a.usercheck.token = g('USERCHECK_API_TOKEN');
    for (const [k, v] of env) if (k.startsWith('USERCHECK_BLOCK_')) a.usercheck.block[k.slice(16).toLowerCase()] = v === 'true';
  }
  const wafMode = g('WAF_MODE');
  if (wafMode && wafMode !== 'off') {
    a.waf = { mode: wafMode, stateDir: safelineEnv.get('SAFELINE_DIR') || '', consolePort: Number(g('WAF_CONSOLE_PORT')) || 9443, consoleAllow: g('WAF_CONSOLE_ALLOW') };
  }
  return a;
}
