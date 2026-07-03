# Auto Dialer Distribution List CSV Mapping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign distribution list CSV upload so users can map arbitrary CSV columns to `phone_number`, `name`, `batch_identifier`, and a flexible `metadata` bucket, while removing the legacy `description` column.

**Architecture:** Keep the existing Laravel queue-based upload pipeline but introduce a small, explicit mapping contract passed from the React UI through the controller/service into the validation job. Add dedicated destination columns for `name` and `batch_identifier` and a JSON `metadata` column for anything else; phone validation stays unchanged.

**Tech Stack:** Laravel 12 (PHP 8.4), MySQL, Redis queues, React 18 + TanStack Query + shadcn/ui, libphonenumber.

---

## File Map

| File | Responsibility |
|------|----------------|
| `database/migrations/2026_07_03_100000_update_auto_dialer_destinations_for_mapping.php` | Adds `name`, `batch_identifier`, `metadata`; drops `description`. |
| `app/Models/AutoDialerDestination.php` | Fillable/casts for new columns. |
| `app/Http/Resources/ListDestinationResource.php` | Exposes `name`, `batch_identifier`, `metadata`. |
| `app/Services/AutoDialer/CsvValidationResult.php` | Result shape for new row fields. |
| `app/Services/AutoDialer/ListValidationService.php` | Parses CSV preview and validates rows using a mapping config. |
| `app/Jobs/ProcessListUploadJob.php` | Accepts mapping, creates destinations with new fields. |
| `app/Services/AutoDialer/ListManagementService.php` | Orchestrates upload/insert/export with mapping and no `description`. |
| `app/Http/Controllers/DistributionListController.php` | Adds `/preview-csv`, updates `/upload` and manual destination endpoints. |
| `routes/api.php` | Registers `preview-csv` route. |
| `frontend/src/types/index.ts` | Adds `CsvMappingConfig` and new destination fields. |
| `frontend/src/services/distributionListsApi.ts` | Adds `previewCsv` and updates upload/manual APIs. |
| `frontend/src/hooks/useDistributionLists.ts` | Adds `usePreviewCsv` and updates `useUploadList`. |
| `frontend/src/pages/DistributionLists/components/UnifiedUploadDialog.tsx` | Mapping UI with preview and upload. |
| `frontend/src/pages/DistributionListDetail.tsx` | Destination table columns for `name`, `batch_identifier`, `metadata`. |
| `tests/Feature/DistributionListCsvMappingTest.php` | Feature tests for preview and upload mapping. |
| `tests/Unit/ListValidationServiceMappingTest.php` | Unit tests for mapping validation and metadata. |

---

### Task 1: Database Migration

**Files:**
- Create: `database/migrations/2026_07_03_100000_update_auto_dialer_destinations_for_mapping.php`

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_dialer_destinations', function (Blueprint $table) {
            if (! Schema::hasColumn('auto_dialer_destinations', 'name')) {
                $table->string('name', 255)->nullable()->after('phone_number');
            }
            if (! Schema::hasColumn('auto_dialer_destinations', 'batch_identifier')) {
                $table->string('batch_identifier', 255)->nullable()->after('name');
            }
            if (! Schema::hasColumn('auto_dialer_destinations', 'metadata')) {
                $table->json('metadata')->nullable()->after('batch_identifier');
            }
            if (Schema::hasColumn('auto_dialer_destinations', 'description')) {
                $table->dropColumn('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('auto_dialer_destinations', function (Blueprint $table) {
            $table->dropColumnIfExists(['name', 'batch_identifier', 'metadata']);
            if (! Schema::hasColumn('auto_dialer_destinations', 'description')) {
                $table->string('description', 255)->nullable()->after('phone_number');
            }
        });
    }
};
```

- [ ] **Step 2: Run migration**

Run: `docker compose exec app php artisan migrate`
Expected: `Migrated: 2026_07_03_100000_update_auto_dialer_destinations_for_mapping.php`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_07_03_100000_update_auto_dialer_destinations_for_mapping.php
git commit -m "feat(auto-dialer): add name, batch_identifier, metadata; drop description column"
```

---

### Task 2: Update Destination Model

**Files:**
- Modify: `app/Models/AutoDialerDestination.php`

- [ ] **Step 1: Replace `$fillable` and `$casts`**

In `app/Models/AutoDialerDestination.php`:

```php
protected $fillable = [
    'organization_id',
    'list_id',
    'phone_number',
    'name',
    'batch_identifier',
    'metadata',
    'status',
    'dial_attempts',
    'last_session_token',
    'last_call_id',
    'last_dialed_at',
    'next_retry_at',
    'last_disposition',
    'duration',
    'billsec',
    'total_duration',
    'last_cdr_id',
    'last_error',
    'priority',
];

protected $casts = [
    'status' => DestinationStatus::class,
    'metadata' => 'array',
    'last_dialed_at' => 'datetime',
    'next_retry_at' => 'datetime',
];
```

- [ ] **Step 2: Run model unit test if one exists**

Run: `./run-tests.sh --filter=AutoDialerDestinationTest` (skip if class does not exist)
Expected: PASS or no tests found

- [ ] **Step 3: Commit**

```bash
git add app/Models/AutoDialerDestination.php
git commit -m "feat(auto-dialer): destination model supports name, batch_identifier, metadata"
```

---

### Task 3: Update Destination Resource

**Files:**
- Modify: `app/Http/Resources/ListDestinationResource.php`

- [ ] **Step 1: Replace `toArray`**

```php
public function toArray(Request $request): array
{
    return [
        'id' => $this->id,
        'phone_number' => $this->phone_number,
        'name' => $this->name,
        'batch_identifier' => $this->batch_identifier,
        'metadata' => $this->metadata,
        'status' => $this->status->value,
        'status_label' => $this->status->label(),
        'priority' => $this->priority ?? 1,
        'dial_attempts' => $this->dial_attempts,
        'last_dialed_at' => $this->last_dialed_at?->format('Y-m-d H:i:s'),
        'last_disposition' => $this->last_disposition,
        'duration' => $this->duration,
        'billsec' => $this->billsec,
        'total_duration' => $this->total_duration,
        'last_error' => $this->last_error,
        'is_invalid' => $this->status->value === 'invalid',
        'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
    ];
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Resources/ListDestinationResource.php
git commit -m "feat(auto-dialer): expose name, batch_identifier, metadata in destination resource"
```

