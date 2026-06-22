# Call Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Call Tracking module in OPBX, including campaign management, tracking number assignment, call routing, conversion rules, analytics, custom webhooks, and ad-platform integration placeholders.

**Architecture:** DIDs are provisioned via the existing Phone Numbers module and assigned to Call Tracking campaigns. A new routing type `call_tracking` on `did_numbers` triggers a dedicated voice routing strategy that forwards calls to an external number or delegates to existing OPBX destination strategies. CDR processing creates `call_tracking_sessions`, evaluates per-campaign conversion rules, and dispatches custom webhook notifications.

**Tech Stack:** Laravel 12 (PHP 8.4), MySQL, Redis, React 18 + TypeScript + Vite + TanStack Query + shadcn/ui, Cloudonix CXML/webhooks.

---

## File Map

### Backend

| File | Responsibility |
|------|----------------|
| `app/Enums/CallTrackingCampaignStatus.php` | Campaign status enum. |
| `app/Enums/CallTrackingDestinationType.php` | Destination type enum. |
| `app/Enums/CallTrackingEventType.php` | Webhook event types. |
| `app/Models/CallTrackingCampaign.php` | Campaign model. |
| `app/Models/CallTrackingNumber.php` | Join model between campaign and DID. |
| `app/Models/CallTrackingSession.php` | Per-call analytics record. |
| `app/Models/CallTrackingNotificationSettings.php` | Per-campaign webhook settings. |
| `app/Models/CallTrackingNotificationLog.php` | Webhook delivery audit log. |
| `app/Services/CallTracking/ConversionRuleEvaluator.php` | Evaluates conversion rule. |
| `app/Services/CallTracking/CallTrackingDestinationResolver.php` | Resolves campaign destination. |
| `app/Services/CallTracking/CallTrackingSessionService.php` | Creates session from CDR. |
| `app/Services/CallTracking/CallTrackingNotificationDispatcher.php` | Dispatches tracking webhooks. |
| `app/Services/CallTracking/CallTrackingAnalyticsService.php` | Aggregates analytics. |
| `app/Services/CallTracking/Adapters/GoogleAdsConversionAdapter.php` | Google Ads placeholder. |
| `app/Services/CallTracking/Adapters/MetaConversionAdapter.php` | Meta placeholder. |
| `app/Services/VoiceRouting/Strategies/CallTrackingRoutingStrategy.php` | Voice routing strategy. |
| `app/Http/Controllers/Api/CallTrackingCampaignController.php` | Campaign CRUD. |
| `app/Http/Controllers/Api/CallTrackingNumberController.php` | Tracking number assignment. |
| `app/Http/Controllers/Api/CallTrackingSessionController.php` | Sessions list. |
| `app/Http/Controllers/Api/CallTrackingAnalyticsController.php` | Analytics + export. |
| `app/Http/Controllers/Api/CallTrackingNotificationSettingsController.php` | Notification settings. |
| `app/Http/Controllers/Api/CallTrackingDniController.php` | Public DNI swap. |
| `app/Http/Requests/CallTracking/*` | Form request validators. |
| `app/Http/Resources/CallTracking*.php` | API transformers. |
| `app/Policies/CallTrackingCampaignPolicy.php` | RBAC policy. |
| `app/Policies/CallTrackingNumberPolicy.php` | RBAC policy. |
| `database/migrations/2026_06_22_00000*_call_tracking_*.php` | Migrations. |
| `database/factories/CallTracking*.php` | Factories. |
| `routes/api.php` | Register routes. |
| `app/Providers/AppServiceProvider.php` | Register policies + strategy. |
| `app/Jobs/ProcessCDRJob.php` | Hook session creation. |

### Frontend

| File | Responsibility |
|------|----------------|
| `frontend/src/types/api.types.ts` | Add Call Tracking types. |
| `frontend/src/services/callTracking.service.ts` | API client. |
| `frontend/src/pages/CallTracking/*.tsx` | Pages and forms. |
| `frontend/src/App.tsx` | Register routes. |
| `frontend/src/components/Layout/Sidebar.tsx` | Add nav item. |

### Static Asset

| File | Responsibility |
|------|----------------|
| `public/js/call-tracking-dni.js` | DNI snippet. |

---

## Conventions Used in This Plan

- All PHP files must start with `declare(strict_types=1);`.
- All models must use `#[ScopedBy([OrganizationScope::class])]`.
- All controller/store/update actions must enforce RBAC via policy gates.
- All webhook-related code must log with `call_id` correlation.
- All new service classes must have a corresponding unit/feature test.
- Code style: PSR-12 + Laravel conventions; run `vendor/bin/pint` after backend changes.
- Frontend style: 2-space indentation, functional components, TanStack Query, Zod + react-hook-form.

---

## Task 1: Database Migrations

**Files:**
- Create: `database/migrations/2026_06_22_000001_create_call_tracking_campaigns_table.php`
- Create: `database/migrations/2026_06_22_000002_create_call_tracking_numbers_table.php`
- Create: `database/migrations/2026_06_22_000003_create_call_tracking_sessions_table.php`
- Create: `database/migrations/2026_06_22_000004_create_call_tracking_notification_settings_table.php`
- Create: `database/migrations/2026_06_22_000005_create_call_tracking_notification_logs_table.php`
- Create: `database/migrations/2026_06_22_000006_add_call_tracking_to_did_numbers_routing_type.php`
- Test: `tests/Feature/CallTracking/MigrationsTest.php`

- [ ] **Step 1: Create campaign migration**

Create the migration with columns matching the spec section 6.1:
- `id`, `organization_id` FK, `name`, `source`, `medium`, `description`
- `status` enum (`active`, `inactive`)
- `destination_type` enum (forward, extension, ring_group, business_hours, conference_room, ivr_menu, ai_assistant, ai_load_balancer)
- `destination_config` json, `conversion_rule` json, timestamps
- indexes on `[organization_id, status]` and `[organization_id, source, medium]`

- [ ] **Step 2: Create tracking numbers migration**

Create the migration with columns matching spec section 6.2:
- `id`, `organization_id` FK, `call_tracking_campaign_id` FK, `did_number_id` FK
- `friendly_name`, `status` enum, timestamps
- unique index on `did_number_id`

- [ ] **Step 3: Create sessions migration**

Create the migration with columns matching spec section 6.3:
- All call/session attribution fields, duration, disposition, conversion flags, raw_cdr
- Indexes: `[organization_id, call_tracking_campaign_id, started_at]`, `[organization_id, started_at]`, `[called_number, started_at]`

- [ ] **Step 4: Create notification settings migration**

Create the migration matching spec section 6.4:
- webhook_url, auth_method, auth_secret, auth_username, enabled_events, is_active
- unique index on `call_tracking_campaign_id`

- [ ] **Step 5: Create notification logs migration**

Create the migration matching spec section 6.4 log table:
- call_id, event_id, event_type, webhook_url, request/response payloads, timing, success
- Index on `[organization_id, call_tracking_campaign_id, created_at]`

- [ ] **Step 6: Add `call_tracking` to DID routing type enum**

Use `DB::statement` to `ALTER TABLE did_numbers MODIFY COLUMN routing_type` and add `'call_tracking'` to the enum list.

