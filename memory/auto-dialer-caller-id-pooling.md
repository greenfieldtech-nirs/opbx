# Auto Dialer Caller ID Pooling

## Overview
Feature allowing auto-dialer campaigns to use multiple Caller IDs from the organization's active DIDs. The system cycles through Caller IDs using configurable strategies (Round Robin, Random, Least Recently Used) for carrier load distribution and compliance.

## Status
✅ **Implemented** - Feature is complete and deployed

## Key Features
- **Max Caller IDs**: 100 per campaign
- **Weight Support**: Same DID can be added multiple times for weighted distribution (backend support, UI simplified)
- **Backward Compatible**: Existing campaigns auto-migrated
- **Stats Tracking**: Per-Caller ID success/failure rates
- **State Management**: Redis-based (ephemeral, per-campaign)
- **Retry-Aware**: On retry, different Caller ID is selected (tracks tried DIDs in Redis)
- **CSP Support**: Custom error pages use nonce-based CSP for inline scripts

## Source Files

### Backend (Laravel)
| File | Purpose |
|------|---------|
| `app/Enums/CallerIdStrategy.php` | Strategy enum (round_robin, random, least_recently_used) |
| `app/Models/AutoDialerCampaignCallerId.php` | Pivot model for campaign-DID relationship |
| `app/Models/AutoDialerCallerIdStat.php` | Statistics model for per-DID tracking |
| `app/Models/AutoDialerCampaign.php` | Updated with callerIds() and callerIdStats() relationships |
| `app/Models/DidNumber.php` | Added forOrganization() scope |
| `app/Http/Controllers/AutoDialerCampaignController.php` | CRUD with pool management, eager loads callerIds |
| `app/Http/Controllers/DialerWorkerController.php` | Accepts caller_id and caller_did_id in initiateCall |
| `app/Http/Requests/CreateCampaignRequest.php` | Validation for pool creation |
| `app/Http/Requests/UpdateCampaignRequest.php` | Validation for pool updates (DRAFT/PAUSED only) |
| `app/Http/Resources/AutoDialerCampaignResource.php` | Includes caller_id_pool and caller_id_stats |
| `app/Services/AutoDialer/AutoDialerCloudonixService.php` | Uses selected Caller ID from pool for API calls |
| `database/migrations/2026_04_10_000001_add_caller_id_pooling_to_auto_dialer.php` | Creates tables and columns |

### Go Worker
| File | Purpose |
|------|---------|
| `dialer-worker/internal/callerid/strategy.go` | Strategy interface with Select and SelectWithRetry |
| `dialer-worker/internal/callerid/round_robin.go` | Round Robin implementation with Redis counter |
| `dialer-worker/internal/callerid/random.go` | Random selection implementation |
| `dialer-worker/internal/callerid/lru.go` | LRU implementation with Redis hash |
| `dialer-worker/internal/callerid/retry_tracker.go` | Tracks tried DIDs per destination |
| `dialer-worker/internal/executor/executor.go` | Integrates strategy selection with retry logic |
| `dialer-worker/internal/redis/client.go` | Added Incr, Expire, HGetAll, HSet, SAdd, SMembers, Del |
| `dialer-worker/internal/models/models.go` | Campaign model with CallerIDPool fields |

### Frontend (React)
| File | Purpose |
|------|---------|
| `frontend/src/components/AutoDialer/StrategySelector.tsx` | Card-based strategy picker |
| `frontend/src/components/AutoDialer/CallerIdPoolSelector.tsx` | Multi-select DID picker |
| `frontend/src/hooks/useCallerIdPool.ts` | React Query hooks for pool operations |
| `frontend/src/pages/AutoDialerCampaignForm.tsx` | Campaign form with pool editor |
| `frontend/src/pages/AutoDialerCampaignDetail.tsx` | Campaign detail with pool badges |
| `frontend/src/pages/AutoDialerCampaigns.tsx` | List view with Caller ID count badge |
| `frontend/src/services/autoDialerCampaignsApi.ts` | API methods for pool operations |

