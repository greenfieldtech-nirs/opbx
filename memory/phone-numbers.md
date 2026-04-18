# Phone Numbers (DIDs)

## Overview
DID (Direct Inward Dialing) numbers are the entry points for inbound calls. Each DID has a routing configuration that determines where calls are sent. Phone numbers are globally unique (not per-org).

## Source Files

### Backend
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/PhoneNumberController.php` | DID CRUD (559 lines, custom implementation) |
| `app/Models/DidNumber.php` | DID model with polymorphic routing (358 lines) |
| `app/Services/PhoneNumberService.php` | E.164 parsing via libphonenumber |
| `app/Http/Requests/PhoneNumber/StorePhoneNumberRequest.php` | Create validation (485 lines) |
| `app/Http/Requests/PhoneNumber/UpdatePhoneNumberRequest.php` | Update validation |
| `app/Http/Resources/PhoneNumberResource.php` | API response with related resource |
| `app/Policies/DidNumberPolicy.php` | Authorization |

### Frontend
| File | Purpose |
|------|---------|
| `frontend/src/pages/PhoneNumbers.tsx` | Phone number management (627 lines) |
| `frontend/src/components/PhoneNumbers/PhoneNumberDialog.tsx` | Create/edit dialog |
| `frontend/src/components/DIDs/DIDForm.tsx` | Legacy form component |

## Database: `did_numbers` Table
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| organization_id | FK | Tenant scope |
| phone_number | string(20) | **Globally unique** |
| friendly_name | string nullable | Display label |
| routing_type | enum | extension, ring_group, business_hours, conference_room, ai_assistant, ivr_menu, ai_load_balancer |
| routing_config | JSON | `{extension_id: N}` or `{ring_group_id: N}` etc. |
| status | enum | active, inactive |
| cloudonix_config | JSON nullable | Cloudonix metadata |

## Routing Types & Config Keys
| routing_type | Config Key | Target Model |
|-------------|-----------|-------------|
| extension | `extension_id` | Extension |
| ring_group | `ring_group_id` | RingGroup |
| business_hours | `business_hours_schedule_id` | BusinessHoursSchedule |
| conference_room | `conference_room_id` | ConferenceRoom |
| ai_assistant | `ai_assistant_id` | AiAssistant |
| ivr_menu | `ivr_menu_id` | IvrMenu |
| ai_load_balancer | `ai_load_balancer_id` | AiAssistantLoadBalancer |

## Polymorphic Loading Pattern (`DidNumber.php`)
Routing targets are NOT standard Eloquent relationships. Target IDs are stored in JSON `routing_config`:
- `getTargetExtensionId()` -> reads `routing_config['extension_id']`
- `getExtensionAttribute()` -> lazy-loads Extension model using `withoutGlobalScope(OrganizationScope::class)` (line 152)
- `setExtension()` -> manual setter for batch loading optimization
- **Controller** batch-loads all targets per type to prevent N+1 queries

Similar pattern for all 7 routing types (RingGroup at line 177, BusinessHoursSchedule at line 201, ConferenceRoom at line 225, AiAssistant at line 249, IvrMenu at line 273, AiAssistantLoadBalancer at line 297).

## Validation Rules
- `phone_number`: E.164 format (`/^\+[1-9]\d{1,14}$/`), globally unique
- Non-E.164 mode: digits + # only (toggle via `enable_non_e164`)
- `phone_number` is **immutable** after creation (prohibited in UpdatePhoneNumberRequest)
- Routing targets must: exist, belong to same org, be active

## API Routes
Standard apiResource at `/v1/phone-numbers` (index, store, show, update, destroy)

## Related Modules
- [Voice Routing](voice-routing-engine.md) - DIDs are the primary inbound routing entry point
- [Extensions](extensions.md) - Extension routing target
- [Ring Groups](ring-groups.md) - Ring group routing target
- [Business Hours](business-hours.md) - Business hours routing target
- [Destination Routing](destination-routing-system.md) - Unified destination selection