- [ ] **Step 7: Run migrations**

Run: `docker compose exec app php artisan migrate`
Expected: All 6 migrations succeed.

- [ ] **Step 8: Write migration test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\CallTracking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_call_tracking_tables_exist(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('call_tracking_campaigns'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('call_tracking_numbers'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('call_tracking_sessions'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('call_tracking_notification_settings'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('call_tracking_notification_logs'));
    }

    public function test_did_numbers_routing_type_includes_call_tracking(): void
    {
        $column = DB::select("SHOW COLUMNS FROM did_numbers WHERE Field = 'routing_type'")[0];
        $this->assertStringContainsString('call_tracking', $column->Type);
    }
}
```

- [ ] **Step 9: Run migration test**

Run: `./run-tests.sh --filter=Tests\Feature\CallTracking\MigrationsTest`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_06_22_00000*_call_tracking_*.php tests/Feature/CallTracking/MigrationsTest.php
git commit -m "feat(call-tracking): add database migrations"
```

---

## Task 2: Enums

**Files:**
- Create: `app/Enums/CallTrackingCampaignStatus.php`
- Create: `app/Enums/CallTrackingDestinationType.php`
- Create: `app/Enums/CallTrackingEventType.php`
- Test: `tests/Unit/Enums/CallTrackingEnumsTest.php`

- [ ] **Step 1: Create campaign status enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum CallTrackingCampaignStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::INACTIVE => 'Inactive',
        };
    }
}
```

- [ ] **Step 2: Create destination type enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum CallTrackingDestinationType: string
{
    case FORWARD = 'forward';
    case EXTENSION = 'extension';
    case RING_GROUP = 'ring_group';
    case BUSINESS_HOURS = 'business_hours';
    case CONFERENCE_ROOM = 'conference_room';
    case IVR_MENU = 'ivr_menu';
    case AI_ASSISTANT = 'ai_assistant';
    case AI_LOAD_BALANCER = 'ai_load_balancer';

    public function label(): string
    {
        return match ($this) {
            self::FORWARD => 'Forward to External Number',
            self::EXTENSION => 'Extension',
            self::RING_GROUP => 'Ring Group',
            self::BUSINESS_HOURS => 'Business Hours',
            self::CONFERENCE_ROOM => 'Conference Room',
            self::IVR_MENU => 'IVR Menu',
            self::AI_ASSISTANT => 'AI Assistant',
            self::AI_LOAD_BALANCER => 'AI Load Balancer',
        };
    }

    public function toExtensionType(): ?ExtensionType
    {
        return match ($this) {
            self::EXTENSION => ExtensionType::USER,
            self::RING_GROUP => ExtensionType::RING_GROUP,
            self::BUSINESS_HOURS => null,
            self::CONFERENCE_ROOM => ExtensionType::CONFERENCE,
            self::IVR_MENU => ExtensionType::IVR,
            self::AI_ASSISTANT => ExtensionType::AI_ASSISTANT,
            self::AI_LOAD_BALANCER => ExtensionType::AI_LOAD_BALANCER,
            self::FORWARD => ExtensionType::FORWARD,
        };
    }

    public static function options(): array
    {
        return array_reduce(self::cases(), function ($carry, $case) {
            $carry[$case->value] = $case->label();

            return $carry;
        }, []);
    }
}
```

- [ ] **Step 3: Create event type enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum CallTrackingEventType: string
{
    case CALL_RECEIVED = 'call.received';
    case CALL_ANSWERED = 'call.answered';
    case CALL_MISSED = 'call.missed';
    case CALL_CONVERTED = 'call.converted';
    case CALL_FAILED = 'call.failed';

    public function label(): string
    {
        return match ($this) {
            self::CALL_RECEIVED => 'Call Received',
            self::CALL_ANSWERED => 'Call Answered',
            self::CALL_MISSED => 'Call Missed',
            self::CALL_CONVERTED => 'Call Converted',
            self::CALL_FAILED => 'Call Failed',
        };
    }

    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
```

- [ ] **Step 4: Write enum tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\CallTrackingCampaignStatus;
use App\Enums\CallTrackingDestinationType;
use App\Enums\CallTrackingEventType;
use App\Enums\ExtensionType;
use PHPUnit\Framework\TestCase;

class CallTrackingEnumsTest extends TestCase
{
    public function test_campaign_status_values(): void
    {
        $this->assertSame('active', CallTrackingCampaignStatus::ACTIVE->value);
        $this->assertSame('inactive', CallTrackingCampaignStatus::INACTIVE->value);
    }

    public function test_destination_type_maps_to_forward_extension_type(): void
    {
        $this->assertSame(ExtensionType::FORWARD, CallTrackingDestinationType::FORWARD->toExtensionType());
    }

    public function test_destination_type_maps_to_user_extension_type(): void
    {
        $this->assertSame(ExtensionType::USER, CallTrackingDestinationType::EXTENSION->toExtensionType());
    }

    public function test_event_type_values(): void
    {
        $this->assertContains('call.converted', CallTrackingEventType::values());
    }
}
```

- [ ] **Step 5: Run enum tests**

Run: `./run-tests.sh --filter=Tests\Unit\Enums\CallTrackingEnumsTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Enums/CallTracking*.php tests/Unit/Enums/CallTrackingEnumsTest.php
git commit -m "feat(call-tracking): add enums"
```

---

## Task 3: Models

**Files:**
- Create: `app/Models/CallTrackingCampaign.php`
- Create: `app/Models/CallTrackingNumber.php`
- Create: `app/Models/CallTrackingSession.php`
- Create: `app/Models/CallTrackingNotificationSettings.php`
- Create: `app/Models/CallTrackingNotificationLog.php`
- Test: `tests/Unit/Models/CallTrackingCampaignTest.php`

- [ ] **Step 1: Create CallTrackingCampaign model**

Use the spec section 6.1 column list for `$fillable`. Casts:
- `status` => `CallTrackingCampaignStatus::class`
- `destination_type` => `CallTrackingDestinationType::class`
- `destination_config` => `'array'`
- `conversion_rule` => `'array'`

Relations: `organization()`, `trackingNumbers()`, `sessions()`, `notificationSettings()`.
Add scopes: `forOrganization(int)`, `active()`.
Add helpers: `isActive()`, `getForwardTo(): ?string`, `getDestinationId(string): ?int`.

- [ ] **Step 2: Create CallTrackingNumber model**

Use spec section 6.2 for `$fillable`. Cast `status` to enum.
Relations: `organization()`, `campaign()`, `did()` (belongs to `DidNumber` via `did_number_id`).
Scopes: `forOrganization(int)`, `active()`. Helper: `isActive()`.

- [ ] **Step 3: Create CallTrackingSession model**

Use spec section 6.3 for `$fillable`.
Casts: duration/billsec integer, booleans, conversion_value decimal:4, datetimes, raw_cdr array.
Relations: `organization()`, `campaign()`, `trackingNumber()`, `did()`.
Scope: `forOrganization(int)`.

- [ ] **Step 4: Create CallTrackingNotificationSettings model**

Table name: `call_tracking_notification_settings`.
Casts: enabled_events array, is_active boolean.
Default attributes: `auth_method` => `'none'`, `enabled_events` => `['call.received','call.converted']`, `is_active` => true.
Relations: `organization()`, `campaign()`.
Scope: `forCampaign(int)`. Helpers: `isEventEnabled(string)`, `isConfigured()`.

- [ ] **Step 5: Create CallTrackingNotificationLog model**

Table name: `call_tracking_notification_logs`.
Casts: request/response arrays, booleans/integers.
Relations: `organization()`, `campaign()`.

- [ ] **Step 6: Write model unit test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\CallTrackingCampaignStatus;
use App\Enums\CallTrackingDestinationType;
use App\Models\CallTrackingCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallTrackingCampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_casts_destination_type(): void
    {
        $campaign = CallTrackingCampaign::factory()->create([
            'destination_type' => CallTrackingDestinationType::FORWARD,
            'destination_config' => ['forward_to' => '+14155551234'],
            'conversion_rule' => ['min_answered_duration_seconds' => 60],
        ]);

        $this->assertSame(CallTrackingDestinationType::FORWARD, $campaign->destination_type);
        $this->assertSame('+14155551234', $campaign->getForwardTo());
        $this->assertSame(60, $campaign->conversion_rule['min_answered_duration_seconds']);
    }

    public function test_campaign_is_active(): void
    {
        $campaign = CallTrackingCampaign::factory()->create([
            'status' => CallTrackingCampaignStatus::ACTIVE,
        ]);

        $this->assertTrue($campaign->isActive());
    }
}
```

- [ ] **Step 7: Commit**

```bash
git add app/Models/CallTracking*.php tests/Unit/Models/CallTrackingCampaignTest.php
git commit -m "feat(call-tracking): add models"
```

---

## Task 4: Factories

**Files:**
- Create: `database/factories/CallTrackingCampaignFactory.php`
- Create: `database/factories/CallTrackingNumberFactory.php`
- Create: `database/factories/CallTrackingSessionFactory.php`

- [ ] **Step 1: Create campaign factory**

Default state:
- organization_id via Organization::factory
- name: fake words
- source/medium: optional random values
- status: active
- destination_type: forward
- destination_config: `{forward_to: '+1' + 10 digits}`
- conversion_rule: `{min_answered_duration_seconds: 60, require_disposition: 'answered'}`

States: `forwardTo(string)`, `toExtension(int)`, `inactive()`.

- [ ] **Step 2: Create tracking number factory**

Default state links organization, campaign, and did factories.

- [ ] **Step 3: Create session factory**

Default state generates realistic call data. State: `converted()`.

- [ ] **Step 4: Run model test**

Run: `./run-tests.sh --filter=Tests\Unit\Models\CallTrackingCampaignTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/factories/CallTracking*.php
git commit -m "feat(call-tracking): add factories"
```

---

## Task 5: Conversion Rule Evaluator

**Files:**
- Create: `app/Services/CallTracking/ConversionRuleEvaluator.php`
- Test: `tests/Unit/Services/CallTracking/ConversionRuleEvaluatorTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CallTracking;

