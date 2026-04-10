# Caller ID Pooling - Implementation Plan

## Overview
Step-by-step implementation plan for the Auto Dialer Caller ID Pooling feature.

---

## Phase 1: Database & Backend (Week 1)

### Day 1-2: Database Migration
- [ ] Create migration for new tables
  - `auto_dialer_campaign_caller_ids`
  - `auto_dialer_caller_id_stats`
- [ ] Add columns to `auto_dialer_campaigns`
- [ ] Add column to `auto_dialer_call_sessions`
- [ ] Create `CallerIdStrategy` enum

### Day 3-4: Models & Relationships
- [ ] Create `AutoDialerCampaignCallerId` model
- [ ] Create `AutoDialerCallerIdStat` model
- [ ] Update `AutoDialerCampaign` relationships
- [ ] Update `AutoDialerCallSession` model

### Day 5: Data Migration
- [ ] Write migration script for existing campaigns
- [ ] Test migration on staging data
- [ ] Create rollback script

---

## Phase 2: Backend API (Week 2)

### Day 1-2: Campaign CRUD Updates
- [ ] Update `AutoDialerCampaignController@store`
- [ ] Update `AutoDialerCampaignController@update`
- [ ] Update `AutoDialerCampaignController@show`
- [ ] Update Form Request validators

### Day 3: New Endpoints
- [ ] Create `GET /available-caller-ids` endpoint
- [ ] Create `GET /{campaign}/caller-id-stats` endpoint
- [ ] Create `POST /{campaign}/reset-caller-id-cycle` endpoint

### Day 4: Dialer Worker API
- [ ] Update `DialerWorkerController@getActiveCampaigns`
- [ ] Update `DialerWorkerController@initiateCall`
- [ ] Update response transformers

### Day 5: Webhook Integration
- [ ] Update CDR processing to record Caller ID stats
- [ ] Update `AutoDialerCallSession` creation
- [ ] Test webhook flow end-to-end

---

## Phase 3: Go Worker (Week 3)

### Day 1-2: Strategy Implementation
- [ ] Create `callerid` package
- [ ] Implement `Strategy` interface
- [ ] Implement Round Robin strategy
- [ ] Implement Random strategy
- [ ] Implement LRU strategy

### Day 3: Integration
- [ ] Update `Campaign` model
- [ ] Update `Executor` to use strategies
- [ ] Update Redis client with new methods
- [ ] Update API client

### Day 4: Testing
- [ ] Unit tests for strategies
- [ ] Integration tests with mock Redis
- [ ] Load testing

### Day 5: Deployment Prep
- [ ] Build new worker image
- [ ] Update Docker Compose
- [ ] Create deployment checklist

---

## Phase 4: Frontend (Week 4)

### Day 1-2: Components
- [ ] Create `StrategySelector` component
- [ ] Create `CallerIdPoolSelector` component
- [ ] Create `CallerIdPoolSummary` component

### Day 3: Form Integration
- [ ] Update campaign form
- [ ] Update Zod schema
- [ ] Add validation rules
- [ ] Update API hooks

### Day 4: Detail & Monitor Pages
- [ ] Update campaign detail page
- [ ] Add Caller ID stats section
- [ ] Update monitor active calls table

### Day 5: Testing
- [ ] Unit tests for components
- [ ] Integration tests
- [ ] Accessibility audit

---

## Phase 5: Testing & QA (Week 5)

### Day 1-2: Backend Testing
- [ ] Feature tests for all endpoints
- [ ] Unit tests for models
- [ ] Load testing (100 concurrent campaigns)

### Day 3: Integration Testing
- [ ] End-to-end call flow testing
- [ ] Verify Caller ID rotation
- [ ] Verify stats tracking

### Day 4: Regression Testing
- [ ] Test existing campaigns (backward compatibility)
- [ ] Test campaign pause/resume
- [ ] Test campaign archive

### Day 5: Bug Fixes
- [ ] Address any issues found
- [ ] Performance optimization
- [ ] Documentation review

---

## Phase 6: Deployment (Week 6)

### Pre-Deployment
- [ ] Final code review
- [ ] Security review
- [ ] Update user documentation

### Deployment Steps
1. **Database Migration**
   ```bash
   docker compose exec app php artisan migrate
   ```

2. **Backend Deployment**
   ```bash
   # Deploy Laravel changes
   git pull origin main
   composer install --no-dev
   php artisan optimize
   ```

3. **Worker Deployment**
   ```bash
   # Build and deploy Go worker
   docker compose build dialer-worker
   docker compose up -d dialer-worker
   ```

4. **Frontend Deployment**
   ```bash
   cd frontend
   npm run build
   # Deploy static files
   ```

### Post-Deployment
- [ ] Monitor error logs
- [ ] Verify active campaigns
- [ ] Check Redis keys
- [ ] Verify Caller ID rotation

---

## Rollback Plan

### Immediate Rollback (< 1 hour)
```bash
# 1. Stop worker
docker compose stop dialer-worker

# 2. Revert backend
git revert HEAD
composer install --no-dev

# 3. Restart services
docker compose up -d

# 4. Optional: Rollback migration
docker compose exec app php artisan migrate:rollback
```

### Data Recovery
- Migration is reversible
- Stats data can be truncated safely
- Original Caller ID preserved in campaign table

---

## Testing Checklist

### Unit Tests
- [ ] Strategy algorithms
- [ ] Model relationships
- [ ] Validation rules

### Feature Tests
- [ ] Campaign creation with pool
- [ ] Campaign update pool (paused)
- [ ] Caller ID selection per strategy
- [ ] Stats tracking

### Integration Tests
- [ ] End-to-end call with pool
- [ ] Worker selects correct Caller ID
- [ ] CDR updates stats
- [ ] Pause/resume preserves state

### Load Tests
- [ ] 100 campaigns, 10 Caller IDs each
- [ ] 1000 calls/minute
- [ ] Redis connection stability

---

## Success Criteria

1. **Functionality**
   - [ ] Can create campaign with 1-100 Caller IDs
   - [ ] All 3 strategies work correctly
   - [ ] Stats tracked accurately
   - [ ] Backward compatibility maintained

2. **Performance**
   - [ ] Caller ID selection < 10ms
   - [ ] No increase in call initiation latency
   - [ ] Redis memory usage < 100MB for 1000 campaigns

3. **Reliability**
   - [ ] 99.9% uptime during migration
   - [ ] No data loss
   - [ ] Graceful degradation on Redis failure

---

## Open Questions

1. **Q4.2 Clarification Needed**: When Cloudonix API rejects a Caller ID (e.g., "invalid format"), should the worker:
   - (a) Retry same call with next Caller ID automatically
   - (b) Mark call as failed and let retry logic handle it
   - (c) Alert and skip this Caller ID for future calls

2. **Monitoring**: Should we add alerts for:
   - Uneven Caller ID distribution?
   - Specific Caller ID failure rates?
   - Redis strategy key expiration?

---

## Risks & Mitigation

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Redis failure | Low | Medium | Fallback to random selection |
| Migration data loss | Low | High | Backup before migration, reversible |
| Performance degradation | Low | Medium | Load testing, caching |
| Strategy bugs | Medium | Medium | Unit tests, gradual rollout |
