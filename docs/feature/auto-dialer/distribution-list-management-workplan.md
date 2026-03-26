# Distribution List Management - Workplan

## Overview

This document outlines the implementation plan for the Distribution List Management feature within the Auto Dialer module. Distribution lists contain phone numbers to be dialed during campaigns and have a complete lifecycle including creation, versioning, validation, and archival.

---

## Architecture Principles

1. **Tight Campaign Coupling**: Lists are associated with campaigns but can be copied to create new independent lists
2. **Immutable Lists**: Once created, list contents cannot be modified; new versions must be uploaded
3. **Version Control**: Lists support numbered versions (v1, v2, etc.) with full history
4. **Usage Tracking**: Lists track per-destination metrics across campaigns
5. **Size Limits**: Maximum 100,000 entries per list; larger files split automatically
6. **Soft Archival**: Lists cannot be deleted, only archived when not in active use

---

## Data Model

### Database Schema

#### auto_dialer_lists table (Enhanced)

```sql
CREATE TABLE auto_dialer_lists (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED NULL, -- NULL until assigned, then locked
    
    -- Identity
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    
    -- Versioning
    version_number INT DEFAULT 1, -- v1, v2, etc.
    parent_list_id BIGINT UNSIGNED NULL, -- Reference to previous version
    is_latest_version BOOLEAN DEFAULT TRUE,
    
    -- Status Lifecycle
    status ENUM('draft', 'processing', 'ready', 'failed', 'archived', 'in_use', 'used') DEFAULT 'draft',
    
    -- Usage Tracking
    used_by_campaign_id BIGINT UNSIGNED NULL, -- Campaign that used this list
    used_at TIMESTAMP NULL, -- When the campaign started using it
    
    -- Upload tracking
    original_filename VARCHAR(255) NULL,
    processed_at TIMESTAMP NULL,
    total_rows INT DEFAULT 0,
    valid_rows INT DEFAULT 0,
    invalid_rows INT DEFAULT 0,
    
    -- Validation
    validation_errors JSON NULL, -- Store validation error details
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at TIMESTAMP NULL,
    
    -- Indexes
    INDEX idx_organization_status (organization_id, status),
    INDEX idx_campaign (campaign_id),
    INDEX idx_version (parent_list_id, version_number),
    INDEX idx_latest (organization_id, is_latest_version),
    
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES auto_dialer_campaigns(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_list_id) REFERENCES auto_dialer_lists(id) ON DELETE SET NULL,
    FOREIGN KEY (used_by_campaign_id) REFERENCES auto_dialer_campaigns(id) ON DELETE SET NULL
);
```

#### auto_dialer_destinations table (Enhanced with Metrics)

```sql
CREATE TABLE auto_dialer_destinations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    list_id BIGINT UNSIGNED NOT NULL,
    
    -- Destination Info
    phone_number VARCHAR(50) NOT NULL, -- E.164 format
    description VARCHAR(255) NULL,
    
    -- Status Tracking
    status ENUM('pending', 'dialing', 'connected', 'failed', 'completed', 'invalid') DEFAULT 'pending',
    dial_attempts INT DEFAULT 0,
    
    -- Call Metrics (permanent record)
    last_session_token VARCHAR(255) NULL,
    last_call_id VARCHAR(255) NULL,
    last_dialed_at TIMESTAMP NULL,
    last_disposition VARCHAR(50) NULL,
    duration INT DEFAULT 0, -- seconds (last call)
    billsec INT DEFAULT 0, -- seconds (last call)
    total_duration INT DEFAULT 0, -- cumulative across all attempts
    
    -- Error tracking
    last_error VARCHAR(500) NULL,
    
    -- Foreign key to CDR (optional, for deep linking)
    last_cdr_id BIGINT UNSIGNED NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_list_status (list_id, status),
    INDEX idx_phone_number (organization_id, phone_number),
    INDEX idx_session_token (last_session_token),
    
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (list_id) REFERENCES auto_dialer_lists(id) ON DELETE CASCADE
);
```

---

## Status Lifecycle

```
DRAFT → PROCESSING → READY → IN_USE → USED → ARCHIVED
              ↓         ↓        ↓
           FAILED   (locked)  (locked)
```

