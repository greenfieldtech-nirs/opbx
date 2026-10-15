<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\InboundBlacklist;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundBlacklistTenantTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private InboundBlacklist $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'role' => UserRole::OWNER,
        ]);

        $otherOrg = Organization::factory()->create();
        $this->foreign = InboundBlacklist::create([
            'organization_id' => $otherOrg->id,
            'match_type' => 'exact',
            'caller_id_pattern' => '+15551234567',
            'is_global' => true,
            'rejection_strategy' => 'drop',
            'status' => 'active',
        ]);
    }

    public function test_index_does_not_list_other_orgs_entries(): void
    {
        $ids = collect($this->actingAs($this->owner)->getJson('/api/v1/inbound-blacklist')->assertOk()->json('data'))->pluck('id');

        $this->assertNotContains($this->foreign->id, $ids->all());
    }

    public function test_show_other_orgs_entry_is_not_accessible(): void
    {
        $this->assertContains(
            $this->actingAs($this->owner)->getJson("/api/v1/inbound-blacklist/{$this->foreign->id}")->getStatusCode(),
            [403, 404]
        );
    }

    public function test_update_other_orgs_entry_is_not_accessible_and_unchanged(): void
    {
        $before = $this->foreign->fresh()->caller_id_pattern;

        $this->assertContains(
            $this->actingAs($this->owner)->putJson("/api/v1/inbound-blacklist/{$this->foreign->id}", ['caller_id_pattern' => '+19999999999'])->getStatusCode(),
            [403, 404]
        );
        $this->assertSame($before, $this->foreign->fresh()->caller_id_pattern);
    }

    public function test_delete_and_toggle_other_orgs_entry_are_not_accessible(): void
    {
        $this->assertContains($this->actingAs($this->owner)->deleteJson("/api/v1/inbound-blacklist/{$this->foreign->id}")->getStatusCode(), [403, 404]);
        $this->assertContains($this->actingAs($this->owner)->patchJson("/api/v1/inbound-blacklist/{$this->foreign->id}/toggle-status")->getStatusCode(), [403, 404]);
        $this->assertNotNull(InboundBlacklist::withoutGlobalScopes()->find($this->foreign->id));
    }
}
