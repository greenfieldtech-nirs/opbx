<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Organization;
use App\Models\SessionUpdate;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionUpdatePruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_prune_removes_only_rows_past_the_window_without_a_user(): void
    {
        config(['retention.session_updates_days' => 30]);
        $org = Organization::factory()->create();
        $old = SessionUpdate::factory()->create(['organization_id' => $org->id]);
        $recent = SessionUpdate::factory()->create(['organization_id' => $org->id]);
        DB::table('session_updates')->where('id', $old->id)->update(['updated_at' => now()->subDays(31)]);

        $this->artisan('model:prune', ['--model' => [SessionUpdate::class], '--no-interaction' => true])->assertSuccessful();

        $remaining = SessionUpdate::withoutGlobalScope(OrganizationScope::class)->pluck('id')->all();
        $this->assertSame([$recent->id], $remaining);
    }
}
