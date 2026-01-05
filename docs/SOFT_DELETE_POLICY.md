# Soft Delete Policy

## Overview

This document defines the soft delete behavior across the OpBX application. Soft deletion provides a way to mark records as deleted without permanently removing them from the database, allowing for recovery if needed.

## Implementation

The OpBX application uses Laravel's `SoftDeletes` trait to enable soft deletes. Models with this trait have records marked with `deleted_at` timestamp when deleted but remain in the database.

## Models with Soft Delete

### Models that Support Soft Delete:

| Model | Table | Soft Delete Field |
|-------|-------|-------------------|
| User | users | deleted_at |
| Extension | extensions | deleted_at |
| ConferenceRoom | conference_rooms | deleted_at |
| RingGroup | ring_groups | deleted_at |
| BusinessHoursSchedule | business_hours_schedules | deleted_at |
| BusinessHoursExceptionTimeRange | business_hours_exceptions | deleted_at |
| BusinessHoursScheduleDay | business_hours_schedule_days | deleted_at |
| DidNumber | did_numbers | deleted_at |
| CallLog | call_logs | deleted_at |
| Recording | recordings | deleted_at |
| CallDetailRecord | call_detail_records | deleted_at |
| CloudonixSettings | cloudonix_settings | deleted_at |

## Soft Delete Behavior

### When Deleting a Record:

1. **Query Scoping**: Most queries automatically exclude soft-deleted records
2. **Resource Collections**: API responses don't include soft-deleted records
3. **UI Behavior**: Soft-deleted records should not appear in lists
4. **Recovery**: Records can be restored if needed (by setting `deleted_at = null`)

### Example: Deleting an Extension

```php
$extension = Extension::find($id);
$extension->delete();  // Soft delete - sets deleted_at timestamp
```

## Best Practices

1. **Always use query scopes**: Soft-deleted records should be automatically excluded from frontend queries
2. **Force delete for truly sensitive data**: Use `forceDelete()` instead of `delete()` when data must be permanently removed
3. **Never hard delete soft-deletable models**: Always use soft delete to maintain data integrity
4. **Document restoration procedures**: If restoration is needed, clearly document the process and permissions

## Related Policies

All models with soft delete functionality have corresponding policies that define who can view, create, update, and delete these records. The soft delete behavior should align with these policies.

## Notes

- This policy ensures data can be recovered if accidental deletion occurs
- Hard deletes should only be used for compliance or when data must be permanently removed
- Regular cleanup jobs may permanently remove old soft-deleted records after a retention period

---

*Last Updated: 2026-01-05*
