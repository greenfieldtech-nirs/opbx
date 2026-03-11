# Enums

This section documents all the enums used in the OPBX system for type safety and code clarity.

## User & Access Control

| Enum | Description |
|------|-------------|
| [UserRole](./user-role) | User roles: owner, pbx_admin, pbx_user, reporter |
| [UserStatus](./user-status) | Account status: active, inactive |
| [OrganizationStatus](./organization-status) | Organization status: active, inactive, suspended |

## Extension & Routing

| Enum | Description |
|------|-------------|
| [ExtensionType](./extension-type) | Extension types: user, conference, ring_group, ivr, ai_assistant, etc. |
| [RingGroupStrategy](./ring-group-strategy) | Ring strategies: simultaneous, round_robin, sequential |
| [RingGroupFallbackAction](./ring-group-fallback-action) | Fallback actions when no answer |
| [IvrDestinationType](./ivr-destination-type) | IVR routing destinations |

## AI Features

| Enum | Description |
|------|-------------|
| [AlbsStrategy](./albs-strategy) | AI Load Balancer strategies |

## Call Management

| Enum | Description |
|------|-------------|
| [CallStatus](./call-status) | Call lifecycle states |

## Security

| Enum | Description |
|------|-------------|
| [InboundBlacklistMatchType](./inbound-blacklist-match-type) | Blacklist matching types |

## Common Enum Methods

All enums typically include these methods:

### `label(): string`
Returns a human-readable label.

```php
UserRole::OWNER->label(); // "Owner"
```

### `description(): string`
Returns a detailed description (when available).

```php
ExtensionType::AI_ASSISTANT->description();
// "AI-powered virtual assistant"
```

### Boolean Check Methods

Most enums include methods for checking specific values:

```php
// UserRole
$user->role->isOwner();
$user->role->canManageUsers();

// UserStatus
$user->status->isActive();

// CallStatus
$call->status->isTerminal();
$call->status->isActive();
```

### `values(): array`
Returns all enum values as an array.

```php
ExtensionType::values();
// ['user', 'conference', 'ring_group', ...]
```

## Usage in Validation

```php
use App\Enums\UserRole;
use Illuminate\Validation\Rules\Enum;

$validated = $request->validate([
    'role' => ['required', new Enum(UserRole::class)],
]);
```

## Database Storage

All enums are stored as `VARCHAR` strings in the database:

```sql
role VARCHAR(50) NOT NULL DEFAULT 'pbx_user'
status VARCHAR(50) NOT NULL DEFAULT 'active'
```