---

### Task 4: Update CSV Validation Result

**Files:**
- Modify: `app/Services/AutoDialer/CsvValidationResult.php`

- [ ] **Step 1: Update docblock and constructor**

```php
/**
 * @param  array<int, array{phone_number: string, name: ?string, batch_identifier: ?string, metadata: ?array<string, string>}>  $validRows
 * @param  array<int, array{row: int, phone_number: string, error: string}>  $invalidRows
 * @param  array<int, array{phone_number: string, row: int, kept_row: int}>  $duplicates
 */
public function __construct(
    public int $totalRows,
    public array $validRows,
    public array $invalidRows,
    public array $duplicates,
    public bool $success,
    public ?string $error = null,
) {}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/AutoDialer/CsvValidationResult.php
git commit -m "feat(auto-dialer): extend CsvValidationResult for mapped fields"
```

---

### Task 5: Refactor ListValidationService for Mapping

**Files:**
- Modify: `app/Services/AutoDialer/ListValidationService.php`

- [ ] **Step 1: Add a preview method at the top of the class**

```php
/**
 * Parse a CSV preview: headers and first N rows.
 *
 * @return array{headers: array<int, string>, rows: array<int, array<string, string>>, total_rows: int, has_header: bool}
 */
public function parseCsvPreview(string $filePath, bool $hasHeader = true, int $limit = 5): array
{
    $handle = fopen($filePath, 'r');
    if ($handle === false) {
        return ['headers' => [], 'rows' => [], 'total_rows' => 0, 'has_header' => $hasHeader];
    }

    $headers = [];
    $rows = [];
    $totalRows = 0;
    $rowNumber = 0;

    while (($rowData = fgetcsv($handle, escape: '\\')) !== false) {
        $rowNumber++;

        if ($rowNumber === 1 && $hasHeader) {
            $headers = array_map(fn ($h) => trim((string) $h), $rowData);
            continue;
        }

        if ($totalRows < $limit) {
            $rows[] = $hasHeader
                ? $this->combineWithHeaders($headers, $rowData)
                : $this->combineWithColumnIndexes($rowData);
        }
        $totalRows++;
    }

    fclose($handle);

    return [
        'headers' => $headers,
        'rows' => $rows,
        'total_rows' => $totalRows,
        'has_header' => $hasHeader,
    ];
}

/**
 * @param  array<int, string>  $headers
 * @param  array<int, string>  $rowData
 * @return array<string, string>
 */
private function combineWithHeaders(array $headers, array $rowData): array
{
    $combined = [];
    foreach ($headers as $index => $header) {
        $combined[$header] = $rowData[$index] ?? '';
    }

    return $combined;
}

/**
 * @param  array<int, string>  $rowData
 * @return array<string, string>
 */
private function combineWithColumnIndexes(array $rowData): array
{
    $combined = [];
    foreach ($rowData as $index => $value) {
        $combined["column_".($index + 1)] = $value;
    }

    return $combined;
}
```

- [ ] **Step 2: Replace `validateCsvFile` signature and body**

Replace the entire `validateCsvFile` method with:

```php
/**
 * Validate a CSV file using a column mapping.
 *
 * @param  string  $filePath  Path to the CSV file
 * @param  int  $organizationId  Organization ID for context
 * @param  array<string, mixed>  $mapping  Mapping config: phone, name?, batch_identifier?, metadata?[]
 * @param  string|null  $defaultRegion  Default region for validation
 */
public function validateCsvFile(
    string $filePath,
    int $organizationId,
    array $mapping,
    ?string $defaultRegion = 'US',
): CsvValidationResult {
    try {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return new CsvValidationResult(
                totalRows: 0,
                validRows: [],
                invalidRows: [],
                duplicates: [],
                success: false,
                error: 'Failed to open CSV file',
            );
        }

        $headers = fgetcsv($handle, escape: '\\');
        if ($headers === false) {
            fclose($handle);

            return new CsvValidationResult(
                totalRows: 0,
                validRows: [],
                invalidRows: [],
                duplicates: [],
                success: false,
                error: 'CSV file is empty or has no headers',
            );
        }

        $headers = array_map(fn ($h) => trim((string) $h), $headers);
        $phoneColumn = $mapping['phone'] ?? null;

        if (empty($phoneColumn) || ! in_array($phoneColumn, $headers, true)) {
            fclose($handle);

            return new CsvValidationResult(
                totalRows: 0,
                validRows: [],
                invalidRows: [],
                duplicates: [],
                success: false,
                error: 'CSV must contain a column mapped to phone_number',
            );
        }

        $nameColumn = $mapping['name'] ?? null;
        $batchColumn = $mapping['batch_identifier'] ?? null;
        $metadataColumns = $mapping['metadata'] ?? [];

        $validRows = [];
        $invalidRows = [];
        $seenNumbers = [];
        $duplicates = [];
        $rowNumber = 0;

        while (($rowData = fgetcsv($handle, escape: '\\')) !== false) {
            $rowNumber++;

            $record = $this->combineWithHeaders($headers, $rowData);
            if (empty($record[$phoneColumn])) {
                continue;
            }

            $phoneNumber = trim($record[$phoneColumn]);
            $name = $nameColumn ? trim($record[$nameColumn] ?? '') : null;
            $name = $name === '' ? null : $name;
            $batchIdentifier = $batchColumn ? trim($record[$batchColumn] ?? '') : null;
            $batchIdentifier = $batchIdentifier === '' ? null : $batchIdentifier;

            $metadata = null;
            if (! empty($metadataColumns)) {
                foreach ($metadataColumns as $column) {
                    if (in_array($column, $headers, true)) {
                        $value = trim($record[$column] ?? '');
                        if ($value !== '') {
                            $metadata[$column] = $value;
                        }
                    }
                }
            }

            $normalizedForDedup = $this->normalizeForDedup($phoneNumber);

            if (isset($seenNumbers[$normalizedForDedup])) {
                $duplicates[] = [
                    'phone_number' => $phoneNumber,
                    'row' => $rowNumber,
                    'kept_row' => $seenNumbers[$normalizedForDedup],
                ];

                continue;
            }

            $validationResult = $this->validatePhoneNumber($phoneNumber, $defaultRegion);

            if ($validationResult->valid) {
                $validRows[] = [
                    'phone_number' => $validationResult->normalizedNumber,
                    'name' => $name,
                    'batch_identifier' => $batchIdentifier,
                    'metadata' => $metadata,
                ];
                $seenNumbers[$normalizedForDedup] = $rowNumber;
            } else {
                $invalidRows[] = [
                    'row' => $rowNumber,
                    'phone_number' => $phoneNumber,
                    'error' => $validationResult->error,
                ];
            }
        }

        fclose($handle);

        return new CsvValidationResult(
            totalRows: $rowNumber,
            validRows: $validRows,
            invalidRows: $invalidRows,
            duplicates: $duplicates,
            success: true,
        );
    } catch (\Exception $e) {
        return new CsvValidationResult(
            totalRows: 0,
            validRows: [],
            invalidRows: [],
            duplicates: [],
            success: false,
            error: 'Failed to parse CSV: '.$e->getMessage(),
        );
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Services/AutoDialer/ListValidationService.php
git commit -m "feat(auto-dialer): ListValidationService supports arbitrary column mapping and preview"
```

