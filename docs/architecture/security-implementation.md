# Security Implementation and Best Practices

## Overview

OpBX implements enterprise-grade security measures across all layers of the application, from webhook authentication to multi-tenant data isolation. This document outlines the comprehensive security architecture and implementation details.

## Authentication & Authorization

### Dual Authentication Modes

#### 1. Cookie-Based Authentication (SPA)
- **Implementation**: Laravel Sanctum with HttpOnly session cookies
- **Security Features**:
  - CSRF protection via Sanctum middleware
  - Automatic session regeneration
  - Secure cookie configuration
- **Usage**: AJAX requests from React frontend
- **Detection**: X-Requested-With header

#### 2. Token-Based Authentication (API)
- **Implementation**: Bearer tokens with 24-hour expiration
- **Security Features**:
  - All existing tokens revoked on login
  - No session state dependencies
  - Constant-time string comparison
- **Usage**: Third-party API integrations

### Role-Based Access Control (RBAC)

#### User Roles Hierarchy
```php
enum UserRole: string
{
    case OWNER = 'owner';        // Full organization control
    case PBX_ADMIN = 'pbx_admin'; // User/extension management
    case PBX_USER = 'pbx_user';   // Own data + basic features
    case REPORTER = 'reporter';   // Read-only reporting access
}
```

#### Permission Matrix

| Permission | Owner | PBX Admin | PBX User | Reporter |
|------------|-------|-----------|----------|----------|
| View Organization Settings | ✅ | ❌ | ❌ | ❌ |
| Manage Users | ✅ | ✅ | ❌ | ❌ |
| Manage Extensions | ✅ | ✅ | ✅ | ❌ |
| View Call Logs | ✅ | ✅ | ✅ | ✅ |
| Manage Cloudonix Settings | ✅ | ❌ | ❌ | ❌ |
| Export Data | ✅ | ✅ | ❌ | ✅ |

#### Authorization Implementation
```php
// Policy-based permissions
class UserPolicy
{
    public function manageUsers(User $user): bool
    {
        return in_array($user->role, [UserRole::OWNER, UserRole::PBX_ADMIN]);
    }

    public function manageOrganization(User $user): bool
    {
        return $user->role === UserRole::OWNER;
    }
}
```

## Multi-Tenant Security

### Organization Isolation

#### Database-Level Isolation
- **Global Scope**: `OrganizationScope` automatically filters all queries
- **Security-First Approach**: Unauthenticated users get zero results
- **Foreign Key Constraints**: Prevent cross-organization data access

```php
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $organizationId = $this->getOrganizationId();
        if ($organizationId !== null) {
            $builder->where($model->getTable() . '.organization_id', $organizationId);
        } else {
            // SECURITY: Force zero results when unauthenticated
            $builder->whereRaw('1 = 0');
        }
    }
}
```

#### Application-Level Isolation
- **Middleware Enforcement**: `EnsureOrganization` on all routes
- **Context Injection**: Organization context in all requests
- **Audit Logging**: Organization ID in all log entries

### Tenant Data Protection
- **Query Scoping**: All database queries automatically scoped
- **Cache Isolation**: Redis keys prefixed by organization
- **File Storage**: MinIO buckets separated by organization

## Webhook Security

### Dual Authentication Mechanisms

#### Voice Webhooks (`VerifyVoiceWebhookAuth`)
**Endpoints**: `/voice/route`, `/voice/ivr-input`, `/callbacks/voice/ring-group-callback`

**Authentication Methods** (tried in order):
1. `X-Cx-Apikey` header
2. `Authorization: Bearer {token}` header
3. Domain name lookup in `cloudonix_settings.domain_name`
4. DID/extension number lookup

**Security Features**:
- Token validation against `domain_requests_api_key`
- Organization resolution and isolation
- CXML error responses for voice applications

#### Status/CDR Webhooks (`VerifyCloudonixSignature`)
**Endpoints**: `/webhooks/cloudonix/call-initiated`, `/call-status`, `/session-update`, `/cdr`

**Authentication Method**: HMAC-SHA256 signature verification
- **Header**: `X-Cloudonix-Signature`
- **Secret**: `CLOUDONIX_WEBHOOK_SECRET` environment variable
- **Algorithm**: SHA256 HMAC with constant-time comparison

