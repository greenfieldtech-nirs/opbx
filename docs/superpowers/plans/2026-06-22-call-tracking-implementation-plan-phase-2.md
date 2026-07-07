# Call Tracking Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish Call Tracking v1 by adding notification testing/delivery logs, ad-platform integration settings + toggles, queued stub uploads, and the React admin UI.

**Architecture:** Extend the existing Phase 1/2 Laravel backend with three small backend APIs and two queue jobs, then build the React frontend using existing OpBX patterns (TanStack Query, shadcn/ui, react-hook-form + Zod).

**Tech Stack:** Laravel 12 (PHP 8.4), MySQL, Redis, React 18 + Vite + TypeScript + TanStack Query + shadcn/ui + react-hook-form + Zod + recharts.

---

## File Structure

### Backend
- `database/migrations/2026_06_22_000007_create_call_tracking_ad_platform_integrations_table.php`
- `database/migrations/2026_06_22_000008_add_ad_platform_columns_to_call_tracking_campaigns_table.php`
- `database/factories/CallTrackingAdPlatformIntegrationFactory.php`
- `app/Models/CallTrackingAdPlatformIntegration.php`
- `app/Models/CallTrackingCampaign.php` (modify)
- `app/Policies/CallTrackingAdPlatformIntegrationPolicy.php`
- `app/Http/Requests/CallTracking/TestNotificationSettingsRequest.php`
- `app/Http/Requests/CallTracking/NotificationLogIndexRequest.php`
- `app/Http/Requests/CallTracking/StoreAdPlatformIntegrationRequest.php`
- `app/Http/Resources/CallTrackingNotificationLogResource.php`
- `app/Http/Resources/CallTrackingAdPlatformIntegrationResource.php`
- `app/Http/Resources/CallTrackingCampaignResource.php` (modify)
- `app/Http/Controllers/Api/CallTrackingAdPlatformIntegrationController.php`
- `app/Http/Controllers/Api/CallTrackingNotificationSettingsController.php` (modify)
- `app/Http/Requests/CallTracking/StoreCampaignRequest.php` (modify)
- `app/Http/Requests/CallTracking/UpdateCampaignRequest.php` (modify)
- `app/Services/CallTracking/CallTrackingAdPlatformDispatcher.php`
- `app/Jobs/CallTracking/UploadGoogleAdsConversionJob.php`
- `app/Jobs/CallTracking/SendMetaConversionEventJob.php`
- `app/Jobs/ProcessCDRJob.php` (modify)
- `routes/api.php` (modify)
- `tests/Feature/Api/CallTrackingNotificationSettingsControllerTest.php` (modify)
- `tests/Feature/Api/CallTrackingAdPlatformIntegrationControllerTest.php`
- `tests/Feature/CallTracking/AdPlatformDispatchTest.php`

### Frontend
- `frontend/package.json` (modify — add recharts)
- `frontend/src/types/callTracking.ts`
- `frontend/src/services/callTrackingCampaignsApi.ts`
- `frontend/src/services/callTrackingNumbersApi.ts`
- `frontend/src/services/callTrackingSessionsApi.ts`
- `frontend/src/services/callTrackingAnalyticsApi.ts`
- `frontend/src/services/callTrackingNotificationSettingsApi.ts`
- `frontend/src/services/callTrackingIntegrationsApi.ts`
- `frontend/src/hooks/useCallTrackingCampaigns.ts`
- `frontend/src/hooks/useCallTrackingNumbers.ts`
- `frontend/src/hooks/useCallTrackingSessions.ts`
- `frontend/src/hooks/useCallTrackingAnalytics.ts`
- `frontend/src/hooks/useCallTrackingNotificationSettings.ts`
- `frontend/src/hooks/useCallTrackingIntegrations.ts`
- `frontend/src/components/Layout/Sidebar.tsx` (modify)
- `frontend/src/router.tsx` (modify)
- `frontend/src/pages/CallTrackingDashboard.tsx`
- `frontend/src/pages/CallTrackingCampaigns.tsx`
- `frontend/src/pages/CallTrackingCampaignForm.tsx`
- `frontend/src/pages/CallTrackingCampaignDetail.tsx`
- `frontend/src/pages/CallTrackingSessions.tsx`
- `frontend/src/pages/CallTrackingDniSnippet.tsx`
- `frontend/src/pages/CallTrackingIntegrations.tsx`
- `frontend/src/components/call-tracking/KpiCards.tsx`
- `frontend/src/components/call-tracking/CallsChart.tsx`
- `frontend/src/components/call-tracking/TopTable.tsx`
- `frontend/src/components/call-tracking/ConversionRuleFields.tsx`
- `frontend/src/components/call-tracking/NotificationAuthFields.tsx`
- `frontend/src/components/call-tracking/DniCodeBlock.tsx`

---

## Backend Tasks

### Task 1: Create ad-platform integrations table + campaign toggle columns

**Files:**
- Create: `database/migrations/2026_06_22_000007_create_call_tracking_ad_platform_integrations_table.php`
- Create: `database/migrations/2026_06_22_000008_add_ad_platform_columns_to_call_tracking_campaigns_table.php`

- [ ] **Step 1: Write the organization-level migration**

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
        Schema::create('call_tracking_ad_platform_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();

            $table->boolean('google_ads_enabled')->default(false);
            $table->text('google_ads_developer_token')->nullable();
            $table->text('google_ads_refresh_token')->nullable();
            $table->string('google_ads_customer_id', 255)->nullable();
            $table->string('google_ads_conversion_action_resource_name', 1024)->nullable();

            $table->boolean('meta_enabled')->default(false);
            $table->string('meta_pixel_id', 255)->nullable();
            $table->text('meta_access_token')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'google_ads_enabled']);
            $table->index(['organization_id', 'meta_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_tracking_ad_platform_integrations');
    }
};
```

- [ ] **Step 2: Write the campaign toggles migration**

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
        Schema::table('call_tracking_campaigns', function (Blueprint $table) {
            $table->boolean('google_ads_upload_enabled')->default(false)->after('conversion_rule');
            $table->boolean('meta_upload_enabled')->default(false)->after('google_ads_upload_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('call_tracking_campaigns', function (Blueprint $table) {
            $table->dropColumn(['google_ads_upload_enabled', 'meta_upload_enabled']);
        });
    }
};
```

- [ ] **Step 3: Run migrations**

```bash
docker compose exec app php artisan migrate
```

Expected: both migrations succeed.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_06_22_000007_create_call_tracking_ad_platform_integrations_table.php database/migrations/2026_06_22_000008_add_ad_platform_columns_to_call_tracking_campaigns_table.php
git commit -m "feat(call-tracking): add ad-platform integrations table and campaign toggles"
```

---

### Task 2: Add `CallTrackingAdPlatformIntegration` model + update campaign model

**Files:**
- Create: `app/Models/CallTrackingAdPlatformIntegration.php`
- Modify: `app/Models/CallTrackingCampaign.php`

- [ ] **Step 1: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class CallTrackingAdPlatformIntegration extends Model
{
    use HasFactory;

    protected $table = 'call_tracking_ad_platform_integrations';

    protected $fillable = [
        'organization_id',
        'google_ads_enabled',
        'google_ads_developer_token',
        'google_ads_refresh_token',
        'google_ads_customer_id',
        'google_ads_conversion_action_resource_name',
        'meta_enabled',
        'meta_pixel_id',
        'meta_access_token',
    ];

    protected function casts(): array
    {
        return [
            'google_ads_enabled' => 'boolean',
            'meta_enabled' => 'boolean',
            'google_ads_developer_token' => 'encrypted',
            'google_ads_refresh_token' => 'encrypted',
            'google_ads_customer_id' => 'encrypted',
            'google_ads_conversion_action_resource_name' => 'encrypted',
            'meta_pixel_id' => 'encrypted',
            'meta_access_token' => 'encrypted',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
```

- [ ] **Step 2: Update campaign fillable + casts**

Add to `app/Models/CallTrackingCampaign.php`:

```php
protected $fillable = [
    'organization_id',
    'name',
    'source',
    'medium',
    'description',
    'status',
    'destination_type',
    'destination_config',
    'conversion_rule',
    'google_ads_upload_enabled',
    'meta_upload_enabled',
];

protected function casts(): array
{
    return [
        'status' => CallTrackingCampaignStatus::class,
        'destination_type' => CallTrackingDestinationType::class,
        'destination_config' => 'array',
        'conversion_rule' => 'array',
        'google_ads_upload_enabled' => 'boolean',
        'meta_upload_enabled' => 'boolean',
    ];
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/CallTrackingAdPlatformIntegration.php app/Models/CallTrackingCampaign.php
git commit -m "feat(call-tracking): add ad-platform integration model and campaign toggle casts"
```

---

### Task 3: Create factory for `CallTrackingAdPlatformIntegration`

**Files:**
- Create: `database/factories/CallTrackingAdPlatformIntegrationFactory.php`

