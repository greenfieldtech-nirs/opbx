<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Models\CallDetailRecord;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Services\WebPhoneCallsLogBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WebPhoneCallsLogBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_own_calls_excluding_coaching(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        Extension::factory()->create([
            'organization_id' => $org->id, 'user_id' => $user->id,
            'type' => 'user', 'extension_number' => '1000',
        ]);
        CallDetailRecord::factory()->create([
            'organization_id' => $org->id, 'session_timestamp' => '2026-07-14 10:00:00',
            'from' => '1000', 'to' => '12125551234', 'disposition' => 'ANSWER',
        ]);
        CallDetailRecord::factory()->create([
            'organization_id' => $org->id, 'session_timestamp' => '2026-07-14 10:01:00',
            'from' => '1000', 'to' => 'spy_deadbeef', 'disposition' => 'ANSWER',
        ]);

        $rows = app(WebPhoneCallsLogBuilder::class)->buildForUser($user);

        $this->assertCount(1, $rows);
        $this->assertSame('12125551234', $rows[0]['to']);
    }

    public function test_returns_empty_array_when_no_extension(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->assertSame([], app(WebPhoneCallsLogBuilder::class)->buildForUser($user));
    }
}
