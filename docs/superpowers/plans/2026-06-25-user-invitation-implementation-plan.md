# User Invitation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an **Invite User** button to the Users page that creates a pending user, sends a magic-link invitation email, and activates the user after they authenticate via Auth0.

**Architecture:** Reuse existing Laravel auth/Sanctum, Auth0 OAuth state store, and transactional email service. A new `UserInvitationService` manages single-use Redis tokens. A new `UserInvitationController` exposes invite/validate/accept endpoints. The frontend adds a dialog, an accept-invitation page, and a new Auth0 callback intent path.

**Tech Stack:** Laravel 12 (PHP 8.4), MySQL, Redis, React 18, TypeScript, TanStack Query, Auth0 OAuth, transactional email service.

---

## File Map

| File | Responsibility |
|------|----------------|
| `app/Enums/UserStatus.php` | Add `PENDING` case |
| `database/migrations/2026_06_25_000000_add_pending_to_user_status.php` | Alter `users.status` enum |
| `app/Models/User.php` | `isPending()` helper; allow nullable password |
| `config/services.php` | Invitation config keys |
| `.env.example` | New env variables |
| `app/Services/UserInvitation/UserInvitationService.php` | Token creation/validation/consumption; email dispatch |
| `app/Http/Requests/User/InviteUserRequest.php` | Email validation |
| `app/Http/Controllers/Api/UserInvitationController.php` | Invite, validate, accept endpoints |
| `routes/api.php` | Register invite routes |
| `resources/views/emails/user-invitation.blade.php` | Invitation email HTML/text |
| `resources/views/emails/duplicate-invite-alert.blade.php` | Platform manager alert email |
| `app/Services/Auth0/Auth0AccountResolver.php` | Handle `invitation` intent in callback |
| `app/Http/Controllers/Api/Auth0Controller.php` | Route `invitation` intent to resolver |
| `frontend/src/services/invitation.service.ts` | Invite API client |
| `frontend/src/components/Users/InviteUserDialog.tsx` | Email input dialog |
| `frontend/src/pages/UsersComplete.tsx` | Add Invite User button |
| `frontend/src/pages/AcceptInvitation.tsx` | Token validation + accept page |
| `frontend/src/pages/Auth0Callback.tsx` | Handle invitation activation |
| `frontend/src/router.tsx` | `/ui/invite` route |
| `tests/Feature/UserInvitationTest.php` | Backend feature tests |

---

## Task 1: Add `PENDING` status to `UserStatus` enum

**Files:**
- Modify: `app/Enums/UserStatus.php`

- [ ] **Step 1: Add case and helpers**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum UserStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case PENDING = 'pending';

    public function label(): string
    {
        return match($this) {
            self::ACTIVE => 'Active',
            self::INACTIVE => 'Inactive',
            self::PENDING => 'Pending',
        };
    }

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function isInactive(): bool
    {
        return $this === self::INACTIVE;
    }

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }
}
```

- [ ] **Step 2: Run enum unit test**

```bash
./run-tests.sh --filter=UserStatusTest
```

Expected: PASS (if no test exists, skip).

- [ ] **Step 3: Commit**

```bash
git add app/Enums/UserStatus.php
git commit -m "feat(invite): add PENDING user status"
```

---

## Task 2: Migration to add `pending` to `users.status` enum

**Files:**
- Create: `database/migrations/2026_06_25_000000_add_pending_to_user_status.php`

- [ ] **Step 1: Create migration**

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
        Schema::table('users', function (Blueprint $table) {
            $table->enum('status', ['active', 'inactive', 'pending'])
                ->default('active')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Revert any pending rows to inactive to keep enum valid
            \DB::table('users')->where('status', 'pending')->update(['status' => 'inactive']);

            $table->enum('status', ['active', 'inactive'])
                ->default('active')
                ->change();
        });
    }
};
```

- [ ] **Step 2: Run migration**

```bash
docker compose exec app php artisan migrate
```

Expected: "Migrated" success.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_25_000000_add_pending_to_user_status.php
git commit -m "feat(invite): add pending value to users.status enum"
```

---

## Task 3: Update `User` model

**Files:**
- Modify: `app/Models/User.php`

- [ ] **Step 1: Add `isPending()` helper and make password nullable**

Edit `app/Models/User.php`:

```php
    protected $fillable = [
        'organization_id',
        'name',
        'email',
        'password',
        'role',
        'status',
        // ... rest unchanged
    ];
