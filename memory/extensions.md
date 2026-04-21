# Extensions

## Change History

### 2026-04-11: ExtensionController Deleted (CR-5)
**File:** `app/Http/Controllers/Api/ExtensionController.php` (DELETED)

**Reason:** Dead code removal. The controller (909 lines) had no route references and was superseded by:
- `ExtensionCrudController` - CRUD operations via `AbstractApiCrudController`
- `ExtensionCloudonixController` - Cloudonix sync operations  
- `ExtensionPasswordController` - Password operations

**Migration:** All functionality already migrated. No breaking changes.

---

## Overview
SIP extensions are the core routing entities in the PBX. Each extension has a type (user, conference, ring_group, ivr, ai_assistant, forward, ai_load_balancer, custom_logic) that determines its behavior. User-type extensions are synced to Cloudonix as subscribers.

## Source Files

### Backend
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/ExtensionCrudController.php` | Extension CRUD (extends AbstractApiCrudController) |
| `app/Http/Controllers/Api/ExtensionPasswordController.php` | SIP password get/reset |
| `app/Http/Controllers/Api/ExtensionCloudonixController.php` | Bidirectional Cloudonix sync |
| `app/Models/Extension.php` | Extension model (309 lines) |
| `app/Enums/ExtensionType.php` | 8 extension types (111 lines) |
| `app/Services/CloudonixClient/CloudonixSubscriberService.php` | Cloudonix subscriber sync (697 lines) |
| `app/Services/PasswordGenerator.php` | Secure password generation |
| `app/Http/Requests/Extension/StoreExtensionRequest.php` | Create validation |
| `app/Http/Requests/Extension/UpdateExtensionRequest.php` | Update validation |
| `app/Http/Resources/ExtensionResource.php` | API response transformer |
| `app/Policies/ExtensionPolicy.php` | Authorization |

### Frontend
| File | Purpose |
|------|---------|
| `frontend/src/pages/Extensions.tsx` | Extension management page (2231 lines) |
| `frontend/src/services/extensions.service.ts` | Extension API + sync/password endpoints |
| `frontend/src/components/Extensions/AiAssistantConfigForm.tsx` | Dynamic AI provider config form |

## Database: `extensions` Table
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| organization_id | FK | Tenant scope |
| user_id | FK nullable | Only for USER type |
| extension_number | string(5) | 3-5 digits, unique per org |
| password | string(32) | SIP auth, plain text (for SIP client sharing) |
| type | enum | user, conference, ring_group, ivr, ai_assistant, custom_logic, forward, ai_load_balancer |
| status | enum | active, inactive |
| voicemail_enabled | boolean | Only for USER type |
| configuration | JSON | Type-specific config |
| service_url | string nullable | AI assistant service URL |
| service_token | string nullable | AI assistant service token |
| service_params | JSON nullable | AI assistant service params |
| ai_assistant_id | FK nullable | Direct AI assistant link |
| cloudonix_subscriber_id | string nullable | Cloudonix sync tracking |
| cloudonix_uuid | string nullable | |
| cloudonix_synced | boolean | |

## Extension Types (`app/Enums/ExtensionType.php`)
| Type | Value | Required Config |
|------|-------|----------------|
| USER | `user` | None (password auto-generated) |
| CONFERENCE | `conference` | `conference_room_id` |
| RING_GROUP | `ring_group` | `ring_group_id` |
| IVR | `ivr` | `ivr_id` |
| AI_ASSISTANT | `ai_assistant` | `provider`, `phone_number` |
| CUSTOM_LOGIC | `custom_logic` | `container_application_name`, `container_block_name` |
| FORWARD | `forward` | `forward_to` |
| AI_LOAD_BALANCER | `ai_load_balancer` | `ai_load_balancer_id` |

## API Routes
| Method | URI | Controller | Notes |
|--------|-----|-----------|-------|
| GET | `/v1/extensions/sync/compare` | ExtensionCloudonixController@compareSync | Compare local vs Cloudonix |
| POST | `/v1/extensions/sync` | ExtensionCloudonixController@performSync | Bidirectional sync |
| GET/POST/PUT/DELETE | `/v1/extensions[/{extension}]` | ExtensionCrudController | Standard CRUD |
| GET | `/v1/extensions/{extension}/password` | ExtensionPasswordController@getPassword | Sensitive (no-cache headers) |
| PUT | `/v1/extensions/{extension}/reset-password` | ExtensionPasswordController@resetPassword | Sensitive |

## Cloudonix Sync Lifecycle
- **Create USER**: Generate 32-char hex password -> DB insert -> `syncToCloudnonix()` (creates subscriber)
- **Type change TO USER**: Generate password -> sync to Cloudonix
- **Type change FROM USER**: Clear password -> unsync from Cloudonix (delete subscriber)
- **Delete USER**: Unsync from Cloudonix -> DB delete
- **Bidirectional sync**: Phase 1 (local->Cloudonix) + Phase 2 (Cloudonix->local, skip MSISDN>5) + Phase 3 (status alignment)

## Immutability
- `extension_number` cannot be changed after creation (validated in UpdateExtensionRequest)

## Authorization (`ExtensionPolicy.php`)
| Action | Owner | PBX Admin | PBX User | Reporter |
|--------|-------|-----------|----------|----------|
| viewAny/view | Yes | Yes | Yes | Yes |
| create | Yes | Yes | No | No |
| update | Yes | Yes | Own only | No |
| delete | Yes | Yes | No | No |

## Related Modules
- [Phone Numbers](phone-numbers.md) - DIDs route to extensions
- [Ring Groups](ring-groups.md) - Extensions are ring group members
- [AI Assistants](ai-assistants.md) - AI_ASSISTANT type extensions
- [Voice Routing](voice-routing-engine.md) - Extensions are routing targets
- [Settings & Cloudonix](settings-cloudonix.md) - Subscriber sync requires settings
