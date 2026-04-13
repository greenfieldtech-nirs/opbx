# Auto Dialer Implementation Plan

## Overview

This document outlines the phased implementation of the Auto Dialer feature, with detailed steps for each phase.

## Phase 1: Foundation & Database Schema
**Estimated Duration: 3-4 days**
**Priority: Critical**

### Goals
- Create all database tables and relationships
- Set up models with proper scopes and relationships
- Create migration files
- Add basic seeders for testing

### Tasks

#### 1.1 Database Migrations
- [ ] Create migration for `auto_dialer_campaigns` table
- [ ] Create migration for `auto_dialer_lists` table
- [ ] Create migration for `auto_dialer_destinations` table
- [ ] Create migration for `auto_dialer_call_sessions` table
- [ ] Create migration to add `is_auto_dialer` flag to CDR table
- [ ] Run migrations and verify schema

#### 1.2 Eloquent Models
- [ ] Create `AutoDialerCampaign` model with relationships
- [ ] Create `AutoDialerList` model with relationships
- [ ] Create `AutoDialerDestination` model with relationships
- [ ] Create `AutoDialerCallSession` model with relationships
- [ ] Add scopes for common queries (active, pending, etc.)
- [ ] Add helper methods (isRunnable, getProgress, etc.)

#### 1.3 Enums
- [ ] Create `CampaignStatus` enum (draft, active, paused, completed, archived)
- [ ] Create `DestinationStatus` enum (pending, dialing, connected, failed, completed, invalid)
- [ ] Create `RoutingDestinationType` enum (ai_assistant, ai_load_balancer, hangup)
- [ ] Create `AmdMode` enum (Enabled, DetectMessageEnd)

#### 1.4 Factory & Seeder
- [ ] Create factories for all models
- [ ] Create seeders with sample data
- [ ] Add to DatabaseSeeder

### Acceptance Criteria
- All migrations run successfully
- Models can create/read/update records
- Relationships work correctly
- Enums are properly typed

---

## Phase 2: Core Backend API - Campaign CRUD
**Estimated Duration: 4-5 days**
**Priority: Critical**

### Goals
- Build REST API for campaign management
- Implement authorization policies
- Add validation rules
- Create API resources for JSON responses

### Tasks

#### 2.1 Form Requests
- [ ] Create `CreateCampaignRequest` with validation rules
- [ ] Create `UpdateCampaignRequest` with validation rules
- [ ] Create `UploadListRequest` for CSV uploads

#### 2.2 Controller
- [ ] Create `AutoDialerCampaignController` extending AbstractApiCrudController
- [ ] Implement `getModelClass()` method
- [ ] Implement `getResourceClass()` method
- [ ] Implement `getAllowedFilters()` method
- [ ] Implement `getAllowedSortFields()` method
- [ ] Implement `getDefaultSortField()` method
- [ ] Override `beforeStore()` to set defaults
- [ ] Override `afterStore()` for audit logging
- [ ] Override `beforeUpdate()` for validation
- [ ] Override `afterUpdate()` for audit logging
- [ ] Add custom methods: start(), pause(), resume(), archive()

#### 2.3 API Resource
- [ ] Create `AutoDialerCampaignResource`
- [ ] Include computed fields (progress percentage, statistics)
- [ ] Include relationships (list count, destination counts)

#### 2.4 Policy
- [ ] Create `AutoDialerCampaignPolicy`
- [ ] Implement viewAny() - Owner/PBX Admin only
- [ ] Implement view() - Same organization
- [ ] Implement create() - Owner/PBX Admin only
- [ ] Implement update() - Same organization + can manage
- [ ] Implement delete() - Owner only

#### 2.5 Routes
- [ ] Add API routes in routes/api.php
- [ ] Group under 'auto-dialer-campaigns' resource
- [ ] Add custom routes for actions (start, pause, etc.)
- [ ] Apply auth and policy middleware

#### 2.6 List Upload Endpoint
- [ ] Create upload endpoint in controller
- [ ] Parse CSV file
- [ ] Validate phone numbers (E.164)
- [ ] Remove duplicates
- [ ] Create destinations
- [ ] Update campaign statistics