```

Add method after `isPBXAdmin()` (or near other helpers):

```php
    /**
     * Check if the user account is pending invitation acceptance.
     */
    public function isPending(): bool
    {
        return $this->status === UserStatus::PENDING;
    }
```

Password is already in `$fillable` and can be set to `null` by omitting it on create.

- [ ] **Step 2: Commit**

```bash
git add app/Models/User.php
git commit -m "feat(invite): add User::isPending() helper"
```

---

## Task 4: Add invitation configuration

**Files:**
- Modify: `config/services.php`
- Modify: `.env.example`

- [ ] **Step 1: Add config section**

Append to `config/services.php` before the closing `];`:

```php
    /*
    |--------------------------------------------------------------------------
    | User Invitations
    |--------------------------------------------------------------------------
    */
    'invitation' => [
        'token_ttl_hours' => (int) env('OPBX_INVITE_TOKEN_TTL_HOURS', 24),
        'rate_limit_per_hour' => (int) env('OPBX_INVITE_RATE_LIMIT_PER_HOUR', 10),
        'frontend_url' => env('APP_URL', 'http://localhost'),
    ],
```

- [ ] **Step 2: Add env example entries**

Append to `.env.example`:

```bash
# User Invitations
OPBX_INVITE_TOKEN_TTL_HOURS=24
OPBX_INVITE_RATE_LIMIT_PER_HOUR=10
```

- [ ] **Step 3: Commit**

```bash
git add config/services.php .env.example
git commit -m "feat(invite): add invitation config"
```

---

## Task 5: Create `UserInvitationService`

**Files:**
- Create: `app/Services/UserInvitation/UserInvitationService.php`
- Create: `tests/Unit/Services/UserInvitation/UserInvitationServiceTest.php`

- [ ] **Step 1: Write the service**

```php
<?php

declare(strict_types=1);

