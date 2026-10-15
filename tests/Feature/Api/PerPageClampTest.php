<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PerPageClampTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_page_is_clamped(): void
    {
        $org = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]));

        $this->getJson('/api/v1/auto-dialer-campaigns/lists?per_page=1000000')
            ->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/v1/auto-dialer-campaigns/lists?per_page=0')
            ->assertOk()->assertJsonPath('meta.per_page', 1);
    }
}
