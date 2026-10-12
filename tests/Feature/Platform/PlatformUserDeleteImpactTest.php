<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PlatformUserDeleteImpactTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_matches_what_platform_delete_does_and_needs_platform_manager(): void
    {
        $platformOrg = Organization::factory()->create();
        $manager = User::factory()->create(['organization_id' => $platformOrg->id, 'role' => UserRole::OWNER, 'is_platform_manager' => true]);
        $org = Organization::factory()->create();
        User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        $victim = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        $ext = Extension::factory()->create(['organization_id' => $org->id, 'type' => ExtensionType::USER, 'user_id' => $victim->id]);
        $did = DidNumber::factory()->routeToExtension($ext)->create(['organization_id' => $org->id]);

        // Not a platform manager: no preview.
        $regular = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        Sanctum::actingAs($regular, ['*']);
        $this->getJson("/api/v1/platform/users/{$victim->id}/delete-impact")->assertForbidden();

        Sanctum::actingAs($manager, ['*']);
        $data = $this->getJson("/api/v1/platform/users/{$victim->id}/delete-impact")->assertOk()->json('data');

        $this->assertSame([$ext->id], array_column($data['extensions'], 'id'));
        $this->assertSame([['did_number', $did->id]], array_map(fn (array $r): array => [$r['type'], $r['id']], $data['routes']));
        $this->assertSame($victim->id, $ext->fresh()->user_id, 'preview wrote nothing');

        $this->deleteJson("/api/v1/platform/users/{$victim->id}")->assertOk();

        $this->assertNull($ext->fresh()->user_id);
        $this->assertSame('unavailable', $did->fresh()->routing_type);
    }
}
