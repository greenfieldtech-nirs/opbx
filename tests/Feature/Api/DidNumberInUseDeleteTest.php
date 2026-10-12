<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Models\AutoDialerCampaign;
use App\Models\CallLog;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\OutboundWhitelist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DidNumberInUseDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private DidNumber $did;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]));
        $this->did = DidNumber::factory()->create(['organization_id' => $this->org->id]);
    }

    private function assertBlocked(string $refType, int $refId, string $refName, string $detail): void
    {
        $this->deleteJson("/api/v1/phone-numbers/{$this->did->id}")
            ->assertStatus(409)
            ->assertJsonFragment(['type' => $refType, 'id' => $refId, 'name' => $refName, 'detail' => $detail]);
        $this->assertDatabaseHas('did_numbers', ['id' => $this->did->id]);
    }

    private function assertDeletes(): void
    {
        $this->deleteJson("/api/v1/phone-numbers/{$this->did->id}")->assertNoContent();
        $this->assertDatabaseMissing('did_numbers', ['id' => $this->did->id]);
    }

    public function test_extension_default_caller_id_blocks_delete(): void
    {
        $ext = Extension::factory()->create(['organization_id' => $this->org->id, 'type' => ExtensionType::FORWARD, 'default_caller_id_did_id' => $this->did->id]);
        $this->assertBlocked('extension', $ext->id, (string) $ext->extension_number, 'default caller ID');

        $ext->update(['default_caller_id_did_id' => null]);
        $this->assertDeletes();
    }

    public function test_outbound_whitelist_default_caller_id_blocks_delete(): void
    {
        $wl = OutboundWhitelist::factory()->create(['organization_id' => $this->org->id, 'default_caller_id_did_id' => $this->did->id]);
        $this->assertBlocked('outbound_whitelist', $wl->id, $wl->name, 'default caller ID');

        $wl->update(['default_caller_id_did_id' => null]);
        $this->assertDeletes();
    }

    public function test_call_tracking_number_blocks_delete(): void
    {
        $campaign = CallTrackingCampaign::factory()->create(['organization_id' => $this->org->id]);
        $number = CallTrackingNumber::factory()->create(['organization_id' => $this->org->id, 'call_tracking_campaign_id' => $campaign->id, 'did_number_id' => $this->did->id]);
        $this->assertBlocked('call_tracking_campaign', $campaign->id, $campaign->name, 'tracking number');

        $number->delete();
        $this->assertDeletes();
    }

    public function test_auto_dialer_caller_id_pool_blocks_delete(): void
    {
        $campaign = AutoDialerCampaign::factory()->create(['organization_id' => $this->org->id]);
        DB::table('auto_dialer_campaign_caller_ids')->insert(['campaign_id' => $campaign->id, 'did_number_id' => $this->did->id, 'weight' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertBlocked('auto_dialer_campaign', $campaign->id, $campaign->name, 'caller ID pool');

        DB::table('auto_dialer_campaign_caller_ids')->delete();
        $this->assertDeletes();
    }

    public function test_inbound_blacklist_scope_blocks_delete(): void
    {
        $id = DB::table('inbound_blacklists')->insertGetId(['organization_id' => $this->org->id, 'match_type' => 'exact', 'caller_id_pattern' => '+15550001111', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inbound_blacklist_did_number')->insert(['inbound_blacklist_id' => $id, 'did_number_id' => $this->did->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertBlocked('inbound_blacklist', $id, '+15550001111', 'blocked DID');

        DB::table('inbound_blacklist_did_number')->delete();
        $this->assertDeletes();
    }

    public function test_history_rows_never_block_delete(): void
    {
        CallLog::factory()->create(['organization_id' => $this->org->id, 'did_id' => $this->did->id]);
        $campaign = AutoDialerCampaign::factory()->create(['organization_id' => $this->org->id]);
        DB::table('auto_dialer_caller_id_stats')->insert(['campaign_id' => $campaign->id, 'did_number_id' => $this->did->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('blocked_call_logs')->insert(['organization_id' => $this->org->id, 'did_number_id' => $this->did->id, 'caller_id' => '+15550002222', 'called_number' => '+15550003333', 'rejection_strategy' => 'drop', 'blocked_at' => now()]);

        $this->assertDeletes();
    }

    public function test_reference_in_another_organization_does_not_block(): void
    {
        $other = Organization::factory()->create();
        Extension::factory()->create(['organization_id' => $other->id, 'type' => ExtensionType::FORWARD, 'default_caller_id_did_id' => $this->did->id]);

        $this->assertDeletes();
    }
}