---

### Task 6: Update ProcessListUploadJob

**Files:**
- Modify: `app/Jobs/ProcessListUploadJob.php`

- [ ] **Step 1: Add mapping to the constructor**

```php
public function __construct(
    public int $listId,
    public string $filePath,
    public string $jobId,
    public bool $isNewVersion = false,
    public array $mapping = [],
) {}
```

- [ ] **Step 2: Pass mapping to validation**

In `handle()`, change the validation call to:

```php
$result = $validator->validateCsvFile(
    $this->filePath,
    $list->organization_id,
    $this->mapping,
);
```

- [ ] **Step 3: Update destination creation**

In `createDestinations()`, update the insert array:

```php
foreach ($batch as $row) {
    $destinations[] = [
        'organization_id' => $list->organization_id,
        'list_id' => $list->id,
        'phone_number' => $row['phone_number'],
        'name' => $row['name'] ?? null,
        'batch_identifier' => $row['batch_identifier'] ?? null,
        'metadata' => isset($row['metadata']) ? json_encode($row['metadata']) : null,
        'status' => DestinationStatus::PENDING,
        'dial_attempts' => 0,
        'duration' => 0,
        'billsec' => 0,
        'total_duration' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}
```

- [ ] **Step 4: Commit**

```bash
git add app/Jobs/ProcessListUploadJob.php
git commit -m "feat(auto-dialer): ProcessListUploadJob uses mapping and writes new destination fields"
```

---

### Task 7: Update ListManagementService

**Files:**
- Modify: `app/Services/AutoDialer/ListManagementService.php`

- [ ] **Step 1: Update uploadCsv signature and dispatch**

Replace `uploadCsv` with:

```php
/**
 * Upload CSV file to a list.
 *
 * @param  array<string, mixed>  $mapping
 * @return array{job_id: string, list_id: int, is_large_file: bool, total_rows: int}
 */
public function uploadCsv(
    int $listId,
    UploadedFile $file,
    array $mapping = [],
): array {
    $list = AutoDialerList::findOrFail($listId);

    if (! $list->status->canUpload()) {
        throw new \InvalidArgumentException('List cannot accept uploads in current status: '.$list->status->label());
    }

    $tempPath = $file->store('temp/list_uploads');
    $fullPath = storage_path('app/private/'.$tempPath);

    $jobId = Str::uuid()->toString();

    $rowCount = $this->countCsvRows($fullPath);
    $isLargeFile = $rowCount > self::MAX_ENTRIES_PER_LIST;

    Cache::put(
        "list_upload_progress:{$jobId}",
        [
            'percentage' => 0,
            'status' => 'queued',
            'updated_at' => now()->toIso8601String(),
        ],
        now()->addHours(2)
    );

    if ($isLargeFile) {
        ProcessLargeListJob::dispatch($list->id, $fullPath, $jobId, $rowCount, $mapping)
            ->onQueue('auto-dialer');
    } else {
        ProcessListUploadJob::dispatch($list->id, $fullPath, $jobId, false, $mapping)
            ->onQueue('auto-dialer');
    }

    $list->update([
        'original_filename' => $file->getClientOriginalName(),
        'status' => ListStatus::PROCESSING,
    ]);

    return [
        'job_id' => $jobId,
        'list_id' => $list->id,
        'is_large_file' => $isLargeFile,
        'total_rows' => $rowCount,
    ];
}
```

- [ ] **Step 2: Update updateListWithBackup signature**

Replace method signature and upload call with:

```php
/**
 * Update list with new CSV - backup old destinations first.
 *
 * @param  array<string, mixed>  $mapping
 * @return array{job_id: string, list_id: int, is_large_file: bool, total_rows: int, version_number: int, backup_path: string}
 */
public function updateListWithBackup(int $listId, UploadedFile $file, array $mapping = []): array
{
    $list = AutoDialerList::findOrFail($listId);

    if (! $list->status->canCreateVersion()) {
        throw new \InvalidArgumentException('Cannot update list in status: '.$list->status->label());
    }

    $backupPath = $this->backupDestinations($list);
    $deletedCount = $list->destinations()->delete();

    $list->update([
        'total_rows' => 0,
        'valid_rows' => 0,
        'invalid_rows' => 0,
        'status' => ListStatus::PENDING,
        'version_number' => $list->version_number + 1,
    ]);

    Log::info('ListManagementService: Backed up and cleared old destinations', [
        'list_id' => $listId,
        'backup_path' => $backupPath,
        'deleted_count' => $deletedCount,
        'new_version' => $list->version_number,
    ]);

    $result = $this->uploadCsv($listId, $file, $mapping);

    return [
        ...$result,
        'version_number' => $list->version_number,
        'backup_path' => $backupPath,
    ];
}
```

- [ ] **Step 3: Replace manual destination methods**

Replace `addDestination` with:

