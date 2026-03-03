# Platform Management

The Platform Management feature provides **cross-tenant administrative capabilities** for platform managers. This allows designated users to manage all organizations, users, and settings across the entire platform.

## Overview

Platform Management is designed for **super-administrators** who need to:

- Monitor platform-wide metrics and activity
- Manage organizations (create, update, suspend, delete)
- Manage users across all organizations
- View comprehensive audit logs of all administrative actions
- Control platform manager access for other users

## Architecture

### Security Model

```
┌─────────────────────────────────────────────────────────────┐
│                     Platform Manager                        │
│  (User with is_platform_manager = true)                     │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│              EnsurePlatformManager Middleware               │
│  Verifies:                                                  │
│  - User is authenticated (Sanctum)                          │
│  - User has is_platform_manager flag                        │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│              OrganizationScope::bypass()                    │
│  Temporarily disables tenant scoping to allow               │
│  cross-organization data access                             │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│              PlatformAuditService                           │
│  Logs all mutations for audit trail                         │
└─────────────────────────────────────────────────────────────┘
```

### Key Components

| Component | Purpose |
|-----------|---------|
| `EnsurePlatformManager` middleware | Validates platform manager access |
| `OrganizationScope::bypass()` | Allows cross-tenant queries |
| `PlatformAuditService` | Records all administrative actions |
| `PlatformAuditLog` model | Stores audit trail entries |

## Access Control

### Who Can Access

Only users with **`is_platform_manager = true`** can access platform management endpoints.

| Role | Can Access Platform Management? |
|------|--------------------------------|
| Platform Manager | ✅ Yes |
| Owner (non-PM) | ❌ No (403 Forbidden) |
| PBX Admin | ❌ No (403 Forbidden) |
| PBX User | ❌ No (403 Forbidden) |
| Reporter | ❌ No (403 Forbidden) |

### Setting Platform Manager Status

```bash
# Promote a user to platform manager
php artisan opbx:set-platform-manager user@example.com

# Revoke platform manager status (invalidates all tokens)
php artisan opbx:revoke-platform-manager user@example.com

# Interactive creation
php artisan opbx:create-platform-manager
```

## Features

### Dashboard

The dashboard provides a high-level overview of the platform:

- **Organization statistics**: Total, active, suspended, deleted
- **User statistics**: Total, active, inactive, platform managers
- **Extension statistics**: Total count
- **DID statistics**: Total count
- **Recent organizations**: Last 10 created
- **Recent audit logs**: Last 10 administrative actions

### Organizations

Full CRUD operations for organizations:

- **List**: View all organizations with user/extension counts
- **View**: Organization details and associated users
- **Update**: Modify name, timezone, settings
- **Status changes**: Activate, suspend, or delete (soft)

### Users

Cross-tenant user management:

- **List**: All users across all organizations (Owner role only)
- **View**: User details and organization
- **Create**: Add users to any organization
- **Update**: Modify user details
- **Delete**: Remove users
- **Platform Manager toggle**: Grant/revoke PM status

### Audit Log

Complete audit trail of all platform management actions:

- Filter by action type
- Filter by platform manager
- View before/after state changes
- Exportable for compliance

## Security Considerations

### Mass Assignment Protection

The `is_platform_manager` field is **NOT** in the User model's `$fillable` array. It can only be modified through the dedicated endpoint or Artisan commands.

### Token Revocation

When a user's platform manager status is revoked:

1. The flag is set to `false`
2. **All tokens are immediately invalidated**
3. The user is effectively logged out

### Last Platform Manager Protection

The system prevents revoking the last platform manager to avoid lockout:

```php
if ($pmCount <= 1) {
    return response()->json([
        'message' => 'Cannot revoke the last platform manager.',
    ], 422);
}
```

### Audit Logging

All mutations create audit log entries with:

