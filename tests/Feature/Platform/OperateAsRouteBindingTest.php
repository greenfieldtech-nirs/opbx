<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\UserRole;
use App\Models\AutoDialerCampaign;
use App\Models\CallTrackingCampaign;
use App\Models\DidNumber;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Implicit route-model binding must resolve under the operate-as organization, not the
 * platform manager's own one (middleware priority regression).
 */
final class OperateAsRouteBindingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
    }

    private function manager(): User
    {
        return User::factory()->create([
            'organization_id' => $this->orgA->id,
            'role' => UserRole::OWNER,
            'is_platform_manager' => true,
        ]);
    }

    /** @return array<string, array{string, string}> */
    private function singleRecordRoutes(): array
    {
        $user = User::factory()->create(['organization_id' => $this->orgB->id, 'role' => UserRole::OWNER]);
        $did = DidNumber::factory()->create(['organization_id' => $this->orgB->id]);
        $auto = AutoDialerCampaign::factory()->create(['organization_id' => $this->orgB->id]);
        $track = CallTrackingCampaign::factory()->create(['organization_id' => $this->orgB->id]);

        return [
            'user delete-impact' => ['GET', "/api/v1/users/{$user->id}/delete-impact"],
            'phone number delete' => ['DELETE', "/api/v1/phone-numbers/{$did->id}"],
            'auto-dialer show' => ['GET', "/api/v1/auto-dialer-campaigns/{$auto->id}"],
            'auto-dialer monitor detail' => ['GET', "/api/v1/auto-dialer-campaigns/{$auto->id}/monitor/detail"],
            'call-tracking show' => ['GET', "/api/v1/call-tracking-campaigns/{$track->id}"],
        ];
    }

    public function test_manager_operating_as_org_b_resolves_org_b_records(): void
    {
        Sanctum::actingAs($this->manager());

        $statuses = [];
        foreach ($this->singleRecordRoutes() as $label => [$method, $uri]) {
            $r = $this->withHeaders(['X-Operate-As-Organization' => (string) $this->orgB->id])
                ->json($method, $uri);
            $statuses[$label] = $r->getStatusCode();
        }

        foreach ($statuses as $label => $status) {
            $this->assertNotSame(404, $status, "{$label} returned 404 while operating as the owning org");
            $this->assertLessThan(500, $status, "{$label} errored ({$status})");
        }
    }

    public function test_manager_without_operate_as_gets_404_for_org_b_records(): void
    {
        Sanctum::actingAs($this->manager());

        foreach ($this->singleRecordRoutes() as $label => [$method, $uri]) {
            $response = $this->json($method, $uri)->assertNotFound();
            $this->assertStringNotContainsString('could not be found', (string) $response->getContent(), "{$label}: route missing");
            $this->assertStringNotContainsString('No query results', (string) $response->getContent(), $label);
            $this->assertStringNotContainsString('Models', (string) $response->getContent(), $label);
        }
    }

    public function test_org_a_user_gets_404_for_org_b_records(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'organization_id' => $this->orgA->id,
            'role' => UserRole::OWNER,
        ]));

        foreach ($this->singleRecordRoutes() as $label => [$method, $uri]) {
            $response = $this->json($method, $uri)->assertNotFound();
            $this->assertStringNotContainsString('could not be found', (string) $response->getContent(), "{$label}: route missing");
        }
    }
}
