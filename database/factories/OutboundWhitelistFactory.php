<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OutboundWhitelist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OutboundWhitelist>
 */
class OutboundWhitelistFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = OutboundWhitelist::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => $this->faker->words(2, true),
            'destination_country' => $this->faker->country(),
            'destination_prefix' => $this->faker->numerify('+##########'),
            'outbound_trunk_name' => $this->faker->word(),
        ];
    }

    /**
     * Create an outbound whitelist entry for a specific organization.
     */
    public function forOrganization(Organization $organization): self
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $organization->id,
        ]);
    }

    /**
     * Create an outbound whitelist entry with a specific name.
     */
    public function withName(string $name): self
    {
        return $this->state(fn (array $attributes) => [
            'name' => $name,
        ]);
    }

    /**
     * Create an outbound whitelist entry with a specific country.
     */
    public function withCountry(string $country): self
    {
        return $this->state(fn (array $attributes) => [
            'destination_country' => $country,
        ]);
    }

    /**
     * Create an outbound whitelist entry with a specific prefix.
     */
    public function withPrefix(string $prefix): self
    {
        return $this->state(fn (array $attributes) => [
            'destination_prefix' => $prefix,
        ]);
    }

    /**
     * Create an outbound whitelist entry with a specific trunk name.
     */
    public function withTrunkName(string $trunkName): self
    {
        return $this->state(fn (array $attributes) => [
            'outbound_trunk_name' => $trunkName,
        ]);
    }
}
