<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AutoDialerList;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DistributionListIndexTest extends TestCase
{
    use RefreshDatabase;

    private function indexQueryCount(User $user): int
    {
        DB::enableQueryLog();
        $this->actingAs($user)->getJson('/api/v1/auto-dialer-campaigns/lists')->assertOk();
        $count = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        return $count;
    }

    public function test_index_query_count_does_not_grow_with_the_number_of_lists(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => 'owner']);

        AutoDialerList::factory()->count(2)->create(['organization_id' => $org->id]);
        $this->indexQueryCount($user); // warm-up: first request loads the org once
        $small = $this->indexQueryCount($user);

        AutoDialerList::factory()->count(8)->create(['organization_id' => $org->id]);
        $this->assertSame($small, $this->indexQueryCount($user));
    }

    public function test_has_versions_reflects_child_versions(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => 'owner']);
        $parent = AutoDialerList::factory()->create(['organization_id' => $org->id, 'name' => 'Parent']);
        AutoDialerList::factory()->create(['organization_id' => $org->id, 'parent_list_id' => $parent->id, 'is_latest_version' => false]);
        $lonely = AutoDialerList::factory()->create(['organization_id' => $org->id, 'name' => 'Lonely']);

        $byId = collect($this->actingAs($user)->getJson('/api/v1/auto-dialer-campaigns/lists')->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue($byId[$parent->id]['has_versions']);
        $this->assertFalse($byId[$lonely->id]['has_versions']);
    }
}