```php
public function addDestination(
    int $listId,
    string $phoneNumber,
    ?string $name = null,
): AutoDialerDestination {
    $list = AutoDialerList::findOrFail($listId);

    if (! $list->status->canUpload()) {
        throw new \InvalidArgumentException('Cannot add destinations to list in status: '.$list->status->label());
    }

    $validation = $this->validator->validatePhoneNumber($phoneNumber);

    if (! $validation->valid) {
        throw new \InvalidArgumentException('Invalid phone number: '.$validation->error);
    }

    $existing = AutoDialerDestination::where('list_id', $listId)
        ->where('phone_number', $validation->normalizedNumber)
        ->first();

    if ($existing) {
        throw new \InvalidArgumentException('Phone number already exists in this list');
    }

    $destination = AutoDialerDestination::create([
        'organization_id' => $list->organization_id,
        'list_id' => $list->id,
        'phone_number' => $validation->normalizedNumber,
        'name' => $name,
        'status' => DestinationStatus::PENDING,
        'dial_attempts' => 0,
        'duration' => 0,
        'billsec' => 0,
        'total_duration' => 0,
    ]);

    $list->update([
        'total_rows' => $list->total_rows + 1,
        'valid_rows' => $list->valid_rows + 1,
    ]);

    if ($list->status === ListStatus::DRAFT) {
        $list->update(['status' => ListStatus::READY]);
    }

    return $destination;
}
```

Replace `addDestinationsBatch` `$validEntries` building with:

```php
$validEntries[] = [
    'organization_id' => $list->organization_id,
    'list_id' => $list->id,
    'phone_number' => $result->normalizedNumber,
    'name' => $destinations[$index]['name'] ?? null,
    'status' => DestinationStatus::PENDING,
    'dial_attempts' => 0,
    'duration' => 0,
    'billsec' => 0,
    'total_duration' => 0,
    'created_at' => now(),
    'updated_at' => now(),
];
```

- [ ] **Step 4: Update export, backup, example, and copy**

In `generateCsvExport`, change header and row to:

```php
fputcsv($handle, ['phone_number', 'name', 'batch_identifier', 'metadata', 'status', 'dial_attempts']);

$list->destinations()->chunk(1000, function ($destinations) use ($handle) {
    foreach ($destinations as $destination) {
        fputcsv($handle, [
            $destination->phone_number,
            $destination->name,
            $destination->batch_identifier,
            $destination->metadata ? json_encode($destination->metadata) : null,
            $destination->status->value,
            $destination->dial_attempts,
        ]);
    }
});
```

In `backupDestinations`, change the mapped fields to include `name`, `batch_identifier`, `metadata` instead of `description`.

In `copy`, change the destination create to include `name`.

In `downloadExample`, replace the content with:

```php
$content = "phone_number,name,batch_identifier\n".
    "+14155551212,John Doe,batch-a\n".
    "+14155551213,Jane Smith,batch-b\n".
    "+14155551214,Bob Johnson,batch-a\n";
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/AutoDialer/ListManagementService.php
git commit -m "feat(auto-dialer): remove description and support mapping in list management"
```

---

### Task 8: Update DistributionListController

**Files:**
- Modify: `app/Http/Controllers/DistributionListController.php`

- [ ] **Step 1: Update upload validation**

In `upload`:

```php
$validated = $request->validate([
    'file' => ['required', 'file', 'mimes:csv,txt', 'max:51200'],
    'mapping' => ['required', 'array'],
    'mapping.phone' => ['required', 'string'],
    'mapping.name' => ['nullable', 'string'],
    'mapping.batch_identifier' => ['nullable', 'string'],
    'mapping.metadata' => ['nullable', 'array'],
    'mapping.metadata.*' => ['string'],
]);

$mapping = $validated['mapping'];
$file = $validated['file'];
```

Pass `$mapping` to `$this->listService->uploadCsv(...)` and `$this->listService->updateListWithBackup(...)`.

- [ ] **Step 2: Add preview endpoint**

Add a new method after `uploadProgress`:

```php
/**
 * Preview a CSV file before upload.
 */
public function previewCsv(Request $request, AutoDialerList $list): JsonResponse
{
    $this->authorize('view', $list);

    $validated = $request->validate([
        'file' => ['required', 'file', 'mimes:csv,txt', 'max:51200'],
        'has_header' => ['boolean'],
    ]);

    $file = $validated['file'];
    $hasHeader = $validated['has_header'] ?? true;

    $tempPath = $file->store('temp/list_previews');
    $fullPath = storage_path('app/private/'.$tempPath);

    try {
        $preview = $this->listService->previewCsv($fullPath, $hasHeader);

        return response()->json([
            'data' => $preview,
        ]);
    } finally {
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }
}
```

- [ ] **Step 3: Update manual destination endpoints**

In `addDestination`:

```php
$validated = $request->validate([
    'phone_number' => ['required', 'string'],
    'name' => ['nullable', 'string', 'max:255'],
]);
```

Pass `$validated['name'] ?? null` to `addDestination`.

In `addDestinationsBatch`:

```php
'destinations.*.name' => ['nullable', 'string', 'max:255'],
```

- [ ] **Step 4: Update example CSV content**

Already covered in Task 7; ensure `downloadExample` uses the new content.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/DistributionListController.php
git commit -m "feat(auto-dialer): add preview endpoint, update upload with mapping, remove description"
```

---

### Task 9: Add Preview Service Method

**Files:**
- Modify: `app/Services/AutoDialer/ListManagementService.php`

- [ ] **Step 1: Add previewCsv method**

Add near `uploadCsv`:

```php
/**
 * Preview a CSV file for column mapping.
 *
 * @return array{headers: array<int, string>, rows: array<int, array<string, string>>, total_rows: int, has_header: bool}
 */