use App\Services\CallTracking\ConversionRuleEvaluator;
use PHPUnit\Framework\TestCase;

class ConversionRuleEvaluatorTest extends TestCase
{
    public function test_call_is_converted_when_answered_and_duration_exceeds_threshold(): void
    {
        $this->assertTrue(ConversionRuleEvaluator::evaluate(
            ['min_answered_duration_seconds' => 60, 'require_disposition' => 'answered'],
            'ANSWER',
            70
        ));
    }

    public function test_call_is_not_converted_when_duration_below_threshold(): void
    {
        $this->assertFalse(ConversionRuleEvaluator::evaluate(
            ['min_answered_duration_seconds' => 60, 'require_disposition' => 'answered'],
            'ANSWER',
            30
        ));
    }

    public function test_call_is_not_converted_when_not_answered(): void
    {
        $this->assertFalse(ConversionRuleEvaluator::evaluate(
            ['min_answered_duration_seconds' => 60, 'require_disposition' => 'answered'],
            'BUSY',
            0
        ));
    }

    public function test_default_rule_requires_answered_disposition(): void
    {
        $this->assertTrue(ConversionRuleEvaluator::evaluate([], 'ANSWER', 1));
    }
}
```

Run: `./run-tests.sh --filter=Tests\Unit\Services\CallTracking\ConversionRuleEvaluatorTest`
Expected: FAIL

- [ ] **Step 2: Implement evaluator**

```php
<?php

declare(strict_types=1);

namespace App\Services\CallTracking;

class ConversionRuleEvaluator
{
    public static function evaluate(array $rule, string $disposition, int $billsec): bool
    {
        $minDuration = $rule['min_answered_duration_seconds'] ?? 0;
        $requiredDisposition = strtolower($rule['require_disposition'] ?? 'answered');
        $normalizedDisposition = strtolower($disposition);

        $dispositionMatches = match ($requiredDisposition) {
            'answered' => in_array($normalizedDisposition, ['answer', 'answered'], true),
            default => $normalizedDisposition === $requiredDisposition,
        };

        return $dispositionMatches && $billsec >= $minDuration;
    }
}
```

- [ ] **Step 3: Run evaluator test**

Run: `./run-tests.sh --filter=Tests\Unit\Services\CallTracking\ConversionRuleEvaluatorTest`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Services/CallTracking/ConversionRuleEvaluator.php tests/Unit/Services/CallTracking/ConversionRuleEvaluatorTest.php
git commit -m "feat(call-tracking): add conversion rule evaluator"
```

---

## Task 6: Destination Resolver

**Files:**
- Create: `app/Services/CallTracking/CallTrackingDestinationResolver.php`
- Test: `tests/Unit/Services/CallTracking/CallTrackingDestinationResolverTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CallTracking;

use App\Models\CallTrackingCampaign;
use App\Models\Extension;
use App\Models\Organization;
use App\Services\CallTracking\CallTrackingDestinationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallTrackingDestinationResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_forward_destination(): void
    {
        $campaign = CallTrackingCampaign::factory()->forwardTo('+14155551234')->create();

        $result = CallTrackingDestinationResolver::resolve($campaign);

        $this->assertSame('forward', $result['type']);
        $this->assertSame('+14155551234', $result['forward_to']);
    }

    public function test_resolves_extension_destination(): void
    {
        $organization = Organization::factory()->create();
        $extension = Extension::factory()->create([
            'organization_id' => $organization->id,
            'extension_number' => '1001',
        ]);
        $campaign = CallTrackingCampaign::factory()->recycle($organization)->toExtension($extension->id)->create();

        $result = CallTrackingDestinationResolver::resolve($campaign);

        $this->assertSame('extension', $result['type']);
        $this->assertSame($extension->id, $result['extension']->id);
    }
}
```

Run: `./run-tests.sh --filter=Tests\Unit\Services\CallTracking\CallTrackingDestinationResolverTest`
Expected: FAIL

- [ ] **Step 2: Implement resolver**

Create `app/Services/CallTracking/CallTrackingDestinationResolver.php` with a `resolve(CallTrackingCampaign $campaign): array` method that:
- Returns `['type' => 'forward', 'forward_to' => ...]` for forward destinations.
- For OPBX destinations, resolves the target model using `withoutGlobalScope(OrganizationScope::class)` and scoped by `organization_id`.
- Supported targets: extension, ring_group, business_hours_schedule, conference_room, ivr_menu, ai_assistant (extension with aiAssistant eager loaded), ai_load_balancer (extension).

