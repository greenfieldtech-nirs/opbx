<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Recording>
 */
class RecordingFactory extends Factory
{
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
            'type' => fake()->randomElement(['upload', 'remote']),
            'file_path' => null,
            'remote_url' => null,
            'original_filename' => null,
            'file_size' => null,
            'mime_type' => null,
            'duration_seconds' => null,
            'status' => 'active',
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }

    /**
     * Indicate that the recording is an uploaded file.
     */
    public function uploaded(): static
    {
        return $this->state(fn(array $attributes) => [
            'type' => 'upload',
            'file_path' => Str::uuid() . '.mp3',
            'original_filename' => fake()->word() . '.mp3',
            'file_size' => fake()->numberBetween(100000, 10000000), // 100KB to 10MB
            'mime_type' => 'audio/mpeg',
            'duration_seconds' => fake()->numberBetween(30, 3600), // 30 seconds to 1 hour
        ]);
    }

    /**
     * Indicate that the recording is a remote URL.
     */
    public function remote(): static
    {
        return $this->state(fn(array $attributes) => [
            'type' => 'remote',
            'remote_url' => 'https://example.com/audio/' . Str::uuid() . '.mp3',
            'file_size' => fake()->numberBetween(100000, 10000000),
            'mime_type' => 'audio/mpeg',
            'duration_seconds' => fake()->numberBetween(30, 3600),
        ]);
    }

    /**
     * Indicate that the recording is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Indicate that the recording has metadata.
     */
    public function withMetadata(): static
    {
        return $this->state(fn(array $attributes) => [
            'file_size' => fake()->numberBetween(500000, 5000000), // 500KB to 5MB
            'mime_type' => fake()->randomElement(['audio/mpeg', 'audio/wav', 'audio/ogg']),
            'duration_seconds' => fake()->numberBetween(60, 1800), // 1 to 30 minutes
        ]);
    }

    /**
     * Indicate that the recording belongs to a specific organization.
     */
    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn(array $attributes) => [
            'organization_id' => $organization->id,
        ]);
    }

    /**
     * Indicate that the recording was created by a specific user.
     */
    public function createdBy(User $user): static
    {
        return $this->state(fn(array $attributes) => [
            'created_by' => $user->id,
        ]);
    }
}