public function previewCsv(string $filePath, bool $hasHeader = true): array
{
    return $this->validator->parseCsvPreview($filePath, $hasHeader, 5);
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/AutoDialer/ListManagementService.php
git commit -m "feat(auto-dialer): add previewCsv helper"
```

---

### Task 10: Register Route

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 1: Add preview route after upload route**

```php
Route::post('auto-dialer-campaigns/lists/{list}/preview-csv', [DistributionListController::class, 'previewCsv'])
    ->name('distribution-lists.preview-csv');
```

- [ ] **Step 2: Commit**

```bash
git add routes/api.php
git commit -m "feat(auto-dialer): register preview-csv route"
```

---

### Task 11: Update Frontend Types

**Files:**
- Modify: `frontend/src/types/index.ts`

- [ ] **Step 1: Update ListDestination and add CsvMappingConfig**

In the Distribution Lists section, update `ListDestination` and add:

```typescript
export interface ListDestination {
  id: number;
  phone_number: string;
  name: string | null;
  batch_identifier: string | null;
  metadata: Record<string, string> | null;
  status: string;
  status_label: string;
  dial_attempts: number;
  last_dialed_at: string | null;
  last_disposition: string | null;
  duration: number;
  billsec: number;
  total_duration: number;
  last_error: string | null;
  is_invalid: boolean;
  created_at: string;
  updated_at: string;
}

export interface CsvMappingConfig {
  phone: string;
  name?: string;
  batch_identifier?: string;
  metadata?: string[];
}

export interface CsvPreview {
  headers: string[];
  rows: Record<string, string>[];
  total_rows: number;
  has_header: boolean;
}
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/types/index.ts
git commit -m "feat(auto-dialer): add CsvMappingConfig and new destination fields"
```

---

### Task 12: Update Frontend API

**Files:**
- Modify: `frontend/src/services/distributionListsApi.ts`

- [ ] **Step 1: Update imports and add preview/upload signatures**

Update imports:

```typescript
import type { AutoDialerList, CreateListRequest, CsvMappingConfig, CsvPreview, DistributionListParams, PaginatedResponse } from '@/types';
```

Add `previewCsv` method:

```typescript
previewCsv: async (
    listId: string | number,
    file: File,
    hasHeader: boolean = true,
  ): Promise<{ data: CsvPreview }> => {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('has_header', hasHeader ? '1' : '0');

    const response = await api.post(`/auto-dialer-campaigns/lists/${listId}/preview-csv`, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    return response.data;
  },
```

Update `uploadCsv` signature:

```typescript
uploadCsv: async (
    listId: string | number,
    file: File,
    mapping: CsvMappingConfig,
  ): Promise<{
    message: string;
    data: {
      job_id: string;
      list_id: number;
      is_large_file: boolean;
      total_rows: number;
      action: 'upload' | 'update';
      new_version_number?: number;
      backup_path?: string;
    }
  }> => {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('mapping', JSON.stringify(mapping));

    const response = await api.post(`/auto-dialer-campaigns/lists/${listId}/upload`, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    return response.data;
  },
```

Update `addDestination` and `addDestinationsBatch` to use `name`:

```typescript
addDestination: async (
    listId: string | number,
    data: { phone_number: string; name?: string },
  ): Promise<{ message: string; data: ListDestination }> => {
    const response = await api.post(`/auto-dialer-campaigns/lists/${listId}/destinations`, data);
    return response.data;
  },

addDestinationsBatch: async (
    listId: string | number,
    destinations: Array<{ phone_number: string; name?: string }>,
  ): Promise<{ message: string; data: BatchAddResult }> => {
    const response = await api.post(`/auto-dialer-campaigns/lists/${listId}/destinations/batch`, {
      destinations,
    });
    return response.data;
  },
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/services/distributionListsApi.ts
git commit -m "feat(auto-dialer): add previewCsv and mapping to upload APIs"
```

---

### Task 13: Update Frontend Hooks

**Files:**
- Modify: `frontend/src/hooks/useDistributionLists.ts`

- [ ] **Step 1: Add import and update upload hook**

Update import:

```typescript
import type { CreateListRequest, CsvMappingConfig, CsvPreview, DistributionListParams } from '@/types';
```

Add `usePreviewCsv`:

```typescript
export function usePreviewCsv() {
  return useMutation({
    mutationFn: ({
      listId,
      file,
      hasHeader,
    }: {
      listId: string | number;
      file: File;
      hasHeader?: boolean;
    }) => distributionListsApi.previewCsv(listId, file, hasHeader),
  });
}
```

Update `useUploadList`:

```typescript
export function useUploadList() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      listId,
      file,
      mapping,
    }: {
      listId: string | number;
      file: File;
      mapping: CsvMappingConfig;
    }) => distributionListsApi.uploadCsv(listId, file, mapping),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: distributionListKeys.detail(variables.listId),
      });
    },
  });
}
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/hooks/useDistributionLists.ts
git commit -m "feat(auto-dialer): add usePreviewCsv and mapping-aware upload hook"
```

---

### Task 14: Rewrite UnifiedUploadDialog with Mapping

**Files:**
- Modify: `frontend/src/pages/DistributionLists/components/UnifiedUploadDialog.tsx`

- [ ] **Step 1: Replace the entire component with a mapping UI**

```tsx
import { useState, useRef, useEffect, useMemo } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Upload, FileSpreadsheet, X, CheckCircle, AlertCircle, AlertTriangle } from 'lucide-react';
import { useUploadList, useUploadProgress, usePreviewCsv } from '@/hooks/useDistributionLists';
import { toast } from 'sonner';
import type { AutoDialerList, CsvMappingConfig } from '@/types';

interface UnifiedUploadDialogProps {
  list: AutoDialerList;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess?: (newListId?: number) => void;
}

const REQUIRED_FIELDS = [
  { key: 'phone', label: 'Phone Number *' },
  { key: 'name', label: 'Full Name' },
  { key: 'batch_identifier', label: 'Batch Identifier' },
];

