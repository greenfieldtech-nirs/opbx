<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Models\CallDetailRecord;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class WebPhoneCallsLogControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);
    }

    private function createUser(UserRole $role): User
    {
        return User::create([
            'organization_id' => $this->organization->id,
            'name' => $role->name.' User',
            'email' => strtolower($role->name).'@example.com',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function createExtension(User $user, string $number): Extension
    {
        return Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
            'extension_number' => $number,
            'password' => 'secret123',
            'type' => ExtensionType::USER,
            'status' => 'active',
        ]);
    }

    private function createCdr(string $from, string $to, string $ts): CallDetailRecord
    {
        return CallDetailRecord::factory()->create([
            'organization_id' => $this->organization->id,
            'session_timestamp' => $ts,
            'from' => $from,
            'to' => $to,
            'disposition' => 'ANSWERED',
            'duration' => 154,
            'billsec' => 150,
        ]);
    }

    public function test_returns_calls_placed_by_own_extension(): void
    {
        $owner = $this->createUser(UserRole::OWNER);
        $this->createExtension($owner, '1000');
        $this->createCdr('1000', '12125551234', '2026-07-13 10:00:00');

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/webphone/calls-log');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.to', '12125551234')
            ->assertJsonPath('data.0.disposition', 'ANSWERED')
            ->assertJsonPath('data.0.duration', 154);
    }
}
