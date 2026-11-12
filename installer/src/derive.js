const PROVIDERS = { mailgun: 'MAILGUN', mailjet: 'MAILJET', mailerlite: 'MAILERLITE', sendinblue: 'SENDINBLUE' };

// Keys reconfigure may rewrite (changing the domain / the WAF settings).
export const OWNED_KEYS = {
  domain: [
    'APP_URL', 'FRONTEND_URL', 'SANCTUM_STATEFUL_DOMAINS', 'SESSION_DOMAIN', 'VITE_WS_HOST', 'VITE_WS_PORT',
    'VITE_WS_SCHEME', 'VITE_ALLOWED_HOSTS', 'GRAFANA_ROOT_URL', 'AUTH0_REDIRECT_URI', 'OPBX_APPLICATION_WEBHOOK_BASEURL',
  ],
  waf: [
    'WAF_MODE', 'WAF_CONSOLE_ENABLED', 'WAF_CONSOLE_PORT', 'WAF_CONSOLE_ALLOW', 'TRUSTED_PROXY_CIDR', 'COMPOSE_FILE',
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
  if (a.waf) env.WAF_CONSOLE_PORT = '9443';
  if (a.tenancy === 'multi') env.OPBX_APPLICATION_WEBHOOK_BASEURL = a.publicUrl;
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
