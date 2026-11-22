<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ExtensionType;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Web Phone settings live in the extension's Cloudonix subscriber profile. */
final class WebPhoneSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Extension $extension;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organization::factory()->create();
        CloudonixSettings::factory()->create(['organization_id' => $org->id, 'domain_name' => 'test.cloudonix.io']);
        $this->user = User::factory()->create(['organization_id' => $org->id]);
        $this->extension = Extension::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $this->user->id,
            'type' => ExtensionType::USER,
            'extension_number' => '1001',
            'cloudonix_subscriber_id' => '777',
        ]);
        Sanctum::actingAs($this->user);
    }

    /** Fakes the subscriber with $profile and records every update. */
    private function cloudonix(array $profile, int $updateStatus = 200): void
    {
        Http::fake([
            '*/subscribers/777' => fn (HttpRequest $r) => $r->method() === 'GET'
                ? Http::response(['id' => 777, 'msisdn' => '1001', 'profile' => $profile])
                : Http::response(['id' => 777], $updateStatus),
        ]);
    }

    private function updates(): array
    {
        return collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'PUT')->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    public function test_launch_writes_the_defaults_when_the_profile_has_none(): void
    {
        $this->cloudonix(['other-key' => 'keep me']);

        $settings = $this->getJson('/api/v1/webphone/config')->assertOk()->json('data.settings');

        $this->assertSame(['auto_answer' => false, 'keep_alive' => true, 'refresh_on_hangup' => true], $settings);
        [$update] = $this->updates();
        $this->assertSame([
            'other-key' => 'keep me',
            'webphone-auto-answer' => 'false',
            'webphone-keep-alive' => 'true',
            'webphone-refresh-on-hangup' => 'true',
        ], $update['profile']);
        $this->assertSame('1001', $update['msisdn']);
        $this->assertArrayHasKey('sipPassword', $update);
    }

    public function test_launch_reads_stored_settings_without_writing(): void
    {
        $this->cloudonix(['webphone-auto-answer' => 'true', 'webphone-keep-alive' => 'false', 'webphone-refresh-on-hangup' => 'true']);

        $settings = $this->getJson('/api/v1/webphone/config')->assertOk()->json('data.settings');

        $this->assertSame(['auto_answer' => true, 'keep_alive' => false, 'refresh_on_hangup' => true], $settings);
        $this->assertSame([], $this->updates());
    }

    public function test_launch_still_works_with_defaults_when_cloudonix_is_down(): void
    {
        Http::fake(['*/subscribers/777' => Http::response(['message' => 'down'], 503)]);

        $settings = $this->getJson('/api/v1/webphone/config')->assertOk()->json('data.settings');

        $this->assertSame(['auto_answer' => false, 'keep_alive' => true, 'refresh_on_hangup' => true], $settings);
        $this->assertSame([], $this->updates());
    }

    public function test_save_merges_into_the_existing_profile(): void
    {
        $this->cloudonix(['other-key' => 'keep me', 'webphone-auto-answer' => 'false']);

        $this->putJson('/api/v1/webphone/settings', ['auto_answer' => true, 'keep_alive' => false, 'refresh_on_hangup' => true])
            ->assertOk()
            ->assertExactJson(['data' => ['auto_answer' => true, 'keep_alive' => false, 'refresh_on_hangup' => true]]);

        [$update] = $this->updates();
        $this->assertSame('keep me', $update['profile']['other-key']);
        $this->assertSame('true', $update['profile']['webphone-auto-answer']);
        $this->assertSame('false', $update['profile']['webphone-keep-alive']);
    }

    public function test_save_reports_a_cloudonix_failure(): void
    {
        $this->cloudonix([], 500);

        $this->putJson('/api/v1/webphone/settings', ['auto_answer' => true, 'keep_alive' => true, 'refresh_on_hangup' => true])
            ->assertStatus(502);
    }

    public function test_save_refuses_an_extension_not_synced_to_cloudonix(): void
    {
        Http::fake();
        $this->extension->update(['cloudonix_subscriber_id' => null]);

        $this->putJson('/api/v1/webphone/settings', ['auto_answer' => true, 'keep_alive' => true, 'refresh_on_hangup' => true])
            ->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_save_requires_all_three_booleans(): void
    {
        Http::fake();

        $this->putJson('/api/v1/webphone/settings', ['auto_answer' => 'yes please'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['auto_answer', 'keep_alive', 'refresh_on_hangup']);
    }
}
