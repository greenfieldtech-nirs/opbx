# Embedded Dialer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a remotely-loaded, GA-style JS snippet that embeds a specific user's Web Phone widget (in an iframe) into any third-party website, authenticated by a per-user embed token, with a `window.OpbxDialer` command API.

**Architecture:** Per-user `opbxd_` embed token (hashed at rest, one per user). A public `/v1/embed/*` API returns the bound extension's SIP config after validating the token + request `Origin` (with per-request CORS). A loader snippet injects an iframe served by OpBX (`/embed/dialer`, per-token `frame-ancestors` CSP) that runs a standalone widget bundle wrapping the existing `<WebPhone />`. Host page ↔ widget communicate via `postMessage`.

**Tech Stack:** Laravel 12 (PHP 8.4), React 18 + Vite, TanStack Query, JsSIP, MySQL, shadcn/ui.

**Spec:** `docs/superpowers/specs/2026-07-14-embedded-dialer-design.md`

**Conventions:** PHP `declare(strict_types=1)`, PSR-12 (`vendor/bin/pint --dirty`). Tests run via `./run-tests.sh` against MySQL (never SQLite). Test files are gitignored under `/tests` — stage them with `git add -f`. Frontend: 2-space indent, `@/` alias, `npm run type-check` (NOT `npm run lint` — it's broken repo-wide). Rebuild `frontend/dist` with `npm run build` to see changes. Work on the `develop` branch.

---

## File Structure

**Backend — create:**
- `app/Enums/EmbedIconPosition.php` — 4-corner enum
- `database/migrations/2026_07_14_000001_create_user_embed_tokens_table.php`
- `database/migrations/2026_07_14_000002_backfill_user_embed_tokens.php`
- `app/Models/UserEmbedToken.php`
- `database/factories/UserEmbedTokenFactory.php`
- `app/Services/EmbedTokenService.php` — generate/hash/resolve/regenerate
- `app/Services/WebPhoneConfigBuilder.php` — shared SIP-config builder (refactor target)
- `app/Http/Middleware/ResolveEmbedToken.php` — alias `resolve.embed.token`
- `app/Http/Controllers/Api/EmbedConfigController.php` — `config`, `callsLog`
- `app/Http/Controllers/Api/UserEmbedTokenController.php` — `show`, `update`, `regenerate`
- `app/Http/Controllers/EmbedDialerController.php` — serves iframe HTML + loader.js (web routes)
- `app/Http/Requests/EmbedToken/UpdateEmbedTokenRequest.php`
- `app/Http/Resources/EmbedTokenResource.php`

**Backend — modify:**
- `app/Models/User.php` — `embedToken()` hasOne
- `app/Http/Controllers/Api/WebPhoneConfigController.php` — delegate to `WebPhoneConfigBuilder`
- `app/Http/Controllers/Api/WebPhoneCallsLogController.php` — extract shared calls-log query
- `bootstrap/app.php` — register `resolve.embed.token` alias + throttle
- `routes/api.php` — `/v1/embed/*` public routes + user embed-token routes
- `routes/web.php` — `/embed/dialer`, `/embed/loader.js`
- Wherever users are created (UsersController store / user service) — auto-generate token

**Frontend — create:**
- `frontend/vite.embed.config.ts` — standalone widget build
- `frontend/src/embed/main.tsx` — widget bundle entry (mounts WebPhone in iframe)
- `frontend/src/embed/embedApi.ts` — postMessage command/event bridge (widget side)
- `frontend/src/embed/loader.ts` — the GA-style loader IIFE (host side, exposes `window.OpbxDialer`)
- `frontend/src/services/embedTokens.service.ts` — admin CRUD (get/update/regenerate)
- `frontend/src/types/embed.types.ts`
- `frontend/src/components/Users/EmbeddedDialerDialog.tsx` — per-user config + regenerate + snippet

**Frontend — modify:**
- `frontend/src/components/WebPhone/WebPhone.tsx` — accept props (config source, icon position/color, command bus)
- `frontend/src/pages/UsersComplete.tsx` — per-row Embed action + creation snippet reveal

**Docs:**
- `docs/opbx-userguide/modules/embedded-dialer.mdx` (new)
- `docs/opbx-userguide/modules/web-phone.mdx` (cross-link)

---

## Phase 1 — Data model & token service

### Task 1: EmbedIconPosition enum

**Files:**
- Create: `app/Enums/EmbedIconPosition.php`
- Test: `tests/Unit/Enums/EmbedIconPositionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\EmbedIconPosition;
use PHPUnit\Framework\TestCase;

final class EmbedIconPositionTest extends TestCase
{
    public function test_has_four_corner_cases(): void
    {
        $values = array_map(fn ($c) => $c->value, EmbedIconPosition::cases());
        sort($values);
        $this->assertSame(['bottom-left', 'bottom-right', 'top-left', 'top-right'], $values);
    }

    public function test_default_is_bottom_right(): void
    {
        $this->assertSame(EmbedIconPosition::BOTTOM_RIGHT, EmbedIconPosition::default());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./run-tests.sh --filter=EmbedIconPositionTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum EmbedIconPosition: string
{
    case BOTTOM_RIGHT = 'bottom-right';
    case BOTTOM_LEFT = 'bottom-left';
    case TOP_RIGHT = 'top-right';
    case TOP_LEFT = 'top-left';

    public static function default(): self
    {
        return self::BOTTOM_RIGHT;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./run-tests.sh --filter=EmbedIconPositionTest` → PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint app/Enums/EmbedIconPosition.php
git add app/Enums/EmbedIconPosition.php && git add -f tests/Unit/Enums/EmbedIconPositionTest.php
git commit -m "feat(embed): add EmbedIconPosition enum"
```

---

### Task 2: Migration — user_embed_tokens table

**Files:**
- Create: `database/migrations/2026_07_14_000001_create_user_embed_tokens_table.php`
- Test: `tests/Feature/Embed/EmbedTokenMigrationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class EmbedTokenMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('user_embed_tokens'));
        $this->assertTrue(Schema::hasColumns('user_embed_tokens', [
            'id', 'user_id', 'organization_id', 'token',
            'allowed_domains', 'icon_position', 'icon_background_color',
            'last_used_at', 'created_at', 'updated_at',
        ]));
    }
}
```

- [ ] **Step 2: Run to verify fail**

Run: `./run-tests.sh --filter=EmbedTokenMigrationTest` → FAIL (no table).

- [ ] **Step 3: Implement migration**

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
        Schema::create('user_embed_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->json('allowed_domains');
            $table->string('icon_position')->default('bottom-right');
            $table->string('icon_background_color')->default('#007acc');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_embed_tokens');
    }
};
```

