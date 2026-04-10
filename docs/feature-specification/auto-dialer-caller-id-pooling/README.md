# Auto Dialer Caller ID Pooling

## Feature Description
Enable auto-dialer campaigns to use multiple Caller IDs from the organization's active DIDs, with automatic cycling based on configurable strategies (Round Robin, Random, Least Recently Used).

## Documents

| Document | Purpose |
|----------|---------|
| [specification.md](specification.md) | Complete feature specification with requirements |
| [database-schema.md](database-schema.md) | Database changes and migrations |
| [api-specification.md](api-specification.md) | REST API and Worker API changes |
| [frontend-specification.md](frontend-specification.md) | React component specifications |
| [worker-specification.md](worker-specification.md) | Go worker implementation details |
| [implementation-plan.md](implementation-plan.md) | Step-by-step implementation schedule |

## Quick Summary

### What Changes
- Campaigns can have 1-100 Caller IDs instead of just 1
- 3 distribution strategies: Round Robin, Random, LRU
- Stats tracked per Caller ID
- Full backward compatibility with existing campaigns

### Key Components
1. **Database**: New pivot table for Caller ID pool, stats table
2. **Backend**: Updated Campaign CRUD, new endpoints
3. **Worker**: Strategy pattern for Caller ID selection
4. **Frontend**: Multi-select DID picker, strategy selector

### Timeline
- **Phase 1** (Week 1): Database & Backend models
- **Phase 2** (Week 2): Backend API
- **Phase 3** (Week 3): Go worker
- **Phase 4** (Week 4): Frontend
- **Phase 5** (Week 5): Testing & QA
- **Phase 6** (Week 6): Deployment

### Status
✅ **Specification Complete** - Ready for implementation

## Q4.2 Resolution

**Question:** When Cloudonix API rejects a Caller ID during call initiation, what should the worker do?

**Answer:** Cloudonix will never reject call initiation based on Caller ID. Call failures occur for other reasons (network, congestion). On retry (when `max_dial_attempts > 1`), the worker MUST select a **different** Caller ID from the pool.

**Implementation:**
- Track tried Caller IDs per destination in Redis (`dialer:retry:{campaign_id}:{destination_id}`)
- On retry, use `SelectWithRetry()` to exclude already tried DIDs
- When all DIDs have been tried, reset and cycle through pool again
- Clear retry tracking on call success or when max retries reached
- [auto-dialer-campaigns.md](../../memory/auto-dialer-campaigns.md)
- [dialer-worker.md](../../memory/dialer-worker.md)
