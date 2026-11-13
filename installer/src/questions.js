import { normalizePublicUrl, isEmail, parseCidrList, parseHostList, isPortNumber, isSafeAbsolutePath } from './validate.js';

// Defaults are the template's own (.env.example); only values the user changes are returned.
const ADVANCED = [
  ['Request limits per minute', [
    ['RATE_LIMIT_API', 'Normal API requests', '60'], ['RATE_LIMIT_SENSITIVE', 'Sensitive actions', '10'],
    ['RATE_LIMIT_SENSITIVE_ADMIN', 'Sensitive actions by administrators', '60'], ['RATE_LIMIT_AUTH', 'Sign-in attempts', '5']]],
  ['How long to keep old records (days)', [
    ['RETENTION_SESSION_UPDATES_DAYS', 'Call session updates', '30'], ['RETENTION_CALL_NOTIFICATION_LOGS_DAYS', 'Call notification logs', '90'],
    ['RETENTION_EMAIL_LOGS_DAYS', 'Email logs', '90'], ['RETENTION_BLOCKED_CALL_LOGS_DAYS', 'Blocked call logs', '90'],
    ['RETENTION_QUEUE_CALLS_DAYS', 'Call queue calls', '183'], ['RETENTION_CALL_TRACKING_SESSIONS_DAYS', 'Call tracking sessions', '183'],
    ['RETENTION_AUTO_DIALER_CALL_SESSIONS_DAYS', 'Auto dialer call sessions', '183'], ['RETENTION_AUTO_DIALER_LIST_VERSIONS_DAYS', 'Old versions of dialer lists', '30'],
    ['RETENTION_PLATFORM_AUDIT_LOGS_DAYS', 'Platform audit logs', '365']]],
  ['Routing cache lifetime (seconds)', [
    ['VOICE_CACHE_EXTENSION_TTL', 'Extension routing', '1800'], ['VOICE_CACHE_BUSINESS_HOURS_TTL', 'Business hours routing', '900']]],
  ['Cloudonix connection protection', [
    ['CIRCUIT_BREAKER_CLOUDONIX_THRESHOLD', 'Failures before pausing requests', '5'],
    ['CIRCUIT_BREAKER_CLOUDONIX_TIMEOUT', 'Seconds to wait for each request', '30'],
    ['CIRCUIT_BREAKER_CLOUDONIX_RETRY_AFTER', 'Seconds to pause before trying again', '60']]],
];
const LOG_LEVELS = [['LOG_LEVEL', 'Web application log detail', 'info', ['debug', 'info', 'notice', 'warning', 'error']],
  ['ACD_LOG_LEVEL', 'Call queue service log detail', 'info', ['debug', 'info', 'warn', 'error']],
  ['AMD_LOG_LEVEL', 'Voicemail detection service log detail', 'info', ['debug', 'info', 'warn', 'error']]];

const EMAIL = {
  mailgun: ['Mailgun', [['MAILGUN_DOMAIN', 'Mailgun sending domain', ''], ['MAILGUN_SECRET', 'Mailgun API key', null],
    ['MAILGUN_ENDPOINT', 'Mailgun server', 'api.mailgun.net'], ['MAILGUN_REGION', 'Mailgun region (us or eu)', 'us']]],
  mailjet: ['Mailjet', [['MAILJET_APIKEY', 'Mailjet API key', ''], ['MAILJET_APISECRET', 'Mailjet API secret', null]]],
  mailerlite: ['MailerLite', [['MAILERLITE_API_KEY', 'MailerLite API key', null]]],
  sendinblue: ['Brevo (Sendinblue)', [['SENDINBLUE_API_KEY', 'Brevo API key', null]]],
};
const BLOCKS = [['disposable', 'Disposable (throw-away) addresses', true], ['blocklisted', 'Addresses on block lists', true],
  ['spam', 'Known spam addresses', true], ['role_accounts', 'Shared mailboxes such as info@ or admin@', true],
  ['relay_domains', 'Privacy relay addresses', false], ['public_domains', 'Free public providers such as gmail.com', false]];

// Free text ends up in .env: refuse what renderEnv cannot store.
const unsafe = (s) => (/[\r\n]/.test(s) ? 'Please type this on a single line.'
  : s.includes('$') && s.includes("'") ? "This cannot contain both a $ sign and an apostrophe (')." : undefined);

