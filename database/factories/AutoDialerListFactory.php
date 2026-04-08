<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ListStatus;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerList;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<AutoDialerList>
 */
class AutoDialerListFactory extends Factory
{
    protected $model = AutoDialerList::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'campaign_id' => null, // Not assigned to campaign by default
            'name' => $this->faker->words(3, true).' List',
            'description' => $this->faker->optional()->sentence,
            'version_number' => 1,
            'parent_list_id' => null,
            'is_latest_version' => true,
            'status' => ListStatus::READY,
            'used_by_campaign_id' => null,
            'used_at' => null,
            'original_filename' => null,
            'processed_at' => now(),
            'total_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'validation_errors' => null,
            'archived_at' => null,
        ];
    }

    /**
     * Indicate that the list is assigned to a campaign.
     */
    public function assignedToCampaign(AutoDialerCampaign $campaign): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $campaign->organization_id,
            'campaign_id' => $campaign->id,
            'used_by_campaign_id' => $campaign->id,
            'status' => ListStatus::IN_USE,
            'used_at' => now(),
        ]);
    }

    /**
     * Indicate that the list is in use.
     */
    public function inUse(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ListStatus::IN_USE,
            'used_at' => now(),
        ]);
    }

    /**
     * Indicate that the list is ready.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ListStatus::READY,
        ]);
    }

    /**
     * Indicate that the list is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ListStatus::ARCHIVED,
            'archived_at' => now(),
            'is_latest_version' => false,
        ]);
    }

    /**
     * Set the row counts.
     */
    public function withRowCounts(int $total, int $valid, int $invalid = 0): static
    {
        return $this->state(fn (array $attributes) => [
            'total_rows' => $total,
            'valid_rows' => $valid,
            'invalid_rows' => $invalid,
        ]);
    }
}