**Special CDR Handling**:
- No signature required for CDRs
- Organization identified by `owner.domain.uuid`
- Matched against `cloudonix_settings.domain_uuid`

### Webhook Security Features

#### Timestamp Validation
```php
private function isValidTimestamp(array $payload): bool
{
    $timestamp = $payload['timestamp'] ?? null;
    if (!$timestamp) return false;

    $requestTime = Carbon::createFromTimestamp($timestamp);
    $now = Carbon::now();

    // 5-minute tolerance window
    return abs($now->diffInSeconds($requestTime)) <= 300;
}
```

#### Idempotency Protection
- **Redis-based keys**: `idem:webhook:{hash}`
- **TTL expiration**: 24 hours
- **Duplicate detection**: Prevents replay attacks

#### Rate Limiting
- **Organization-based limits**: Prevents abuse
- **Configurable thresholds**:
  - API calls: 60/minute
  - Webhooks: 100/minute
  - Voice routing: 1000/minute
  - Auth attempts: 5/minute

## Input Validation & Sanitization

### Request Validation

#### Form Request Classes
```php
class CreateExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Extension::class);
    }

    public function rules(): array
    {
        return [
            'extension_number' => [
                'required',
                'string',
                'size:5',
                'regex:/^\d{3,5}$/',
                Rule::unique('extensions')->where(function ($query) {
                    return $query->where('organization_id', $this->user()->organization_id);
                })
            ],
            'type' => ['required', Rule::enum(ExtensionType::class)],
            'user_id' => ['nullable', 'exists:users,id,organization_id,' . $this->user()->organization_id],
        ];
    }
}
```

#### Phone Number Normalization
```php
class PhoneNumberRule implements Rule
{
    public function passes($attribute, $value): bool
    {
        // E.164 format validation
        return preg_match('/^\+[1-9]\d{1,14}$/', $value);
    }
}
```

### Log Sanitization

#### Sensitive Data Protection
```php
class LogSanitizer
{
    private const SENSITIVE_PATTERNS = [
        '/password/i',
        '/api[_-]?key/i',
        '/secret/i',
        '/token/i',
        '/sip[_-]?password/i',
    ];

    public static function sanitize(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                foreach (self::SENSITIVE_PATTERNS as $pattern) {
                    if (preg_match($pattern, $value)) {
                        return '[REDACTED]';
                    }
                }
            }
            return $value;
        }, $data);
    }
}
```

## Security Headers & Protections

### Content Security Policy (CSP)

#### Strict CSP Implementation
```php
$cspDirectives = [
    "default-src 'self'",
    "script-src 'self' 'nonce-{$nonce}'",
    "style-src 'self' 'unsafe-inline'", // For Tailwind
    "img-src 'self' data: https:",
    "font-src 'self' https://fonts.gstatic.com",
    "connect-src 'self' ws: wss: https://api.pusherapp.com",
    "object-src 'none'",
    "frame-ancestors 'none'",
    "base-uri 'self'",
    "form-action 'self'",
];
```

#### CSP Violation Reporting
```php
// Report URI for CSP violations
"report-uri /api/v1/csp-report"
```

### Additional Security Headers

#### Production Headers
```nginx
# Security headers in nginx
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
add_header Cross-Origin-Embedder-Policy "require-corp" always;
add_header Cross-Origin-Opener-Policy "same-origin" always;
add_header Cross-Origin-Resource-Policy "same-origin" always;
```

#### HSTS (HTTP Strict Transport Security)
```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
```

## Password Security

### Password Policy Enforcement

#### Strong Password Requirements
```php
class PasswordRule implements Rule
{
    public function passes($attribute, $value): bool
    {
        return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{12,}$/', $value);
    }
}
```

#### Password Security Features
- **Bcrypt hashing** with configurable rounds
- **Password age limits**: 90-day maximum age
- **Password history**: Prevent reuse of last 5 passwords
- **Compromised password checking**: Against HaveIBeenPwned API

### User Account Security

#### Account Protection
- **Self-service password changes only**
- **Owner cannot change own role** (prevents lockout)
- **PBX Admin cannot escalate privileges**
- **Users cannot delete themselves**

## API Security

### Rate Limiting Implementation