const ask = (kind) => async (ui, message, o = {}) => {
  const v = await ui[kind]({
    message, placeholder: o.placeholder, ...(kind === 'text' && { initialValue: o.def }),
    validate: (raw) => {
      const s = String(raw ?? '').trim();
      if (!s) return o.optional ? undefined : 'This is required.';
      return unsafe(s) ?? o.check?.(s);
    },
  });
  return String(v ?? '').trim();
};
const text = ask('text');
const password = ask('password');
// A secret already in defaults is kept when the answer is left blank.
const secret = async (ui, message, current, o = {}) => {
  if (!current) return password(ui, message, o);
  return (await password(ui, `${message} (leave blank to keep the current value)`, { ...o, optional: true })) || current;
};
const intCheck = (s) => (/^\d+$/.test(s) ? undefined : 'Enter a whole number.');
const cidrCheck = (s) => { const r = parseCidrList(s); return r.ok ? undefined : r.reason; };
const joinCidrs = (s) => parseCidrList(s).value.join(',');

// Re-asks while the port is taken; when the probe itself fails the user may keep the port (no endless loop).
async function askPort(ui, message, def, portCheck, reserved) {
  for (;;) {
    const port = Number(await text(ui, message, {
      def: String(def),
      check: (s) => (!(isPortNumber(Number(s)) && /^\d+$/.test(s)) ? 'Enter a port number between 1 and 65535.'
        : Number(s) === reserved ? `Port ${s} is already the OPBX web port. Choose another one.` : undefined),
    }));
    if (!portCheck) return port;
    try {
      if (await portCheck(port)) return port;
      ui.note('Port in use', `Port ${port} is already used by another program. Choose a different port.`);
    } catch (e) {
      ui.note('Could not check the port', e.message);
      if (await ui.confirm({ message: `Use port ${port} anyway?`, initialValue: false })) return port;
    }
    def = port;
  }
}

export async function askPublicUrl(ui, d, mode) {
  ui.note('Public address', 'Enter the address people will type in their browser, for example https://pbx.example.com.\nIt must use https:// and a real domain name: the browser phone only works over HTTPS.');
  return normalizePublicUrl(await text(ui, 'What is your server\'s public address?', {
    def: d.publicUrl, placeholder: 'https://pbx.example.com', check: (s) => { const r = normalizePublicUrl(s, mode); return r.ok ? undefined : r.reason; },
  }), mode).value;
}

export async function askWaf(ui, d, trustedProxyCidr, { portCheck = null, httpPort } = {}) {
  const wafMode = await ui.select({
    message: 'Put a web application firewall in front of OPBX?',
    options: [{ value: 'off', label: 'No' }, { value: 'monitor', label: 'Yes, monitor only', hint: 'recommended to start' },
      { value: 'block', label: 'Yes, blocking' }],
    initialValue: d.waf?.mode ?? 'off',
  });
  if (wafMode !== 'off') {
    const stateDir = await text(ui, 'Where on this machine should the firewall keep its data?', {
      def: d.waf?.stateDir ?? '/var/lib/safeline',
      check: (s) => (isSafeAbsolutePath(s) ? undefined : 'Please type a full path such as /var/lib/safeline, using only letters, digits and . _ - /'),
    });
    const consolePort = await askPort(ui, 'Which port should the firewall console use? (choose one your reverse proxy does not listen on)',
      d.waf?.consolePort ?? 9443, portCheck, httpPort);
    let consoleAllow = '';
    if (trustedProxyCidr) {
      consoleAllow = joinCidrs(await text(ui, 'Which IPs or networks may open the firewall console?', {
        def: d.waf?.consoleAllow, placeholder: '203.0.113.0/24', check: cidrCheck,
      }));
    } else {
      ui.note('Firewall console', 'Without a proxy address, OPBX cannot tell visitors apart by IP, so the firewall console will be protected by its own login only.');
    }
    return { mode: wafMode, stateDir, consolePort, consoleAllow };
  }
  return null;
}

// Development only: the Vite dev server refuses requests for host names it does not know.
export async function askAllowedHosts(ui, d) {
  ui.note('Allowed host names', 'The development web server only answers to host names it knows; localhost always works.\n'
    + 'List every other name people use to open OPBX on this machine: a VPN name (for example a Tailscale name such as dev-box.tailnet.ts.net), '
    + 'the machine\'s IP address, or your ngrok domain. Start a name with a dot (.ts.net) to allow every name under it.\n'
    + 'Live updates then connect to that same name on port 6001, so the VPN or firewall must let port 6001 through as well as 3000.');
  const v = await text(ui, 'Other host names allowed to open OPBX (comma-separated, optional, press Enter to skip)', {
    def: (d.allowedHosts ?? []).join(','), placeholder: 'dev-box.tailnet.ts.net, .ts.net', optional: true, check: (s) => parseHostList(s).reason,
  });
  return parseHostList(v).value;
}