- Platform manager who performed the action
- Target organization
- Before/after state
- IP address and user agent
- Timestamp

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/platform/dashboard` | Platform statistics |
| GET | `/api/v1/platform/organizations` | List all organizations |
| GET | `/api/v1/platform/organizations/{id}` | Organization details |
| PUT | `/api/v1/platform/organizations/{id}` | Update organization |
| PATCH | `/api/v1/platform/organizations/{id}/status` | Change status |
| GET | `/api/v1/platform/users` | List all users |
| GET | `/api/v1/platform/users/{id}` | User details |
| PATCH | `/api/v1/platform/users/{id}/platform-manager` | Toggle PM status |
| GET | `/api/v1/platform/audit-logs` | View audit trail |

## Usage Examples

### Suspending an Organization

```bash
curl -X PATCH \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status": "suspended", "reason": "Payment overdue"}' \
  https://api.example.com/api/v1/platform/organizations/123/status
```

### Granting Platform Manager Access

```bash
curl -X PATCH \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"is_platform_manager": true}' \
  https://api.example.com/api/v1/platform/users/456/platform-manager
```

### Querying Audit Logs

```bash
# All actions by a specific platform manager
curl "https://api.example.com/api/v1/platform/audit-logs?platform_manager_user_id=123" \
  -H "Authorization: Bearer $TOKEN"

# All organization status changes
curl "https://api.example.com/api/v1/platform/audit-logs?action=organization.status.updated" \
  -H "Authorization: Bearer $TOKEN"
```

## Database Schema

### platform_audit_logs

```sql
CREATE TABLE platform_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    platform_manager_user_id BIGINT UNSIGNED,
    target_organization_id BIGINT UNSIGNED,
    action VARCHAR(100) NOT NULL,
    target_entity_type VARCHAR(50),
    target_entity_id BIGINT UNSIGNED,
    before_state JSON,
    after_state JSON,
    reason TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_action (action),
    INDEX idx_platform_manager (platform_manager_user_id),
    INDEX idx_target_org (target_organization_id),
    INDEX idx_created_at (created_at)
);
```

## Scheduled Tasks

### Audit Log Cleanup

Old audit logs are automatically cleaned up (default: 14 days retention):

```bash
php artisan opbx:cleanup-audit-logs
```

Scheduled in `routes/console.php`:

```php
Schedule::command('opbx:cleanup-audit-logs')
    ->daily()
    ->at('02:00');
```

## Frontend Integration

The Platform Management UI is available at:

| Page | URL |
|------|-----|
| Dashboard | `/ui/platform/dashboard` |
| Organizations | `/ui/platform/organizations` |
| Organization Detail | `/ui/platform/organizations/{id}` |
| Users | `/ui/platform/users` |
| Audit Log | `/ui/platform/audit-log` |

The UI is only visible to users with `is_platform_manager = true`.

## Troubleshooting

### User Can't Access Platform Management

1. Check `is_platform_manager` flag in database:
   ```sql
   SELECT is_platform_manager FROM users WHERE email = 'user@example.com';
   ```

2. Ensure user has logged out and back in (token contains PM abilities)

3. Check browser console for auth errors

### Data Counts Don't Match

Ensure `OrganizationScope::bypass()` is being used in the controller:

```php
$organizations = OrganizationScope::bypass(function () {
    return Organization::withCount(['users', 'extensions'])->get();
});
```

### Missing Audit Logs

Verify the action is using `PlatformAuditService`:

```php
$auditService->log(
    request: $request,
    action: 'organization.status.updated',
    targetOrganizationId: $organization->id,
    beforeState: ['status' => $oldStatus],
    afterState: ['status' => $newStatus],
);
```

## Best Practices

1. **Limit Platform Managers**: Keep the number of PMs minimal (2-3 maximum)
2. **Regular Audit Review**: Check audit logs weekly for suspicious activity
3. **Strong Passwords**: Platform managers should use strong, unique passwords
4. **MFA**: Consider implementing MFA for platform manager accounts
5. **Token Expiry**: Use short-lived tokens for platform manager sessions

## See Also

- [API Reference](./api-reference.md)
- [Frontend Components](./frontend-components.md)
- [Security Checklist](./security-checklist.md)