- [ ] **Step 3: Run resolver test**

Run: `./run-tests.sh --filter=Tests\Unit\Services\CallTracking\CallTrackingDestinationResolverTest`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Services/CallTracking/CallTrackingDestinationResolver.php tests/Unit/Services/CallTracking/CallTrackingDestinationResolverTest.php
git commit -m "feat(call-tracking): add destination resolver"
```

---

## Task 7: Voice Routing Strategy

**Files:**
- Create: `app/Services/VoiceRouting/Strategies/CallTrackingRoutingStrategy.php`
- Modify: `app/Services/VoiceRouting/InboundRoutingService.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/CallTracking/VoiceRoutingTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\CallTracking;

use App\Enums\CallTrackingCampaignStatus;
use App\Enums\CallTrackingDestinationType;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_call_tracking_did_forwards_to_external_number(): void
    {
        $organization = Organization::factory()->create(['status' => 'active']);
        CloudonixSettings::factory()->create([
            'organization_id' => $organization->id,
            'domain_requests_api_key' => 'test-key',
        ]);

        $campaign = CallTrackingCampaign::factory()->recycle($organization)->create([
            'destination_type' => CallTrackingDestinationType::FORWARD,
            'destination_config' => ['forward_to' => '+14155559999'],
            'status' => CallTrackingCampaignStatus::ACTIVE,
        ]);

        $did = DidNumber::factory()->create([
            'organization_id' => $organization->id,
            'phone_number' => '+14155551234',
            'routing_type' => 'call_tracking',
            'routing_config' => ['call_tracking_campaign_id' => $campaign->id],
        ]);

        CallTrackingNumber::factory()->recycle($organization)->create([
            'call_tracking_campaign_id' => $campaign->id,
            'did_number_id' => $did->id,
        ]);

        $response = $this->postJson('/api/voice/route', [
            'Direction' => 'inbound',
            'From' => '+14155551111',
            'To' => '+14155551234',
            '_organization_id' => $organization->id,
        ], ['Authorization' => 'Bearer test-key']);

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee('+14155559999', false);
    }
}
```

Run: `./run-tests.sh --filter=Tests\Feature\CallTracking\VoiceRoutingTest`
Expected: FAIL

- [ ] **Step 2: Create CallTrackingRoutingStrategy**

Implement `app/Services/VoiceRouting/Strategies/CallTrackingRoutingStrategy.php`:
- `canHandle(ExtensionType $type): bool` returns `true`.
- `route(Request, DidNumber, array)` checks `$did->routing_type === 'call_tracking'`.
- Loads campaign via `withoutGlobalScope(OrganizationScope::class)`.
- If campaign inactive or missing, returns `CxmlBuilder::unavailable(...)`.
- If destination is forward, validates `forward_to` E.164 and returns `CxmlBuilder::simpleDial($forwardTo)`.
- For OPBX destinations, resolves via `CallTrackingDestinationResolver`, maps to existing strategy, and delegates by instantiating the strategy class from the container.

- [ ] **Step 3: Update InboundRoutingService**

In `resolveDidDestination`, add `'call_tracking' => $this->resolveCallTrackingDestination($did, $destination)` and implement a small helper to capture `call_tracking_campaign_id`.

In `routeDidCall`, before the strategy executor, add:

```php
if ($did->routing_type === 'call_tracking') {
    $strategy = app(CallTrackingRoutingStrategy::class);

    return $strategy->route($request, $did, $destination);
}
```

- [ ] **Step 4: Tag strategy in AppServiceProvider**

Add `App\Services\VoiceRouting\Strategies\CallTrackingRoutingStrategy::class` to the `'voice_routing.strategies'` tagged array.

- [ ] **Step 5: Run voice routing test**

Run: `./run-tests.sh --filter=Tests\Feature\CallTracking\VoiceRoutingTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/VoiceRouting/Strategies/CallTrackingRoutingStrategy.php app/Services/VoiceRouting/InboundRoutingService.php app/Providers/AppServiceProvider.php tests/Feature/CallTracking/VoiceRoutingTest.php
git commit -m "feat(call-tracking): add voice routing strategy"
```

---

## Task 8: CDR Session Creation

**Files:**
- Create: `app/Services/CallTracking/CallTrackingSessionService.php`
- Modify: `app/Jobs/ProcessCDRJob.php`
- Test: `tests/Feature/CallTracking/SessionCreationTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\CallTracking;

use App\Enums\CallTrackingCampaignStatus;
use App\Enums\CallTrackingDestinationType;
use App\Jobs\ProcessCDRJob;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\DidNumber;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cdr_creates_call_tracking_session(): void
    {
        $organization = Organization::factory()->create();
        $campaign = CallTrackingCampaign::factory()->recycle($organization)->create([
            'destination_type' => CallTrackingDestinationType::FORWARD,
            'destination_config' => ['forward_to' => '+14155559999'],
            'conversion_rule' => ['min_answered_duration_seconds' => 30, 'require_disposition' => 'answered'],
            'status' => CallTrackingCampaignStatus::ACTIVE,
        ]);
        $did = DidNumber::factory()->create([
            'organization_id' => $organization->id,
            'phone_number' => '+14155551234',
            'routing_type' => 'call_tracking',
            'routing_config' => ['call_tracking_campaign_id' => $campaign->id],
        ]);
        CallTrackingNumber::factory()->recycle($organization)->create([
            'call_tracking_campaign_id' => $campaign->id,
            'did_number_id' => $did->id,
        ]);

        $webhookData = [
            'call_id' => 'call-123',
            'from' => '+14155551111',
            'to' => '+14155551234',
            'disposition' => 'ANSWER',
            'duration' => 45,
            'billsec' => 40,
            'timestamp' => time(),
            '_organization_id' => $organization->id,
            'session' => [
                'token' => 'session-token',
                'id' => 1,
                'callStartTime' => now()->subSeconds(45)->getTimestampMs(),
                'callEndTime' => now()->getTimestampMs(),
                'callAnswerTime' => now()->subSeconds(40)->getTimestampMs(),
            ],
        ];

        (new ProcessCDRJob($webhookData))->handle();

        $this->assertDatabaseHas('call_tracking_sessions', [
            'organization_id' => $organization->id,
            'call_tracking_campaign_id' => $campaign->id,
            'call_id' => 'call-123',
            'called_number' => '+14155551234',
            'is_converted' => true,
        ]);
    }
}
```

Run: `./run-tests.sh --filter=Tests\Feature\CallTracking\SessionCreationTest`
Expected: FAIL

- [ ] **Step 2: Implement CallTrackingSessionService**

Create `app/Services/CallTracking/CallTrackingSessionService.php` with a static method `createFromCDR(CallDetailRecord $cdr, int $organizationId): ?CallTrackingSession` that:
- Looks up the active `CallTrackingNumber` whose DID matches `$cdr->to`.
- Skips if campaign inactive.
- Evaluates `ConversionRuleEvaluator::evaluate(...)`.
- Creates a `CallTrackingSession` with all attribution fields from the CDR and campaign snapshot.
- Stores the full raw CDR.

- [ ] **Step 3: Hook into ProcessCDRJob**

Add import `App\Services\CallTracking\CallTrackingSessionService` and call it after the CDR is created. Log the result with `call_id`.

- [ ] **Step 4: Run session creation test**

Run: `./run-tests.sh --filter=Tests\Feature\CallTracking\SessionCreationTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/CallTracking/CallTrackingSessionService.php app/Jobs/ProcessCDRJob.php tests/Feature/CallTracking/SessionCreationTest.php
git commit -m "feat(call-tracking): create sessions from CDR"
```

---

## Task 9: Custom Webhook Notifications

**Files:**
- Create: `app/Services/CallTracking/CallTrackingNotificationDispatcher.php`
- Modify: `app/Jobs/ProcessCDRJob.php`
- Create: `app/Http/Controllers/Api/CallTrackingNotificationSettingsController.php`
- Create: `app/Http/Requests/CallTracking/UpdateNotificationSettingsRequest.php`
- Create: `app/Http/Resources/CallTrackingNotificationSettingsResource.php`
- Test: `tests/Feature/CallTracking/NotificationsTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\CallTracking;

