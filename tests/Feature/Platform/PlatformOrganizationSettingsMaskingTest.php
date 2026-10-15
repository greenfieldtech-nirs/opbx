<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\OrganizationStatus;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cloudonix secrets in organization settings are masked in the update response
 * and in the platform audit log, the same way GET masks them.
 */
final class PlatformOrganizationSettingsMaskingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'XI-supersecret-1234';

    private Organization $target;

    protected function setUp(): void
    {
        parent::setUp();

        $home = Organization::factory()->create(['status' => OrganizationStatus::ACTIVE->value]);
        Sanctum::actingAs(User::factory()->create(['organization_id' => $home->id, 'role' => UserRole::OWNER, 'is_platform_manager' => true]), ['*']);

        $this->target = Organization::factory()->create([
            'status' => OrganizationStatus::ACTIVE->value,
            'settings' => ['cloudonix' => ['domain_api_key' => self::SECRET, 'domain' => 'acme.cloudonix.net']],
        ]);
    }

    public function test_update_response_masks_cloudonix_settings(): void
    {
        $response = $this->putJson("/api/v1/platform/organizations/{$this->target->id}", ['name' => 'Renamed'])->assertOk();

        $response->assertJsonPath('data.name', 'Renamed');
        $response->assertJsonPath('data.settings.cloudonix.domain_api_key', '***************1234');
        $response->assertJsonPath('data.settings.cloudonix.domain', 'acme.cloudonix.net');
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
    }

    public function test_audit_log_snapshots_mask_cloudonix_settings(): void
    {
        $this->putJson("/api/v1/platform/organizations/{$this->target->id}", ['settings' => ['cloudonix' => ['domain_api_key' => 'XI-newsecret-9876']]])->assertOk();

        $log = PlatformAuditLog::where('action', 'organization.settings.updated')->sole();
        $this->assertSame('***************1234', $log->before_state['settings']['cloudonix']['domain_api_key']);
        $this->assertSame('*************9876', $log->after_state['settings']['cloudonix']['domain_api_key']);
        $this->assertStringNotContainsString('supersecret', json_encode([$log->before_state, $log->after_state]));
        $this->assertStringNotContainsString('newsecret', $this->getJson('/api/v1/platform/audit-logs')->getContent());

        // The real value is still stored.
        $this->assertSame('XI-newsecret-9876', $this->target->fresh()->settings['cloudonix']['domain_api_key']);
    }

    public function test_deletion_audit_snapshot_masks_cloudonix_settings(): void
    {
        $this->patchJson("/api/v1/platform/organizations/{$this->target->id}/status", ['status' => OrganizationStatus::DELETED->value, 'reason' => 'test'])->assertOk();

        $log = PlatformAuditLog::where('action', 'organization.deleted')->sole();
        $this->assertSame('***************1234', $log->before_state['settings']['cloudonix']['domain_api_key']);
    }
}