export function UnifiedUploadDialog({
  list,
  open,
  onOpenChange,
  onSuccess,
}: UnifiedUploadDialogProps) {
  const [file, setFile] = useState<File | null>(null);
  const [hasHeader, setHasHeader] = useState(true);
  const [preview, setPreview] = useState<{ headers: string[]; rows: Record<string, string>[]; total_rows: number } | null>(null);
  const [mapping, setMapping] = useState<CsvMappingConfig>({ phone: '', name: '', batch_identifier: '', metadata: [] });
  const [jobId, setJobId] = useState<string | null>(null);
  const [uploadResult, setUploadResult] = useState<{
    action?: 'upload' | 'update';
    listId?: number;
    newVersionNumber?: number;
  } | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const autoCloseTimerRef = useRef<NodeJS.Timeout | null>(null);

  const uploadMutation = useUploadList();
  const previewMutation = usePreviewCsv();
  const { data: progressData } = useUploadProgress(jobId);

  const willCreateVersion = !list.status?.match(/^(draft|pending|failed)$/);
  const newVersionNumber = list.version_number + 1;

  const availableColumns = useMemo(() => preview?.headers ?? [], [preview]);

  const resetDialog = () => {
    setFile(null);
    setHasHeader(true);
    setPreview(null);
    setMapping({ phone: '', name: '', batch_identifier: '', metadata: [] });
    setJobId(null);
    setUploadResult(null);
    if (autoCloseTimerRef.current) {
      clearTimeout(autoCloseTimerRef.current);
      autoCloseTimerRef.current = null;
    }
  };

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const selectedFile = e.target.files?.[0];
    if (!selectedFile) return;
    if (!selectedFile.name.endsWith('.csv')) {
      toast.error('Please upload a CSV file');
      return;
    }
    setFile(selectedFile);
    setPreview(null);
    setMapping({ phone: '', name: '', batch_identifier: '', metadata: [] });
  };

  const handlePreview = async () => {
    if (!file) return;
    try {
      const result = await previewMutation.mutateAsync({ listId: list.id, file, hasHeader });
      setPreview(result.data);
      // Auto-map common phone headers if possible
      const headers = result.data.headers;
      const phoneGuess = headers.find((h) => h.toLowerCase().includes('phone') || h.toLowerCase() === 'phone_number') ?? '';
      const nameGuess = headers.find((h) => h.toLowerCase().includes('name') && h.toLowerCase() !== 'phone_number') ?? '';
      const batchGuess = headers.find((h) => h.toLowerCase().includes('batch')) ?? '';
      setMapping({ phone: phoneGuess, name: nameGuess, batch_identifier: batchGuess, metadata: [] });
    } catch {
      toast.error('Failed to parse CSV preview');
    }
  };

  const handleUpload = async () => {
    if (!file || !mapping.phone) return;

    const cleanedMapping: CsvMappingConfig = {
      phone: mapping.phone,
      ...(mapping.name ? { name: mapping.name } : {}),
      ...(mapping.batch_identifier ? { batch_identifier: mapping.batch_identifier } : {}),
      ...(mapping.metadata?.length ? { metadata: mapping.metadata } : {}),
    };

    try {
      const result = await uploadMutation.mutateAsync({
        listId: list.id,
        file,
        mapping: cleanedMapping,
      });

      setJobId(result.data.job_id);
      setUploadResult({
        action: result.data.action,
        listId: result.data.list_id,
        newVersionNumber: result.data.new_version_number,
      });

      toast.success(
        result.data.action === 'update'
          ? `Version ${result.data.new_version_number} created. Old data backed up. Processing...`
          : 'Upload started. Processing...'
      );
    } catch {
      toast.error('Failed to start upload');
    }
  };

  useEffect(() => {
    const status = progressData?.data?.status;

    if (status === 'completed' && uploadResult && !autoCloseTimerRef.current) {
      if (onSuccess) {
        onSuccess(uploadResult.listId || list.id);
      }
      autoCloseTimerRef.current = setTimeout(() => {
        handleClose(false);
      }, 2000);
    }

    return () => {
      if (autoCloseTimerRef.current) {
        clearTimeout(autoCloseTimerRef.current);
      }
    };
  }, [progressData?.data?.status, uploadResult]);

  const handleClose = (triggerSuccess = false) => {
    if (triggerSuccess && onSuccess) {
      onSuccess(uploadResult?.listId || list.id);
    }
    resetDialog();
    onOpenChange(false);
  };

  const isComplete = progressData?.data?.status === 'completed';
  const isFailed =
    progressData?.data?.status === 'failed' ||
    progressData?.data?.status === 'error' ||
    progressData?.data?.status === 'validation_failed';
  const progress = progressData?.data?.percentage || 0;

  const mappedPreview = useMemo(() => {
    if (!preview) return [];
    return preview.rows.map((row) => ({
      phone: mapping.phone ? row[mapping.phone] ?? '' : '',
      name: mapping.name ? row[mapping.name] ?? '' : '',
      batch: mapping.batch_identifier ? row[mapping.batch_identifier] ?? '' : '',
      metadata: mapping.metadata?.map((col) => `${col}: ${row[col] ?? ''}`).join(', ') ?? '',
    }));
  }, [preview, mapping]);

  return (
    <Dialog open={open} onOpenChange={(value) => !value && handleClose(false)}>
      <DialogContent className="sm:max-w-[700px]">
        <DialogHeader>
          <DialogTitle>Upload Destinations</DialogTitle>
          <DialogDescription>
            Upload a CSV file to &quot;{list.name}&quot; and map columns.
          </DialogDescription>
        </DialogHeader>

        {willCreateVersion && !jobId && (
          <Alert className="bg-amber-50 border-amber-200">
            <AlertTriangle className="h-4 w-4 text-amber-600" />
            <AlertDescription className="text-amber-800">
              Current version (v{list.version_number}) will be archived and version {newVersionNumber} will be created.
            </AlertDescription>
          </Alert>
        )}

        {!jobId && !preview && (
          <>
            <div className="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center">
              <input
                ref={fileInputRef}
                type="file"
                accept=".csv"
                onChange={handleFileSelect}
                className="hidden"
              />
              {file ? (
                <div className="flex items-center justify-center gap-2">
                  <FileSpreadsheet className="h-8 w-8 text-green-600" />
                  <div className="text-left">
                    <p className="font-medium">{file.name}</p>
                    <p className="text-sm text-muted-foreground">{(file.size / 1024).toFixed(1)} KB</p>
                  </div>
                  <Button variant="ghost" size="icon" onClick={() => setFile(null)}>
                    <X className="h-4 w-4" />
                  </Button>
                </div>
              ) : (
                <button onClick={() => fileInputRef.current?.click()} className="w-full">
                  <Upload className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                  <p className="text-lg font-medium">Click to select a CSV file</p>
                  <p className="text-sm text-muted-foreground mt-2">CSV must include a phone column</p>
                </button>
              )}
            </div>

            <div className="flex items-center gap-2">
              <Checkbox
                id="has-header"
                checked={hasHeader}
                onCheckedChange={(checked) => setHasHeader(checked === true)}
              />
              <Label htmlFor="has-header">File has a header row</Label>
            </div>

            <Button onClick={handlePreview} disabled={!file || previewMutation.isPending}>
              {previewMutation.isPending ? 'Parsing...' : 'Continue to Mapping'}
            </Button>
          </>
        )}

        {!jobId && preview && (
          <div className="space-y-4">
            <p className="text-sm text-muted-foreground">{preview.total_rows.toLocaleString()} rows detected</p>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              {REQUIRED_FIELDS.map((field) => (
                <div key={field.key} className="space-y-2">
                  <Label>{field.label}</Label>
                  <Select
                    value={mapping[field.key as keyof CsvMappingConfig] as string}
                    onValueChange={(value) =>
                      setMapping((prev) => ({ ...prev, [field.key]: value === 'NONE' ? '' : value }))
                    }
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select column" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="NONE">None</SelectItem>
                      {availableColumns.map((col) => (
                        <SelectItem key={col} value={col}>
                          {col}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              ))}
            </div>

            <div className="space-y-2">
              <Label>Metadata columns</Label>
              <div className="grid grid-cols-2 gap-2 border rounded p-3">
                {availableColumns.map((col) => (
                  <div key={col} className="flex items-center gap-2">
                    <Checkbox
                      id={`meta-${col}`}
                      checked={mapping.metadata?.includes(col) ?? false}
                      onCheckedChange={(checked) => {
                        setMapping((prev) => {
                          const current = prev.metadata ?? [];
                          const updated = checked
                            ? [...current, col]
                            : current.filter((c) => c !== col);
                          return { ...prev, metadata: updated };
                        });
                      }}
                    />
                    <Label htmlFor={`meta-${col}`} className="text-sm font-normal">{col}</Label>
                  </div>
                ))}
              </div>
            </div>

            {mappedPreview.length > 0 && (
              <div className="border rounded-md overflow-hidden">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Phone</TableHead>
                      <TableHead>Name</TableHead>
                      <TableHead>Batch</TableHead>
                      <TableHead>Metadata</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {mappedPreview.slice(0, 3).map((row, idx) => (
                      <TableRow key={idx}>
                        <TableCell>{row.phone}</TableCell>
                        <TableCell>{row.name || '-'}</TableCell>
                        <TableCell>{row.batch || '-'}</TableCell>
                        <TableCell className="text-xs text-muted-foreground max-w-[200px] truncate">{row.metadata || '-'}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}

            <Button variant="outline" onClick={() => setPreview(null)}>
              Back
            </Button>
          </div>
        )}

        {jobId && (
          <div className="space-y-4">
            <div className="space-y-2">
              <div className="flex justify-between text-sm">
                <span>Status: {progressData?.data?.status}</span>
                <span>{progress}%</span>
              </div>
              <div className="w-full bg-gray-200 rounded-full h-2">
                <div className="bg-blue-600 h-2 rounded-full transition-all" style={{ width: `${progress}%` }} />
              </div>
            </div>
            {isComplete && (
              <Alert className="bg-green-50 border-green-200">
                <CheckCircle className="h-4 w-4 text-green-600" />
                <AlertDescription className="text-green-800">
                  {uploadResult?.action === 'update'
                    ? `Version ${uploadResult.newVersionNumber} created and uploaded successfully!`
                    : 'Upload completed successfully!'}
                </AlertDescription>
              </Alert>
            )}
            {isFailed && (
              <Alert variant="destructive">
                <AlertCircle className="h-4 w-4" />
                <AlertDescription>
                  {progressData?.data?.status === 'validation_failed'
                    ? 'CSV validation failed. Check the file format and mapping.'
                    : 'Upload failed. Check validation errors.'}
                </AlertDescription>
              </Alert>
            )}
          </div>
        )}

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => handleClose(false)}
            disabled={uploadMutation.isPending || (!isComplete && !isFailed && jobId !== null)}
          >
            {isComplete || isFailed ? 'Close' : 'Cancel'}
          </Button>
          {!jobId && preview && (
            <Button onClick={handleUpload} disabled={!mapping.phone || uploadMutation.isPending}>
              {uploadMutation.isPending ? 'Starting...' : willCreateVersion ? 'Create Version & Upload' : 'Start Upload'}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export default UnifiedUploadDialog;
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/pages/DistributionLists/components/UnifiedUploadDialog.tsx
git commit -m "feat(auto-dialer): mapping UI in UnifiedUploadDialog"
```

---

### Task 15: Update DistributionListDetail Columns

**Files:**
- Modify: `frontend/src/pages/DistributionListDetail.tsx`

- [ ] **Step 1: Replace Description column**

In the table header, replace:

```tsx
<TableHead>Phone Number</TableHead>
<TableHead>Name</TableHead>
<TableHead>Batch</TableHead>
<TableHead>Metadata</TableHead>
<TableHead>Status</TableHead>
```

In the table body row, replace the description cell with:

```tsx
<TableCell>{destination.name || '-'}</TableCell>
<TableCell>{destination.batch_identifier || '-'}</TableCell>
<TableCell className="max-w-[150px] truncate">
  {destination.metadata ? Object.keys(destination.metadata).length : 0} fields
</TableCell>
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/pages/DistributionListDetail.tsx
git commit -m "feat(auto-dialer): show name, batch, metadata in destination list"
```

---

### Task 16: Update ProcessLargeListJob (if it exists)

**Files:**
- Modify: `app/Jobs/ProcessLargeListJob.php` (if present)

- [ ] **Step 1: Add mapping parameter to constructor and dispatch**

If the file exists, mirror the mapping changes from `ProcessListUploadJob`. If it does not exist, mark this task as N/A.

- [ ] **Step 2: Commit or skip**

```bash
git add app/Jobs/ProcessLargeListJob.php || true
git commit -m "feat(auto-dialer): ProcessLargeListJob mapping support" || echo "skipped"
```

---

### Task 17: Backend Feature Tests

**Files:**
- Create: `tests/Feature/DistributionListCsvMappingTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AutoDialerList;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DistributionListCsvMappingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private AutoDialerList $list;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => 'owner',
        ]);
        $this->list = AutoDialerList::factory()->create([
            'organization_id' => $organization->id,
            'status' => 'draft',
        ]);

        Storage::fake('local');
    }

    public function test_csv_preview_returns_headers_and_rows(): void
    {
        $csv = "phone,full_name,batch_id,account\n+14155551212,John Doe,batch-1,ACC-123\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $this->actingAs($this->user)
            ->postJson("/api/v1/auto-dialer-campaigns/lists/{$this->list->id}/preview-csv", [
                'file' => $file,
                'has_header' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.headers', ['phone', 'full_name', 'batch_id', 'account'])
            ->assertJsonPath('data.total_rows', 1);
    }

    public function test_upload_with_mapping_creates_destinations(): void
    {
        $csv = "phone,full_name,batch_id,account\n+14155551212,John Doe,batch-1,ACC-123\n+14155551213,Jane Smith,batch-2,ACC-456\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/auto-dialer-campaigns/lists/{$this->list->id}/upload", [
                'file' => $file,
                'mapping' => [
                    'phone' => 'phone',
                    'name' => 'full_name',
                    'batch_identifier' => 'batch_id',
                    'metadata' => ['account'],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.action', 'upload');

        $this->assertDatabaseHas('auto_dialer_destinations', [
            'list_id' => $this->list->id,
            'phone_number' => '+14155551212',
            'name' => 'John Doe',
            'batch_identifier' => 'batch-1',
            'metadata' => json_encode(['account' => 'ACC-123']),
        ]);
    }

    public function test_upload_rejects_missing_phone_mapping(): void
    {
        $csv = "phone\n+14155551212\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $this->actingAs($this->user)
            ->postJson("/api/v1/auto-dialer-campaigns/lists/{$this->list->id}/upload", [
                'file' => $file,
                'mapping' => ['name' => 'phone'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mapping.phone']);
    }
}
```

- [ ] **Step 2: Run feature tests**

Run: `./run-tests.sh --filter=DistributionListCsvMappingTest`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/DistributionListCsvMappingTest.php
git commit -m "test(auto-dialer): feature tests for CSV mapping preview and upload"
```

---

### Task 18: Backend Unit Tests

**Files:**
- Create: `tests/Unit/ListValidationServiceMappingTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AutoDialer\ListValidationService;
use Tests\TestCase;

class ListValidationServiceMappingTest extends TestCase
{
    private ListValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ListValidationService();
    }

    public function test_validation_maps_name_batch_and_metadata(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($path, "phone,full_name,batch_id,account\n+14155551212,John,batch-a,ACC-1\n");

        $result = $this->service->validateCsvFile($path, 1, [
            'phone' => 'phone',
            'name' => 'full_name',
            'batch_identifier' => 'batch_id',
            'metadata' => ['account'],
        ]);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->validRows);
        $this->assertSame('John', $result->validRows[0]['name']);
        $this->assertSame('batch-a', $result->validRows[0]['batch_identifier']);
        $this->assertSame(['account' => 'ACC-1'], $result->validRows[0]['metadata']);

        unlink($path);
    }

    public function test_validation_fails_when_phone_column_missing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($path, "name\nJohn\n");

        $result = $this->service->validateCsvFile($path, 1, ['phone' => 'phone']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('phone_number', $result->error);

        unlink($path);
    }

    public function test_preview_parses_headers_and_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($path, "phone,name\n+14155551212,John\n+14155551213,Jane\n");

        $preview = $this->service->parseCsvPreview($path, true, 5);

        $this->assertSame(['phone', 'name'], $preview['headers']);
        $this->assertCount(2, $preview['rows']);
        $this->assertSame(2, $preview['total_rows']);

        unlink($path);
    }
}
```

- [ ] **Step 2: Run unit tests**

Run: `./run-tests.sh --filter=ListValidationServiceMappingTest`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/ListValidationServiceMappingTest.php
git commit -m "test(auto-dialer): unit tests for CSV mapping validation"
```

---

### Task 19: Full Regression

- [ ] **Step 1: Run backend test suite**

Run: `./run-tests.sh`
Expected: `Tests: 1173+, Errors: 0, Failures: 0`

- [ ] **Step 2: Lint and type-check**

Run: `vendor/bin/pint`
Run: `cd frontend && npm run lint && npm run type-check`
Expected: clean

- [ ] **Step 3: Commit any fixes**

```bash
git add -A
git commit -m "style(auto-dialer): lint and type-check fixes for CSV mapping"
```

---

### Task 20: Update Memory Files

**Files:**
- Modify: `/.my_agent/memory/auto-dialer-campaigns.md`
- Modify: `/.my_agent/memory/distribution-lists.md`

- [ ] **Step 1: Update backend source tables and routes**

Add `name`, `batch_identifier`, `metadata` to the destinations table documentation. Add the `/preview-csv` route and the mapping contract. Remove `description` from destination fields.

- [ ] **Step 2: Commit**

```bash
git add .my_agent/memory/auto-dialer-campaigns.md .my_agent/memory/distribution-lists.md
git commit -m "docs(memory): update auto-dialer and distribution list memory for CSV mapping"
```

---

## Spec Coverage Check

| Requirement | Task |
|-------------|------|
| Full name as a new column | Task 1, 2, 3, 5, 6, 7, 12, 14, 15 |
| Batch ID per row (multiple per file) | Task 1, 2, 5, 6, 7, 14, 15 |
| Phone validation unchanged | Task 5 (keeps `validatePhoneNumber`) |
| Description column removed | Task 1, 2, 7, 8, 12, 14, 15 |
| Arbitrary CSV mapping | Task 5, 8, 12, 13, 14 |
| Preview before upload | Task 5, 8, 10, 12, 13, 14 |
| Tests | Task 17, 18, 19 |

## Placeholder Scan

- No "TBD", "TODO", or "implement later" strings remain in the plan above.
- Each code step contains the actual code or exact file path.