- [ ] **Step 1: Write the factory**

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CallTrackingAdPlatformIntegration;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class CallTrackingAdPlatformIntegrationFactory extends Factory
{
    protected $model = CallTrackingAdPlatformIntegration::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'google_ads_enabled' => false,
            'google_ads_developer_token' => null,
            'google_ads_refresh_token' => null,
            'google_ads_customer_id' => null,
            'google_ads_conversion_action_resource_name' => null,
            'meta_enabled' => false,
            'meta_pixel_id' => null,
            'meta_access_token' => null,
        ];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add database/factories/CallTrackingAdPlatformIntegrationFactory.php
git commit -m "feat(call-tracking): add ad-platform integration factory"
```

---

### Task 4: Create form requests

**Files:**
- Create: `app/Http/Requests/CallTracking/TestNotificationSettingsRequest.php`
- Create: `app/Http/Requests/CallTracking/NotificationLogIndexRequest.php`
- Create: `app/Http/Requests/CallTracking/StoreAdPlatformIntegrationRequest.php`

- [ ] **Step 1: Write test request**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\CallTracking;

use App\Enums\CallTrackingEventType;
use App\Models\CallTrackingCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TestNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $campaign = $this->route('call_tracking_campaign');

        return $campaign instanceof CallTrackingCampaign
            && ($this->user()?->can('update', $campaign) ?? false);
    }

    public function rules(): array
    {
        return [
            'event_type' => [
                'nullable',
                'string',
                Rule::in(CallTrackingEventType::values()),
            ],
        ];
    }
}
```

- [ ] **Step 2: Write log index request**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\CallTracking;

use App\Enums\CallTrackingEventType;
use App\Models\CallTrackingCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationLogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $campaign = $this->route('call_tracking_campaign');

        return $campaign instanceof CallTrackingCampaign
            && ($this->user()?->can('view', $campaign) ?? false);
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'event_type' => ['nullable', 'string', Rule::in(CallTrackingEventType::values())],
            'success' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
```

- [ ] **Step 3: Write ad-platform integration request**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\CallTracking;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdPlatformIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() || $this->user()?->isPBXAdmin();
    }

    public function rules(): array
    {
        return [
            'google_ads_enabled' => ['boolean'],
            'google_ads_developer_token' => ['nullable', 'required_if:google_ads_enabled,true', 'string', 'max:1024'],
            'google_ads_refresh_token' => ['nullable', 'required_if:google_ads_enabled,true', 'string', 'max:8192'],
            'google_ads_customer_id' => ['nullable', 'required_if:google_ads_enabled,true', 'string', 'max:255'],
            'google_ads_conversion_action_resource_name' => ['nullable', 'required_if:google_ads_enabled,true', 'string', 'max:1024'],

            'meta_enabled' => ['boolean'],
            'meta_pixel_id' => ['nullable', 'required_if:meta_enabled,true', 'string', 'max:255'],
            'meta_access_token' => ['nullable', 'required_if:meta_enabled,true', 'string', 'max:8192'],
        ];
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add app/Http/Requests/CallTracking/TestNotificationSettingsRequest.php app/Http/Requests/CallTracking/NotificationLogIndexRequest.php app/Http/Requests/CallTracking/StoreAdPlatformIntegrationRequest.php
git commit -m "feat(call-tracking): add phase-2 form requests"
```

---

### Task 5: Create API resources

**Files:**
- Create: `app/Http/Resources/CallTrackingNotificationLogResource.php`
- Create: `app/Http/Resources/CallTrackingAdPlatformIntegrationResource.php`

- [ ] **Step 1: Write notification log resource**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CallTrackingNotificationLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CallTrackingNotificationLog */
class CallTrackingNotificationLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'call_id' => $this->call_id,
            'event_id' => $this->event_id,
            'event_type' => $this->event_type,
            'webhook_url' => $this->webhook_url,
            'response_status_code' => $this->response_status_code,
            'response_time_ms' => $this->response_time_ms,
            'is_success' => $this->is_success,
            'attempt_number' => $this->attempt_number,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

- [ ] **Step 2: Write ad-platform integration resource**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CallTrackingAdPlatformIntegration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CallTrackingAdPlatformIntegration */
class CallTrackingAdPlatformIntegrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'organization_id' => $this->organization_id,
            'google_ads' => [
                'enabled' => $this->google_ads_enabled,
                'is_configured' => ! empty($this->google_ads_customer_id) && ! empty($this->google_ads_developer_token),
            ],
            'meta' => [
                'enabled' => $this->meta_enabled,
                'is_configured' => ! empty($this->meta_pixel_id) && ! empty($this->meta_access_token),
            ],
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Resources/CallTrackingNotificationLogResource.php app/Http/Resources/CallTrackingAdPlatformIntegrationResource.php
git commit -m "feat(call-tracking): add notification log and ad-platform integration resources"
```

---

### Task 6: Update campaign request/resource for ad-platform toggles

**Files:**
- Modify: `app/Http/Requests/CallTracking/StoreCampaignRequest.php`
- Modify: `app/Http/Requests/CallTracking/UpdateCampaignRequest.php`
- Modify: `app/Http/Resources/CallTrackingCampaignResource.php`

- [ ] **Step 1: Add toggle rules to store request**

Add to `rules()` in `StoreCampaignRequest.php`:

```php
'google_ads_upload_enabled' => ['nullable', 'boolean'],
'meta_upload_enabled' => ['nullable', 'boolean'],
```

- [ ] **Step 2: Add toggle rules to update request**

Add to `rules()` in `UpdateCampaignRequest.php`:

```php
'google_ads_upload_enabled' => ['sometimes', 'nullable', 'boolean'],
'meta_upload_enabled' => ['sometimes', 'nullable', 'boolean'],
```

- [ ] **Step 3: Add toggles to campaign resource**

Add to `toArray()` in `CallTrackingCampaignResource.php`:

```php
'google_ads_upload_enabled' => $this->google_ads_upload_enabled,
'meta_upload_enabled' => $this->meta_upload_enabled,
```

- [ ] **Step 4: Commit**

```bash
git add app/Http/Requests/CallTracking/StoreCampaignRequest.php app/Http/Requests/CallTracking/UpdateCampaignRequest.php app/Http/Resources/CallTrackingCampaignResource.php
git commit -m "feat(call-tracking): expose ad-platform upload toggles on campaigns"
```

---

### Task 7: Create ad-platform integration controller + policy

**Files:**
- Create: `app/Http/Controllers/Api/CallTrackingAdPlatformIntegrationController.php`
- Create: `app/Policies/CallTrackingAdPlatformIntegrationPolicy.php`

- [ ] **Step 1: Write the policy**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class CallTrackingAdPlatformIntegrationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->organization_id === $organization->id
            && ($user->isOwner() || $user->isPBXAdmin());
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->organization_id === $organization->id
            && ($user->isOwner() || $user->isPBXAdmin());
    }
}
```

Register in `app/Providers/AuthServiceProvider.php`:

```php
CallTrackingAdPlatformIntegration::class => CallTrackingAdPlatformIntegrationPolicy::class,
```

