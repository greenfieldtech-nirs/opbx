<?php

declare(strict_types=1);

namespace Tests\Unit\Services\UserInvitation;

use App\Enums\UserRole;
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

        $emailService = $this->createMock(TransactionalEmailInterface::class);
        $emailService->method('sendAsync');

        $this->service = new UserInvitationService($emailService);
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

    public function test_duplicate_email_throws_and_does_not_create_user(): void
    {
        $org = Organization::factory()->create();
        $inviter = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'exists@example.com',
            'role' => UserRole::PBX_USER,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->invite($inviter, 'exists@example.com');
    }
}