| Status | Description | Transitions |
|--------|-------------|-------------|
| **DRAFT** | List created, no destinations yet | Can upload destinations → PROCESSING |
| **PROCESSING** | Destinations being added/validated | Success → READY, Failure → FAILED |
| **READY** | Validated and available for campaigns | Assign to campaign → IN_USE, Manual → ARCHIVED |
| **FAILED** | Validation failed, has errors | Fix and retry → PROCESSING, Manual → ARCHIVED |
| **IN_USE** | Currently assigned to active campaign | Campaign completes → USED |
| **USED** | Campaign completed, list exhausted | Manual → ARCHIVED |
| **ARCHIVED** | Soft-deleted, read-only | None (terminal state) |

---

## API Endpoints

### List Management

```
GET    /api/v1/auto-dialer-campaigns/lists
       - List all distribution lists for organization
       - Query params: status, search, per_page, page
       
GET    /api/v1/auto-dialer-campaigns/lists/{list}
       - Get single list with details and version history
       
POST   /api/v1/auto-dialer-campaigns/lists
       - Create new empty list (DRAFT status)
       - Body: name, description
       
POST   /api/v1/auto-dialer-campaigns/lists/{list}/upload
       - Upload CSV file to populate list
       - Async processing for large files
       - Returns job ID for tracking
       
POST   /api/v1/auto-dialer-campaigns/lists/{list}/destinations
       - Add single destination (for API integrations)
       - Body: phone_number, description
       
POST   /api/v1/auto-dialer-campaigns/lists/{list}/destinations/batch
       - Add multiple destinations (batch API)
       - Body: [{phone_number, description}, ...]
       - Max 1000 per request
       
PATCH  /api/v1/auto-dialer-campaigns/lists/{list}/archive
       - Archive the list (only if status allows)
       
POST   /api/v1/auto-dialer-campaigns/lists/{list}/copy
       - Copy list to create new independent list
       - Body: new_name (required)
       - Resets all destination statuses to pending
       
GET    /api/v1/auto-dialer-campaigns/lists/{list}/versions
       - Get all versions of this list lineage
       
GET    /api/v1/auto-dialer-campaigns/lists/{list}/download
       - Download list as CSV (any version)
       
GET    /api/v1/auto-dialer-campaigns/lists/{list}/destinations
       - Get paginated destinations with metrics
       - Query params: status, search, per_page, page
       
GET    /api/v1/auto-dialer-campaigns/lists/{list}/validation-errors
       - Get validation errors (for FAILED status)
       
GET    /api/v1/auto-dialer-campaigns/lists/example-csv
       - Download example CSV template
```

### Campaign List Assignment

```
POST   /api/v1/auto-dialer-campaigns/{campaign}/assign-list
       - Assign a READY list to a campaign
       - Body: list_id
       - Changes list status to IN_USE
       
GET    /api/v1/auto-dialer-campaigns/{campaign}/list
       - Get list assigned to campaign
       - Returns list with destination statistics
```

---

## Backend Implementation Tasks

### 1. Database Migrations

**Task 1.1: Modify auto_dialer_lists table**
- Add `version_number` (INT, default 1)
- Add `parent_list_id` (nullable FK to auto_dialer_lists)
- Add `is_latest_version` (BOOLEAN, default TRUE)
- Add `used_by_campaign_id` (nullable FK to auto_dialer_campaigns)
- Add `used_at` (nullable TIMESTAMP)
- Add `validation_errors` (JSON, nullable)
- Add `archived_at` (nullable TIMESTAMP)
- Expand status enum to include: 'in_use', 'used'
- Add indexes for version tracking

**Task 1.2: Modify auto_dialer_destinations table**
- Add `total_duration` (INT, default 0)
- Add index for list_id + status queries

**Task 1.3: Create migration for list_metrics (optional denormalization)**
- Cache frequently accessed metrics at list level

### 2. Eloquent Model Updates

**Task 2.1: Update AutoDialerList Model**
```php
// New fillable fields
protected $fillable = [
    'organization_id', 'campaign_id', 'name', 'description',
    'version_number', 'parent_list_id', 'is_latest_version',
    'status', 'used_by_campaign_id', 'used_at',
    'original_filename', 'processed_at',
    'total_rows', 'valid_rows', 'invalid_rows',
    'validation_errors', 'archived_at'
];

// New casts
protected $casts = [
    'status' => ListStatus::class, // New enum
    'is_latest_version' => 'boolean',
    'validation_errors' => 'array',
    'used_at' => 'datetime',
    'archived_at' => 'datetime',
];

// New relationships
public function parentList(): BelongsTo
public function versions(): HasMany
public function latestVersion(): HasOne
public function usedByCampaign(): BelongsTo

// New scopes
public function scopeReady($query)
public function scopeArchived($query)
public function scopeLatestVersion($query)
public function scopeAssignable($query) // READY status only

// New helper methods
public function canBeArchived(): bool
public function getVersionHistory(): Collection
public function markAsInUse(int $campaignId): void
public function markAsUsed(): void
public function archive(): void
```

