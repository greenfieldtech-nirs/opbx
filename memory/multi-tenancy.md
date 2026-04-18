# Multi-Tenancy

## Overview
All data is scoped by `organization_id`. Enforcement happens at three layers: database query (OrganizationScope), HTTP middleware (EnsureTenantScope), and authorization (Policies). Platform managers bypass tenant scoping.

## Source Files
| File | Purpose |
|------|---------|
| `app/Scopes/OrganizationScope.php` | Global query scope - auto-adds WHERE organization_id clause (92 lines) |
| `app/Http/Middleware/EnsureTenantScope.php` | Validates org exists and is active (78 lines) |
| `app/Models/Organization.php` | Tenant root model with SoftDeletes |
| `app/Enums/OrganizationStatus.php` | ACTIVE, SUSPENDED, DELETED |
| `bootstrap/app.php:33` | Registers `tenant.scope` middleware alias |

## OrganizationScope (`app/Scopes/OrganizationScope.php`)
- Applied via `#[ScopedBy([OrganizationScope::class])]` attribute on models
- **apply() logic (line 55-71)**:
  - If bypassed (`bypassCount > 0`): does nothing
  - If authenticated user has `organization_id`: adds `WHERE {table}.organization_id = ?`
  - If NO authenticated user: adds `WHERE 1 = 0` (security failsafe - returns empty)
- **Bypass pattern**: `OrganizationScope::bypass(fn() => User::all())` - counter-based, supports nesting

## EnsureTenantScope Middleware (`app/Http/Middleware/EnsureTenantScope.php:23`)
Applied as `tenant.scope` on all protected API routes (`routes/api.php:206`).
1. No user -> 401
2. Platform manager -> bypass (passes through)
3. No organization_id -> 403
4. Org not found -> 403
5. Org suspended -> 403 "suspended"
6. Org deleted -> 403 "not active"
7. Org not active (catch-all) -> 403

## Models Using OrganizationScope
All tenant-scoped models use `#[ScopedBy([OrganizationScope::class])]`:
User, Extension, DidNumber, RingGroup, CallLog, SessionUpdate, IvrMenu, ConferenceRoom, AiAssistant, AiAssistantLoadBalancer, OutboundWhitelist, InboundBlacklist, BusinessHoursSchedule, AutoDialerCampaign, AutoDialerList, AutoDialerDestination, AutoDialerCallSession, BlockedCallLog, BusinessHours, Recording, CallDetailRecord, CallNotificationsSettings

**All use `#[ScopedBy([OrganizationScope::class])]` attribute** (the boot() method note is outdated)

**NOT scoped** (no organization_id): Organization, PlatformAuditLog, AiAssistantLoadBalancerMember, RingGroupMember, IvrMenuOption, BusinessHoursScheduleDay, BusinessHoursTimeRange, BusinessHoursException, BusinessHoursExceptionTimeRange, EmailLog, AutoDialerCampaignCallerId, AutoDialerCallerIdStat

## Organization Model (`app/Models/Organization.php`)
- Table: `organizations` (id, name, slug, status, timezone, settings JSON, timestamps, deleted_at)
- Uses SoftDeletes
- Auto-generates slug from name on creation (boot method)
- Relationships: users, extensions, didNumbers, ringGroups, businessHoursSchedules, callLogs, cloudonixSettings (HasOne)
- `isActive()`: checks `status === 'active'` (string comparison, not enum)

## Key Pattern: Bypassing Scope for Cross-Tenant Operations
```php
OrganizationScope::bypass(function () {
    return User::where('email', $email)->first();
});
```
Used by: AuthController (login), Platform controllers, Webhook controllers, Dialer worker API

## Related Modules
- [Authentication](authentication-authorization.md) - Auth flow that sets up the authenticated user
- [Platform Management](platform-management.md) - Cross-tenant operations
