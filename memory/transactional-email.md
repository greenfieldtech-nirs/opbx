# Transactional Email

## Overview
Multi-provider email sending with queue support, rate limiting, and audit logging. Supports 4 email drivers. Used for system notifications.

## Source Files
| File | Purpose |
|------|---------|
| `app/Services/Email/TransactionalEmailService.php` | Orchestrator |
| `app/Services/Email/Contracts/TransactionalEmailInterface.php` | Driver interface |
| `app/Services/Email/Drivers/MailgunDriver.php` | Mailgun |
| `app/Services/Email/Drivers/MailjetDriver.php` | Mailjet |
| `app/Services/Email/Drivers/MailerLiteDriver.php` | MailerLite |
| `app/Services/Email/Drivers/SendInBlueDriver.php` | Brevo (SendInBlue) |
| `app/Services/Email/DTOs/` | EmailMessage, EmailRecipient, EmailAttachment, EmailSendResult |
| `app/Services/Email/Jobs/SendTransactionalEmailJob.php` | Queued sending |
| `app/Services/Email/Providers/EmailServiceProvider.php` | Laravel service provider |
| `app/Services/Email/Validation/` | Provider config validation |
| `app/Models/EmailLog.php` | Send audit log |
| `app/Facades/TransactionalEmail.php` | Laravel facade |

## Sending Modes
- **Synchronous**: `TransactionalEmailService::send()` - checks rate limit, sends, logs
- **Asynchronous**: `TransactionalEmailService::sendAsync()` - creates QUEUED log, dispatches job

## Rate Limiting
Per-driver via `RateLimiter::attempt()`. Default 100/minute (configurable per provider).

## EmailLog Statuses
QUEUED, SENT, DELIVERED, BOUNCED, FAILED

## Related Modules
- [Security](security.md) - Email validation rules (ValidEmailDomain)