**Task 2.2: Create ListStatus Enum**
```php
enum ListStatus: string
{
    case DRAFT = 'draft';
    case PROCESSING = 'processing';
    case READY = 'ready';
    case FAILED = 'failed';
    case IN_USE = 'in_use';
    case USED = 'used';
    case ARCHIVED = 'archived';
    
    public function canArchive(): bool
    public function canAssign(): bool
    public function canUpload(): bool
    public function label(): string
    public function color(): string
}
```

### 3. Validation Service

**Task 3.1: Create ListValidationService**
```php
class ListValidationService
{
    /**
     * Validate phone number using libphonenumber
     */
    public function validatePhoneNumber(
        string $phoneNumber, 
        ?string $defaultRegion = 'US'
    ): ValidationResult
    
    /**
     * Validate entire CSV file
     */
    public function validateCsvFile(
        string $filePath,
        int $organizationId
    ): CsvValidationResult
    
    /**
     * Check for duplicates within list
     */
    public function findDuplicates(array $phoneNumbers): array
    
    /**
     * Batch validate multiple numbers
     */
    public function batchValidate(
        array $entries,
        int $organizationId
    ): BatchValidationResult
}
```

**Task 3.2: Create ValidationResult Value Object**
```php
class ValidationResult
{
    public bool $valid;
    public ?string $error;
    public ?string $normalizedNumber; // E.164 format
    public ?string $carrier;
    public ?string $numberType; // MOBILE, FIXED_LINE, etc.
}
```

### 4. List Processing Jobs

**Task 4.1: Create ProcessListUploadJob**
```php
class ProcessListUploadJob implements ShouldQueue
{
    public int $tries = 3;
    public int $timeout = 3600; // 1 hour for large files
    public array $backoff = [60, 300, 900]; // 1min, 5min, 15min
    
    public function __construct(
        public int $listId,
        public string $filePath,
        public bool $isNewVersion = false
    ) {}
    
    public function handle(
        ListValidationService $validator,
        DestinationProcessor $processor
    ): void
}
```

**Task 4.2: Create ProcessLargeListJob (for 100k+ entries)**
```php
class ProcessLargeListJob implements ShouldQueue
{
    // Splits large files into chunks
    // Processes sequentially
    // Updates progress in cache for real-time UI
}
```

### 5. List Management Service

**Task 5.1: Create ListManagementService**
```php
class ListManagementService
{
    public function createList(
        int $organizationId,
        string $name,
        ?string $description
    ): AutoDialerList;
    
    public function uploadCsv(
        int $listId,
        UploadedFile $file
    ): string; // Returns job ID
    
    public function addDestination(
        int $listId,
        string $phoneNumber,
        ?string $description
    ): AutoDialerDestination;
    
    public function addDestinationsBatch(
        int $listId,
        array $destinations
    ): BatchAddResult;
    
    public function copyList(
        int $sourceListId,
        string $newName
    ): AutoDialerList;
    
    public function createNewVersion(
        int $listId,
        UploadedFile $file
    ): AutoDialerList; // Creates v2, archives v1
    
    public function archiveList(int $listId): void;
    
    public function canArchive(int $listId): bool;
    
    public function getVersionHistory(int $listId): Collection;
    
    public function generateCsvExport(int $listId): string; // File path
}
```

### 6. Controller Updates