- [ ] **Step 4: Run to verify pass**

Run: `./run-tests.sh --filter=EmbedTokenMigrationTest` → PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_14_000001_create_user_embed_tokens_table.php
git add -f tests/Feature/Embed/EmbedTokenMigrationTest.php
git commit -m "feat(embed): create user_embed_tokens table"
```

---

### Task 3: UserEmbedToken model + factory + User relation

**Files:**
- Create: `app/Models/UserEmbedToken.php`, `database/factories/UserEmbedTokenFactory.php`
- Modify: `app/Models/User.php`
- Test: `tests/Unit/Models/UserEmbedTokenTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\EmbedIconPosition;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserEmbedToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserEmbedTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_user_and_casts(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $token = UserEmbedToken::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'allowed_domains' => ['crm.acme.com'],
            'icon_position' => EmbedIconPosition::TOP_LEFT->value,
        ]);

        $this->assertSame($user->id, $token->user->id);
        $this->assertSame(['crm.acme.com'], $token->allowed_domains);
        $this->assertSame(EmbedIconPosition::TOP_LEFT, $token->icon_position);
    }

    public function test_user_has_embed_token_relation(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        UserEmbedToken::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);

        $this->assertNotNull($user->fresh()->embedToken);
    }
}
```

- [ ] **Step 2: Run to verify fail**

Run: `./run-tests.sh --filter=UserEmbedTokenTest` → FAIL (model/factory missing).

- [ ] **Step 3: Implement model**

`app/Models/UserEmbedToken.php`:
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmbedIconPosition;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class UserEmbedToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'organization_id',
        'token',
        'allowed_domains',
        'icon_position',
        'icon_background_color',
        'last_used_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'allowed_domains' => 'array',
            'icon_position' => EmbedIconPosition::class,
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

> **Note:** Confirm the OrganizationScope namespace/path by checking an existing model (e.g. `app/Models/CallDetailRecord.php`) — match its exact `#[ScopedBy(...)]` import. Adjust the `use` line if the codebase uses a different path.

`database/factories/UserEmbedTokenFactory.php`:
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserEmbedToken;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserEmbedTokenFactory extends Factory
{
    protected $model = UserEmbedToken::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'organization_id' => Organization::factory(),
            'token' => hash('sha256', 'opbxd_'.fake()->unique()->sha1()),
            'allowed_domains' => ['example.com'],
            'icon_position' => 'bottom-right',
            'icon_background_color' => '#007acc',
        ];
    }
}
```

- [ ] **Step 4: Add User relation**

In `app/Models/User.php`, add (near other relations):
```php
    public function embedToken(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(UserEmbedToken::class);
    }
```
Add `use App\Models\UserEmbedToken;` if the file uses grouped imports; otherwise the FQCN above suffices — match the file's existing style.

- [ ] **Step 5: Run to verify pass**

Run: `./run-tests.sh --filter=UserEmbedTokenTest` → PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Models/UserEmbedToken.php database/factories/UserEmbedTokenFactory.php app/Models/User.php
git add -f tests/Unit/Models/UserEmbedTokenTest.php
git commit -m "feat(embed): add UserEmbedToken model, factory, User relation"
```

---

### Task 4: EmbedTokenService (generate / hash / resolve / regenerate)

**Files:**
- Create: `app/Services/EmbedTokenService.php`
- Test: `tests/Feature/Embed/EmbedTokenServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Models\Organization;
use App\Models\User;
use App\Services\EmbedTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EmbedTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EmbedTokenService
    {
        return app(EmbedTokenService::class);
    }

    public function test_generate_creates_row_and_returns_plaintext_once(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        [$model, $plaintext] = $this->service()->generateFor($user);

        $this->assertStringStartsWith('opbxd_', $plaintext);
        $this->assertSame(hash('sha256', $plaintext), $model->token);
        $this->assertSame($user->id, $model->user_id);
        $this->assertSame($org->id, $model->organization_id);
    }

    public function test_resolve_returns_token_for_valid_plaintext(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        [, $plaintext] = $this->service()->generateFor($user);

        $resolved = $this->service()->resolve($plaintext);
        $this->assertNotNull($resolved);
        $this->assertSame($user->id, $resolved->user_id);
    }

    public function test_resolve_rejects_bad_prefix_and_unknown(): void
    {
        $this->assertNull($this->service()->resolve('nope_123'));
        $this->assertNull($this->service()->resolve('opbxd_unknown'));
    }

    public function test_regenerate_rotates_hash_and_kills_old_token(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        [, $old] = $this->service()->generateFor($user);

        [$model, $new] = $this->service()->regenerateFor($user);

        $this->assertNotSame($old, $new);
        $this->assertNull($this->service()->resolve($old));
        $this->assertNotNull($this->service()->resolve($new));
        // Config (domains) preserved across regenerate.
        $this->assertSame($model->id, $user->fresh()->embedToken->id);
    }
}
```

- [ ] **Step 2: Run to verify fail**

Run: `./run-tests.sh --filter=EmbedTokenServiceTest` → FAIL.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Models\UserEmbedToken;
use Illuminate\Support\Str;

final class EmbedTokenService
{
    private const PREFIX = 'opbxd_';

    /**
     * Create a token row for a user. Returns [model, plaintext] — plaintext shown once.
     */
    public function generateFor(User $user): array
    {
        $plaintext = self::PREFIX.Str::random(40);

        $model = UserEmbedToken::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'token' => hash('sha256', $plaintext),
            'allowed_domains' => [],
            'icon_position' => 'bottom-right',
            'icon_background_color' => '#007acc',
        ]);

        return [$model, $plaintext];
    }

    /**
     * Rotate the token hash in place, preserving allowed_domains + icon config.
     * Returns [model, plaintext].
     */
    public function regenerateFor(User $user): array
    {
        $model = $user->embedToken;
        if (! $model) {
            return $this->generateFor($user);
        }

        $plaintext = self::PREFIX.Str::random(40);
        $model->token = hash('sha256', $plaintext);
        $model->save();

        return [$model, $plaintext];
    }

    public function resolve(string $plaintext): ?UserEmbedToken
    {
        if (! str_starts_with($plaintext, self::PREFIX)) {
            return null;
        }

        return UserEmbedToken::withoutGlobalScope(OrganizationScope::class)
            ->where('token', hash('sha256', $plaintext))
            ->first();
    }
}
```

> **Note:** Match the `OrganizationScope` bypass idiom used in `app/Services/ApiKeyService.php` (it may use `OrganizationScope::bypass(fn () => ...)` instead of `withoutGlobalScope`). Use whichever the codebase uses.

- [ ] **Step 4: Run to verify pass** → PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint app/Services/EmbedTokenService.php
git add app/Services/EmbedTokenService.php
git add -f tests/Feature/Embed/EmbedTokenServiceTest.php
git commit -m "feat(embed): add EmbedTokenService generate/resolve/regenerate"
```