- [ ] **Step 2: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CallTracking\StoreAdPlatformIntegrationRequest;
use App\Http\Resources\CallTrackingAdPlatformIntegrationResource;
use App\Models\CallTrackingAdPlatformIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CallTrackingAdPlatformIntegrationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $integration = CallTrackingAdPlatformIntegration::forOrganization((int) $user->organization_id)->first();

        if (! $integration) {
            return response()->json([
                'data' => [
                    'organization_id' => (int) $user->organization_id,
                    'google_ads' => ['enabled' => false, 'is_configured' => false],
                    'meta' => ['enabled' => false, 'is_configured' => false],
                    'updated_at' => null,
                ],
            ]);
        }

        return response()->json([
            'data' => new CallTrackingAdPlatformIntegrationResource($integration),
        ]);
    }

    public function update(StoreAdPlatformIntegrationRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $payload = [
            'organization_id' => (int) $user->organization_id,
            'google_ads_enabled' => $validated['google_ads_enabled'] ?? false,
            'meta_enabled' => $validated['meta_enabled'] ?? false,
        ];

        foreach (
            [
                'google_ads_developer_token',
                'google_ads_refresh_token',
                'google_ads_customer_id',
                'google_ads_conversion_action_resource_name',
            ] as $field
        ) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                $payload[$field] = $validated[$field];
            }
        }

        foreach (['meta_pixel_id', 'meta_access_token'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                $payload[$field] = $validated[$field];
            }
        }

        $integration = CallTrackingAdPlatformIntegration::updateOrCreate(
            ['organization_id' => (int) $user->organization_id],
            $payload
        );

        Log::info('Call tracking ad-platform integration settings updated', [
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
        ]);

        return response()->json([
            'message' => 'Integration settings updated successfully.',
            'data' => new CallTrackingAdPlatformIntegrationResource($integration),
        ]);
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/CallTrackingAdPlatformIntegrationController.php app/Policies/CallTrackingAdPlatformIntegrationPolicy.php
git commit -m "feat(call-tracking): add ad-platform integration controller and policy"
```

---

### Task 8: Extend notification settings controller with test + logs

**Files:**
- Modify: `app/Http/Controllers/Api/CallTrackingNotificationSettingsController.php`

- [ ] **Step 1: Add imports and test method**

Add imports:

```php
use App\Enums\CallTrackingEventType;
use App\Http\Requests\CallTracking\NotificationLogIndexRequest;
use App\Http\Requests\CallTracking\TestNotificationSettingsRequest;
use App\Http\Resources\CallTrackingNotificationLogResource;
use App\Models\CallTrackingNotificationLog;
use App\Models\CallTrackingSession;
use App\Services\CallTracking\CallTrackingWebhookDispatcher;
use Illuminate\Support\Str;
```

Add `test()` method after `update()`:

```php
public function test(
    TestNotificationSettingsRequest $request,
    CallTrackingCampaign $callTrackingCampaign,
    CallTrackingWebhookDispatcher $dispatcher
): JsonResponse {
    $settings = CallTrackingNotificationSettings::forCampaign($callTrackingCampaign->id)->first();

    if (! $settings || ! $settings->isConfigured()) {
        return response()->json([
            'error' => 'Unprocessable Content',
            'message' => 'Notification settings are missing, inactive, or have no webhook URL configured.',
        ], 422);
    }

    $eventType = $request->input('event_type', CallTrackingEventType::CALL_RECEIVED->value);
    $eventId = 'ct_test_'.Str::uuid();

    $session = new CallTrackingSession([
        'organization_id' => $callTrackingCampaign->organization_id,
        'call_tracking_campaign_id' => $callTrackingCampaign->id,
        'call_tracking_number_id' => null,
        'did_number_id' => null,
        'call_id' => 'test_call_'.Str::random(8),
        'session_id' => null,
        'caller_number' => '+15550000000',
        'called_number' => '+15551111111',
        'source' => $callTrackingCampaign->source,
        'medium' => $callTrackingCampaign->medium,
        'campaign_name' => $callTrackingCampaign->name,
        'disposition' => 'ANSWERED',
        'duration' => 72,
        'billsec' => 70,
        'is_answered' => true,
        'is_converted' => true,
        'conversion_value' => null,
    ]);

    $log = $dispatcher->dispatch($settings, $session, $eventType, $eventId);

    return response()->json([
        'data' => new CallTrackingNotificationLogResource($log),
    ]);
}
```

- [ ] **Step 2: Add logs method**

Add after `test()`:

```php
public function logs(
    NotificationLogIndexRequest $request,
    CallTrackingCampaign $callTrackingCampaign
): JsonResponse {
    $validated = $request->validated();

    $query = CallTrackingNotificationLog::query()
        ->where('organization_id', $callTrackingCampaign->organization_id)
        ->where('call_tracking_campaign_id', $callTrackingCampaign->id);

    if (! empty($validated['event_type'])) {
        $query->where('event_type', $validated['event_type']);
    }

    if (isset($validated['success'])) {
        $query->where('is_success', (bool) $validated['success']);
    }

    if (! empty($validated['from'])) {
        $query->whereDate('created_at', '>=', $validated['from']);
    }

    if (! empty($validated['to'])) {
        $query->whereDate('created_at', '<=', $validated['to']);
    }

    $logs = $query->orderByDesc('created_at')
        ->paginate($validated['per_page'] ?? 20);

    return response()->json([
        'data' => CallTrackingNotificationLogResource::collection($logs->items()),
        'meta' => [
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'per_page' => $logs->perPage(),
            'total' => $logs->total(),
        ],
    ]);
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/CallTrackingNotificationSettingsController.php
git commit -m "feat(call-tracking): add notification test endpoint and delivery logs API"
```

---

### Task 9: Create ad-platform dispatcher service + jobs

**Files:**
- Create: `app/Services/CallTracking/CallTrackingAdPlatformDispatcher.php`
- Create: `app/Jobs/CallTracking/UploadGoogleAdsConversionJob.php`
- Create: `app/Jobs/CallTracking/SendMetaConversionEventJob.php`

- [ ] **Step 1: Write dispatcher service**

```php
<?php

declare(strict_types=1);

namespace App\Services\CallTracking;

use App\Jobs\CallTracking\SendMetaConversionEventJob;
use App\Jobs\CallTracking\UploadGoogleAdsConversionJob;
use App\Models\CallTrackingAdPlatformIntegration;
use App\Models\CallTrackingSession;
use App\Scopes\OrganizationScope;

class CallTrackingAdPlatformDispatcher
{
    public function dispatch(CallTrackingSession $session): void
    {
        if (! $session->is_converted) {
            return;
        }

        $campaign = $session->campaign;

        if (! $campaign) {
            return;
        }

        $integration = OrganizationScope::bypass(
            fn () => CallTrackingAdPlatformIntegration::forOrganization($session->organization_id)->first()
        );

        if (! $integration) {
            return;
        }

        if ($campaign->google_ads_upload_enabled && $integration->google_ads_enabled) {
            UploadGoogleAdsConversionJob::dispatch($session->id);
        }

        if ($campaign->meta_upload_enabled && $integration->meta_enabled) {
            SendMetaConversionEventJob::dispatch($session->id);
        }
    }
}
```

- [ ] **Step 2: Write Google Ads upload job**

```php
<?php

declare(strict_types=1);

namespace App\Jobs\CallTracking;

use App\Models\CallTrackingAdPlatformIntegration;
use App\Models\CallTrackingNotificationLog;
use App\Models\CallTrackingSession;
use App\Scopes\OrganizationScope;
use App\Services\CallTracking\Adapters\GoogleAdsConversionUploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class UploadGoogleAdsConversionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $sessionId) {}

    public function handle(GoogleAdsConversionUploadService $service): void
    {
        $session = OrganizationScope::bypass(fn () => CallTrackingSession::find($this->sessionId));

        if (! $session || ! $session->is_converted) {
            return;
        }

        $integration = OrganizationScope::bypass(
            fn () => CallTrackingAdPlatformIntegration::forOrganization($session->organization_id)->first()
        );

        if (! $integration || ! $integration->google_ads_enabled) {
            return;
        }

        $config = [
            'developer_token' => $integration->google_ads_developer_token,
            'refresh_token' => $integration->google_ads_refresh_token,
            'customer_id' => $integration->google_ads_customer_id,
            'conversion_action_resource_name' => $integration->google_ads_conversion_action_resource_name,
        ];

        try {
            $result = $service->uploadCallConversion($session, $config);

            CallTrackingNotificationLog::create([
                'organization_id' => $session->organization_id,
                'call_tracking_campaign_id' => $session->call_tracking_campaign_id,
                'call_id' => $session->call_id,
                'event_id' => 'ct_ad_google_'.uniqid(),
                'event_type' => 'ad_platform.google_ads',
                'webhook_url' => 'google-ads-api',
                'request_payload' => $config,
                'request_headers' => [],
                'response_body' => json_encode($result),
                'response_headers' => [],
                'response_status_code' => 200,
                'response_time_ms' => 0,
                'is_success' => true,
                'attempt_number' => 1,
                'error_message' => null,
            ]);

            Log::info('Call tracking Google Ads upload queued', [
                'session_id' => $session->id,
                'campaign_id' => $session->call_tracking_campaign_id,
            ]);
        } catch (Throwable $e) {
            CallTrackingNotificationLog::create([
                'organization_id' => $session->organization_id,
                'call_tracking_campaign_id' => $session->call_tracking_campaign_id,
                'call_id' => $session->call_id,
                'event_id' => 'ct_ad_google_'.uniqid(),
                'event_type' => 'ad_platform.google_ads',
                'webhook_url' => 'google-ads-api',
                'request_payload' => $config,
                'request_headers' => [],
                'response_body' => null,
                'response_headers' => [],
                'response_status_code' => null,
                'response_time_ms' => null,
                'is_success' => false,
                'attempt_number' => 1,
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Call tracking Google Ads upload failed', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

- [ ] **Step 3: Write Meta conversion job**

Mirror `UploadGoogleAdsConversionJob.php` in `app/Jobs/CallTracking/SendMetaConversionEventJob.php`, replacing:
- Service: `MetaConversionsApiService`
- Method: `sendOfflineEvent`
- Config keys: `pixel_id`, `access_token`
- Event type: `ad_platform.meta`
- Log message text: `Meta`

- [ ] **Step 4: Commit**

```bash
git add app/Services/CallTracking/CallTrackingAdPlatformDispatcher.php app/Jobs/CallTracking/UploadGoogleAdsConversionJob.php app/Jobs/CallTracking/SendMetaConversionEventJob.php
git commit -m "feat(call-tracking): add ad-platform dispatcher and queued upload jobs"
```

---

### Task 10: Wire ad-platform dispatcher into `ProcessCDRJob`

**Files:**
- Modify: `app/Jobs/ProcessCDRJob.php`

- [ ] **Step 1: Inject dispatcher and dispatch after session creation**

Add import:

```php
use App\Services\CallTracking\CallTrackingAdPlatformDispatcher;
```

Change `handle()` signature:

```php
public function handle(
    CallTrackingSessionService $callTrackingSessionService,
    CallTrackingEventDispatcher $eventDispatcher,
    CallTrackingAdPlatformDispatcher $adPlatformDispatcher,
): void {
```

After the webhook dispatch block, add:

```php
if ($session && $session->is_converted) {
    $adPlatformDispatcher->dispatch($session);
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Jobs/ProcessCDRJob.php
git commit -m "feat(call-tracking): dispatch ad-platform upload jobs on converted sessions"
```

---

### Task 11: Register new API routes

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 1: Add routes inside the protected v1 group**

After the existing call-tracking routes (around line 299), add:

```php
// Call Tracking Notification Settings test + delivery logs
Route::post('call-tracking-campaigns/{callTrackingCampaign}/notification-settings/test', [
    CallTrackingNotificationSettingsController::class, 'test',
])->middleware(['throttle:5,1'])
  ->name('call-tracking-campaigns.notification-settings.test');

Route::get('call-tracking-campaigns/{callTrackingCampaign}/notification-logs', [
    CallTrackingNotificationSettingsController::class, 'logs',
])->name('call-tracking-campaigns.notification-logs.index');

// Call Tracking Ad-Platform Integrations (organization singleton)
Route::get('call-tracking-ad-platform-integrations', [
    CallTrackingAdPlatformIntegrationController::class, 'show',
])->name('call-tracking-ad-platform-integrations.show');

Route::put('call-tracking-ad-platform-integrations', [
    CallTrackingAdPlatformIntegrationController::class, 'update',
])->name('call-tracking-ad-platform-integrations.update');
```

- [ ] **Step 2: Commit**

```bash
git add routes/api.php
git commit -m "feat(call-tracking): register phase-2 routes"
```

---

### Task 12: Backend tests

**Files:**
- Modify: `tests/Feature/Api/CallTrackingNotificationSettingsControllerTest.php`
- Create: `tests/Feature/Api/CallTrackingAdPlatformIntegrationControllerTest.php`
- Create: `tests/Feature/CallTracking/AdPlatformDispatchTest.php`

- [ ] **Step 1: Append notification test/log tests**

Add to the end of `CallTrackingNotificationSettingsControllerTest.php` before the closing brace:

```php
public function test_owner_can_send_test_notification(): void
{
    Sanctum::actingAs($this->owner);

    $campaign = CallTrackingCampaign::factory()->create([
        'organization_id' => $this->organization->id,
    ]);
    $this->createSettings($campaign);

    $response = $this->postJson(
        '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings/test'
    );

    $response->assertStatus(200)
        ->assertJsonPath('data.event_type', 'call.received');

    $this->assertDatabaseHas('call_tracking_notification_logs', [
        'call_tracking_campaign_id' => $campaign->id,
        'event_type' => 'call.received',
    ]);
}

public function test_test_notification_returns_422_when_settings_not_configured(): void
{
    Sanctum::actingAs($this->owner);

    $campaign = CallTrackingCampaign::factory()->create([
        'organization_id' => $this->organization->id,
    ]);

    $response = $this->postJson(
        '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings/test'
    );

    $response->assertStatus(422);
}

public function test_agent_cannot_send_test_notification(): void
{
    Sanctum::actingAs($this->agent);

    $campaign = CallTrackingCampaign::factory()->create([
        'organization_id' => $this->organization->id,
    ]);
    $this->createSettings($campaign);

    $response = $this->postJson(
        '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings/test'
    );

    $response->assertStatus(403);
}

public function test_owner_can_list_notification_logs(): void
{
    Sanctum::actingAs($this->owner);

    $campaign = CallTrackingCampaign::factory()->create([
        'organization_id' => $this->organization->id,
    ]);

    CallTrackingNotificationLog::factory()->create([
        'organization_id' => $this->organization->id,
        'call_tracking_campaign_id' => $campaign->id,
        'event_type' => 'call.converted',
        'is_success' => true,
    ]);

    $response = $this->getJson(
        '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-logs'
    );

    $response->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.event_type', 'call.converted');
}
```

Add import `App\Models\CallTrackingNotificationLog` if missing.

- [ ] **Step 2: Write ad-platform integration controller test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CallTrackingAdPlatformIntegration;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CallTrackingAdPlatformIntegrationControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Organization $otherOrganization;
    private User $owner;
    private User $admin;
    private User $agent;
    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);
        $this->admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_ADMIN,
        ]);
        $this->agent = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);
        $this->otherOwner = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'role' => UserRole::OWNER,
        ]);
    }

    public function test_owner_can_view_empty_integration_settings(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/v1/call-tracking-ad-platform-integrations');

        $response->assertStatus(200)
            ->assertJsonPath('data.google_ads.enabled', false)
            ->assertJsonPath('data.meta.enabled', false);
    }

    public function test_owner_can_update_integration_settings(): void
    {
        Sanctum::actingAs($this->owner);

        $payload = [
            'google_ads_enabled' => true,
            'google_ads_developer_token' => 'dev-token',
            'google_ads_refresh_token' => 'refresh-token',
            'google_ads_customer_id' => '123-456-7890',
            'google_ads_conversion_action_resource_name' => 'customers/123/conversionActions/456',
            'meta_enabled' => false,
        ];

        $response = $this->putJson('/api/v1/call-tracking-ad-platform-integrations', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.google_ads.enabled', true)
            ->assertJsonPath('data.google_ads.is_configured', true)
            ->assertJsonMissingPath('data.google_ads.developer_token');

        $this->assertDatabaseHas('call_tracking_ad_platform_integrations', [
            'organization_id' => $this->organization->id,
            'google_ads_enabled' => true,
        ]);
    }

    public function test_update_validates_missing_google_credentials_when_enabled(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->putJson('/api/v1/call-tracking-ad-platform-integrations', [
            'google_ads_enabled' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['google_ads_developer_token']);
    }

    public function test_agent_cannot_update_integrations(): void
    {
        Sanctum::actingAs($this->agent);

        $response = $this->putJson('/api/v1/call-tracking-ad-platform-integrations', [
            'google_ads_enabled' => false,
            'meta_enabled' => false,
        ]);

        $response->assertStatus(403);
    }

    public function test_update_preserves_existing_secrets_when_not_sent(): void
    {
        Sanctum::actingAs($this->owner);

        CallTrackingAdPlatformIntegration::factory()->create([
            'organization_id' => $this->organization->id,
            'google_ads_enabled' => true,
            'google_ads_developer_token' => 'existing-token',
            'google_ads_refresh_token' => 'existing-refresh',
            'google_ads_customer_id' => '123',
            'google_ads_conversion_action_resource_name' => 'customers/123/actions/1',
            'meta_enabled' => false,
        ]);

        $response = $this->putJson('/api/v1/call-tracking-ad-platform-integrations', [
            'google_ads_enabled' => true,
            'meta_enabled' => false,
        ]);

        $response->assertStatus(200);

        $integration = CallTrackingAdPlatformIntegration::forOrganization($this->organization->id)->first();
        $this->assertSame('existing-token', $integration->google_ads_developer_token);
    }
}
```

- [ ] **Step 3: Write dispatch feature test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\CallTracking;

use App\Jobs\CallTracking\SendMetaConversionEventJob;
use App\Jobs\CallTracking\UploadGoogleAdsConversionJob;
use App\Models\CallTrackingAdPlatformIntegration;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\CallTrackingSession;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdPlatformDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_converted_session_dispatches_upload_jobs_when_toggles_enabled(): void
    {
        Queue::fake();

        $organization = Organization::factory()->create();
        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'google_ads_upload_enabled' => true,
            'meta_upload_enabled' => true,
        ]);
        CallTrackingAdPlatformIntegration::factory()->create([
            'organization_id' => $organization->id,
            'google_ads_enabled' => true,
            'meta_enabled' => true,
        ]);
        $number = CallTrackingNumber::factory()->forCampaign($campaign)->create();
        $session = CallTrackingSession::factory()->forCampaignAndNumber($campaign, $number)->create([
            'is_converted' => true,
        ]);

        $dispatcher = app(\App\Services\CallTracking\CallTrackingAdPlatformDispatcher::class);
        $dispatcher->dispatch($session);

        Queue::assertPushed(UploadGoogleAdsConversionJob::class);
        Queue::assertPushed(SendMetaConversionEventJob::class);
    }

    public function test_non_converted_session_does_not_dispatch_jobs(): void
    {
        Queue::fake();

        $organization = Organization::factory()->create();
        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'google_ads_upload_enabled' => true,
            'meta_upload_enabled' => true,
        ]);
        CallTrackingAdPlatformIntegration::factory()->create([
            'organization_id' => $organization->id,
            'google_ads_enabled' => true,
            'meta_enabled' => true,
        ]);
        $number = CallTrackingNumber::factory()->forCampaign($campaign)->create();
        $session = CallTrackingSession::factory()->forCampaignAndNumber($campaign, $number)->create([
            'is_converted' => false,
        ]);

        $dispatcher = app(\App\Services\CallTracking\CallTrackingAdPlatformDispatcher::class);
        $dispatcher->dispatch($session);

        Queue::assertNothingPushed();
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./run-tests.sh --filter=CallTracking
```

Expected: all CallTracking tests pass.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Api/CallTrackingNotificationSettingsControllerTest.php tests/Feature/Api/CallTrackingAdPlatformIntegrationControllerTest.php tests/Feature/CallTracking/AdPlatformDispatchTest.php
git commit -m "test(call-tracking): add phase-2 backend tests"
```

---

## Frontend Tasks

### Task 13: Install chart dependency

**Files:**
- Modify: `frontend/package.json`

- [ ] **Step 1: Install recharts**

```bash
cd frontend
npm install recharts
```

- [ ] **Step 2: Verify build still passes**

```bash
npm run type-check
npm run lint
```

Expected: no new errors.

- [ ] **Step 3: Commit**

```bash
cd ..
git add frontend/package.json frontend/package-lock.json
git commit -m "chore(frontend): add recharts for call tracking analytics"
```

---

### Task 14: Create shared types

**Files:**
- Create: `frontend/src/types/callTracking.ts`

- [ ] **Step 1: Write types**

```ts
export type CallTrackingCampaignStatus = 'active' | 'inactive';

export interface CallTrackingCampaign {
  id: number;
  organization_id: number;
  name: string;
  source: string | null;
  medium: string | null;
  description: string | null;
  status: CallTrackingCampaignStatus;
  destination_type: string;
  destination_config: Record<string, unknown>;
  conversion_rule: {
    min_answered_duration_seconds?: number;
    requires_answered_disposition?: boolean;
    conversion_value?: number | null;
  } | null;
  google_ads_upload_enabled: boolean;
  meta_upload_enabled: boolean;
  tracking_numbers_count?: number;
  created_at: string;
  updated_at: string;
}

export interface CallTrackingNumber {
  id: number;
  organization_id: number;
  call_tracking_campaign_id: number;
  did_number_id: number;
  friendly_name: string | null;
  status: CallTrackingCampaignStatus;
  did?: {
    id: number;
    phone_number: string;
  };
  created_at: string;
  updated_at: string;
}

export interface CallTrackingSession {
  id: number;
  call_id: string;
  caller_number: string;
  called_number: string;
  campaign_name: string | null;
  source: string | null;
  medium: string | null;
  duration: number;
  billsec: number;
  disposition: string;
  is_answered: boolean;
  is_converted: boolean;
  started_at: string;
}

export interface CallTrackingNotificationSettings {
  id: number;
  call_tracking_campaign_id: number;
  webhook_url: string;
  auth_method: 'none' | 'bearer_token' | 'basic_auth';
  auth_username: string | null;
  enabled_events: string[];
  is_active: boolean;
}

export interface CallTrackingNotificationLog {
  id: number;
  event_type: string;
  webhook_url: string;
  response_status_code: number | null;
  response_time_ms: number | null;
  is_success: boolean;
  error_message: string | null;
  created_at: string;
}

export interface CallTrackingAdPlatformIntegration {
  organization_id: number;
  google_ads: {
    enabled: boolean;
    is_configured: boolean;
  };
  meta: {
    enabled: boolean;
    is_configured: boolean;
  };
  updated_at: string | null;
}

export interface CallTrackingAnalytics {
  kpis: {
    total_calls: number;
    unique_callers: number;
    answered_calls: number;
    missed_calls: number;
    avg_duration: number;
    conversions: number;
    conversion_rate: number;
  };
  time_series: Array<{
    date_key: string;
    calls: number;
    conversions: number;
  }>;
  top_campaigns: Array<{
    campaign_name: string;
    calls: number;
    conversions: number;
  }>;
  top_sources: Array<{
    source: string;
    medium: string | null;
    calls: number;
    conversions: number;
  }>;
  filters: {
    start_date: string;
    end_date: string;
    group_by: string;
    campaign_ids?: number[];
    sources?: string[];
    mediums?: string[];
  };
}
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/types/callTracking.ts
git commit -m "feat(frontend): add call tracking shared types"
```

---

### Task 15: Create API services

**Files:**
- Create all six services under `frontend/src/services/callTracking*.ts`

- [ ] **Step 1: Write `callTrackingCampaignsApi.ts`**

```ts
import api from '@/services/api';
import type { CallTrackingCampaign } from '@/types/callTracking';

export interface CampaignListParams {
  search?: string;
  status?: string;
  page?: number;
  per_page?: number;
}

export interface CampaignFormData {
  name: string;
  source?: string | null;
  medium?: string | null;
  description?: string | null;
  status: 'active' | 'inactive';
  destination_type: string;
  destination_config: Record<string, unknown>;
  conversion_rule?: {
    min_answered_duration_seconds?: number;
    requires_answered_disposition?: boolean;
    conversion_value?: number | null;
  };
  google_ads_upload_enabled?: boolean;
  meta_upload_enabled?: boolean;
}

export const callTrackingCampaignsApi = {
  getAll: (params?: CampaignListParams) =>
    api
      .get<{ data: CallTrackingCampaign[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }>(
        '/call-tracking-campaigns',
        { params }
      )
      .then((r) => r.data),

  getById: (id: string | number) =>
    api.get<{ data: CallTrackingCampaign }>(`/call-tracking-campaigns/${id}`).then((r) => r.data.data),

  create: (data: CampaignFormData) =>
    api.post<{ data: CallTrackingCampaign }>('/call-tracking-campaigns', data).then((r) => r.data.data),

  update: (id: string | number, data: Partial<CampaignFormData>) =>
    api.put<{ data: CallTrackingCampaign }>(`/call-tracking-campaigns/${id}`, data).then((r) => r.data.data),

  destroy: (id: string | number) => api.delete(`/call-tracking-campaigns/${id}`),
};
```

- [ ] **Step 2: Write the remaining services**

`frontend/src/services/callTrackingNumbersApi.ts`:

```ts
import api from '@/services/api';
import type { CallTrackingNumber } from '@/types/callTracking';

export interface NumberFormData {
  did_number_id: number;
  friendly_name?: string | null;
  status?: 'active' | 'inactive';
}

export const callTrackingNumbersApi = {
  getForCampaign: (campaignId: string | number, params?: { page?: number; per_page?: number }) =>
    api
      .get<{ data: CallTrackingNumber[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }>(
        `/call-tracking-campaigns/${campaignId}/call-tracking-numbers`,
        { params }
      )
      .then((r) => r.data),

  create: (campaignId: string | number, data: NumberFormData) =>
    api.post<{ data: CallTrackingNumber }>(`/call-tracking-campaigns/${campaignId}/call-tracking-numbers`, data).then((r) => r.data.data),

  update: (campaignId: string | number, id: string | number, data: Partial<NumberFormData>) =>
    api.put<{ data: CallTrackingNumber }>(`/call-tracking-campaigns/${campaignId}/call-tracking-numbers/${id}`, data).then((r) => r.data.data),

  destroy: (campaignId: string | number, id: string | number) =>
    api.delete(`/call-tracking-campaigns/${campaignId}/call-tracking-numbers/${id}`),
};
```

`frontend/src/services/callTrackingSessionsApi.ts`:

```ts
import api from '@/services/api';
import type { CallTrackingSession } from '@/types/callTracking';

export interface SessionListParams {
  start_date?: string;
  end_date?: string;
  campaign_ids?: number[];
  sources?: string[];
  mediums?: string[];
  disposition?: string;
  is_converted?: boolean;
  page?: number;
  per_page?: number;
}

export const callTrackingSessionsApi = {
  getAll: (params?: SessionListParams) =>
    api
      .get<{ data: CallTrackingSession[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }>(
        '/call-tracking-sessions',
        { params }
      )
      .then((r) => r.data),
};
```

`frontend/src/services/callTrackingAnalyticsApi.ts`:

```ts
import api from '@/services/api';
import type { CallTrackingAnalytics } from '@/types/callTracking';

export interface AnalyticsParams {
  start_date: string;
  end_date: string;
  group_by?: 'day' | 'week' | 'month';
  campaign_ids?: number[];
  sources?: string[];
  mediums?: string[];
}

export const callTrackingAnalyticsApi = {
  getAnalytics: (params: AnalyticsParams) =>
    api.get<{ data: CallTrackingAnalytics }>('/call-tracking-analytics', { params }).then((r) => r.data.data),

  exportCsv: (params: AnalyticsParams) =>
    api.get('/call-tracking-analytics/export', { params, responseType: 'blob' }).then((r) => r.data as Blob),
};
```

`frontend/src/services/callTrackingNotificationSettingsApi.ts`:

```ts
import api from '@/services/api';
import type {
  CallTrackingNotificationSettings,
  CallTrackingNotificationLog,
} from '@/types/callTracking';

export interface NotificationSettingsFormData {
  webhook_url: string;
  auth_method: 'none' | 'bearer_token' | 'basic_auth';
  auth_username?: string | null;
  auth_secret?: string | null;
  enabled_events: string[];
  is_active: boolean;
}

export interface NotificationLogParams {
  event_type?: string;
  success?: boolean;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
}

export const callTrackingNotificationSettingsApi = {
  get: (campaignId: string | number) =>
    api
      .get<{ data: CallTrackingNotificationSettings }>(`/call-tracking-campaigns/${campaignId}/notification-settings`)
      .then((r) => r.data.data),

  update: (campaignId: string | number, data: NotificationSettingsFormData) =>
    api
      .put<{ data: CallTrackingNotificationSettings }>(`/call-tracking-campaigns/${campaignId}/notification-settings`, data)
      .then((r) => r.data.data),

  test: (campaignId: string | number, eventType?: string) =>
    api
      .post<{ data: CallTrackingNotificationLog }>(`/call-tracking-campaigns/${campaignId}/notification-settings/test`, { event_type: eventType })
      .then((r) => r.data.data),

  getLogs: (campaignId: string | number, params?: NotificationLogParams) =>
    api
      .get<{ data: CallTrackingNotificationLog[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }>(
        `/call-tracking-campaigns/${campaignId}/notification-logs`,
        { params }
      )
      .then((r) => r.data),
};
```

`frontend/src/services/callTrackingIntegrationsApi.ts`:

```ts
import api from '@/services/api';
import type { CallTrackingAdPlatformIntegration } from '@/types/callTracking';

export interface AdPlatformIntegrationFormData {
  google_ads_enabled: boolean;
  google_ads_developer_token?: string;
  google_ads_refresh_token?: string;
  google_ads_customer_id?: string;
  google_ads_conversion_action_resource_name?: string;
  meta_enabled: boolean;
  meta_pixel_id?: string;
  meta_access_token?: string;
}

export const callTrackingIntegrationsApi = {
  get: () =>
    api.get<{ data: CallTrackingAdPlatformIntegration }>('/call-tracking-ad-platform-integrations').then((r) => r.data.data),

  update: (data: AdPlatformIntegrationFormData) =>
    api
      .put<{ data: CallTrackingAdPlatformIntegration; message: string }>('/call-tracking-ad-platform-integrations', data)
      .then((r) => r.data),
};
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/services/callTracking*.ts
git commit -m "feat(frontend): add call tracking API services"
```

---

### Task 16: Create TanStack Query hooks

**Files:**
- Create all six hooks under `frontend/src/hooks/useCallTracking*.ts`

- [ ] **Step 1: Write `useCallTrackingCampaigns.ts`**

```ts
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  callTrackingCampaignsApi,
  type CampaignFormData,
  type CampaignListParams,
} from '@/services/callTrackingCampaignsApi';

export const callTrackingCampaignKeys = {
  all: ['call-tracking-campaigns'] as const,
  lists: () => [...callTrackingCampaignKeys.all, 'list'] as const,
  list: (params: CampaignListParams) => [...callTrackingCampaignKeys.lists(), params] as const,
  detail: (id: string | number) => [...callTrackingCampaignKeys.all, 'detail', id] as const,
};

export function useCallTrackingCampaigns(params?: CampaignListParams) {
  return useQuery({
    queryKey: callTrackingCampaignKeys.list(params ?? {}),
    queryFn: () => callTrackingCampaignsApi.getAll(params),
  });
}

export function useCallTrackingCampaign(id: string | number | undefined) {
  return useQuery({
    queryKey: callTrackingCampaignKeys.detail(id ?? ''),
    queryFn: () => callTrackingCampaignsApi.getById(id!),
    enabled: !!id,
  });
}

export function useCreateCallTrackingCampaign() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: CampaignFormData) => callTrackingCampaignsApi.create(data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: callTrackingCampaignKeys.lists() }),
  });
}