**Task 6.1: Create DistributionListController**
```php
class DistributionListController extends Controller
{
    // List CRUD
    public function index(Request $request): JsonResponse;
    public function store(CreateListRequest $request): JsonResponse;
    public function show(AutoDialerList $list): JsonResponse;
    
    // Upload and processing
    public function upload(UploadListRequest $request, AutoDialerList $list): JsonResponse;
    public function getUploadProgress(string $jobId): JsonResponse;
    
    // Destination management
    public function addDestination(AddDestinationRequest $request, AutoDialerList $list): JsonResponse;
    public function addDestinationsBatch(BatchDestinationRequest $request, AutoDialerList $list): JsonResponse;
    public function getDestinations(Request $request, AutoDialerList $list): JsonResponse;
    
    // Versioning
    public function getVersions(AutoDialerList $list): JsonResponse;
    public function createNewVersion(UploadListRequest $request, AutoDialerList $list): JsonResponse;
    
    // Copy and archive
    public function copy(CopyListRequest $request, AutoDialerList $list): JsonResponse;
    public function archive(AutoDialerList $list): JsonResponse;
    
    // Download
    public function download(AutoDialerList $list): BinaryFileResponse;
    public function downloadExample(): BinaryFileResponse;
    
    // Validation errors
    public function getValidationErrors(AutoDialerList $list): JsonResponse;
}
```

**Task 6.2: Update AutoDialerCampaignController**
```php
// Add methods
public function assignList(AssignListRequest $request, AutoDialerCampaign $campaign): JsonResponse;
public function getCampaignList(AutoDialerCampaign $campaign): JsonResponse;
public function removeList(AutoDialerCampaign $campaign): JsonResponse; // Only if campaign is draft
```

### 7. Form Requests

**Task 7.1: Create List Management Requests**
```php
// CreateListRequest
// - name: required, string, max:255
// - description: nullable, string, max:1000

// UploadListRequest
// - file: required, file, mimes:csv,txt, max:51200 (50MB)

// AddDestinationRequest
// - phone_number: required, string, libphonenumber validation
// - description: nullable, string, max:255

// BatchDestinationRequest
// - destinations: required, array, max:1000
// - destinations.*.phone_number: required, libphonenumber
// - destinations.*.description: nullable, string, max:255

// CopyListRequest
// - new_name: required, string, max:255, unique per org

// AssignListRequest
// - list_id: required, exists:auto_dialer_lists,id, status:ready
```

### 8. API Resources

**Task 8.1: Create DistributionListResource**
```php
class DistributionListResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'version_number' => $this->version_number,
            'is_latest_version' => $this->is_latest_version,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            
            // Usage info
            'campaign_id' => $this->campaign_id,
            'used_by_campaign_id' => $this->used_by_campaign_id,
            'used_at' => $this->used_at?->format('Y-m-d H:i:s'),
            
            // Statistics
            'statistics' => [
                'total_rows' => $this->total_rows,
                'valid_rows' => $this->valid_rows,
                'invalid_rows' => $this->invalid_rows,
            ],
            
            // Versioning
            'parent_list_id' => $this->parent_list_id,
            'has_versions' => $this->versions()->exists(),
            
            // Flags
            'can_archive' => $this->canBeArchived(),
            'can_assign' => $this->status === ListStatus::READY,
            'can_upload' => in_array($this->status, [ListStatus::DRAFT, ListStatus::FAILED]),
            
            // Timestamps
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'processed_at' => $this->processed_at?->format('Y-m-d H:i:s'),
            'archived_at' => $this->archived_at?->format('Y-m-d H:i:s'),
        ];
    }
}
```

**Task 8.2: Create ListDestinationResource**
```php
class ListDestinationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'phone_number' => $this->phone_number,
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            
            // Metrics
            'dial_attempts' => $this->dial_attempts,
            'last_dialed_at' => $this->last_dialed_at?->format('Y-m-d H:i:s'),
            'last_disposition' => $this->last_disposition,
            'duration' => $this->duration,
            'billsec' => $this->billsec,
            'total_duration' => $this->total_duration,
            
            // Error info
            'last_error' => $this->last_error,
            'is_invalid' => $this->status === DestinationStatus::INVALID,
        ];
    }
}
```

### 9. Policy Updates

**Task 9.1: Create DistributionListPolicy**
```php
class DistributionListPolicy
{
    public function viewAny(User $user): bool;
    public function view(User $user, AutoDialerList $list): bool;
    public function create(User $user): bool;
    public function update(User $user, AutoDialerList $list): bool; // Only name/description, not destinations
    public function upload(User $user, AutoDialerList $list): bool;
    public function archive(User $user, AutoDialerList $list): bool;
    public function copy(User $user, AutoDialerList $list): bool;
    public function download(User $user, AutoDialerList $list): bool;
}
```

### 10. Event & Audit Logging