---

### Task 5: Backfill migration for existing users

**Files:**
- Create: `database/migrations/2026_07_14_000002_backfill_user_embed_tokens.php`
- Test: `tests/Feature/Embed/EmbedBackfillTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserEmbedToken;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class EmbedBackfillTest extends TestCase
{
    public function test_backfill_creates_a_token_per_user_without_one(): void
    {
        // RefreshDatabase is intentionally omitted: we drive migrations manually.
        Artisan::call('migrate:fresh');

        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        UserEmbedToken::where('user_id', $user->id)->delete();

        require database_path('migrations/2026_07_14_000002_backfill_user_embed_tokens.php');
        (new (require database_path('migrations/2026_07_14_000002_backfill_user_embed_tokens.php')))->up();

        $this->assertNotNull(UserEmbedToken::where('user_id', $user->id)->first());
    }
}
```

> **Note:** If the double-require pattern is awkward in this codebase's test style, instead assert via `Artisan::call('migrate')` re-run idempotency, or test the backfill logic by extracting it into a small invokable and calling that. Keep the assertion: every user ends with exactly one token, and re-running does not duplicate.

- [ ] **Step 2: Run to verify fail** → FAIL.

- [ ] **Step 3: Implement migration**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\EmbedTokenService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $service = app(EmbedTokenService::class);

        User::query()->whereDoesntHave('embedToken')->chunkById(200, function ($users) use ($service) {
            foreach ($users as $user) {
                $service->generateFor($user);
            }
        });
    }

    public function down(): void
    {
        // Tokens are dropped with the table; no per-row rollback.
    }
};
```

- [ ] **Step 4: Run to verify pass** → PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_14_000002_backfill_user_embed_tokens.php
git add -f tests/Feature/Embed/EmbedBackfillTest.php
git commit -m "feat(embed): backfill embed tokens for existing users"
```

---

## Phase 2 — Shared config builder + refactor

### Task 6: Extract WebPhoneConfigBuilder

**Files:**
- Create: `app/Services/WebPhoneConfigBuilder.php`
- Modify: `app/Http/Controllers/Api/WebPhoneConfigController.php`
- Test: `tests/Feature/Embed/WebPhoneConfigBuilderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Services\WebPhoneConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WebPhoneConfigBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_sip_config_for_user(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'name' => 'Jane']);
        $ext = Extension::factory()->create([
            'organization_id' => $org->id, 'user_id' => $user->id,
            'type' => 'user', 'extension_number' => '1001', 'password' => 'sipsecret',
        ]);
        CloudonixSettings::factory()->create(['organization_id' => $org->id, 'domain_name' => 'acme.cx']);

        $config = app(WebPhoneConfigBuilder::class)->buildForUser($user);

        $this->assertSame('1001', $config['sip_username']);
        $this->assertSame('sipsecret', $config['sip_password']);
        $this->assertSame('acme.cx', $config['sip_domain']);
        $this->assertSame('sip:1001@acme.cx', $config['sip_uri']);
        $this->assertSame('Jane', $config['display_name']);
    }

    public function test_returns_null_when_no_extension(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        CloudonixSettings::factory()->create(['organization_id' => $org->id, 'domain_name' => 'acme.cx']);

        $this->assertNull(app(WebPhoneConfigBuilder::class)->buildForUser($user));
    }
}
```

> **Note:** Verify `CloudonixSettings` has a factory. If not, create one or build the row via `CloudonixSettings::create([...])` with the columns the model requires.

- [ ] **Step 2: Run to verify fail** → FAIL.

- [ ] **Step 3: Implement the builder** (move logic verbatim from `WebPhoneConfigController::config`)

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExtensionType;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\User;

final class WebPhoneConfigBuilder
{
    /**
     * Build the JsSIP config array for a user's USER-type extension.
     * Returns null if the user has no extension or the org has no Cloudonix domain.
     *
     * @return array<string, mixed>|null
     */
    public function buildForUser(User $user): ?array
    {
        $extension = Extension::where('user_id', $user->id)
            ->where('type', ExtensionType::USER)
            ->where('organization_id', $user->organization_id)
            ->first();

        if (! $extension) {
            return null;
        }

        $cloudonixSettings = CloudonixSettings::where('organization_id', $user->organization_id)->first();
        if (! $cloudonixSettings || ! $cloudonixSettings->domain_name) {
            return null;
        }

        $organization = $user->organization;
        $country = 'us';
        if ($organization && $organization->settings) {
            $settingsCountry = strtolower(trim((string) ($organization->settings['country'] ?? '')));
            if ($settingsCountry !== '') {
                $country = $settingsCountry;
            }
        }

        return [
            'sip_username' => $extension->extension_number,
            'sip_password' => $extension->password,
            'sip_domain' => $cloudonixSettings->domain_name,
            'sip_uri' => "sip:{$extension->extension_number}@{$cloudonixSettings->domain_name}",
            'display_name' => $user->name,
            'wss_server' => 'wss://webrtc.cloudonix.io',
            'websocket_port' => 443,
            'server_path' => '',
            'sip_contact' => $extension->extension_number,
            'profile_name' => $user->name,
            'registration_mode' => 'Direct',
            'country' => $country,
        ];
    }
}
```

- [ ] **Step 4: Refactor WebPhoneConfigController to delegate**

Replace the body of `config()` so it calls the builder and preserves the existing 404 messages + security log:
```php
    public function config(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $config = app(\App\Services\WebPhoneConfigBuilder::class)->buildForUser($user);

        if ($config === null) {
            return response()->json(['message' => 'Web Phone configuration is unavailable for this user.'], 404);
        }

        Log::info('Web Phone config retrieved', [
            'request_id' => $this->getRequestId(),
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'security_event' => true,
        ]);

        return response()->json(['data' => $config]);
    }
