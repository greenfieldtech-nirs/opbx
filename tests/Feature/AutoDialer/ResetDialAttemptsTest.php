<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\UserRole;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ResetDialAttemptsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_dial_attempts_works_and_unknown_destination_is_404(): void
    {
        $org = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]));
        $list = AutoDialerList::factory()->create(['organization_id' => $org->id]);
        $dest = AutoDialerDestination::factory()->create([
            'organization_id' => $org->id, 'list_id' => $list->id, 'dial_attempts' => 3,
        ]);

        $base = "/api/v1/auto-dialer-campaigns/lists/{$list->id}/destinations";
        $this->postJson("{$base}/{$dest->id}/reset-dial-attempts")->assertOk();
        $this->assertSame(0, $dest->fresh()->dial_attempts);
        $this->postJson("{$base}/999999/reset-dial-attempts")->assertNotFound();
    }
}
