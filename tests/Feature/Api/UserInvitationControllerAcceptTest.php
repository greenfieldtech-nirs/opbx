<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Email\Contracts\TransactionalEmailInterface;
use App\Services\UserInvitation\UserInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class UserInvitationControllerAcceptTest extends TestCase
{
    use RefreshDatabase;
    public function test_accept_returns_503_when_auth0_disabled_and_does_not_consume_token(): void
    {
        Config::set('services.auth0.enabled', false);

        $org = Organization::factory()->create();
        $inviter = User::factory()->create([
            'organization_id' => $org->id,
            'role' => UserRole::OWNER,
        ]);
        $emailService = $this->createMock(TransactionalEmailInterface::class);
        $emailService->method('sendAsync')->willReturn('');
        $service = new UserInvitationService($emailService);

        $token = $service->invite($inviter, 'pending@example.com')['token'];

        $response = $this->postJson('/api/v1/users/invite/accept', ['token' => $token]);

        $response->assertStatus(503);
        $response->assertJsonPath('error.code', 'AUTH0_NOT_CONFIGURED');
        $this->assertNotNull($service->validateToken($token));
    }
}
