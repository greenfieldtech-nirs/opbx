# Outbound Whitelist

## Overview
Controls which external numbers PBX users can dial. Entries define country/prefix rules with associated outbound trunks.

## Source Files
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/OutboundWhitelistController.php` | CRUD + toggleStatus |
| `app/Models/OutboundWhitelist.php` | Model |
| `app/Enums/WhitelistStatus.php` | ACTIVE, INACTIVE |
| `app/Services/VoiceRouting/OutboundRoutingService.php` | Whitelist matching (318 lines) |
| `frontend/src/pages/OutboundWhitelist.tsx` | Management page |
| `frontend/src/services/outboundWhitelist.service.ts` | API calls |

## Database: `outbound_whitelists` Table
organization_id, name, destination_country, destination_prefix, outbound_trunk_name, status (WhitelistStatus)

## Matching Algorithm (OutboundRoutingService::findOutboundWhitelistEntry)
Scoring system: country match (+10), full international prefix (+length), local prefix (+length). Best score wins. Entry with prefix that doesn't match is rejected entirely.

## API Routes
| Method | URI | Purpose |
|--------|-----|---------|
| Standard CRUD | `/v1/outbound-whitelist[/{outbound_whitelist}]` | apiResource |
| PATCH | `/v1/outbound-whitelist/{outbound_whitelist}/toggle-status` | Status toggle |

## Related Modules
- [Voice Routing](voice-routing-engine.md) - OutboundRoutingService used during call routing
- [Auto Dialer](auto-dialer-campaigns.md) - Outbound trunk selection for campaigns