### Documentation
| File | Purpose |
|------|---------|
| `docs/feature-specification/auto-dialer-caller-id-pooling/specification.md` | Feature requirements |
| `docs/feature-specification/auto-dialer-caller-id-pooling/database-schema.md` | Database design |
| `docs/feature-specification/auto-dialer-caller-id-pooling/api-specification.md` | API contracts |
| `docs/feature-specification/auto-dialer-caller-id-pooling/frontend-specification.md` | UI specifications |
| `docs/feature-specification/auto-dialer-caller-id-pooling/worker-specification.md` | Go worker implementation |
| `docs/feature-specification/auto-dialer-caller-id-pooling/implementation-plan.md` | 6-week timeline |

## Database Schema

### Tables
- `auto_dialer_campaign_caller_ids` - Campaign-DID pivot with weight
- `auto_dialer_caller_id_stats` - Usage statistics per DID per campaign

### Modified Tables
- `auto_dialer_campaigns` - Adds `caller_id_strategy`, `caller_id_pool_enabled`
- `auto_dialer_call_sessions` - Adds `caller_did_id` (FK to did_numbers)

## Distribution Strategies

| Strategy | Description | State Storage | Retry Behavior |
|----------|-------------|---------------|----------------|
| Round Robin | Sequential cycling (1,2,3,1,2,3...) | Redis: `dialer:rr:{id}:position` | Skips tried DIDs, resets when all tried |
| Random | Weighted random selection | Stateless | Excludes tried DIDs from selection |
| Least Recently Used | Select least recently used | Redis: `dialer:lru:{id}:timestamps` | Considers tried DIDs as "recently used" |

## Go Worker: Retry Tracking

### Flow
1. First attempt: Select Caller ID using strategy
2. If call fails AND retry permitted: Mark DID as tried in Redis
3. Retry attempt: Use `SelectWithRetry()` to exclude tried DIDs
4. When all DIDs tried: Reset and cycle through pool again
5. On success: Clear retry tracking for destination

### Redis Keys
| Pattern | Purpose | TTL |
|---------|---------|-----|
| `dialer:rr:{campaign_id}:position` | Round robin counter | 24h |
| `dialer:lru:{campaign_id}:timestamps` | LRU timestamps hash | 24h |
| `dialer:retry:{campaign_id}:{destination_id}` | Retry tracking (tried DIDs) | 1h |

## API Routes

### User-Facing
| Method | URI | Purpose |
|--------|-----|---------|
| GET | `/v1/auto-dialer-campaigns/available-caller-ids` | List available DIDs |
| GET | `/{campaign}/caller-id-stats` | Get usage statistics |
| POST | `/{campaign}/reset-caller-id-cycle` | Reset Round Robin position |

### Worker-Facing
| Method | URI | Changes |
|--------|-----|---------|
| GET | `/v1/dialer/worker/campaigns/active` | Includes `caller_id_pool`, `caller_id_strategy` |
| POST | `/v1/dialer/worker/calls/initiate` | Accepts `caller_id` and `caller_did_id` parameters |

## Implementation Notes

### UI Simplifications
- Weight input removed from UI (still supported in API)
- Pool summary removed from campaign form
- Pool displayed as badges in campaign detail

### Backend Changes
- Weight is optional in validation (defaults to 1)
- Eager load `callerIds` in index() for list view performance
- Pass selected Caller ID to Cloudonix API (not campaign default)

### CSP Integration
- Error pages use nonce-based CSP for inline scripts
- Exception handler generates nonce and sets CSP header
- Script tag includes `nonce="{{ $csp_nonce }}"`

## Related Modules
- [Auto Dialer Campaigns](auto-dialer-campaigns.md) - Parent module
- [Dialer Worker](dialer-worker.md) - Go worker integration
- [Phone Numbers](phone-numbers.md) - DID source
