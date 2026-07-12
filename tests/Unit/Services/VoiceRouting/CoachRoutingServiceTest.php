<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VoiceRouting;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Scopes\OrganizationScope;
use App\Services\VoiceRouting\CoachRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class CoachRoutingServiceTest extends TestCase
{
    use RefreshDatabase;

    private CoachRoutingService $service;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CoachRoutingService::class);
        $this->organization = Organization::factory()->create();
    }

    private function supervisorExtension(): Extension
    {
        return OrganizationScope::bypass(function () {
            $user = User::factory()->create([
                'organization_id' => $this->organization->id,
                'role' => UserRole::SUPERVISOR,
            ]);

            return Extension::factory()->create([
                'organization_id' => $this->organization->id,
                'user_id' => $user->id,
                'type' => ExtensionType::USER,
                'extension_number' => '2001',
            ]);
        });
    }

    private function request(string $to, string $from): Request
    {
        return Request::create('/api/voice/route', 'POST', [
            'To' => $to,
            'From' => $from,
            '_organization_id' => $this->organization->id,
        ]);
    }

    public function test_non_coach_destination_returns_null(): void
    {
        $result = $this->service->tryHandle($this->request('2001', '2001'));

        $this->assertNull($result);
    }

    public function test_spy_from_supervisor_emits_listen_coach(): void
    {
        $ext = $this->supervisorExtension();

        $result = $this->service->tryHandle(
            $this->request('spy_abc123def4567890', $ext->extension_number)
        );

        $this->assertNotNull($result);
        $body = $result->getContent();
        $this->assertStringContainsString('policy="listen"', $body);
        $this->assertStringContainsString('>abc123def4567890</Coach>', $body);
    }

    public function test_whisper_callee_maps_to_calee(): void
    {
        $ext = $this->supervisorExtension();

        $result = $this->service->tryHandle(
            $this->request('whisper_callee_abc123def4567890', $ext->extension_number)
        );

        $this->assertStringContainsString('policy="whisper"', $result->getContent());
        $this->assertStringContainsString('whisperDirection="calee"', $result->getContent());
    }

    public function test_barge_from_supervisor_emits_barge(): void
    {
        $ext = $this->supervisorExtension();

        $result = $this->service->tryHandle(
            $this->request('barge_abc123def4567890', $ext->extension_number)
        );

        $this->assertStringContainsString('policy="barge"', $result->getContent());
    }

    public function test_non_privileged_caller_is_denied(): void
    {
        $ext = OrganizationScope::bypass(function () {
            $user = User::factory()->create([
                'organization_id' => $this->organization->id,
                'role' => UserRole::PBX_USER,
            ]);

            return Extension::factory()->create([
                'organization_id' => $this->organization->id,
                'user_id' => $user->id,
                'type' => ExtensionType::USER,
                'extension_number' => '3001',
            ]);
        });

        $result = $this->service->tryHandle(
            $this->request('spy_abc123def4567890', $ext->extension_number)
        );

        $this->assertNotNull($result);
        $body = $result->getContent();
        $this->assertStringNotContainsString('<Coach', $body);
        $this->assertStringContainsString('<Hangup', $body);
    }

    public function test_unknown_from_extension_is_denied(): void
    {
        $result = $this->service->tryHandle(
            $this->request('spy_abc123def4567890', '9999')
        );

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('<Coach', $result->getContent());
    }

    public function test_malformed_token_returns_null(): void
    {
        $ext = $this->supervisorExtension();

        // Too-short / non-hex token does not match the sentinel grammar.
        $result = $this->service->tryHandle(
            $this->request('spy_XYZ', $ext->extension_number)
        );

        $this->assertNull($result);
    }
}
