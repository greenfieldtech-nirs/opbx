# Distribution Lists

## Overview
Contact lists containing phone numbers for auto-dialer campaigns. Supports CSV upload, single/batch add, versioning, copying, and archiving. Max 100,000 entries per list.

## Source Files
| File | Purpose |
|------|---------|
| `app/Http/Controllers/DistributionListController.php` | List CRUD + upload + assign (485 lines) |
| `app/Models/AutoDialerList.php` | List model with versioning (323 lines) |
| `app/Models/AutoDialerDestination.php` | Destination/contact model (157 lines) |
| `app/Enums/ListStatus.php` | DRAFT, PENDING, PROCESSING, READY, FAILED, IN_USE, USED, ARCHIVED |
| `app/Services/AutoDialer/ListManagementService.php` | Core operations (561 lines) |
| `app/Services/AutoDialer/ListValidationService.php` | Phone validation via libphonenumber (314 lines) |
| `app/Services/AutoDialer/DestinationValidator.php` | E.164 + whitelist validation |
| `app/Jobs/ProcessListUploadJob.php` | CSV processing |
| `app/Jobs/ProcessLargeListJob.php` | Large list processing (>100K) |
| `frontend/src/pages/DistributionLists.tsx` | List management |
| `frontend/src/pages/DistributionListDetail.tsx` | List detail |
| `frontend/src/services/distributionListsApi.ts` | API calls |

## List Status Lifecycle
```
DRAFT --[first dest added]--> READY
DRAFT --[CSV upload]--> PROCESSING --[success]--> READY
                                    --[failure]--> FAILED
READY --[assign to campaign]--> IN_USE --[campaign done]--> USED
READY|FAILED|USED --[archive]--> ARCHIVED
READY|USED --[create version]--> new version (DRAFT)
```

## Database Tables
### `auto_dialer_lists`
organization_id, campaign_id, name, description, version_number, parent_list_id (self-ref), is_latest_version, status, used_by_campaign_id, used_at, original_filename, total_rows, valid_rows, invalid_rows, validation_errors (JSON), archived_at

### `auto_dialer_destinations`
organization_id, list_id, phone_number, description, status (DestinationStatus), dial_attempts, priority, last_session_token, last_call_id, last_dialed_at, next_retry_at, last_disposition, duration, billsec, total_duration, last_cdr_id, last_error

## Versioning
- `parent_list_id` creates a version chain
- `createNewVersion()`: archives current, creates child with incremented version_number
- `getVersionHistory()`: traverses parent chain to root, returns all versions

## CSV Upload Flow
1. Store temp file, count rows
2. If <= 100K: dispatch `ProcessListUploadJob`
3. If > 100K: dispatch `ProcessLargeListJob`
4. Job validates each row (E.164), deduplicates, batch inserts (1000/batch)
5. Updates list stats (total_rows, valid_rows, invalid_rows)
6. Status: PROCESSING -> READY or FAILED

## Campaign Assignment (assignListToCampaign)
Validates: org match, campaign accepts list, list is READY. Sets list to IN_USE. May auto-activate DRAFT campaigns.

## API Routes (routes/api.php:240-273)
Prefix: `/v1/auto-dialer-campaigns/lists`
| Method | URI | Purpose |
|--------|-----|---------|
| GET/POST | `/` | List/create |
| GET | `/{list}` | Show |
| POST | `/{list}/upload` | CSV upload |
| GET | `/upload-progress/{jobId}` | Upload progress |
| POST | `/{list}/destinations` | Add single |
| POST | `/{list}/destinations/batch` | Add batch (max 1000) |
| GET | `/{list}/destinations` | List destinations |
| GET | `/{list}/versions` | Version history |
| POST | `/{list}/copy` | Copy list |
| PATCH | `/{list}/archive` | Archive |
| GET | `/{list}/download` | CSV export |
| DELETE | `/{list}` | Delete |
| POST | `/{list}/assign` | Assign to campaign |
| POST | `/{list}/unassign` | Unassign |

## Related Modules
- [Auto Dialer Campaigns](auto-dialer-campaigns.md) - Lists belong to campaigns
- [Outbound Whitelist](outbound-whitelist.md) - Trunk matching for validation