use App\Enums\UserRole;
use App\Models\CallTrackingCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_crud(): void
    {
        $campaign = CallTrackingCampaign::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $campaign->organization_id,
            'role' => UserRole::OWNER,
        ]);
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/call-tracking/campaigns/{$campaign->id}/notifications", [
            'webhook_url' => 'https://example.com/webhook',
            'auth_method' => 'none',
            'enabled_events' => ['call.converted'],
        ])->assertStatus(200);

        $this->getJson("/api/v1/call-tracking/campaigns/{$campaign->id}/notifications")
            ->assertStatus(200)
            ->assertJsonPath('data.webhook_url', 'https://example.com/webhook');
    }
}
```

Run: `./run-tests.sh --filter=Tests\Feature\CallTracking\NotificationsTest`
Expected: FAIL

- [ ] **Step 2: Create notification dispatcher**

Create `app/Services/CallTracking/CallTrackingNotificationDispatcher.php` with a static `dispatch(CallTrackingSession $session, string $eventType): void` method that:
- Loads settings for the session's campaign.
- Skips if not configured, inactive, or event not enabled.
- Validates URL with `SsrfUrlValidator`.
- Creates a `CallTrackingNotificationLog` row.
- Adds Bearer/Basic auth headers if configured (decrypt secrets).
- POSTs JSON payload and updates the log with response.

- [ ] **Step 3: Update ProcessCDRJob**

After creating the tracking session, dispatch a notification event:
- `call.converted` if `is_converted`
- `call.received` otherwise

- [ ] **Step 4: Create UpdateNotificationSettingsRequest**

Validation rules:
- `webhook_url`: required, url, max:2048, `ValidWebhookUrl`
- `auth_method`: required, in `[none, bearer_token, basic_auth]`
- `auth_secret`: nullable, string, max:512
- `auth_username`: nullable, string, max:255
- `enabled_events`: required, array, min:1, each in `CallTrackingEventType::values()`
- `is_active`: nullable, boolean

- [ ] **Step 5: Create resource**

`app/Http/Resources/CallTrackingNotificationSettingsResource.php` exposes id, campaign_id, webhook_url, auth_method, auth_username, enabled_events, is_active, timestamps.

- [ ] **Step 6: Create controller**

`app/Http/Controllers/Api/CallTrackingNotificationSettingsController.php`:
- `show(CallTrackingCampaign $campaign)` — authorize view, return settings or null.
- `update(...)` — authorize update, encrypt `auth_secret`, `updateOrCreate` by campaign_id.
- `test(CallTrackingCampaign $campaign)` — authorize update, find latest session or create factory session, dispatch `call.converted`.

- [ ] **Step 7: Run notification test**

Run: `./run-tests.sh --filter=Tests\Feature\CallTracking\NotificationsTest`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Services/CallTracking/CallTrackingNotificationDispatcher.php app/Http/Controllers/Api/CallTrackingNotificationSettingsController.php app/Http/Requests/CallTracking/UpdateNotificationSettingsRequest.php app/Http/Resources/CallTrackingNotificationSettingsResource.php app/Jobs/ProcessCDRJob.php tests/Feature/CallTracking/NotificationsTest.php
git commit -m "feat(call-tracking): add custom webhook notifications"
```

---

## Task 10: Ad-Platform Adapter Placeholders

**Files:**
- Create: `app/Services/CallTracking/Adapters/GoogleAdsConversionAdapter.php`
- Create: `app/Services/CallTracking/Adapters/MetaConversionAdapter.php`
- Test: `tests/Unit/Services/CallTracking/AdaptersTest.php`

- [ ] **Step 1: Create Google Ads adapter placeholder**

```php
<?php

declare(strict_types=1);

namespace App\Services\CallTracking\Adapters;

use App\Models\CallTrackingSession;

class GoogleAdsConversionAdapter
{
    public function uploadCallConversion(CallTrackingSession $session): bool
    {
        logger()->info('Google Ads conversion upload placeholder', [
            'session_id' => $session->id,
            'call_id' => $session->call_id,
        ]);

        return false;
    }
}
```

- [ ] **Step 2: Create Meta adapter placeholder**

```php
<?php

declare(strict_types=1);

namespace App\Services\CallTracking\Adapters;

use App\Models\CallTrackingSession;

class MetaConversionAdapter
{
    public function sendOfflineEvent(CallTrackingSession $session): bool
    {
        logger()->info('Meta conversion upload placeholder', [
            'session_id' => $session->id,
            'call_id' => $session->call_id,
        ]);

        return false;
    }
}
```

- [ ] **Step 3: Adapter test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CallTracking;

use App\Models\CallTrackingSession;
use App\Services\CallTracking\Adapters\GoogleAdsConversionAdapter;
use App\Services\CallTracking\Adapters\MetaConversionAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdaptersTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_ads_adapter_returns_false_placeholder(): void
    {
        $session = CallTrackingSession::factory()->create();
        $adapter = new GoogleAdsConversionAdapter;

        $this->assertFalse($adapter->uploadCallConversion($session));
    }

    public function test_meta_adapter_returns_false_placeholder(): void
    {
        $session = CallTrackingSession::factory()->create();
        $adapter = new MetaConversionAdapter;

        $this->assertFalse($adapter->sendOfflineEvent($session));
    }
}
```

Run: `./run-tests.sh --filter=Tests\Unit\Services\CallTracking\AdaptersTest`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Services/CallTracking/Adapters/GoogleAdsConversionAdapter.php app/Services/CallTracking/Adapters/MetaConversionAdapter.php tests/Unit/Services/CallTracking/AdaptersTest.php
git commit -m "feat(call-tracking): add ad-platform adapter placeholders"
```

---

## Task 11: Campaign Controller + API

**Files:**
- Create: `app/Http/Controllers/Api/CallTrackingCampaignController.php`
- Create: `app/Http/Requests/CallTracking/StoreCampaignRequest.php`
- Create: `app/Http/Requests/CallTracking/UpdateCampaignRequest.php`
- Create: `app/Http/Resources/CallTrackingCampaignResource.php`
- Create: `app/Policies/CallTrackingCampaignPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Api/CallTrackingCampaignControllerTest.php`