export function useUpdateCallTrackingCampaign() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string | number; data: Partial<CampaignFormData> }) =>
      callTrackingCampaignsApi.update(id, data),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: callTrackingCampaignKeys.lists() });
      queryClient.invalidateQueries({ queryKey: callTrackingCampaignKeys.detail(variables.id) });
    },
  });
}

export function useDeleteCallTrackingCampaign() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string | number) => callTrackingCampaignsApi.destroy(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: callTrackingCampaignKeys.lists() }),
  });
}
```

- [ ] **Step 2: Write the remaining hooks**

`frontend/src/hooks/useCallTrackingNumbers.ts`:

```ts
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { callTrackingNumbersApi, type NumberFormData } from '@/services/callTrackingNumbersApi';

export const callTrackingNumberKeys = {
  all: ['call-tracking-numbers'] as const,
  lists: () => [...callTrackingNumberKeys.all, 'list'] as const,
  list: (campaignId: string | number) => [...callTrackingNumberKeys.lists(), campaignId] as const,
};

export function useCallTrackingNumbers(campaignId: string | number) {
  return useQuery({
    queryKey: callTrackingNumberKeys.list(campaignId),
    queryFn: () => callTrackingNumbersApi.getForCampaign(campaignId),
  });
}

export function useCreateCallTrackingNumber() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ campaignId, data }: { campaignId: string | number; data: NumberFormData }) =>
      callTrackingNumbersApi.create(campaignId, data),
    onSuccess: (_, variables) =>
      queryClient.invalidateQueries({ queryKey: callTrackingNumberKeys.list(variables.campaignId) }),
  });
}