**Task 10.1: Create List Events**
```php
// ListCreated
// ListUploaded
// ListProcessed
// ListValidationFailed
// ListArchived
// ListCopied
// ListAssignedToCampaign
// ListMarkedAsUsed
// DestinationAdded
// BatchDestinationsAdded
```

**Task 10.2: Add Audit Action Types**
```php
const AUDIT_LIST_CREATED = 'auto_dialer.list.created';
const AUDIT_LIST_UPLOADED = 'auto_dialer.list.uploaded';
const AUDIT_LIST_PROCESSED = 'auto_dialer.list.processed';
const AUDIT_LIST_VALIDATION_FAILED = 'auto_dialer.list.validation_failed';
const AUDIT_LIST_ARCHIVED = 'auto_dialer.list.archived';
const AUDIT_LIST_COPIED = 'auto_dialer.list.copied';
const AUDIT_LIST_VERSION_CREATED = 'auto_dialer.list.version_created';
const AUDIT_LIST_ASSIGNED = 'auto_dialer.list.assigned';
const AUDIT_LIST_MARKED_USED = 'auto_dialer.list.marked_used';
const AUDIT_DESTINATION_ADDED = 'auto_dialer.destination.added';
const AUDIT_DESTINATION_BATCH_ADDED = 'auto_dialer.destination.batch_added';
```

---

## Frontend Implementation Tasks

### 11. API Service Layer

**Task 11.1: Create distributionListsApi.ts**
```typescript
export const distributionListsApi = {
  // List management
  getAll: (params?: ListParams) => Promise<PaginatedResponse<DistributionList>>;
  getById: (id: string) => Promise<DistributionList>;
  create: (data: CreateListRequest) => Promise<DistributionList>;
  
  // Upload
  uploadCsv: (listId: string, file: File) => Promise<{ jobId: string }>;
  getUploadProgress: (jobId: string) => Promise<UploadProgress>;
  
  // Destinations
  getDestinations: (listId: string, params?: DestinationParams) => Promise<PaginatedResponse<ListDestination>>;
  addDestination: (listId: string, data: AddDestinationRequest) => Promise<ListDestination>;
  addDestinationsBatch: (listId: string, data: BatchDestinationRequest) => Promise<BatchAddResult>;
  
  // Versioning
  getVersions: (listId: string) => Promise<DistributionList[]>;
  createNewVersion: (listId: string, file: File) => Promise<DistributionList>;
  
  // Copy and archive
  copy: (listId: string, newName: string) => Promise<DistributionList>;
  archive: (listId: string) => Promise<void>;
  
  // Download
  download: (listId: string) => Promise<Blob>;
  downloadExample: () => Promise<Blob>;
  
  // Validation
  getValidationErrors: (listId: string) => Promise<ValidationError[]>;
  
  // Campaign assignment
  assignToCampaign: (campaignId: string, listId: string) => Promise<void>;
};
```

**Task 11.2: Create React Query Hooks**
```typescript
// useDistributionLists.ts
export function useDistributionLists(params?: ListParams);
export function useDistributionList(id: string);
export function useCreateList();
export function useUploadList();
export function useListProgress(jobId: string);
export function useListDestinations(listId: string, params?: DestinationParams);
export function useAddDestination();
export function useAddDestinationsBatch();
export function useCopyList();
export function useArchiveList();
export function useCreateListVersion();
export function useAssignListToCampaign();
```

### 12. UI Components

**Task 12.1: Create DistributionLists Page**
```typescript
// DistributionLists.tsx
// - Page header with title and "Create List" button
// - Filter bar: status dropdown, search input
// - Data table with columns:
//   - Name (with version badge if v2+)
//   - Status (badge)
//   - Statistics (valid/invalid/total)
//   - Campaign (if assigned)
//   - Created date
//   - Actions menu
// - Empty state
// - Pagination
```

**Task 12.2: Create List Detail Page**
```typescript
// DistributionListDetail.tsx
// - Header: Name, status badge, version info
// - Action buttons: Edit name, Upload, Archive, Copy, Download
// - Version history section (accordion)
// - Destinations table with:
//   - Phone number
//   - Description
//   - Status badge
//   - Dial attempts
//   - Last call info
//   - Duration metrics
// - Filters: status, search
// - Pagination
```

**Task 12.3: Create CreateList Dialog**
```typescript
// CreateListDialog.tsx
// - Name input (required)
// - Description textarea (optional)
// - Submit creates list in DRAFT status
// - After creation, prompts to upload destinations
```

