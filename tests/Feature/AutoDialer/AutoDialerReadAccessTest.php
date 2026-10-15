<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\UserRole;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerList;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AutoDialerReadAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: UserRole, 1: int}>
     */
    public static function roles(): array
    {
        return [
            'owner' => [UserRole::OWNER, 200],
            'pbx admin' => [UserRole::PBX_ADMIN, 200],
            'reporter' => [UserRole::REPORTER, 200],
            'pbx user' => [UserRole::PBX_USER, 403],
            'supervisor' => [UserRole::SUPERVISOR, 403],
        ];
    }

    /**
     * @dataProvider roles
     */
    public function test_read_endpoints_per_role(UserRole $role, int $expected): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => $role]);
        $campaign = AutoDialerCampaign::factory()->create(['organization_id' => $org->id]);
        $list = AutoDialerList::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user);

        foreach ([
            '/api/v1/auto-dialer-campaigns',
            "/api/v1/auto-dialer-campaigns/{$campaign->id}",
            "/api/v1/auto-dialer-campaigns/{$campaign->id}/destinations",
            "/api/v1/auto-dialer-campaigns/{$campaign->id}/monitor/detail",
            '/api/v1/auto-dialer-campaigns/monitor/summary',
            '/api/v1/auto-dialer-campaigns/lists',
            "/api/v1/auto-dialer-campaigns/lists/{$list->id}",
            "/api/v1/auto-dialer-campaigns/lists/{$list->id}/destinations",
            '/api/v1/auto-dialer-campaigns/lists/upload-progress/does-not-exist',
        ] as $url) {
            $status = $this->getJson($url)->status();
            if ($expected === 200) {
                $this->assertNotSame(403, $status, $url);
            } else {
                $this->assertSame(403, $status, $url);
            }
        }
    }
}
