# Auto Dialer Implementation TODO List

**Project:** Auto Dialer Feature  
**Status:** Specification Complete  
**Last Updated:** 2026-03-16  
**Next Review:** TBD

---

## Legend

- [ ] Pending
- [~] In Progress
- [x] Completed
- [!] Blocked/Issue

---

## Phase 1: Foundation & Database Schema

### 1.1 Database Migrations
- [ ] Create migration for `auto_dialer_campaigns` table
- [ ] Create migration for `auto_dialer_lists` table
- [ ] Create migration for `auto_dialer_destinations` table
- [ ] Create migration for `auto_dialer_call_sessions` table
- [ ] Create migration to add `is_auto_dialer` flag to CDR table
- [ ] Run migrations and verify schema
- [ ] Create migration rollback tests

### 1.2 Eloquent Models
- [ ] Create `AutoDialerCampaign` model
  - [ ] Define fillable fields
  - [ ] Add casts for JSON fields
  - [ ] Add relationships (belongsTo Organization, hasOne List, hasMany Destinations)
  - [ ] Add scopes: active(), pending(), runnable()
  - [ ] Add helper methods: isRunnable(), getProgressPercentage()
- [ ] Create `AutoDialerList` model
  - [ ] Define fillable fields
  - [ ] Add relationships (belongsTo Campaign, hasMany Destinations)
  - [ ] Add scopes: ready(), processing()
- [ ] Create `AutoDialerDestination` model
  - [ ] Define fillable fields
  - [ ] Add relationships (belongsTo List, belongsTo Campaign)
  - [ ] Add scopes: pending(), completed(), failed(), invalid()
- [ ] Create `AutoDialerCallSession` model
  - [ ] Define fillable fields
  - [ ] Add relationships (belongsTo Campaign, belongsTo Destination)
  - [ ] Add scope: active()

### 1.3 Enums
- [ ] Create `CampaignStatus` enum
  - [ ] draft, active, paused, completed, archived
- [ ] Create `DestinationStatus` enum
  - [ ] pending, dialing, connected, failed, completed, invalid
- [ ] Create `RoutingDestinationType` enum
  - [ ] ai_assistant, ai_load_balancer, hangup
- [ ] Create `AmdMode` enum
  - [ ] Enabled, DetectMessageEnd

### 1.4 Factory & Seeder
- [ ] Create `AutoDialerCampaignFactory`
- [ ] Create `AutoDialerListFactory`
- [ ] Create `AutoDialerDestinationFactory`
- [ ] Create `AutoDialerCallSessionFactory`
- [ ] Create `AutoDialerSeeder`
- [ ] Add to `DatabaseSeeder`

**Phase 1 Status:** Not Started  
**Assigned To:** TBD  
**Due Date:** TBD

---

## Phase 2: Core Backend API - Campaign CRUD

### 2.1 Form Requests
- [ ] Create `CreateCampaignRequest`
  - [ ] Validate name (required, string, max 255)
  - [ ] Validate routing_destination_type (required, enum)
  - [ ] Validate routing_destination_id (required_if not hangup)
  - [ ] Validate dial_timeout (integer, 1-300)
  - [ ] Validate caller_id (required, exists in DID numbers)
  - [ ] Validate max_dial_attempts (integer, 1-5)
  - [ ] Validate calls_per_second (integer, 1-5)
  - [ ] Validate days_active (required, array of valid days)
  - [ ] Validate start_time/end_time (integers, 0-23, end > start)
  - [ ] Validate start_date/end_date (dates, end >= start)
  - [ ] Validate AMD parameters when enabled
- [ ] Create `UpdateCampaignRequest`
  - [ ] Similar to Create but allow partial updates
  - [ ] Prevent updates when campaign is active
- [ ] Create `UploadListRequest`
  - [ ] Validate file (required, file, mimes:csv, max size)
  - [ ] Validate file content format

### 2.2 Controller
- [ ] Create `AutoDialerCampaignController`
  - [ ] Extend `AbstractApiCrudController`
  - [ ] Implement `getModelClass()` → AutoDialerCampaign::class
  - [ ] Implement `getResourceClass()` → AutoDialerCampaignResource::class
  - [ ] Implement `getAllowedFilters()` → ['status', 'name', 'start_date']
  - [ ] Implement `getAllowedSortFields()` → ['name', 'created_at', 'status']
  - [ ] Implement `getDefaultSortField()` → 'created_at'
  - [ ] Override `beforeStore()`
  - [ ] Override `afterStore()` with audit logging
  - [ ] Override `beforeUpdate()` to check status
  - [ ] Override `afterUpdate()` with audit logging
  - [ ] Add `start()` method
  - [ ] Add `pause()` method
  - [ ] Add `resume()` method
  - [ ] Add `archive()` method
  - [ ] Add `uploadList()` method

