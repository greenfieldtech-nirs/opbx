<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IvrDestinationType;
use App\Enums\IvrMenuStatus;
use App\Models\IvrMenu;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\IvrMenu>
 */
class IvrMenuFactory extends Factory
{
    protected $model = IvrMenu::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'audio_file_path' => null,
            'tts_text' => fake()->optional()->sentence(),
            'tts_voice' => fake()->randomElement(['en-US-Neural2-A', 'en-US-Neural2-D', 'en-GB-Neural2-A']),
            'max_turns' => fake()->numberBetween(1, 9),
            'failover_destination_type' => IvrDestinationType::HANGUP->value,
            'failover_destination_id' => null,
            'status' => fake()->randomElement([IvrMenuStatus::ACTIVE->value, IvrMenuStatus::INACTIVE->value]),
        ];
    }

    /**
     * Indicate that the IVR menu is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IvrMenuStatus::ACTIVE->value,
        ]);
    }

    /**
     * Indicate that the IVR menu is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IvrMenuStatus::INACTIVE->value,
        ]);
    }
}