export async function askAnswers(ui, { platform, defaults = {}, portCheck = null } = {}) {
  const d = { ...defaults };
  const a = {};

  a.mode = await ui.select({
    message: 'Is this a production server or a development machine?',
    options: [{ value: 'production', label: 'Production', hint: 'a real server your users will reach over HTTPS' },
      { value: 'development', label: 'Development', hint: 'trying OPBX on this computer at http://localhost' }],
    initialValue: d.mode ?? 'production',
  });
  const prod = a.mode === 'production';

  if (prod) {
    a.publicUrl = await askPublicUrl(ui, d, a.mode);
  } else {
    a.publicUrl = 'http://localhost';
  }

  a.httpPort = await askPort(ui, 'Which port on this machine should OPBX listen on?', d.httpPort ?? 80, portCheck);
  if (!prod && a.httpPort !== 80) a.publicUrl = `http://localhost:${a.httpPort}`;
  a.allowedHosts = prod ? [] : await askAllowedHosts(ui, d);

  a.trustedProxyCidr = '';
  if (prod) {
    ui.note('Why we ask', 'With the proxy address, OPBX can see each visitor\'s real IP address, which makes rate limits, logs and firewall events accurate.');
    const proxy = await text(ui, 'What is the IP address or network of the proxy that forwards traffic to OPBX? (optional, press Enter to skip)', {
      def: d.trustedProxyCidr, optional: true, placeholder: '10.0.0.5 or 10.0.0.0/24', check: cidrCheck,
    });
    a.trustedProxyCidr = proxy && joinCidrs(proxy);
  }

  a.orgName = await text(ui, 'What is your organization\'s name?', { def: d.orgName });
  a.ownerName = await text(ui, 'Your full name (the platform owner)', { def: d.ownerName });
  a.ownerEmail = await text(ui, 'Your email address (used to sign in)', {
    def: d.ownerEmail, check: (s) => (isEmail(s) ? undefined : 'That does not look like an email address.'),
  });

  a.tenancy = await ui.select({
    message: 'Will this server run just your company, or many customer organizations?',
    options: [{ value: 'single', label: 'Just my company' }, { value: 'multi', label: 'Many customer organizations' }],
    initialValue: d.tenancy ?? 'single',
  });
  if (a.tenancy === 'single') {
    ui.note('Heads up: sign-up stays open', 'Anyone who can reach this server will be able to create their own organization from the sign-up page (and through Auth0 sign-in, if you enable it). Restrict access to the server if that is not what you want.');
  }

  a.frontPage = await ui.select({
    message: `What should people see when they open ${a.publicUrl}?`,
    options: [{ value: 'login', label: 'The sign-in page', hint: 'recommended; people already signed in go straight to the dashboard' },
      { value: 'home', label: 'The OPBX homepage', hint: 'the public page that describes OPBX' }],
    initialValue: d.frontPage ?? 'login',
  });

  const provider = await ui.select({
    message: 'Which outgoing email service would you like to use?',
    options: [...Object.entries(EMAIL).map(([value, [label]]) => ({ value, label })), { value: 'none', label: 'None for now' }],
    initialValue: d.email?.provider ?? 'none',
  });
  a.email = { provider, fields: {}, fromAddress: '', fromName: '' };
  if (provider === 'none') {
    ui.note('No email service', 'Invitation and notification emails will not be sent until you set up an email service.');
  } else {
    const [label, fields] = EMAIL[provider];
    const prev = d.email?.provider === provider ? d.email.fields ?? {} : {};
    for (const [key, q, def] of fields) {
      a.email.fields[key] = def === null ? await secret(ui, q, prev[key]) : await text(ui, q, { def: prev[key] ?? def, optional: def !== '' });
    }
    const host = new URL(a.publicUrl).hostname; // development has no real domain: no default
    a.email.fromAddress = await text(ui, `Which address should emails from ${label} come from?`, {
      def: d.email?.fromAddress || (prod ? `noreply@${host}` : ''), check: (s) => (isEmail(s) ? undefined : 'That does not look like an email address.'),
    });
    a.email.fromName = await text(ui, 'Which sender name should recipients see?', { def: d.email?.fromName || a.orgName });
  }

  a.auth0 = null;
  if (await ui.confirm({ message: 'Allow sign-in with Google or GitHub through Auth0?', initialValue: !!d.auth0 })) {
    const o = d.auth0 ?? {};
    ui.note('Auth0', `Add this as an allowed callback address in your Auth0 application:\n${a.publicUrl}/ui/auth/callback`);
    a.auth0 = {
      domain: await text(ui, 'Auth0 domain', { def: o.domain, placeholder: 'your-tenant.auth0.com' }),
      clientId: await text(ui, 'Auth0 client ID', { def: o.clientId }),
      clientSecret: await secret(ui, 'Auth0 client secret', o.clientSecret),
      providers: await ui.multiselect({
        message: 'Which sign-in providers should be offered?', required: true,
        options: [{ value: 'google', label: 'Google' }, { value: 'github', label: 'GitHub' }], initialValues: o.providers ?? ['google', 'github'],
      }),
    };
  }

  a.recaptcha = null;
  if (await ui.confirm({ message: 'Protect sign-up with Google reCAPTCHA v3?', initialValue: !!d.recaptcha })) {
    const o = d.recaptcha ?? {};
    a.recaptcha = {
      siteKey: await text(ui, 'reCAPTCHA site key', { def: o.siteKey }),
      secretKey: await secret(ui, 'reCAPTCHA secret key', o.secretKey),
      minScore: Number(await text(ui, 'Minimum score to accept (0 to 1; higher is stricter)', {
        def: String(o.minScore ?? 0.5), check: (s) => (Number(s) >= 0 && Number(s) <= 1 ? undefined : 'Enter a number between 0 and 1.'),
      })),
    };
  }

  a.usercheck = null;
  if (await ui.confirm({ message: 'Check sign-up email addresses with UserCheck?', initialValue: !!d.usercheck })) {
    const o = d.usercheck ?? {};
    const token = await secret(ui, 'UserCheck API token', o.token);
    const chosen = await ui.multiselect({
      message: 'Which kinds of email address should be refused?', required: false,
      options: BLOCKS.map(([value, label]) => ({ value, label })),
      initialValues: BLOCKS.filter(([k, , def]) => (o.block ? o.block[k] : def)).map(([k]) => k),
    });
    a.usercheck = { token, block: Object.fromEntries(BLOCKS.map(([k]) => [k, chosen.includes(k)])) };
  }

  a.logs = await ui.confirm({ message: 'Collect logs in Grafana?', initialValue: d.logs ?? false });

  a.waf = await askWaf(ui, d, a.trustedProxyCidr, { portCheck, httpPort: a.httpPort });

  a.ngrokToken = '';
  if (!prod) a.ngrokToken = await secret(ui, 'ngrok auth token, to receive calls from Cloudonix (optional, press Enter to skip)', d.ngrokToken, { optional: true });

  a.advanced = {};
  if (await ui.confirm({ message: 'Review advanced settings (limits, retention, caches, log detail)?', initialValue: false })) {
    for (const [title, items] of ADVANCED) {
      ui.note(title, 'Press Enter to keep the value shown.');
      for (const [key, label, def] of items) {
        const v = await text(ui, label, { def: d.advanced?.[key] ?? def, check: intCheck });
        if (v !== def) a.advanced[key] = v;
      }
    }
    for (const [key, label, def, levels] of LOG_LEVELS) {
      const v = await ui.select({ message: label, options: levels.map((value) => ({ value, label: value })), initialValue: d.advanced?.[key] ?? def });
      if (v !== def) a.advanced[key] = v;
    }
  }
  return a;
}

