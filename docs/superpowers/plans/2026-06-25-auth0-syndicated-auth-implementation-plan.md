# Auth0 Syndicated Signup/Login Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Auth0-powered social signup/login (Google, Facebook, Microsoft, GitHub, X) to OPBX when running in SaaS mode, while keeping the existing email/password flow intact.

**Architecture:** Auth0 acts as the OAuth2 identity broker. The OPBX backend generates Auth0 authorize URLs, validates state/PKCE, exchanges codes, fetches `/userinfo`, and resolves users through a new `user_social_identities` table. New Auth0 users either create an organization or request to join one. Existing password users can link Auth0 identities in Profile settings.

**Tech Stack:** Laravel 12 (PHP 8.4), React 18 + TypeScript, Laravel Sanctum (existing), Laravel HTTP client, Redis for ephemeral state.

---

## Conventions

- All PHP files start with `declare(strict_types=1);`.
- All PHP classes/methods follow PSR-12 and existing OPBX conventions.
- All new backend code is covered by tests in `tests/Unit` or `tests/Feature`.
- Run `./run-tests.sh --filter=Auth0` after backend changes.
- Run `cd frontend && npm run type-check && npm run lint` after frontend changes.
- Run `vendor/bin/pint --dirty` before committing.

---

## Phase 1: SaaS/Auth0 Configuration

### Task 1.1: Add Auth0 config to `config/services.php`

**Files:**
- Modify: `config/services.php`

**Context:** Centralizes Auth0 environment configuration.

- [ ] **Step 1: Add Auth0 section**

Add the following inside the returned array (near the end):

```php
'auth0' => [
    'enabled' => env('OPBX_SAAS_ENABLED', false),
    'domain' => env('AUTH0_DOMAIN'),
    'client_id' => env('AUTH0_CLIENT_ID'),
    'client_secret' => env('AUTH0_CLIENT_SECRET'),
    'redirect_uri' => env('AUTH0_REDIRECT_URI'),
    'providers' => array_filter(explode(',', env('AUTH0_PROVIDERS', ''))),
    'connection_map' => [
        'google' => 'google-oauth2',
        'facebook' => 'facebook',
        'microsoft' => 'windowslive',
        'github' => 'github',
        'x' => 'twitter',
    ],
],
```

- [ ] **Step 2: Add env vars to `.env.example`**

Append to `.env.example`:

```bash
# SaaS Mode
OPBX_SAAS_ENABLED=false

# Auth0 Syndicated Auth (only used when OPBX_SAAS_ENABLED=true)
AUTH0_DOMAIN=
AUTH0_CLIENT_ID=
AUTH0_CLIENT_SECRET=
AUTH0_REDIRECT_URI=https://app.opbx.com/ui/auth/callback
AUTH0_PROVIDERS=google,facebook,microsoft,github,x
```

- [ ] **Step 3: Commit**

```bash
git add config/services.php .env.example
git commit -m "config: add Auth0 SaaS configuration"
```

---

### Task 1.2: Expose SaaS/Auth0 settings via `ApplicationConfig`

**Files:**
- Modify: `app/Services/ApplicationConfig.php`
- Test: `tests/Unit/Services/ApplicationConfigTest.php` (create if missing)

**Context:** Frontend needs to know whether to show Auth0 buttons and which Auth0 domain/client_id to use.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/ApplicationConfigTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ApplicationConfig;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ApplicationConfigTest extends TestCase
{
    public function test_summary_includes_auth0_when_enabled(): void
    {
        Config::set('services.auth0.enabled', true);
        Config::set('services.auth0.domain', 'tenant.us.auth0.com');
        Config::set('services.auth0.client_id', 'client-id');
        Config::set('services.auth0.providers', ['google', 'github']);

        $summary = ApplicationConfig::getConfigurationSummary();

        $this->assertTrue($summary['saas_enabled']);
        $this->assertTrue($summary['auth0']['enabled']);
        $this->assertSame('tenant.us.auth0.com', $summary['auth0']['domain']);
        $this->assertSame('client-id', $summary['auth0']['client_id']);
        $this->assertSame(['google', 'github'], $summary['auth0']['providers']);
    }

    public function test_summary_hides_auth0_when_disabled(): void
    {
        Config::set('services.auth0.enabled', false);

        $summary = ApplicationConfig::getConfigurationSummary();

        $this->assertFalse($summary['saas_enabled']);
        $this->assertFalse($summary['auth0']['enabled']);
        $this->assertArrayNotHasKey('client_secret', $summary['auth0']);
    }
}
```

- [ ] **Step 2: Run the failing test**

```bash
./run-tests.sh --filter=ApplicationConfigTest
```

Expected: 2 failures (`saas_enabled` and `auth0` keys missing).

- [ ] **Step 3: Implement the change**

Modify `app/Services/ApplicationConfig.php`. Add a private helper:

```php
private static function getAuth0Config(): array
{
    $enabled = (bool) config('services.auth0.enabled', false);

    if (! $enabled) {
        return ['enabled' => false];
    }

    return [
        'enabled' => true,
        'domain' => config('services.auth0.domain'),
        'client_id' => config('services.auth0.client_id'),
        'providers' => config('services.auth0.providers', []),
    ];
}
```

Then update `getConfigurationSummary()` to include:

```php
'saas_enabled' => (bool) config('services.auth0.enabled', false),
'auth0' => self::getAuth0Config(),
```

- [ ] **Step 4: Run the test**

```bash
./run-tests.sh --filter=ApplicationConfigTest
```

Expected: 2 passes.

- [ ] **Step 5: Lint**

```bash
docker compose exec app vendor/bin/pint app/Services/ApplicationConfig.php tests/Unit/Services/ApplicationConfigTest.php
```

- [ ] **Step 6: Commit**

```bash
git add app/Services/ApplicationConfig.php tests/Unit/Services/ApplicationConfigTest.php config/services.php .env.example
git commit -m "feat(auth0): expose SaaS/Auth0 config to frontend"
```

---

### Task 1.3: Update frontend config types and context

**Files:**
- Modify: `frontend/src/services/config.service.ts`
- Modify: `frontend/src/context/ConfigContext.tsx`

**Context:** Frontend needs typed access to Auth0 config.

- [ ] **Step 1: Update `ApplicationConfig` type**

In `frontend/src/services/config.service.ts`, update or add:

```typescript
export interface Auth0Config {
  enabled: boolean;
  domain?: string;
  client_id?: string;
  providers?: string[];
}

export interface ApplicationConfig {
  mode: string;
  is_production: boolean;
  has_application_webhook_url: boolean;
  is_valid_configuration: boolean;
  warnings: string[];
  hide_webhook_fields: boolean;
  recaptcha: {
    enabled: boolean;
    site_key?: string;
  };
  saas_enabled: boolean;
  auth0: Auth0Config;
}
```

- [ ] **Step 2: Update `ConfigContext` helpers**

In `frontend/src/context/ConfigContext.tsx`, update the `value` object to include:

```typescript
const value: ConfigContextType = {
  config,
  isLoading,
  error,
  isProduction: config?.is_production ?? false,
  shouldHideWebhookFields: config?.hide_webhook_fields ?? false,
  hasWarnings: (config?.warnings?.length ?? 0) > 0,
  warnings: config?.warnings ?? [],
  isValidConfiguration: config?.is_valid_configuration ?? true,
  saasEnabled: config?.saas_enabled ?? false,
  auth0Config: config?.auth0 ?? { enabled: false },
  refetch: fetchConfig,
};
```

And update the interface:

```typescript
interface ConfigContextType {
  config: ApplicationConfig | null;
  isLoading: boolean;
  error: string | null;
  isProduction: boolean;
  shouldHideWebhookFields: boolean;
  hasWarnings: boolean;
  warnings: string[];
  isValidConfiguration: boolean;
  saasEnabled: boolean;
  auth0Config: Auth0Config;
  refetch: () => Promise<void>;
}
```

- [ ] **Step 3: Run frontend type-check**

```bash
cd frontend && npm run type-check
```

Expected: no new errors.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/services/config.service.ts frontend/src/context/ConfigContext.tsx
git commit -m "feat(auth0): add Auth0 config types to frontend"
```

---

## Phase 2: Database, Models, and Enums

### Task 2.1: Create social identity and join request enums

**Files:**
- Create: `app/Enums/SocialIdentityProvider.php`
- Create: `app/Enums/OrganizationJoinRequestStatus.php`
- Test: `tests/Unit/Enums/SocialIdentityProviderTest.php`
- Test: `tests/Unit/Enums/OrganizationJoinRequestStatusTest.php`

- [ ] **Step 1: Create `SocialIdentityProvider` enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum SocialIdentityProvider: string
{
    case GOOGLE = 'google';
    case FACEBOOK = 'facebook';
    case MICROSOFT = 'microsoft';
    case GITHUB = 'github';
    case X = 'x';

    public function auth0Connection(): string
    {
        return match ($this) {
            self::GOOGLE => 'google-oauth2',
            self::FACEBOOK => 'facebook',
            self::MICROSOFT => 'windowslive',
            self::GITHUB => 'github',
            self::X => 'twitter',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $provider) => $provider->value, self::cases());
    }
}
```

- [ ] **Step 2: Create `OrganizationJoinRequestStatus` enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganizationJoinRequestStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
```

- [ ] **Step 3: Write enum tests**

```php
// tests/Unit/Enums/SocialIdentityProviderTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\SocialIdentityProvider;
use Tests\TestCase;

class SocialIdentityProviderTest extends TestCase
{
    public function test_google_maps_to_auth0_connection(): void
    {
        $this->assertSame('google-oauth2', SocialIdentityProvider::GOOGLE->auth0Connection());
    }

    public function test_x_maps_to_twitter_connection(): void
    {
        $this->assertSame('twitter', SocialIdentityProvider::X->auth0Connection());
    }
}
```