**Task 12.4: Create UploadDestinations Dialog**
```typescript
// UploadDestinationsDialog.tsx
// - File drop zone with CSV validation
// - Show example CSV download link
// - Preview first 10 rows before confirm
// - Validation summary: total, valid, invalid, duplicates
// - Progress indicator for large files
// - Error display for failed validations
```

**Task 12.5: Create CopyList Dialog**
```typescript
// CopyListDialog.tsx
// - Source list info display
// - New name input (required, validation for unique name)
// - Warning: "This will create a new independent list with reset statuses"
// - Submit creates copy
```

**Task 12.6: Create NewVersion Dialog**
```typescript
// NewVersionDialog.tsx
// - Current version info
// - File upload (same as UploadDestinationsDialog)
// - Warning: "Current version will be archived"
// - Creates v2, v3, etc.
```

**Task 12.7: Create AssignListToCampaign Dialog**
```typescript
// AssignListToCampaignDialog.tsx
// - Show only READY lists
// - Campaign selector (draft campaigns only)
// - Validation: ensure campaign doesn't already have list
// - Confirmation before assignment
```

**Task 12.8: Create ValidationErrors Dialog**
```typescript
// ValidationErrorsDialog.tsx
// - Display list of validation errors from JSON
// - Show row number, phone number, error message
// - Download errors as CSV option
```

### 13. Routes & Navigation

**Task 13.1: Update router.tsx**
```typescript
// Add routes
{
  path: '/ui/auto-dialer/lists',
  element: <DistributionLists />,
},
{
  path: '/ui/auto-dialer/lists/:id',
  element: <DistributionListDetail />,
},
{
  path: '/ui/auto-dialer/lists/:id/upload',
  element: <UploadDestinations />,
},
```

**Task 13.2: Update sidebar navigation**
```typescript
// Already exists per user instruction
// Ensure it's properly linked to the lists page
```

### 14. CSV Template

**Task 14.1: Create Example CSV File**
```csv
phone_number,description
+14155551212,John Doe - Sales Lead
+14155551213,Jane Smith - Support Case
+14155551214,Bob Johnson - Follow-up Call
+14155551215,Alice Brown - Cold Outreach
```

**Task 14.2: Create CSV Format Documentation**
- Format: CSV with header row
- Required columns: phone_number
- Optional columns: description
- Phone number format: E.164 (+ followed by 10-15 digits)
- Max file size: 50MB
- Max entries per list: 100,000
- Encoding: UTF-8

---

## Testing Tasks

### 15. Unit Tests

**Task 15.1: Test ListValidationService**
- Valid E.164 numbers
- Invalid formats
- Regional variations
- Batch validation

**Task 15.2: Test ListManagementService**
- Create list
- Upload CSV
- Copy list
- Version creation
- Archive logic

**Task 15.3: Test ListStatus Enum**
- State transitions
- Permission checks

### 16. Feature Tests

**Task 16.1: Test API Endpoints**
- CRUD operations
- Upload processing
- Versioning
- Copy/Archive

**Task 16.2: Test Large File Processing**
- Files > 100k entries
- Sequential processing
- Progress tracking
- Splitting logic

**Task 16.3: Test Validation**
- Valid CSV
- Invalid phone numbers
- Duplicate handling
- Error reporting

### 17. Frontend Tests

**Task 17.1: Test Components**
- Upload dialog
- Validation error display
- Progress indicators
- Copy/version dialogs

---

## Implementation Phases

### Phase 1: Database & Models (2-3 days)
- Tasks 1.1, 1.2, 1.3 (migrations)
- Tasks 2.1, 2.2 (models & enum)
- Task 9.1 (policy)

### Phase 2: Backend Core (4-5 days)
- Task 3.1 (validation service)
- Tasks 4.1, 4.2 (jobs)
- Task 5.1 (management service)
- Tasks 6.1, 6.2 (controllers)
- Tasks 7.1, 8.1, 8.2 (requests & resources)

### Phase 3: API Integration & Testing (3-4 days)
- Task 10.1, 10.2 (events & audit)
- Tasks 15.1, 15.2, 15.3, 16.1, 16.2, 16.3 (testing)

### Phase 4: Frontend - Lists Page (3-4 days)
- Tasks 11.1, 11.2 (API & hooks)
- Tasks 12.1, 12.2 (pages)
- Task 13.1 (routing)

