<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AmdActionControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'amd-test-token';

    private AutoDialerCallSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.amd_worker.api_token' => self::TOKEN]);
        Http::fake(['*' => Http::response([], 200)]);

        $org = Organization::factory()->create();
        CloudonixSettings::factory()->create(['organization_id' => $org->id]);
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $org->id,
            'action_voicemail' => 'HANGUP',
            'action_human' => 'CONTINUE',
        ]);
        $this->session = AutoDialerCallSession::factory()->create([
            'organization_id' => $org->id,
            'campaign_id' => $campaign->id,
            'session_token' => 'sess-live-1',
            'status' => 'answered',
        ]);
    }

    private function postAction(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/voice/amd-action', array_merge([
            'callSid' => 'call-1',
            'streamSid' => 'stream-1',
            'session' => 'sess-live-1',
            'result' => 'human',
        ], $overrides), ['Authorization' => 'Bearer '.self::TOKEN]);
    }

    public function test_session_check_accepts_only_active_sessions(): void
    {
        $auth = ['Authorization' => 'Bearer '.self::TOKEN];

        $this->postJson('/api/voice/amd-session', ['session' => 'sess-live-1'], $auth)
            ->assertOk()->assertJson(['active' => true]);
        $this->postJson('/api/voice/amd-session', ['session' => 'sess-unknown'], $auth)
            ->assertNotFound();

        OrganizationScope::bypass(fn () => $this->session->update(['status' => 'completed']));
        $this->postJson('/api/voice/amd-session', ['session' => 'sess-live-1'], $auth)
            ->assertNotFound();
    }

    public function test_session_check_requires_worker_token(): void
    {
        $this->postJson('/api/voice/amd-session', ['session' => 'sess-live-1'])->assertUnauthorized();

        config(['services.amd_worker.api_token' => '']);
        $this->postJson('/api/voice/amd-session', ['session' => 'sess-live-1'], ['Authorization' => 'Bearer '])
            ->assertUnauthorized();
    }

    public function test_payload_action_is_ignored_in_favour_of_campaign_config(): void
    {
        $this->postAction(['action' => 'https://attacker.example/cxml'])
            ->assertOk()->assertJson(['action' => 'continue']);

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->body(), 'attacker.example'));
        $this->assertSame('human', OrganizationScope::bypass(fn () => $this->session->fresh()->amd_result));
    }

    public function test_voicemail_result_runs_campaign_hangup(): void
    {
        $this->postAction(['result' => 'voicemail', 'action' => 'CONTINUE'])
            ->assertOk()->assertJson(['action' => 'hangup']);
    }

    public function test_unknown_session_is_rejected(): void
    {
        $this->postAction(['session' => 'sess-unknown'])->assertNotFound();
    }
}