### 2.3 API Resource
- [ ] Create `AutoDialerCampaignResource`
  - [ ] Include all campaign fields
  - [ ] Add computed: progress_percentage
  - [ ] Add computed: total_destinations
  - [ ] Add computed: pending_count, completed_count, failed_count
  - [ ] Add relationship: list (when loaded)

### 2.4 Policy
- [ ] Create `AutoDialerCampaignPolicy`
  - [ ] `viewAny()` - Owner or PBX Admin
  - [ ] `view()` - Same organization
  - [ ] `create()` - Owner or PBX Admin
  - [ ] `update()` - Same organization + can manage
  - [ ] `delete()` - Owner only
  - [ ] `start()` - Owner or PBX Admin, campaign must be draft/paused
  - [ ] `pause()` - Owner or PBX Admin, campaign must be active
  - [ ] `archive()` - Owner only

### 2.5 Routes
- [ ] Add to `routes/api.php`
  - [ ] `Route::apiResource('auto-dialer-campaigns', ...)`
  - [ ] `Route::patch('auto-dialer-campaigns/{campaign}/start', ...)`
  - [ ] `Route::patch('auto-dialer-campaigns/{campaign}/pause', ...)`
  - [ ] `Route::patch('auto-dialer-campaigns/{campaign}/resume', ...)`
  - [ ] `Route::patch('auto-dialer-campaigns/{campaign}/archive', ...)`
  - [ ] `Route::post('auto-dialer-campaigns/{campaign}/list', ...)`
  - [ ] `Route::get('auto-dialer-campaigns/{campaign}/list', ...)`
  - [ ] `Route::delete('auto-dialer-campaigns/{campaign}/list', ...)`
  - [ ] `Route::get('auto-dialer-campaigns/{campaign}/destinations', ...)`
  - [ ] `Route::get('auto-dialer-campaigns/{campaign}/statistics', ...)`
  - [ ] `Route::get('auto-dialer-campaigns/{campaign}/progress', ...)`

### 2.6 List Upload Processing
- [ ] Create CSV parser service
  - [ ] Handle different delimiters
  - [ ] Validate header row
  - [ ] Parse phone_number and description columns
- [ ] Create duplicate removal logic
- [ ] Create phone number validator (E.164)
- [ ] Create destination batch insert
- [ ] Update campaign statistics after upload

**Phase 2 Status:** Not Started  
**Assigned To:** TBD  
**Due Date:** TBD

---

## Phase 3: Cloudonix Integration

### 3.1 Cloudonix Client Enhancement
- [ ] Add `initiateCall()` method to `CloudonixClient`
  - [ ] Implement POST /calls endpoint
  - [ ] Support from, to, trunk parameters
  - [ ] Support timeout, execute, timeLimit
  - [ ] Support recording parameters
  - [ ] Support AMD parameters
  - [ ] Handle API errors
  - [ ] Add retry logic
- [ ] Add unit tests with mocked responses
- [ ] Test with real Cloudonix API (staging)

### 3.2 CXML Builder
- [ ] Create `AutoDialerCxmlBuilder` class
  - [ ] `connectToAiAssistant()` method
    - [ ] Add AMD detection if enabled
    - [ ] Build connect verb with action URL
    - [ ] Add stream or dial verb based on AI config
  - [ ] `connectToAiLoadBalancer()` method
    - [ ] Similar to AI Assistant
    - [ ] Support load balancer failover
  - [ ] `generateHangupResponse()` method
- [ ] Create unit tests

### 3.3 Webhook Endpoints
- [ ] Create `AutoDialerWebhookController`
  - [ ] `callStatus()` method
    - [ ] Handle 'initiated' status
    - [ ] Handle 'ringing' status
    - [ ] Handle 'answered' status
    - [ ] Handle 'completed' status
  - [ ] Update destination records
  - [ ] Update call session records
  - [ ] Trigger next call if applicable
- [ ] Add routes in `routes/webhook.php`
  - [ ] `POST /webhooks/auto-dialer/call-status`
- [ ] Apply webhook signature verification middleware