- [ ] **Step 1: Create policy**

`app/Policies/CallTrackingCampaignPolicy.php`:
- `viewAny`: true
- `view`: same org
- `create`: owner or pbx_admin
- `update`: owner/pbx_admin + same org
- `delete`: owner/pbx_admin + same org

- [ ] **Step 2: Create resource**

`app/Http/Resources/CallTrackingCampaignResource.php` exposes id, org_id, name, source, medium, description, status, destination_type, destination_config, conversion_rule, tracking_numbers_count, timestamps.

- [ ] **Step 3: Create StoreCampaignRequest**

Validation:
- name: required, string, max:255
- source/medium: nullable, string, max:100
- description: nullable, string
- status: required, in [active, inactive]
- destination_type: required, in `CallTrackingDestinationType::values()`
- destination_config: required, array
- Conditional required fields inside destination_config based on destination_type (forward_to, extension_id, ring_group_id, etc.)
- conversion_rule: required, array
- conversion_rule.min_answered_duration_seconds: required, integer, min:0

- [ ] **Step 4: Create UpdateCampaignRequest**

Same fields as StoreCampaignRequest but use `sometimes|required` for top-level keys so partial updates work.

- [ ] **Step 5: Create controller**

`app/Http/Controllers/Api/CallTrackingCampaignController.php`:
- `index(Request)` — scoped list with filters (status, source, search), sort, pagination.
- `store(StoreCampaignRequest)` — inject `organization_id` from user, create.
- `show(CallTrackingCampaign)` — authorize view, load trackingNumbers count.
- `update(UpdateCampaignRequest, CallTrackingCampaign)` — authorize update.
- `destroy(CallTrackingCampaign)` — authorize delete.

- [ ] **Step 6: Register policy**

Add in `AppServiceProvider::boot()`:
```php
Gate::policy(\App\Models\CallTrackingCampaign::class, \App\Policies\CallTrackingCampaignPolicy::class);
```

- [ ] **Step 7: Write feature test**

Test cases:
- owner can create campaign
- reporter cannot create campaign
- list is scoped to organization
- update/delete respect RBAC and org scope

- [ ] **Step 8: Run campaign tests**

Run: `./run-tests.sh --filter=Tests\Feature\Api\CallTrackingCampaignControllerTest`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Api/CallTrackingCampaignController.php app/Http/Requests/CallTracking/StoreCampaignRequest.php app/Http/Requests/CallTracking/UpdateCampaignRequest.php app/Http/Resources/CallTrackingCampaignResource.php app/Policies/CallTrackingCampaignPolicy.php app/Providers/AppServiceProvider.php tests/Feature/Api/CallTrackingCampaignControllerTest.php
git commit -m "feat(call-tracking): add campaign CRUD API"
```

---

## Task 12: Tracking Number Controller + API

**Files:**
- Create: `app/Http/Controllers/Api/CallTrackingNumberController.php`
- Create: `app/Http/Requests/CallTracking/StoreNumberRequest.php`
- Create: `app/Http/Resources/CallTrackingNumberResource.php`
- Create: `app/Policies/CallTrackingNumberPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Api/CallTrackingNumberControllerTest.php`

- [ ] **Step 1: Create policy**

`app/Policies/CallTrackingNumberPolicy.php` with same RBAC pattern as CallTrackingCampaignPolicy.

- [ ] **Step 2: Create StoreNumberRequest**

Validation:
- did_number_id: required, integer, exists in did_numbers where org matches + active, unique in call_tracking_numbers
- friendly_name: nullable, string, max:255
- status: nullable, in [active, inactive]

Custom message: "This DID is already assigned to a call tracking campaign."

- [ ] **Step 3: Create resource**

`app/Http/Resources/CallTrackingNumberResource.php` exposes id, org_id, campaign_id, did_number_id, phone_number (when did loaded), friendly_name, status, timestamps.

- [ ] **Step 4: Create controller**

`app/Http/Controllers/Api/CallTrackingNumberController.php`:
- `index(Request, CallTrackingCampaign $campaign)` — list paginated with did relation.
- `store(StoreNumberRequest, CallTrackingCampaign $campaign)` — authorize update on campaign, create number.
- `destroy(CallTrackingNumber $number)` — authorize delete, remove assignment.

- [ ] **Step 5: Register policy**

Add in `AppServiceProvider::boot()`:
```php
Gate::policy(\App\Models\CallTrackingNumber::class, \App\Policies\CallTrackingNumberPolicy::class);
```

- [ ] **Step 6: Write feature test**

Test cases:
- owner can assign DID to campaign
- cannot assign same DID twice
- destroy removes assignment
- unauthorized users cannot assign

- [ ] **Step 7: Run number tests**

Run: `./run-tests.sh --filter=Tests\Feature\Api\CallTrackingNumberControllerTest`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/CallTrackingNumberController.php app/Http/Requests/CallTracking/StoreNumberRequest.php app/Http/Resources/CallTrackingNumberResource.php app/Policies/CallTrackingNumberPolicy.php app/Providers/AppServiceProvider.php tests/Feature/Api/CallTrackingNumberControllerTest.php
git commit -m "feat(call-tracking): add tracking number assignment API"
```

---

## Task 13: Sessions + Analytics Controller

**Files:**
- Create: `app/Http/Controllers/Api/CallTrackingSessionController.php`
- Create: `app/Http/Controllers/Api/CallTrackingAnalyticsController.php`
- Create: `app/Services/CallTracking/CallTrackingAnalyticsService.php`
- Create: `app/Http/Resources/CallTrackingSessionResource.php`
- Test: `tests/Feature/Api/CallTrackingAnalyticsTest.php`

- [ ] **Step 1: Create analytics service**

`app/Services/CallTracking/CallTrackingAnalyticsService.php`:
- `aggregate(int $organizationId, array $filters): array`
- Totals: total_calls, unique_callers, answered_calls, missed_calls, average_duration, conversions, conversion_rate
- Trend: group by day/week/month via `group_by` filter
- Top campaigns/sources by calls and conversions
- Filters: campaign_id, source, medium, from_date, to_date

- [ ] **Step 2: Create session resource**

`app/Http/Resources/CallTrackingSessionResource.php` exposes id, call_id, caller/called numbers, campaign, source/medium, disposition, duration, billsec, is_answered, is_converted, started_at.

- [ ] **Step 3: Create controllers**

`CallTrackingSessionController::index(Request)`:
- Scope by organization, eager load campaign, apply filters, order by started_at desc, paginate.

`CallTrackingAnalyticsController`:
- `index(Request)` — return aggregate data.
- `export(Request)` — stream CSV with columns: Call ID, Campaign, Source, Medium, Caller, Called, Disposition, Duration, Billsec, Converted, Started At.

- [ ] **Step 4: Write analytics test**

Create sessions, call analytics endpoint, assert totals and conversion rate.

- [ ] **Step 5: Run analytics test**