```
Remove now-unused imports (`Extension`, `CloudonixSettings`, `ExtensionType`) from the controller.

- [ ] **Step 5: Run builder test + existing WebPhone config tests**

Run: `./run-tests.sh --filter=WebPhoneConfigBuilderTest` → PASS.
Run: `./run-tests.sh --filter=WebPhoneConfig` → existing controller tests still PASS.

> If an existing test asserts the exact old 404 message ("No extension is assigned..."), update that assertion to the consolidated message, or keep two distinct 404 branches in the controller. Prefer keeping existing behavior — if tests demand the specific message, branch on `$user->embedToken`-independent extension check. Keep existing tests green.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/WebPhoneConfigBuilder.php app/Http/Controllers/Api/WebPhoneConfigController.php
git add -f tests/Feature/Embed/WebPhoneConfigBuilderTest.php
git commit -m "refactor(webphone): extract WebPhoneConfigBuilder shared by SPA + embed"
```

---

### Task 7: Extract shared calls-log query

**Files:**
- Create: `app/Services/WebPhoneCallsLogBuilder.php`
- Modify: `app/Http/Controllers/Api/WebPhoneCallsLogController.php`
- Test: `tests/Feature/Embed/WebPhoneCallsLogBuilderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Models\CallDetailRecord;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Services\WebPhoneCallsLogBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WebPhoneCallsLogBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_own_calls_excluding_coaching(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        Extension::factory()->create([
            'organization_id' => $org->id, 'user_id' => $user->id,
            'type' => 'user', 'extension_number' => '1000',
        ]);
        CallDetailRecord::factory()->create([
            'organization_id' => $org->id, 'session_timestamp' => '2026-07-14 10:00:00',
            'from' => '1000', 'to' => '12125551234', 'disposition' => 'ANSWER',
        ]);
        CallDetailRecord::factory()->create([
            'organization_id' => $org->id, 'session_timestamp' => '2026-07-14 10:01:00',
            'from' => '1000', 'to' => 'spy_deadbeef', 'disposition' => 'ANSWER',
        ]);

        $rows = app(WebPhoneCallsLogBuilder::class)->buildForUser($user);

        $this->assertCount(1, $rows);
        $this->assertSame('12125551234', $rows[0]['to']);
    }
}
```

- [ ] **Step 2: Run to verify fail** → FAIL.

- [ ] **Step 3: Implement builder**

Move the query from `WebPhoneCallsLogController::index` into `buildForUser(User $user): array` returning the same mapped array the controller currently returns (`to`, `session_timestamp`, `duration`, `duration_formatted`, `disposition`). Read the current controller to copy the exact query (exact `from` match, `not like` sentinels `spy_%`/`barge_%`/`whisper_%`, `orderByDesc('session_timestamp')`, `limit(50)`, `forOrganization`). Return `[]` if no extension.

- [ ] **Step 4: Refactor controller to delegate**

`index()` resolves the user, calls `buildForUser`, returns `['data' => $rows]`; keep the 404 for no-extension if the current controller returns one (match existing behavior — check the current test `WebPhoneCallsLogControllerTest`).

- [ ] **Step 5: Run**

Run: `./run-tests.sh --filter=WebPhoneCallsLogBuilderTest` → PASS.
Run: `./run-tests.sh --filter=WebPhoneCallsLogControllerTest` → still PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/WebPhoneCallsLogBuilder.php app/Http/Controllers/Api/WebPhoneCallsLogController.php
git add -f tests/Feature/Embed/WebPhoneCallsLogBuilderTest.php
git commit -m "refactor(webphone): extract WebPhoneCallsLogBuilder shared by SPA + embed"
```

---

## Phase 3 — Embed API (public config + CORS + iframe)

### Task 8: ResolveEmbedToken middleware

**Files:**
- Create: `app/Http/Middleware/ResolveEmbedToken.php`
- Modify: `bootstrap/app.php` (register alias `resolve.embed.token`)
- Test: `tests/Feature/Embed/ResolveEmbedTokenTest.php`

The middleware: resolve token from `Authorization: Bearer`; 401 if missing/unresolvable. Read request `Origin` header; if not in `allowed_domains` → 403. On success: set `Access-Control-Allow-Origin: <origin>` and `Vary: Origin` on the response, stash the resolved `UserEmbedToken` (and its `User`) on the request (`$request->attributes->set('embedUser', $user)`), throttle-bump `last_used_at`.

- [ ] **Step 1: Write the failing test** (register a temporary test route in the test, or hit the real route added in Task 9 — prefer testing via the real route in Task 9; here, unit-test the domain match helper). Minimum test:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Http\Middleware\ResolveEmbedToken;
use Tests\TestCase;

final class ResolveEmbedTokenTest extends TestCase
{
    public function test_origin_matches_allowed_domain(): void
    {
        $mw = new ResolveEmbedToken(app(\App\Services\EmbedTokenService::class));
        $this->assertTrue($mw->originAllowed('https://crm.acme.com', ['crm.acme.com']));
        $this->assertTrue($mw->originAllowed('https://crm.acme.com:8443', ['crm.acme.com']));
        $this->assertFalse($mw->originAllowed('https://evil.com', ['crm.acme.com']));
        $this->assertFalse($mw->originAllowed(null, ['crm.acme.com']));
    }
}
```