### 3.4 CDR Integration
- [ ] Modify `CloudonixWebhookController@cdr()`
  - [ ] Detect auto-dialer calls (check session token or campaign ID)
  - [ ] Update `is_auto_dialer` flag in CDR
  - [ ] Update corresponding destination record
  - [ ] Update campaign statistics
  - [ ] Link CDR to destination (optional)

**Phase 3 Status:** Not Started  
**Assigned To:** TBD  
**Due Date:** TBD

---

## Phase 4: Campaign Execution Engine

### 4.1 Services
- [ ] Create `CampaignProcessor` service
  - [ ] `process()` main method
  - [ ] `canRun()` check method
  - [ ] `getNextDestination()` method
  - [ ] `dialDestination()` method
- [ ] Create `DestinationValidator` service
  - [ ] `validate()` method
  - [ ] Integrate with OutboundRoutingService
  - [ ] Mark invalid destinations
- [ ] Create `DialingScheduler` service
  - [ ] Check scheduling constraints
  - [ ] Check if within active hours
  - [ ] Check if within active days
- [ ] Create `CampaignStatistics` service
  - [ ] `updateCounts()` method
  - [ ] `getProgress()` method
  - [ ] Cache statistics

### 4.2 Job Classes
- [ ] Create `ProcessAutoDialerCampaignJob`
  - [ ] Check if campaign should run
  - [ ] Process batch of destinations
  - [ ] Schedule next batch
  - [ ] Handle completion
- [ ] Create `DialDestinationJob`
  - [ ] Validate destination
  - [ ] Call Cloudonix API
  - [ ] Handle response
  - [ ] Update records
- [ ] Create `UpdateDestinationStatusJob`
  - [ ] Update from webhook data
  - [ ] Trigger retries if needed
- [ ] Configure queue in `config/queue.php`
  - [ ] Create 'auto-dialer' queue
- [ ] Add to `app/Providers/HorizonServiceProvider.php`

### 4.3 Rate Limiting
- [ ] Configure rate limiter in `RouteServiceProvider`
  - [ ] `RateLimiter::for('auto-dialer', ...)`
  - [ ] Use campaign's calls_per_second
- [ ] Implement in job dispatch
- [ ] Handle rate limit errors

### 4.4 Campaign Lifecycle
- [ ] Implement campaign start logic
  - [ ] Validate campaign is ready
  - [ ] Dispatch ProcessAutoDialerCampaignJob
  - [ ] Update status to 'active'
  - [ ] Log audit event
- [ ] Implement campaign pause logic
  - [ ] Stop processing new calls
  - [ ] Allow active calls to complete
  - [ ] Update status to 'paused'
- [ ] Implement campaign completion
  - [ ] Detect when all destinations processed
  - [ ] Update status to 'completed'
  - [ ] Generate final report
- [ ] Implement auto-start
  - [ ] Check auto_start flag
  - [ ] Validate scheduling
  - [ ] Start campaign automatically

### 4.5 Outbound Whitelist Validation
- [ ] Integrate with `OutboundRoutingService`
  - [ ] Use existing `getBestMatch()` method
  - [ ] Validate before each call
  - [ ] Get trunk name from match
- [ ] Mark invalid numbers
  - [ ] Update status to 'invalid'
  - [ ] Set error message
  - [ ] Skip in future attempts

### 4.6 Error Handling & Retries
- [ ] Implement retry logic
  - [ ] Check dial_attempts < max_dial_attempts
  - [ ] Schedule retry with delay
  - [ ] Exponential backoff
- [ ] Handle different error types
  - [ ] API errors (retry)
  - [ ] Network errors (retry)
  - [ ] Busy signal (retry)
  - [ ] No answer (retry)
  - [ ] Invalid number (no retry)

**Phase 4 Status:** Not Started  
**Assigned To:** TBD  
**Due Date:** TBD

---

## Phase 5: Frontend - Campaign Management UI

### 5.1 Services & Hooks
- [ ] Create `autoDialerCampaignsService`
  - [ ] Extend createResourceService
  - [ ] Add custom methods
- [ ] Create `useAutoDialerCampaigns()` hook
  - [ ] Use TanStack Query
  - [ ] Add filters
  - [ ] Add sorting
- [ ] Create `useCreateCampaign()` mutation
- [ ] Create `useUpdateCampaign()` mutation
- [ ] Create `useDeleteCampaign()` mutation
- [ ] Create `useCampaignActions()` hook
  - [ ] start, pause, resume, archive
- [ ] Create `useUploadList()` mutation
- [ ] Create `useCampaignStatistics()` query

