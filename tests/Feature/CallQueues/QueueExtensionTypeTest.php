<?php

declare(strict_types=1);

namespace Tests\Feature\CallQueues;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CallQueue;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Call Queue as an extension type: validation for configuration.call_queue_id.
 */
class QueueExtensionTypeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private CallQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['status' => 'active']);
        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);
        $this->queue = CallQueue::factory()->create(['organization_id' => $this->organization->id]);
    }

    public function test_owner_can_create_queue_extension(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/extensions', [
            'extension_number' => '8001',
            'type' => ExtensionType::QUEUE->value,
            'status' => UserStatus::ACTIVE->value,
            'voicemail_enabled' => false,
            'configuration' => ['call_queue_id' => $this->queue->id],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('extensions', [
            'organization_id' => $this->organization->id,
            'extension_number' => '8001',
            'type' => 'queue',
        ]);
    }

    public function test_queue_extension_requires_call_queue_id(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/extensions', [
            'extension_number' => '8002',
            'type' => ExtensionType::QUEUE->value,
            'status' => UserStatus::ACTIVE->value,
            'voicemail_enabled' => false,
            'configuration' => [],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['configuration.call_queue_id']);
    }

    public function test_queue_extension_rejects_foreign_queue(): void
    {
        Sanctum::actingAs($this->owner);

        $foreignQueue = CallQueue::factory()->create();

        $this->postJson('/api/v1/extensions', [
            'extension_number' => '8003',
            'type' => ExtensionType::QUEUE->value,
            'status' => UserStatus::ACTIVE->value,
            'voicemail_enabled' => false,
            'configuration' => ['call_queue_id' => $foreignQueue->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['configuration.call_queue_id']);
    }
}
