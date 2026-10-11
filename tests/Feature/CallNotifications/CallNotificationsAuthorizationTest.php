<?php

declare(strict_types=1);

namespace Tests\Feature\CallNotifications;

use App\Enums\UserRole;
use App\Models\CallNotificationLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CallNotificationsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(UserRole $role): Organization
    {
        $org = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->create(['organization_id' => $org->id, 'role' => $role]));

        return $org;
    }

    public function test_reporter_and_pbx_user_get_403_everywhere(): void
    {
        foreach ([UserRole::REPORTER, UserRole::PBX_USER] as $role) {
            $this->actAs($role);

            $this->getJson('/api/v1/call-notifications/settings')->assertForbidden();
            $this->postJson('/api/v1/call-notifications/settings', [])->assertForbidden();
            $this->putJson('/api/v1/call-notifications/settings', [])->assertForbidden();
            $this->deleteJson('/api/v1/call-notifications/settings')->assertForbidden();
            $this->postJson('/api/v1/call-notifications/settings/test')->assertForbidden();
            $this->getJson('/api/v1/call-notifications/logs')->assertForbidden();
            $this->getJson('/api/v1/call-notifications/logs/abc')->assertForbidden();
            $this->getJson('/api/v1/call-notifications/rate-limit')->assertForbidden();
        }
    }

    public function test_owner_can_read_and_logs_redact_auth_headers(): void
    {
        $org = $this->actAs(UserRole::OWNER);
        CallNotificationLog::factory()->create([
            'organization_id' => $org->id,
            'call_session_token' => 'tok-1',
            'request_headers' => ['Authorization' => 'Bearer s3cret', 'Accept' => 'application/json'],
        ]);

        $this->getJson('/api/v1/call-notifications/settings')->assertOk();
        $response = $this->getJson('/api/v1/call-notifications/logs/tok-1')->assertOk();

        $this->assertStringNotContainsString('s3cret', $response->getContent());
        $response->assertJsonPath('data.0.request_headers.Accept', 'application/json');
    }
}
