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
            'disposition' => 'ANSWER',
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
            ->assertJsonPath('data.0.disposition', 'ANSWER')
            ->assertJsonPath('data.0.duration', 154);
    }

    public function test_from_is_exact_match_not_partial(): void
    {
        $owner = $this->createUser(UserRole::OWNER);
        $this->createExtension($owner, '1001');
        $this->createCdr('1001', '12125550001', '2026-07-13 10:00:00'); // theirs
        $this->createCdr('10010', '12125550002', '2026-07-13 11:00:00'); // NOT theirs

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/webphone/calls-log');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.to', '12125550001');
    }

    public function test_excludes_coaching_sentinel_destinations(): void
    {
        $owner = $this->createUser(UserRole::OWNER);
        $this->createExtension($owner, '1000');
        $this->createCdr('1000', '12125551234', '2026-07-13 10:00:00');
        $this->createCdr('1000', 'spy_deadbeefdeadbeef', '2026-07-13 10:01:00');
        $this->createCdr('1000', 'barge_deadbeefdeadbeef', '2026-07-13 10:02:00');
        $this->createCdr('1000', 'whisper_caller_deadbeefdeadbeef', '2026-07-13 10:03:00');

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/webphone/calls-log');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.to', '12125551234');
    }

    public function test_orders_by_timestamp_desc_and_caps_at_50(): void
    {
        $owner = $this->createUser(UserRole::OWNER);
        $this->createExtension($owner, '1000');
        for ($i = 0; $i < 55; $i++) {
            $ts = sprintf('2026-07-13 %02d:%02d:00', intdiv($i, 60), $i % 60);
            $this->createCdr('1000', '1212555'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), $ts);
        }

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/webphone/calls-log');

        $response->assertStatus(200)->assertJsonCount(50, 'data');
        // Newest first: i=54 has the latest timestamp.
        $response->assertJsonPath('data.0.to', '12125550054');
    }

    public function test_does_not_return_other_organizations_calls(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Org',
            'slug' => 'other-org',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        $owner = $this->createUser(UserRole::OWNER);
        $this->createExtension($owner, '1000');

        // A CDR in another org with the same 'from' value must not leak.
        CallDetailRecord::factory()->create([
            'organization_id' => $otherOrg->id,
            'session_timestamp' => '2026-07-13 10:00:00',
            'from' => '1000',
            'to' => '19998887777',
            'disposition' => 'ANSWER',
            'duration' => 10,
            'billsec' => 10,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/webphone/calls-log');

        $response->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_returns_404_when_user_has_no_extension(): void
    {
        $owner = $this->createUser(UserRole::OWNER);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/webphone/calls-log');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'No extension is assigned to this user.');
    }

    public function test_returns_empty_data_when_no_calls(): void
    {
        $owner = $this->createUser(UserRole::OWNER);
        $this->createExtension($owner, '1000');

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/webphone/calls-log');

        $response->assertStatus(200)->assertExactJson(['data' => []]);
    }
}
