# Conference Rooms

## Overview
Conference bridge management with PIN protection, recording, and participant controls. Simple CRUD module.

## Source Files
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/ConferenceRoomController.php` | CRUD (extends AbstractApiCrudController) |
| `app/Models/ConferenceRoom.php` | Model |
| `app/Http/Requests/ConferenceRoom/StoreConferenceRoomRequest.php` | Create validation |
| `app/Http/Requests/ConferenceRoom/UpdateConferenceRoomRequest.php` | Update validation |
| `app/Policies/ConferenceRoomPolicy.php` | Authorization |
| `frontend/src/pages/ConferenceRooms.tsx` | Management page (1174 lines) |

## Database: `conference_rooms` Table
| Column | Type | Notes |
|--------|------|-------|
| id, organization_id | FK | Tenant scope |
| name | string | Unique per org |
| description | string nullable | |
| max_participants | integer | 2-1000 |
| status | enum | Uses UserStatus (active/inactive) |
| pin | string nullable | Numeric, max 20 |
| pin_required | boolean | |
| host_pin | string nullable | |
| recording_enabled, recording_auto_start | boolean | |
| recording_webhook_url | URL nullable | |
| wait_for_host, mute_on_entry, announce_join_leave, music_on_hold | boolean | |
| talk_detection_enabled | boolean | |
| talk_detection_webhook_url | URL nullable | Required when talk_detection_enabled |

## API Routes
Standard apiResource at `/v1/conference-rooms`

## Related Modules
- [Extensions](extensions.md) - CONFERENCE type extensions link to rooms
- [Voice Routing](voice-routing-engine.md) - ConferenceRoutingStrategy (identifier: `conf_{room_id}`)
- [IVR Menus](ivr-menus.md) - IVR options can route to conference rooms