### 5.2 Campaign List Page
- [ ] Create `AutoDialerCampaigns.tsx` page
  - [ ] Page header with title and create button
  - [ ] Filter bar (status, date range)
  - [ ] Data table with columns:
    - [ ] Name
    - [ ] Status badge
    - [ ] Progress bar
    - [ ] Start/End dates
    - [ ] Actions dropdown
  - [ ] Empty state
  - [ ] Loading skeleton
  - [ ] Pagination
- [ ] Implement real-time updates
  - [ ] Polling every 30 seconds
  - [ ] Or WebSocket if available

### 5.3 Campaign Form
- [ ] Create `CampaignForm.tsx` component
  - [ ] Form wrapper with validation
  - [ ] Tab 1: Basic Info
    - [ ] Name input
    - [ ] Description textarea
    - [ ] Auto-start toggle
  - [ ] Tab 2: Routing
    - [ ] Destination type select
    - [ ] AI Assistant selector (if type=ai_assistant)
    - [ ] AI Load Balancer selector (if type=ai_load_balancer)
  - [ ] Tab 3: Dialing Settings
    - [ ] Timeout input (1-300)
    - [ ] Connect mode select
    - [ ] Caller ID select (from DID numbers)
    - [ ] Max dial attempts (1-5)
    - [ ] Calls per second (1-5)
  - [ ] Tab 4: Schedule
    - [ ] Days active checkboxes
    - [ ] Start time select (0-23)
    - [ ] End time select (0-23)
    - [ ] Start date picker
    - [ ] End date picker
    - [ ] Timezone select
  - [ ] Tab 5: Recording
    - [ ] Enable toggle
    - [ ] Retention info
  - [ ] Tab 6: AMD
    - [ ] Enable toggle
    - [ ] Mode select
    - [ ] Timeout input
    - [ ] Speech threshold inputs
- [ ] Add Zod validation schema
- [ ] Handle form submission

### 5.4 List Upload Dialog
- [ ] Create `UploadListDialog.tsx`
  - [ ] File input with drag-drop
  - [ ] CSV validation
  - [ ] Preview table (first 10 rows)
  - [ ] Validation summary
    - [ ] Total rows
    - [ ] Valid rows
    - [ ] Invalid rows
    - [ ] Duplicates removed
  - [ ] Confirm upload button
  - [ ] Progress indicator
  - [ ] Success/error messages

### 5.5 Campaign Detail Page
- [ ] Create `CampaignDetail.tsx` page
  - [ ] Header with actions
  - [ ] Statistics cards
    - [ ] Total destinations
    - [ ] Completed calls
    - [ ] Failed calls
    - [ ] Pending calls
  - [ ] Progress visualization
    - [ ] Progress bar
    - [ ] Percentage complete
  - [ ] Destinations table
    - [ ] Phone number
    - [ ] Status badge
    - [ ] Dial attempts
    - [ ] Last call info
    - [ ] Actions (retry)
  - [ ] Activity log
    - [ ] Recent calls
    - [ ] Dispositions
  - [ ] Action buttons
    - [ ] Start/Pause/Resume
    - [ ] Archive

### 5.6 Routing & Navigation
- [ ] Add routes in `router.tsx`
  - [ ] `/ui/auto-dialer` - Campaign list
  - [ ] `/ui/auto-dialer/:id` - Campaign detail
  - [ ] `/ui/auto-dialer/new` - Create campaign
- [ ] Add sidebar navigation item
  - [ ] Icon: PhoneCall
  - [ ] Label: "Auto Dialer"
  - [ ] Roles: owner, pbx_admin

**Phase 5 Status:** Not Started  
**Assigned To:** TBD  
**Due Date:** TBD

---

## Phase 6: Call Monitor Module

### 6.1 Backend API
- [ ] Create `CallMonitorController`
  - [ ] `index()` - List recordings
  - [ ] Add filters (campaign, date, disposition)
  - [ ] Add pagination
  - [ ] `show()` - Get recording details
  - [ ] `getAudioUrl()` - Generate signed URL
  - [ ] `export()` - CSV export
- [ ] Create routes
  - [ ] `GET /api/v1/call-monitor`
  - [ ] `GET /api/v1/call-monitor/{id}`
  - [ ] `GET /api/v1/call-monitor/{id}/audio`
  - [ ] `GET /api/v1/call-monitor/export`