### Phase 5: Frontend - Upload & Versioning (3-4 days)
- Tasks 12.3, 12.4, 12.5, 12.6 (dialogs)
- Task 14.1, 14.2 (CSV template)
- Tasks 12.7, 12.8 (utility dialogs)

### Phase 6: Integration & Final Testing (2-3 days)
- Task 17.1 (frontend tests)
- Integration testing
- Bug fixes

**Total Estimated Duration: 17-23 days** (approximately 3-4 weeks)

---

## Dependencies

### External Libraries
- `giggsey/libphonenumber-for-php` - Phone number validation
- `league/csv` - CSV parsing (if not already installed)

### Internal Dependencies
- Outbound Whitelist module (for validation context)
- Auto Dialer Campaign module (for assignment)
- Audit logging system
- Queue infrastructure (already configured)

---

## Success Metrics

1. **Performance**
   - List creation < 1 second
   - CSV upload processing < 5 seconds per 1000 rows
   - Large file (100k) processing < 30 minutes
   - API response time < 500ms

2. **Reliability**
   - 99.9% successful CSV processing
   - Zero data loss during uploads
   - Accurate phone validation (libphonenumber)

3. **User Experience**
   - Upload success rate > 95%
   - Clear validation error messages
   - Intuitive versioning interface
   - Progress visibility for large uploads

---

## Questions & Clarifications

### Answered Questions (from conversation)
1. ✅ Lists are tightly coupled but can be copied
2. ✅ Lists are immutable with version support (v1, v2)
3. ✅ CSV upload + API (batch/single) supported
4. ✅ Dedicated UI with status lifecycle
5. ✅ Validation on upload using libphonenumber
6. ✅ Organization-shared with role-based access, example CSV provided
7. ✅ 100k max per list, larger files split, async processing
8. ✅ Soft archive only, usage/version tracking logged
9. ✅ Copy requires new name
10. ✅ Old versions archived, auto-numbered, viewable
11. ✅ Campaign locks list on start, no mid-switch
12. ✅ API nested under /auto-dialer-campaigns
13. ✅ Statistics at campaign level, per-destination metrics tracked
14. ✅ Sequential processing for large files
15. ✅ Archive only when not in use
16. ✅ Copy resets all statuses to pending

### Open Questions
None - all requirements clarified.

---

# TODO - Implementation Tracker

> **Legend:**
> - [ ] Pending (not started)
> - [~] In Progress
> - [x] Completed
> - [!] Blocked/Issue

---

## Phase 1: Database & Models (2-3 days)

### 1.1 Database Migrations
- [ ] Task 1.1: Modify auto_dialer_lists table (add versioning, status, tracking fields)
- [ ] Task 1.2: Modify auto_dialer_destinations table (add total_duration)
- [ ] Task 1.3: Create migration for list_metrics (optional)

### 1.2 Eloquent Models
- [ ] Task 2.1: Update AutoDialerList Model (fillable, casts, relationships, scopes, helpers)
- [ ] Task 2.2: Create ListStatus Enum (all statuses with methods)

### 1.3 Authorization
- [ ] Task 9.1: Create DistributionListPolicy (all permission methods)

**Phase 1 Status:** Pending  
**Phase 1 Assigned To:** TBD  
**Phase 1 Due Date:** TBD

---

## Phase 2: Backend Core (4-5 days)

### 2.1 Validation Service
- [ ] Task 3.1: Create ListValidationService (libphonenumber integration)
- [ ] Task 3.2: Create ValidationResult Value Object

### 2.2 Background Jobs
- [ ] Task 4.1: Create ProcessListUploadJob (with queue config)
- [ ] Task 4.2: Create ProcessLargeListJob (100k+ entries, sequential)

### 2.3 Business Logic
- [ ] Task 5.1: Create ListManagementService (all CRUD operations)

### 2.4 Controllers
- [ ] Task 6.1: Create DistributionListController (all endpoints)
- [ ] Task 6.2: Update AutoDialerCampaignController (assignment methods)

### 2.5 Validation & Resources
- [ ] Task 7.1: Create Form Requests (Create, Upload, AddDestination, Batch, Copy, Assign)
- [ ] Task 8.1: Create DistributionListResource
- [ ] Task 8.2: Create ListDestinationResource

**Phase 2 Status:** Pending  
**Phase 2 Assigned To:** TBD  
**Phase 2 Due Date:** TBD

---

