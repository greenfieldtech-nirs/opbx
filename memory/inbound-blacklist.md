# Inbound Blacklist

## Overview
Blocks unwanted inbound calls using pattern matching (exact, prefix, wildcard). Three rejection strategies: silent drop, audible reject, or "torment" (conference hold music). Supports global rules and per-DID rules.

## Source Files
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/InboundBlacklistController.php` | CRUD + stats + blocked logs |
| `app/Models/InboundBlacklist.php` | Blacklist entry model |
| `app/Models/BlockedCallLog.php` | Blocked call audit log |
| `app/Services/InboundBlacklist/InboundBlacklistService.php` | Matching + CXML generation |
| `app/Enums/InboundBlacklistMatchType.php` | EXACT, PREFIX, WILDCARD |
| `app/Enums/InboundBlacklistRejectionStrategy.php` | DROP, REJECT, TORMENT |
| `app/Enums/InboundBlacklistStatus.php` | ACTIVE, INACTIVE |
| `frontend/src/pages/InboundBlacklist.tsx` | Management page |

## Database Tables
### `inbound_blacklists`
organization_id, match_type, caller_id_pattern, is_global (bool), rejection_strategy, torment_room_prefix, torment_music_timeout, status, blocked_count

### `inbound_blacklist_did_number` (pivot)
inbound_blacklist_id, did_number_id (BelongsToMany)

### `blocked_call_logs`
organization_id, inbound_blacklist_id, did_number_id, caller_id, called_number, call_sid, session_id, rejection_strategy, torment_room_id, torment_duration, webhook_payload (JSON), source_ip, blocked_at

## Matching Logic (InboundBlacklist::matches)
- EXACT: `===` comparison
- PREFIX: `str_starts_with()`
- WILDCARD: `fnmatch()` (supports `*` and `?`)

## Rejection Strategies (InboundBlacklistService)
| Strategy | CXML Response |
|----------|--------------|
| DROP | Silent `<Hangup/>` |
| REJECT | `<Say>Your call has been rejected</Say><Hangup/>` |
| TORMENT | `<Conference>` with hold music, configurable timeout |

## Integration with Voice Routing
`InboundBlacklistService::isBlacklisted()` is called by `VoiceRoutingManager` before routing. Checks active entries for the org that are either global OR apply to the specific DID (via pivot table).

## API Routes
| Method | URI | Purpose |
|--------|-----|---------|
| Standard CRUD | `/v1/inbound-blacklist[/{inbound_blacklist}]` | apiResource |
| PATCH | `/v1/inbound-blacklist/{blacklist}/toggle-status` | Status toggle |
| GET | `/v1/inbound-blacklist/blocked-calls` | Blocked call logs |
| GET | `/v1/inbound-blacklist/statistics` | Cached (5min) aggregate stats |

## Related Modules
- [Voice Routing](voice-routing-engine.md) - Blacklist checked before routing
- [Phone Numbers](phone-numbers.md) - Per-DID blacklist rules