#### Organization-Based Limiting
```php
class RateLimitOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->user()?->organization_id;
        $key = "rate_limit:org:{$organizationId}:" . $request->path();

        $maxAttempts = config('rate_limit.org.' . $this->getRouteGroup($request));
        $decaySeconds = 60; // 1 minute

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            return response('API rate limit exceeded', 429)
                ->header('Retry-After', $this->limiter->availableIn($key));
        }

        $this->limiter->hit($key, $decaySeconds);

        return $next($request);
    }
}
```

### CORS Configuration

#### Secure CORS Setup
```php
// config/cors.php
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-RateLimit-Remaining', 'X-RateLimit-Reset'],
    'max_age' => 86400,
    'supports_credentials' => true,
];
```

## Data Protection

### Encryption at Rest

#### Sensitive Data Encryption
```php
class CloudonixSetting extends Model
{
    protected function domainApiKey(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => decrypt($value),
            set: fn ($value) => encrypt($value),
        );
    }
}
```

### Database Security

#### Query Parameterization
- **Eloquent ORM** prevents SQL injection
- **Prepared statements** for all queries
- **Input validation** before database operations

#### Connection Security
- **SSL/TLS** for production database connections
- **Connection pooling** for performance
- **Read replicas** for scalability

## Real-Time Security

### WebSocket Authentication

#### Laravel Echo Authentication
```php
// routes/channels.php
Broadcast::channel('presence.org.{organizationId}', function ($user, $organizationId) {
    return $user->organization_id === (int) $organizationId;
});
```

#### Connection Security
- **WSS required** in production
- **Origin validation** for WebSocket connections
- **Authentication tokens** for private channels

## Monitoring & Incident Response

### Security Event Logging

#### Comprehensive Audit Trail
```php
class SecurityLogger
{
    public static function logSecurityEvent(string $event, array $context = []): void
    {
        Log::channel('security')->info($event, array_merge($context, [
            'user_id' => auth()->id(),
            'organization_id' => auth()->user()?->organization_id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now()->toISOString(),
        ]));
    }
}
```

### Security Monitoring

#### Key Metrics to Monitor
- **Failed authentication attempts**
- **Rate limit violations**
- **Webhook authentication failures**
- **CSP violation reports**
- **Suspicious API usage patterns**

### Incident Response Plan

#### Security Incident Procedures
1. **Immediate containment** - Disable compromised accounts
2. **Investigation** - Review audit logs and access patterns
3. **Recovery** - Restore from clean backups
4. **Notification** - Inform affected parties
5. **Prevention** - Update security measures

## Compliance Considerations

### GDPR Compliance

#### Data Protection Measures
- **Data minimization** - Only collect necessary data
- **Consent management** - For optional data collection
- **Right to erasure** - Account deletion functionality
- **Data portability** - Export user data feature

### Industry Standards

#### Security Best Practices
- **OWASP Top 10** compliance
- **NIST Cybersecurity Framework** alignment
- **ISO 27001** security controls
- **SOC 2** audit readiness

## Testing & Validation

### Security Test Suite

#### Comprehensive Testing
```php
class SecurityHeadersTest extends TestCase
{
    public function test_csp_headers_are_present()
    {
        $response = $this->get('/');

        $response->assertHeader('Content-Security-Policy');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
```

#### Webhook Security Tests
```php
class WebhookAuthenticationTest extends TestCase
{
    public function test_invalid_signature_returns_401()
    {
        $payload = ['test' => 'data'];
        $signature = 'invalid_signature';

        $response = $this->postJson('/webhooks/cloudonix/call-status', $payload, [
            'X-Cloudonix-Signature' => $signature
        ]);

        $response->assertStatus(401);
    }
}
```

## Production Security Checklist

### Pre-Deployment Verification
- [ ] All secrets moved to environment variables
- [ ] Debug mode disabled
- [ ] Secure cookie settings enabled
- [ ] HTTPS enforced
- [ ] Security headers configured
- [ ] Rate limiting enabled
- [ ] Audit logging active
- [ ] CSP properly configured

### Ongoing Security Maintenance
- [ ] Regular dependency updates
- [ ] Security patch monitoring
- [ ] Log review and analysis
- [ ] Penetration testing
- [ ] Security training for developers

This comprehensive security implementation provides enterprise-grade protection for the OpBX PBX system, covering all attack vectors and compliance requirements.