Run: `./run-tests.sh --filter=Tests\Feature\Api\CallTrackingAnalyticsTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/CallTracking/CallTrackingAnalyticsService.php app/Http/Controllers/Api/CallTrackingSessionController.php app/Http/Controllers/Api/CallTrackingAnalyticsController.php app/Http/Resources/CallTrackingSessionResource.php tests/Feature/Api/CallTrackingAnalyticsTest.php
git commit -m "feat(call-tracking): add sessions and analytics API"
```

---

## Task 14: DNI Controller + Snippet

**Files:**
- Create: `app/Http/Controllers/Api/CallTrackingDniController.php`
- Create: `public/js/call-tracking-dni.js`
- Test: `tests/Feature/CallTracking/DniTest.php`

- [ ] **Step 1: Write failing test**

Create a campaign with source=google, medium=cpc, assign a DID. Call `/api/v1/call-tracking/dni/swap?organization={slug}&utm_source=google&utm_medium=cpc`. Assert response contains the DID and campaign id.

- [ ] **Step 2: Implement DNI controller**

`app/Http/Controllers/Api/CallTrackingDniController.php`:
- Public endpoint, rate-limited per org+IP.
- Resolve organization by slug.
- Match campaign by `campaign_id` query param, or by `utm_source` + `utm_medium`, or return fallback default_number.
- Pick the first active tracking number for the matched campaign.
- Return JSON: tracking_number, campaign_id, campaign_name, source, medium.

- [ ] **Step 3: Create DNI JS snippet**

Save to `public/js/call-tracking-dni.js`:
- Reads `data-organization`, `data-selector`, `data-default` from the script tag.
- Reads URL params `utm_source`, `utm_medium`, `utm_campaign`.
- Uses sessionStorage to avoid flicker.
- Calls the swap endpoint and replaces matched elements' text and `tel:` href.

- [ ] **Step 4: Run DNI test**

Run: `./run-tests.sh --filter=Tests\Feature\CallTracking\DniTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/CallTrackingDniController.php public/js/call-tracking-dni.js tests/Feature/CallTracking/DniTest.php
git commit -m "feat(call-tracking): add DNI swap endpoint and snippet"
```

---

## Task 15: Routes

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 1: Register API routes**

Add inside authenticated `v1` group in `routes/api.php`:

```php
use App\Http\Controllers\Api\CallTrackingAnalyticsController;
use App\Http\Controllers\Api\CallTrackingCampaignController;
use App\Http\Controllers\Api\CallTrackingDniController;
use App\Http\Controllers\Api\CallTrackingNotificationSettingsController;
use App\Http\Controllers\Api\CallTrackingNumberController;
use App\Http\Controllers\Api\CallTrackingSessionController;

Route::prefix('v1/call-tracking')->middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('campaigns', CallTrackingCampaignController::class);

    Route::prefix('campaigns/{campaign}')->group(function (): void {
        Route::get('numbers', [CallTrackingNumberController::class, 'index']);
        Route::post('numbers', [CallTrackingNumberController::class, 'store']);
        Route::get('notifications', [CallTrackingNotificationSettingsController::class, 'show']);
        Route::put('notifications', [CallTrackingNotificationSettingsController::class, 'update']);
        Route::post('notifications/test', [CallTrackingNotificationSettingsController::class, 'test']);
    });

    Route::delete('numbers/{number}', [CallTrackingNumberController::class, 'destroy']);

    Route::get('sessions', [CallTrackingSessionController::class, 'index']);
    Route::get('analytics', [CallTrackingAnalyticsController::class, 'index']);
    Route::get('analytics/export', [CallTrackingAnalyticsController::class, 'export']);
});

Route::get('v1/call-tracking/dni/swap', [CallTrackingDniController::class, 'swap'])
    ->middleware('throttle:60,1');
```

- [ ] **Step 2: Run full Call Tracking test suite**

Run: `./run-tests.sh --filter=CallTracking`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add routes/api.php
git commit -m "feat(call-tracking): register API routes"
```

---

## Task 16: Frontend Types + Service

**Files:**
- Modify: `frontend/src/types/api.types.ts`
- Create: `frontend/src/services/callTracking.service.ts`

- [ ] **Step 1: Add Call Tracking types**

Append to `frontend/src/types/api.types.ts`:

```typescript
export type CallTrackingDestinationType =
  | 'forward'
  | 'extension'
  | 'ring_group'
  | 'business_hours'
  | 'conference_room'
  | 'ivr_menu'
  | 'ai_assistant'
  | 'ai_load_balancer';

export interface CallTrackingCampaign {
  id: string;
  organization_id: string;
  name: string;
  source: string | null;
  medium: string | null;
  description: string | null;
  status: 'active' | 'inactive';
  destination_type: CallTrackingDestinationType;
  destination_config: Record<string, unknown>;
  conversion_rule: {
    min_answered_duration_seconds: number;
    require_disposition?: string;
  };
  tracking_numbers_count?: number;
  created_at: string;
  updated_at: string;
}

export interface CallTrackingNumber {
  id: string;
  organization_id: string;
  call_tracking_campaign_id: string;
  did_number_id: string;
  phone_number?: string;
  friendly_name: string | null;
  status: 'active' | 'inactive';
  created_at: string;
  updated_at: string;
}

export interface CallTrackingSession {
  id: string;
  call_id: string;
  caller_number: string;
  called_number: string;
  campaign: { id: string; name: string };
  source: string | null;
  medium: string | null;
  disposition: string;
  duration: number;
  billsec: number;
  is_answered: boolean;
  is_converted: boolean;
  started_at: string;
}

export interface CallTrackingNotificationSettings {
  id: string;
  call_tracking_campaign_id: string;
  webhook_url: string;
  auth_method: 'none' | 'bearer_token' | 'basic_auth';
  auth_username: string | null;
  enabled_events: string[];
  is_active: boolean;
}

export interface CallTrackingAnalytics {
  totals: {
    total_calls: number;
    unique_callers: number;
    answered_calls: number;
    missed_calls: number;
    average_duration: number;
    conversions: number;
    conversion_rate: number;
  };
  trend: Array<{ period: string; calls: number; conversions: number }>;
  top_campaigns: Array<{ call_tracking_campaign_id: string; campaign_name: string; calls: number; conversions: number }>;
  top_sources: Array<{ source: string; calls: number; conversions: number }>;
}
```

- [ ] **Step 2: Create API service**

Create `frontend/src/services/callTracking.service.ts` with methods:
- `getCampaigns(params)`, `getCampaign(id)`, `createCampaign(data)`, `updateCampaign(id, data)`, `deleteCampaign(id)`
- `getTrackingNumbers(campaignId, params)`, `assignTrackingNumber(campaignId, data)`, `removeTrackingNumber(numberId)`
- `getSessions(params)`, `getAnalytics(params)`, `exportAnalytics(params)`
- `getNotificationSettings(campaignId)`, `updateNotificationSettings(campaignId, data)`, `testNotification(campaignId)`

Each method uses the existing `api` axios instance and returns `response.data`.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/types/api.types.ts frontend/src/services/callTracking.service.ts
git commit -m "feat(call-tracking): add frontend types and service"
```

---

## Task 17: Frontend Pages