const yn = (b) => (b ? 'yes' : 'no');
export async function reviewAnswers(ui, a) {
  const lines = [
    `Type: ${a.mode === 'production' ? 'Production' : 'Development'}`,
    `Address: ${a.publicUrl}   Port: ${a.httpPort}`,
    ...(a.mode === 'production' ? [`Proxy: ${a.trustedProxyCidr || 'none'}`] : []),
    `Organization: ${a.orgName}`, `Owner: ${a.ownerName} <${a.ownerEmail}>`,
    `Server for: ${a.tenancy === 'single' ? 'just your company' : 'many customer organizations'}`,
    `Front page: ${a.frontPage === 'home' ? 'the OPBX homepage' : 'the sign-in page'}`,
    `Email service: ${a.email.provider === 'none' ? 'none for now' : `${EMAIL[a.email.provider][0]} (credentials set)`}`,
    `Auth0 sign-in: ${a.auth0 ? `${a.auth0.domain} (${a.auth0.providers.join(', ')})` : 'no'}`,
    `reCAPTCHA: ${yn(a.recaptcha)}`, `UserCheck: ${yn(a.usercheck)}`, `Grafana logs: ${yn(a.logs)}`,
    `Firewall: ${a.waf ? `${a.waf.mode === 'monitor' ? 'monitor only' : 'blocking'}, console on port ${a.waf.consolePort}${a.waf.consoleAllow ? `, console open to ${a.waf.consoleAllow}` : ''}` : 'no'}`,
    ...(a.mode === 'production' ? [] : [`ngrok: ${a.ngrokToken ? 'token set' : 'no'}`, `Allowed host names: ${a.allowedHosts?.length ? a.allowedHosts.join(', ') : 'localhost only'}`]),
    `Advanced settings changed: ${Object.keys(a.advanced).length}`,
  ];
  ui.note('Please review your choices (secrets are hidden)', lines.join('\n'));
  return ui.select({
    message: 'Is this correct?',
    options: [{ value: 'confirm', label: 'Yes, continue' }, { value: 'edit', label: 'No, go back and change something' }],
  });
}
