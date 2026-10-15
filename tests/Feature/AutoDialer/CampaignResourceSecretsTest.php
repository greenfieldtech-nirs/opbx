<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\UserRole;
use App\Models\AutoDialerCampaign;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The campaign resource used to embed the org's decrypted Cloudonix domain API
 * key, readable by every role that can view a campaign.
 */
class CampaignResourceSecretsTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_responses_contain_no_cloudonix_credentials(): void
    {
        $org = Organization::factory()->create();
        CloudonixSettings::factory()->create(['organization_id' => $org->id, 'domain_api_key' => 'XI-test-not-a-real-key']);
        $campaign = AutoDialerCampaign::factory()->create(['organization_id' => $org->id]);
        $admin = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_ADMIN]);

        foreach (['/api/v1/auto-dialer-campaigns', "/api/v1/auto-dialer-campaigns/{$campaign->id}"] as $url) {
            $response = $this->actingAs($admin)->getJson($url)->assertOk();

            $this->assertStringNotContainsString('cloudonix_', $response->getContent(), $url);
            $this->assertStringNotContainsString('XI-test-not-a-real-key', $response->getContent(), $url);
        }
    }
}