**Files:**
- Create: `frontend/src/pages/CallTracking/CallTrackingCampaigns.tsx`
- Create: `frontend/src/pages/CallTracking/CallTrackingCampaignForm.tsx`
- Create: `frontend/src/pages/CallTracking/CallTrackingNumbers.tsx`
- Create: `frontend/src/pages/CallTracking/CallTrackingDashboard.tsx`
- Create: `frontend/src/pages/CallTracking/CallTrackingSessions.tsx`
- Create: `frontend/src/pages/CallTracking/CallTrackingNotificationsSettings.tsx`
- Create: `frontend/src/pages/CallTracking/CallTrackingDniSnippet.tsx`
- Modify: `frontend/src/App.tsx`
- Modify: `frontend/src/components/Layout/Sidebar.tsx`

- [ ] **Step 1: Create Campaigns list page**

`CallTrackingCampaigns.tsx`:
- Table with columns: Name, Source/Medium, Destination Type, Status, Tracking Numbers, Actions.
- Search, status filter, source filter.
- Empty state matching the mandatory pattern (large icon, heading, contextual message, CTA if canCreate).
- Create/Edit/Delete dialogs.
- Use `callTrackingService` + TanStack Query.

- [ ] **Step 2: Create Campaign form page/dialog**

`CallTrackingCampaignForm.tsx`:
- Fields: name, source, medium, description, status.
- Destination selector: type dropdown + conditional input (reuse `DestinationTypeAndSelector` if it supports the call-tracking destination set; otherwise build a minimal version).
- Conversion rule: min_answered_duration_seconds number input.
- Zod schema + react-hook-form.

- [ ] **Step 3: Create Tracking Numbers page**

`CallTrackingNumbers.tsx`:
- Shows numbers for a selected campaign.
- Assign DID dropdown (only active DIDs not already assigned).
- Remove assignment action.
- Empty state.

- [ ] **Step 4: Create Dashboard page**

`CallTrackingDashboard.tsx`:
- Date range picker, campaign filter, source/medium filters, group-by toggle (day/week/month).
- KPI cards: Total Calls, Unique Callers, Answered, Missed, Avg Duration, Conversions, Conversion Rate.
- Charts: calls/conversions over time, top campaigns, top sources.
- Campaign comparison table.
- Use `recharts` if already installed; otherwise use the chart library already in the project.

- [ ] **Step 5: Create Sessions page**

`CallTrackingSessions.tsx`:
- Filterable table of call tracking sessions.
- Columns: Time, Caller, Called Number, Campaign, Source/Medium, Disposition, Duration, Converted.
- Empty state.

- [ ] **Step 6: Create Notifications settings page**

`CallTrackingNotificationsSettings.tsx`:
- Form: webhook_url, auth_method, auth_secret, auth_username, enabled_events multi-select, is_active toggle.
- Test webhook button.

- [ ] **Step 7: Create DNI snippet page**

`CallTrackingDniSnippet.tsx`:
- Display the `<script>` tag to copy with the user's organization slug.
- Brief usage instructions.

- [ ] **Step 8: Register routes and navigation**

In `frontend/src/App.tsx`, add routes under the authenticated layout:
- `/call-tracking/campaigns`
- `/call-tracking/campaigns/:id`
- `/call-tracking/campaigns/:id/numbers`
- `/call-tracking/dashboard`
- `/call-tracking/sessions`
- `/call-tracking/campaigns/:id/notifications`
- `/call-tracking/dni`

In `frontend/src/components/Layout/Sidebar.tsx`, add a "Call Tracking" nav group with items for Campaigns, Dashboard, Sessions, DNI.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/pages/CallTracking/*.tsx frontend/src/App.tsx frontend/src/components/Layout/Sidebar.tsx
git commit -m "feat(call-tracking): add frontend pages"
```

---

## Task 18: Final Verification

- [ ] **Step 1: Run full PHP test suite**

Run: `./run-tests.sh`
Expected: PASS (or only pre-existing failures).

- [ ] **Step 2: Run frontend lint and type-check**

```bash
cd frontend && npm run lint && npm run type-check
```
Expected: PASS

- [ ] **Step 3: Run PHP linter**

```bash
vendor/bin/pint
```
Expected: no unfixable issues.

- [ ] **Step 4: Update memory files**

Create `/.my_agent/memory/call-tracking.md` following the format of other memory files. Include source files, routes, models, services, frontend pages, and related modules.

Update `/.my_agent/memory/_index.md` to add the Call Tracking module row.

Update `/.my_agent/memory/phone-numbers.md` to note the new `call_tracking` routing type.

Update `/.my_agent/memory/voice-routing-engine.md` to list `CallTrackingRoutingStrategy`.

Update `/.my_agent/memory/call-detail-records.md` to note CDR now creates call tracking sessions.

- [ ] **Step 5: Commit memory updates**

```bash
git add .my_agent/memory/_index.md .my_agent/memory/call-tracking.md .my_agent/memory/phone-numbers.md .my_agent/memory/voice-routing-engine.md .my_agent/memory/call-detail-records.md
git commit -m "docs(memory): update call tracking module memory"
```

---

## Plan Self-Review

### Spec Coverage

| Spec Section | Task(s) |
|--------------|---------|
| Data model (campaigns, numbers, sessions, notifications) | Tasks 1, 3, 4 |
| Routing type + voice strategy | Tasks 1, 7 |
| Conversion rules | Tasks 5, 8 |
| CDR session creation | Task 8 |
| Custom webhook notifications | Task 9 |
| Ad-platform placeholders | Task 10 |
| Campaign CRUD API | Task 11 |
| Tracking number API | Task 12 |
| Sessions + analytics API | Task 13 |
| DNI swap + snippet | Task 14 |
| Routes | Task 15 |
| Frontend types/service | Task 16 |
| Frontend pages | Task 17 |
| Memory updates | Task 18 |

### Placeholder Scan

No TBD/TODO/"implement later" placeholders remain. Every task includes exact files, validation rules, test targets, and commit commands.

### Type Consistency Check

- `CallTrackingDestinationType` values are used consistently across enum, model cast, request validation, and resolver.
- `CallTrackingEventType` values are used in enum, notification request validation, and dispatcher.
- Foreign key names (`call_tracking_campaign_id`, `did_number_id`) match across migrations, models, factories, and controllers.
- Route parameter `{campaign}` binds to `CallTrackingCampaign` model.

### Open Risks

1. The destination selector component in the frontend may not support all call-tracking destination types. If `DestinationTypeAndSelector` is insufficient, build a minimal inline selector in the campaign form.
2. `CallTrackingRoutingStrategy::canHandle` returns `true` for all extension types because the strategy is selected by DID routing_type, not by extension type. Ensure `InboundRoutingService::routeDidCall` short-circuits to this strategy before the executor.
3. Analytics queries use `DATE_FORMAT(started_at, ...)`. This is MySQL-specific but matches the production DB. SQLite tests may fail on this syntax; if so, gate the test or use Carbon date casting in the query.

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-06-22-call-tracking-implementation-plan.md`.**

**Two execution options:**

1. **Subagent-Driven (recommended)** — Dispatch a fresh subagent per task (or small groups of tasks), review between tasks, fast iteration.
2. **Inline Execution** — Execute tasks in this session using `executing-plans`, batch execution with checkpoints for review.

**Which approach would you like?**