### Acceptance Criteria
- All CRUD endpoints return proper JSON
- Validation errors are properly formatted
- Authorization works correctly
- List upload processes CSV correctly
- Audit logs are created for all actions

---

## Phase 3: Cloudonix Integration
**Estimated Duration: 3-4 days**
**Priority: Critical**

### Goals
- Add outbound call method to Cloudonix client
- Create CXML builder for AI routing
- Test Cloudonix API integration

### Tasks

#### 3.1 Cloudonix Client Enhancement
- [ ] Add `initiateCall()` method to CloudonixClient
- [ ] Support all API parameters (timeout, execute, timeLimit, recording, AMD)
- [ ] Handle API errors gracefully
- [ ] Add retry logic for transient failures
- [ ] Write unit tests with mocked responses

#### 3.2 CXML Builder
- [ ] Create `AutoDialerCxmlBuilder` class
- [ ] Implement `connectToAiAssistant()` method
- [ ] Implement `connectToAiLoadBalancer()` method
- [ ] Support AMD detection parameters
- [ ] Support recording parameters
- [ ] Write unit tests

#### 3.3 Webhook Endpoints
- [ ] Create `AutoDialerWebhookController`
- [ ] Implement `callStatus()` method
- [ ] Handle call initiated, ringing, answered, completed events
- [ ] Update destination and session records
- [ ] Add route in routes/webhook.php
- [ ] Apply webhook signature verification

#### 3.4 CDR Integration
- [ ] Modify existing CDR webhook to detect auto-dialer calls
- [ ] Update auto-dialer destinations when CDR received
- [ ] Link CDR to destination (optional foreign key)
- [ ] Update campaign statistics

### Acceptance Criteria
- Cloudonix client can initiate calls
- CXML responses are valid XML
- Webhooks handle all call states
- CDR updates are processed correctly

---

## Phase 4: Campaign Execution Engine
**Estimated Duration: 5-6 days**
**Priority: Critical**

### Goals
- Build campaign processor service
- Implement job queue for dialing
- Add rate limiting
- Handle call lifecycle

### Tasks

#### 4.1 Services
- [ ] Create `CampaignProcessor` service
- [ ] Create `DestinationValidator` service (whitelist check)
- [ ] Create `DialingScheduler` service
- [ ] Create `CampaignStatistics` service

#### 4.2 Job Classes
- [ ] Create `ProcessAutoDialerCampaignJob`
- [ ] Create `DialDestinationJob`
- [ ] Create `UpdateDestinationStatusJob`
- [ ] Configure queue in config/queue.php

#### 4.3 Rate Limiting
- [ ] Configure rate limiter for calls per second
- [ ] Implement per-campaign rate limiting
- [ ] Handle rate limit errors

#### 4.4 Campaign Lifecycle
- [ ] Implement campaign start logic
- [ ] Implement campaign pause/resume logic
- [ ] Implement campaign completion detection
- [ ] Handle campaign scheduling (days, hours)
- [ ] Auto-start campaigns when enabled

#### 4.5 Outbound Whitelist Validation
- [ ] Integrate with existing OutboundRoutingService
- [ ] Validate each number before dialing
- [ ] Mark invalid numbers in destinations table
- [ ] Log validation failures

#### 4.6 Error Handling & Retries
- [ ] Implement retry logic for failed calls
- [ ] Track dial attempts per destination
- [ ] Handle different error types (API, network, call failure)
- [ ] Update destination status on max retries

### Acceptance Criteria
- Campaign processor can dial numbers
- Rate limiting enforces CPS limits
- Whitelist validation works
- Retries are handled correctly
- Campaign state transitions work

---

## Phase 5: Frontend - Campaign Management UI
**Estimated Duration: 5-6 days**
**Priority: High**

### Goals
- Build campaign list page
- Create campaign form with tabs
- Implement list upload dialog
- Add real-time status updates

### Tasks