### 6.2 Frontend Page
- [ ] Create `CallMonitor.tsx` page
  - [ ] Filters sidebar
    - [ ] Campaign select
    - [ ] Date range picker
    - [ ] Disposition select
  - [ ] Data table
    - [ ] Timestamp
    - [ ] Phone number
    - [ ] Campaign name
    - [ ] Duration
    - [ ] Disposition
    - [ ] Actions (play, download)
  - [ ] Export button
  - [ ] Pagination

### 6.3 Audio Player
- [ ] Create `AudioPlayer.tsx` component
  - [ ] Play/pause button
  - [ ] Progress bar
  - [ ] Time display (current/total)
  - [ ] Volume control
  - [ ] Download button
  - [ ] Loading state
  - [ ] Error handling

### 6.4 Integration
- [ ] Add navigation in sidebar
  - [ ] Icon: Headphones
  - [ ] Label: "Call Monitor"
- [ ] Link from campaign detail

**Phase 6 Status:** Not Started  
**Assigned To:** TBD  
**Due Date:** TBD

---

## Phase 7: Testing & Quality Assurance

### 7.1 Unit Tests
- [ ] Test `AutoDialerCampaign` model
- [ ] Test `AutoDialerDestination` model
- [ ] Test `CampaignProcessor` service
- [ ] Test `DestinationValidator` service
- [ ] Test `AutoDialerCxmlBuilder`
- [ ] Test form requests
- [ ] Test policy methods

### 7.2 Feature Tests
- [ ] Test campaign CRUD
- [ ] Test list upload
- [ ] Test campaign actions
- [ ] Test webhook handling
- [ ] Test CDR processing

### 7.3 Integration Tests
- [ ] Mock Cloudonix API
- [ ] Test end-to-end campaign flow
- [ ] Test rate limiting
- [ ] Test retry logic
- [ ] Test scheduling

### 7.4 Frontend Tests
- [ ] Test `CampaignForm` component
- [ ] Test `UploadListDialog` component
- [ ] Test hooks
- [ ] Test API integration

### 7.5 Security Audit
- [ ] Review authorization policies
- [ ] Check SQL injection prevention
- [ ] Verify CSRF protection
- [ ] Review file upload security
- [ ] Check for data leaks

**Phase 7 Status:** Not Started  
**Assigned To:** TBD  
**Due Date:** TBD

---

## Phase 8: Documentation & Deployment

### 8.1 API Documentation
- [ ] Document all endpoints
- [ ] Add request/response examples
- [ ] Document error codes
- [ ] Update OpenAPI spec
- [ ] Generate Swagger UI

### 8.2 User Documentation
- [ ] Create user guide
  - [ ] Getting started
  - [ ] Creating campaigns
  - [ ] Uploading lists
  - [ ] Monitoring progress
  - [ ] Troubleshooting
- [ ] Document CSV format
- [ ] Add screenshots
- [ ] Create FAQ

### 8.3 Deployment
- [ ] Create deployment checklist
- [ ] Set up queue workers
- [ ] Configure Horizon
- [ ] Set up monitoring
- [ ] Configure alerts
- [ ] Run migrations
- [ ] Test in staging

### 8.4 Post-Deployment
- [ ] Monitor error logs
- [ ] Check queue performance
- [ ] Verify rate limiting
- [ ] Collect user feedback
- [ ] Fix critical issues

**Phase 8 Status:** Not Started  
**Assigned To:** TBD  
**Due Date:** TBD

---

## Bugs & Issues

| ID | Description | Severity | Status | Notes |
|----|-------------|----------|--------|-------|
| -  | -           | -        | -      | -     |

---

## Notes & Decisions

### Decision Log

| Date | Decision | Rationale | Impact |
|------|----------|-----------|--------|
| 2026-03-16 | Initial specification created | Feature requirements defined | Foundation for development |

### Technical Notes

- Use existing IVR routing strategies for AI connections
- Single campaign execution per organization
- Immutable lists uploaded via CSV
- Dedicated audit log action types

---

## Progress Summary

**Total Tasks:** 200+  
**Completed:** 0  
**In Progress:** 0  
**Pending:** 200+  
**Blocked:** 0  

**Overall Progress:** 0%

---

## Next Steps

1. Review specification with stakeholders
2. Assign development tasks
3. Set up development environment
4. Begin Phase 1 implementation
5. Schedule weekly progress reviews

---

**Document Information**  
**Version:** 1.0  
**Last Updated:** 2026-03-16  
**Maintainer:** AI Assistant  
**Review Schedule:** Weekly