export function useUpdateCallTrackingNumber() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      campaignId,
      id,
      data,
    }: {
      campaignId: string | number;
      id: string | number;
      data: Partial<NumberFormData>;
    }) => callTrackingNumbersApi.update(campaignId, id, data),
    onSuccess: (_, variables) =>
      queryClient.invalidateQueries({ queryKey: callTrackingNumberKeys.list(variables.campaignId) }),
  });
}

export function useDeleteCallTrackingNumber() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ campaignId, id }: { campaignId: string | number; id: string | number }) =>
      callTrackingNumbersApi.destroy(campaignId, id),
    onSuccess: (_, variables) =>
      queryClient.invalidateQueries({ queryKey: callTrackingNumberKeys.list(variables.campaignId) }),
  });
}
```

`frontend/src/hooks/useCallTrackingSessions.ts`:

```ts
import { useQuery } from '@tanstack/react-query';
import { callTrackingSessionsApi, type SessionListParams } from '@/services/callTrackingSessionsApi';

export const callTrackingSessionKeys = {
  all: ['call-tracking-sessions'] as const,
  lists: () => [...callTrackingSessionKeys.all, 'list'] as const,
  list: (params: SessionListParams) => [...callTrackingSessionKeys.lists(), params] as const,
};

export function useCallTrackingSessions(params?: SessionListParams) {
  return useQuery({
    queryKey: callTrackingSessionKeys.list(params ?? {}),
    queryFn: () => callTrackingSessionsApi.getAll(params),
  });
}
```

`frontend/src/hooks/useCallTrackingAnalytics.ts`:

```ts
import { useQuery } from '@tanstack/react-query';
import { callTrackingAnalyticsApi, type AnalyticsParams } from '@/services/callTrackingAnalyticsApi';

