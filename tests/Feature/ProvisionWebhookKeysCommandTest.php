<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CloudonixSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProvisionWebhookKeysCommandTest extends TestCase
{
    use RefreshDatabase;

    private function setUpOrgs(): array
    {
        $without = CloudonixSettings::factory()->create(['domain_requests_api_key' => null]);
        $with = CloudonixSettings::factory()->create(['domain_requests_api_key' => 'existing-key']);

        return [$without, $with];
    }

    public function test_provisions_and_pushes_key_only_for_orgs_without_one(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        [$without, $with] = $this->setUpOrgs();

        $this->artisan('cloudonix:provision-webhook-keys')->assertSuccessful();

        $key = $without->fresh()->domain_requests_api_key;
        $this->assertSame(48, strlen($key));
        $this->assertSame('existing-key', $with->fresh()->domain_requests_api_key);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => ($r['profile']['authorization-api-key'] ?? null) === $key);
    }

    public function test_dry_run_changes_nothing(): void
    {
        Http::fake();
        [$without] = $this->setUpOrgs();

        $this->artisan('cloudonix:provision-webhook-keys --dry-run')->assertSuccessful();

        $this->assertNull($without->fresh()->domain_requests_api_key);
        Http::assertNothingSent();
    }

    public function test_failed_push_rolls_key_back_and_fails(): void
    {
        Http::fake(['*' => Http::response(['message' => 'nope'], 500)]);
        [$without] = $this->setUpOrgs();

        $this->artisan('cloudonix:provision-webhook-keys')->assertFailed();

        $this->assertNull($without->fresh()->domain_requests_api_key);
    }
}
