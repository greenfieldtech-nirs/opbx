<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Scopes\OrganizationScope;
use App\Services\VoiceRouting\VoiceRoutingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class CoachRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_spy_sentinel_returns_coach_cxml(): void
    {
        $org = Organization::factory()->create();

        $ext = OrganizationScope::bypass(function () use ($org) {
            $user = User::factory()->create([
                'organization_id' => $org->id,
                'role' => UserRole::SUPERVISOR,
            ]);

            return Extension::factory()->create([
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'type' => ExtensionType::USER,
                'extension_number' => '2001',
            ]);
        });

        $request = Request::create('/api/voice/route', 'POST', [
            'Direction' => 'subscriber',
            'To' => 'spy_abc123def4567890',
            'From' => '2001',
            '_organization_id' => $org->id,
        ]);

        $response = OrganizationScope::bypass(
            fn () => app(VoiceRoutingManager::class)->handleInbound($request)
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('policy="listen"', $response->getContent());
        $this->assertStringContainsString('>abc123def4567890</Coach>', $response->getContent());
    }

    public function test_normal_destination_still_routes_normally(): void
    {
        $org = Organization::factory()->create();

        $request = Request::create('/api/voice/route', 'POST', [
            'Direction' => 'subscriber',
            'To' => '9999',
            'From' => '1001',
            '_organization_id' => $org->id,
        ]);

        $response = OrganizationScope::bypass(
            fn () => app(VoiceRoutingManager::class)->handleInbound($request)
        );

        // No coach verb; falls through to normal (error) routing.
        $this->assertStringNotContainsString('<Coach', $response->getContent());
    }
}