export const callTrackingAnalyticsKeys = {
  all: ['call-tracking-analytics'] as const,
  query: (params: AnalyticsParams) => [...callTrackingAnalyticsKeys.all, params] as const,
};

export function useCallTrackingAnalytics(params: AnalyticsParams) {
  return useQuery({
    queryKey: callTrackingAnalyticsKeys.query(params),
    queryFn: () => callTrackingAnalyticsApi.getAnalytics(params),
  });
}
```

`frontend/src/hooks/useCallTrackingNotificationSettings.ts`:

```ts
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  callTrackingNotificationSettingsApi,
  type NotificationSettingsFormData,
  type NotificationLogParams,
} from '@/services/callTrackingNotificationSettingsApi';

export const callTrackingNotificationKeys = {
  all: ['call-tracking-notification-settings'] as const,
  settings: (campaignId: string | number) =>
    [...callTrackingNotificationKeys.all, 'settings', campaignId] as const,
  logs: (campaignId: string | number, params?: NotificationLogParams) =>
    [...callTrackingNotificationKeys.all, 'logs', campaignId, params ?? {}] as const,
};

export function useCallTrackingNotificationSettings(campaignId: string | number) {
  return useQuery({
    queryKey: callTrackingNotificationKeys.settings(campaignId),
    queryFn: () => callTrackingNotificationSettingsApi.get(campaignId),
  });
}

export function useUpdateCallTrackingNotificationSettings() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ campaignId, data }: { campaignId: string | number; data: NotificationSettingsFormData }) =>
      callTrackingNotificationSettingsApi.update(campaignId, data),
    onSuccess: (_, variables) =>
      queryClient.invalidateQueries({ queryKey: callTrackingNotificationKeys.settings(variables.campaignId) }),
  });
}

export function useTestCallTrackingNotification() {
  return useMutation({
    mutationFn: ({ campaignId, eventType }: { campaignId: string | number; eventType?: string }) =>
      callTrackingNotificationSettingsApi.test(campaignId, eventType),
  });
}

export function useCallTrackingNotificationLogs(campaignId: string | number, params?: NotificationLogParams) {
  return useQuery({
    queryKey: callTrackingNotificationKeys.logs(campaignId, params),
    queryFn: () => callTrackingNotificationSettingsApi.getLogs(campaignId, params),
  });
}
```

`frontend/src/hooks/useCallTrackingIntegrations.ts`:

```ts
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { callTrackingIntegrationsApi, type AdPlatformIntegrationFormData } from '@/services/callTrackingIntegrationsApi';