#### 5.1 Services & Hooks
- [ ] Create `autoDialerCampaignsService`
- [ ] Create `useAutoDialerCampaigns()` hook
- [ ] Create `useCreateCampaign()` mutation
- [ ] Create `useUpdateCampaign()` mutation
- [ ] Create `useDeleteCampaign()` mutation
- [ ] Create `useCampaignActions()` hook (start, pause, etc.)

#### 5.2 Campaign List Page
- [ ] Create `AutoDialerCampaigns.tsx` page
- [ ] Add data table with columns
- [ ] Add filters (status, date range)
- [ ] Add create campaign button
- [ ] Implement real-time polling or WebSocket
- [ ] Add progress bars

#### 5.3 Campaign Form
- [ ] Create `CampaignForm.tsx` component
- [ ] Tab 1: Basic Info (name, description, auto-start)
- [ ] Tab 2: Routing (destination type selector)
- [ ] Tab 3: Dialing Settings (timeout, CPS, attempts)
- [ ] Tab 4: Schedule (days, hours, dates)
- [ ] Tab 5: Recording options
- [ ] Tab 6: AMD configuration
- [ ] Add validation with Zod
- [ ] Add form submission handling

#### 5.4 List Upload Dialog
- [ ] Create `UploadListDialog.tsx`
- [ ] Add file input with CSV validation
- [ ] Add preview of parsed data
- [ ] Show validation errors
- [ ] Add confirm upload button
- [ ] Show progress during processing

#### 5.5 Campaign Detail Page
- [ ] Create `CampaignDetail.tsx` page
- [ ] Add statistics cards
- [ ] Add progress visualization
- [ ] Add destinations table
- [ ] Add activity log
- [ ] Add action buttons (start, pause, etc.)

#### 5.6 Routing & Navigation
- [ ] Add routes in router.tsx
- [ ] Add sidebar navigation item
- [ ] Set up role-based access

### Acceptance Criteria
- Campaign list displays correctly
- Form validates all fields
- List upload works with CSV
- Real-time updates show progress
- Navigation works properly

---

## Phase 6: Call Monitor Module
**Estimated Duration: 3-4 days**
**Priority: Medium**

### Goals
- Create separate Call Monitor page
- Display auto-dialer call recordings
- Add filtering and search
- Implement audio player

### Tasks

#### 6.1 Backend API
- [ ] Create `CallMonitorController`
- [ ] Add endpoint to list recordings
- [ ] Add filtering by campaign, date, disposition
- [ ] Add endpoint to get recording URL
- [ ] Add CSV export endpoint

#### 6.2 Frontend Page
- [ ] Create `CallMonitor.tsx` page
- [ ] Add filters (campaign, date range, disposition)
- [ ] Add data table with call details
- [ ] Add audio player component
- [ ] Add export to CSV button

#### 6.3 Audio Player
- [ ] Create `AudioPlayer.tsx` component
- [ ] Support play/pause/stop
- [ ] Show progress bar
- [ ] Handle loading states

#### 6.4 Integration
- [ ] Link from campaign detail page
- [ ] Add navigation in sidebar

### Acceptance Criteria
- Call recordings are listed
- Audio plays correctly
- Filters work properly
- Export generates CSV

---

## Phase 7: Testing & Quality Assurance
**Estimated Duration: 4-5 days**
**Priority: High**

### Goals
- Comprehensive test coverage
- Integration testing with Cloudonix
- Performance testing
- Security audit

### Tasks

#### 7.1 Unit Tests
- [ ] Test all models and relationships
- [ ] Test service classes
- [ ] Test validation rules
- [ ] Test policy methods

#### 7.2 Feature Tests
- [ ] Test campaign CRUD endpoints
- [ ] Test list upload
- [ ] Test campaign actions (start, pause, etc.)
- [ ] Test webhook handling

#### 7.3 Integration Tests
- [ ] Mock Cloudonix API calls
- [ ] Test CDR processing
- [ ] Test rate limiting
- [ ] Test retry logic

#### 7.4 Frontend Tests
- [ ] Test React components
- [ ] Test hooks
- [ ] Test form validation
- [ ] Test API integration

