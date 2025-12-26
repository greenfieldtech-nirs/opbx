<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\CallStatus;
use App\Models\CallLog;
use App\Models\Organization;
use App\Services\CallStateManager\CallStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CallStateManagerTest extends TestCase
{
    use RefreshDatabase;

    private CallStateManager $stateManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateManager = new CallStateManager();
    }

    public function test_acquire_lock_returns_true_for_new_lock(): void
    {
        $callId = 'test-call-' . uniqid();

        $result = $this->stateManager->acquireLock($callId);

        $this->assertTrue($result);
    }

    public function test_get_and_set_state(): void
    {
        $callId = 'test-call-' . uniqid();
        $state = [
            'status' => 'ringing',
            'updated_at' => now()->toIso8601String(),
        ];

        $this->stateManager->setState($callId, $state);
        $retrieved = $this->stateManager->getState($callId);

        $this->assertEquals($state, $retrieved);
    }

    public function test_delete_state(): void
    {
        $callId = 'test-call-' . uniqid();
        $state = ['status' => 'ringing'];

        $this->stateManager->setState($callId, $state);
        $this->assertNotNull($this->stateManager->getState($callId));

        $this->stateManager->deleteState($callId);
        $this->assertNull($this->stateManager->getState($callId));
    }

    public function test_transition_to_valid_state(): void
    {
        $organization = Organization::factory()->create();

        $callLog = CallLog::create([
            'organization_id' => $organization->id,
            'call_id' => 'test-call-' . uniqid(),
            'direction' => 'inbound',
            'from_number' => '+1234567890',
            'to_number' => '+0987654321',
            'status' => CallStatus::INITIATED,
            'initiated_at' => now(),
        ]);

        $result = $this->stateManager->transitionTo(
            $callLog,
            CallStatus::RINGING
        );

        $this->assertTrue($result);
        $this->assertEquals(CallStatus::RINGING, $callLog->fresh()->status);
    }

    public function test_transition_to_invalid_state_fails(): void
    {
        $organization = Organization::factory()->create();

        $callLog = CallLog::create([
            'organization_id' => $organization->id,
            'call_id' => 'test-call-' . uniqid(),
            'direction' => 'inbound',
            'from_number' => '+1234567890',
            'to_number' => '+0987654321',
            'status' => CallStatus::COMPLETED,
            'initiated_at' => now(),
        ]);

        // Try to transition from COMPLETED (terminal) to RINGING
        $result = $this->stateManager->transitionTo(
            $callLog,
            CallStatus::RINGING
        );

        $this->assertFalse($result);
        $this->assertEquals(CallStatus::COMPLETED, $callLog->fresh()->status);
    }

    public function test_with_lock_executes_callback(): void
    {
        $callId = 'test-call-' . uniqid();
        $executed = false;

        $result = $this->stateManager->withLock($callId, function () use (&$executed) {
            $executed = true;
            return 'success';
        });

        $this->assertTrue($executed);
        $this->assertEquals('success', $result);
    }
}