export const callTrackingIntegrationKeys = {
  all: ['call-tracking-ad-platform-integrations'] as const,
};

export function useCallTrackingIntegrations() {
  return useQuery({
    queryKey: callTrackingIntegrationKeys.all,
    queryFn: () => callTrackingIntegrationsApi.get(),
  });
}

export function useUpdateCallTrackingIntegrations() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: AdPlatformIntegrationFormData) => callTrackingIntegrationsApi.update(data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: callTrackingIntegrationKeys.all }),
  });
}
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/hooks/useCallTracking*.ts
git commit -m "feat(frontend): add call tracking tanstack query hooks"
```

---

### Task 17: Register routes and sidebar navigation

**Files:**
- Modify: `frontend/src/router.tsx`
- Modify: `frontend/src/components/Layout/Sidebar.tsx`

- [ ] **Step 1: Add lazy imports in `router.tsx`**

Add after existing lazy imports:

```tsx
const CallTrackingDashboard = lazy(() => import('@/pages/CallTrackingDashboard'));
const CallTrackingCampaigns = lazy(() => import('@/pages/CallTrackingCampaigns'));
const CallTrackingCampaignForm = lazy(() => import('@/pages/CallTrackingCampaignForm'));
const CallTrackingCampaignDetail = lazy(() => import('@/pages/CallTrackingCampaignDetail'));
const CallTrackingSessions = lazy(() => import('@/pages/CallTrackingSessions'));
const CallTrackingDniSnippet = lazy(() => import('@/pages/CallTrackingDniSnippet'));
const CallTrackingIntegrations = lazy(() => import('@/pages/CallTrackingIntegrations'));
```

- [ ] **Step 2: Add routes inside `/ui` children**

```tsx
{
  path: 'call-tracking',
  element: <Navigate to="/ui/call-tracking/dashboard" replace />,
},
{
  path: 'call-tracking/dashboard',
  element: <CallTrackingDashboard />,
},
{
  path: 'call-tracking/campaigns',
  element: <CallTrackingCampaigns />,
},
{
  path: 'call-tracking/campaigns/new',
  element: <CallTrackingCampaignForm />,
},
{
  path: 'call-tracking/campaigns/:id',
  element: <CallTrackingCampaignDetail />,
},
{
  path: 'call-tracking/campaigns/:id/edit',
  element: <CallTrackingCampaignForm />,
},
{
  path: 'call-tracking/sessions',
  element: <CallTrackingSessions />,
},
{
  path: 'call-tracking/dni-snippet',
  element: <CallTrackingDniSnippet />,
},
{
  path: 'call-tracking/integrations',
  element: <CallTrackingIntegrations />,
},
```

- [ ] **Step 3: Add sidebar section**

In `frontend/src/components/Layout/Sidebar.tsx`, add to `sidebarSections`:

```tsx
{
  id: 'call-tracking',
  title: 'Call Tracking',
  icon: 'codicon-call-incoming',
  accentColor: 'default',
  items: [
    { name: 'Dashboard', href: '/ui/call-tracking/dashboard', icon: 'codicon-graph', roles: ['owner', 'pbx_admin', 'pbx_user', 'reporter'] },
    { name: 'Campaigns', href: '/ui/call-tracking/campaigns', icon: 'codicon-target', roles: ['owner', 'pbx_admin'] },
    { name: 'Sessions', href: '/ui/call-tracking/sessions', icon: 'codicon-list-flat', roles: ['owner', 'pbx_admin', 'pbx_user', 'reporter'] },
    { name: 'DNI Snippet', href: '/ui/call-tracking/dni-snippet', icon: 'codicon-code', roles: ['owner', 'pbx_admin'] },
    { name: 'Integrations', href: '/ui/call-tracking/integrations', icon: 'codicon-plug', roles: ['owner', 'pbx_admin'] },
  ],
},
```

- [ ] **Step 4: Commit**

```bash
git add frontend/src/router.tsx frontend/src/components/Layout/Sidebar.tsx
git commit -m "feat(frontend): add call tracking routes and sidebar navigation"
```

---

### Task 18: Build dashboard components and page

**Files:**
- Create: `frontend/src/components/call-tracking/KpiCards.tsx`
- Create: `frontend/src/components/call-tracking/CallsChart.tsx`
- Create: `frontend/src/components/call-tracking/TopTable.tsx`
- Create: `frontend/src/pages/CallTrackingDashboard.tsx`

- [ ] **Step 1: Write `KpiCards.tsx`**

```tsx
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PhoneCall, Users, CheckCircle, XCircle, Clock, TrendingUp, Percent } from 'lucide-react';
import type { CallTrackingAnalytics } from '@/types/callTracking';

interface KpiCardsProps {
  kpis: CallTrackingAnalytics['kpis'];
}

const formatDuration = (seconds: number) => {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${m}m ${s}s`;
};

export function KpiCards({ kpis }: KpiCardsProps) {
  const cards = [
    { title: 'Total Calls', value: kpis.total_calls, icon: PhoneCall },
    { title: 'Unique Callers', value: kpis.unique_callers, icon: Users },
    { title: 'Answered', value: kpis.answered_calls, icon: CheckCircle },
    { title: 'Missed', value: kpis.missed_calls, icon: XCircle },
    { title: 'Avg Duration', value: formatDuration(kpis.avg_duration), icon: Clock },
    { title: 'Conversions', value: kpis.conversions, icon: TrendingUp },
    { title: 'Conversion Rate', value: `${(kpis.conversion_rate * 100).toFixed(1)}%`, icon: Percent },
  ];

  return (
    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
      {cards.map((card) => (
        <Card key={card.title}>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium">{card.title}</CardTitle>
            <card.icon className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{card.value}</div>
          </CardContent>
        </Card>
      ))}
    </div>
  );
}
```

- [ ] **Step 2: Write `CallsChart.tsx`**

```tsx
import {
  ResponsiveContainer,
  AreaChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
} from 'recharts';
import type { CallTrackingAnalytics } from '@/types/callTracking';

interface CallsChartProps {
  data: CallTrackingAnalytics['time_series'];
}

export function CallsChart({ data }: CallsChartProps) {
  return (
    <div className="h-[300px] w-full">
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={data} margin={{ top: 10, right: 30, left: 0, bottom: 0 }}>
          <defs>
            <linearGradient id="colorCalls" x1="0" y1="0" x2="0" y2="1">
              <stop offset="5%" stopColor="#3b82f6" stopOpacity={0.8} />
              <stop offset="95%" stopColor="#3b82f6" stopOpacity={0} />
            </linearGradient>
            <linearGradient id="colorConversions" x1="0" y1="0" x2="0" y2="1">
              <stop offset="5%" stopColor="#22c55e" stopOpacity={0.8} />
              <stop offset="95%" stopColor="#22c55e" stopOpacity={0} />
            </linearGradient>
          </defs>
          <XAxis dataKey="date_key" />
          <YAxis allowDecimals={false} />
          <CartesianGrid strokeDasharray="3 3" />
          <Tooltip />
          <Legend />
          <Area type="monotone" dataKey="calls" stroke="#3b82f6" fillOpacity={1} fill="url(#colorCalls)" />
          <Area type="monotone" dataKey="conversions" stroke="#22c55e" fillOpacity={1} fill="url(#colorConversions)" />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}
```

- [ ] **Step 3: Write `TopTable.tsx`**

```tsx
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

interface TopTableProps<T> {
  title: string;
  data: T[];
  columns: { key: keyof T; label: string }[];
}

export function TopTable<T extends Record<string, unknown>>({ title, data, columns }: TopTableProps<T>) {
  return (
    <div>
      <h3 className="text-lg font-semibold mb-4">{title}</h3>
      <Table>
        <TableHeader>
          <TableRow>
            {columns.map((col) => (
              <TableHead key={String(col.key)}>{col.label}</TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {data.length === 0 && (
            <TableRow>
              <TableCell colSpan={columns.length} className="text-center text-muted-foreground">
                No data
              </TableCell>
            </TableRow>
          )}
          {data.map((row, idx) => (
            <TableRow key={idx}>
              {columns.map((col) => (
                <TableCell key={String(col.key)}>{String(row[col.key])}</TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
```

- [ ] **Step 4: Write `CallTrackingDashboard.tsx`**

```tsx
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCallTrackingAnalytics } from '@/hooks/useCallTrackingAnalytics';
import { KpiCards } from '@/components/call-tracking/KpiCards';
import { CallsChart } from '@/components/call-tracking/CallsChart';
import { TopTable } from '@/components/call-tracking/TopTable';
import { callTrackingAnalyticsApi } from '@/services/callTrackingAnalyticsApi';
import { Download, Loader2 } from 'lucide-react';

export default function CallTrackingDashboard() {
  const navigate = useNavigate();
  const today = new Date().toISOString().split('T')[0];
  const thirtyDaysAgo = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];

  const [startDate, setStartDate] = useState(thirtyDaysAgo);
  const [endDate, setEndDate] = useState(today);

  const { data, isLoading } = useCallTrackingAnalytics({
    start_date: startDate,
    end_date: endDate,
    group_by: 'day',
  });

  const handleExport = async () => {
    const blob = await callTrackingAnalyticsApi.exportCsv({
      start_date: startDate,
      end_date: endDate,
      group_by: 'day',
    });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `call-tracking-analytics-${startDate}-to-${endDate}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
  };

  if (isLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin" />
      </div>
    );
  }

  return (
    <div className="space-y-6 p-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">Call Tracking Dashboard</h1>
          <p className="text-muted-foreground">Campaign attribution and conversion analytics</p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={handleExport}>
            <Download className="h-4 w-4 mr-2" />
            Export CSV
          </Button>
          <Button onClick={() => navigate('/ui/call-tracking/campaigns')}>Manage Campaigns</Button>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Date Range</CardTitle>
        </CardHeader>
        <CardContent className="flex gap-4">
          <div className="grid gap-2">
            <Label htmlFor="start">Start</Label>
            <Input id="start" type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="end">End</Label>
            <Input id="end" type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
          </div>
        </CardContent>
      </Card>

      {data && (
        <>
          <KpiCards kpis={data.kpis} />

          <Card>
            <CardHeader>
              <CardTitle>Calls & Conversions Over Time</CardTitle>
            </CardHeader>
            <CardContent>
              <CallsChart data={data.time_series} />
            </CardContent>
          </Card>

          <div className="grid gap-6 lg:grid-cols-2">
            <Card>
              <CardContent className="pt-6">
                <TopTable
                  title="Top Campaigns"
                  data={data.top_campaigns}
                  columns={[
                    { key: 'campaign_name', label: 'Campaign' },
                    { key: 'calls', label: 'Calls' },
                    { key: 'conversions', label: 'Conversions' },
                  ]}
                />
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <TopTable
                  title="Top Sources / Mediums"
                  data={data.top_sources}
                  columns={[
                    { key: 'source', label: 'Source' },
                    { key: 'medium', label: 'Medium' },
                    { key: 'calls', label: 'Calls' },
                    { key: 'conversions', label: 'Conversions' },
                  ]}
                />
              </CardContent>
            </Card>
          </div>
        </>
      )}
    </div>
  );
}
```

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/call-tracking/KpiCards.tsx frontend/src/components/call-tracking/CallsChart.tsx frontend/src/components/call-tracking/TopTable.tsx frontend/src/pages/CallTrackingDashboard.tsx
git commit -m "feat(frontend): add call tracking dashboard"
```