## Phase 3: API Integration & Testing (3-4 days)

### 3.1 Events & Audit
- [ ] Task 10.1: Create List Events (ListCreated, ListUploaded, ListProcessed, etc.)
- [ ] Task 10.2: Add Audit Action Types to system

### 3.2 Unit Tests
- [ ] Task 15.1: Test ListValidationService (valid/invalid numbers, batch, regions)
- [ ] Task 15.2: Test ListManagementService (create, upload, copy, version, archive)
- [ ] Task 15.3: Test ListStatus Enum (transitions, permissions)

### 3.3 Feature Tests
- [ ] Task 16.1: Test API Endpoints (CRUD, upload, versioning, copy, archive)
- [ ] Task 16.2: Test Large File Processing (>100k, sequential, progress, splitting)
- [ ] Task 16.3: Test Validation (valid CSV, invalid phones, duplicates, errors)

**Phase 3 Status:** Pending  
**Phase 3 Assigned To:** TBD  
**Phase 3 Due Date:** TBD

---

## Phase 4: Frontend - Lists Page (3-4 days)

### 4.1 API Layer
- [ ] Task 11.1: Create distributionListsApi.ts (all API methods)
- [ ] Task 11.2: Create React Query Hooks (useDistributionLists, useDistributionList, useCreateList, etc.)

### 4.2 Pages
- [ ] Task 12.1: Create DistributionLists Page (list view, filters, table, pagination)
- [ ] Task 12.2: Create DistributionListDetail Page (header, stats, destinations table)

### 4.3 Routing
- [ ] Task 13.1: Update router.tsx (add list routes)

**Phase 4 Status:** Pending  
**Phase 4 Assigned To:** TBD  
**Phase 4 Due Date:** TBD

---

## Phase 5: Frontend - Upload & Versioning (3-4 days)

### 5.1 Dialogs
- [ ] Task 12.3: Create CreateListDialog (name, description)
- [ ] Task 12.4: Create UploadDestinationsDialog (drop zone, preview, progress)
- [ ] Task 12.5: Create CopyListDialog (new name, warnings)
- [ ] Task 12.6: Create NewVersionDialog (upload, archive old version)
- [ ] Task 12.7: Create AssignListToCampaignDialog (campaign selector)
- [ ] Task 12.8: Create ValidationErrorsDialog (error list, download)

### 5.2 CSV Template
- [ ] Task 14.1: Create Example CSV File
- [ ] Task 14.2: Create CSV Format Documentation

**Phase 5 Status:** Pending  
**Phase 5 Assigned To:** TBD  
**Phase 5 Due Date:** TBD

---

## Phase 6: Integration & Final Testing (2-3 days)

### 6.1 Frontend Tests
- [ ] Task 17.1: Test Components (upload, validation, progress, dialogs)

### 6.2 Integration
- [ ] Integration testing (end-to-end workflows)
- [ ] Bug fixes
- [ ] Performance optimization

**Phase 6 Status:** Pending  
**Phase 6 Assigned To:** TBD  
**Phase 6 Due Date:** TBD

---

## Progress Summary

| Phase | Tasks | Completed | Progress |
|-------|-------|-----------|----------|
| Phase 1 | 3 | 0 | 0% |
| Phase 2 | 11 | 0 | 0% |
| Phase 3 | 8 | 0 | 0% |
| Phase 4 | 4 | 0 | 0% |
| Phase 5 | 8 | 0 | 0% |
| Phase 6 | 3 | 0 | 0% |
| **Total** | **37** | **0** | **0%** |

**Overall Status:** Not Started  
**Estimated Completion:** TBD  
**Actual Duration:** TBD

---

## Dependencies Tracker

### External Libraries
- [ ] Install `giggsey/libphonenumber-for-php`
- [ ] Verify `league/csv` is installed

### Internal Dependencies
- [ ] Verify Outbound Whitelist module
- [ ] Verify Audit logging system
- [ ] Verify Queue infrastructure

---

## Blockers & Issues

| ID | Description | Severity | Status | Date | Resolution |
|----|-------------|----------|--------|------|------------|
| - | - | - | - | - | - |

---

## Notes & Decisions Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-03-26 | Initial workplan created | Requirements clarified and documented |

---

**Document Information**

**Version:** 1.0  
**Last Updated:** 2026-03-26  
**Author:** AI Assistant  
**Status:** Ready for Review  
**Next Step:** Await approval before implementation