```php
// tests/Unit/Enums/OrganizationJoinRequestStatusTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\OrganizationJoinRequestStatus;
use Tests\TestCase;

class OrganizationJoinRequestStatusTest extends TestCase
{
    public function test_status_values(): void
    {
        $this->assertSame('pending', OrganizationJoinRequestStatus::PENDING->value);
        $this->assertSame('approved', OrganizationJoinRequestStatus::APPROVED->value);
        $this->assertSame('rejected', OrganizationJoinRequestStatus::REJECTED->value);
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./run-tests.sh --filter=SocialIdentityProviderTest
./run-tests.sh --filter=OrganizationJoinRequestStatusTest
```

Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add app/Enums/SocialIdentityProvider.php app/Enums/OrganizationJoinRequestStatus.php \
  tests/Unit/Enums/SocialIdentityProviderTest.php tests/Unit/Enums/OrganizationJoinRequestStatusTest.php
git commit -m "feat(auth0): add social identity and join request enums"
```

---

### Task 2.2: Create migrations

**Files:**
- Create: `database/migrations/2026_06_25_000001_create_user_social_identities_table.php`
- Create: `database/migrations/2026_06_25_000002_create_organization_join_requests_table.php`
- Test: `tests/Feature/Auth0/MigrationsTest.php`

- [ ] **Step 1: Create user_social_identities migration**

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
        Schema::create('user_social_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('provider', 32);
            $table->string('provider_subject', 255);
            $table->string('provider_email', 255)->nullable();
            $table->json('provider_data')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_subject']);
            $table->unique(['user_id', 'provider']);
            $table->index('provider_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_social_identities');
    }
};
```

- [ ] **Step 2: Create organization_join_requests migration**

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
        Schema::create('organization_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('email', 255);
            $table->string('name', 255)->nullable();
            $table->string('provider', 32);
            $table->string('provider_subject', 255);
            $table->string('status', 32)->default('pending');
            $table->string('role', 32)->default('pbx_user');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->unique(['organization_id', 'email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_join_requests');
    }
};
```

- [ ] **Step 3: Write migration test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Auth0;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth0_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('user_social_identities'));
        $this->assertTrue(Schema::hasTable('organization_join_requests'));
    }

    public function test_migrations_can_be_rolled_back(): void
    {
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_06_25_000001_create_user_social_identities_table.php']);
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_06_25_000002_create_organization_join_requests_table.php']);

        $this->assertFalse(Schema::hasTable('user_social_identities'));
        $this->assertFalse(Schema::hasTable('organization_join_requests'));
    }
}
```

- [ ] **Step 4: Run migrations and tests**

```bash
docker compose exec app php artisan migrate
./run-tests.sh --filter=Auth0\\MigrationsTest
```

Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_25_000001_create_user_social_identities_table.php \
  database/migrations/2026_06_25_000002_create_organization_join_requests_table.php \
  tests/Feature/Auth0/MigrationsTest.php
git commit -m "feat(auth0): add social identity and join request migrations"
```

---

### Task 2.3: Create models and factories

**Files:**
- Create: `app/Models/UserSocialIdentity.php`
- Create: `app/Models/OrganizationJoinRequest.php`
- Create: `database/factories/UserSocialIdentityFactory.php`
- Create: `database/factories/OrganizationJoinRequestFactory.php`
- Test: `tests/Unit/Models/UserSocialIdentityTest.php`
- Test: `tests/Unit/Models/OrganizationJoinRequestTest.php`

- [ ] **Step 1: Create `UserSocialIdentity` model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SocialIdentityProvider;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class UserSocialIdentity extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_subject',
        'provider_email',
        'provider_data',
    ];

    protected function casts(): array
    {
        return [
            'provider' => SocialIdentityProvider::class,
            'provider_data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 2: Create `OrganizationJoinRequest` model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationJoinRequestStatus;
use App\Enums\SocialIdentityProvider;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ScopedBy([OrganizationScope::class])]
class OrganizationJoinRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'email',
        'name',
        'provider',
        'provider_subject',
        'status',
        'role',
    ];

    protected function casts(): array
    {
        return [
            'provider' => SocialIdentityProvider::class,
            'status' => OrganizationJoinRequestStatus::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
```

- [ ] **Step 3: Create factories**

```php
// database/factories/UserSocialIdentityFactory.php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SocialIdentityProvider;
use App\Models\User;
use App\Models\UserSocialIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserSocialIdentityFactory extends Factory
{
    protected $model = UserSocialIdentity::class;

    public function definition(): array
    {
        $provider = $this->faker->randomElement(SocialIdentityProvider::cases());

        return [
            'user_id' => User::factory(),
            'provider' => $provider,
            'provider_subject' => $provider->auth0Connection().'|'.$this->faker->uuid(),
            'provider_email' => $this->faker->unique()->safeEmail(),
            'provider_data' => [],
        ];
    }
}
```

```php
// database/factories/OrganizationJoinRequestFactory.php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganizationJoinRequestStatus;
use App\Enums\SocialIdentityProvider;
use App\Models\Organization;
use App\Models\OrganizationJoinRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrganizationJoinRequestFactory extends Factory
{
    protected $model = OrganizationJoinRequest::class;

    public function definition(): array
    {
        $provider = $this->faker->randomElement(SocialIdentityProvider::cases());

        return [
            'organization_id' => Organization::factory(),
            'email' => $this->faker->unique()->safeEmail(),
            'name' => $this->faker->name(),
            'provider' => $provider,
            'provider_subject' => $provider->auth0Connection().'|'.$this->faker->uuid(),
            'status' => OrganizationJoinRequestStatus::PENDING,
            'role' => 'pbx_user',
        ];
    }
}
```

- [ ] **Step 4: Write model tests**

```php
// tests/Unit/Models/UserSocialIdentityTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\UserSocialIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSocialIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_user(): void
    {
        $identity = UserSocialIdentity::factory()->create();

        $this->assertInstanceOf(User::class, $identity->user);
    }
}
```

```php
// tests/Unit/Models/OrganizationJoinRequestTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Organization;
use App\Models\OrganizationJoinRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationJoinRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_organization(): void
    {
        $request = OrganizationJoinRequest::factory()->create();

        $this->assertInstanceOf(Organization::class, $request->organization);
    }
}
```

- [ ] **Step 5: Run tests**

```bash
./run-tests.sh --filter=UserSocialIdentityTest
./run-tests.sh --filter=OrganizationJoinRequestTest
```

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Models/UserSocialIdentity.php app/Models/OrganizationJoinRequest.php \
  database/factories/UserSocialIdentityFactory.php database/factories/OrganizationJoinRequestFactory.php \
  tests/Unit/Models/UserSocialIdentityTest.php tests/Unit/Models/OrganizationJoinRequestTest.php
git commit -m "feat(auth0): add social identity and join request models"
```

---

## Phase 3: Auth0 Service Layer

### Task 3.1: Auth0 config validation

**Files:**
- Create: `app/Services/Auth0/Auth0Config.php`
- Test: `tests/Unit/Services/Auth0/Auth0ConfigTest.php`

- [ ] **Step 1: Create `Auth0Config` value object**

```php
<?php

declare(strict_types=1);

namespace App\Services\Auth0;

use App\Enums\SocialIdentityProvider;
use InvalidArgumentException;

