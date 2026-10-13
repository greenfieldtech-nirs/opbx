<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AlbsStatus;
use App\Enums\AlbsStrategy;
use App\Enums\RingGroupFallbackAction;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Models\RingGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiAssistantLoadBalancer>
 */
class AiAssistantLoadBalancerFactory extends Factory
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
            'name' => fake()->words(3, true).' Load Balancer',
            'description' => fake()->sentence(),
            'strategy' => AlbsStrategy::ROUND_ROBIN,
            'status' => AlbsStatus::ACTIVE,
            'fallback_action' => RingGroupFallbackAction::HANGUP,
            'fallback_extension_id' => null,
            'fallback_ring_group_id' => null,
            'fallback_ivr_menu_id' => null,
            'fallback_ai_assistant_id' => null,
            'fallback_call_queue_id' => null,
            'fallback_ai_load_balancer_id' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    /**
     * Indicate that the load balancer is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AlbsStatus::INACTIVE,
        ]);
    }

    /**
     * Set the load balancer strategy to round robin.
     */
    public function roundRobin(): static
    {
        return $this->state(fn (array $attributes) => [
            'strategy' => AlbsStrategy::ROUND_ROBIN,
        ]);
    }

    /**
     * Set the load balancer strategy to priority.
     */
    public function priority(): static
    {
        return $this->state(fn (array $attributes) => [
            'strategy' => AlbsStrategy::PRIORITY,
        ]);
    }

    /**
     * Set the load balancer strategy to percentage.
     */
    public function percentage(): static
    {
        return $this->state(fn (array $attributes) => [
            'strategy' => AlbsStrategy::PERCENTAGE,
        ]);
    }

    /**
     * Set fallback action to extension.
     */
    public function withFallbackExtension(?Extension $extension = null): static
    {
        return $this->state(fn (array $attributes) => [
            'fallback_action' => RingGroupFallbackAction::EXTENSION,
            'fallback_extension_id' => $extension?->id ?? Extension::factory(),
        ]);
    }

    /**
     * Set fallback action to ring group.
     */
    public function withFallbackRingGroup(?RingGroup $ringGroup = null): static
    {
        return $this->state(fn (array $attributes) => [
            'fallback_action' => RingGroupFallbackAction::RING_GROUP,
            'fallback_ring_group_id' => $ringGroup?->id ?? RingGroup::factory(),
        ]);
    }

    /**
     * Set fallback action to IVR menu.
     */
    public function withFallbackIvrMenu(?IvrMenu $ivrMenu = null): static
    {
        return $this->state(fn (array $attributes) => [
            'fallback_action' => RingGroupFallbackAction::IVR_MENU,
            'fallback_ivr_menu_id' => $ivrMenu?->id ?? IvrMenu::factory(),
        ]);
    }

    /**
     * Set fallback action to AI assistant.
     */
    public function withFallbackAiAssistant(?AiAssistant $aiAssistant = null): static
    {
        return $this->state(fn (array $attributes) => [
            'fallback_action' => RingGroupFallbackAction::AI_ASSISTANT,
            'fallback_ai_assistant_id' => $aiAssistant?->id ?? AiAssistant::factory(),
        ]);
    }
}