- [ ] **Step 2: Run to verify fail** → FAIL.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\EmbedTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveEmbedToken
{
    public function __construct(private readonly EmbedTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plaintext = (string) $request->bearerToken();
        $embedToken = $this->tokens->resolve($plaintext);

        if (! $embedToken) {
            return response()->json(['message' => 'Invalid embed token.'], 401);
        }

        $origin = $request->headers->get('Origin');
        if (! $this->originAllowed($origin, $embedToken->allowed_domains ?? [])) {
            return response()->json(['message' => 'Origin not allowed.'], 403);
        }

        $request->attributes->set('embedToken', $embedToken);
        $request->attributes->set('embedUser', $embedToken->user);

        // Throttled last_used bump (<=1/5s).
        if (! $embedToken->last_used_at || $embedToken->last_used_at->diffInSeconds(now()) >= 5) {
            $embedToken->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Vary', 'Origin');

        return $response;
    }

    public function originAllowed(?string $origin, array $allowedDomains): bool
    {
        if ($origin === null || $origin === '') {
            return false;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        if (! $host) {
            return false;
        }

        return in_array($host, $allowedDomains, true);
    }
}
```

Register in `bootstrap/app.php` `$middleware->alias([... 'resolve.embed.token' => \App\Http\Middleware\ResolveEmbedToken::class])`.

- [ ] **Step 4: Run** → PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Middleware/ResolveEmbedToken.php bootstrap/app.php
git add -f tests/Feature/Embed/ResolveEmbedTokenTest.php
git commit -m "feat(embed): add ResolveEmbedToken middleware with origin allowlist + CORS"
```

---

### Task 9: EmbedConfigController + public routes + OPTIONS preflight

**Files:**
- Create: `app/Http/Controllers/Api/EmbedConfigController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Embed/EmbedConfigEndpointTest.php`

Endpoints (public group, outside `auth:sanctum`, with `throttle:embed` + `resolve.embed.token`):
- `GET /v1/embed/config` → `EmbedConfigController::config`
- `GET /v1/embed/calls-log` → `EmbedConfigController::callsLog`
- `OPTIONS /v1/embed/{any}` preflight → returns 204 with `Access-Control-Allow-Origin` echoing Origin if allowed. (Preflight cannot carry the bearer header, so handle OPTIONS before `resolve.embed.token`, or register a dedicated preflight route that reflects any Origin for embed paths and lets the real GET enforce the token. Simplest: a tiny `EmbedCorsController::preflight` on `OPTIONS /v1/embed/{any?}` outside `resolve.embed.token`, reflecting the Origin and allowing `Authorization` header.)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Services\EmbedTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EmbedConfigEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function seedUserWithToken(array $domains): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'name' => 'Jane']);
        Extension::factory()->create([
            'organization_id' => $org->id, 'user_id' => $user->id,
            'type' => 'user', 'extension_number' => '1001', 'password' => 'sipsecret',
        ]);
        CloudonixSettings::factory()->create(['organization_id' => $org->id, 'domain_name' => 'acme.cx']);

        [$model, $plaintext] = app(EmbedTokenService::class)->generateFor($user);
        $model->update(['allowed_domains' => $domains]);

        return [$plaintext, $org, $user];
    }

    public function test_valid_token_and_origin_returns_config_with_cors(): void
    {
        [$token] = $this->seedUserWithToken(['crm.acme.com']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Origin' => 'https://crm.acme.com',
        ])->getJson('/api/v1/embed/config');

        $response->assertOk()
            ->assertJsonPath('data.sip_username', '1001')
            ->assertJsonPath('data.sip_password', 'sipsecret');
        $this->assertSame('https://crm.acme.com', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_disallowed_origin_is_403(): void
    {
        [$token] = $this->seedUserWithToken(['crm.acme.com']);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Origin' => 'https://evil.com',
        ])->getJson('/api/v1/embed/config')->assertStatus(403);
    }

    public function test_invalid_token_is_401(): void
    {
        $this->withHeaders([
            'Authorization' => 'Bearer opbxd_bogus',
            'Origin' => 'https://crm.acme.com',
        ])->getJson('/api/v1/embed/config')->assertStatus(401);
    }

    public function test_calls_log_returns_data(): void
    {
        [$token] = $this->seedUserWithToken(['crm.acme.com']);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Origin' => 'https://crm.acme.com',
        ])->getJson('/api/v1/embed/calls-log')->assertOk()->assertJsonStructure(['data']);
    }
}
```

- [ ] **Step 2: Run to verify fail** → FAIL.

- [ ] **Step 3: Implement controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WebPhoneCallsLogBuilder;
use App\Services\WebPhoneConfigBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmbedConfigController extends Controller
{
    public function config(Request $request, WebPhoneConfigBuilder $builder): JsonResponse
    {
        $user = $request->attributes->get('embedUser');
        $config = $builder->buildForUser($user);

        if ($config === null) {
            return response()->json(['message' => 'Embedded dialer configuration is unavailable.'], 404);
        }

        return response()->json(['data' => $config]);
    }

    public function callsLog(Request $request, WebPhoneCallsLogBuilder $builder): JsonResponse
    {
        $user = $request->attributes->get('embedUser');

        return response()->json(['data' => $builder->buildForUser($user)]);
    }
}
```

- [ ] **Step 4: Register routes** in `routes/api.php` inside the `v1` prefix but OUTSIDE the `auth:sanctum` group (mirror the DNI `call-tracking-dni/swap` placement). Add a `throttle:embed` rate limiter in `bootstrap/app.php` or `AppServiceProvider` (mirror `throttle:call-tracking-dni`). Add the OPTIONS preflight route reflecting Origin + allowing `Authorization, Content-Type` headers.

- [ ] **Step 5: Run** → PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Api/EmbedConfigController.php routes/api.php bootstrap/app.php
git add -f tests/Feature/Embed/EmbedConfigEndpointTest.php
git commit -m "feat(embed): public /v1/embed/config + calls-log with origin-scoped CORS"
```

---

### Task 10: Iframe + loader web routes with frame-ancestors CSP

**Files:**
- Create: `app/Http/Controllers/EmbedDialerController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Embed/EmbedDialerPageTest.php`

`GET /embed/dialer?token=opbxd_…` → resolves the token (via `EmbedTokenService`), and if valid returns an HTML page that:
- loads the embed widget bundle (`/embed/assets/embed-widget.js` — served from `public/embed/` after the frontend embed build is copied there; for now reference a configurable URL),
- passes the token to the widget (inline `window.__OPBX_EMBED__ = { token, iconPosition, iconBackgroundColor }`),
- sets header `Content-Security-Policy: frame-ancestors <space-separated https://domain per allowed_domains>`.
If token invalid → 403 minimal HTML, no `frame-ancestors` grant.

