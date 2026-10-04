<?php

declare(strict_types=1);

namespace Tests\Feature\Logging;

use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The webhook/voice route groups must tag their logs as call_flow so the
 * aggregation stack can split them from platform logs.
 */
class CallFlowLogTaggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_update_webhook_logs_are_tagged_call_flow(): void
    {
        $organization = Organization::factory()->create();

        $settings = CloudonixSettings::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)
            ->first() ?? CloudonixSettings::factory()->create(['organization_id' => $organization->id]);
        $settings->update(['domain_name' => 'logs-test.example.com']);

        Log::spy();

        $response = $this->postJson('/api/webhooks/cloudonix/session-update', [
            'id' => 777000111,
            'token' => 'sess-tag-test',
            'eventId' => 'evt-tag-test',
            'domainId' => 1764,
            'domain' => 'logs-test.example.com',
            'subscriberId' => '248967',
            'callerId' => '9099',
            'destination' => '20001',
            'direction' => 'incoming',
            'status' => 'connected',
            'createdAt' => now()->subMinute()->toIso8601String(),
            'modifiedAt' => now()->toIso8601String(),
            'action' => 'none',
            'reason' => 'normal',
        ], ['Authorization' => 'Bearer '.$settings->domain_requests_api_key]);

        $response->assertOk();

        // The webhook entry log carries the session token (audit-trail key).
        // The call_flow tag itself is covered by TagCallFlowLogsTest.
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context = []) => str_contains($message, 'session-update')
                && ($context['session_token'] ?? null) === 'sess-tag-test'
        );
    }
}
