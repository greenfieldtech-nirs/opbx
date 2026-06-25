<?php

declare(strict_types=1);

namespace Tests\Unit\Services\UserInvitation;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Email\Contracts\TransactionalEmailInterface;
use App\Services\Email\DTOs\EmailMessage;
use App\Services\Email\DTOs\EmailRecipient;
use App\Services\UserInvitation\UserInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class UserInvitationServiceTest extends TestCase
{
    use RefreshDatabase;

    private UserInvitationService $service;

    private TransactionalEmailInterface $emailService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emailService = $this->createMock(TransactionalEmailInterface::class);
        $this->emailService->method('sendAsync')->willReturn('');

        $this->service = new UserInvitationService($this->emailService);
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

        $this->expectException(InvalidArgumentException::class);
        $this->service->invite($inviter, 'exists@example.com');
    }

    public function test_reinviting_pending_user_generates_new_token_and_sends_email(): void
    {
        $org = Organization::factory()->create();
        $inviter = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        $pending = User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'pending@example.com',
            'status' => UserStatus::PENDING,
            'role' => UserRole::PBX_USER,
        ]);

        $this->emailService->expects($this->once())->method('sendAsync')->with($this->callback(
            fn (EmailMessage $message) => $message->to[0]->email === 'pending@example.com'
        ))->willReturn('');

        $result = $this->service->invite($inviter, 'pending@example.com');

        $this->assertSame($pending->id, $result['user']->id);
        $this->assertTrue($result['user']->isPending());
        $this->assertDatabaseCount('users', 2);

        $cached = Cache::get('invite:'.hash('sha256', $result['token']));
        $this->assertNotNull($cached);
        $this->assertEquals($pending->id, $cached['user_id']);
    }

    public function test_duplicate_email_for_active_user_throws_and_alerts_platform_managers(): void
    {
        $org = Organization::factory()->create();
        $inviter = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'exists@example.com',
            'status' => UserStatus::ACTIVE,
            'role' => UserRole::PBX_USER,
        ]);
        User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'email' => 'manager@example.com',
            'is_platform_manager' => true,
            'role' => UserRole::OWNER,
        ]);

        $this->emailService->expects($this->once())->method('sendAsync')->with($this->callback(
            fn (EmailMessage $message) => in_array('manager@example.com', array_map(
                fn (EmailRecipient $recipient) => $recipient->email,
                $message->to
            ), true)
        ))->willReturn('');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A user with this email already exists in the organization.');

        $this->service->invite($inviter, 'exists@example.com');
    }

    public function test_global_duplicate_email_throws_user_already_exists(): void
    {
        $org = Organization::factory()->create();
        $inviter = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'email' => 'global@example.com',
            'status' => UserStatus::ACTIVE,
            'role' => UserRole::PBX_USER,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A user with this email already exists.');

        $this->service->invite($inviter, 'global@example.com');
    }

    public function test_validate_token_returns_null_for_invalid_token(): void
    {
        $this->assertNull($this->service->validateToken('invalid-token'));
    }

    public function test_reinviting_pending_user_invalidates_old_token(): void
    {
        $org = Organization::factory()->create();
        $inviter = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'pending@example.com',
            'status' => UserStatus::PENDING,
            'role' => UserRole::PBX_USER,
        ]);

        $firstInvite = $this->service->invite($inviter, 'pending@example.com');
        $secondInvite = $this->service->invite($inviter, 'pending@example.com');

        $this->assertSame($firstInvite['user']->id, $secondInvite['user']->id);
        $this->assertNull($this->service->validateToken($firstInvite['token']));
        $this->assertNotNull($this->service->validateToken($secondInvite['token']));
    }

    public function test_rate_limit_blocks_excessive_invites(): void
    {
        config(['services.invitation.rate_limit_per_hour' => 1]);
        $org = Organization::factory()->create();
        $inviter = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);

        $this->service->invite($inviter, 'first@example.com');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invitation rate limit exceeded for this organization.');

        $this->service->invite($inviter, 'second@example.com');
    }

    public function test_cross_tenant_token_cannot_be_consumed(): void
    {
        $orgA = Organization::factory()->create();
        $inviter = User::factory()->create(['organization_id' => $orgA->id, 'role' => UserRole::OWNER]);
        $result = $this->service->invite($inviter, 'tenant@example.com');

        $user = $result['user'];
        $user->organization_id = Organization::factory()->create()->id;
        $user->save();

        $this->assertNull($this->service->consumeToken($result['token']));
    }

    public function test_validate_token_returns_null_for_non_pending_user(): void
    {
        $org = Organization::factory()->create();
        $inviter = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        $activeUser = User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'active@example.com',
            'status' => UserStatus::ACTIVE,
            'role' => UserRole::PBX_USER,
        ]);

        $token = $this->service->invite($inviter, 'other@example.com')['token'];

        Cache::put('invite:'.hash('sha256', $token), [
            'user_id' => $activeUser->id,
            'organization_id' => $org->id,
            'email' => $activeUser->email,
        ], 3600);
        Cache::put('invite:user:'.$activeUser->id, hash('sha256', $token), 3600);

        $this->assertNull($this->service->validateToken($token));
    }
}