`GET /embed/loader.js` → serves the loader IIFE (Task 13) with `Content-Type: application/javascript`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Models\Organization;
use App\Models\User;
use App\Services\EmbedTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EmbedDialerPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_token_sets_frame_ancestors_csp(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        [$model, $token] = app(EmbedTokenService::class)->generateFor($user);
        $model->update(['allowed_domains' => ['crm.acme.com']]);

        $response = $this->get('/embed/dialer?token='.$token);

        $response->assertOk();
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-ancestors', $csp);
        $this->assertStringContainsString('https://crm.acme.com', $csp);
    }

    public function test_invalid_token_is_forbidden_without_frame_grant(): void
    {
        $response = $this->get('/embed/dialer?token=opbxd_bogus');
        $response->assertStatus(403);
        $this->assertStringNotContainsString('frame-ancestors https://', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_loader_js_is_served(): void
    {
        $this->get('/embed/loader.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');
    }
}
```

- [ ] **Step 2: Run to verify fail** → FAIL.

- [ ] **Step 3: Implement controller** with `dialer()` and `loader()` methods (Blade view or inline HTML response for the iframe; loader served from a resource file). Build CSP: `"frame-ancestors ".implode(' ', array_map(fn($d) => "https://$d", $domains))`. For the loader, read Task 13's file from `resources/embed/loader.js` and return it.

- [ ] **Step 4: Register routes** in `routes/web.php`:
```php
Route::get('/embed/dialer', [\App\Http\Controllers\EmbedDialerController::class, 'dialer']);
Route::get('/embed/loader.js', [\App\Http\Controllers\EmbedDialerController::class, 'loader']);
```

- [ ] **Step 5: Run** → PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/EmbedDialerController.php routes/web.php resources/embed/
git add -f tests/Feature/Embed/EmbedDialerPageTest.php
git commit -m "feat(embed): iframe + loader routes with per-token frame-ancestors CSP"
```

---

## Phase 4 — Token management API + policy

### Task 11: UserEmbedTokenController (show / update / regenerate) + policy

**Files:**
- Create: `app/Http/Controllers/Api/UserEmbedTokenController.php`, `app/Http/Requests/EmbedToken/UpdateEmbedTokenRequest.php`, `app/Http/Resources/EmbedTokenResource.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Embed/UserEmbedTokenControllerTest.php`

Authorization: Owner + PBX Admin only. Reuse the app's existing role gate style (check `UserPolicy` / how `SupervisorAssignmentController` authorizes — mirror it). Deny supervisor/pbx_user/reporter.

Routes (protected group):
- `GET /v1/users/{user}/embed-token` → `show` (non-secret config)
- `PATCH /v1/users/{user}/embed-token` → `update` (domains, icon_position, icon_background_color)
- `POST /v1/users/{user}/embed-token/regenerate` → `regenerate` (returns plaintext once + snippet)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\EmbedTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserEmbedTokenControllerTest extends TestCase
{
    use RefreshDatabase;

    private function orgWithActor(UserRole $role): array
    {
        $org = Organization::factory()->create();
        $actor = User::factory()->create(['organization_id' => $org->id, 'role' => $role]);
        $target = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        app(EmbedTokenService::class)->generateFor($target);

        return [$org, $actor, $target];
    }

    public function test_owner_can_update_config(): void
    {
        [, $owner, $target] = $this->orgWithActor(UserRole::OWNER);

        $this->actingAs($owner)->patchJson("/api/v1/users/{$target->id}/embed-token", [
            'allowed_domains' => ['crm.acme.com'],
            'icon_position' => 'top-left',
            'icon_background_color' => '#123456',
        ])->assertOk()
          ->assertJsonPath('data.allowed_domains', ['crm.acme.com'])
          ->assertJsonPath('data.icon_position', 'top-left')
          ->assertJsonMissingPath('data.token');
    }

    public function test_regenerate_returns_plaintext_once_and_snippet(): void
    {
        [, $owner, $target] = $this->orgWithActor(UserRole::OWNER);

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/users/{$target->id}/embed-token/regenerate")
            ->assertOk();

        $this->assertStringStartsWith('opbxd_', $response->json('token'));
        $this->assertStringContainsString('opbxd_', $response->json('snippet'));
    }

    public function test_supervisor_is_forbidden(): void
    {
        [, $supervisor, $target] = $this->orgWithActor(UserRole::SUPERVISOR);

        $this->actingAs($supervisor)
            ->postJson("/api/v1/users/{$target->id}/embed-token/regenerate")
            ->assertForbidden();
    }

    public function test_show_never_returns_token(): void
    {
        [, $owner, $target] = $this->orgWithActor(UserRole::OWNER);

        $this->actingAs($owner)->getJson("/api/v1/users/{$target->id}/embed-token")
            ->assertOk()->assertJsonMissingPath('data.token');
    }
}
```

- [ ] **Step 2: Run to verify fail** → FAIL.

- [ ] **Step 3: Implement** the FormRequest (authorize = actor role is owner/pbx_admin AND target in same org; validate `allowed_domains` array of hostnames, `icon_position` in enum values, `icon_background_color` regex `/^#[0-9a-fA-F]{6}$/`), the Resource (`allowed_domains`, `icon_position`, `icon_background_color`, `last_used_at` — never `token`), and the controller. `regenerate` builds the snippet string (see Task 13 shape) and returns `['data' => EmbedTokenResource, 'token' => $plaintext, 'snippet' => $snippet]`.

- [ ] **Step 4: Register routes.** Match how `SupervisorAssignmentController` routes are grouped.

- [ ] **Step 5: Run** → PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Api/UserEmbedTokenController.php app/Http/Requests/EmbedToken/ app/Http/Resources/EmbedTokenResource.php routes/api.php
git add -f tests/Feature/Embed/UserEmbedTokenControllerTest.php
git commit -m "feat(embed): user embed-token management API (owner/admin only)"
```

---

### Task 12: Auto-generate token on user creation

**Files:**
- Modify: the user-creation path (`UsersController` store hook or the service it calls — locate `createUserMutation.mutationFn: usersService.create` server counterpart; likely `UsersController::store`/`afterStore`)
- Test: `tests/Feature/Embed/EmbedTokenOnUserCreationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserEmbedToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EmbedTokenOnUserCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_user_via_api_generates_embed_token(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);

        $response = $this->actingAs($owner)->postJson('/api/v1/users', [
            'name' => 'New Agent',
            'email' => 'agent@example.com',
            'password' => 'Password123!',
            'role' => 'pbx_user',
        ])->assertCreated();

        $userId = $response->json('data.id');
        $this->assertNotNull(UserEmbedToken::where('user_id', $userId)->first());
    }
}
```

> **Note:** Adjust the request payload to match the real `StoreUserRequest` validation (check the existing user-store test for required fields). The assertion — a token exists after creation — is the invariant.

- [ ] **Step 2: Run to verify fail** → FAIL.

- [ ] **Step 3: Implement** — hook `EmbedTokenService::generateFor($user)` into the user-creation flow (an `afterStore`/model `created` observer, or inline in the controller store). Prefer a `User::created` observer so both API and any seeders get it. Guard against duplicates (`whereDoesntHave` / firstOrCreate semantics).

- [ ] **Step 4: Run** → PASS. Also run the existing user-store tests to confirm no regression.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/  # observer/controller change
git add -f tests/Feature/Embed/EmbedTokenOnUserCreationTest.php
git commit -m "feat(embed): auto-generate embed token on user creation"
```

---

## Phase 5 — Frontend widget bundle, loader, Widget API

### Task 13: Loader IIFE + snippet shape