---

### Task 19: Build campaigns list page

**Files:**
- Create: `frontend/src/pages/CallTrackingCampaigns.tsx`

- [ ] **Step 1: Implement the list page**

Follow the `ConferenceRooms.tsx` / `AutoDialerCampaigns.tsx` pattern:

- Use `useCallTrackingCampaigns` with `search` and `status` filters.
- Render a shadcn `Table` with columns: Name, Source/Medium, Destination, Status, Numbers, Actions.
- Use the mandatory empty state pattern:
  - Icon: `PhoneCall` (`h-12 w-12 mx-auto text-muted-foreground mb-4`)
  - Heading: "No campaigns found"
  - Message: "Try adjusting your filters" if filters active, otherwise "Get started by creating your first campaign"
  - CTA button (owner/admin only, no filters): "Add Campaign" → `/ui/call-tracking/campaigns/new`
- Row click navigates to `/ui/call-tracking/campaigns/:id`.

- [ ] **Step 2: Commit**

```bash
git add frontend/src/pages/CallTrackingCampaigns.tsx
git commit -m "feat(frontend): add call tracking campaigns list page"
```

---

### Task 20: Build campaign form page

**Files:**
- Create: `frontend/src/pages/CallTrackingCampaignForm.tsx`
- Create: `frontend/src/components/call-tracking/ConversionRuleFields.tsx`

- [ ] **Step 1: Write `ConversionRuleFields.tsx`**

A reusable component using `react-hook-form` register/watch/setValue. Fields:
- Minimum answered duration (number)
- Require answered disposition (checkbox)

- [ ] **Step 2: Write `CallTrackingCampaignForm.tsx`**

- Zod schema: name required, source/medium/description optional, status enum, destination_type enum, destination_config record, conversion_rule object.
- Default destination: `forward` with `forward_to`.
- Use `useCreateCallTrackingCampaign` / `useUpdateCallTrackingCampaign`.
- On success navigate to detail page.
- Include `ConversionRuleFields`.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/call-tracking/ConversionRuleFields.tsx frontend/src/pages/CallTrackingCampaignForm.tsx
git commit -m "feat(frontend): add call tracking campaign form"
```

---

### Task 21: Build campaign detail page

**Files:**
- Create: `frontend/src/pages/CallTrackingCampaignDetail.tsx`
- Create: `frontend/src/components/call-tracking/NotificationAuthFields.tsx`

- [ ] **Step 1: Write `NotificationAuthFields.tsx`**

Conditional auth fields based on `auth_method` (`none`, `bearer_token`, `basic_auth`).

- [ ] **Step 2: Write `CallTrackingCampaignDetail.tsx`**

Use `Tabs` with three tabs:

1. **Tracking Numbers**
   - Show assigned numbers in a `Table`.
   - Provide an input + "Assign" button to add a DID by ID.
   - Per-row remove button.

2. **Notifications**
   - Load settings via `useCallTrackingNotificationSettings`.
   - Form: webhook URL, auth method, enabled events checkboxes, active toggle.
   - "Save Settings" and "Send Test Event" buttons.
   - Show recent delivery logs via `useCallTrackingNotificationLogs`.

3. **Ad-Platform Uploads**
   - Display Google Ads and Meta upload toggles for the campaign.
   - Update via `useUpdateCallTrackingCampaign`.
   - Disable toggles if organization integration is not configured.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/call-tracking/NotificationAuthFields.tsx frontend/src/pages/CallTrackingCampaignDetail.tsx
git commit -m "feat(frontend): add call tracking campaign detail page"
```

---

### Task 22: Build sessions page

**Files:**
- Create: `frontend/src/pages/CallTrackingSessions.tsx`

- [ ] **Step 1: Implement the page**

- Filters: date range, campaign, source/medium, disposition, converted toggle.
- Use `useCallTrackingSessions`.
- Table columns: Timestamp, Caller, Called Number, Campaign, Source/Medium, Duration, Disposition, Converted.
- Empty state pattern with `ClipboardList` icon.

- [ ] **Step 2: Commit**

```bash
git add frontend/src/pages/CallTrackingSessions.tsx
git commit -m "feat(frontend): add call tracking sessions page"
```

---

### Task 23: Build DNI snippet page

**Files:**
- Create: `frontend/src/pages/CallTrackingDniSnippet.tsx`
- Create: `frontend/src/components/call-tracking/DniCodeBlock.tsx`

- [ ] **Step 1: Write `DniCodeBlock.tsx`**

A read-only `pre`/`code` block with a copy-to-clipboard button.

- [ ] **Step 2: Write `CallTrackingDniSnippet.tsx`**

- Inputs: default number, organization ID (auto-filled from auth user).
- Generate JS snippet:

```html
<script async src="https://opbx.example.com/js/call-tracking-dni.js"
  data-organization="{orgId}"
  data-default="{defaultNumber}">
</script>
```

- Render `DniCodeBlock` and copy button.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/call-tracking/DniCodeBlock.tsx frontend/src/pages/CallTrackingDniSnippet.tsx
git commit -m "feat(frontend): add call tracking DNI snippet page"
```

---

### Task 24: Build integrations page

**Files:**
- Create: `frontend/src/pages/CallTrackingIntegrations.tsx`

- [ ] **Step 1: Implement the page**

- Use `useCallTrackingIntegrations` and `useUpdateCallTrackingIntegrations`.
- Two cards: Google Ads and Meta.
- Each card has:
  - Enable toggle
  - Credential inputs (developer token, refresh token, customer ID, conversion action resource name for Google; pixel ID and access token for Meta)
  - Masked inputs for secrets
  - Save button
- Show "configured" status from API.

- [ ] **Step 2: Commit**

```bash
git add frontend/src/pages/CallTrackingIntegrations.tsx
git commit -m "feat(frontend): add call tracking integrations page"
```

---

### Task 25: Frontend lint, type-check, and build verification

- [ ] **Step 1: Run lint and type-check**

```bash
cd frontend
npm run lint
npm run type-check
npm run build
```

Expected: no errors.

- [ ] **Step 2: Commit any fixes**

```bash
cd ..
git add -A
git commit -m "chore(frontend): lint and type-check call tracking UI"
```

---

## Verification

- [ ] **Run backend tests**

```bash
./run-tests.sh --filter=CallTracking
```

Expected: all CallTracking tests pass.

- [ ] **Run PHP lint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Run full frontend checks**

```bash
cd frontend && npm run lint && npm run type-check && npm run build
```

---

## Memory Update

After implementation, update `.my_agent/memory/call-tracking.md`:

- Mark notification test endpoint, notification delivery logs API, ad-platform integration settings, campaign toggles, and frontend pages as completed.
- Add new backend files and frontend files to the tables.
- Update routes list.
- Update test table.
