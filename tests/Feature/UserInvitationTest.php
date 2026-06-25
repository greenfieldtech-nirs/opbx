<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth0\Auth0StateStore;
use App\Services\Email\Contracts\TransactionalEmailInterface;
use App\Services\Email\DTOs\EmailMessage;
use App\Services\UserInvitation\UserInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class UserInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function enableAuth0(): void
    {
        Config::set('services.auth0.enabled', true);
        Config::set('services.auth0.domain', 'tenant.us.auth0.com');
        Config::set('services.auth0.client_id', 'id');
        Config::set('services.auth0.client_secret', 'secret');
        Config::set('services.auth0.redirect_uri', 'https://app.opbx.com/ui/auth/callback');
        Config::set('services.auth0.providers', ['google']);
    }

    private function extractTokenFromEmail(EmailMessage $message): string
    {
        preg_match('/token=([^"&\s]+)/', $message->htmlContent ?? '', $matches);
        $this->assertArrayHasKey(1, $matches, 'No invitation token found in email.');

        return urldecode($matches[1]);
    }

    public function test_owner_can_invite_user(): void
    {
        $this->enableAuth0();

        $organization = Organization::factory()->create();
        $owner = User::factory()->owner()->create(['organization_id' => $organization->id]);
        Sanctum::actingAs($owner);

        $capturedMessage = null;
        $emailService = $this->mock(TransactionalEmailInterface::class);
        $emailService->shouldReceive('sendAsync')
            ->once()
            ->with(Mockery::on(function (EmailMessage $message) use (&$capturedMessage): bool {
                $capturedMessage = $message;

                return true;
            }))
            ->andReturn('');

        $response = $this->postJson('/api/v1/users/invite', ['email' => 'invited@example.com']);

        $response->assertStatus(201);
        $response->assertJsonPath('data.email', 'invited@example.com');
        $response->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('users', [
            'organization_id' => $organization->id,
            'email' => 'invited@example.com',
            'role' => 'pbx_user',
            'status' => 'pending',
        ]);

        $this->assertNotNull($capturedMessage);
        $this->assertSame('invited@example.com', $capturedMessage->to[0]->email);

        $token = $this->extractTokenFromEmail($capturedMessage);
        $service = new UserInvitationService($emailService);
        $this->assertNotNull($service->validateToken($token));
    }

    public function test_pbx_user_cannot_invite(): void
    {
        $this->enableAuth0();

        $organization = Organization::factory()->create();
        $pbxUser = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => UserRole::PBX_USER,
        ]);
        Sanctum::actingAs($pbxUser);

        $response = $this->postJson('/api/v1/users/invite', ['email' => 'invited@example.com']);

        $response->assertStatus(403);
    }

    public function test_duplicate_invite_to_active_user_notifies_platform_managers(): void
    {
        $this->enableAuth0();

        $organization = Organization::factory()->create();
        $owner = User::factory()->owner()->create(['organization_id' => $organization->id]);
        User::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'active@example.com',
            'status' => UserStatus::ACTIVE,
        ]);
        $platformManager = User::factory()->create([
            'email' => 'platform-manager@example.com',
            'is_platform_manager' => true,
        ]);
        Sanctum::actingAs($owner);

        $capturedMessage = null;
        $emailService = $this->mock(TransactionalEmailInterface::class);
        $emailService->shouldReceive('sendAsync')
            ->once()
            ->with(Mockery::on(function (EmailMessage $message) use (&$capturedMessage): bool {
                $capturedMessage = $message;

                return true;
            }))
            ->andReturn('');

        $response = $this->postJson('/api/v1/users/invite', ['email' => 'active@example.com']);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'USER_ALREADY_EXISTS');

        $this->assertNotNull($capturedMessage);
        $this->assertSame('platform-manager@example.com', $capturedMessage->to[0]->email);
        $this->assertStringContainsString('Duplicate invitation attempt alert', $capturedMessage->subject);
        $this->assertDatabaseHas('users', ['email' => 'platform-manager@example.com']);
    }

    public function test_re_inviting_pending_user_invalidates_previous_token(): void
    {
        $this->enableAuth0();

        $organization = Organization::factory()->create();
        $owner = User::factory()->owner()->create(['organization_id' => $organization->id]);
        Sanctum::actingAs($owner);

        $messages = [];
        $emailService = $this->mock(TransactionalEmailInterface::class);
        $emailService->shouldReceive('sendAsync')
            ->twice()
            ->with(Mockery::on(function (EmailMessage $message) use (&$messages): bool {
                $messages[] = $message;

                return true;
            }))
            ->andReturn('');

        $firstResponse = $this->postJson('/api/v1/users/invite', ['email' => 'pending@example.com']);
        $firstResponse->assertStatus(201);

        $userId = $firstResponse->json('data.id');

        $secondResponse = $this->postJson('/api/v1/users/invite', ['email' => 'pending@example.com']);
        $secondResponse->assertStatus(201);
        $secondResponse->assertJsonPath('data.id', $userId);

        $this->assertCount(2, $messages);

        $firstToken = $this->extractTokenFromEmail($messages[0]);
        $secondToken = $this->extractTokenFromEmail($messages[1]);

        $this->assertNotSame($firstToken, $secondToken);

        $service = new UserInvitationService($emailService);
        $this->assertNull($service->validateToken($firstToken));
        $this->assertNotNull($service->validateToken($secondToken));
    }

    public function test_accept_invitation_creates_auth0_state_with_intent_and_user_id(): void
    {
        $this->enableAuth0();

        $organization = Organization::factory()->create();
        $owner = User::factory()->owner()->create(['organization_id' => $organization->id]);

        $emailService = $this->mock(TransactionalEmailInterface::class);
        $emailService->shouldReceive('sendAsync')->andReturn('');

        $service = new UserInvitationService($emailService);
        $inviteResult = $service->invite($owner, 'pending@example.com');
        $user = $inviteResult['user'];
        $token = $inviteResult['token'];

        $response = $this->postJson('/api/v1/users/invite/accept', ['token' => $token]);

        $response->assertOk();
        $response->assertJsonPath('redirect_url', fn ($url) => str_contains($url, 'https://tenant.us.auth0.com/authorize'));

        $redirectUrl = $response->json('redirect_url');
        parse_str(parse_url($redirectUrl, PHP_URL_QUERY) ?? '', $query);
        $state = $query['state'] ?? '';
        $this->assertNotEmpty($state);

        $storedState = app(Auth0StateStore::class)->consume($state);
        $this->assertSame('invitation', $storedState->payload['intent']);
        $this->assertSame($user->id, $storedState->payload['user_id']);
        $this->assertSame('pending@example.com', $query['login_hint'] ?? null);
    }

    public function test_auth0_callback_with_invitation_intent_activates_user(): void
    {
        $this->enableAuth0();

        $organization = Organization::factory()->create();
        $owner = User::factory()->owner()->create(['organization_id' => $organization->id]);

        $emailService = $this->mock(TransactionalEmailInterface::class);
        $emailService->shouldReceive('sendAsync')->andReturn('');

        $service = new UserInvitationService($emailService);
        $token = $service->invite($owner, 'pending@example.com')['token'];

        $acceptResponse = $this->postJson('/api/v1/users/invite/accept', ['token' => $token]);
        $acceptResponse->assertOk();

        $redirectUrl = $acceptResponse->json('redirect_url');
        parse_str(parse_url($redirectUrl, PHP_URL_QUERY) ?? '', $query);
        $state = $query['state'] ?? '';

        Http::fake([
            'tenant.us.auth0.com/oauth/token' => Http::response(['access_token' => 'token'], 200),
            'tenant.us.auth0.com/userinfo' => Http::response([
                'sub' => 'google-oauth2|123',
                'email' => 'pending@example.com',
                'email_verified' => true,
                'name' => 'Accepted User',
            ], 200),
        ]);

        $callbackResponse = $this->getJson("/api/v1/auth/auth0/callback?code=valid&state={$state}");

        $callbackResponse->assertOk();
        $callbackResponse->assertJsonPath('user.email', 'pending@example.com');
        $callbackResponse->assertJsonPath('user.status', 'active');
        $callbackResponse->assertJsonPath('access_token', fn ($t) => ! empty($t));

        $this->assertDatabaseHas('users', [
            'email' => 'pending@example.com',
            'status' => 'active',
            'name' => 'Accepted User',
        ]);
        $this->assertDatabaseHas('user_social_identities', [
            'provider_subject' => 'google-oauth2|123',
        ]);
    }

    public function test_auth0_callback_with_mismatched_email_returns_invite_email_mismatch(): void
    {
        $this->enableAuth0();

        $organization = Organization::factory()->create();
        $owner = User::factory()->owner()->create(['organization_id' => $organization->id]);

        $emailService = $this->mock(TransactionalEmailInterface::class);
        $emailService->shouldReceive('sendAsync')->andReturn('');

        $service = new UserInvitationService($emailService);
        $token = $service->invite($owner, 'pending@example.com')['token'];

        $acceptResponse = $this->postJson('/api/v1/users/invite/accept', ['token' => $token]);
        $acceptResponse->assertOk();

        $redirectUrl = $acceptResponse->json('redirect_url');
        parse_str(parse_url($redirectUrl, PHP_URL_QUERY) ?? '', $query);
        $state = $query['state'] ?? '';

        Http::fake([
            'tenant.us.auth0.com/oauth/token' => Http::response(['access_token' => 'token'], 200),
            'tenant.us.auth0.com/userinfo' => Http::response([
                'sub' => 'google-oauth2|123',
                'email' => 'other@example.com',
                'email_verified' => true,
                'name' => 'Other User',
            ], 200),
        ]);

        $callbackResponse = $this->getJson("/api/v1/auth/auth0/callback?code=valid&state={$state}");

        $callbackResponse->assertStatus(422);
        $callbackResponse->assertJsonPath('error.code', 'INVITE_EMAIL_MISMATCH');

        $this->assertDatabaseHas('users', [
            'email' => 'pending@example.com',
            'status' => 'pending',
        ]);
    }

    public function test_expired_or_invalid_token_returns_gone(): void
    {
        $this->enableAuth0();

        $validateResponse = $this->getJson('/api/v1/users/invite/validate?token=invalid-token');
        $validateResponse->assertStatus(410);
        $validateResponse->assertJsonPath('error.code', 'INVITE_EXPIRED_OR_INVALID');

        $acceptResponse = $this->postJson('/api/v1/users/invite/accept', ['token' => 'invalid-token']);
        $acceptResponse->assertStatus(410);
        $acceptResponse->assertJsonPath('error.code', 'INVITE_EXPIRED_OR_INVALID');
    }

    public function test_rate_limiting_blocks_excessive_invites(): void
    {
        $this->enableAuth0();
        Config::set('services.invitation.rate_limit_per_hour', 3);

        $organization = Organization::factory()->create();
        $owner = User::factory()->owner()->create(['organization_id' => $organization->id]);
        Sanctum::actingAs($owner);

        $emailService = $this->mock(TransactionalEmailInterface::class);
        $emailService->shouldReceive('sendAsync')->andReturn('');

        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/v1/users/invite', ['email' => "user{$i}@example.com"]);
            $response->assertStatus(201);
        }

        $blockedResponse = $this->postJson('/api/v1/users/invite', ['email' => 'blocked@example.com']);
        $blockedResponse->assertStatus(429);
        $blockedResponse->assertJsonPath('error.code', 'INVITE_RATE_LIMITED');
    }
}
