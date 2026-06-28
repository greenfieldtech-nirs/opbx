# Transactional Email Troubleshooting

This guide explains how OPBX sends transactional emails, how to diagnose problems,
and how to use the built-in diagnostic Artisan commands.

---

## How Email Sending Works

1. **Application code** builds an `EmailMessage` and calls either:
   - `TransactionalEmailInterface::send()` — synchronous send
   - `TransactionalEmailInterface::sendAsync()` — dispatches a queue job
2. **`TransactionalEmailService`** checks the per-driver rate limit, delegates to the configured driver, and writes an `EmailLog` record.
3. **The driver** builds the provider-specific payload, calls the provider HTTP API, and logs the full request/response.
4. **Queue worker** (`queue-worker` container) runs `SendTransactionalEmailJob` when using async sending.
5. **Retries**: failed jobs are retried up to 3 times with backoffs of 30s, 60s, and 120s. After all retries fail, the job is moved to `failed_jobs`.

---

## Required `.env` Variables Per Provider

Only **one** provider can be enabled at a time.

### Mailgun

```env
EMAIL_PROVIDER=mailgun
MAILGUN_ENABLED=true
MAILGUN_DOMAIN=mg.your-domain.com
MAILGUN_SECRET=your-mailgun-api-key
MAILGUN_ENDPOINT=api.mailgun.net      # or api.eu.mailgun.net
MAILGUN_REGION=us                     # or eu
```

### Mailjet

```env
EMAIL_PROVIDER=mailjet
MAILJET_ENABLED=true
MAILJET_APIKEY=your-mailjet-api-key
MAILJET_APISECRET=your-mailjet-secret
```

### MailerLite

```env
EMAIL_PROVIDER=mailerlite
MAILERLITE_ENABLED=true
MAILERLITE_API_KEY=your-mailerlite-api-key
```

### SendInBlue / Brevo

```env
EMAIL_PROVIDER=sendinblue
SENDINBLUE_ENABLED=true
SENDINBLUE_API_KEY=your-brevo-api-key
```

Common settings:

```env
EMAIL_QUEUE=default
EMAIL_TRACK_OPENS=true
EMAIL_TRACK_CLICKS=true
```

---

## Diagnostic Commands

### `email:test`

Send a test email to validate provider configuration.

```bash
# Queue a test email using the active provider
php artisan email:test user@example.com

# Send synchronously (useful for immediate feedback)
php artisan email:test user@example.com --sync

# Test a specific provider without changing .env
php artisan email:test user@example.com --provider=mailgun --sync
```

Output:

- Success: provider name and message ID.
- Failure: clear error message (missing keys, invalid config, provider error).

### `email:logs`

Show the 20 most recent `email_logs` records for a recipient.

```bash
php artisan email:logs user@example.com
```

Columns: `id`, `status`, `subject`, `provider`, `error_message`, `created_at`.

Statuses:

- `queued` — job dispatched, waiting for worker
- `sent` — provider accepted the message
- `delivered` — delivery confirmed (if webhooks are configured)
- `bounced` — delivery failed permanently
- `failed` — send failed (check `error_message`)

### `email:retry-failed`

List failed `SendTransactionalEmailJob` entries from `failed_jobs` and retry them.

```bash
# Retry the 10 most recent failed email jobs (default)
php artisan email:retry-failed

# Retry a specific number of jobs
php artisan email:retry-failed --limit=5
```

The command displays a table with `id`, `to_email`, `exception`, and `failed_at`, then
prompts for confirmation before calling `queue:retry` for each job.

---

## Reading Queue-Worker Logs

If emails are queued but not delivered, check the worker logs:

```bash
docker compose logs -f queue-worker
```

Look for these log entries:

- `Processing queued email` — worker picked up the job
- `Email provider API request` — outgoing HTTP request (method, URL, to, subject)
- `Email provider API response` — provider response (status, body truncated to 2000 chars)
- `Email provider API error response` — full response body and status on failure
- `Transactional email send completed` — final success summary
- `Transactional email send failed` — final failure summary
- `Email delivery permanently failed` — job exhausted all retries

Secrets (`secret`, `key`, `api_key`, etc.) are redacted from logs automatically.

---

## Common Issues

### Missing domain or credentials

Error example:

```
Configuration error: Missing required configuration key: domain
```

Fix: set `MAILGUN_DOMAIN` and `MAILGUN_SECRET` (or the equivalent keys for your provider).

### No queue worker running

Symptom: emails stay `queued` in `email_logs` and are never sent.

Fix: start the worker container:

```bash
docker compose up -d queue-worker
```

Or run a local worker:

```bash
php artisan queue:listen --queue=default
```

### Multiple providers enabled

Error example:

```
Configuration Error: 2 transactional email providers are enabled (mailgun, mailjet).
Only ONE provider can be active at a time.
```

Fix: set only one `EMAIL_*_ENABLED=true` in `.env` and restart containers.

### Provider rejects the sender domain

Symptom: `Email provider API error response` shows status `401`/`403` or a domain-not-found message.

Fix:

- Verify the sender domain is verified with the provider.
- Check `MAIL_FROM_ADDRESS` uses a domain configured in the provider dashboard.
- For Mailgun, ensure `MAILGUN_DOMAIN` matches the verified domain.

### Rate limiting

Symptom: `Rate limit exceeded for mailgun`.

Fix: increase the provider's `rate_limit` config or reduce send volume. Default is 100 per minute.

### `.env` changes not applied

Symptom: updated credentials but still getting auth errors.

Fix: restart the `app` and `queue-worker` containers so the new environment is loaded:

```bash
docker compose restart app queue-worker
```

---

## Related Files

- `app/Services/Email/TransactionalEmailService.php`
- `app/Services/Email/Drivers/`
- `app/Services/Email/Jobs/SendTransactionalEmailJob.php`
- `app/Models/EmailLog.php`
- `app/Console/Commands/TestEmailConfiguration.php`
- `app/Console/Commands/ShowEmailLogs.php`
- `app/Console/Commands/RetryFailedEmails.php`