final readonly class Auth0Config
{
    /**
     * @param  array<int, SocialIdentityProvider>  $providers
     */
    public function __construct(
        public string $domain,
        public string $clientId,
        public string $clientSecret,
        public string $redirectUri,
        public array $providers,
        public bool $enabled,
    ) {}

    public static function fromConfig(): self
    {
        $enabled = (bool) config('services.auth0.enabled', false);

        if (! $enabled) {
            return new self('', '', '', '', [], false);
        }

        $domain = (string) config('services.auth0.domain');
        $clientId = (string) config('services.auth0.client_id');
        $clientSecret = (string) config('services.auth0.client_secret');
        $redirectUri = (string) config('services.auth0.redirect_uri');

        if ($domain === '' || $clientId === '' || $clientSecret === '' || $redirectUri === '') {
            throw new InvalidArgumentException('Auth0 is enabled but missing required configuration.');
        }

        $providers = array_filter(
            array_map(
                fn (string $value) => SocialIdentityProvider::tryFrom($value),
                config('services.auth0.providers', [])
            )
        );

        return new self($domain, $clientId, $clientSecret, $redirectUri, array_values($providers), true);
    }

    public function getAuthorizeUrl(): string
    {
        return sprintf('https://%s/authorize', $this->domain);
    }

    public function getTokenUrl(): string
    {
        return sprintf('https://%s/oauth/token', $this->domain);
    }

    public function getUserInfoUrl(): string
    {
        return sprintf('https://%s/userinfo', $this->domain);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
```

- [ ] **Step 2: Write test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth0;

use App\Enums\SocialIdentityProvider;
use App\Services\Auth0\Auth0Config;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class Auth0ConfigTest extends TestCase
{
    public function test_from_config_returns_disabled_when_feature_off(): void
    {
        Config::set('services.auth0.enabled', false);

        $config = Auth0Config::fromConfig();

        $this->assertFalse($config->isEnabled());
    }

    public function test_from_config_parses_providers(): void
    {
        Config::set('services.auth0.enabled', true);
        Config::set('services.auth0.domain', 'tenant.us.auth0.com');
        Config::set('services.auth0.client_id', 'id');
        Config::set('services.auth0.client_secret', 'secret');
        Config::set('services.auth0.redirect_uri', 'https://app.opbx.com/ui/auth/callback');
        Config::set('services.auth0.providers', ['google', 'github']);

        $config = Auth0Config::fromConfig();

        $this->assertTrue($config->isEnabled());
        $this->assertSame('tenant.us.auth0.com', $config->domain);
        $this->assertSame(['google', 'github'], array_map(fn ($p) => $p->value, $config->providers));
        $this->assertSame('https://tenant.us.auth0.com/authorize', $config->getAuthorizeUrl());
    }

    public function test_throws_when_enabled_but_missing_config(): void
    {
        Config::set('services.auth0.enabled', true);
        Config::set('services.auth0.domain', '');

        $this->expectException(InvalidArgumentException::class);

        Auth0Config::fromConfig();
    }
}
```

- [ ] **Step 3: Run tests**

```bash
./run-tests.sh --filter=Auth0ConfigTest
```

Expected: pass.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Auth0/Auth0Config.php tests/Unit/Services/Auth0/Auth0ConfigTest.php
git commit -m "feat(auth0): add Auth0 config value object"
```

---

### Task 3.2: State and PKCE management

**Files:**
- Create: `app/Services/Auth0/Auth0State.php`
- Create: `app/Services/Auth0/Auth0StateStore.php`
- Test: `tests/Unit/Services/Auth0/Auth0StateStoreTest.php`

**Context:** OAuth2 `state` and PKCE `code_verifier` must be stored server-side, validated once, and expire quickly.

- [ ] **Step 1: Create `Auth0State` DTO**

```php
<?php

declare(strict_types=1);

namespace App\Services\Auth0;

final readonly class Auth0State
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $state,
        public string $codeVerifier,
        public array $payload,
    ) {}
}
```

- [ ] **Step 2: Create `Auth0StateStore`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Auth0;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

class Auth0StateStore
{
    private const TTL_SECONDS = 600;

    public function create(string $provider, string $intent, ?int $userId = null): Auth0State
    {
        $state = Str::random(64);
        $codeVerifier = Str::random(128);

        $payload = [
            'provider' => $provider,
            'intent' => $intent,
            'user_id' => $userId,
            'nonce' => Str::random(32),
        ];

        Cache::put($this->key($state), Crypt::encryptString(json_encode([
            'code_verifier' => $codeVerifier,
            'payload' => $payload,
        ])), self::TTL_SECONDS);

        return new Auth0State($state, $codeVerifier, $payload);
    }

    public function consume(string $state): Auth0State
    {
        $encrypted = Cache::pull($this->key($state));

        if ($encrypted === null) {
            throw new RuntimeException('Invalid or expired Auth0 state.');
        }

        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true);
        } catch (\Throwable $e) {
            throw new RuntimeException('Invalid Auth0 state.', previous: $e);
        }

        return new Auth0State(
            $state,
            $decoded['code_verifier'],
            $decoded['payload']
        );
    }

    private function key(string $state): string
    {
        return 'auth0:state:'.$state;
    }
}
```

- [ ] **Step 3: Write test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth0;

