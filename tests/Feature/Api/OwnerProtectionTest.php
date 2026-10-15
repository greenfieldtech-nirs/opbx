<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Services\EmbedTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Nobody but an Owner may change an Owner's account, credentials or extension. */
final class OwnerProtectionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private function user(UserRole $role): User
    {
        $this->org ??= Organization::factory()->create();

        return User::factory()->create(['organization_id' => $this->org->id, 'role' => $role]);
    }

    /** @return array<string, array{string, string}> method, uri-template; {u}=user id, {e}=extension id */
    public static function restricted(): array
    {
        return [
            'update' => ['putJson', '/api/v1/users/{u}'],
            'password' => ['patchJson', '/api/v1/users/{u}/password'],
            'delete' => ['deleteJson', '/api/v1/users/{u}'],
            'embed show' => ['getJson', '/api/v1/users/{u}/embed-token'],
            'embed update' => ['patchJson', '/api/v1/users/{u}/embed-token'],
            'embed regenerate' => ['postJson', '/api/v1/users/{u}/embed-token/regenerate'],
            'extension update' => ['putJson', '/api/v1/extensions/{e}'],
            'extension delete' => ['deleteJson', '/api/v1/extensions/{e}'],
            'extension password' => ['getJson', '/api/v1/extensions/{e}/password'],
            'extension reset' => ['putJson', '/api/v1/extensions/{e}/reset-password'],
        ];
    }

    private function hit(User $actor, User $target, string $method, string $uri): int
    {
        $ext = Extension::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $target->id,
            'type' => 'user',
        ]);
        $uri = str_replace(['{u}', '{e}'], [(string) $target->id, (string) $ext->id], $uri);
        $payload = ['name' => 'Changed', 'email' => 'changed@example.com', 'password' => 'NewPassw0rd!x', 'password_confirmation' => 'NewPassw0rd!x'];

        return $this->actingAs($actor, 'sanctum')->{$method}($uri, $payload)->status();
    }

    #[DataProvider('restricted')]
    public function test_pbx_admin_is_refused_on_an_owner(string $method, string $uri): void
    {
        $admin = $this->user(UserRole::PBX_ADMIN);
        $owner = $this->user(UserRole::OWNER);
        $before = $owner->fresh()->only(['name', 'email', 'password']);

        $this->assertSame(403, $this->hit($admin, $owner, $method, $uri));
        $this->assertSame($before, $owner->fresh()->only(['name', 'email', 'password']));
    }

    public function test_owner_can_update_and_reach_another_owner(): void
    {
        $actor = $this->user(UserRole::OWNER);
        $other = $this->user(UserRole::OWNER);
        app(EmbedTokenService::class)->generateFor($other);

        $this->assertSame(200, $this->hit($actor, $other, 'putJson', '/api/v1/users/{u}'));
        $this->assertSame(200, $this->hit($actor, $other, 'getJson', '/api/v1/users/{u}/embed-token'));
        $this->assertSame(200, $this->hit($actor, $other, 'patchJson', '/api/v1/users/{u}/password'));
    }

    public function test_pbx_admin_can_still_manage_a_pbx_user(): void
    {
        $admin = $this->user(UserRole::PBX_ADMIN);
        $target = $this->user(UserRole::PBX_USER);

        $this->assertSame(200, $this->hit($admin, $target, 'putJson', '/api/v1/users/{u}'));
        $this->assertSame(200, $this->hit($admin, $target, 'patchJson', '/api/v1/users/{u}/password'));
        $this->assertSame(200, $this->hit($admin, $target, 'getJson', '/api/v1/extensions/{e}/password'));
    }
}
