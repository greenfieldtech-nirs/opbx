<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CloudonixSettings;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CloudonixSettings>
 */
class CloudonixSettingsFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = CloudonixSettings::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'domain_uuid' => fake()->uuid(),
            'domain_api_key' => fake()->sha256(),
            'domain_requests_api_key' => fake()->sha256(),
            'no_answer_timeout' => 30,
            'recording_format' => 'wav',
        ];
    }

    /**
     * Indicate that the settings should have a specific domain UUID.
     */
    public function withDomainUuid(string $uuid): static
    {
        return $this->state(fn (array $attributes) => [
            'domain_uuid' => $uuid,
        ]);
    }

    /**
     * Indicate that the settings should belong to a specific organization.
     */
    public function forOrganization(int $organizationId): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $organizationId,
        ]);
    }
}