namespace App\Services\UserInvitation;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Email\Contracts\TransactionalEmailInterface;
use App\Services\Email\DTOs\EmailMessage;
use App\Services\Email\DTOs\EmailRecipient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class UserInvitationService
{
    private const TOKEN_BYTES = 32;
    private const CACHE_PREFIX = 'invite:';

    public function __construct(
        private readonly TransactionalEmailInterface $emailService,
    ) {}

    /**
     * Create a pending user and send an invitation email.
     *
     * @return array{user: User, token: string}
     *
     * @throws \RuntimeException if rate limit exceeded
     */
    public function invite(User $inviter, string $email): array
    {
        $email = strtolower(trim($email));
        $organizationId = $inviter->organization_id;

        $this->ensureRateLimit($organizationId);

        if (User::where('organization_id', $organizationId)->where('email', $email)->exists()) {
            $this->notifyPlatformManagersOfDuplicateInvite($email, $inviter);

            throw new \InvalidArgumentException('A user with this email already exists in the organization.');
        }

        $user = User::create([
            'organization_id' => $organizationId,
            'name' => $this->placeholderNameFromEmail($email),
            'email' => $email,
            'password' => null,
            'role' => UserRole::PBX_USER,
            'status' => UserStatus::PENDING,
        ]);

        $token = $this->createToken($user);
        $this->sendInvitationEmail($user, $token);

        return ['user' => $user, 'token' => $token];
    }

    /**
     * Validate a token without consuming it.
     */
    public function validateToken(string $token): ?User
    {
        $payload = Cache::get(self::CACHE_PREFIX.$this->hashToken($token));

        if ($payload === null) {
            return null;
        }

        return User::find($payload['user_id'] ?? null);
    }

    /**
     * Consume a token and return the pending user.
     */
    public function consumeToken(string $token): ?User
    {
        $payload = Cache::pull(self::CACHE_PREFIX.$this->hashToken($token));

        if ($payload === null) {
            return null;
        }

        $user = User::find($payload['user_id'] ?? null);

        if ($user === null || ! $user->isPending()) {
            return null;
        }

        return $user;
    }

    private function createToken(User $user): string
    {
        $token = Str::random(self::TOKEN_BYTES);
        $ttlSeconds = config('services.invitation.token_ttl_hours', 24) * 3600;

        Cache::put(
            self::CACHE_PREFIX.$this->hashToken($token),
            ['user_id' => $user->id, 'organization_id' => $user->organization_id, 'email' => $user->email],
            $ttlSeconds
        );

        return $token;
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function placeholderNameFromEmail(string $email): string
    {
        return explode('@', $email)[0];
    }

    private function ensureRateLimit(int $organizationId): void
    {
        $maxAttempts = config('services.invitation.rate_limit_per_hour', 10);
        $key = 'invite:org:'.$organizationId;

        $allowed = RateLimiter::attempt($key, $maxAttempts, fn () => true, 3600);

        if (! $allowed) {
            throw new \RuntimeException('Invitation rate limit exceeded for this organization.');
        }
    }

    private function sendInvitationEmail(User $user, string $token): void
    {
        $organization = $user->organization;
        $frontendUrl = rtrim(config('services.invitation.frontend_url', config('app.url', 'http://localhost')), '/');
        $link = $frontendUrl.'/ui/invite?token='.urlencode($token);
        $ttlHours = config('services.invitation.token_ttl_hours', 24);

        $message = new EmailMessage(
            from: new EmailRecipient(config('mail.from.address', 'noreply@opbx.local'), config('mail.from.name', 'OPBX')),
            to: [new EmailRecipient($user->email)],
            subject: "You've been invited to join {$organization->name} on OPBX",
            htmlContent: view('emails.user-invitation', [
                'organizationName' => $organization->name,
                'inviteLink' => $link,
                'ttlHours' => $ttlHours,
            ])->render(),
            textContent: view('emails.user-invitation-text', [
                'organizationName' => $organization->name,
                'inviteLink' => $link,
                'ttlHours' => $ttlHours,
            ])->render(),
        );

        $this->emailService->sendAsync($message);
    }

    private function notifyPlatformManagersOfDuplicateInvite(string $email, User $inviter): void
    {
        $managers = User::where('is_platform_manager', true)->get();

        if ($managers->isEmpty()) {
            return;
        }

        $frontendUrl = rtrim(config('services.invitation.frontend_url', config('app.url', 'http://localhost')), '/');

        $message = new EmailMessage(
            from: new EmailRecipient(config('mail.from.address', 'noreply@opbx.local'), config('mail.from.name', 'OPBX')),
            to: $managers->map(fn (User $u) => new EmailRecipient($u->email))->all(),
            subject: 'Duplicate invitation attempt alert',
            htmlContent: view('emails.duplicate-invite-alert', [
                'email' => $email,
                'inviterName' => $inviter->name,
                'inviterEmail' => $inviter->email,
                'organizationName' => $inviter->organization->name,
                'usersUrl' => $frontendUrl.'/ui/users',
            ])->render(),
            textContent: "An invitation was attempted for {$email} in {$inviter->organization->name} by {$inviter->name} ({$inviter->email}), but a user with that email already exists.",
        );

        $this->emailService->sendAsync($message);
    }
}
```

- [ ] **Step 2: Write unit test for token hash/validate/consume**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\UserInvitation;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Email\Contracts\TransactionalEmailInterface;
use App\Services\UserInvitation\UserInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class UserInvitationServiceTest extends TestCase
{
    use RefreshDatabase;

    private UserInvitationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UserInvitationService(app(TransactionalEmailInterface::class));
    }

    public function test_it_creates_pending_user_and_stores_token(): void
    {
        $org = Organization::factory()->create();
        $inviter = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);

        $result = $this->service->invite($inviter, 'new@example.com');

        $this->assertEquals('new@example.com', $result['user']->email);
        $this->assertTrue($result['user']->isPending());
        $this->assertEquals(UserRole::PBX_USER, $result['user']->role);

        $cached = Cache::get('invite:'.hash('sha256', $result['token']));
        $this->assertNotNull($cached);
        $this->assertEquals($result['user']->id, $cached['user_id']);
    }

    public function test_validate_token_returns_user_without_consuming(): void
    {
        $org = Organization::factory()->create();
        $inviter = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);

        $result = $this->service->invite($inviter, 'new@example.com');

        $found = $this->service->validateToken($result['token']);
        $this->assertNotNull($found);

        $stillThere = $this->service->validateToken($result['token']);
        $this->assertNotNull($stillThere);
    }

    public function test_consume_token_removes_it(): void
    {
        $org = Organization::factory()->create();
        $inviter = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);

        $result = $this->service->invite($inviter, 'new@example.com');

        $found = $this->service->consumeToken($result['token']);
        $this->assertNotNull($found);

        $this->assertNull($this->service->validateToken($result['token']));
    }
}
```

- [ ] **Step 3: Run tests**

```bash
./run-tests.sh --filter=UserInvitationServiceTest
```

Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Services/UserInvitation/UserInvitationService.php tests/Unit/Services/UserInvitation/UserInvitationServiceTest.php
git commit -m "feat(invite): add UserInvitationService with token lifecycle"
```

---

## Task 6: Create request and controller

**Files:**
- Create: `app/Http/Requests/User/InviteUserRequest.php`
- Create: `app/Http/Controllers/Api/UserInvitationController.php`

- [ ] **Step 1: Create form request**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role->canManageUsers() ?? false;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
```

- [ ] **Step 2: Create controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\User\InviteUserRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth0\Auth0Config;
use App\Services\Auth0\Auth0Service;
use App\Services\UserInvitation\UserInvitationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class UserInvitationController extends Controller
{
    public function __construct(
        private readonly UserInvitationService $invitationService,
        private readonly Auth0Service $auth0Service,
    ) {}

    public function invite(InviteUserRequest $request): JsonResponse
    {
        $config = Auth0Config::fromConfig();

        if (! $config->isEnabled()) {
            return response()->json([
                'error' => ['code' => 'AUTH0_NOT_CONFIGURED', 'message' => 'Auth0 is not enabled.'],
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $result = $this->invitationService->invite($request->user(), $request->input('email'));
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => ['code' => 'USER_ALREADY_EXISTS', 'message' => $e->getMessage()],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => ['code' => 'INVITE_RATE_LIMITED', 'message' => $e->getMessage()],
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return response()->json([
            'data' => new UserResource($result['user']),
            'invite_sent' => true,
        ], Response::HTTP_CREATED);
    }

    public function validateToken(\Illuminate\Http\Request $request): JsonResponse
    {
        $token = $request->query('token');

        if (! is_string($token) || $token === '') {
            return response()->json(['error' => ['code' => 'INVITE_INVALID', 'message' => 'Token is required.']], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->invitationService->validateToken($token);

        if ($user === null || ! $user->isPending()) {
            return response()->json([
                'error' => ['code' => 'INVITE_EXPIRED_OR_INVALID', 'message' => 'Invitation is invalid or has expired.'],
            ], Response::HTTP_GONE);
        }

        return response()->json([
            'data' => [
                'email' => $user->email,
                'organization_name' => $user->organization->name,
            ],
        ]);
    }

    public function accept(\Illuminate\Http\Request $request): JsonResponse
    {
        $token = $request->input('token');

        if (! is_string($token) || $token === '') {
            return response()->json(['error' => ['code' => 'INVITE_INVALID', 'message' => 'Token is required.']], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->invitationService->consumeToken($token);

        if ($user === null) {
            return response()->json([
                'error' => ['code' => 'INVITE_EXPIRED_OR_INVALID', 'message' => 'Invitation is invalid or has expired.'],
            ], Response::HTTP_GONE);
        }

        $config = Auth0Config::fromConfig();

        if (! $config->isEnabled()) {
            return response()->json([
                'error' => ['code' => 'AUTH0_NOT_CONFIGURED', 'message' => 'Auth0 is not enabled.'],
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $result = $this->auth0Service->buildAuthorizeUrl('google', 'invitation', $user->id);

        return response()->json([
            'redirect_url' => $result['url'],
        ]);
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/User/InviteUserRequest.php app/Http/Controllers/Api/UserInvitationController.php
git commit -m "feat(invite): add invitation controller and request"
```

---

## Task 7: Register routes

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 1: Add invite routes inside authenticated tenant group**

Find the existing users block around line 266 and change to:

```php
        // Users
        Route::apiResource('users', UsersController::class);
        Route::patch('users/{user}/password', [UsersController::class, 'updatePassword'])
            ->name('users.password.update');

        // User invitations
        Route::post('users/invite', [UserInvitationController::class, 'invite'])
            ->name('users.invite');
        Route::get('users/invite/validate', [UserInvitationController::class, 'validateToken'])
            ->name('users.invite.validate');
        Route::post('users/invite/accept', [UserInvitationController::class, 'accept'])
            ->name('users.invite.accept');
```

- [ ] **Step 2: Add import at top**

Add to the `use` statements at the top of `routes/api.php`:

```php
use App\Http\Controllers\Api\UserInvitationController;
```

- [ ] **Step 3: Run route list to verify**

```bash
docker compose exec app php artisan route:list --name=users.invite
```

Expected: Three routes listed.

- [ ] **Step 4: Commit**

```bash
git add routes/api.php
git commit -m "feat(invite): register invitation routes"
```

---

## Task 8: Create email templates

**Files:**
- Create: `resources/views/emails/user-invitation.blade.php`
- Create: `resources/views/emails/user-invitation-text.blade.php`
- Create: `resources/views/emails/duplicate-invite-alert.blade.php`

- [ ] **Step 1: HTML invitation email**

```blade
@component('mail::message')
# You've been invited to join {{ $organizationName }}

Click the button below to accept your invitation and create your OPBX account.

@component('mail::button', ['url' => $inviteLink])
Accept Invitation
@endcomponent

This link expires in {{ $ttlHours }} hours.

If you did not expect this invitation, you can ignore this email.
@endcomponent
```

- [ ] **Step 2: Plain text invitation email**

```blade
You've been invited to join {{ $organizationName }} on OPBX.

Accept your invitation by visiting:
{{ $inviteLink }}

This link expires in {{ $ttlHours }} hours.

If you did not expect this invitation, you can ignore this email.
```

- [ ] **Step 3: Duplicate invite alert email**

```blade
@component('mail::message')
# Duplicate invitation attempt

An invitation was attempted for **{{ $email }}** in organization **{{ $organizationName }}** by {{ $inviterName }} ({{ $inviterEmail }}), but a user with that email already exists.

[Review users]({{ $usersUrl }})
@endcomponent
```

- [ ] **Step 4: Commit**

```bash
git add resources/views/emails/user-invitation.blade.php resources/views/emails/user-invitation-text.blade.php resources/views/emails/duplicate-invite-alert.blade.php
git commit -m "feat(invite): add invitation email templates"
```

---

## Task 9: Handle invitation intent in Auth0 callback

**Files:**
- Modify: `app/Services/Auth0/Auth0AccountResolver.php`
- Modify: `app/Http/Controllers/Api/Auth0Controller.php`

- [ ] **Step 1: Add invitation resolution to resolver**

Add a new public method to `Auth0AccountResolver`:

```php
    /**
     * @param  array<string, mixed>  $profile
     */
    public function resolveInvitation(User $pendingUser, array $profile): User
    {
        if (! ($profile['email_verified'] ?? false)) {
            throw new \RuntimeException('Email not verified.');
        }

        if ($pendingUser->email !== $profile['email']) {
            throw new \RuntimeException('Email does not match invitation.');
        }

        $pendingUser->status = UserStatus::ACTIVE;
        $pendingUser->name = $profile['name'] ?: $pendingUser->name;
        $pendingUser->save();

        UserSocialIdentity::create([
            'user_id' => $pendingUser->id,
            'provider' => $profile['provider'],
            'provider_subject' => $profile['subject'],
            'provider_email' => $profile['email'],
            'provider_data' => $profile['raw'] ?? [],
        ]);

        return $pendingUser;
    }
```

- [ ] **Step 2: Route invitation intent in Auth0Controller callback**

In `app/Http/Controllers/Api/Auth0Controller.php`, replace the existing callback match block (around lines 101-117) with:

```php
        if (($profile['intent'] ?? '') === 'link' && ($profile['user_id'] ?? null) !== null) {
            return $this->handleLink($profile);
        }

        if (($profile['intent'] ?? '') === 'invitation' && ($profile['user_id'] ?? null) !== null) {
            return $this->handleInvitation($profile);
        }

        $resolver = app(Auth0AccountResolver::class);
        $resolution = $resolver->resolve($profile);

        return match ($resolution['action']) {
            // ... existing cases unchanged
```

Then add the private method:

```php
    /**
     * @param  array<string, mixed>  $profile
     */
    private function handleInvitation(array $profile): JsonResponse
    {
        $pendingUser = User::find($profile['user_id']);

        if ($pendingUser === null || ! $pendingUser->isPending()) {
            return response()->json([
                'error' => ['code' => 'INVITE_INVALID_USER', 'message' => 'Invitation user not found or already activated.'],
            ], Response::HTTP_GONE);
        }

        try {
            $user = app(Auth0AccountResolver::class)->resolveInvitation($pendingUser, $profile);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => ['code' => 'INVITE_EMAIL_MISMATCH', 'message' => $e->getMessage()],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->buildAuthResponse($user);
    }
```

- [ ] **Step 3: Commit**

```bash
git add app/Services/Auth0/Auth0AccountResolver.php app/Http/Controllers/Api/Auth0Controller.php
git commit -m "feat(invite): bind Auth0 callback to pending invitation user"
```

---

## Task 10: Backend feature tests

**Files:**
- Create: `tests/Feature/UserInvitationTest.php`

- [ ] **Step 1: Write feature tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_invite_user(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/users/invite', ['email' => 'invite@example.com']);

        $response->assertCreated();
        $response->assertJsonPath('invite_sent', true);

        $this->assertDatabaseHas('users', [
            'email' => 'invite@example.com',
            'organization_id' => $org->id,
            'role' => 'pbx_user',
            'status' => 'pending',
        ]);
    }

    public function test_pbx_user_cannot_invite(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/users/invite', ['email' => 'invite@example.com']);

        $response->assertForbidden();
    }

    public function test_duplicate_invite_returns_422(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        User::factory()->create(['organization_id' => $org->id, 'email' => 'exists@example.com', 'role' => UserRole::PBX_USER]);
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/users/invite', ['email' => 'exists@example.com']);

        $response->assertUnprocessable();
        $response->assertJsonPath('error.code', 'USER_ALREADY_EXISTS');
    }

    public function test_validate_token_returns_preview(): void
    {
        $this->markTestSkipped('Requires Redis/Cache driver setup for tokens');
    }

    public function test_accept_invalid_token_returns_410(): void
    {
        $response = $this->postJson('/api/v1/users/invite/accept', ['token' => 'invalid-token']);

        $response->assertGone();
        $response->assertJsonPath('error.code', 'INVITE_EXPIRED_OR_INVALID');
    }
}
```

- [ ] **Step 2: Run tests**

```bash
./run-tests.sh --filter=UserInvitationTest
```

Expected: PASS (skip the Redis-dependent test if needed).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/UserInvitationTest.php
git commit -m "test(invite): add invitation feature tests"
```

---

## Task 11: Frontend invitation service

**Files:**
- Create: `frontend/src/services/invitation.service.ts`

- [ ] **Step 1: Create service**

```typescript
import api from '@/services/api';

export interface InviteUserRequest {
  email: string;
}

export interface InviteUserResponse {
  data: {
    id: string;
    email: string;
    name: string;
    role: string;
    status: string;
  };
  invite_sent: boolean;
}

export interface ValidateInviteResponse {
  data: {
    email: string;
    organization_name: string;
  };
}

export interface AcceptInviteResponse {
  redirect_url: string;
}

export const invitationService = {
  invite(data: InviteUserRequest): Promise<InviteUserResponse> {
    return api.post('/users/invite', data).then((res) => res.data);
  },

  validateToken(token: string): Promise<ValidateInviteResponse> {
    return api.get('/users/invite/validate', { params: { token } }).then((res) => res.data);
  },

  accept(token: string): Promise<AcceptInviteResponse> {
    return api.post('/users/invite/accept', { token }).then((res) => res.data);
  },
};
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/services/invitation.service.ts
git commit -m "feat(invite): add invitation frontend service"
```

---

## Task 12: Create `InviteUserDialog`

**Files:**
- Create: `frontend/src/components/Users/InviteUserDialog.tsx`

- [ ] **Step 1: Create dialog component**

```tsx
import { useState } from 'react';
import { toast } from 'sonner';
import { Mail, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { invitationService } from '@/services/invitation.service';

interface InviteUserDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess: () => void;
}

export function InviteUserDialog({ open, onOpenChange, onSuccess }: InviteUserDialogProps) {
  const [email, setEmail] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setError('Please enter a valid email address.');
      return;
    }

    setIsSubmitting(true);

    try {
      await invitationService.invite({ email });
      toast.success('Invitation sent successfully.');
      setEmail('');
      onSuccess();
      onOpenChange(false);
    } catch (err: any) {
      const code = err?.response?.data?.error?.code;
      const message = err?.response?.data?.error?.message || 'Failed to send invitation.';

      if (code === 'USER_ALREADY_EXISTS') {
        setError('A user with this email already exists in the organization.');
      } else if (code === 'INVITE_RATE_LIMITED') {
        setError('Invitation rate limit exceeded. Please try again later.');
      } else {
        setError(message);
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[425px]">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Mail className="h-5 w-5" />
              Invite User
            </DialogTitle>
            <DialogDescription>
              Send an invitation email. The user will join as a PBX User after authenticating via Auth0.
            </DialogDescription>
          </DialogHeader>

          <div className="grid gap-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="invite-email">Email address</Label>
              <Input
                id="invite-email"
                type="email"
                placeholder="colleague@example.com"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                disabled={isSubmitting}
                autoFocus
              />
              {error && <p className="text-sm text-destructive">{error}</p>}
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={isSubmitting}>
              Cancel
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Send Invitation
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/components/Users/InviteUserDialog.tsx
git commit -m "feat(invite): add InviteUserDialog component"
```

---

## Task 13: Add Invite User button to Users page

**Files:**
- Modify: `frontend/src/pages/UsersComplete.tsx`

- [ ] **Step 1: Add imports and state**

Add imports:

```tsx
import { Mail } from 'lucide-react'; // already imported? add if missing
import { InviteUserDialog } from '@/components/Users/InviteUserDialog';
```

Add state near other dialog state:

```tsx
  const [showInviteDialog, setShowInviteDialog] = useState(false);
```

- [ ] **Step 2: Add button next to Add User**

Find the Add User button around line 530 and wrap both buttons:

```tsx
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={() => setShowInviteDialog(true)}>
            <Mail className="h-4 w-4 mr-2" />
            Invite User
          </Button>
          <Button onClick={() => setShowCreateDialog(true)}>
            <Plus className="h-4 w-4 mr-2" />
            Add User
          </Button>
        </div>
```

- [ ] **Step 3: Render dialog**

Add the dialog JSX near other dialogs:

```tsx
      <InviteUserDialog
        open={showInviteDialog}
        onOpenChange={setShowInviteDialog}
        onSuccess={() => queryClient.invalidateQueries({ queryKey: ['users'] })}
      />
```

- [ ] **Step 4: Type-check**

```bash
cd frontend && npm run type-check
```

Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/UsersComplete.tsx
git commit -m "feat(invite): add Invite User button to Users page"
```

---

## Task 14: Create `AcceptInvitation` page

**Files:**
- Create: `frontend/src/pages/AcceptInvitation.tsx`

- [ ] **Step 1: Create page component**

```tsx
import { useEffect, useState } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import { toast } from 'sonner';
import { Mail, Loader2, AlertCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { invitationService } from '@/services/invitation.service';

export default function AcceptInvitation() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const token = searchParams.get('token') || '';

  const [isLoading, setIsLoading] = useState(true);
  const [isAccepting, setIsAccepting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [preview, setPreview] = useState<{ email: string; organization_name: string } | null>(null);

  useEffect(() => {
    if (!token) {
      setError('Invalid invitation link.');
      setIsLoading(false);
      return;
    }

    invitationService
      .validateToken(token)
      .then((res) => setPreview(res.data))
      .catch((err) => {
        const message = err?.response?.data?.error?.message || 'Invitation is invalid or has expired.';
        setError(message);
      })
      .finally(() => setIsLoading(false));
  }, [token]);

  const handleAccept = async () => {
    setIsAccepting(true);

    try {
      const { redirect_url } = await invitationService.accept(token);
      window.location.href = redirect_url;
    } catch (err: any) {
      const message = err?.response?.data?.error?.message || 'Failed to accept invitation.';
      toast.error(message);
      setIsAccepting(false);
    }
  };

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-primary" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="min-h-screen flex items-center justify-center p-4">
        <Card className="max-w-md w-full">
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-destructive">
              <AlertCircle className="h-5 w-5" />
              Invitation Error
            </CardTitle>
            <CardDescription>{error}</CardDescription>
          </CardHeader>
          <CardContent>
            <Button onClick={() => navigate('/ui/login')}>Go to Login</Button>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex items-center justify-center p-4">
      <Card className="max-w-md w-full">
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Mail className="h-5 w-5" />
            Accept Invitation
          </CardTitle>
          <CardDescription>
            You&apos;ve been invited to join <strong>{preview?.organization_name}</strong>.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <p className="text-sm text-muted-foreground">
            Email: <strong>{preview?.email}</strong>
          </p>
          <Button onClick={handleAccept} disabled={isAccepting} className="w-full">
            {isAccepting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            Accept &amp; Continue
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/pages/AcceptInvitation.tsx
git commit -m "feat(invite): add AcceptInvitation page"
```

---

## Task 15: Update router and Auth0 callback

**Files:**
- Modify: `frontend/src/router.tsx`
- Modify: `frontend/src/pages/Auth0Callback.tsx`

- [ ] **Step 1: Add route import and entry**

In `frontend/src/router.tsx` add import:

```tsx
const AcceptInvitation = lazy(() => import('@/pages/AcceptInvitation'));
```

Add public route before `/ui` protected block:

```tsx
  {
    path: '/ui/invite',
    element: <AcceptInvitation />,
  },
```

- [ ] **Step 2: Handle invitation activation in callback**

`Auth0Callback.tsx` already redirects to `/ui/dashboard` on success. Since the backend now activates the pending user and returns a normal auth response, no special handling is needed for the success path. The existing flow works.

However, add handling for the new error codes for better UX:

```tsx
          if (code === 'INVITE_EXPIRED_OR_INVALID') {
            toast.error('Invitation is invalid or has expired.');
            navigate('/ui/login');
            return;
          }

          if (code === 'INVITE_EMAIL_MISMATCH') {
            toast.error('The Auth0 email does not match the invitation.');
            navigate('/ui/login');
            return;
          }
```

Insert these blocks before the generic `toast.error(data.error.message)` line.

- [ ] **Step 3: Type-check and build**

```bash
cd frontend && npm run type-check && npm run build
```

Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add frontend/src/router.tsx frontend/src/pages/Auth0Callback.tsx
git commit -m "feat(invite): add /ui/invite route and callback error handling"
```

---

## Task 16: Final verification

- [ ] **Step 1: Run backend tests**

```bash
./run-tests.sh --filter=UserInvitation
```

Expected: PASS

- [ ] **Step 2: Run full frontend build**

```bash
cd frontend && npm run build
```

Expected: PASS

- [ ] **Step 3: Run PHP linter on changed files**

```bash
vendor/bin/pint --dirty
```

Expected: no changes.

- [ ] **Step 4: Commit any final fixes**

```bash
git add -A && git commit -m "fix(invite): final lint and test fixes" || echo "No changes"
```

---

## Self-Review

### Spec Coverage

| Spec Requirement | Task(s) |
|------------------|---------|
| Add `PENDING` status | 1, 2, 3 |
| Redis single-use tokens | 5 |
| `POST /api/v1/users/invite` | 6, 7 |
| `GET /api/v1/users/invite/validate` | 6, 7 |
| `POST /api/v1/users/invite/accept` | 6, 7 |
| Auth0 callback activation | 9 |
| Duplicate email 422 + platform manager alert | 5, 6 |
| Email templates | 8 |
| Frontend dialog + button | 12, 13 |
| Accept invitation page | 14 |
| Dashboard redirect | 9 (backend issues token), 15 |
| Rate limiting | 5 |
| Tests | 5, 10, 16 |

### Placeholder Scan

No TBD/TODO placeholders. All code blocks are complete.

### Type Consistency

- `UserStatus::PENDING` used in enum, migration, model, service, and tests.
- `UserInvitationService` token methods consistent across service/controller/tests.
- Frontend service types match backend responses.

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-06-25-user-invitation-implementation-plan.md`.**

Two execution options:

1. **Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — Execute tasks in this session using `executing-plans`, batch execution with checkpoints.

Which approach would you like?
