<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\DidNumber;
use App\Models\InboundBlacklist;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A blacklist rule that is not global must apply to at least one phone number.
 */
final class InboundBlacklistScopeValidationTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Please select at least one phone number when not using global scope.';

    private DidNumber $did;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]));
        $this->did = DidNumber::factory()->create(['organization_id' => $org->id]);
    }

    /** @return array<string, mixed> */
    private function rule(array $extra = []): array
    {
        return ['caller_id_pattern' => '+15551234567', 'match_type' => 'exact', 'rejection_strategy' => 'drop'] + $extra;
    }

    public function test_store_non_global_without_numbers_is_refused(): void
    {
        foreach ([['is_global' => false], ['is_global' => false, 'did_number_ids' => []], []] as $extra) {
            $this->postJson('/api/v1/inbound-blacklist', $this->rule($extra))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['did_number_ids' => self::MESSAGE]);
        }
        $this->assertSame(0, InboundBlacklist::count());
    }

    public function test_store_global_or_with_numbers_is_accepted(): void
    {
        $this->postJson('/api/v1/inbound-blacklist', $this->rule(['is_global' => true]))->assertCreated();
        $this->postJson('/api/v1/inbound-blacklist', $this->rule(['caller_id_pattern' => '+15550000000', 'did_number_ids' => [$this->did->id]]))->assertCreated();
    }

    public function test_update_to_non_global_without_numbers_is_refused(): void
    {
        $entry = InboundBlacklist::create($this->rule(['organization_id' => $this->did->organization_id, 'is_global' => true, 'status' => 'active']));

        $this->putJson("/api/v1/inbound-blacklist/{$entry->id}", ['is_global' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['did_number_ids' => self::MESSAGE]);
        $this->assertTrue($entry->fresh()->is_global);
    }

    public function test_update_removing_all_numbers_from_a_specific_rule_is_refused(): void
    {
        $entry = InboundBlacklist::create($this->rule(['organization_id' => $this->did->organization_id, 'is_global' => false, 'status' => 'active']));
        $entry->didNumbers()->sync([$this->did->id]);

        // Unrelated change keeps the existing numbers.
        $this->putJson("/api/v1/inbound-blacklist/{$entry->id}", ['rejection_strategy' => 'reject'])->assertOk();

        $this->putJson("/api/v1/inbound-blacklist/{$entry->id}", ['did_number_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['did_number_ids' => self::MESSAGE]);
        $this->assertSame(1, $entry->didNumbers()->count());
    }
}
