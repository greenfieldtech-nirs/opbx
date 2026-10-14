<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CallTrackingCampaign;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Call tracking data is readable by Owner, PBX Admin and Reporter only (owner decision 2026-10-14).
 */
class CallTrackingReadAccessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private CallTrackingCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->campaign = CallTrackingCampaign::factory()->create(['organization_id' => $this->organization->id]);
    }

    /**
     * @return array<int, string>
     */
    private function readEndpoints(): array
    {
        $id = $this->campaign->id;

        return [
            '/api/v1/call-tracking-campaigns',
            "/api/v1/call-tracking-campaigns/{$id}",
            "/api/v1/call-tracking-campaigns/{$id}/call-tracking-numbers",
            "/api/v1/call-tracking-campaigns/{$id}/notification-logs",
            '/api/v1/call-tracking-analytics?start_date=2026-10-01&end_date=2026-10-14',
            '/api/v1/call-tracking-analytics/export?start_date=2026-10-01&end_date=2026-10-14',
            '/api/v1/call-tracking-sessions',
        ];
    }

    public function test_pbx_users_and_supervisors_cannot_read_call_tracking(): void
    {
        foreach ([UserRole::PBX_USER, UserRole::SUPERVISOR] as $role) {
            Sanctum::actingAs(User::factory()->create(['organization_id' => $this->organization->id, 'role' => $role]));

            foreach ($this->readEndpoints() as $url) {
                $this->getJson($url)->assertForbidden();
            }
        }
    }

    public function test_owner_pbx_admin_and_reporter_can_read_call_tracking(): void
    {
        foreach ([UserRole::OWNER, UserRole::PBX_ADMIN, UserRole::REPORTER] as $role) {
            Sanctum::actingAs(User::factory()->create(['organization_id' => $this->organization->id, 'role' => $role]));

            foreach ($this->readEndpoints() as $url) {
                $this->get($url, ['Accept' => 'application/json'])->assertOk();
            }
        }
    }
}