**Files:**
- Create: `resources/embed/loader.js` (served by Task 10), and mirror source `frontend/src/embed/loader.ts` for reference/build if desired.
- Test: manual + a small node assertion is optional; primary verification is the browser integration in Task 17.

The loader (host-side) must:
1. Read the config object passed to it (`{ token, iconPosition, iconBackgroundColor }`).
2. Create an `<iframe>` with `src = "<OPBX_ORIGIN>/embed/dialer?token=<token>&iconPosition=<pos>&iconBackgroundColor=<color>"`, styled fixed to the configured corner, `allow="microphone"`, no border, high `z-index`.
3. Expose `window.OpbxDialer` with `dial(number)`, `hangup()`, `open()`, `close()`, `on(event, cb)` — each `dial/hangup/open/close` posts `{ source:'opbx-dialer', type:'command', name, args }` to `iframe.contentWindow` with explicit target origin `<OPBX_ORIGIN>`.
4. Listen for `message` events; ignore any whose `event.origin !== <OPBX_ORIGIN>` or `event.data.source !== 'opbx-dialer-widget'`; dispatch `{type:'event', name, payload}` to registered `on()` handlers.

- [ ] **Step 1: Write loader** (`resources/embed/loader.js`) as a plain IIFE (no bundler needed; it's tiny vanilla JS). Include `OPBX_ORIGIN` derived from the script's own `src` (read `document.currentScript.src`), so it points back to the serving origin automatically.

- [ ] **Step 2: Define the snippet** the admin copies (used by Task 11 `regenerate` + Task 16 dialog):
```html
<script>
  (function(w,d,s,c){var j=d.createElement(s);j.async=1;j.src=c.loaderUrl;
  j.onload=function(){w.OpbxDialer.init(c);};d.head.appendChild(j);})
  (window,document,'script',{
    loaderUrl:'https://<OPBX_ORIGIN>/embed/loader.js',
    token:'opbxd_XXXXXXXX',
    iconPosition:'bottom-right',
    iconBackgroundColor:'#007acc'
  });
</script>
```
Adjust the loader to expose `OpbxDialer.init(config)` matching this snippet (init builds the iframe + wires postMessage). Keep `dial/hangup/open/close/on` on `window.OpbxDialer`.

- [ ] **Step 3: Commit**

```bash
git add resources/embed/loader.js frontend/src/embed/loader.ts
git commit -m "feat(embed): loader IIFE + OpbxDialer host API + snippet shape"
```

---

### Task 14: WebPhone component — accept props (config source, icon, command bus)

**Files:**
- Modify: `frontend/src/components/WebPhone/WebPhone.tsx`
- Test: `npm run type-check` (behavioral verified in Task 17 browser test)

Make `WebPhone` accept optional props so the same component serves SPA and embed:
```ts
interface WebPhoneProps {
  configQueryFn?: () => Promise<{ data: WebPhoneConfig }>;   // default: getWebPhoneConfig
  callsLogQueryFn?: () => Promise<WebPhoneCallsLogResponse>; // default: getWebPhoneCallsLog
  iconPosition?: 'bottom-right' | 'bottom-left' | 'top-right' | 'top-left'; // default bottom-right
  iconBackgroundColor?: string;                              // default existing
  autoOpen?: boolean;                                        // embed may open immediately
}
```

- [ ] **Step 1: Add props with defaults.** Replace the hardcoded `queryFn: getWebPhoneConfig` with `queryFn: configQueryFn ?? getWebPhoneConfig`. Pass `callsLogQueryFn` down to `CallsLogView` (add a matching optional prop there defaulting to `getWebPhoneCallsLog`). Apply `iconPosition` to the pull-tab's fixed positioning classes (map the 4 values to Tailwind `bottom-6 right-6` etc.), and `iconBackgroundColor` as an inline style on the pull-tab button.

- [ ] **Step 2: Command bus hook.** Add an optional effect: if `window.__OPBX_EMBED_BUS__` exists (set by the embed entry), subscribe to `dial/hangup/open/close` commands and drive them via the existing handlers (reuse the `pendingRedialRef` queued-dial pattern for `dial`, so it auto-places once ready). Emit `ready/call.started/call.ended/call.failed` back through the bus. Keep this behind a prop or global guard so the SPA is unaffected.

- [ ] **Step 3: Verify SPA unaffected.** `npm run type-check` passes; `npm run build` succeeds; the SPA still mounts `<WebPhone />` with no props.

- [ ] **Step 4: Commit**

```bash
cd frontend && npm run type-check
git add frontend/src/components/WebPhone/WebPhone.tsx frontend/src/components/WebPhone/CallsLogView.tsx
git commit -m "feat(embed): parameterize WebPhone (config source, icon, command bus)"
```

---

### Task 15: Embed widget bundle + separate Vite build

**Files:**
- Create: `frontend/src/embed/main.tsx`, `frontend/src/embed/embedApi.ts`, `frontend/vite.embed.config.ts`
- Modify: `frontend/package.json` (add `build:embed` script), root build wiring to copy output into `public/embed/`
- Test: `npm run build:embed` produces a self-contained bundle; Task 17 browser test exercises it.

- [ ] **Step 1: Widget entry** `frontend/src/embed/main.tsx`:
- Reads `window.__OPBX_EMBED__ = { token, iconPosition, iconBackgroundColor }` (injected by the iframe HTML).
- Creates an axios instance with `baseURL` = same origin `/api/v1` and `Authorization: Bearer <token>`.
- Defines `configQueryFn`/`callsLogQueryFn` hitting `/embed/config` + `/embed/calls-log` via that axios instance.
- Sets `window.__OPBX_EMBED_BUS__` (a tiny emitter) and wires it to `postMessage` to `window.parent` (target origin = the host origin, learned from the first inbound command's `event.origin`, validated against nothing here since the server already enforced `frame-ancestors`; still validate `event.data.source === 'opbx-dialer'`).
- Mounts `<QueryClientProvider><WebPhone configQueryFn=… callsLogQueryFn=… iconPosition=… iconBackgroundColor=… autoOpen /></QueryClientProvider>` into `#root`.

- [ ] **Step 2: `embedApi.ts`** — the postMessage bridge: validates inbound messages (`event.data.source==='opbx-dialer'`, `event.source===window.parent`), forwards to the bus; posts outbound events to `window.parent` with the captured host origin.

- [ ] **Step 3: `vite.embed.config.ts`** — `build.lib` with entry `src/embed/main.tsx`, formats `['iife']`, `name: 'OpbxEmbedWidget'`, `cssCodeSplit:false`, output to `dist-embed/`. Single self-contained file (React bundled in). `package.json`: `"build:embed": "vite build --config vite.embed.config.ts"`. After build, copy `dist-embed/*` into the Laravel `public/embed/assets/` (a small script or documented step) so Task 10's iframe HTML can reference `/embed/assets/embed-widget.js`.

- [ ] **Step 4: Build**

Run: `cd frontend && npm run build:embed`
Expected: emits `dist-embed/embed-widget.js` (+ css) with no external imports.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/embed/ frontend/vite.embed.config.ts frontend/package.json
git commit -m "feat(embed): standalone widget bundle + separate Vite build"
```

---

## Phase 6 — Users page UI

### Task 16: EmbeddedDialerDialog + per-row action + creation reveal

**Files:**
- Create: `frontend/src/components/Users/EmbeddedDialerDialog.tsx`, `frontend/src/services/embedTokens.service.ts`, `frontend/src/types/embed.types.ts`
- Modify: `frontend/src/pages/UsersComplete.tsx`
- Test: `npm run type-check`; browser check in Task 17.

- [ ] **Step 1: Service** `embedTokens.service.ts`:
```ts
import api from '@/services/api';

export interface EmbedTokenConfig {
  allowed_domains: string[];
  icon_position: 'bottom-right' | 'bottom-left' | 'top-right' | 'top-left';
  icon_background_color: string;
  last_used_at: string | null;
}

export const embedTokensService = {
  get: (userId: number) =>
    api.get<{ data: EmbedTokenConfig }>(`/users/${userId}/embed-token`).then(r => r.data.data),
  update: (userId: number, payload: Partial<EmbedTokenConfig>) =>
    api.patch<{ data: EmbedTokenConfig }>(`/users/${userId}/embed-token`, payload).then(r => r.data.data),
  regenerate: (userId: number) =>
    api.post<{ token: string; snippet: string }>(`/users/${userId}/embed-token/regenerate`).then(r => r.data),
};
```

- [ ] **Step 2: Dialog** — form for allowed domains (add/remove list), icon position select (4 corners), color picker; `PATCH` on save via TanStack `useMutation`. A **Regenerate** button → confirm → shows the returned snippet in a read-only, copyable block (one-time). Owner/PBX Admin only (gate on `user.role`).

- [ ] **Step 3: Users page wiring** — add a per-row "Embed" icon action (visible only when current user is owner/pbx_admin) opening the dialog for that row's user. On user-creation success, if the create response surfaces a snippet, show the same reveal block. (If the create API doesn't return the snippet, instead auto-open the dialog's regenerate on first setup — simplest: after create, open the EmbeddedDialerDialog for the new user.)

- [ ] **Step 4: Verify** `npm run type-check` + `npm run build` pass.

- [ ] **Step 5: Commit**

```bash
cd frontend && npm run type-check && npm run build
git add frontend/src/components/Users/EmbeddedDialerDialog.tsx frontend/src/services/embedTokens.service.ts frontend/src/types/embed.types.ts frontend/src/pages/UsersComplete.tsx
git commit -m "feat(embed): Users page embed-token dialog (config, regenerate, snippet)"
```

---

## Phase 7 — Integration test, docs, full verification

### Task 17: Browser integration smoke test

**Files:** none (manual/automated browser verification)

- [ ] **Step 1:** Load the agent-browser skill. Build backend + embed bundle, run the stack (`docker compose up -d`, wait 120s).
- [ ] **Step 2:** Create a test HTML page on a local origin added to a token's `allowed_domains`, paste the snippet, confirm the widget iframe loads and registers (or shows a clear config error if Cloudonix creds absent in the test env).
- [ ] **Step 3:** In the browser console, call `OpbxDialer.dial('+12125551234')` and confirm the widget attempts the call (or the command reaches the widget — verify via a `call.started`/`call.failed` event handler registered with `OpbxDialer.on`).
- [ ] **Step 4:** Confirm a page on a NON-allowlisted origin is refused (iframe blocked by `frame-ancestors`; `/embed/config` returns 403).
- [ ] **Step 5:** Record findings; file follow-up tasks for any gaps. No commit unless code changes.

---

### Task 18: Documentation

**Files:**
- Create: `docs/opbx-userguide/modules/embedded-dialer.mdx`
- Modify: `docs/opbx-userguide/modules/web-phone.mdx`

- [ ] **Step 1:** Write the Embedded Dialer doc: what it is, how to get the snippet (Users page → Embed → Regenerate), the config variables (`token`, `iconPosition`, `iconBackgroundColor`), the Widget API reference (`dial`, `hangup`, `open`, `close`, `on` + the 4 events), the security model (domain allowlist, token shown once, regenerate = revoke), and the **documented residual risk** (SIP password reaches the host page; treat embedded extensions as lower-trust; regenerate is the fast mitigation).
- [ ] **Step 2:** Cross-link from `web-phone.mdx`.
- [ ] **Step 3: Commit**

```bash
git add docs/opbx-userguide/modules/embedded-dialer.mdx docs/opbx-userguide/modules/web-phone.mdx
git commit -m "docs: Embedded Dialer guide + Widget API reference"
```

---

### Task 19: Full verification

- [ ] **Step 1:** `vendor/bin/pint --dirty` → clean.
- [ ] **Step 2:** `./run-tests.sh` → all pass (new embed suite + no regressions).
- [ ] **Step 3:** `cd frontend && npm run type-check` → clean; `npm run build` → succeeds; `npm run build:embed` → succeeds.
- [ ] **Step 4:** Confirm the SPA Web Phone still works unchanged (regression: `WebPhone` renders with no props).
- [ ] **Step 5:** Final commit if any fixups.

---

## Notes / Risks carried from the spec

- **SIP password exposure** is accepted and documented (Cloudonix has no ephemeral creds). The embed token (domain-allowlisted, revocable via regenerate) is the boundary.
- **`Origin`/`Referer` spoofing** by non-browser clients cannot be prevented client-side; revocation is the mitigation. CORS + `frame-ancestors` block all real-browser cross-site abuse.
- **JsSIP loads from a CDN** (`jssip.net`) — host-site CSP may block it. Document that embedding sites must allow `jssip.net` (and `wss://webrtc.cloudonix.io`) in their CSP, or self-host JsSIP inside the widget bundle (consider bundling JsSIP into `embed-widget.js` in a follow-up if CDN/CSP proves painful — flagged, not done in v1).
- **`build.lib` widget size** (React bundled) will be large; acceptable for v1. Code-splitting/preact swap is a future optimization.