use App\Services\Auth0\Auth0StateStore;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class Auth0StateStoreTest extends TestCase
{
    public function test_create_stores_state(): void
    {
        $store = new Auth0StateStore;
        $state = $store->create('google', 'login');

        $this->assertNotEmpty($state->state);
        $this->assertNotEmpty($state->codeVerifier);
        $this->assertSame('google', $state->payload['provider']);
        $this->assertTrue(Cache::has('auth0:state:'.$state->state));
    }

    public function test_consume_returns_state_and_deletes_it(): void
    {
        $store = new Auth0StateStore;
        $created = $store->create('github', 'register');

        $consumed = $store->consume($created->state);

        $this->assertSame($created->state, $consumed->state);
        $this->assertSame($created->codeVerifier, $consumed->codeVerifier);
        $this->assertFalse(Cache::has('auth0:state:'.$created->state));
    }

    public function test_consume_throws_for_missing_state(): void
    {
        $store = new Auth0StateStore;

        $this->expectException(RuntimeException::class);

        $store->consume('invalid-state');
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./run-tests.sh --filter=Auth0StateStoreTest
```

Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Auth0/Auth0State.php app/Services/Auth0/Auth0StateStore.php \
  tests/Unit/Services/Auth0/Auth0StateStoreTest.php
git commit -m "feat(auth0): add OAuth state and PKCE store"
```

---

### Task 3.3: Auth0 HTTP service

**Files:**
- Create: `app/Services/Auth0/Auth0Service.php`
- Create: `app/Services/Auth0/Auth0ProfileNormalizer.php`
- Test: `tests/Unit/Services/Auth0/Auth0ServiceTest.php`

- [ ] **Step 1: Create profile normalizer**

```php
<?php

declare(strict_types=1);

namespace App\Services\Auth0;

use App\Enums\SocialIdentityProvider;

class Auth0ProfileNormalizer
{
    /**
     * @param  SocialIdentityProvider  $provider
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function normalize(SocialIdentityProvider $provider, array $profile): array
    {
        return [
            'subject' => $profile['sub'] ?? '',
            'email' => strtolower((string) ($profile['email'] ?? '')),
            'email_verified' => (bool) ($profile['email_verified'] ?? false),
            'name' => $profile['name'] ?? ($profile['nickname'] ?? ''),
            'picture' => $profile['picture'] ?? null,
            'provider' => $provider,
            'raw' => $profile,
        ];
    }
}
```

- [ ] **Step 2: Create `Auth0Service`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Auth0;

use App\Enums\SocialIdentityProvider;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class Auth0Service
{
    public function __construct(
        private readonly Auth0Config $config,
        private readonly Auth0StateStore $stateStore,
        private readonly Auth0ProfileNormalizer $normalizer,
    ) {}

    public function buildAuthorizeUrl(string $provider, string $intent, ?int $userId = null): array
    {
        $socialProvider = SocialIdentityProvider::tryFrom($provider);

        if ($socialProvider === null || ! $this->isProviderEnabled($socialProvider)) {
            throw new InvalidArgumentException("Unsupported or disabled provider: {$provider}");
        }

        $state = $this->stateStore->create($provider, $intent, $userId);
        $codeChallenge = $this->base64UrlEncode(hash('sha256', $state->codeVerifier, true));

        $url = $this->config->getAuthorizeUrl().'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->config->redirectUri,
            'scope' => 'openid profile email',
            'state' => $state->state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'connection' => $socialProvider->auth0Connection(),
        ]);

        return ['url' => $url, 'state' => $state->state];
    }

    /**
     * @return array<string, mixed>
     */
    public function handleCallback(string $code, string $state): array
    {
        $stored = $this->stateStore->consume($state);

        $tokenResponse = Http::timeout(30)->asForm()->post($this->config->getTokenUrl(), [
            'grant_type' => 'authorization_code',
            'client_id' => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->config->redirectUri,
            'code_verifier' => $stored->codeVerifier,
        ]);

        if ($tokenResponse->failed()) {
            throw new RuntimeException('Auth0 token exchange failed: '.$tokenResponse->body());
        }

        $accessToken = $tokenResponse->json('access_token');

        $userInfoResponse = Http::timeout(30)
            ->withToken($accessToken)
            ->get($this->config->getUserInfoUrl());

        if ($userInfoResponse->failed()) {
            throw new RuntimeException('Auth0 userinfo request failed: '.$userInfoResponse->body());
        }

        $provider = SocialIdentityProvider::tryFrom($stored->payload['provider']);

        if ($provider === null) {
            throw new RuntimeException('Invalid provider in state.');
        }

        return [
            ...$this->normalizer->normalize($provider, $userInfoResponse->json()),
            'intent' => $stored->payload['intent'],
            'user_id' => $stored->payload['user_id'] ?? null,
        ];
    }

    private function isProviderEnabled(SocialIdentityProvider $provider): bool
    {
        return in_array($provider, $this->config->providers, true);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
```

- [ ] **Step 3: Write test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth0;

use App\Services\Auth0\Auth0Config;
use App\Services\Auth0\Auth0ProfileNormalizer;
use App\Services\Auth0\Auth0Service;
use App\Services\Auth0\Auth0StateStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class Auth0ServiceTest extends TestCase
{
    private Auth0Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.auth0.enabled' => true,
            'services.auth0.domain' => 'tenant.us.auth0.com',
            'services.auth0.client_id' => 'client-id',
            'services.auth0.client_secret' => 'client-secret',
            'services.auth0.redirect_uri' => 'https://app.opbx.com/ui/auth/callback',
            'services.auth0.providers' => ['google'],
        ]);

        $this->service = new Auth0Service(
            Auth0Config::fromConfig(),
            new Auth0StateStore,
            new Auth0ProfileNormalizer
        );
    }

    public function test_build_authorize_url_returns_valid_url(): void
    {
        $result = $this->service->buildAuthorizeUrl('google', 'login');

        $this->assertStringContainsString('https://tenant.us.auth0.com/authorize', $result['url']);
        $this->assertStringContainsString('connection=google-oauth2', $result['url']);
        $this->assertStringContainsString('state='.$result['state'], $result['url']);
        $this->assertStringContainsString('code_challenge=', $result['url']);
        $this->assertTrue(Cache::has('auth0:state:'.$result['state']));
    }

    public function test_build_authorize_url_rejects_disabled_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->buildAuthorizeUrl('github', 'login');
    }

    public function test_handle_callback_exchanges_code_and_fetches_profile(): void
    {
        $result = $this->service->buildAuthorizeUrl('google', 'login');
        $state = $result['state'];

        Http::fake([
            'tenant.us.auth0.com/oauth/token' => Http::response(['access_token' => 'token'], 200),
            'tenant.us.auth0.com/userinfo' => Http::response([
                'sub' => 'google-oauth2|123',
                'email' => 'user@example.com',
                'email_verified' => true,
                'name' => 'User',
            ], 200),
        ]);

        $profile = $this->service->handleCallback('auth-code', $state);

        $this->assertSame('google-oauth2|123', $profile['subject']);
        $this->assertSame('user@example.com', $profile['email']);
        $this->assertTrue($profile['email_verified']);
        $this->assertSame('login', $profile['intent']);
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./run-tests.sh --filter=Auth0ServiceTest
```

Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Auth0/Auth0ProfileNormalizer.php app/Services/Auth0/Auth0Service.php \
  tests/Unit/Services/Auth0/Auth0ServiceTest.php
git commit -m "feat(auth0): add Auth0 authorize/callback service"
```

---

## Phase 4: Backend Account Resolution

### Task 4.1: Auth0 account resolver

**Files:**
- Create: `app/Services/Auth0/Auth0AccountResolver.php`
- Test: `tests/Unit/Services/Auth0/Auth0AccountResolverTest.php`

**Context:** Determines what to do with a normalized Auth0 profile: login existing user, create org+user, create join request, or reject.

- [ ] **Step 1: Create `Auth0AccountResolver`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Auth0;

use App\Enums\OrganizationJoinRequestStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\OrganizationJoinRequest;
use App\Models\User;
use App\Models\UserSocialIdentity;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class Auth0AccountResolver
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function resolve(array $profile): array
    {
        if (! ($profile['email_verified'] ?? false)) {
            return ['action' => 'email_unverified'];
        }

        $identity = UserSocialIdentity::withoutGlobalScope(OrganizationScope::class)
            ->where('provider', $profile['provider'])
            ->where('provider_subject', $profile['subject'])
            ->first();

        if ($identity !== null) {
            return ['action' => 'login', 'user' => $identity->user];
        }

        $existingUser = User::withoutGlobalScope(OrganizationScope::class)
            ->where('email', $profile['email'])
            ->first();

        if ($existingUser !== null) {
            return ['action' => 'account_exists', 'user' => $existingUser];
        }

        return ['action' => 'new_user', 'profile' => $profile];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    public function createOrganizationAndUser(array $profile): User
    {
        return OrganizationScope::bypass(function () use ($profile) {
            $organization = Organization::create([
                'name' => $profile['email'].' Organization',
                'slug' => $this->generateSlug($profile['email']),
                'status' => 'active',
                'timezone' => 'UTC',
                'settings' => [],
            ]);

            $user = User::create([
                'organization_id' => $organization->id,
                'name' => $profile['name'] ?: $profile['email'],
                'email' => $profile['email'],
                'password' => null,
                'role' => UserRole::OWNER,
                'status' => UserStatus::ACTIVE,
            ]);

            UserSocialIdentity::create([
                'user_id' => $user->id,
                'provider' => $profile['provider'],
                'provider_subject' => $profile['subject'],
                'provider_email' => $profile['email'],
                'provider_data' => $profile['raw'] ?? [],
            ]);

            return $user;
        });
    }

    public function createJoinRequest(string $organizationSlug, array $profile): OrganizationJoinRequest
    {
        $organization = Organization::where('slug', $organizationSlug)->where('status', 'active')->first();

        if ($organization === null) {
            throw new ModelNotFoundException('Organization not found.');
        }

        return OrganizationJoinRequest::create([
            'organization_id' => $organization->id,
            'email' => $profile['email'],
            'name' => $profile['name'] ?: $profile['email'],
            'provider' => $profile['provider'],
            'provider_subject' => $profile['subject'],
            'status' => OrganizationJoinRequestStatus::PENDING,
            'role' => 'pbx_user',
        ]);
    }

    public function linkIdentity(User $user, array $profile): UserSocialIdentity
    {
        return UserSocialIdentity::create([
            'user_id' => $user->id,
            'provider' => $profile['provider'],
            'provider_subject' => $profile['subject'],
            'provider_email' => $profile['email'],
            'provider_data' => $profile['raw'] ?? [],
        ]);
    }

    private function generateSlug(string $email): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]+/', '-', explode('@', $email)[0]));
        $base = trim($base, '-');
        $suffix = '';
        $counter = 1;

        while (Organization::withoutGlobalScope(OrganizationScope::class)->where('slug', $base.$suffix)->exists()) {
            $suffix = '-'.$counter;
            $counter++;
        }

        return $base.$suffix;
    }
}
```

- [ ] **Step 2: Write test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth0;

use App\Enums\SocialIdentityProvider;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserSocialIdentity;
use App\Scopes\OrganizationScope;
use App\Services\Auth0\Auth0AccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Auth0AccountResolverTest extends TestCase
{
    use RefreshDatabase;

    private Auth0AccountResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new Auth0AccountResolver;
    }

    public function test_resolve_returns_login_for_existing_identity(): void
    {
        $identity = UserSocialIdentity::factory()->create();
        $profile = [
            'provider' => $identity->provider->value,
            'subject' => $identity->provider_subject,
            'email_verified' => true,
        ];

        $result = $this->resolver->resolve($profile);

        $this->assertSame('login', $result['action']);
        $this->assertTrue($identity->user->is($result['user']));
    }

    public function test_resolve_returns_account_exists_for_existing_email(): void
    {
        $user = User::factory()->create();
        $profile = [
            'provider' => SocialIdentityProvider::GOOGLE->value,
            'subject' => 'google-oauth2|999',
            'email' => $user->email,
            'email_verified' => true,
        ];

        $result = $this->resolver->resolve($profile);

        $this->assertSame('account_exists', $result['action']);
        $this->assertTrue($user->is($result['user']));
    }

    public function test_resolve_returns_new_user_for_unknown_profile(): void
    {
        $profile = [
            'provider' => SocialIdentityProvider::GOOGLE->value,
            'subject' => 'google-oauth2|999',
            'email' => 'new@example.com',
            'email_verified' => true,
        ];

        $result = $this->resolver->resolve($profile);

        $this->assertSame('new_user', $result['action']);
    }

    public function test_create_organization_and_user_creates_owner(): void
    {
        $profile = [
            'provider' => SocialIdentityProvider::GOOGLE->value,
            'subject' => 'google-oauth2|999',
            'email' => 'owner@example.com',
            'name' => 'Owner',
            'email_verified' => true,
            'raw' => [],
        ];

        $user = $this->resolver->createOrganizationAndUser($profile);

        $this->assertSame('owner@example.com', $user->email);
        $this->assertTrue($user->isOwner());
        $this->assertInstanceOf(Organization::class, OrganizationScope::bypass(fn () => $user->organization));
        $this->assertDatabaseHas('user_social_identities', [
            'user_id' => $user->id,
            'provider_subject' => 'google-oauth2|999',
        ]);
    }
}
```

- [ ] **Step 3: Run tests**

```bash
./run-tests.sh --filter=Auth0AccountResolverTest
```

Expected: pass.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Auth0/Auth0AccountResolver.php tests/Unit/Services/Auth0/Auth0AccountResolverTest.php
git commit -m "feat(auth0): add account resolver for login/signup/linking"
```

---

## Phase 5: Backend API Controllers

### Task 5.1: Auth0 redirect endpoint

**Files:**
- Create: `app/Http/Controllers/Api/Auth0Controller.php` (initially with redirect only)
- Create: `app/Http/Requests/Auth0/RedirectRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/Auth0ControllerRedirectTest.php`

- [ ] **Step 1: Create `RedirectRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth0;

use App\Enums\SocialIdentityProvider;
use Illuminate\Foundation\Http\FormRequest;

class RedirectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'in:'.implode(',', SocialIdentityProvider::values())],
            'intent' => ['required', 'string', 'in:login,register,link'],
        ];
    }
}
```

- [ ] **Step 2: Create `Auth0Controller::redirect`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth0\RedirectRequest;
use App\Services\Auth0\Auth0Config;
use App\Services\Auth0\Auth0Service;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class Auth0Controller extends Controller
{
    public function __construct(
        private readonly Auth0Service $auth0Service,
    ) {}

    public function redirect(RedirectRequest $request): JsonResponse
    {
        $config = Auth0Config::fromConfig();

        if (! $config->isEnabled()) {
            return response()->json([
                'error' => ['code' => 'AUTH0_NOT_CONFIGURED', 'message' => 'Auth0 is not enabled.'],
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $result = $this->auth0Service->buildAuthorizeUrl(
                $request->input('provider'),
                $request->input('intent'),
                $request->user()?->id
            );

            return response()->json([
                'redirect_url' => $result['url'],
                'state' => $result['state'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => ['code' => 'AUTH0_INVALID_PROVIDER', 'message' => $e->getMessage()],
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
```

- [ ] **Step 3: Register routes**

In `routes/api.php`, inside the public/auth route group (near existing `/v1/auth/login`):

```php
Route::post('/auth/auth0/redirect', [Auth0Controller::class, 'redirect'])
    ->name('auth.auth0.redirect')
    ->middleware('throttle:auth');
```

- [ ] **Step 4: Write test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class Auth0ControllerRedirectTest extends TestCase
{
    public function test_redirect_returns_auth0_url_when_enabled(): void
    {
        Config::set('services.auth0.enabled', true);
        Config::set('services.auth0.domain', 'tenant.us.auth0.com');
        Config::set('services.auth0.client_id', 'id');
        Config::set('services.auth0.client_secret', 'secret');
        Config::set('services.auth0.redirect_uri', 'https://app.opbx.com/ui/auth/callback');
        Config::set('services.auth0.providers', ['google']);

        $response = $this->postJson('/v1/auth/auth0/redirect', [
            'provider' => 'google',
            'intent' => 'login',
        ]);

        $response->assertOk();
        $response->assertJsonPath('redirect_url', fn ($url) => str_contains($url, 'https://tenant.us.auth0.com/authorize'));
    }

    public function test_redirect_returns_503_when_disabled(): void
    {
        Config::set('services.auth0.enabled', false);

        $response = $this->postJson('/v1/auth/auth0/redirect', [
            'provider' => 'google',
            'intent' => 'login',
        ]);

        $response->assertStatus(503);
        $response->assertJsonPath('error.code', 'AUTH0_NOT_CONFIGURED');
    }
}
```

- [ ] **Step 5: Run tests**

```bash
./run-tests.sh --filter=Auth0ControllerRedirectTest
```

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Auth0Controller.php app/Http/Requests/Auth0/RedirectRequest.php \
  routes/api.php tests/Feature/Api/Auth0ControllerRedirectTest.php
git commit -m "feat(auth0): add Auth0 redirect endpoint"
```

---

### Task 5.2: Auth0 callback endpoint

**Files:**
- Modify: `app/Http/Controllers/Api/Auth0Controller.php` (add callback)
- Test: `tests/Feature/Api/Auth0ControllerCallbackTest.php`

- [ ] **Step 1: Add `issueToken` helper to controller**

Use the same token abilities logic as `AuthController`. Add to `Auth0Controller`:

```php
/**
 * @param  array<int, string>  $abilities
 */
private function issueToken(User $user, string $name, array $abilities, int $expiresMinutes): string
{
    return $user->createToken($name, $abilities, now()->addMinutes($expiresMinutes))->plainTextToken;
}

/**
 * @return array<int, string>
 */
private function getTokenAbilities(User $user): array
{
    return match ($user->role->value) {
        'owner' => [
            'extension:*',
            'user:*',
            'ring-group:*',
            'did-number:*',
            'recording:*',
            'settings:*',
            'business-hours:*',
            'conference:*',
            'ivr:*',
            'voice-agent:*',
            'call-log:*',
            'outbound-whitelist:*',
            'recording-download:*',
        ],
        'pbx_admin' => [
            'extension:*',
            'user:read',
            'user:update',
            'ring-group:*',
            'did-number:*',
            'recording:read',
            'business-hours:*',
            'conference:*',
            'ivr:*',
            'call-log:*',
        ],
        'pbx_user' => [
            'extension:read',
            'extension:update:own',
            'user:read',
            'ring-group:read',
            'did-number:read',
            'recording:read',
            'call-log:read',
        ],
        'reporter' => [
            'extension:read',
            'user:read',
            'ring-group:read',
            'did-number:read',
            'recording:read',
            'call-log:read',
            'business-hours:read',
        ],
        default => [],
    };
}
```

- [ ] **Step 2: Add `callback` method**

```php
public function callback(Request $request): JsonResponse
{
    $config = Auth0Config::fromConfig();

    if (! $config->isEnabled()) {
        return response()->json([
            'error' => ['code' => 'AUTH0_NOT_CONFIGURED', 'message' => 'Auth0 is not enabled.'],
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    $code = $request->query('code');
    $state = $request->query('state');

    if (! is_string($code) || ! is_string($state)) {
        return response()->json([
            'error' => ['code' => 'AUTH0_INVALID_CALLBACK', 'message' => 'Missing code or state.'],
        ], Response::HTTP_BAD_REQUEST);
    }

    try {
        $profile = $this->auth0Service->handleCallback($code, $state);
    } catch (\RuntimeException $e) {
        return response()->json([
            'error' => ['code' => 'AUTH0_INVALID_STATE', 'message' => $e->getMessage()],
        ], Response::HTTP_BAD_REQUEST);
    }

    $resolver = app(Auth0AccountResolver::class);
    $resolution = $resolver->resolve($profile);

    return match ($resolution['action']) {
        'email_unverified' => response()->json([
            'error' => ['code' => 'AUTH0_EMAIL_UNVERIFIED', 'message' => 'Please verify your email with the provider before continuing.'],
        ], Response::HTTP_UNPROCESSABLE_ENTITY),

        'account_exists' => response()->json([
            'error' => ['code' => 'AUTH0_ACCOUNT_EXISTS', 'message' => 'An account with this email already exists. Please log in with your password and link this account in Profile settings.'],
        ], Response::HTTP_CONFLICT),

        'login' => $this->buildAuthResponse($resolution['user']),

        'new_user' => $this->handleNewUser($profile),

        default => response()->json([
            'error' => ['code' => 'AUTH0_RESOLUTION_FAILED', 'message' => 'Unable to process Auth0 login.'],
        ], Response::HTTP_INTERNAL_SERVER_ERROR),
    };
}

/**
 * @param  array<string, mixed>  $profile
 */
private function handleNewUser(array $profile): JsonResponse
{
    if (($profile['intent'] ?? '') === 'register') {
        $user = app(Auth0AccountResolver::class)->createOrganizationAndUser($profile);

        return $this->buildAuthResponse($user);
    }

    return response()->json([
        'error' => ['code' => 'AUTH0_REGISTRATION_REQUIRED', 'message' => 'Please complete onboarding.'],
        'profile' => [
            'email' => $profile['email'],
            'name' => $profile['name'],
            'provider' => $profile['provider'],
            'subject' => $profile['subject'],
        ],
    ], Response::HTTP_CONFLICT);
}

private function buildAuthResponse(User $user): JsonResponse
{
    if ($user->status !== UserStatus::ACTIVE) {
        return response()->json([
            'error' => ['code' => 'ACCOUNT_INACTIVE', 'message' => 'Account is not active.'],
        ], Response::HTTP_FORBIDDEN);
    }

    $token = $this->issueToken($user, 'auth0-token', $this->getTokenAbilities($user), 1440);

    return response()->json([
        'user' => [
            'id' => $user->id,
            'organization_id' => $user->organization_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'status' => $user->status->value,
        ],
        'organization' => [
            'id' => $user->organization->id,
            'name' => $user->organization->name,
            'slug' => $user->organization->slug,
            'status' => $user->organization->status,
            'timezone' => $user->organization->timezone,
        ],
        'access_token' => $token,
        'token_type' => 'Bearer',
        'expires_in' => 86400,
    ]);
}
```

- [ ] **Step 3: Register route**

In `routes/api.php`:

```php
Route::get('/auth/auth0/callback', [Auth0Controller::class, 'callback'])
    ->name('auth.auth0.callback')
    ->middleware('throttle:auth');
```

- [ ] **Step 4: Write callback tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\SocialIdentityProvider;
use App\Models\User;
use App\Models\UserSocialIdentity;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Auth0ControllerCallbackTest extends TestCase
{
    private function enableAuth0(): void
    {
        Config::set('services.auth0.enabled', true);
        Config::set('services.auth0.domain', 'tenant.us.auth0.com');
        Config::set('services.auth0.client_id', 'id');
        Config::set('services.auth0.client_secret', 'secret');
        Config::set('services.auth0.redirect_uri', 'https://app.opbx.com/ui/auth/callback');
        Config::set('services.auth0.providers', ['google']);
    }

    private function fakeAuth0Responses(string $email = 'user@example.com'): string
    {
        $redirect = $this->postJson('/v1/auth/auth0/redirect', [
            'provider' => 'google',
            'intent' => 'login',
        ]);

        $state = $redirect->json('state');

        Http::fake([
            'tenant.us.auth0.com/oauth/token' => Http::response(['access_token' => 'token'], 200),
            'tenant.us.auth0.com/userinfo' => Http::response([
                'sub' => 'google-oauth2|123',
                'email' => $email,
                'email_verified' => true,
                'name' => 'Test User',
            ], 200),
        ]);

        return $state;
    }

    public function test_callback_logs_in_existing_identity(): void
    {
        $this->enableAuth0();
        $identity = UserSocialIdentity::factory()->create([
            'provider' => SocialIdentityProvider::GOOGLE,
            'provider_subject' => 'google-oauth2|123',
        ]);
        $state = $this->fakeAuth0Responses($identity->provider_email);

        $response = $this->getJson("/v1/auth/auth0/callback?code=valid&state={$state}");

        $response->assertOk();
        $response->assertJsonPath('user.id', $identity->user->id);
        $response->assertJsonPath('access_token', fn ($t) => ! empty($t));
    }

    public function test_callback_returns_registration_required_for_new_user(): void
    {
        $this->enableAuth0();
        $state = $this->fakeAuth0Responses('new@example.com');

        $response = $this->getJson("/v1/auth/auth0/callback?code=valid&state={$state}");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'AUTH0_REGISTRATION_REQUIRED');
    }

    public function test_callback_creates_organization_when_intent_is_register(): void
    {
        $this->enableAuth0();
        $redirect = $this->postJson('/v1/auth/auth0/redirect', [
            'provider' => 'google',
            'intent' => 'register',
        ]);
        $state = $redirect->json('state');

        Http::fake([
            'tenant.us.auth0.com/oauth/token' => Http::response(['access_token' => 'token'], 200),
            'tenant.us.auth0.com/userinfo' => Http::response([
                'sub' => 'google-oauth2|123',
                'email' => 'newowner@example.com',
                'email_verified' => true,
                'name' => 'New Owner',
            ], 200),
        ]);

        $response = $this->getJson("/v1/auth/auth0/callback?code=valid&state={$state}");

        $response->assertOk();
        $response->assertJsonPath('user.role', 'owner');
        $this->assertDatabaseHas('organizations', ['name' => 'New Owner']);
    }

    public function test_callback_rejects_unverified_email(): void
    {
        $this->enableAuth0();
        $redirect = $this->postJson('/v1/auth/auth0/redirect', [
            'provider' => 'google',
            'intent' => 'login',
        ]);
        $state = $redirect->json('state');

        Http::fake([
            'tenant.us.auth0.com/oauth/token' => Http::response(['access_token' => 'token'], 200),
            'tenant.us.auth0.com/userinfo' => Http::response([
                'sub' => 'google-oauth2|123',
                'email' => 'unverified@example.com',
                'email_verified' => false,
            ], 200),
        ]);

        $response = $this->getJson("/v1/auth/auth0/callback?code=valid&state={$state}");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'AUTH0_EMAIL_UNVERIFIED');
    }
}
```

- [ ] **Step 5: Run tests**

```bash
./run-tests.sh --filter=Auth0ControllerCallbackTest
```

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Auth0Controller.php routes/api.php tests/Feature/Api/Auth0ControllerCallbackTest.php
git commit -m "feat(auth0): add Auth0 callback endpoint"
```

---

### Task 5.3: Account linking endpoints

**Files:**
- Modify: `app/Http/Controllers/Api/Auth0Controller.php`
- Create: `app/Http/Requests/Auth0/LinkRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/Auth0ControllerLinkTest.php`

- [ ] **Step 1: Create `LinkRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth0;

use App\Enums\SocialIdentityProvider;
use Illuminate\Foundation\Http\FormRequest;

class LinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'in:'.implode(',', SocialIdentityProvider::values())],
        ];
    }
}
```

- [ ] **Step 2: Add link/unlink methods to controller**

```php
public function initiateLink(LinkRequest $request): JsonResponse
{
    $config = Auth0Config::fromConfig();

    if (! $config->isEnabled()) {
        return response()->json([
            'error' => ['code' => 'AUTH0_NOT_CONFIGURED', 'message' => 'Auth0 is not enabled.'],
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    $result = $this->auth0Service->buildAuthorizeUrl(
        $request->input('provider'),
        'link',
        $request->user()->id
    );

    return response()->json([
        'redirect_url' => $result['url'],
        'state' => $result['state'],
    ]);
}

public function unlink(LinkRequest $request): JsonResponse
{
    $request->user()->socialIdentities()
        ->where('provider', $request->input('provider'))
        ->delete();

    return response()->json(['message' => 'Identity unlinked.']);
}
```

- [ ] **Step 3: Update callback to handle link intent**

Inside `callback()`, before resolving, check if `intent === 'link'` and user is authenticated:

```php
if (($profile['intent'] ?? '') === 'link' && ($profile['user_id'] ?? null) !== null) {
    $user = User::find($profile['user_id']);

    if ($user === null || $user->email !== $profile['email']) {
        return response()->json([
            'error' => ['code' => 'AUTH0_LINK_EMAIL_MISMATCH', 'message' => 'Auth0 email does not match your account email.'],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    app(Auth0AccountResolver::class)->linkIdentity($user, $profile);

    return response()->json(['message' => 'Identity linked successfully.']);
}
```

Also add the `socialIdentities()` relationship to `User` model:

```php
public function socialIdentities(): HasMany
{
    return $this->hasMany(UserSocialIdentity::class);
}
```

- [ ] **Step 4: Register routes**

```php
Route::middleware(['auth:sanctum', 'throttle:auth'])->group(function () {
    Route::post('/auth/auth0/link', [Auth0Controller::class, 'initiateLink'])->name('auth.auth0.link');
    Route::post('/auth/auth0/unlink', [Auth0Controller::class, 'unlink'])->name('auth.auth0.unlink');
});
```

- [ ] **Step 5: Write tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Auth0ControllerLinkTest extends TestCase
{
    private function enableAuth0(): void
    {
        Config::set('services.auth0.enabled', true);
        Config::set('services.auth0.domain', 'tenant.us.auth0.com');
        Config::set('services.auth0.client_id', 'id');
        Config::set('services.auth0.client_secret', 'secret');
        Config::set('services.auth0.redirect_uri', 'https://app.opbx.com/ui/auth/callback');
        Config::set('services.auth0.providers', ['google']);
    }

    public function test_authenticated_user_can_initiate_link(): void
    {
        $this->enableAuth0();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/v1/auth/auth0/link', ['provider' => 'google']);

        $response->assertOk();
        $response->assertJsonPath('redirect_url', fn ($url) => str_contains($url, 'connection=google-oauth2'));
    }

    public function test_callback_links_identity_when_emails_match(): void
    {
        $this->enableAuth0();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $redirect = $this->postJson('/v1/auth/auth0/link', ['provider' => 'google']);
        $state = $redirect->json('state');

        Http::fake([
            'tenant.us.auth0.com/oauth/token' => Http::response(['access_token' => 'token'], 200),
            'tenant.us.auth0.com/userinfo' => Http::response([
                'sub' => 'google-oauth2|123',
                'email' => $user->email,
                'email_verified' => true,
            ], 200),
        ]);

        $response = $this->getJson("/v1/auth/auth0/callback?code=valid&state={$state}");

        $response->assertOk();
        $this->assertDatabaseHas('user_social_identities', [
            'user_id' => $user->id,
            'provider_subject' => 'google-oauth2|123',
        ]);
    }
}
```

- [ ] **Step 6: Run tests**

```bash
./run-tests.sh --filter=Auth0ControllerLinkTest
```

Expected: pass.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Auth0Controller.php app/Http/Requests/Auth0/LinkRequest.php \
  app/Models/User.php routes/api.php tests/Feature/Api/Auth0ControllerLinkTest.php
git commit -m "feat(auth0): add Auth0 account linking"
```

---

### Task 5.4: Organization join request endpoints

**Files:**
- Create: `app/Http/Controllers/Api/OrganizationJoinRequestController.php`
- Create: `app/Http/Requests/OrganizationJoinRequest/StoreRequest.php`
- Create: `app/Policies/OrganizationJoinRequestPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php` (register policy)
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/OrganizationJoinRequestControllerTest.php`

- [ ] **Step 1: Create policy**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\OrganizationJoinRequest;
use App\Models\User;

class OrganizationJoinRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::OWNER, UserRole::PBX_ADMIN], true);
    }

    public function approve(User $user, OrganizationJoinRequest $request): bool
    {
        return $user->organization_id === $request->organization_id
            && in_array($user->role, [UserRole::OWNER, UserRole::PBX_ADMIN], true);
    }

    public function reject(User $user, OrganizationJoinRequest $request): bool
    {
        return $this->approve($user, $request);
    }
}
```

- [ ] **Step 2: Register policy in `AuthServiceProvider`**

Add to the `$policies` array:

```php
App\Models\OrganizationJoinRequest::class => App\Policies\OrganizationJoinRequestPolicy::class,
```

- [ ] **Step 3: Create `StoreRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationJoinRequest;

use App\Enums\SocialIdentityProvider;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization_slug' => ['required', 'string', 'exists:organizations,slug'],
            'provider' => ['required', 'string', 'in:'.implode(',', SocialIdentityProvider::values())],
            'provider_subject' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 4: Create controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OrganizationJoinRequestStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationJoinRequest\StoreRequest;
use App\Models\Organization;
use App\Models\OrganizationJoinRequest;
use App\Models\User;
use App\Models\UserSocialIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrganizationJoinRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OrganizationJoinRequest::class);

        $requests = OrganizationJoinRequest::where('organization_id', Auth::user()->organization_id)
            ->where('status', OrganizationJoinRequestStatus::PENDING)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($requests);
    }

    public function store(StoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $organization = Organization::where('slug', $validated['organization_slug'])
            ->where('status', 'active')
            ->firstOrFail();

        $joinRequest = OrganizationJoinRequest::create([
            'organization_id' => $organization->id,
            'email' => strtolower($validated['email']),
            'name' => $validated['name'] ?? $validated['email'],
            'provider' => $validated['provider'],
            'provider_subject' => $validated['provider_subject'],
            'status' => OrganizationJoinRequestStatus::PENDING,
            'role' => 'pbx_user',
        ]);

        return response()->json($joinRequest, 201);
    }

    public function approve(OrganizationJoinRequest $joinRequest): JsonResponse
    {
        $this->authorize('approve', $joinRequest);

        if ($joinRequest->status !== OrganizationJoinRequestStatus::PENDING) {
            return response()->json([
                'error' => ['code' => 'JOIN_REQUEST_NOT_PENDING', 'message' => 'Request is not pending.'],
            ], 422);
        }

        $user = User::create([
            'organization_id' => $joinRequest->organization_id,
            'name' => $joinRequest->name,
            'email' => $joinRequest->email,
            'password' => null,
            'role' => UserRole::PBX_USER,
            'status' => UserStatus::ACTIVE,
        ]);

        UserSocialIdentity::create([
            'user_id' => $user->id,
            'provider' => $joinRequest->provider,
            'provider_subject' => $joinRequest->provider_subject,
            'provider_email' => $joinRequest->email,
            'provider_data' => [],
        ]);

        $joinRequest->update(['status' => OrganizationJoinRequestStatus::APPROVED]);

        return response()->json([
            'message' => 'Join request approved.',
            'user' => $user,
        ]);
    }

    public function reject(OrganizationJoinRequest $joinRequest): JsonResponse
    {
        $this->authorize('reject', $joinRequest);

        $joinRequest->update(['status' => OrganizationJoinRequestStatus::REJECTED]);

        return response()->json(['message' => 'Join request rejected.']);
    }
}
```

- [ ] **Step 5: Register routes**

```php
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/organizations/join-requests', [OrganizationJoinRequestController::class, 'index'])->name('join-requests.index');
    Route::post('/organizations/join-requests/{joinRequest}/approve', [OrganizationJoinRequestController::class, 'approve'])->name('join-requests.approve');
    Route::post('/organizations/join-requests/{joinRequest}/reject', [OrganizationJoinRequestController::class, 'reject'])->name('join-requests.reject');
});

Route::post('/organizations/join-requests', [OrganizationJoinRequestController::class, 'store'])
    ->name('join-requests.store')
    ->middleware('throttle:auth');
```

- [ ] **Step 6: Write tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\OrganizationJoinRequestStatus;
use App\Models\Organization;
use App\Models\OrganizationJoinRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationJoinRequestControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_list_pending_requests(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($owner);
        OrganizationJoinRequest::factory()->create([
            'organization_id' => $owner->organization_id,
            'status' => OrganizationJoinRequestStatus::PENDING,
        ]);

        $response = $this->getJson('/v1/organizations/join-requests');

        $response->assertOk();
        $response->assertJsonCount(1);
    }

    public function test_pbx_user_cannot_list_requests(): void
    {
        $user = User::factory()->create(['role' => 'pbx_user']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/v1/organizations/join-requests');

        $response->assertForbidden();
    }

    public function test_store_creates_pending_request(): void
    {
        $organization = Organization::factory()->create(['status' => 'active']);

        $response = $this->postJson('/v1/organizations/join-requests', [
            'organization_slug' => $organization->slug,
            'provider' => 'google',
            'provider_subject' => 'google-oauth2|123',
            'email' => 'applicant@example.com',
            'name' => 'Applicant',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('organization_join_requests', [
            'organization_id' => $organization->id,
            'email' => 'applicant@example.com',
            'status' => 'pending',
        ]);
    }

    public function test_owner_can_approve_request(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($owner);
        $request = OrganizationJoinRequest::factory()->create([
            'organization_id' => $owner->organization_id,
        ]);

        $response = $this->postJson("/v1/organizations/join-requests/{$request->id}/approve");

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'email' => $request->email,
            'organization_id' => $owner->organization_id,
            'role' => 'pbx_user',
        ]);
        $this->assertDatabaseHas('organization_join_requests', [
            'id' => $request->id,
            'status' => 'approved',
        ]);
    }
}
```

- [ ] **Step 7: Run tests**

```bash
./run-tests.sh --filter=OrganizationJoinRequestControllerTest
```

Expected: pass.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/OrganizationJoinRequestController.php \
  app/Http/Requests/OrganizationJoinRequest/StoreRequest.php \
  app/Policies/OrganizationJoinRequestPolicy.php \
  app/Providers/AuthServiceProvider.php routes/api.php \
  tests/Feature/Api/OrganizationJoinRequestControllerTest.php
git commit -m "feat(auth0): add organization join request endpoints"
```

---

## Phase 6: Frontend Integration

### Task 6.1: Auth0 service and types

**Files:**
- Create: `frontend/src/services/auth0.service.ts`
- Modify: `frontend/src/types/api.types.ts`

- [ ] **Step 1: Create `auth0.service.ts`**

```typescript
import api from '@/services/api';

export interface Auth0RedirectResponse {
  redirect_url: string;
  state: string;
}

export interface Auth0CallbackResponse {
  user: {
    id: number;
    organization_id: number;
    name: string;
    email: string;
    role: string;
    status: string;
  };
  organization: {
    id: number;
    name: string;
    slug: string;
    status: string;
    timezone: string;
  };
  access_token: string;
  token_type: string;
  expires_in: number;
}

export interface Auth0RegistrationRequired {
  error: { code: 'AUTH0_REGISTRATION_REQUIRED'; message: string };
  profile: {
    email: string;
    name: string;
    provider: string;
    subject: string;
  };
}

export interface Auth0Error {
  error: { code: string; message: string };
}

export const auth0Service = {
  redirect(provider: string, intent: 'login' | 'register' | 'link'): Promise<Auth0RedirectResponse> {
    return api.post('/v1/auth/auth0/redirect', { provider, intent }).then((res) => res.data);
  },

  callback(code: string, state: string): Promise<Auth0CallbackResponse | Auth0RegistrationRequired | Auth0Error> {
    return api.get('/v1/auth/auth0/callback', { params: { code, state } }).then((res) => res.data);
  },

  initiateLink(provider: string): Promise<Auth0RedirectResponse> {
    return api.post('/v1/auth/auth0/link', { provider }).then((res) => res.data);
  },

  unlink(provider: string): Promise<{ message: string }> {
    return api.post('/v1/auth/auth0/unlink', { provider }).then((res) => res.data);
  },

  submitJoinRequest(data: {
    organization_slug: string;
    provider: string;
    provider_subject: string;
    email: string;
    name: string;
  }): Promise<unknown> {
    return api.post('/v1/organizations/join-requests', data).then((res) => res.data);
  },
};
```

- [ ] **Step 2: Update `api.types.ts`**

Add the new service exports to `frontend/src/types/api.types.ts` if it aggregates service types; otherwise, import directly from `auth0.service.ts`.

- [ ] **Step 3: Run type-check**

```bash
cd frontend && npm run type-check
```

Expected: pass.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/services/auth0.service.ts
git commit -m "feat(auth0): add frontend Auth0 service"
```

---

### Task 6.2: Provider buttons on Login/Register pages

**Files:**
- Create: `frontend/src/components/Auth/SocialAuthButtons.tsx`
- Modify: `frontend/src/pages/Login.tsx`
- Modify: `frontend/src/pages/Register.tsx`

- [ ] **Step 1: Create `SocialAuthButtons.tsx`**

```tsx
import { Button } from '@/components/ui/button';
import { useConfig } from '@/context/ConfigContext';
import { auth0Service } from '@/services/auth0.service';
import { toast } from 'sonner';

const PROVIDERS = [
  { key: 'google', label: 'Google' },
  { key: 'facebook', label: 'Facebook' },
  { key: 'microsoft', label: 'Microsoft' },
  { key: 'github', label: 'GitHub' },
  { key: 'x', label: 'X' },
];

interface SocialAuthButtonsProps {
  intent: 'login' | 'register';
}

export function SocialAuthButtons({ intent }: SocialAuthButtonsProps) {
  const { saasEnabled, auth0Config } = useConfig();

  if (!saasEnabled || !auth0Config.enabled || !auth0Config.providers?.length) {
    return null;
  }

  const enabledProviders = PROVIDERS.filter((p) => auth0Config.providers?.includes(p.key));

  const handleClick = async (provider: string) => {
    try {
      const { redirect_url } = await auth0Service.redirect(provider, intent);
      window.location.href = redirect_url;
    } catch (error) {
      toast.error('Failed to start social login. Please try again.');
    }
  };

  return (
    <div className="space-y-3">
      <div className="relative">
        <div className="absolute inset-0 flex items-center">
          <span className="w-full border-t" />
        </div>
        <div className="relative flex justify-center text-xs uppercase">
          <span className="bg-background px-2 text-muted-foreground">Or continue with</span>
        </div>
      </div>
      <div className="grid grid-cols-2 gap-3">
        {enabledProviders.map((provider) => (
          <Button
            key={provider.key}
            variant="outline"
            onClick={() => handleClick(provider.key)}
            type="button"
          >
            {provider.label}
          </Button>
        ))}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Add to Login and Register pages**

In `Login.tsx`, inside the card content after the password form submit button:

```tsx
<SocialAuthButtons intent="login" />
```

In `Register.tsx`, add:

```tsx
<SocialAuthButtons intent="register" />
```

- [ ] **Step 3: Run type-check and lint**

```bash
cd frontend && npm run type-check && npm run lint
```

Expected: pass.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/Auth/SocialAuthButtons.tsx frontend/src/pages/Login.tsx frontend/src/pages/Register.tsx
git commit -m "feat(auth0): add social provider buttons to login and register"
```

---

### Task 6.3: Auth0 callback and onboarding pages

**Files:**
- Create: `frontend/src/pages/Auth0Callback.tsx`
- Create: `frontend/src/pages/Auth0Onboarding.tsx`
- Modify: `frontend/src/App.tsx` or router config
- Modify: `frontend/src/context/AuthContext.tsx`

- [ ] **Step 1: Create `Auth0Callback.tsx`**

```tsx
import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { auth0Service } from '@/services/auth0.service';
import { useAuth } from '@/hooks/useAuth';
import { storage } from '@/utils/storage';
import { toast } from 'sonner';

export default function Auth0Callback() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { setUser, setToken } = useAuth();
  const [status, setStatus] = useState('Processing login...');

  useEffect(() => {
    const code = searchParams.get('code');
    const state = searchParams.get('state');

    if (!code || !state) {
      toast.error('Invalid callback.');
      navigate('/ui/login');
      return;
    }

    auth0Service
      .callback(code, state)
      .then((data) => {
        if ('error' in data) {
          const code = data.error.code;

          if (code === 'AUTH0_REGISTRATION_REQUIRED') {
            navigate(
              `/ui/auth/onboarding?email=${encodeURIComponent(data.profile.email)}&provider=${data.profile.provider}&subject=${data.profile.subject}&name=${encodeURIComponent(data.profile.name)}`
            );
            return;
          }

          if (code === 'JOIN_REQUEST_PENDING') {
            setStatus('Your request to join the organization is pending approval.');
            return;
          }

          toast.error(data.error.message);
          navigate('/ui/login');
          return;
        }

        storage.setToken(data.access_token);
        storage.setUser(data.user);
        setToken(data.access_token);
        setUser(data.user);
        toast.success('Login successful!');
        navigate('/ui/dashboard');
      })
      .catch(() => {
        toast.error('Login failed.');
        navigate('/ui/login');
      });
  }, [searchParams, navigate, setUser, setToken]);

  return (
    <div className="min-h-screen flex items-center justify-center">
      <p>{status}</p>
    </div>
  );
}
```

- [ ] **Step 2: Update `AuthContext` exports**

Ensure `AuthContext` exposes `setUser` and `setToken` or use the existing `login`/`register` flow. If not exposed, add them to the context interface.

- [ ] **Step 3: Create `Auth0Onboarding.tsx`**

```tsx
import { useState } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { auth0Service } from '@/services/auth0.service';
import { toast } from 'sonner';

export default function Auth0Onboarding() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const [slug, setSlug] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const email = searchParams.get('email') || '';
  const provider = searchParams.get('provider') || '';
  const subject = searchParams.get('subject') || '';
  const name = searchParams.get('name') || '';

  const createOrganization = async () => {
    setIsSubmitting(true);
    try {
      // Re-initiate Auth0 with register intent
      const { redirect_url } = await auth0Service.redirect(provider, 'register');
      window.location.href = redirect_url;
    } catch {
      toast.error('Failed to start organization creation.');
    } finally {
      setIsSubmitting(false);
    }
  };

  const requestJoin = async () => {
    setIsSubmitting(true);
    try {
      await auth0Service.submitJoinRequest({
        organization_slug: slug,
        provider,
        provider_subject: subject,
        email,
        name,
      });
      toast.success('Join request submitted. Please wait for approval.');
      navigate('/ui/login');
    } catch {
      toast.error('Failed to submit join request.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center p-4">
      <div className="max-w-md w-full space-y-6">
        <h1 className="text-2xl font-bold">Complete your account</h1>
        <div className="space-y-4">
          <Button onClick={createOrganization} disabled={isSubmitting} className="w-full">
            Create a new organization
          </Button>
          <div className="relative">
            <div className="absolute inset-0 flex items-center"><span className="w-full border-t" /></div>
            <div className="relative flex justify-center text-xs uppercase">
              <span className="bg-background px-2 text-muted-foreground">Or</span>
            </div>
          </div>
          <div className="space-y-2">
            <Label htmlFor="slug">Organization slug</Label>
            <Input id="slug" value={slug} onChange={(e) => setSlug(e.target.value)} placeholder="acme-corp" />
          </div>
          <Button onClick={requestJoin} disabled={isSubmitting || !slug} variant="outline" className="w-full">
            Request to join organization
          </Button>
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Add routes**

In the frontend router (likely `frontend/src/App.tsx` or `frontend/src/router.tsx`), add:

```tsx
<Route path="/ui/auth/callback" element={<Auth0Callback />} />
<Route path="/ui/auth/onboarding" element={<Auth0Onboarding />} />
```

- [ ] **Step 5: Run type-check and lint**

```bash
cd frontend && npm run type-check && npm run lint
```

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/Auth0Callback.tsx frontend/src/pages/Auth0Onboarding.tsx \
  frontend/src/context/AuthContext.tsx frontend/src/App.tsx
git commit -m "feat(auth0): add callback and onboarding pages"
```

---

### Task 6.4: Profile linked accounts

**Files:**
- Modify: `frontend/src/pages/Profile.tsx`
- Modify: `frontend/src/services/auth0.service.ts` (ensure list endpoint or fetch from `/me`)

- [ ] **Step 1: Add linked accounts section to Profile**

Create a small component or inline section in `Profile.tsx`:

```tsx
const { saasEnabled, auth0Config } = useConfig();
const [linkedProviders, setLinkedProviders] = useState<string[]>([]);

useEffect(() => {
  if (saasEnabled && user?.social_identities) {
    setLinkedProviders(user.social_identities.map((i) => i.provider));
  }
}, [saasEnabled, user]);

const handleLink = async (provider: string) => {
  const { redirect_url } = await auth0Service.initiateLink(provider);
  window.location.href = redirect_url;
};

const handleUnlink = async (provider: string) => {
  await auth0Service.unlink(provider);
  setLinkedProviders((prev) => prev.filter((p) => p !== provider));
  toast.success('Account unlinked.');
};
```

- [ ] **Step 2: Update `/me` response to include social identities**

Modify `AuthController::me()` or `AuthController::buildAuthResponse()` to include:

```php
'social_identities' => $user->socialIdentities->select('provider', 'provider_email')->get(),
```

- [ ] **Step 3: Update frontend User type**

Add `social_identities?: Array<{ provider: string; provider_email?: string }>` to the `User` type.

- [ ] **Step 4: Run type-check and lint**

```bash
cd frontend && npm run type-check && npm run lint
```

Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/Profile.tsx app/Http/Controllers/Api/AuthController.php frontend/src/types/api.types.ts
git commit -m "feat(auth0): add profile linked accounts section"
```

---

## Phase 7: Documentation, Memory, and Final Verification

### Task 7.1: Update project memory

**Files:**
- Modify: `.my_agent/memory/authentication-authorization.md`
- Create or modify: `.my_agent/memory/auth0-syndicated-auth.md`
- Modify: `.my_agent/memory/_index.md`

- [ ] **Step 1: Update `authentication-authorization.md`**

Add a new section "Auth0 Syndicated Auth" with:
- Link to spec and plan.
- New files: `Auth0Controller`, `Auth0Service`, `Auth0AccountResolver`, `UserSocialIdentity`, `OrganizationJoinRequest`.
- New routes: `/v1/auth/auth0/redirect`, `/v1/auth/auth0/callback`, `/v1/auth/auth0/link`, `/v1/auth/auth0/unlink`, `/v1/organizations/join-requests`.
- Feature flag: `OPBX_SAAS_ENABLED`.

- [ ] **Step 2: Create `auth0-syndicated-auth.md`**

Follow the existing memory file format. Include scope, source files, routes, tests, and run commands.

- [ ] **Step 3: Update `_index.md`**

Add a row for the new module:

```markdown
| **Auth0 Syndicated Auth** | [auth0-syndicated-auth.md](auth0-syndicated-auth.md) | Auth0Controller, Auth0Service | Auth0Callback, Auth0Onboarding, SocialAuthButtons |
```

- [ ] **Step 4: Commit**

```bash
git add .my_agent/memory/authentication-authorization.md .my_agent/memory/auth0-syndicated-auth.md .my_agent/memory/_index.md
git commit -m "docs(auth0): update project memory for syndicated auth"
```

---

### Task 7.2: Documentation and env example

**Files:**
- Create: `docs/WEBHOOK-AUTHENTICATION.md` is unrelated; instead create `docs/AUTH0-SYNDICATED-AUTH.md`
- Modify: `.env.example` (already done in Task 1.1)

- [ ] **Step 1: Create admin setup guide**

Create `docs/AUTH0-SYNDICATED-AUTH.md` with:

```markdown
# Auth0 Syndicated Auth Setup

## Enabling SaaS mode

Set `OPBX_SAAS_ENABLED=true` in `.env`.

## Auth0 Application configuration

1. Create a **Regular Web Application** in Auth0.
2. Allowed Callback URLs: `https://app.opbx.com/v1/auth/auth0/callback`
3. Allowed Logout URLs: `https://app.opbx.com/ui/login`
4. Allowed Web Origins: `https://app.opbx.com`
5. Enable social connections: Google, Facebook, Microsoft, GitHub, X.

## Environment variables

```bash
OPBX_SAAS_ENABLED=true
AUTH0_DOMAIN=your-tenant.us.auth0.com
AUTH0_CLIENT_ID=...
AUTH0_CLIENT_SECRET=...
AUTH0_REDIRECT_URI=https://app.opbx.com/ui/auth/callback
AUTH0_PROVIDERS=google,facebook,microsoft,github,x
```

## User flows

- **Sign up**: Auth0 → create organization → OWNER.
- **Log in**: Auth0 → issue Sanctum token.
- **Join existing org**: Auth0 → submit request → owner/admin approves.
- **Link account**: Profile → Linked Accounts → connect provider.
```

- [ ] **Step 2: Commit**

```bash
git add docs/AUTH0-SYNDICATED-AUTH.md
git commit -m "docs(auth0): add Auth0 setup guide"
```

---

### Task 7.3: Final verification

- [ ] **Step 1: Run full Auth0 test filter**

```bash
./run-tests.sh --filter=Auth0
```

Expected: all tests pass.

- [ ] **Step 2: Run PHP lint on changed files**

```bash
docker compose exec app vendor/bin/pint
```

Expected: no fixes needed.

- [ ] **Step 3: Run frontend build**

```bash
cd frontend && npm run type-check && npm run build
```

Expected: build succeeds.

- [ ] **Step 4: Regression test unrelated modules**

```bash
./run-tests.sh --filter=Auth
./run-tests.sh --filter=Profile
./run-tests.sh --filter=User
```

Expected: no new failures.

- [ ] **Step 5: Commit any lint fixes**

```bash
git add -A
git commit -m "style(auth0): apply pint and frontend lint fixes"
```

---

## Self-Review Checklist

- [ ] Spec coverage: every acceptance criterion in the design doc maps to at least one task.
- [ ] Placeholder scan: no "TBD", "TODO", or vague "add validation" steps remain.
- [ ] Type consistency: `provider`, `intent`, `subject`, and `email_verified` names match across backend/frontend.
- [ ] Security: state is stored server-side and consumed once; PKCE is used; client secret stays server-side.
- [ ] Multi-tenancy: join requests use `organization_id`; models use `OrganizationScope`.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-06-25-auth0-syndicated-auth-implementation-plan.md`.

**Two execution options:**

1. **Subagent-Driven (recommended)** — Dispatch a fresh subagent per phase/task, review between tasks, fast iteration.
2. **Inline Execution** — Execute tasks in this session using `executing-plans`, batch execution with checkpoints for review.

Which approach would you like?