#### 7.5 Security Audit
- [ ] Review authorization policies
- [ ] Check for SQL injection vulnerabilities
- [ ] Verify CSRF protection
- [ ] Review file upload security

### Acceptance Criteria
- >80% test coverage
- All critical paths tested
- Security vulnerabilities fixed
- Performance benchmarks met

---

## Phase 8: Documentation & Deployment
**Estimated Duration: 2-3 days**
**Priority: Medium**

### Goals
- Complete API documentation
- User guide
- Deployment checklist
- Monitoring setup

### Tasks

#### 8.1 API Documentation
- [ ] Document all endpoints
- [ ] Add request/response examples
- [ ] Document error codes
- [ ] Update OpenAPI spec

#### 8.2 User Documentation
- [ ] Create user guide for campaign management
- [ ] Document CSV upload format
- [ ] Add troubleshooting guide
- [ ] Create video tutorials (optional)

#### 8.3 Deployment
- [ ] Create deployment checklist
- [ ] Set up queue workers
- [ ] Configure monitoring (Horizon)
- [ ] Set up alerts for failures

#### 8.4 Post-Deployment
- [ ] Monitor error logs
- [ ] Check queue performance
- [ ] Verify rate limiting
- [ ] Collect user feedback

### Acceptance Criteria
- Documentation is complete
- Deployment is successful
- Monitoring is active
- Users can access the feature

---

## Total Estimated Duration

**Phase 1:** 3-4 days  
**Phase 2:** 4-5 days  
**Phase 3:** 3-4 days  
**Phase 4:** 5-6 days  
**Phase 5:** 5-6 days  
**Phase 6:** 3-4 days  
**Phase 7:** 4-5 days  
**Phase 8:** 2-3 days  

**Total: 29-37 days** (approximately 6-8 weeks)

---

## Resource Requirements

### Development Team
- 1 Senior Backend Developer (PHP/Laravel)
- 1 Senior Frontend Developer (React/TypeScript)
- 1 DevOps Engineer (queue setup, monitoring)

### Infrastructure
- Queue workers (Laravel Horizon recommended)
- Redis for caching and queues
- Additional database storage for CDRs
- Monitoring tools (Sentry, CloudWatch, etc.)

### External Services
- Cloudonix API access
- Webhook endpoint (must be publicly accessible)

---

## Risk Assessment

### High Risk
- **Cloudonix API Rate Limits**: May need to adjust CPS or implement backoff
- **Database Performance**: Large destination lists may cause slow queries
- **Queue Worker Failures**: Must have monitoring and auto-restart

### Medium Risk
- **Webhook Delivery**: Network issues may cause missed call events
- **Timezone Handling**: Scheduling across timezones is complex
- **AMD Accuracy**: False positives/negatives may affect user experience

### Mitigation Strategies
- Implement circuit breaker pattern for API calls
- Add database indexes and query optimization
- Set up comprehensive monitoring and alerting
- Implement idempotent webhook processing
- Add retry logic for failed webhooks

---

## Dependencies

### External
- Cloudonix API availability
- Webhook endpoint accessibility
- DID numbers configured
- AI Assistants/Load Balancers configured

### Internal
- Outbound Whitelist module (must be configured)
- CDR processing (existing functionality)
- Queue infrastructure (Redis, workers)
- Authentication/Authorization system

---

## Success Metrics

1. **Performance**: 
   - Campaign creation < 2 seconds
   - List upload processing < 5 seconds per 1000 rows
   - API response time < 500ms for all endpoints

2. **Reliability**:
   - 99.9% uptime for campaign execution
   - <1% call initiation failures
   - Zero data loss

3. **User Experience**:
   - Campaign setup time < 10 minutes
   - List upload success rate > 95%
   - User satisfaction score > 4/5

---

## Notes

- All dates are estimates and may change based on development velocity
- Phases can overlap where dependencies allow
- Regular code reviews should happen throughout
- Weekly progress meetings recommended
- Feature flags can be used to enable gradual rollout

---

## Document Information

**Version:** 1.0  
**Last Updated:** 2026-03-16  
**Author:** AI Assistant  
**Status:** Draft
