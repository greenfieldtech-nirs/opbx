# OPBX Data Models

This section provides comprehensive documentation for all data models used in the OPBX (Open Source Business PBX) Laravel backend.

## Overview

OPBX uses a multi-tenant architecture where all data is scoped to an `Organization`. The data models are organized into the following categories:

## Model Categories

### Core Models
- **[User](./models/user)** - System users with role-based access control
- **[Organization](./models/organization)** - Multi-tenant organization container
- **[Extension](./models/extension)** - PBX extensions (the central entity for call routing)

### PBX Resource Models
- **[RingGroup](./models/ring-group)** - Call distribution groups
- **[ConferenceRoom](./models/conference-room)** - Multi-party conference rooms
- **[DidNumber](./models/did-number)** - Direct Inward Dialing (phone numbers)

### Call Management Models
- **[CallLog](./models/call-log)** - Call activity logs
- **[CallDetailRecord](./models/call-detail-record)** - Billing and audit records
- **[Recording](./models/recording)** - Call recordings

### AI Feature Models
- **[AiAssistant](./models/ai-assistant)** - AI assistant configuration
- **[AiAssistantLoadBalancer](./models/ai-assistant-load-balancer)** - AI load balancing

### Configuration Models
- **[BusinessHoursSchedule](./models/business-hours-schedule)** - Business hours
- **[IvrMenu](./models/ivr-menu)** - Interactive Voice Response menus
- **[CloudonixSettings](./models/cloudonix-settings)** - Cloudonix integration
- **[CallNotificationsSettings](./models/call-notifications-settings)** - Webhook notifications

### Security Models
- **[InboundBlacklist](./models/inbound-blacklist)** - Blocked callers
- **[OutboundWhitelist](./models/outbound-whitelist)** - Outbound dialing restrictions

## Enums

All enums are documented in the [Enums](./enums) section:

- **UserRole** - owner, pbx_admin, pbx_user, reporter
- **UserStatus** - active, inactive
- **ExtensionType** - user, conference, ring_group, ivr, ai_assistant, etc.
- **RingGroupStrategy** - simultaneous, round_robin, priority, weighted, memory
- And more...

## Entity Relationships

See the [Entity Relationships](./relationships/entity-relationships) page for a complete diagram of model relationships.

## Common Patterns

### Multi-Tenancy
All models use the `OrganizationScope` global scope to ensure data isolation:

```php
#[ScopedBy([OrganizationScope::class])]
class Model extends Model
{
    // Automatically scoped by organization_id
}
```

### Soft Deletes
Most models support soft deletes for data recovery.

### Audit Fields
Models track creation and update information via relationships to the `User` model.

### JSON Configuration
Many models use JSON columns for flexible configuration storage.
